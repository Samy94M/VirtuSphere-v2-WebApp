BeforeAll {
    $repoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $wrapper = Join-Path $repoRoot 'Powershell-MECM/Package_Vorlage/install.ps1'
    $tokens = $null
    $errors = $null
    $ast = [Management.Automation.Language.Parser]::ParseFile($wrapper, [ref]$tokens, [ref]$errors)
    if ($errors.Count -ne 0) { throw 'Wrapper source does not parse.' }
    foreach ($name in @('Start-VsPackageReporterJobProcess', 'Start-VsPackageReporterPipeSession',
            'Close-VsPackageReporterPipeSession', 'Invoke-VsPackageReporterPipeExchange')) {
        $definition = $ast.Find({ param($node) $node -is [Management.Automation.Language.FunctionDefinitionAst] -and
            $node.Name -eq $name }, $true)
        if ($null -eq $definition) { throw "Reporter process owner is missing: $name" }
        . ([scriptblock]::Create($definition.Extent.Text))
    }
}

# Discovery-time capability flag, like $HasRegistry in VirtuSphere.ErrorPaths.Tests.ps1:
# the reporter child runs as a suspended powershell.exe inside a Win32 job object.
# pwsh on Linux has neither, so those cases are defined only on Windows, where
# the PS-5.1 CI job proves them. On Linux they would return $null for the wrong reason.
$HasWindowsJobObjects = [Environment]::OSVersion.Platform -eq [PlatformID]::Win32NT

Describe 'T4 reporter process starts suspended inside its private kill-on-close job' {
    It 'does not launch an unvalidated worker request' {
        $scriptPath = Join-Path $TestDrive 'nonexistent.ps1'
        Start-VsPackageReporterJobProcess -ScriptPath $scriptPath -PipeName 'bad' | Should -BeNullOrEmpty
    }

    # Windows only: these cases start the real job-bound child process.
    if ($HasWindowsJobObjects) {
        It 'does not probe a verified package host path synchronously in the wrapper' {
            $scriptPath = Join-Path $TestDrive 'missing-package-host.ps1'
            $pipeName = 'virtusphere-report-' + ([guid]::NewGuid()).ToString('N')
            Mock Test-Path {
                if ($LiteralPath -like '*missing-package-host.ps1') {
                    throw 'A package filesystem probe ran on the wrapper thread.'
                }
                return $true
            }
            $job = $null
            try {
                # A missing host is allowed to fail in its private job, where the
                # bounded ready handshake can disable reporting without blocking
                # the installation on an unbounded package filesystem operation.
                $job = Start-VsPackageReporterJobProcess -ScriptPath $scriptPath -PipeName $pipeName
                $job | Should -Not -BeNullOrEmpty
                Should -Invoke Test-Path -Times 0 -Exactly -ParameterFilter {
                    $LiteralPath -eq $scriptPath
                }
            } finally {
                if ($null -ne $job) { $job.Dispose() }
            }
        }

        It 'disables a missing verified host within the bounded ready handshake' {
            $scriptPath = Join-Path $TestDrive 'missing-bundle-host/VirtuSphere-Package-ReporterHost.ps1'
            $bundle = [pscustomobject]@{
                Root = Split-Path $scriptPath -Parent
                Files = @($scriptPath)
            }
            $clock = [Diagnostics.Stopwatch]::StartNew()
            Start-VsPackageReporterPipeSession -VerifiedBundle $bundle -TimeoutMs 1000 | Should -BeNullOrEmpty
            $clock.Stop()
            $clock.ElapsedMilliseconds | Should -BeLessThan 2000
        }

        It 'terminates only its worker when the wrapper closes the job handle' {
            $scriptPath = Join-Path $TestDrive 'worker.ps1'
            $markerPath = Join-Path $TestDrive 'worker-started.txt'
            $markerLiteral = $markerPath.Replace("'", "''")
            [IO.File]::WriteAllText($scriptPath, "param([string]`$VsReporterPipeName)`n[IO.File]::WriteAllText('$markerLiteral', `$VsReporterPipeName)`nStart-Sleep -Seconds 30`n")
            $pipeName = 'virtusphere-report-' + ([guid]::NewGuid()).ToString('N')
            $job = Start-VsPackageReporterJobProcess -ScriptPath $scriptPath -PipeName $pipeName
            $job | Should -Not -BeNullOrEmpty
            try {
                $job.ProcessId | Should -BeGreaterThan 0
                $watch = [Diagnostics.Stopwatch]::StartNew()
                while (-not (Test-Path -LiteralPath $markerPath) -and $watch.ElapsedMilliseconds -lt 5000) {
                    Start-Sleep -Milliseconds 25
                }
                (Get-Content -LiteralPath $markerPath -Raw) | Should -Be $pipeName
                $job.IsRunning | Should -BeTrue
            } finally {
                $job.Dispose()
            }
            $watch = [Diagnostics.Stopwatch]::StartNew()
            while ((Get-Process -Id $job.ProcessId -ErrorAction SilentlyContinue) -and $watch.ElapsedMilliseconds -lt 3000) {
                Start-Sleep -Milliseconds 25
            }
            Get-Process -Id $job.ProcessId -ErrorAction SilentlyContinue | Should -BeNullOrEmpty
        }

        It 'authenticates the actual bundle worker and reads one bounded ready handshake' {
            $bundleRoot = Join-Path $TestDrive 'bundle'
            $null = New-Item -ItemType Directory -Path $bundleRoot -Force
            $clients = Join-Path $repoRoot 'Powershell-MECM/clients'
            $paths = @('VirtuSphere-Client-Common.ps1', 'VirtuSphere-Client-Logging.ps1',
                'VirtuSphere-Package-Reporter.ps1', 'VirtuSphere-Package-ReporterHost.ps1') |
                ForEach-Object {
                    $destination = Join-Path $bundleRoot $_
                    Copy-Item -LiteralPath (Join-Path $clients $_) -Destination $destination
                    $destination
                }
            $bundle = [pscustomobject]@{ Root = $bundleRoot; Files = $paths }
            $session = Start-VsPackageReporterPipeSession -VerifiedBundle $bundle -TimeoutMs 2000
            $session | Should -Not -BeNullOrEmpty
            try {
                $session.Job.IsRunning | Should -BeTrue
                $response = Invoke-VsPackageReporterPipeExchange -Session $session `
                    -Message ([pscustomobject]@{ event = 'unknown'; event_seq = 1 }) -TimeoutMs 2000
                $response.schema_version | Should -Be 1
                $response.reason | Should -Be 'invalid_request'
                $response.confirmed | Should -BeFalse
            } finally {
                Close-VsPackageReporterPipeSession -Session $session
            }
        }
    }
}
