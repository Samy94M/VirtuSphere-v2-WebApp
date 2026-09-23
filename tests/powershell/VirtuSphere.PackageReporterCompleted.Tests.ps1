BeforeAll {
    $repoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    . (Join-Path $repoRoot 'Powershell-MECM/clients/VirtuSphere-Package-Reporter.ps1')
    $fixture = Get-Content -LiteralPath (Join-Path $repoRoot 'Docker/WebAPI/tests/fixtures/package-report-v1.json') -Raw | ConvertFrom-Json
    $script:base = $fixture.base
    $script:complete = @($fixture.validation_cases | Where-Object { $_.name -eq 'completed carries reserved failure and wrapper core' })[0].patch
    $script:snapshot = [pscustomobject]@{
        MacCandidates = @($script:base.mac_candidates)
        RolloutRevision = [int]$script:base.rollout_revision
        DeviceGeneration = $script:base.device_generation
        AcceptanceGeneration = $script:base.acceptance_generation
    }
    function New-TestCompletion {
        param([object]$EventSeq = $script:complete.event_seq,
            [string]$WrapperResult = $script:complete.wrapper_result,
            [object]$WrapperExitCode = $script:complete.wrapper_exit_code,
            [string]$DetectionResult = $script:complete.detection_result,
            [object]$ProcessedCount = $script:complete.processed_count,
            [object]$OkCount = $script:complete.ok_count,
            [object]$SkipCount = $script:complete.skip_count,
            [object]$FailCount = $script:complete.fail_count,
            [object]$LastProcessedIndex = $script:complete.last_processed_index,
            [object]$FirstFailure = $script:complete.first_failure,
            [object]$PayloadOmittedCount = $script:complete.payload_omitted_count,
            [object]$WrapperLogPath = $script:complete.wrapper_log_path,
            [object]$ReportingLogPath = $script:complete.reporting_log_path,
            [object]$Total = $script:base.total)
        New-VsPackageReportCompletedRequest -RunId $script:base.run_id -Snapshot $script:snapshot `
            -ProjectName $script:base.project_name -PackageVersion $script:base.package_version `
            -ClientStartedAt $script:base.client_started_at -EventAt $script:base.event_at `
            -Context $script:base.context -Total $Total -EventSeq $EventSeq `
            -WrapperResult $WrapperResult -WrapperExitCode $WrapperExitCode -DetectionResult $DetectionResult `
            -ProcessedCount $ProcessedCount -OkCount $OkCount -SkipCount $SkipCount -FailCount $FailCount `
            -LastProcessedIndex $LastProcessedIndex -FirstFailure $FirstFailure `
            -PayloadOmittedCount $PayloadOmittedCount -WrapperLogPath $WrapperLogPath `
            -ReportingLogPath $ReportingLogPath
    }
}

Describe 'T4 bounded V1 completed request' {
    It 'emits the fixture completion and reserved late first failure without extra fields' {
        $request = New-TestCompletion
        $request | Should -Not -BeNullOrEmpty
        $request.BodyBytes -is [byte[]] | Should -BeTrue
        $request.BodyBytes.Length | Should -BeLessOrEqual 65536
        $request.ReportEvent | Should -Be 'completed'
        $request.EventSeq | Should -Be 302
        $body = [Text.Encoding]::UTF8.GetString($request.BodyBytes) | ConvertFrom-Json
        $expected = @($script:base.PSObject.Properties.Name + @($script:complete.PSObject.Properties.Name | Where-Object { $_ -notin @('event', 'event_seq') }) | Sort-Object)
        @($body.PSObject.Properties.Name | Sort-Object) | Should -Be $expected
        foreach ($property in $script:complete.PSObject.Properties) {
            if ($property.Name -in @('event', 'event_seq', 'first_failure')) { continue }
            $body.($property.Name) | Should -Be $property.Value
        }
        @($body.first_failure.PSObject.Properties.Name | Sort-Object) | Should -Be @($script:complete.first_failure.PSObject.Properties.Name | Sort-Object)
        $body.first_failure.step_index | Should -Be 300
    }

    It 'allows a failed detection marker without a failed step and preserves nulls' {
        $request = New-TestCompletion -WrapperResult 'failed' -WrapperExitCode 1 -DetectionResult 'failed' `
            -ProcessedCount 1 -OkCount 1 -SkipCount 0 -FailCount 0 -LastProcessedIndex 1 `
            -FirstFailure $null -PayloadOmittedCount 0 -WrapperLogPath $null -ReportingLogPath $null
        $body = [Text.Encoding]::UTF8.GetString($request.BodyBytes) | ConvertFrom-Json
        $body.first_failure | Should -BeNullOrEmpty
        $body.wrapper_log_path | Should -BeNullOrEmpty
        $body.reporting_log_path | Should -BeNullOrEmpty
        $body.fail_count | Should -Be 0
    }

    It 'rejects inconsistent counts, missing first failure and impossible last index' {
        (New-TestCompletion -OkCount 300) | Should -BeNullOrEmpty
        (New-TestCompletion -FirstFailure $null) | Should -BeNullOrEmpty
        (New-TestCompletion -LastProcessedIndex 301) | Should -BeNullOrEmpty
        (New-TestCompletion -ProcessedCount '300') | Should -BeNullOrEmpty
    }

    It 'rejects foreign or unbounded failure details and log paths' {
        $badFailure = [pscustomobject]@{ step_index = 300; script_name = '300-final.ps1'; future_field = 1 }
        (New-TestCompletion -FirstFailure $badFailure) | Should -BeNullOrEmpty
        $badFailure = [pscustomobject]@{ step_index = 300; script_name = '300-final.ps1'; detail_path = ('x' * 1025) }
        (New-TestCompletion -FirstFailure $badFailure) | Should -BeNullOrEmpty
        (New-TestCompletion -WrapperLogPath ('x' * 1025)) | Should -BeNullOrEmpty
        (New-TestCompletion -WrapperExitCode 2147483648) | Should -BeNullOrEmpty
    }
}
