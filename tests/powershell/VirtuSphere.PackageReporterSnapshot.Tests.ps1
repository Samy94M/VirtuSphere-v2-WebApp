BeforeAll {
    $script:ClientCommon = Join-Path (Split-Path (Split-Path $PSScriptRoot -Parent) -Parent) 'Powershell-MECM/clients/VirtuSphere-Client-Common.ps1'
    function Read-ReporterSnapshot {
        . $script:ClientCommon
        Get-VsPackageReportSnapshot
    }
}

Describe 'T4 package reporter reads one frozen published client identity' {
    BeforeEach {
        $global:VsReporterSnapshotFixture = @{
            Id = '0123456789abcdef0123456789abcdef'
            BaseReads = 0
            MetaReads = 0
            ChildReads = 0
            EntryReads = 0
            ErrorText = ''
            ReadIndices = [System.Collections.Generic.List[int]]::new()
            SwitchActive = $false
            LoseRoot = $false
            MissingInterface = $false
            Macs = @('00:50:56:FF:EE:DD', '00:50:56:AA:BB:CC')
            DeviceGeneration = '018f2f49-5e41-4d55-8f05-8f55a5334102'
            AcceptanceGeneration = '018f2f49-5e41-4d55-8f05-8f55a5334103'
        }
        Mock Get-ItemProperty {
            $fixture = $global:VsReporterSnapshotFixture
            if ($Path -eq 'HKLM:\SOFTWARE\VirtuSphere') {
                $fixture.BaseReads++
                $active = if ($fixture.SwitchActive -and $fixture.BaseReads -gt 1) { 'fedcba9876543210fedcba9876543210' } else { $fixture.Id }
                return [pscustomobject]@{ ActiveSnapshot = $active; SetupState = 'complete' }
            }
            if ($Path -match '\\Snapshots\\[^\\]+$') {
                $fixture.MetaReads++
                if ($fixture.LoseRoot -and $fixture.MetaReads -gt 1) { throw 'snapshot removed' }
                return [pscustomobject]@{
                    SnapshotSchema = 1; SnapshotState = 'published'; InterfaceCount = $fixture.Macs.Count
                    rollout_revision = '3'; device_generation = $fixture.DeviceGeneration
                    acceptance_generation = $fixture.AcceptanceGeneration
                }
            }
            if ($Path -match '\\Interfaces\\Interface([0-9]+)$') {
                $fixture.EntryReads++
                $index = [int]([regex]::Match($Path, '\\Interfaces\\Interface([0-9]+)$').Groups[1].Value)
                $fixture.ReadIndices.Add($index)
                if ($fixture.MissingInterface -and $index -eq 1) { throw 'interface removed' }
                return [pscustomobject]@{ mac = $fixture.Macs[$index] }
            }
            throw "unexpected registry read: $Path"
        }
        Mock Get-ChildItem {
            $global:VsReporterSnapshotFixture.ChildReads++
            @($global:VsReporterSnapshotFixture.Macs | ForEach-Object { [pscustomobject]@{ Name = $_ } })
        }
        Mock Invoke-RestMethod { throw 'report snapshot must not probe health' }
        Mock New-ItemProperty { throw 'report snapshot must not write the registry' }
        Mock Write-Debug { $global:VsReporterSnapshotFixture.ErrorText = [string]$Message }
    }

    AfterEach {
        Remove-Variable -Name VsReporterSnapshotFixture -Scope Global -ErrorAction SilentlyContinue
    }

    It 'reads every interface once, sorts canonical MACs and freezes both generations' {
        $result = Read-ReporterSnapshot
        $global:VsReporterSnapshotFixture.ChildReads | Should -Be 1
        $global:VsReporterSnapshotFixture.EntryReads | Should -Be 2
        $global:VsReporterSnapshotFixture.ReadIndices.ToArray() | Should -Be @(0, 1)
        $global:VsReporterSnapshotFixture.ErrorText | Should -Be ''
        $global:VsReporterSnapshotFixture.MetaReads | Should -Be 2
        $global:VsReporterSnapshotFixture.BaseReads | Should -Be 2
        $result.SnapshotId | Should -Be $global:VsReporterSnapshotFixture.Id
        $result.MacCandidates | Should -Be @('00:50:56:AA:BB:CC', '00:50:56:FF:EE:DD')
        $result.RolloutRevision | Should -Be 3
        $result.DeviceGeneration | Should -Be $global:VsReporterSnapshotFixture.DeviceGeneration
        $result.AcceptanceGeneration | Should -Be $global:VsReporterSnapshotFixture.AcceptanceGeneration
        Should -Invoke Invoke-RestMethod -Times 0
        Should -Invoke New-ItemProperty -Times 0
    }

    It 'refuses a changed active snapshot instead of mixing generations' {
        $global:VsReporterSnapshotFixture.SwitchActive = $true
        Read-ReporterSnapshot | Should -BeNullOrEmpty
    }

    It 'refuses a root removed during the final consistency read' {
        $global:VsReporterSnapshotFixture.LoseRoot = $true
        Read-ReporterSnapshot | Should -BeNullOrEmpty
    }

    It 'refuses a missing interface instead of falling back to the local NIC' {
        $global:VsReporterSnapshotFixture.MissingInterface = $true
        Read-ReporterSnapshot | Should -BeNullOrEmpty
    }

    It 'refuses duplicate MACs and a malformed restore generation' {
        $global:VsReporterSnapshotFixture.Macs = @('00:50:56:AA:BB:CC', '00:50:56:AA:BB:CC')
        Read-ReporterSnapshot | Should -BeNullOrEmpty
        $global:VsReporterSnapshotFixture.Macs = @('00:50:56:AA:BB:CC')
        $global:VsReporterSnapshotFixture.AcceptanceGeneration = 'invalid'
        Read-ReporterSnapshot | Should -BeNullOrEmpty
    }
}
