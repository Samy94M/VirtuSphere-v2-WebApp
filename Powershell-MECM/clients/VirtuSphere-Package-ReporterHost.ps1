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

# The wall clock is consulted only once to translate an HTTP-date into a
# duration. Subsequent admission decisions use the run's monotonic clock and
# never sleep or hold up package execution for Retry-After.
function Get-VsPackageReportRetryUntilMs {
    param(
        [Parameter(Mandatory)][int]$StatusCode,
        [AllowNull()][string]$RetryAfter,
        [Parameter(Mandatory)][DateTimeOffset]$ReceivedAtUtc,
        [Parameter(Mandatory)][long]$MonotonicNowMs
    )

    if ($StatusCode -ne 429 -and $StatusCode -ne 503) { return $null }
    if ([string]::IsNullOrEmpty($RetryAfter) -or $RetryAfter.Length -gt 128 -or
        $MonotonicNowMs -lt 0) { return $null }
    $delayMs = [long]0
    if ($RetryAfter -cmatch '\A[0-9]+\z') {
        # Any larger valid decimal exceeds this run's lifetime. Saturate
        # instead of treating it as malformed and sending the next event.
        if ($RetryAfter.Length -gt 15) { return [long]::MaxValue }
        $seconds = [long]0
        if (-not [long]::TryParse($RetryAfter, [ref]$seconds)) { return [long]::MaxValue }
        if ($seconds -gt ([long]::MaxValue / 1000)) { return [long]::MaxValue }
        $delayMs = $seconds * 1000
    } else {
        $date = [DateTimeOffset]::MinValue
        if (-not [DateTimeOffset]::TryParseExact($RetryAfter, 'r',
                [Globalization.CultureInfo]::InvariantCulture,
                [Globalization.DateTimeStyles]::AssumeUniversal, [ref]$date)) { return $null }
        $delta = $date - $ReceivedAtUtc
        if ($delta.Ticks -le 0) { return $null }
        $delayMs = [long][Math]::Ceiling($delta.TotalMilliseconds)
    }
    if ($delayMs -le 0) { return $null }
    if ($MonotonicNowMs -gt [long]::MaxValue - $delayMs) { return [long]::MaxValue }
    return ($MonotonicNowMs + $delayMs)
}

function New-VsPackageReportBudget {
    $monotonic = [Diagnostics.Stopwatch]::StartNew()
    return [pscustomobject]@{
        MonotonicClock = $monotonic
        ActiveClock = (New-Object Diagnostics.Stopwatch)
        RetryUntilMs = $null
    }
}

function Get-VsPackageReportAttemptTimeoutMs {
    param(
        [Parameter(Mandatory)][object]$Budget,
        [Parameter(Mandatory)][ValidateSet('started', 'step_result', 'completed')][string]$ReportEvent
    )

    if ($null -ne $Budget.RetryUntilMs -and
        $Budget.MonotonicClock.ElapsedMilliseconds -lt [long]$Budget.RetryUntilMs) { return 0 }
    # 7.5 seconds for setup/start/steps, 2 seconds reserved for completion,
    # and 0.5 seconds reserved for controlled teardown: 10 seconds total.
    $cutoffMs = if ($ReportEvent -eq 'completed') { 9500 } else { 7500 }
    $availableMs = $cutoffMs - [long]$Budget.ActiveClock.ElapsedMilliseconds
    if ($availableMs -lt 100) { return 0 }
    return [int][Math]::Min(2000, $availableMs)
}

# Run-level admission in the isolated host. In particular, a Retry-After
# response suppresses completed too; it never delays the package payload.
function Invoke-VsPackageReportBudgetedAttempt {
    param(
        [Parameter(Mandatory)][object]$Budget,
        [Parameter(Mandatory)][object]$ApiConfiguration,
        [Parameter(Mandatory)][object]$ReportRequest
    )

    $reportEvent = [string]$ReportRequest.ReportEvent
    if (@('started', 'step_result', 'completed') -cnotcontains $reportEvent) {
        return [pscustomobject]@{ Attempted = $false; Confirmed = $false; Deduplicated = $false; Reason = 'invalid_request'; StatusCode = $null }
    }
    $timeoutMs = Get-VsPackageReportAttemptTimeoutMs -Budget $Budget -ReportEvent $reportEvent
    if ($timeoutMs -le 0) {
        $reason = if ($null -ne $Budget.RetryUntilMs -and
            $Budget.MonotonicClock.ElapsedMilliseconds -lt [long]$Budget.RetryUntilMs) {
            'backpressure'
        } else { 'budget_exhausted' }
        return [pscustomobject]@{ Attempted = $false; Confirmed = $false; Deduplicated = $false; Reason = $reason; StatusCode = $null }
    }

    $Budget.ActiveClock.Start()
    try {
        $result = Invoke-VsPackageReportHttp -ApiConfiguration $ApiConfiguration `
            -ReportRequest $ReportRequest -TimeoutMs $timeoutMs
    } finally {
        $Budget.ActiveClock.Stop()
    }
    if (($result.StatusCode -eq 429 -or $result.StatusCode -eq 503) -and $result.RetryAfter) {
        $deadline = Get-VsPackageReportRetryUntilMs -StatusCode $result.StatusCode `
            -RetryAfter $result.RetryAfter -ReceivedAtUtc ([DateTimeOffset]::UtcNow) `
            -MonotonicNowMs $Budget.MonotonicClock.ElapsedMilliseconds
        if ($null -ne $deadline) { $Budget.RetryUntilMs = $deadline }
    }
    return $result
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
