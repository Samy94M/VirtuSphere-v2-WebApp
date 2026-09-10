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
            $fileSystemPath = [string]$Path
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
