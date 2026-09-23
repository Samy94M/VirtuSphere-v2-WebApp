#Requires -Version 5.1
# Prozesshost-Vertrag des Paket-Reporters (ADR-0044). T3 verteilt diesen Host
# als Teil eines unveraenderlichen Bundles. Prozesskapselung, IPC und Transport
# werden in T4 implementiert und vor Aktivierung im Wrapper gesondert gemessen.
Set-StrictMode -Version 1.0

$script:VsPackageReporterHostContractVersion = 1
$script:VsPackageReporterHostExpectedAdapterContractVersion = 1

function Get-VsPackageReporterHostContractVersion {
    return $script:VsPackageReporterHostContractVersion
}

# Called only by the later isolated reporter process. The request timeout is
# defense in depth, not a wall-clock guarantee for DNS/TLS or a replacement
# for the wrapper-owned job object and cumulative Stopwatch budget.
function Invoke-VsPackageReportHttp {
    param(
        [Parameter(Mandatory)][object]$ApiConfiguration,
        [Parameter(Mandatory)][object]$ReportRequest,
        [Parameter(Mandatory)][ValidateRange(1, 2000)][int]$TimeoutMs
    )

    $result = [pscustomobject]@{
        Attempted = $false
        Confirmed = $false
        Deduplicated = $false
        Reason = 'invalid_request'
        StatusCode = $null
        RetryAfter = $null
    }
    try {
        if ($ReportRequest.BodyBytes -isnot [byte[]] -or $ReportRequest.BodyBytes.Length -lt 1 -or
            $ReportRequest.BodyBytes.Length -gt 65536 -or
            [string]$ReportRequest.RunId -cnotmatch '\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z' -or
            @('started', 'step_result', 'completed') -cnotcontains [string]$ReportRequest.ReportEvent -or
            ($ReportRequest.EventSeq -isnot [int] -and $ReportRequest.EventSeq -isnot [long]) -or
            $ReportRequest.EventSeq -le 0) { return $result }

        $scheme = [string]$ApiConfiguration.Scheme
        $api = [string]$ApiConfiguration.Api
        $url = [string]$ApiConfiguration.ReportUrl
        $pin = [string]$ApiConfiguration.CertThumbprint
        $result.Reason = 'invalid_configuration'
        if (@('http', 'https') -cnotcontains $scheme -or
            $api -cnotmatch '\A[A-Za-z0-9](?:[A-Za-z0-9.\-]*[A-Za-z0-9])?(?::[0-9]+)?\z' -or
            $url -cne ('{0}://{1}/mecm_report.php?action=reportPackageRun' -f $scheme, $api) -or
            ($pin -and $pin -cnotmatch '\A[0-9A-F]{40}\z')) { return $result }
        $uri = [uri]$url
        if ($uri.Scheme -cne $scheme -or $uri.UserInfo -or $uri.Fragment -or
            $uri.AbsolutePath -cne '/mecm_report.php' -or $uri.Query -cne '?action=reportPackageRun' -or
            $uri.Port -lt 1 -or $uri.Port -gt 65535) { return $result }

        $request = [Net.HttpWebRequest][Net.WebRequest]::Create($uri)
        $request.Method = 'POST'
        $request.ContentType = 'application/json; charset=utf-8'
        $request.Accept = 'application/json'
        $request.ContentLength = $ReportRequest.BodyBytes.Length
        $request.Timeout = $TimeoutMs
        $request.ReadWriteTimeout = $TimeoutMs
        $request.AllowAutoRedirect = $false
        $request.AllowWriteStreamBuffering = $false
        $request.KeepAlive = $false
        $request.Proxy = $null
        $request.Credentials = $null
        $request.UseDefaultCredentials = $false
        $request.MaximumResponseHeadersLength = 8
        $request.AutomaticDecompression = [Net.DecompressionMethods]::None
        $request.ServicePoint.Expect100Continue = $false
        if ($scheme -eq 'https' -and $pin) {
            $expectedPin = $pin
            $request.ServerCertificateValidationCallback = {
                param($senderObject, $certificate, $chain, $sslPolicyErrors)
                $null = $senderObject, $chain
                if ($sslPolicyErrors -eq [Net.Security.SslPolicyErrors]::None) { return $true }
                if (-not $certificate) { return $false }
                try { return $certificate.GetCertHashString().ToUpperInvariant() -ceq $expectedPin } catch { return $false }
            }.GetNewClosure()
        }

        $result.Attempted = $true
        $response = $null
        try {
            $sendStream = $request.GetRequestStream()
            try {
                $sendStream.Write($ReportRequest.BodyBytes, 0, $ReportRequest.BodyBytes.Length)
            } finally {
                $sendStream.Dispose()
            }
            try {
                $response = [Net.HttpWebResponse]$request.GetResponse()
            } catch [Net.WebException] {
                if ($_.Exception.Response -isnot [Net.HttpWebResponse]) { throw }
                $response = [Net.HttpWebResponse]$_.Exception.Response
            }
            $result.StatusCode = [int]$response.StatusCode
            if ($result.StatusCode -eq 429 -or $result.StatusCode -eq 503) {
                $retryAfter = [string]$response.Headers['Retry-After']
                if ($retryAfter.Length -le 128) { $result.RetryAfter = $retryAfter }
            }
            $result.Reason = 'http_status'
            if ($result.StatusCode -ne 200) { return $result }

            $result.Reason = 'body_shape'
            if ($response.ContentLength -gt 4096) { return $result }
            $bodyStream = $response.GetResponseStream()
            $buffer = New-Object byte[] 1024
            $bodyBytes = New-Object 'System.Collections.Generic.List[byte]'
            while ($bodyBytes.Count -le 4096) {
                $read = $bodyStream.Read($buffer, 0, [Math]::Min($buffer.Length, 4097 - $bodyBytes.Count))
                if ($read -le 0) { break }
                for ($index = 0; $index -lt $read; $index++) { $bodyBytes.Add($buffer[$index]) }
            }
            if ($bodyBytes.Count -gt 4096) { return $result }
            try {
                $json = (New-Object Text.UTF8Encoding($false, $true)).GetString($bodyBytes.ToArray())
            } catch [Text.DecoderFallbackException] {
                return $result
            }
            $ack = Resolve-VsPackageReportAcknowledgement -StatusCode $result.StatusCode `
                -ContentType ([string]$response.ContentType) -ResponseJson $json `
                -RunId $ReportRequest.RunId -ReportEvent $ReportRequest.ReportEvent -EventSeq $ReportRequest.EventSeq
            $result.Confirmed = $ack.Confirmed
            $result.Deduplicated = $ack.Deduplicated
            $result.Reason = $ack.Reason
            return $result
        } finally {
            if ($response) { $response.Dispose() }
            if ($request) { $request.Abort() }
        }
    } catch {
        Write-Debug $_
        $result.Reason = if ($result.Attempted) { 'transport_error' } else { 'invalid_configuration' }
        return $result
    }
}
