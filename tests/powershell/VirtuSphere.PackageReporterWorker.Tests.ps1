BeforeAll {
    $repoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    . (Join-Path $repoRoot 'Powershell-MECM/clients/VirtuSphere-Package-Reporter.ps1')
    . (Join-Path $repoRoot 'Powershell-MECM/clients/VirtuSphere-Package-ReporterHost.ps1')
    $fixture = Get-Content -LiteralPath (Join-Path $repoRoot 'Docker/WebAPI/tests/fixtures/package-report-v1.json') -Raw | ConvertFrom-Json
    $script:base = $fixture.base
    $script:step = @($fixture.validation_cases | Where-Object name -eq 'first failure beyond normal detail limit')[0].patch
    $script:completion = @($fixture.validation_cases | Where-Object name -eq 'completed carries reserved failure and wrapper core')[0].patch
    $script:snapshot = [pscustomobject]@{
        MacCandidates = @($script:base.mac_candidates)
        RolloutRevision = [int]$script:base.rollout_revision
        DeviceGeneration = $script:base.device_generation
        AcceptanceGeneration = $script:base.acceptance_generation
    }
    function Get-VsPackageReportSnapshot { return $null }
    function Get-VsPackageReportApiConfiguration { return $null }
    function New-WorkerState {
        return [pscustomobject]@{ Budget = (New-VsPackageReportBudget); Snapshot = $null; Api = $null; Run = $null }
    }
}

Describe 'T4 isolated worker builds only the frozen V1 run sequence' {
    BeforeEach {
        $script:requests = New-Object 'System.Collections.Generic.List[object]'
        Mock Get-VsPackageReportSnapshot { return $script:snapshot }
        Mock Get-VsPackageReportApiConfiguration { return [pscustomobject]@{ Api = '127.0.0.1:8021'; Scheme = 'http'; ReportUrl = 'http://127.0.0.1:8021/mecm_report.php?action=reportPackageRun'; CertThumbprint = '' } }
        Mock Invoke-VsPackageReportBudgetedAttempt {
            $script:requests.Add($ReportRequest)
            return [pscustomobject]@{ Attempted = $true; Confirmed = $true; Deduplicated = $false; Reason = 'accepted'; StatusCode = 200 }
        }
    }

    It 'does not call HTTP when the published identity is unavailable' {
        Mock Get-VsPackageReportSnapshot { return $null }
        $state = New-WorkerState
        $answer = Invoke-VsPackageReportWorkerMessage -State $state -Message $script:base
        $answer.reason | Should -Be 'identity_unavailable'
        $answer.attempted | Should -BeFalse
        Should -Invoke Invoke-VsPackageReportBudgetedAttempt -Exactly 0
    }

    It 'freezes started metadata and carries a late first failure into completion' {
        $state = New-WorkerState
        $started = Invoke-VsPackageReportWorkerMessage -State $state -Message $script:base
        $started.confirmed | Should -BeTrue
        $step = [pscustomobject]@{ event = 'step_result'; event_seq = $script:step.event_seq;
            event_at = $script:base.event_at; step_index = $script:step.step_index;
            script_name = $script:step.script_name; result = $script:step.result;
            is_first_failure = $script:step.is_first_failure; error_category = $script:step.error_category;
            child_exit_code = $script:step.child_exit_code; duration_ms = $script:step.duration_ms;
            detail_path = $script:step.detail_path }
        $stepAnswer = Invoke-VsPackageReportWorkerMessage -State $state -Message $step
        $stepAnswer.confirmed | Should -BeTrue
        $completed = [pscustomobject]@{ event = 'completed'; event_seq = $script:completion.event_seq;
            event_at = $script:base.event_at; wrapper_result = $script:completion.wrapper_result;
            wrapper_exit_code = $script:completion.wrapper_exit_code;
            detection_result = $script:completion.detection_result;
            processed_count = $script:completion.processed_count; ok_count = $script:completion.ok_count;
            skip_count = $script:completion.skip_count; fail_count = $script:completion.fail_count;
            last_processed_index = $script:completion.last_processed_index;
            first_failure = $script:completion.first_failure;
            payload_omitted_count = $script:completion.payload_omitted_count;
            wrapper_log_path = $script:completion.wrapper_log_path;
            reporting_log_path = $script:completion.reporting_log_path }
        $endAnswer = Invoke-VsPackageReportWorkerMessage -State $state -Message $completed
        $endAnswer.confirmed | Should -BeTrue
        @($script:requests | ForEach-Object ReportEvent) | Should -Be @('started', 'step_result', 'completed')
        @($script:requests | ForEach-Object EventSeq) | Should -Be @(1, 301, 302)
        $lastBody = [Text.Encoding]::UTF8.GetString($script:requests[2].BodyBytes) | ConvertFrom-Json
        $lastBody.first_failure.step_index | Should -Be 300
        $lastBody.payload_omitted_count | Should -Be 43
        Should -Invoke Get-VsPackageReportSnapshot -Exactly 1
        Should -Invoke Get-VsPackageReportApiConfiguration -Exactly 1
    }

    It 'rejects step reports before a valid started message' {
        $state = New-WorkerState
        $answer = Invoke-VsPackageReportWorkerMessage -State $state -Message ([pscustomobject]@{ event = 'step_result'; event_seq = 2 })
        $answer.reason | Should -Be 'invalid_request'
        Should -Invoke Invoke-VsPackageReportBudgetedAttempt -Exactly 0
    }

    It 'accepts a JSON-roundtripped wrapper hash-skip event' {
        $state = New-WorkerState
        $start = [pscustomobject]@{ event = 'started'; event_seq = 1; run_id = $script:base.run_id;
            project_name = $script:base.project_name; package_version = $script:base.package_version;
            client_started_at = $script:base.client_started_at; event_at = $script:base.event_at;
            context = 'system'; total = 2 }
        $null = Invoke-VsPackageReportWorkerMessage -State $state -Message ($start | ConvertTo-Json -Compress | ConvertFrom-Json)
        $skip = [pscustomobject]@{ event = 'step_result'; event_seq = 2; event_at = $script:base.event_at;
            step_index = 1; script_name = '01.ps1'; result = 'skip'; is_first_failure = $false;
            error_category = 'hash_match'; child_exit_code = $null; duration_ms = 5; detail_path = $null }
        $answer = Invoke-VsPackageReportWorkerMessage -State $state -Message ($skip | ConvertTo-Json -Compress | ConvertFrom-Json)
        $answer.reason | Should -Be 'accepted'
        $answer.attempted | Should -BeTrue
    }
}
