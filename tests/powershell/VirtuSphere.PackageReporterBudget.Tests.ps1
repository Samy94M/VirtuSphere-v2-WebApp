BeforeAll {
    $repoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    . (Join-Path $repoRoot 'Powershell-MECM/clients/VirtuSphere-Package-ReporterHost.ps1')
    $script:now = [DateTimeOffset]::Parse('2026-09-23T12:00:00Z', [Globalization.CultureInfo]::InvariantCulture)
}

Describe 'T4 monotonic backpressure and reserved reporter budget' {
    It 'translates delta-seconds and RFC date once into monotonic deadlines' {
        (Get-VsPackageReportRetryUntilMs -StatusCode 503 -RetryAfter '12' -ReceivedAtUtc $script:now -MonotonicNowMs 500) | Should -Be 12500
        (Get-VsPackageReportRetryUntilMs -StatusCode 429 -RetryAfter 'Wed, 23 Sep 2026 12:00:03 GMT' -ReceivedAtUtc $script:now -MonotonicNowMs 500) | Should -Be 3500
        (Get-VsPackageReportRetryUntilMs -StatusCode 503 -RetryAfter '999999999999999999999999' -ReceivedAtUtc $script:now -MonotonicNowMs 500) | Should -Be ([long]::MaxValue)
    }

    It 'ignores missing, invalid, expired and unrelated Retry-After values' {
        (Get-VsPackageReportRetryUntilMs -StatusCode 200 -RetryAfter '12' -ReceivedAtUtc $script:now -MonotonicNowMs 500) | Should -BeNullOrEmpty
        (Get-VsPackageReportRetryUntilMs -StatusCode 503 -RetryAfter 'bad' -ReceivedAtUtc $script:now -MonotonicNowMs 500) | Should -BeNullOrEmpty
        (Get-VsPackageReportRetryUntilMs -StatusCode 503 -RetryAfter 'Wed, 23 Sep 2026 11:59:59 GMT' -ReceivedAtUtc $script:now -MonotonicNowMs 500) | Should -BeNullOrEmpty
        (Get-VsPackageReportRetryUntilMs -StatusCode 429 -RetryAfter '0' -ReceivedAtUtc $script:now -MonotonicNowMs 500) | Should -BeNullOrEmpty
    }

    It 'holds regular events and completion alike while backpressure is live without sleeping' {
        $budget = [pscustomobject]@{
            MonotonicClock = [pscustomobject]@{ ElapsedMilliseconds = 1000 }
            ActiveClock = [pscustomobject]@{ ElapsedMilliseconds = 200 }
            RetryUntilMs = [long]1500
        }
        (Get-VsPackageReportAttemptTimeoutMs -Budget $budget -ReportEvent 'step_result') | Should -Be 0
        (Get-VsPackageReportAttemptTimeoutMs -Budget $budget -ReportEvent 'completed') | Should -Be 0
        $budget.MonotonicClock.ElapsedMilliseconds = 1500
        (Get-VsPackageReportAttemptTimeoutMs -Budget $budget -ReportEvent 'step_result') | Should -Be 2000
    }

    It 'reserves completion and teardown room inside the ten-second active budget' {
        $budget = [pscustomobject]@{
            MonotonicClock = [pscustomobject]@{ ElapsedMilliseconds = 1000 }
            ActiveClock = [pscustomobject]@{ ElapsedMilliseconds = 7400 }
            RetryUntilMs = $null
        }
        (Get-VsPackageReportAttemptTimeoutMs -Budget $budget -ReportEvent 'started') | Should -Be 100
        (Get-VsPackageReportAttemptTimeoutMs -Budget $budget -ReportEvent 'completed') | Should -Be 2000
        $budget.ActiveClock.ElapsedMilliseconds = 7500
        (Get-VsPackageReportAttemptTimeoutMs -Budget $budget -ReportEvent 'step_result') | Should -Be 0
        (Get-VsPackageReportAttemptTimeoutMs -Budget $budget -ReportEvent 'completed') | Should -Be 2000
        $budget.ActiveClock.ElapsedMilliseconds = 9450
        (Get-VsPackageReportAttemptTimeoutMs -Budget $budget -ReportEvent 'completed') | Should -Be 0
    }

    It 'suppresses completion after 503 without sleeping or invoking HTTP again' {
        $budget = New-VsPackageReportBudget
        $configuration = [pscustomobject]@{}
        $request = [pscustomobject]@{ ReportEvent = 'step_result' }
        Mock Invoke-VsPackageReportHttp {
            return [pscustomobject]@{ Attempted = $true; Confirmed = $false; Deduplicated = $false; Reason = 'http_status'; StatusCode = 503; RetryAfter = '30' }
        }
        $first = Invoke-VsPackageReportBudgetedAttempt -Budget $budget -ApiConfiguration $configuration -ReportRequest $request
        $first.StatusCode | Should -Be 503
        $budget.RetryUntilMs | Should -BeGreaterThan $budget.MonotonicClock.ElapsedMilliseconds
        $request.ReportEvent = 'completed'
        $second = Invoke-VsPackageReportBudgetedAttempt -Budget $budget -ApiConfiguration $configuration -ReportRequest $request
        $second.Reason | Should -Be 'backpressure'
        $second.Attempted | Should -BeFalse
        Should -Invoke Invoke-VsPackageReportHttp -Exactly 1
    }

    It 'allows later events after ordinary errors and accounts for active attempt time' {
        $budget = New-VsPackageReportBudget
        $configuration = [pscustomobject]@{}
        $request = [pscustomobject]@{ ReportEvent = 'started' }
        Mock Invoke-VsPackageReportHttp {
            Start-Sleep -Milliseconds 15
            return [pscustomobject]@{ Attempted = $true; Confirmed = $false; Deduplicated = $false; Reason = 'http_status'; StatusCode = 500; RetryAfter = $null }
        }
        $null = Invoke-VsPackageReportBudgetedAttempt -Budget $budget -ApiConfiguration $configuration -ReportRequest $request
        $budget.ActiveClock.ElapsedMilliseconds | Should -BeGreaterThan 0
        $budget.ActiveClock.IsRunning | Should -BeFalse
        $request.ReportEvent = 'step_result'
        $null = Invoke-VsPackageReportBudgetedAttempt -Budget $budget -ApiConfiguration $configuration -ReportRequest $request
        Should -Invoke Invoke-VsPackageReportHttp -Exactly 2
    }
}
