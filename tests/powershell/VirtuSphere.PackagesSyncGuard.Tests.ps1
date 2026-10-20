# Pester-Suite fuer den Sende-Guard des Packages-Sync (T-05 aus dem Audit
# 2026-09-28, Mutant M8). Ein leerer Katalog darf nicht an das Portal gehen: der
# Lauf meldet dann warning/source_missing. Ohne den Guard saehe derselbe Lauf als
# fail/portal_unreachable aus, weil das Portal den leeren Payload ablehnt.
#
# Der Hauptschleifenkoerper wird aus dem AST des Skripts uebernommen und genau
# einmal ausgefuehrt. MECM, Portal und Berichtsweg sind Stubs; die Schleife selbst
# und die echte Common-Bibliothek laufen unveraendert.
#
# Ausfuehren:  Invoke-Pester tests/powershell/VirtuSphere.PackagesSyncGuard.Tests.ps1

BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $script:MecmDir = Join-Path (Join-Path $script:RepoRoot 'Powershell-MECM') 'mecm'
    $script:CommonPath = Join-Path $script:MecmDir 'VirtuSphere-Common.ps1'
    $script:PackagesSyncPath = Join-Path $script:MecmDir 'mecm_Packages-TaskSeq-sync.ps1'

    function Invoke-InFileScope {
        param([string]$Path, [scriptblock]$Body, [object[]]$Arguments = @())
        & {
            param($p, $b, $a)
            . $p
            & $b @a
        } $Path $Body $Arguments
    }

    # Die Hauptschleife: das while, das den Ordner ueber Get-CMFolder einsammelt.
    # Die Selbstheilungs-Warteschleife auf die Registry hat kein Get-CMFolder.
    $source = Get-Content -LiteralPath $script:PackagesSyncPath -Raw
    $tokens = $null
    $parseErrors = $null
    $ast = [System.Management.Automation.Language.Parser]::ParseFile($script:PackagesSyncPath, [ref]$tokens, [ref]$parseErrors)
    $loops = @($ast.FindAll({ param($n)
        $n -is [System.Management.Automation.Language.WhileStatementAst] -and $n.Body.Extent.Text -match 'Get-CMFolder'
    }, $true))
    if ($loops.Count -ne 1) { throw 'Die Hauptschleife des Packages-Sync ist nicht eindeutig.' }
    $statements = @($loops[0].Body.Statements)
    $script:LoopBody = $source.Substring($statements[0].Extent.StartOffset, $statements[-1].Extent.EndOffset - $statements[0].Extent.StartOffset)

    function Invoke-PackagesSyncOnce {
        param([Parameter(Mandatory)][ValidateSet('empty-catalog', 'folder-missing', 'one-package')][string]$Scenario)
        Invoke-InFileScope -Path $script:CommonPath -Arguments @($Scenario, $script:LoopBody) -Body {
            param($scenario, $loopText)
            $apiCalls = New-Object System.Collections.Generic.List[string]
            $completed = New-Object System.Collections.Generic.List[object]
            $logs = New-Object System.Collections.Generic.List[string]

            function Write-VsLog {
                param([string]$Level = 'INFO', [string]$Context = '-', [string]$Message = '')
                [void]$logs.Add($Message)
            }
            function Send-VsRunReport {
                param($Config, $Source, $RunEvent, $RunId, $IntervalSeconds, $Outcome, $ErrorCategory, $DurationMs, $Detail, $Summary, $ScriptVersion)
                if ($RunEvent -eq 'completed') {
                    [void]$completed.Add([pscustomobject]@{ Outcome = $Outcome; ErrorCategory = $ErrorCategory; Summary = $Summary })
                }
            }
            function Get-CMFolder {
                param($Name, $ErrorAction)
                if ($scenario -eq 'folder-missing') { return $null }
                return [pscustomobject]@{ ObjectType = 5000; ContainerNodeID = 'ABC' }
            }
            function Get-CimInstance {
                param($Namespace, $Query, $ErrorAction)
                if ($scenario -eq 'one-package') { return @([pscustomobject]@{ Name = 'Agent-1.0' }) }
                return @()
            }
            function Get-CMTaskSequence {
                param([switch]$Fast, $ErrorAction)
                return @()
            }
            function Invoke-VsApi {
                param($Config, $Path, $Method, $Body)
                [void]$apiCalls.Add([string]$Path)
                return [pscustomobject]@{ packages = 1; task_sequences = 0 }
            }
            # Nach einem Durchlauf stoppt das Sleep die Endlosschleife.
            function Start-Sleep {
                param($Seconds)
                throw 'ONCE-STOP'
            }

            $config = [pscustomobject]@{ LogRoot = (Join-Path ([System.IO.Path]::GetTempPath()) 'vs-packages-sync-guard') }
            $folderName = 'VirtuSphere_Applications'
            $intervalSeconds = 300
            $SCRIPT_VERSION = 'packages-sync/test'
            $siteCode = 'P01'
            $lastPayloadHash = ''
            $lastFullSync = [datetime]::MinValue
            $forceSyncEverySeconds = 3600
            $sha = [System.Security.Cryptography.SHA256]::Create()
            $consecutiveErrors = 0

            $stopped = $false
            try {
                . ([scriptblock]::Create($loopText))
            } catch {
                if ($_.Exception.Message -ne 'ONCE-STOP') { throw }
                $stopped = $true
            }
            [pscustomobject]@{
                Stopped = $stopped
                ApiCalls = $apiCalls.ToArray()
                Completed = $completed.ToArray()
                Logs = $logs.ToArray()
            }
        }
    }
}

Describe 'Packages-Sync T-05: der Sende-Guard fuer einen leeren Katalog ist getestet' {
    It 'die Schleife enthaelt den Guard fuer den leeren Katalog (Vorbedingung dieser Suite)' {
        $script:LoopBody | Should -Match 'if \(\$payload\.Count -eq 0\)'
    }

    It 'ein leerer Katalog wird nicht an das Portal gesendet und meldet warning/source_missing' {
        $r = Invoke-PackagesSyncOnce -Scenario 'empty-catalog'
        $r.Stopped | Should -BeTrue -Because 'sonst lief die Schleife nicht genau einmal'
        $r.ApiCalls.Count | Should -Be 0 -Because 'ein leerer Payload zoege den Katalog im Portal zurueck'
        $r.Completed.Count | Should -Be 1
        $r.Completed[0].Outcome | Should -Be 'warning'
        $r.Completed[0].ErrorCategory | Should -Be 'source_missing'
        ($r.Logs -join "`n") | Should -Match 'Katalog leer' -Because 'der Grund steht im lokalen Log'
    }

    It 'ein fehlender Ordner sendet ebenfalls nichts und meldet warning/source_missing' {
        $r = Invoke-PackagesSyncOnce -Scenario 'folder-missing'
        $r.Stopped | Should -BeTrue
        $r.ApiCalls.Count | Should -Be 0
        $r.Completed[0].Outcome | Should -Be 'warning'
        $r.Completed[0].ErrorCategory | Should -Be 'source_missing'
    }

    It 'Gegenprobe: ein Katalog mit Eintraegen wird gesendet und meldet ok' {
        # Ohne diesen Fall koennte der Fixture-Aufbau selbst den Sendepfad verfehlen,
        # und die beiden Tests darueber waeren gruen, ohne etwas zu pruefen.
        $r = Invoke-PackagesSyncOnce -Scenario 'one-package'
        $r.ApiCalls.Count | Should -Be 1
        $r.Completed[0].Outcome | Should -Be 'ok'
        $r.Completed[0].ErrorCategory | Should -BeNullOrEmpty
    }
}
