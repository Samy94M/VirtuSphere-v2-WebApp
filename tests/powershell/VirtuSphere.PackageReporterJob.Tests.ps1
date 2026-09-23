BeforeAll {
    $repoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $wrapper = Join-Path $repoRoot 'Powershell-MECM/Package_Vorlage/install.ps1'
    $tokens = $null
    $errors = $null
    $ast = [Management.Automation.Language.Parser]::ParseFile($wrapper, [ref]$tokens, [ref]$errors)
    if ($errors.Count -ne 0) { throw 'Wrapper source does not parse.' }
    $definition = $ast.Find({ param($node) $node -is [Management.Automation.Language.FunctionDefinitionAst] -and
        $node.Name -eq 'Start-VsPackageReporterJobProcess' }, $true)
    if ($null -eq $definition) { throw 'Reporter job owner is missing.' }
    . ([scriptblock]::Create($definition.Extent.Text))
}

Describe 'T4 reporter process starts suspended inside its private kill-on-close job' {
    It 'does not launch an unvalidated worker request' {
        $scriptPath = Join-Path $TestDrive 'nonexistent.ps1'
        Start-VsPackageReporterJobProcess -ScriptPath $scriptPath -PipeName 'bad' | Should -BeNullOrEmpty
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
}
