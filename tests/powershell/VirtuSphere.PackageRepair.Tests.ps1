# U10: run the actual wrapper with isolated files and mocked registry/children.
# No registry provider or child payload is reached by these tests.
BeforeAll {
    $script:Template = Join-Path (Split-Path (Split-Path $PSScriptRoot -Parent) -Parent) 'Powershell-MECM/Package_Vorlage/install.ps1'
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
        @($reportingRecords.event) | Should -Be @('header', 'reporting_disabled')
        $wrapperRecords[0].run_id | Should -Be $reportingRecords[0].run_id
        $wrapperRecords[0].partner_file | Should -Be $reporting.Name
        $reportingRecords[0].partner_file | Should -Be $wrapper.Name
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
