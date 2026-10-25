BeforeAll {
    $repoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $wrapperSource = Join-Path $repoRoot 'Powershell-MECM/Package_Vorlage/install.ps1'
    $clients = Join-Path $repoRoot 'Powershell-MECM/clients'
    $tokens = $null
    $errors = $null
    $ast = [Management.Automation.Language.Parser]::ParseFile($wrapperSource, [ref]$tokens, [ref]$errors)
    if ($errors.Count -ne 0) { throw 'Wrapper source does not parse.' }
    foreach ($name in @('Get-VsPackageVerifiedReporterBundle', 'Start-VsPackageReporterJobProcess',
            'Get-VsPackageSupervisedReporterBundle', 'Invoke-VsPackageBundleVerifyChild')) {
        $definition = $ast.Find({ param($node) $node -is [Management.Automation.Language.FunctionDefinitionAst] -and
            $node.Name -eq $name }, $true)
        if ($null -eq $definition) { throw "Wrapper bundle owner is missing: $name" }
        . ([scriptblock]::Create($definition.Extent.Text))
    }

    function New-VsReporterBundleFixture {
        param([string]$Root)
        $reporting = Join-Path $Root 'reporting'
        $null = New-Item -ItemType Directory -Path $reporting -Force
        Copy-Item -LiteralPath $wrapperSource -Destination (Join-Path $Root 'install.ps1')
        $entries = New-Object 'System.Collections.Generic.List[object]'
        foreach ($name in @('VirtuSphere-Client-Common.ps1', 'VirtuSphere-Client-Logging.ps1',
                'VirtuSphere-Package-Reporter.ps1', 'VirtuSphere-Package-ReporterHost.ps1') | Sort-Object) {
            $source = Get-Item -LiteralPath (Join-Path $clients $name)
            [void]$entries.Add([pscustomobject]@{
                path = $name
                length = [long]$source.Length
                sha256 = (Get-FileHash -LiteralPath $source.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
            })
        }
        $basis = @($entries.ToArray() | ForEach-Object { '{0}|{1}|{2}' -f $_.path, $_.length, $_.sha256 }) -join "`n"
        $sha = [Security.Cryptography.SHA256]::Create()
        try {
            $bundleId = ([BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($basis))).Replace('-', '')).ToLowerInvariant()
        } finally { $sha.Dispose() }
        $bundleRoot = Join-Path $reporting $bundleId
        $null = New-Item -ItemType Directory -Path $bundleRoot -Force
        foreach ($entry in $entries.ToArray()) {
            Copy-Item -LiteralPath (Join-Path $clients $entry.path) -Destination (Join-Path $bundleRoot $entry.path)
        }
        $contracts = [ordered]@{ common = 1; logging = 1; adapter = 1; host = 1 }
        $manifest = [ordered]@{ schema_version = 1; bundle_id = $bundleId; contracts = $contracts; files = @($entries.ToArray()) }
        $descriptor = [ordered]@{
            schema_version = 1
            bundle_id = $bundleId
            wrapper_sha256 = (Get-FileHash -LiteralPath (Join-Path $Root 'install.ps1') -Algorithm SHA256).Hash.ToLowerInvariant()
            contracts = $contracts
        }
        [IO.File]::WriteAllText((Join-Path $bundleRoot 'manifest.json'), (($manifest | ConvertTo-Json -Depth 5) + "`n"), (New-Object Text.UTF8Encoding($false)))
        [IO.File]::WriteAllText((Join-Path $reporting 'current.json'), (($descriptor | ConvertTo-Json -Depth 4) + "`n"), (New-Object Text.UTF8Encoding($false)))
        return [pscustomobject]@{ Root = $Root; BundleRoot = $bundleRoot; DescriptorPath = (Join-Path $reporting 'current.json'); ManifestPath = (Join-Path $bundleRoot 'manifest.json') }
    }
}

# Discovery-time capability flag, like $HasRegistry in VirtuSphere.ErrorPaths.Tests.ps1:
# the reporter child runs as a suspended powershell.exe inside a Win32 job object.
# pwsh on Linux has neither, so those cases are defined only on Windows, where
# the PS-5.1 CI job proves them. On Linux they would return $null for the wrong reason.
$HasWindowsJobObjects = [Environment]::OSVersion.Platform -eq [PlatformID]::Win32NT

Describe 'T4 wrapper reads only a complete bound reporter generation' {
    BeforeEach {
        $root = Join-Path $TestDrive ([guid]::NewGuid().ToString('N'))
        $script:fixture = New-VsReporterBundleFixture -Root $root
    }

    It 'accepts the exact four-file manifest bound to this wrapper' {
        $verified = Get-VsPackageVerifiedReporterBundle -PackageRoot $script:fixture.Root
        $verified.BundleId | Should -Match '^[0-9a-f]{64}$'
        @($verified.Files).Count | Should -Be 4
    }

    # Windows only: these cases start the real job-bound child process.
    if ($HasWindowsJobObjects) {
        It 'verifies the bundle in a supervised child before exposing its host path' {
            $clock = [Diagnostics.Stopwatch]::StartNew()
            $verified = Get-VsPackageSupervisedReporterBundle -PackageRoot $script:fixture.Root -TimeoutMs 5000
            $clock.Stop()
            $verified.BundleId | Should -Match '^[0-9a-f]{64}$'
            @($verified.Files).Count | Should -Be 4
            $clock.ElapsedMilliseconds | Should -BeLessThan 5500
        }

        It 'refuses a damaged generation through the supervised verifier' {
            Add-Content -LiteralPath (Join-Path $script:fixture.BundleRoot 'VirtuSphere-Package-ReporterHost.ps1') -Value '# damaged'
            Get-VsPackageSupervisedReporterBundle -PackageRoot $script:fixture.Root -TimeoutMs 5000 |
                Should -BeNullOrEmpty
        }

        It 'returns on its deadline when the separate verifier is stuck before hashing' {
            $original = ${function:Get-VsPackageVerifiedReporterBundle}
            try {
                Set-Item -Path function:Get-VsPackageVerifiedReporterBundle -Value {
                    param([string]$PackageRoot)
                    [IO.File]::WriteAllText((Join-Path $PackageRoot 'verifier-pid.txt'), [string]$PID)
                    Start-Sleep -Seconds 60
                }
                # The deadline must be long enough for the child to reach the stuck
                # stub even on a loaded host; a shorter one tests the start timeout
                # instead and leaves no PID to prove the kill (release lane 28.09.).
                $timeoutMs = 6000
                $clock = [Diagnostics.Stopwatch]::StartNew()
                $bundle = Get-VsPackageSupervisedReporterBundle -PackageRoot $script:fixture.Root -TimeoutMs $timeoutMs
                $clock.Stop()
                $bundle | Should -BeNullOrEmpty
                $pidFile = Join-Path $script:fixture.Root 'verifier-pid.txt'
                Test-Path -LiteralPath $pidFile | Should -BeTrue -Because 'the child must have reached the stuck verifier before its deadline'
                # The stuck child never answers, so only the deadline can end the
                # wait; a wait without deadline lasts until the 60 s stub ends. The
                # upper bound sits between both and leaves room for a stalled host:
                # under foreign load the test took 14.7 s while the wrapper spent
                # under 5 ms after its deadline and the child was gone within 50 ms (T1, 25.10.).
                $clock.ElapsedMilliseconds | Should -BeGreaterOrEqual ($timeoutMs - 100) -Because 'the stuck child never answers before the deadline'
                $clock.ElapsedMilliseconds | Should -BeLessThan 20000 -Because 'the wrapper returns on its deadline, not when the 60 s stub ends'
                $childId = [int](Get-Content -LiteralPath $pidFile -Raw)
                $endClock = [Diagnostics.Stopwatch]::StartNew()
                while ((Get-Process -Id $childId -ErrorAction SilentlyContinue) -and $endClock.ElapsedMilliseconds -lt 5000) {
                    Start-Sleep -Milliseconds 25
                }
                Get-Process -Id $childId -ErrorAction SilentlyContinue | Should -BeNullOrEmpty -Because 'closing the job kills the stuck child long before its stub ends'
            } finally {
                Set-Item -Path function:Get-VsPackageVerifiedReporterBundle -Value $original
            }
        }
    }

    It 'refuses a changed host without executing it' {
        Add-Content -LiteralPath (Join-Path $script:fixture.BundleRoot 'VirtuSphere-Package-ReporterHost.ps1') -Value '# modified'
        Get-VsPackageVerifiedReporterBundle -PackageRoot $script:fixture.Root | Should -BeNullOrEmpty
    }

    It 'refuses a wrapper/descriptor mismatch and a missing manifest entry' {
        Add-Content -LiteralPath (Join-Path $script:fixture.Root 'install.ps1') -Value '# modified'
        Get-VsPackageVerifiedReporterBundle -PackageRoot $script:fixture.Root | Should -BeNullOrEmpty
        $script:fixture = New-VsReporterBundleFixture -Root $script:fixture.Root
        Remove-Item -LiteralPath (Join-Path $script:fixture.BundleRoot 'VirtuSphere-Client-Logging.ps1')
        Get-VsPackageVerifiedReporterBundle -PackageRoot $script:fixture.Root | Should -BeNullOrEmpty
    }

    It 'refuses an extra bundle file and a descriptor pointing outside its closed generation' {
        Set-Content -LiteralPath (Join-Path $script:fixture.BundleRoot 'extra.ps1') -Value '# foreign'
        Get-VsPackageVerifiedReporterBundle -PackageRoot $script:fixture.Root | Should -BeNullOrEmpty
        Remove-Item -LiteralPath (Join-Path $script:fixture.BundleRoot 'extra.ps1')
        $descriptor = Get-Content -LiteralPath $script:fixture.DescriptorPath -Raw | ConvertFrom-Json
        $descriptor.bundle_id = '../other'
        [IO.File]::WriteAllText($script:fixture.DescriptorPath, ($descriptor | ConvertTo-Json -Depth 4))
        Get-VsPackageVerifiedReporterBundle -PackageRoot $script:fixture.Root | Should -BeNullOrEmpty
    }
}
