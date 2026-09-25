# U10: run the actual wrapper with isolated files and mocked registry/children.
# No registry provider or child payload is reached by these tests.
BeforeAll {
    $script:Template = Join-Path (Split-Path (Split-Path $PSScriptRoot -Parent) -Parent) 'Powershell-MECM/Package_Vorlage/install.ps1'
    $script:ReporterClients = Join-Path (Split-Path (Split-Path $PSScriptRoot -Parent) -Parent) 'Powershell-MECM/clients'
    function PowerShell.exe {
        param([switch]$NoProfile, [string]$ExecutionPolicy, [switch]$NonInteractive, [string]$File)
        throw 'A child process must be mocked.'
    }
    function Invoke-RepairWrapper {
        $previousLocation = Get-Location
        $wrapperLog = Join-Path $script:PackageRoot 'wrapper.log'
        try {
            & (Join-Path $script:PackageRoot 'install.ps1') *> $wrapperLog
            $script:WrapperExit = $LASTEXITCODE
        } finally {
            Set-Location $previousLocation
        }
        if ($script:WrapperExit -ne 0) {
            $diagnostic = Get-Content -LiteralPath $wrapperLog -Raw -ErrorAction SilentlyContinue
            Write-Host "PackageRepair wrapper diagnostic (exit $($script:WrapperExit)):`n$diagnostic"
        }
    }
    function Set-RepairStepMarker {
        param([string]$Name)
        $hash = (Get-FileHash -LiteralPath (Join-Path $script:StepsRoot $Name) -Algorithm SHA256).Hash.ToLowerInvariant()
        $global:VirtuSpherePackageRepairFixture.Registry["RepairFixture-$Name"] = "Erfolg:$hash - previous run"
    }
    function Add-RepairReporterBundle {
        param([AllowNull()][int]$LoopbackPort = 0)
        $reporting = Join-Path $script:PackageRoot 'reporting'
        $null = New-Item -ItemType Directory -Path $reporting -Force
        $names = @('VirtuSphere-Client-Common.ps1', 'VirtuSphere-Client-Logging.ps1',
            'VirtuSphere-Package-Reporter.ps1', 'VirtuSphere-Package-ReporterHost.ps1') | Sort-Object
        $staging = Join-Path $reporting 'staging'
        $null = New-Item -ItemType Directory -Path $staging
        foreach ($name in $names) {
            Copy-Item -LiteralPath (Join-Path $script:ReporterClients $name) -Destination (Join-Path $staging $name)
        }
        # The synthetic Common can address only this test's loopback listener;
        # neither case can send to a configured real server on the test host.
        if ($LoopbackPort -gt 0) {
            $override = @'
function Get-VsPackageReportSnapshot {
    return [pscustomobject]@{
        MacCandidates = @('00:50:56:AA:BB:CC')
        RolloutRevision = [int]3
        DeviceGeneration = '018f2f49-5e41-4d55-8f05-8f55a5334102'
        AcceptanceGeneration = '018f2f49-5e41-4d55-8f05-8f55a5334103'
    }
}
function Get-VsPackageReportApiConfiguration {
    return [pscustomobject]@{
        Api = '127.0.0.1:__PORT__'
        Scheme = 'http'
        CertThumbprint = ''
        ReportUrl = 'http://127.0.0.1:__PORT__/mecm_report.php?action=reportPackageRun'
    }
}
'@.Replace('__PORT__', [string]$LoopbackPort)
        } else {
            $override = 'function Get-VsPackageReportSnapshot { return $null }'
        }
        Add-Content -LiteralPath (Join-Path $staging 'VirtuSphere-Client-Common.ps1') -Value $override
        $entries = @($names | ForEach-Object {
            $item = Get-Item -LiteralPath (Join-Path $staging $_)
            [pscustomobject]@{
                path = $_
                length = [long]$item.Length
                sha256 = (Get-FileHash -LiteralPath $item.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
            }
        })
        $basis = @($entries | ForEach-Object { '{0}|{1}|{2}' -f $_.path, $_.length, $_.sha256 }) -join "`n"
        $sha = [Security.Cryptography.SHA256]::Create()
        try {
            $bundleId = ([BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($basis))).Replace('-', '')).ToLowerInvariant()
        } finally { $sha.Dispose() }
        $bundleRoot = Join-Path $reporting $bundleId
        $reportingPrefix = [IO.Path]::GetFullPath($reporting).TrimEnd('\') + '\'
        if (-not [IO.Path]::GetFullPath($staging).StartsWith($reportingPrefix, [StringComparison]::OrdinalIgnoreCase) -or
            -not [IO.Path]::GetFullPath($bundleRoot).StartsWith($reportingPrefix, [StringComparison]::OrdinalIgnoreCase)) {
            throw 'Synthetic reporter move left its TestDrive reporting root.'
        }
        Move-Item -LiteralPath $staging -Destination $bundleRoot
        $contracts = [ordered]@{ common = 1; logging = 1; adapter = 1; host = 1 }
        $manifest = [ordered]@{ schema_version = 1; bundle_id = $bundleId; contracts = $contracts; files = $entries }
        $descriptor = [ordered]@{
            schema_version = 1
            bundle_id = $bundleId
            wrapper_sha256 = (Get-FileHash -LiteralPath (Join-Path $script:PackageRoot 'install.ps1') -Algorithm SHA256).Hash.ToLowerInvariant()
            contracts = $contracts
        }
        [IO.File]::WriteAllText((Join-Path $bundleRoot 'manifest.json'), (($manifest | ConvertTo-Json -Depth 5) + "`n"), (New-Object Text.UTF8Encoding($false)))
        [IO.File]::WriteAllText((Join-Path $reporting 'current.json'), (($descriptor | ConvertTo-Json -Depth 4) + "`n"), (New-Object Text.UTF8Encoding($false)))
    }
    function Start-RepairReportServer {
        param([ValidateSet('ack', 'backpressure', 'stall')][string]$Mode = 'ack')
        $reservation = New-Object Net.Sockets.TcpListener ([Net.IPAddress]::Loopback, 0)
        $reservation.Start()
        try { $port = ([Net.IPEndPoint]$reservation.LocalEndpoint).Port } finally { $reservation.Stop() }
        $ready = Join-Path $script:PackageRoot 'report-server.ready'
        $job = Start-Job -ArgumentList @($port, $ready, $Mode) -ScriptBlock {
            param([int]$Port, [string]$Ready, [string]$Mode)
            $listener = New-Object Net.Sockets.TcpListener ([Net.IPAddress]::Loopback, $Port)
            $listener.Start()
            $received = New-Object 'System.Collections.Generic.List[object]'
            try {
                [IO.File]::WriteAllText($Ready, 'ready')
                $expectedCount = if ($Mode -eq 'ack') { 4 } else { 1 }
                for ($requestIndex = 0; $requestIndex -lt $expectedCount; $requestIndex++) {
                    $pending = [Diagnostics.Stopwatch]::StartNew()
                    while (-not $listener.Pending() -and $pending.ElapsedMilliseconds -lt 12000) {
                        Start-Sleep -Milliseconds 25
                    }
                    if (-not $listener.Pending()) { throw "Synthetic HTTP event $requestIndex did not arrive." }
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
                        if ($length -lt 1 -or $length -gt 65536) { throw 'Request size out of bounds.' }
                        $payload = New-Object byte[] $length
                        $offset = 0
                        while ($offset -lt $length) {
                            $read = $stream.Read($payload, $offset, $length - $offset)
                            if ($read -le 0) { throw 'Request body ended early.' }
                            $offset += $read
                        }
                        $body = [Text.Encoding]::UTF8.GetString($payload) | ConvertFrom-Json
                        [void]$received.Add([pscustomobject]@{ event = $body.event; seq = $body.event_seq; body = $body })
                        if ($Mode -eq 'stall') {
                            Start-Sleep -Seconds 8
                            continue
                        }
                        if ($Mode -eq 'ack') {
                            $ack = [ordered]@{ schema_version = 1; run_id = $body.run_id; event = $body.event;
                                event_seq = $body.event_seq; accepted = $true; deduplicated = $false }
                            $response = [Text.Encoding]::UTF8.GetBytes(($ack | ConvertTo-Json -Compress))
                            $status = '200 OK'
                            $extra = ''
                        } else {
                            $response = [Text.Encoding]::UTF8.GetBytes('{}')
                            $status = '503 Service Unavailable'
                            $extra = "Retry-After: 30`r`n"
                        }
                        $head = [Text.Encoding]::ASCII.GetBytes(
                            "HTTP/1.1 $status`r`nContent-Type: application/json; charset=utf-8`r`nContent-Length: $($response.Length)`r`n$extra" +
                            "Connection: close`r`n`r`n")
                        $stream.Write($head, 0, $head.Length)
                        $stream.Write($response, 0, $response.Length)
                        $stream.Flush()
                    } finally {
                        $client.Dispose()
                    }
                }
                return $received.ToArray()
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
            Remove-Job -Job $job -Force -ErrorAction SilentlyContinue
            throw 'Synthetic report server did not become ready.'
        }
        return [pscustomobject]@{ Port = $port; Job = $job }
    }
}

Describe 'Package repair invalidates only an actual hash-miss before child execution' {
    BeforeEach {
        $script:PackageRoot = Join-Path $TestDrive ([guid]::NewGuid().ToString('N'))
        $script:StepsRoot = Join-Path $script:PackageRoot 'powershell'
        $null = New-Item -ItemType Directory -Path $script:StepsRoot -Force
        Copy-Item -LiteralPath $script:Template -Destination (Join-Path $script:PackageRoot 'install.ps1')
        Set-Content -LiteralPath (Join-Path $script:PackageRoot 'config.json') -Encoding UTF8 -Value '{"ProjectName":"RepairFixture","version":"1","InstallationBehaviorType":"InstallForSystem","ErrorAction":"Stop"}'
        Set-Content -LiteralPath (Join-Path $script:StepsRoot '01.ps1') -Encoding UTF8 -Value '# first payload'
        Set-Content -LiteralPath (Join-Path $script:StepsRoot '02.ps1') -Encoding UTF8 -Value '# second payload'
        $global:VirtuSpherePackageRepairFixture = @{
            Registry = @{ Version = '1' }
            Children = [System.Collections.Generic.List[string]]::new()
            ChildCodes = @{ '01.ps1' = 0; '02.ps1' = 0 }
            RemoveCount = 0
            RegistryReadFails = $false
            RegistryRemoveFails = $false
            ChildThrows = $false
            MarkerWriteFails = $false
        }
        $script:OldProgramData = $env:ProgramData
        $script:OldLastExitCode = Get-Variable -Name LASTEXITCODE -Scope Global -ErrorAction SilentlyContinue
        $script:HadLastExitCode = $null -ne $script:OldLastExitCode
        $script:OldLastExitValue = if ($script:HadLastExitCode) { $script:OldLastExitCode.Value } else { $null }
        $env:ProgramData = $script:PackageRoot
        Set-RepairStepMarker '01.ps1'
        Set-RepairStepMarker '02.ps1'

        Mock Test-Path {
            $fileSystemPath = if ($LiteralPath) { [string]$LiteralPath } else { [string]$Path }
            if (-not [System.IO.Path]::IsPathRooted($fileSystemPath)) {
                $fileSystemPath = Join-Path (Get-Location).Path $fileSystemPath
            }
            [System.IO.File]::Exists($fileSystemPath) -or [System.IO.Directory]::Exists($fileSystemPath)
        }
        Mock Test-Path { $true } -ParameterFilter { $Path -like 'HKLM:*' }
        Mock Get-ItemPropertyValue { $global:VirtuSpherePackageRepairFixture.Registry[$Name] }
        Mock Get-ItemProperty {
            if ($global:VirtuSpherePackageRepairFixture.RegistryReadFails) { throw 'registry read denied' }
            [pscustomobject]$global:VirtuSpherePackageRepairFixture.Registry
        }
        Mock Remove-ItemProperty {
            if ($global:VirtuSpherePackageRepairFixture.RegistryRemoveFails) { throw 'registry delete denied' }
            $valueNames = @($Name)
            $valueNames.Count | Should -Be 1
            $valueNames[0] | Should -Be 'Version'
            $global:VirtuSpherePackageRepairFixture.RemoveCount++
            $global:VirtuSpherePackageRepairFixture.Registry.Remove([string]$valueNames[0])
        }
        Mock Set-ItemProperty {
            if ($global:VirtuSpherePackageRepairFixture.MarkerWriteFails) { throw 'registry write denied' }
            $global:VirtuSpherePackageRepairFixture.Registry[$Name] = $Value
        }
        Mock PowerShell.exe {
            # This assertion pins the mutation boundary, not just final state.
            $global:VirtuSpherePackageRepairFixture.Registry.ContainsKey('Version') | Should -BeFalse
            $name = Split-Path $File -Leaf
            $global:VirtuSpherePackageRepairFixture.Children.Add($name)
            if ($global:VirtuSpherePackageRepairFixture.ChildThrows) { throw 'child launch failed' }
            $global:LASTEXITCODE = $global:VirtuSpherePackageRepairFixture.ChildCodes[$name]
        }
    }

    AfterEach {
        $env:ProgramData = $script:OldProgramData
        Remove-Variable -Name VirtuSpherePackageRepairFixture -Scope Global -ErrorAction SilentlyContinue
        if ($script:HadLastExitCode) {
            Set-Variable -Name LASTEXITCODE -Scope Global -Value $script:OldLastExitValue
        } else {
            Remove-Variable -Name LASTEXITCODE -Scope Global -ErrorAction SilentlyContinue
        }
    }

    It 'preserves detection and does not launch children for exact successful hashes' {
        Invoke-RepairWrapper
        $script:WrapperExit | Should -Be 0
        $global:VirtuSpherePackageRepairFixture.Children.Count | Should -Be 0
        $global:VirtuSpherePackageRepairFixture.RemoveCount | Should -Be 0
        $global:VirtuSpherePackageRepairFixture.Registry.Version | Should -Be '1'
    }

    It 'writes a paired UTF-8 BOM run record with skips and one completed summary' {
        Invoke-RepairWrapper
        $script:WrapperExit | Should -Be 0

        $runLogs = @(Get-ChildItem -LiteralPath (Join-Path $script:PackageRoot 'VirtuSphere\Logs\PackageWrapper') -File -Recurse)
        @($runLogs | Where-Object Name -like 'wrapper_*.log').Count | Should -Be 1
        @($runLogs | Where-Object Name -like 'reporting_*.log').Count | Should -Be 1
        foreach ($file in $runLogs) {
            $bytes = [IO.File]::ReadAllBytes($file.FullName)
            @($bytes[0..2]) | Should -Be @(0xef, 0xbb, 0xbf)
        }

        $wrapper = $runLogs | Where-Object Name -like 'wrapper_*.log' | Select-Object -First 1
        $reporting = $runLogs | Where-Object Name -like 'reporting_*.log' | Select-Object -First 1
        $wrapperRecords = @(Get-Content -LiteralPath $wrapper.FullName | ForEach-Object { $_ | ConvertFrom-Json })
        $reportingRecords = @(Get-Content -LiteralPath $reporting.FullName | ForEach-Object { $_ | ConvertFrom-Json })
        @($wrapperRecords.event) | Should -Be @('header', 'inventory', 'step_run', 'step_result', 'step_run', 'step_result', 'completed')
        @($wrapperRecords | Where-Object event -eq 'step_result').outcome | Should -Be @('SKIP', 'SKIP')
        $completed = $wrapperRecords | Where-Object event -eq 'completed'
        $completed.exit_code | Should -Be 0
        $completed.detection_status | Should -Be 'written'
        $completed.total | Should -Be 2
        $completed.processed | Should -Be 2
        $completed.skip | Should -Be 2
        @($reportingRecords.event) | Should -Be @('header', 'reporting_disabled', 'reporting_budget')
        $reportingRecords[1].reason | Should -Be 'bundle_unavailable'
        $reportingRecords[2].active_ms | Should -BeGreaterOrEqual 0
        $wrapperRecords[0].run_id | Should -Be $reportingRecords[0].run_id
        $wrapperRecords[0].partner_file | Should -Be $reporting.Name
        $reportingRecords[0].partner_file | Should -Be $wrapper.Name
    }

    It 'preserves detection and exit when a verified worker lacks a published identity' {
        Add-RepairReporterBundle
        Invoke-RepairWrapper
        $script:WrapperExit | Should -Be 0
        $reporting = Get-ChildItem -LiteralPath (Join-Path $script:PackageRoot 'VirtuSphere\Logs\PackageWrapper') `
            -Filter 'reporting_*.log' -File -Recurse | Select-Object -First 1
        $records = @(Get-Content -LiteralPath $reporting.FullName | ForEach-Object { $_ | ConvertFrom-Json })
        @($records.event) | Should -Be @('header', 'report_attempt', 'reporting_disabled', 'reporting_budget')
        $records[1].report_event | Should -Be 'started'
        $records[1].attempted | Should -BeFalse
        $records[1].reason | Should -Be 'identity_unavailable'
        $records[2].reason | Should -Be 'identity_unavailable'
        $records[3].active_ms | Should -BeLessThan 10000
    }

    It 'sends started, both skips and completion once to a synthetic loopback receiver' {
        $server = Start-RepairReportServer
        try {
            Add-RepairReporterBundle -LoopbackPort $server.Port
            Invoke-RepairWrapper
            $script:WrapperExit | Should -Be 0
            $done = Wait-Job -Job $server.Job -Timeout 10
            if ($null -eq $done) {
                $diagnosticLog = Get-ChildItem -LiteralPath (Join-Path $script:PackageRoot 'VirtuSphere\Logs\PackageWrapper') `
                    -Filter 'reporting_*.log' -File -Recurse | Select-Object -First 1
                $diagnosticRecords = @(Get-Content -LiteralPath $diagnosticLog.FullName | ForEach-Object { $_ | ConvertFrom-Json })
                Write-Host ('Synthetic receiver incomplete: state={0}, attempts={1}, reasons={2}' -f
                    $server.Job.State, @($diagnosticRecords | Where-Object event -eq 'report_attempt').Count,
                    (@($diagnosticRecords | Where-Object event -eq 'report_attempt' | ForEach-Object reason) -join ','))
            }
            $done | Should -Not -BeNullOrEmpty
            $events = @(Receive-Job -Job $server.Job -ErrorAction Stop)
            @($events.event) | Should -Be @('started', 'step_result', 'step_result', 'completed')
            @($events.seq) | Should -Be @(1, 2, 3, 4)
            $events[1].body.result | Should -Be 'skip'
            $events[2].body.result | Should -Be 'skip'
            $events[3].body.wrapper_result | Should -Be 'ok'
            $events[3].body.processed_count | Should -Be 2
            $events[3].body.skip_count | Should -Be 2
            $events[3].body.first_failure | Should -BeNullOrEmpty
            $reporting = Get-ChildItem -LiteralPath (Join-Path $script:PackageRoot 'VirtuSphere\Logs\PackageWrapper') `
                -Filter 'reporting_*.log' -File -Recurse | Select-Object -First 1
            $records = @(Get-Content -LiteralPath $reporting.FullName | ForEach-Object { $_ | ConvertFrom-Json })
            @($records | Where-Object event -eq 'report_attempt').Count | Should -Be 4
            @($records | Where-Object event -eq 'report_attempt' | ForEach-Object confirmed) | Should -Be @($true, $true, $true, $true)
            ($records | Where-Object event -eq 'reporting_budget').active_ms | Should -BeLessThan 10000
        } finally {
            Stop-Job -Job $server.Job -ErrorAction SilentlyContinue
            Remove-Job -Job $server.Job -Force -ErrorAction SilentlyContinue
        }
    }

    It 'does not bypass server Retry-After for steps or completion' {
        $server = Start-RepairReportServer -Mode backpressure
        try {
            Add-RepairReporterBundle -LoopbackPort $server.Port
            Invoke-RepairWrapper
            $script:WrapperExit | Should -Be 0
            (Wait-Job -Job $server.Job -Timeout 5) | Should -Not -BeNullOrEmpty
            $received = @(Receive-Job -Job $server.Job -ErrorAction Stop)
            @($received.event) | Should -Be @('started')
            $reporting = Get-ChildItem -LiteralPath (Join-Path $script:PackageRoot 'VirtuSphere\Logs\PackageWrapper') `
                -Filter 'reporting_*.log' -File -Recurse | Select-Object -First 1
            $records = @(Get-Content -LiteralPath $reporting.FullName | ForEach-Object { $_ | ConvertFrom-Json })
            $attempts = @($records | Where-Object event -eq 'report_attempt')
            @($attempts.report_event) | Should -Be @('started', 'step_result', 'step_result', 'completed')
            @($attempts.reason) | Should -Be @('http_status', 'backpressure', 'backpressure', 'backpressure')
            @($attempts.attempted) | Should -Be @($true, $false, $false, $false)
            ($records | Where-Object event -eq 'reporting_budget').active_ms | Should -BeLessThan 10000
        } finally {
            Stop-Job -Job $server.Job -ErrorAction SilentlyContinue
            Remove-Job -Job $server.Job -Force -ErrorAction SilentlyContinue
        }
    }

    It 'keeps package success when a report response stalls beyond the IPC deadline' {
        $server = Start-RepairReportServer -Mode stall
        try {
            Add-RepairReporterBundle -LoopbackPort $server.Port
            $watch = [Diagnostics.Stopwatch]::StartNew()
            Invoke-RepairWrapper
            $watch.Stop()
            $script:WrapperExit | Should -Be 0
            $watch.ElapsedMilliseconds | Should -BeLessThan 10000
            (Wait-Job -Job $server.Job -Timeout 10) | Should -Not -BeNullOrEmpty
            $received = @(Receive-Job -Job $server.Job -ErrorAction Stop)
            @($received.event) | Should -Be @('started')
            $reporting = Get-ChildItem -LiteralPath (Join-Path $script:PackageRoot 'VirtuSphere\Logs\PackageWrapper') `
                -Filter 'reporting_*.log' -File -Recurse | Select-Object -First 1
            $records = @(Get-Content -LiteralPath $reporting.FullName | ForEach-Object { $_ | ConvertFrom-Json })
            ($records | Where-Object event -eq 'reporting_disabled').reason | Should -Be 'ipc_unconfirmed'
            ($records | Where-Object event -eq 'reporting_budget').active_ms | Should -BeLessThan 10000
        } finally {
            Stop-Job -Job $server.Job -ErrorAction SilentlyContinue
            Remove-Job -Job $server.Job -Force -ErrorAction SilentlyContinue
        }
    }

    It 'retains five paired run groups and leaves a locked older group intact' {
        1..5 | ForEach-Object { Invoke-RepairWrapper; $script:WrapperExit | Should -Be 0 }
        $logRoot = Join-Path $script:PackageRoot 'VirtuSphere\Logs\PackageWrapper'
        $groupsBefore = @(Get-ChildItem -LiteralPath $logRoot -File -Recurse | Group-Object { $_.BaseName -replace '^(wrapper|reporting)_', '' })
        $groupsBefore.Count | Should -Be 5
        $oldestWrapper = Get-ChildItem -LiteralPath $logRoot -Filter 'wrapper_*.log' -File -Recurse | Sort-Object Name | Select-Object -First 1
        $lock = New-Object IO.FileStream($oldestWrapper.FullName, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]::Read)
        try {
            Invoke-RepairWrapper
            $script:WrapperExit | Should -Be 0
            $groupsLocked = @(Get-ChildItem -LiteralPath $logRoot -File -Recurse | Group-Object { $_.BaseName -replace '^(wrapper|reporting)_', '' })
            $groupsLocked.Count | Should -Be 6
        } finally {
            $lock.Dispose()
        }
        Invoke-RepairWrapper
        $script:WrapperExit | Should -Be 0
        $groupsAfter = @(Get-ChildItem -LiteralPath $logRoot -File -Recurse | Group-Object { $_.BaseName -replace '^(wrapper|reporting)_', '' })
        $groupsAfter.Count | Should -Be 5
        @($groupsAfter | Where-Object Count -ne 2).Count | Should -Be 0
    }

    It 'continues the package decision when the managed log ACL cannot be checked' {
        Mock Get-Acl { throw 'acl unavailable' }
        Invoke-RepairWrapper
        $script:WrapperExit | Should -Be 0
        $global:VirtuSpherePackageRepairFixture.Registry.Version | Should -Be '1'
        $global:VirtuSpherePackageRepairFixture.Children.Count | Should -Be 0
        Test-Path -LiteralPath (Join-Path $script:PackageRoot 'VirtuSphere\Logs\PackageWrapper') | Should -BeFalse
    }

    It 'accepts read-only Users access without disabling package logs' {
        $global:VirtuSpherePackageRepairFixture.LogAcl = [Security.AccessControl.DirectorySecurity]::new()
        $usersSid = [Security.Principal.SecurityIdentifier]::new('S-1-5-32-545')
        $readRule = [Security.AccessControl.FileSystemAccessRule]::new(
            $usersSid,
            [Security.AccessControl.FileSystemRights]::ReadAndExecute,
            [Security.AccessControl.InheritanceFlags]::ContainerInherit,
            [Security.AccessControl.PropagationFlags]::None,
            [Security.AccessControl.AccessControlType]::Allow
        )
        $global:VirtuSpherePackageRepairFixture.LogAcl.AddAccessRule($readRule)
        $global:VirtuSpherePackageRepairFixture.LogAcl.Access.Count | Should -Be 1
        Mock Get-Acl { $global:VirtuSpherePackageRepairFixture.LogAcl }

        Invoke-RepairWrapper
        $script:WrapperExit | Should -Be 0
        $logRoot = Join-Path $script:PackageRoot 'VirtuSphere\Logs\PackageWrapper'
        Get-Content -LiteralPath (Join-Path $script:PackageRoot 'wrapper.log') -Raw | Should -Not -Match 'deaktiviert'
        @(Get-ChildItem -LiteralPath $logRoot -File -Recurse).Count | Should -Be 2
    }

    It 'disables package logs for writable Users access without changing detection' {
        $global:VirtuSpherePackageRepairFixture.LogAcl = [Security.AccessControl.DirectorySecurity]::new()
        $usersSid = [Security.Principal.SecurityIdentifier]::new('S-1-5-32-545')
        $writeRule = [Security.AccessControl.FileSystemAccessRule]::new(
            $usersSid,
            [Security.AccessControl.FileSystemRights]::Write,
            [Security.AccessControl.InheritanceFlags]::ContainerInherit,
            [Security.AccessControl.PropagationFlags]::None,
            [Security.AccessControl.AccessControlType]::Allow
        )
        $global:VirtuSpherePackageRepairFixture.LogAcl.AddAccessRule($writeRule)
        Mock Get-Acl { $global:VirtuSpherePackageRepairFixture.LogAcl }

        Invoke-RepairWrapper
        $script:WrapperExit | Should -Be 0
        $global:VirtuSpherePackageRepairFixture.Registry.Version | Should -Be '1'
        Test-Path -LiteralPath (Join-Path $script:PackageRoot 'VirtuSphere\Logs\PackageWrapper') | Should -BeFalse
    }

    It 'removes old detection before changed content, including failure and reboot <Code>' -TestCases @(
        @{ Code = 1; Expected = 1; Detected = $false }
        @{ Code = 1641; Expected = 1641; Detected = $false }
        @{ Code = 0; Expected = 0; Detected = $true }
        @{ Code = 3010; Expected = 3010; Detected = $true }
        @{ Code = 1707; Expected = 0; Detected = $true }
    ) {
        param($Code, $Expected, $Detected)
        Add-Content -LiteralPath (Join-Path $script:StepsRoot '02.ps1') -Value '# changed under same version'
        $global:VirtuSpherePackageRepairFixture.ChildCodes['02.ps1'] = $Code
        Invoke-RepairWrapper
        $script:WrapperExit | Should -Be $Expected
        @($global:VirtuSpherePackageRepairFixture.Children) | Should -Be @('02.ps1')
        $global:VirtuSpherePackageRepairFixture.RemoveCount | Should -Be 1
        $global:VirtuSpherePackageRepairFixture.Registry.ContainsKey('Version') | Should -Be $Detected
    }

    It 'resumes after 1641, skipping the completed hash and running the remaining step' {
        $global:VirtuSpherePackageRepairFixture.Registry.Remove('RepairFixture-01.ps1')
        $global:VirtuSpherePackageRepairFixture.Registry.Remove('RepairFixture-02.ps1')
        $global:VirtuSpherePackageRepairFixture.ChildCodes['01.ps1'] = 1641
        Invoke-RepairWrapper
        $script:WrapperExit | Should -Be 1641
        @($global:VirtuSpherePackageRepairFixture.Children) | Should -Be @('01.ps1')
        $global:VirtuSpherePackageRepairFixture.Registry.ContainsKey('Version') | Should -BeFalse

        Invoke-RepairWrapper
        $script:WrapperExit | Should -Be 0
        @($global:VirtuSpherePackageRepairFixture.Children) | Should -Be @('01.ps1', '02.ps1')
        $global:VirtuSpherePackageRepairFixture.Registry.Version | Should -Be '1'
        $global:VirtuSpherePackageRepairFixture.RemoveCount | Should -Be 1
    }

    It 'keeps failed repair undetected with Continue even when the next child succeeds' {
        Set-Content -LiteralPath (Join-Path $script:PackageRoot 'config.json') -Encoding UTF8 -Value '{"ProjectName":"RepairFixture","version":"1","InstallationBehaviorType":"InstallForSystem","ErrorAction":"Continue"}'
        $global:VirtuSpherePackageRepairFixture.Registry.Remove('RepairFixture-01.ps1')
        $global:VirtuSpherePackageRepairFixture.Registry.Remove('RepairFixture-02.ps1')
        $global:VirtuSpherePackageRepairFixture.ChildCodes['01.ps1'] = 1
        Invoke-RepairWrapper
        $script:WrapperExit | Should -Be 1
        @($global:VirtuSpherePackageRepairFixture.Children) | Should -Be @('01.ps1', '02.ps1')
        $global:VirtuSpherePackageRepairFixture.Registry.ContainsKey('Version') | Should -BeFalse
    }

    It 'retries a failed hash while preserving the other completed step' {
        Add-Content -LiteralPath (Join-Path $script:StepsRoot '02.ps1') -Value '# changed content'
        $global:VirtuSpherePackageRepairFixture.ChildCodes['02.ps1'] = 1
        Invoke-RepairWrapper
        $script:WrapperExit | Should -Be 1
        $global:VirtuSpherePackageRepairFixture.Registry.ContainsKey('Version') | Should -BeFalse

        $global:VirtuSpherePackageRepairFixture.ChildCodes['02.ps1'] = 0
        Invoke-RepairWrapper
        $script:WrapperExit | Should -Be 0
        @($global:VirtuSpherePackageRepairFixture.Children) | Should -Be @('02.ps1', '02.ps1')
        $global:VirtuSpherePackageRepairFixture.Registry.Version | Should -Be '1'
        $global:VirtuSpherePackageRepairFixture.RemoveCount | Should -Be 1
    }

    It 'fails closed before payload if marker invalidation cannot be established (<Fault>)' -TestCases @(
        @{ Fault = 'read' }
        @{ Fault = 'remove' }
    ) {
        param($Fault)
        $global:VirtuSpherePackageRepairFixture.Registry.Remove('RepairFixture-02.ps1')
        $global:VirtuSpherePackageRepairFixture.RegistryReadFails = $Fault -eq 'read'
        $global:VirtuSpherePackageRepairFixture.RegistryRemoveFails = $Fault -eq 'remove'
        Invoke-RepairWrapper
        $script:WrapperExit | Should -Be 1
        $global:VirtuSpherePackageRepairFixture.Children.Count | Should -Be 0
        $global:VirtuSpherePackageRepairFixture.Registry.Version | Should -Be '1'
    }

    It 'leaves detection absent on child launch or step-marker write failure (<Fault>)' -TestCases @(
        @{ Fault = 'launch' }
        @{ Fault = 'write' }
    ) {
        param($Fault)
        $global:VirtuSpherePackageRepairFixture.Registry.Remove('RepairFixture-02.ps1')
        $global:VirtuSpherePackageRepairFixture.ChildThrows = $Fault -eq 'launch'
        $global:VirtuSpherePackageRepairFixture.MarkerWriteFails = $Fault -eq 'write'
        Invoke-RepairWrapper
        $script:WrapperExit | Should -Be 1
        $global:VirtuSpherePackageRepairFixture.Registry.ContainsKey('Version') | Should -BeFalse
    }
}
