BeforeAll {
    $repoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    . (Join-Path $repoRoot 'Powershell-MECM/clients/VirtuSphere-Package-Reporter.ps1')
    $script:base = (Get-Content -LiteralPath (Join-Path $repoRoot 'Docker/WebAPI/tests/fixtures/package-report-v1.json') -Raw | ConvertFrom-Json).base
    function New-TestStarted {
        param([object]$Snapshot, [string]$RunId = $script:base.run_id,
            [string]$ProjectName = $script:base.project_name,
            [string]$ClientStartedAt = $script:base.client_started_at,
            [string]$EventAt = $script:base.event_at,
            [string]$Context = $script:base.context,
            [object]$Total = $script:base.total)
        if ($null -eq $Snapshot) {
            $Snapshot = [pscustomobject]@{
                MacCandidates = @($script:base.mac_candidates)
                RolloutRevision = [int]$script:base.rollout_revision
                DeviceGeneration = $script:base.device_generation
                AcceptanceGeneration = $script:base.acceptance_generation
            }
        }
        New-VsPackageReportStartedRequest -RunId $RunId -Snapshot $Snapshot -ProjectName $ProjectName `
            -PackageVersion $script:base.package_version -ClientStartedAt $ClientStartedAt `
            -EventAt $EventAt -Context $Context -Total $Total
    }
}

Describe 'T4 bounded V1 started request' {
    It 'emits exactly the common fixture fields as UTF-8 with stable correlation' {
        $request = New-TestStarted
        $request | Should -Not -BeNullOrEmpty
        $request.BodyBytes -is [byte[]] | Should -BeTrue
        $request.BodyBytes.Length | Should -BeLessOrEqual 65536
        $request.RunId | Should -Be $script:base.run_id
        $request.ReportEvent | Should -Be 'started'
        $request.EventSeq | Should -Be 1
        $json = [Text.Encoding]::UTF8.GetString($request.BodyBytes)
        $actual = $json | ConvertFrom-Json
        @($actual.PSObject.Properties.Name | Sort-Object) | Should -Be @($script:base.PSObject.Properties.Name | Sort-Object)
        foreach ($property in $script:base.PSObject.Properties) {
            if ($property.Name -eq 'mac_candidates') {
                @($actual.mac_candidates) | Should -Be @($property.Value)
            } else {
                $actual.($property.Name) | Should -Be $property.Value
            }
        }
    }

    It 'preserves explicit unknown total and sorts the MAC set' {
        $snapshot = [pscustomobject]@{
            MacCandidates = @('00:50:56:FF:EE:DD', '00:50:56:AA:BB:CC')
            RolloutRevision = 3
            DeviceGeneration = $script:base.device_generation
            AcceptanceGeneration = $script:base.acceptance_generation
        }
        $request = New-TestStarted -Snapshot $snapshot -Total $null
        $actual = [Text.Encoding]::UTF8.GetString($request.BodyBytes) | ConvertFrom-Json
        @($actual.mac_candidates) | Should -Be @('00:50:56:AA:BB:CC', '00:50:56:FF:EE:DD')
        $actual.PSObject.Properties.Name | Should -Contain 'total'
        $actual.total | Should -BeNullOrEmpty
    }

    It 'refuses invalid identity, duplicate MACs and numeric-string total' {
        (New-TestStarted -RunId $script:base.run_id.ToUpperInvariant()) | Should -BeNullOrEmpty
        (New-TestStarted -Total '300') | Should -BeNullOrEmpty
        $snapshot = [pscustomobject]@{
            MacCandidates = @('00:50:56:AA:BB:CC', '00:50:56:AA:BB:CC')
            RolloutRevision = 3
            DeviceGeneration = $script:base.device_generation
            AcceptanceGeneration = $script:base.acceptance_generation
        }
        (New-TestStarted -Snapshot $snapshot) | Should -BeNullOrEmpty
    }

    It 'refuses invalid package metadata and calendar-invalid timestamps' {
        (New-TestStarted -ProjectName ('a' * 256)) | Should -BeNullOrEmpty
        (New-TestStarted -ProjectName "bad`nname") | Should -BeNullOrEmpty
        (New-TestStarted -Context 'admin') | Should -BeNullOrEmpty
        (New-TestStarted -EventAt '2026-02-31T12:00:00Z') | Should -BeNullOrEmpty
        (New-TestStarted -ClientStartedAt '2026-09-21T12:00:00+00:00') | Should -BeNullOrEmpty
    }
}
