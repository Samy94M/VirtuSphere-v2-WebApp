# Klassifikation des blockierenden Pester-Laufs in scripts/run-pester.ps1: ein
# kaputter Lauf darf nie als gruen enden. Der Runner laeuft als Kindprozess gegen
# ein Testdouble des Pester-Moduls in exakt der Version aus tool-lock.json. Das
# Double liefert je Szenario ein festes Ergebnisobjekt. So wird die Klassifikation
# des Runners selbst ausgefuehrt, ohne von Pester-Interna abzuhaengen.

BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $script:RealRunner = Join-Path (Join-Path $script:RepoRoot 'scripts') 'run-pester.ps1'
    $realLock = Get-Content -LiteralPath (Join-Path (Join-Path $script:RepoRoot 'scripts') 'tool-lock.json') -Raw | ConvertFrom-Json
    $script:PesterVersion = [string]$realLock.powershellModules.Pester
    $script:Utf8 = New-Object System.Text.UTF8Encoding($false)
    # Kindprozess auf dem Host dieser Suite (Windows PowerShell 5.1 oder pwsh).
    $script:EngineExe = (Get-Process -Id $PID).Path

    # Testdouble: nur New-PesterConfiguration und Invoke-Pester. Das Szenario
    # kommt aus der Umgebung des Kindprozesses (VS_STUB_SCENARIO).
    $script:StubRoot = Join-Path $TestDrive 'stub-modules'
    $stubDir = Join-Path (Join-Path $script:StubRoot 'Pester') $script:PesterVersion
    New-Item -ItemType Directory -Path $stubDir -Force | Out-Null
    $stubManifest = @'
@{
    RootModule        = 'Pester.psm1'
    ModuleVersion     = '__VERSION__'
    GUID              = '5d0c2f7e-3a1b-4c8e-9b6d-2f4a8e1c7b90'
    Author            = 'VirtuSphere-Test'
    Description       = 'Testdouble fuer run-pester.ps1, kein echtes Pester'
    PowerShellVersion = '5.1'
    FunctionsToExport = @('New-PesterConfiguration', 'Invoke-Pester')
    CmdletsToExport   = @()
    VariablesToExport = @()
    AliasesToExport   = @()
}
'@
    $stubModule = @'
function New-PesterConfiguration {
    [pscustomobject]@{
        Run          = [pscustomobject]@{ Path = $null; PassThru = $false }
        Output       = [pscustomobject]@{ Verbosity = 'Normal' }
        Filter       = [pscustomobject]@{ ExcludeTag = @() }
        CodeCoverage = [pscustomobject]@{ Enabled = $false; Path = @(); OutputPath = $null; UseBreakpoints = $true }
    }
}

function New-StubError {
    param([string]$Message)
    return [System.Management.Automation.ErrorRecord]::new([System.InvalidOperationException]::new($Message), 'StubFramework', [System.Management.Automation.ErrorCategory]::NotSpecified, $null)
}

function New-StubRun {
    param([string]$Result, [int]$Total, [int]$Passed, [int]$Failed, [int]$FailedContainers, [int]$FailedBlocks, [object[]]$Containers)
    return [pscustomobject]@{
        Result                = $Result
        TotalCount            = $Total
        PassedCount           = $Passed
        FailedCount           = $Failed
        SkippedCount          = 0
        FailedContainersCount = $FailedContainers
        FailedBlocksCount     = $FailedBlocks
        FailedContainers      = @()
        FailedBlocks          = @()
        Containers            = $Containers
        CodeCoverage          = $null
    }
}

function Invoke-Pester {
    param($Configuration)
    # Partitionsszenarien: das Ergebnis haengt an den Dateien des Auftrags.
    # alpha, beta und gamma treffen je einen anderen von vier Coverage-Befehlen.
    if ([string]$env:VS_STUB_SCENARIO -like 'partition-*') {
        # Ein Verzeichnis (Einzelprozess) steht wie bei Pester fuer seine Testdateien.
        $items = @($Configuration.Run.Path | ForEach-Object {
                if (Test-Path -LiteralPath ([string]$_) -PathType Container) {
                    Get-ChildItem -LiteralPath ([string]$_) -Filter '*.Tests.ps1' | Sort-Object Name | ForEach-Object { $_.Name }
                } else { Split-Path ([string]$_) -Leaf }
            })
        Write-Host ('stub ran ' + ($items -join ','))
        if ($env:VS_STUB_SCENARIO -eq 'partition-crash' -and $items -contains 'gamma.Tests.ps1') { [Environment]::Exit(9) }
        $containers = @(foreach ($item in $items) {
                $broken = ($env:VS_STUB_SCENARIO -eq 'partition-container-fails' -and $item -eq 'beta.Tests.ps1')
                $errors = @()
                if ($broken) { $errors = @(New-StubError 'Discovery broke in beta') }
                [pscustomobject]@{ Item = $item; Result = $(if ($broken) { 'Failed' } else { 'Passed' }); ErrorRecord = $errors }
            })
        $failedContainers = @($containers | Where-Object { $_.Result -eq 'Failed' }).Count
        $run = New-StubRun -Result $(if ($failedContainers -gt 0) { 'Failed' } else { 'Passed' }) -Total $items.Count -Passed ($items.Count - $failedContainers) `
            -Failed 0 -FailedContainers $failedContainers -FailedBlocks 0 -Containers $containers
        if ($Configuration.CodeCoverage.Enabled) {
            Write-Host ('stub coverage breakpoints=' + $Configuration.CodeCoverage.UseBreakpoints)
            $hitLines = @(foreach ($item in $items) { @{ 'alpha.Tests.ps1' = 1; 'beta.Tests.ps1' = 2; 'gamma.Tests.ps1' = 3 }[$item] })
            $commands = @(1..4 | ForEach-Object { [pscustomobject]@{ File = 'C:\fake\Common.ps1'; StartLine = $_; StartColumn = 1; EndLine = $_; EndColumn = 9 } })
            $hit = @($commands | Where-Object { $hitLines -contains $_.StartLine })
            $run.CodeCoverage = [pscustomobject]@{
                CoveragePercent  = ($hit.Count / 4) * 100
                CommandsExecuted = $hit
                CommandsMissed   = @($commands | Where-Object { $hitLines -notcontains $_.StartLine })
            }
        }
        return $run
    }
    switch ([string]$env:VS_STUB_SCENARIO) {
        'healthy' {
            return New-StubRun -Result 'Passed' -Total 3 -Passed 3 -Failed 0 -FailedContainers 0 -FailedBlocks 0 -Containers @(
                [pscustomobject]@{ Item = 'healthy.Tests.ps1'; Result = 'Passed'; ErrorRecord = @() }
            )
        }
        'failed-containers' {
            return New-StubRun -Result 'Failed' -Total 874 -Passed 0 -Failed 0 -FailedContainers 2 -FailedBlocks 0 -Containers @(
                [pscustomobject]@{ Item = 'a.Tests.ps1'; Result = 'Failed'; ErrorRecord = @(New-StubError 'Framework failed: TestRegistry HKCU') },
                [pscustomobject]@{ Item = 'b.Tests.ps1'; Result = 'Failed'; ErrorRecord = @(New-StubError 'Parse error line 1') }
            )
        }
        'failed-blocks' {
            return New-StubRun -Result 'Failed' -Total 10 -Passed 9 -Failed 0 -FailedContainers 0 -FailedBlocks 1 -Containers @(
                [pscustomobject]@{ Item = 'blocks.Tests.ps1'; Result = 'Passed'; ErrorRecord = @() }
            )
        }
        'no-result' { return }
        'zero-tests' {
            return New-StubRun -Result 'Passed' -Total 0 -Passed 0 -Failed 0 -FailedContainers 0 -FailedBlocks 0 -Containers @(
                [pscustomobject]@{ Item = 'empty.Tests.ps1'; Result = 'Passed'; ErrorRecord = @() }
            )
        }
        default { throw ('unbekanntes Stub-Szenario: {0}' -f $env:VS_STUB_SCENARIO) }
    }
}
'@
    [System.IO.File]::WriteAllText((Join-Path $stubDir 'Pester.psd1'), $stubManifest.Replace('__VERSION__', $script:PesterVersion), $script:Utf8)
    [System.IO.File]::WriteAllText((Join-Path $stubDir 'Pester.psm1'), $stubModule, $script:Utf8)

    # Gefaelschtes Repo: Kopie des echten Runners, Lockdatei nur mit Pester-Version
    # (optional mit Coverage-Floor), ein Dummy-Test und ein leeres Powershell-MECM.
    function New-FakeRepo {
        param([string]$Root, [bool]$WithCoverageFloor, [string[]]$ExtraTests = @())
        $scriptsDir = Join-Path $Root 'scripts'
        New-Item -ItemType Directory -Path $scriptsDir -Force | Out-Null
        Copy-Item -LiteralPath $script:RealRunner -Destination (Join-Path $scriptsDir 'run-pester.ps1') -Force
        $checkLib = Join-Path (Join-Path $scriptsDir 'lib') 'check'
        New-Item -ItemType Directory -Path $checkLib -Force | Out-Null
        Copy-Item -LiteralPath (Join-Path (Join-Path (Join-Path (Join-Path $script:RepoRoot 'scripts') 'lib') 'check') 'child-streams.ps1') -Destination $checkLib -Force
        $lock = '{ "version": 1, "powershellModules": { "Pester": "' + $script:PesterVersion + '" }'
        if ($WithCoverageFloor) { $lock += ', "pesterCoverageFloorPercent": 55' }
        $lock += ' }'
        [System.IO.File]::WriteAllText((Join-Path $scriptsDir 'tool-lock.json'), $lock, $script:Utf8)
        $testsDir = Join-Path (Join-Path $Root 'tests') 'powershell'
        New-Item -ItemType Directory -Path $testsDir -Force | Out-Null
        $dummy = @'
Describe 'Dummy' { It 'laeuft' { $true | Should -BeTrue } }
'@
        if ($ExtraTests.Count -gt 0) {
            # Partitionsfaelle: gleich grosse Dateien, je eine je Teil.
            foreach ($name in $ExtraTests) { [System.IO.File]::WriteAllText((Join-Path $testsDir ($name + '.Tests.ps1')), $dummy, $script:Utf8) }
        } else {
            [System.IO.File]::WriteAllText((Join-Path $testsDir 'Dummy.Tests.ps1'), $dummy, $script:Utf8)
        }
        New-Item -ItemType Directory -Path (Join-Path $Root 'Powershell-MECM') -Force | Out-Null
    }

    $script:PlainRoot = Join-Path $TestDrive 'plain'
    $script:FloorRoot = Join-Path $TestDrive 'floor'
    $script:PartitionRoot = Join-Path $TestDrive 'partition'
    New-FakeRepo -Root $script:PlainRoot -WithCoverageFloor $false
    New-FakeRepo -Root $script:FloorRoot -WithCoverageFloor $true
    New-FakeRepo -Root $script:PartitionRoot -WithCoverageFloor $true -ExtraTests @('alpha', 'beta', 'gamma')

    # Startet den Runner als Kindprozess. Das Testdouble steht nur in der Umgebung
    # dieses Kindes vorn im Modulpfad; die Umgebung der Suite bleibt unberuehrt.
    function Invoke-StubRunner {
        param([string]$Root, [string]$Scenario, [string]$ExtraArguments = '')
        $psi = New-Object System.Diagnostics.ProcessStartInfo
        $psi.FileName = $script:EngineExe
        $psi.Arguments = '-NoProfile -ExecutionPolicy Bypass -File "' + (Join-Path (Join-Path $Root 'scripts') 'run-pester.ps1') + '" -SkipAnalyzer ' + $ExtraArguments
        $psi.UseShellExecute = $false
        $psi.RedirectStandardOutput = $true
        $psi.RedirectStandardError = $true
        $psi.CreateNoWindow = $true
        $psi.EnvironmentVariables['PSModulePath'] = $script:StubRoot + [System.IO.Path]::PathSeparator + $env:PSModulePath
        $psi.EnvironmentVariables['VS_STUB_SCENARIO'] = $Scenario
        $process = [System.Diagnostics.Process]::Start($psi)
        $stdoutTask = $process.StandardOutput.ReadToEndAsync()
        $stderrText = $process.StandardError.ReadToEnd()
        $process.WaitForExit()
        $exitCode = $process.ExitCode
        $process.Dispose()
        return @{ ExitCode = $exitCode; Output = ([string]$stdoutTask.Result + "`n" + $stderrText) }
    }
}

Describe 'run-pester.ps1 classifies the blocking Pester run' {
    It 'passes a healthy run and prints the real test count' {
        $result = Invoke-StubRunner -Root $script:PlainRoot -Scenario 'healthy'
        $result.ExitCode | Should -Be 0
        $result.Output | Should -Match 'pass Pester'
        $result.Output | Should -Match '3 Test\(s\) gruen'
    }

    It 'fails a run whose containers failed although FailedCount is 0' {
        $result = Invoke-StubRunner -Root $script:PlainRoot -Scenario 'failed-containers'
        $result.ExitCode | Should -Be 1
        $result.Output | Should -Not -Match 'pass Pester'
        $result.Output | Should -Match 'Container gescheitert: a\.Tests\.ps1: Framework failed: TestRegistry HKCU'
        $result.Output | Should -Match 'Container gescheitert: b\.Tests\.ps1: Parse error line 1'
        $result.Output | Should -Match '\] fail Pester'
    }

    It 'fails a run whose setup or teardown block failed' {
        $result = Invoke-StubRunner -Root $script:PlainRoot -Scenario 'failed-blocks'
        $result.ExitCode | Should -Be 1
        $result.Output | Should -Not -Match 'pass Pester'
        $result.Output | Should -Match '1 Setup/Teardown-Bloecke gescheitert'
    }

    It 'reports a missing Pester result object as infrastructure error' {
        $result = Invoke-StubRunner -Root $script:PlainRoot -Scenario 'no-result'
        $result.ExitCode | Should -Be 3
        $result.Output | Should -Match 'infrastructure_error .*Invoke-Pester lieferte kein Ergebnisobjekt'
        $result.Output | Should -Not -Match 'pass Pester'
    }

    It 'treats an empty discovery as infrastructure error and not as green' {
        $result = Invoke-StubRunner -Root $script:PlainRoot -Scenario 'zero-tests'
        $result.ExitCode | Should -Be 3
        $result.Output | Should -Match 'infrastructure_error .*0 Tests entdeckt'
        $result.Output | Should -Not -Match 'pass Pester'
    }

    It 'fails a configured coverage floor when the run carries no coverage result' {
        $result = Invoke-StubRunner -Root $script:FloorRoot -Scenario 'healthy'
        $result.ExitCode | Should -Be 1
        $result.Output | Should -Match 'Coverage-Ergebnis fehlt'
        $result.Output | Should -Not -Match 'pass Pester'
    }
}

Describe 'run-pester.ps1 partitions the Pester suite into child processes' {
    It 'streams every part, unites the counts and the coverage like one process' {
        $result = Invoke-StubRunner -Root $script:PartitionRoot -Scenario 'partition-healthy'
        $result.ExitCode | Should -Be 0
        foreach ($k in 1..3) {
            $result.Output | Should -Match ('\[{0}/3\] RUN  Pester-Teil {0}/3 \(1 Dateien\)' -f $k)
            $result.Output | Should -Match ('\[Teil {0}/3\] stub ran (alpha|beta|gamma)\.Tests\.ps1' -f $k)
            $result.Output | Should -Match ('\[{0}/3\] pass Pester-Teil {0}/3: 1 Test\(s\), 0 rot \(\d+\.\ds\)' -f $k)
            # Coverage ueber den Profiler-Tracer, in jedem Teil.
            $result.Output | Should -Match ('\[Teil {0}/3\] stub coverage breakpoints=False' -f $k)
        }
        # Jeder Teil allein deckt 25 % ab; vereinigt sind es drei von vier Befehlen.
        $result.Output | Should -Match 'Coverage 75% \(Floor 55%\)'
        $result.Output | Should -Match 'pass Pester \(tests\\powershell\): 3 Test\(s\) gruen'

        $single = Invoke-StubRunner -Root $script:PartitionRoot -Scenario 'partition-healthy' -ExtraArguments '-Partitions 1'
        $single.ExitCode | Should -Be 0
        $single.Output | Should -Not -Match 'Pester-Teil'
        $single.Output | Should -Match 'stub coverage breakpoints=False'
        $single.Output | Should -Match 'Coverage 75% \(Floor 55%\)'
    }

    It 'fails the run when a container of one part failed and names it' {
        $result = Invoke-StubRunner -Root $script:PartitionRoot -Scenario 'partition-container-fails'
        $result.ExitCode | Should -Be 1
        $result.Output | Should -Match 'fail Pester-Teil \d/3'
        $result.Output | Should -Match 'Container gescheitert: beta\.Tests\.ps1: Discovery broke in beta'
        $result.Output | Should -Match '\] fail Pester \(tests\\powershell\): 0 Test\(s\) rot, 1 Container gescheitert'
        $result.Output | Should -Not -Match 'pass Pester \('
    }

    It 'reports a part that ends without a result as infrastructure error, never as green' {
        $result = Invoke-StubRunner -Root $script:PartitionRoot -Scenario 'partition-crash'
        $result.ExitCode | Should -Be 3
        $result.Output | Should -Match 'infrastructure_error Pester-Teil \d/3: kein Ergebnis \(Exit 9'
        $result.Output | Should -Match 'infrastructure_error Pester \(tests\\powershell\): Pester-Teil\(e\) \d ohne Ergebnis'
        $result.Output | Should -Not -Match 'pass Pester \('
    }
}
