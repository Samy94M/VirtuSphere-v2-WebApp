# Shards und Parallelbetrieb von scripts/test-guards.ps1 (C2): Round-Robin-
# Auswahl, Live-Weitergabe der Kindzeilen, Fallzeiten und die Zusammenfassung
# der Exitcodes (1 vor 2 vor 0, ein Kind ohne Abschlusszeile ist infra).
# Der Harness laeuft als echter Kindprozess; nur sein Fall-Katalog ist durch
# synthetische Faelle ersetzt, damit jedes Ergebnis ohne Werkzeuge entsteht.

BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $script:Utf8 = New-Object System.Text.UTF8Encoding($false)
    $script:EngineExe = (Get-Process -Id $PID).Path
    $real = Get-Content -LiteralPath (Join-Path (Join-Path $script:RepoRoot 'scripts') 'test-guards.ps1') -Raw
    $catalogStart = $real.IndexOf('# --- Fall-Katalog')
    $executionStart = $real.IndexOf('# --- Ausfuehrung')
    $script:MarkersFound = ($catalogStart -gt 0) -and ($executionStart -gt $catalogStart)

    $catalog = @'
# --- Fall-Katalog (synthetisch) ---
$cases = @(
    @{ Name = 'ok.1'; Body = { @{ Status = 'proven'; Detail = '' } } }
    @{ Name = 'bad.unproven'; Body = { @{ Status = 'unproven'; Detail = 'synthetisch rot' } } }
    @{ Name = 'ok.2'; Body = { @{ Status = 'proven'; Detail = '' } } }
    @{ Name = 'bad.infra'; Body = { @{ Status = 'infra'; Detail = 'synthetisch infra' } } }
    @{ Name = 'ok.3'; Body = { @{ Status = 'proven'; Detail = '' } } }
    @{ Name = 'ok.4'; Body = { @{ Status = 'proven'; Detail = '' } } }
    @{ Name = 'crash.exit'; Body = { [Environment]::Exit(7) } }
)

'@
    $script:FakeRoot = Join-Path $TestDrive 'repo'
    $checkLib = Join-Path (Join-Path (Join-Path $script:FakeRoot 'scripts') 'lib') 'check'
    New-Item -ItemType Directory -Force -Path $checkLib | Out-Null
    if ($script:MarkersFound) {
        $stubbed = $real.Substring(0, $catalogStart) + $catalog + $real.Substring($executionStart)
        [System.IO.File]::WriteAllText((Join-Path (Join-Path $script:FakeRoot 'scripts') 'test-guards.ps1'), $stubbed, $script:Utf8)
    }
    foreach ($module in @('artifact-dir.ps1', 'runtime.ps1', 'child-streams.ps1')) {
        Copy-Item -LiteralPath (Join-Path (Join-Path (Join-Path (Join-Path $script:RepoRoot 'scripts') 'lib') 'check') $module) -Destination $checkLib
    }
    # Ohne dockerImages.php gilt das PHP-Image als nicht verfuegbar: kein Docker-Aufruf.
    [System.IO.File]::WriteAllText((Join-Path (Join-Path $script:FakeRoot 'scripts') 'tool-lock.json'), '{ "version": 1 }', $script:Utf8)

    function Invoke-StubHarness {
        param([string[]]$Arguments)
        $psi = New-Object System.Diagnostics.ProcessStartInfo
        $psi.FileName = $script:EngineExe
        $quoted = @($Arguments | ForEach-Object { '"' + $_ + '"' })
        $psi.Arguments = '-NoProfile -ExecutionPolicy Bypass -File "' + (Join-Path (Join-Path $script:FakeRoot 'scripts') 'test-guards.ps1') + '" ' + ($quoted -join ' ')
        $psi.UseShellExecute = $false
        $psi.RedirectStandardOutput = $true
        $psi.RedirectStandardError = $true
        $psi.CreateNoWindow = $true
        $process = [System.Diagnostics.Process]::Start($psi)
        $stdoutTask = $process.StandardOutput.ReadToEndAsync()
        $stderrText = $process.StandardError.ReadToEnd()
        $process.WaitForExit()
        $exitCode = $process.ExitCode
        $process.Dispose()
        return @{ ExitCode = $exitCode; Output = ([string]$stdoutTask.Result + "`n" + $stderrText) }
    }
}

Describe 'test-guards.ps1 shards and parallel shard processes' {
    It 'keeps the catalog and execution markers the stub replaces' {
        $script:MarkersFound | Should -BeTrue
    }

    It 'selects every N-th case round-robin for -Shard k/N' {
        $result = Invoke-StubHarness @('-Filter', 'ok', '-List', '-Shard', '2/2')
        $result.ExitCode | Should -Be 0
        $result.Output | Should -Match '2 Guard-Faelle'
        $result.Output | Should -Match '(?m)^  ok\.2\r?$'
        $result.Output | Should -Match '(?m)^  ok\.4\r?$'
        $result.Output | Should -Not -Match 'ok\.1|ok\.3'
    }

    It 'rejects an invalid or empty shard and -Parallel combined with -Shard as invalid invocation' {
        (Invoke-StubHarness @('-Shard', '3/2')).ExitCode | Should -Be 3
        (Invoke-StubHarness @('-Filter', 'ok.1', '-Shard', '2/2')).ExitCode | Should -Be 3
        (Invoke-StubHarness @('-Parallel', '2', '-Shard', '1/2')).ExitCode | Should -Be 3
    }

    It 'streams prefixed child lines with case durations and an overall counter' {
        $result = Invoke-StubHarness @('-Filter', 'ok', '-Parallel', '2')
        $result.ExitCode | Should -Be 0
        $result.Output | Should -Match '\[1/2\] RUN  Shard 1/2 \(2 Fall/Faelle\)'
        $result.Output | Should -Match '\[Shard 2/2\] \[1/2\] RUN  ok\.2'
        $result.Output | Should -Match '\[Shard 1/2\] \[2/2\] proven   ok\.3 \(\d+\.\ds\) \| gesamt \d/4'
        $result.Output | Should -Match 'gesamt 4/4'
        $result.Output | Should -Match '\[2/2\] proven Shard 2/2 \(\d+\.\ds\) - 2 proven, 0 unproven, 0 infra, Exit 0'
        $result.Output | Should -Match 'Guards: 4 proven, 0 unproven, 0 infra => Exit 0 \(2 Shards'
    }

    It 'lets an unproven case dominate an infra case across shards' {
        $result = Invoke-StubHarness @('-Filter', 'ok,bad', '-Parallel', '3')
        $result.ExitCode | Should -Be 1
        $result.Output | Should -Match 'unproven bad\.unproven \(\d+\.\ds\) - synthetisch rot'
        $result.Output | Should -Match 'Guards: 4 proven, 1 unproven, 1 infra => Exit 1'
    }

    It 'reports infra alone as exit 2' {
        $result = Invoke-StubHarness @('-Filter', 'ok.1,bad.infra', '-Parallel', '2')
        $result.ExitCode | Should -Be 2
        $result.Output | Should -Match 'Guards: 1 proven, 0 unproven, 1 infra => Exit 2'
    }

    It 'counts a shard that dies without its summary line as infra, never as green' {
        $result = Invoke-StubHarness @('-Filter', 'ok.1,crash', '-Parallel', '2')
        $result.ExitCode | Should -Be 2
        $result.Output | Should -Match '\[2/2\] infra Shard 2/2 \(\d+\.\ds\) - Abschlusszeile fehlt oder unvollstaendig, Exit 7'
        $result.Output | Should -Match 'Guards: 1 proven, 0 unproven, 1 infra => Exit 2'
    }
}
