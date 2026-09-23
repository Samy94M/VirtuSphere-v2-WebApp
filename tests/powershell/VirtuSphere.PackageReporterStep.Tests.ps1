BeforeAll {
    $repoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    . (Join-Path $repoRoot 'Powershell-MECM/clients/VirtuSphere-Package-Reporter.ps1')
    $fixture = Get-Content -LiteralPath (Join-Path $repoRoot 'Docker/WebAPI/tests/fixtures/package-report-v1.json') -Raw | ConvertFrom-Json
    $script:base = $fixture.base
    $script:lateFailure = @($fixture.validation_cases | Where-Object { $_.name -eq 'first failure beyond normal detail limit' })[0].patch
    $script:snapshot = [pscustomobject]@{
        MacCandidates = @($script:base.mac_candidates)
        RolloutRevision = [int]$script:base.rollout_revision
        DeviceGeneration = $script:base.device_generation
        AcceptanceGeneration = $script:base.acceptance_generation
    }
    function New-TestStep {
        param([object]$EventSeq = $script:lateFailure.event_seq,
            [object]$StepIndex = $script:lateFailure.step_index,
            [string]$ScriptName = $script:lateFailure.script_name,
            [string]$Result = $script:lateFailure.result,
            [object]$IsFirstFailure = $script:lateFailure.is_first_failure,
            [object]$ErrorCategory = $script:lateFailure.error_category,
            [object]$ChildExitCode = $script:lateFailure.child_exit_code,
            [object]$DurationMs = $script:lateFailure.duration_ms,
            [object]$DetailPath = $script:lateFailure.detail_path,
            [object]$Total = $script:base.total)
        New-VsPackageReportStepRequest -RunId $script:base.run_id -Snapshot $script:snapshot `
            -ProjectName $script:base.project_name -PackageVersion $script:base.package_version `
            -ClientStartedAt $script:base.client_started_at -EventAt $script:base.event_at `
            -Context $script:base.context -Total $Total -EventSeq $EventSeq -StepIndex $StepIndex `
            -ScriptName $ScriptName -Result $Result -IsFirstFailure $IsFirstFailure `
            -ErrorCategory $ErrorCategory -ChildExitCode $ChildExitCode -DurationMs $DurationMs `
            -DetailPath $DetailPath
    }
}

Describe 'T4 bounded V1 step_result request' {
    It 'emits the late first failure with only the V1 fields and stable correlation' {
        $request = New-TestStep
        $request | Should -Not -BeNullOrEmpty
        $request.BodyBytes -is [byte[]] | Should -BeTrue
        $request.BodyBytes.Length | Should -BeLessOrEqual 65536
        $request.ReportEvent | Should -Be 'step_result'
        $request.EventSeq | Should -Be 301
        $body = [Text.Encoding]::UTF8.GetString($request.BodyBytes) | ConvertFrom-Json
        $expected = @($script:base.PSObject.Properties.Name + @($script:lateFailure.PSObject.Properties.Name | Where-Object { $_ -notin @('event', 'event_seq') }) | Sort-Object)
        @($body.PSObject.Properties.Name | Sort-Object) | Should -Be $expected
        foreach ($property in $script:lateFailure.PSObject.Properties) {
            $body.($property.Name) | Should -Be $property.Value
        }
    }

    It 'keeps absent optional details explicit null and zero child code distinct' {
        $request = New-TestStep -EventSeq 2 -StepIndex 1 -Result 'skip' -IsFirstFailure $false `
            -ErrorCategory $null -ChildExitCode 0 -DurationMs 0 -DetailPath $null
        $body = [Text.Encoding]::UTF8.GetString($request.BodyBytes) | ConvertFrom-Json
        $body.error_category | Should -BeNullOrEmpty
        $body.detail_path | Should -BeNullOrEmpty
        $body.child_exit_code | Should -Be 0
        $body.duration_ms | Should -Be 0
    }

    It 'refuses inconsistent index, fake first failure and numeric-string values' {
        (New-TestStep -StepIndex 301) | Should -BeNullOrEmpty
        (New-TestStep -Result 'ok' -IsFirstFailure $true) | Should -BeNullOrEmpty
        (New-TestStep -EventSeq '301') | Should -BeNullOrEmpty
        (New-TestStep -ChildExitCode '1') | Should -BeNullOrEmpty
        (New-TestStep -DurationMs -1) | Should -BeNullOrEmpty
    }

    It 'refuses unbounded or control-bearing script details without truncating them' {
        (New-TestStep -ScriptName ('x' * 256)) | Should -BeNullOrEmpty
        (New-TestStep -ScriptName "bad`nname") | Should -BeNullOrEmpty
        (New-TestStep -ErrorCategory ('x' * 256)) | Should -BeNullOrEmpty
        (New-TestStep -DetailPath ('C:\\' + ('x' * 1024))) | Should -BeNullOrEmpty
    }
}
