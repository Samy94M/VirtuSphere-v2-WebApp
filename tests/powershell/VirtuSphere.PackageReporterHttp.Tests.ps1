BeforeAll {
    $repoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    . (Join-Path $repoRoot 'Powershell-MECM/clients/VirtuSphere-Package-Reporter.ps1')
    . (Join-Path $repoRoot 'Powershell-MECM/clients/VirtuSphere-Package-ReporterHost.ps1')
    $script:base = (Get-Content -LiteralPath (Join-Path $repoRoot 'Docker/WebAPI/tests/fixtures/package-report-v1.json') -Raw | ConvertFrom-Json).base
    $snapshot = [pscustomobject]@{
        MacCandidates = @($script:base.mac_candidates)
        RolloutRevision = [int]$script:base.rollout_revision
        DeviceGeneration = $script:base.device_generation
        AcceptanceGeneration = $script:base.acceptance_generation
    }
    $script:request = New-VsPackageReportStartedRequest -RunId $script:base.run_id -Snapshot $snapshot `
        -ProjectName $script:base.project_name -PackageVersion $script:base.package_version `
        -ClientStartedAt $script:base.client_started_at -EventAt $script:base.event_at `
        -Context $script:base.context -Total $script:base.total

    function Start-ReportLoopback {
        param([int]$Status, [string]$ContentType, [string]$Body, [string]$ExtraHeader = '', [bool]$NoLength = $false)
        $reservation = New-Object Net.Sockets.TcpListener ([Net.IPAddress]::Loopback, 0)
        $reservation.Start()
        try { $port = ([Net.IPEndPoint]$reservation.LocalEndpoint).Port } finally { $reservation.Stop() }
        $ready = Join-Path $TestDrive ([guid]::NewGuid().ToString('N') + '.ready')
        $job = Start-Job -ArgumentList @($port, $ready, $Status, $ContentType, $Body, $ExtraHeader, $NoLength) -ScriptBlock {
            param($Port, $Ready, $Status, $ContentType, $Body, $ExtraHeader, $NoLength)
            $listener = New-Object Net.Sockets.TcpListener ([Net.IPAddress]::Loopback, [int]$Port)
            $listener.Start()
            try {
                [IO.File]::WriteAllText($Ready, 'ready')
                $client = $listener.AcceptTcpClient()
                try {
                    $client.ReceiveTimeout = 3000
                    $client.SendTimeout = 3000
                    $stream = $client.GetStream()
                    $headerBytes = New-Object 'System.Collections.Generic.List[byte]'
                    while ($headerBytes.Count -lt 8192) {
                        $next = $stream.ReadByte()
                        if ($next -lt 0) { throw 'Request header ended early.' }
                        $headerBytes.Add([byte]$next)
                        $count = $headerBytes.Count
                        if ($count -ge 4 -and $headerBytes[$count - 4] -eq 13 -and
                            $headerBytes[$count - 3] -eq 10 -and $headerBytes[$count - 2] -eq 13 -and
                            $headerBytes[$count - 1] -eq 10) { break }
                    }
                    $headers = [Text.Encoding]::ASCII.GetString($headerBytes.ToArray())
                    $match = [regex]::Match($headers, '(?im)^Content-Length:\s*(\d+)\s*$')
                    if (-not $match.Success) { throw 'Missing Content-Length.' }
                    $length = [int]$match.Groups[1].Value
                    $payload = New-Object byte[] $length
                    $offset = 0
                    while ($offset -lt $length) {
                        $read = $stream.Read($payload, $offset, $length - $offset)
                        if ($read -le 0) { throw 'Request body ended early.' }
                        $offset += $read
                    }
                    $responseBytes = [Text.Encoding]::UTF8.GetBytes($Body)
                    $extraLine = if ($ExtraHeader) { $ExtraHeader } else { '' }
                    $lengthLine = if ($NoLength) { '' } else { "Content-Length: $($responseBytes.Length)`r`n" }
                    $head = [Text.Encoding]::ASCII.GetBytes(
                        "HTTP/1.1 $Status Test`r`nContent-Type: $ContentType`r`n$lengthLine$extraLine" +
                        "Connection: close`r`n`r`n")
                    $stream.Write($head, 0, $head.Length)
                    $stream.Write($responseBytes, 0, $responseBytes.Length)
                    $stream.Flush()
                    [pscustomobject]@{ Headers = $headers; Body = [Text.Encoding]::UTF8.GetString($payload) }
                } finally {
                    if ($client) { $client.Dispose() }
                }
            } finally {
                $listener.Stop()
            }
        }
        for ($attempt = 0; $attempt -lt 120 -and -not (Test-Path -LiteralPath $ready) -and
            $job.State -notin @('Completed', 'Failed', 'Stopped'); $attempt++) {
            Start-Sleep -Milliseconds 25
        }
        if (-not (Test-Path -LiteralPath $ready)) {
            Stop-Job -Job $job -ErrorAction SilentlyContinue
            $failure = Receive-Job -Job $job 2>&1 | Out-String
            Remove-Job -Job $job -Force -ErrorAction SilentlyContinue
            throw ('Loopback listener unavailable: ' + $failure.Trim())
        }
        return [pscustomobject]@{ Job = $job; Port = $port }
    }

    function Invoke-TestLoopback {
        param([int]$Status = 200, [string]$ContentType = 'application/json; charset=utf-8',
            [string]$Body = ('{"schema_version":1,"run_id":"' + $script:base.run_id + '","event":"started","event_seq":1,"accepted":true,"deduplicated":false}'),
            [string]$ExtraHeader = '', [bool]$NoLength = $false)
        $server = Start-ReportLoopback -Status $Status -ContentType $ContentType -Body $Body -ExtraHeader $ExtraHeader -NoLength $NoLength
        try {
            $config = [pscustomobject]@{
                Api = "127.0.0.1:$($server.Port)"
                Scheme = 'http'
                CertThumbprint = ''
                ReportUrl = "http://127.0.0.1:$($server.Port)/mecm_report.php?action=reportPackageRun"
            }
            $outcome = Invoke-VsPackageReportHttp -ApiConfiguration $config -ReportRequest $script:request -TimeoutMs 2000
            $null = Wait-Job -Job $server.Job -Timeout 5
            $capture = Receive-Job -Job $server.Job -ErrorAction Stop
            return [pscustomobject]@{ Outcome = $outcome; Capture = $capture }
        } finally {
            if ($server.Job.State -notin @('Completed', 'Failed', 'Stopped')) { Stop-Job -Job $server.Job -ErrorAction SilentlyContinue }
            Remove-Job -Job $server.Job -Force -ErrorAction SilentlyContinue
        }
    }
}

Describe 'T4 package reporter bounded HTTP transport' {
    It 'sends the exact POST bytes once and accepts only the matching acknowledgement' {
        $run = Invoke-TestLoopback
        $run.Outcome.Attempted | Should -BeTrue
        $run.Outcome.Reason | Should -Be 'accepted'
        $run.Outcome.Confirmed | Should -BeTrue
        $run.Outcome.Deduplicated | Should -BeFalse
        $run.Capture.Headers | Should -Match '^POST /mecm_report.php\?action=reportPackageRun HTTP/1.1'
        $run.Capture.Headers | Should -Match '(?im)^Content-Type: application/json; charset=utf-8\s*$'
        $run.Capture.Body | Should -Be ([Text.Encoding]::UTF8.GetString($script:request.BodyBytes))
        $run.Capture.Headers | Should -Not -Match 'X-VirtuSphere-Token'
    }

    It 'does not confirm a redirect, asynchronous 202 or HTML response' {
        $redirect = (Invoke-TestLoopback -Status 302 -ExtraHeader "Location: http://127.0.0.1:9/other`r`n").Outcome
        $redirect.Confirmed | Should -BeFalse
        $redirect.StatusCode | Should -Be 302
        (Invoke-TestLoopback -Status 202).Outcome.Confirmed | Should -BeFalse
        (Invoke-TestLoopback -ContentType 'text/html' -Body '<html>login</html>').Outcome.Confirmed | Should -BeFalse
        (Invoke-TestLoopback -Body ('{"schema_version":1,"run_id":"018f2f49-5e41-4d55-8f05-8f55a5334199","event":"started","event_seq":1,"accepted":true,"deduplicated":false}')).Outcome.Confirmed | Should -BeFalse
    }

    It 'bounds response bytes and exposes only a bounded Retry-After for later policy' {
        (Invoke-TestLoopback -Body ('x' * 4097)).Outcome.Reason | Should -Be 'body_shape'
        (Invoke-TestLoopback -Body ('x' * 4097) -NoLength $true).Outcome.Reason | Should -Be 'body_shape'
        $throttled = (Invoke-TestLoopback -Status 503 -ExtraHeader "Retry-After: 12`r`n").Outcome
        $throttled.Confirmed | Should -BeFalse
        $throttled.StatusCode | Should -Be 503
        $throttled.RetryAfter | Should -Be '12'
    }

    It 'rejects invalid configuration and oversized request before any network attempt' {
        $config = [pscustomobject]@{ Api = 'example.invalid'; Scheme = 'http'; CertThumbprint = ''
            ReportUrl = 'http://example.invalid/mecm_report.php?action=other' }
        $outcome = Invoke-VsPackageReportHttp -ApiConfiguration $config -ReportRequest $script:request -TimeoutMs 100
        $outcome.Attempted | Should -BeFalse
        $outcome.Reason | Should -Be 'invalid_configuration'
        $tooLarge = [pscustomobject]@{ BodyBytes = (New-Object byte[] 65537); RunId = $script:base.run_id
            ReportEvent = 'started'; EventSeq = 1 }
        $outcome = Invoke-VsPackageReportHttp -ApiConfiguration $config -ReportRequest $tooLarge -TimeoutMs 100
        $outcome.Attempted | Should -BeFalse
        $outcome.Reason | Should -Be 'invalid_request'
    }
}
