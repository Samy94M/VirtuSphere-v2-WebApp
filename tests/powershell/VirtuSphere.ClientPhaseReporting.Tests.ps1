# Pester-Suite fuer die Fehlerursachen der Client-Phasen:
#  - PS-31: eine Phasenmeldung ohne erreichbares Portal hinterlaesst eine WARN-Zeile
#    im Clientlog (vorher verschwand sie ohne Spur).
#  - PS-11: ein blockierter Bootstrap in client_getInfos.ps1 endet mit der Ursache im
#    Phasenlog und Exitcode 1, nicht mit einer unbehandelten Ausnahme.
#
# Ausfuehren:  Invoke-Pester tests/powershell/VirtuSphere.ClientPhaseReporting.Tests.ps1

BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $script:ClientsDir = Join-Path (Join-Path $script:RepoRoot 'Powershell-MECM') 'clients'
    . (Join-Path $script:ClientsDir 'VirtuSphere-Client-Common.ps1')
}

Describe 'Phasenmeldung ohne Portal hinterlaesst eine WARN-Zeile (PS-31)' {
    BeforeEach {
        Mock Write-VsClientLog { }
        Mock Invoke-RestMethod { throw 'Keine Anfrage ohne aufgeloestes Portal.' }
    }

    It 'schreibt genau eine WARN-Zeile und sendet nichts, wenn kein Portal aufloesbar ist' {
        Mock Resolve-VsApi { return $null }
        { Send-VsPhase -Mac '00:50:56:AA:BB:CC' -Phase 'getinfo' -PhaseEvent 'started' } | Should -Not -Throw
        Should -Invoke Invoke-RestMethod -Times 0 -Exactly
        Should -Invoke Write-VsClientLog -Times 1 -Exactly -ParameterFilter {
            $Level -eq 'WARN' -and $Context -eq 'getinfo/started' -and $Message -match 'nicht zustellbar: kein Portal erreichbar'
        }
    }

    It 'schweigt, wenn das Portal die Meldung annimmt' {
        Mock Resolve-VsApi { return 'portal.test:8021' }
        Mock Get-VsApiUrl { return 'http://portal.test:8021/mecm_report.php?action=reportPhase' }
        Mock Invoke-RestMethod { return $null }
        Send-VsPhase -Mac '00:50:56:AA:BB:CC' -Phase 'getinfo' -PhaseEvent 'finished'
        Should -Invoke Invoke-RestMethod -Times 1 -Exactly
        Should -Invoke Write-VsClientLog -Times 0 -Exactly
    }

    It 'behaelt die bisherige Zeile fuer eine abgelehnte Meldung' {
        Mock Resolve-VsApi { return 'portal.test:8021' }
        Mock Get-VsApiUrl { return 'http://portal.test:8021/mecm_report.php?action=reportPhase' }
        Mock Invoke-RestMethod { throw 'transport down' }
        Send-VsPhase -Mac '00:50:56:AA:BB:CC' -Phase 'hostname' -PhaseEvent 'failed' -Detail 'x'
        Should -Invoke Write-VsClientLog -Times 1 -Exactly -ParameterFilter {
            $Level -eq 'WARN' -and $Message -match '^reportPhase nicht zugestellt'
        }
    }
}

Describe 'Client-Phase getinfo beendet sich mit Ursache statt unbehandelter Ausnahme (PS-11)' {
    BeforeAll {
        $tokens = $null
        $errors = $null
        $script:GetInfosAst = [System.Management.Automation.Language.Parser]::ParseFile(
            (Join-Path $script:ClientsDir 'client_getInfos.ps1'), [ref]$tokens, [ref]$errors)
        $script:BootstrapCalls = @($script:GetInfosAst.FindAll({ param($n)
            $n -is [System.Management.Automation.Language.CommandAst] -and $n.GetCommandName() -eq 'Initialize-VsClientBootstrap'
        }, $true))
        $script:BootstrapTry = $null
        if ($script:BootstrapCalls.Count -eq 1) {
            $node = $script:BootstrapCalls[0].Parent
            while ($node -and -not ($node -is [System.Management.Automation.Language.TryStatementAst])) { $node = $node.Parent }
            $script:BootstrapTry = $node
        }
    }

    It 'ruft Initialize-VsClientBootstrap genau einmal, innerhalb eines try-Blocks' {
        $script:BootstrapCalls.Count | Should -Be 1
        $script:BootstrapTry | Should -Not -BeNullOrEmpty -Because 'ohne try endet der Prozess mit einer unbehandelten Ausnahme'
        $call = $script:BootstrapCalls[0]
        $call.Extent.StartOffset | Should -BeGreaterOrEqual $script:BootstrapTry.Body.Extent.StartOffset
        $call.Extent.EndOffset | Should -BeLessOrEqual $script:BootstrapTry.Body.Extent.EndOffset
    }

    It 'der catch-Block schreibt die Ursache als ERROR und beendet mit Exitcode 1' {
        $script:BootstrapTry.CatchClauses.Count | Should -Be 1
        $catch = $script:BootstrapTry.CatchClauses[0].Body
        $logCalls = @($catch.FindAll({ param($n)
            $n -is [System.Management.Automation.Language.CommandAst] -and $n.GetCommandName() -eq 'Write-VsClientLog'
        }, $true))
        $logCalls.Count | Should -Be 1
        $logCalls[0].Extent.Text | Should -Match '-Level ERROR'
        $logCalls[0].Extent.Text | Should -Match '\$_\.Exception\.Message'
        $exits = @($catch.FindAll({ param($n) $n -is [System.Management.Automation.Language.ExitStatementAst] }, $true))
        $exits.Count | Should -Be 1
        $exits[0].Pipeline.Extent.Text | Should -Be '1'
    }
}
