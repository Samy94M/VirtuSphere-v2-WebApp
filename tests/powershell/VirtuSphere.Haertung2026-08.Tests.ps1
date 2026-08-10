# ============================================================================
# Befund-Register der PowerShell/MECM-Haertung 2026-08
# ----------------------------------------------------------------------------
# EIN It je Befund aus der Einzelpruefung der 14 ausgelieferten Skripte,
# benannt nach seiner Etappe im Plan. Alle Tests werden ROT geschrieben, BEVOR
# ein Fix entsteht: der Fortschritt der Kampagne ist die Zahl der gruenen Tests
# in dieser Datei, nicht eine Liste, die man abhaken kann, ohne dass es stimmt.
#
# Was hier NICHT hineingehoert:
#  - Pins, die heute schon gruen sind (die gehoeren in die dauerhaften Suites,
#    denn sie messen keinen Fortschritt),
#  - die drei Waechter aus dem Plan (sie sind selbst Tests, keine Befunde).
#
# Nicht maschinell pruefbare Befunde stehen als -Skip mit ihrem Grund im Namen.
# Sie zaehlen dadurch als uebersprungen und nie als bestanden: eine Zahl, die
# eine Handprobe mitzaehlt, waere derselbe Fehler, den diese Kampagne behebt.
#
# Lebensdauer: nach der Kampagne wandert, was dauerhaft Wert hat, in die
# permanenten Suites; was die Waechter generisch abdecken, wird geloescht.
#
# Tag 'Haertung' an jedem Describe, damit der Release-Gate-Lauf sie bei Bedarf
# per -ExcludeTagFilter aussparen kann, solange die Kampagne laeuft.
# ============================================================================

BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $script:PsRoot = Join-Path $script:RepoRoot 'Powershell-MECM'
    $script:MecmDir = Join-Path $script:PsRoot 'mecm'
    $script:ClientsDir = Join-Path $script:PsRoot 'clients'

    $script:MecmCommon    = Join-Path $script:MecmDir 'VirtuSphere-Common.ps1'
    $script:DeviceSync    = Join-Path $script:MecmDir 'mecm_new-device-sync.ps1'
    $script:PackagesSync  = Join-Path $script:MecmDir 'mecm_Packages-TaskSeq-sync.ps1'
    $script:Autoimporter  = Join-Path $script:MecmDir 'mecm_autoimporter.ps1'
    $script:ClientCommon  = Join-Path $script:ClientsDir 'VirtuSphere-Client-Common.ps1'
    $script:GetInfo       = Join-Path $script:ClientsDir 'client_getinfo.ps1'
    $script:Hostname      = Join-Path $script:ClientsDir 'client_hostname.ps1'
    $script:StaticIp      = Join-Path $script:ClientsDir 'client_staticip.ps1'
    $script:Disks         = Join-Path $script:ClientsDir 'Set-VMDisksOnline.ps1'
    $script:InstMecm      = Join-Path $script:PsRoot 'install-VirtuSphere-MECM.ps1'
    $script:InstClients   = Join-Path $script:PsRoot 'install-VirtuSphere-Clients.ps1'
    $script:Template      = Join-Path (Join-Path $script:PsRoot 'Package_Vorlage') 'install.ps1'

    # Dot-Source im Kindscope, damit Stubs nur im jeweiligen Testscope leben
    # (gleiche Technik wie in den bestehenden Suites).
    function Invoke-InFileScope {
        param([string]$Path, [scriptblock]$Body, [object[]]$Arguments = @())
        & {
            param($p, $b, $a)
            . $p
            & $b @a
        } $Path $Body $Arguments
    }

    function Get-PsAst {
        param([string]$Path)
        $tokens = $null; $errors = $null
        [System.Management.Automation.Language.Parser]::ParseFile($Path, [ref]$tokens, [ref]$errors)
    }

    function Get-PsTokens {
        param([string]$Path)
        $tokens = $null; $errors = $null
        $null = [System.Management.Automation.Language.Parser]::ParseFile($Path, [ref]$tokens, [ref]$errors)
        $tokens
    }

    # Quelltext mit ausgeblendeten Kommentaren (Stringliterale bleiben stehen).
    # Ohne das springt jede Textsuche auf einen Kommentar an, der den gesuchten
    # Namen bloss erwaehnt, und der Test prueft seinen eigenen Suchausdruck.
    function Get-PsCodeText {
        param([string]$Path, [switch]$WithoutStrings)
        $text = Get-Content -Path $Path -Raw
        $chars = $text.ToCharArray()
        $kinds = @('Comment')
        if ($WithoutStrings) {
            $kinds += @('StringLiteral', 'StringExpandable', 'HereStringLiteral', 'HereStringExpandable')
        }
        foreach ($t in (Get-PsTokens -Path $Path)) {
            if ($kinds -notcontains [string]$t.Kind) { continue }
            for ($i = $t.Extent.StartOffset; $i -lt $t.Extent.EndOffset -and $i -lt $chars.Length; $i++) {
                if ($chars[$i] -ne "`n" -and $chars[$i] -ne "`r") { $chars[$i] = ' ' }
            }
        }
        -join $chars
    }

    function Find-Ast {
        param($Ast, [type]$Type, [scriptblock]$Where)
        $found = $Ast.FindAll({ param($n) $n -is $Type }.GetNewClosure(), $true)
        if ($Where) { $found = @($found | Where-Object $Where) }
        @($found)
    }

    function Test-HasAncestorIf {
        param($Node)
        $p = $Node.Parent
        while ($p) {
            if ($p -is [System.Management.Automation.Language.IfStatementAst]) { return $true }
            $p = $p.Parent
        }
        return $false
    }
}

# ---------------------------------------------------------------------------
Describe 'E1 - Invoke-VsApi verliert das JSON-Array bei einem Eintrag' -Tag 'Haertung' {

    It 'E1 - eine einelementige Liste wird als JSON-Array gesendet' {
        # Windows PowerShell 5.1 packt ein einelementiges Array aus, wenn es per
        # Pipeline in ConvertTo-Json geht. mecm_packages.php iteriert dann ueber
        # die Werte des Objekts und antwortet dauerhaft 400.
        $result = Invoke-InFileScope -Path $script:MecmCommon -Body {
            $script:sent = @{}
            function Invoke-RestMethod {
                param($Uri, $Method, $TimeoutSec, $Headers, $Body, $ContentType)
                $script:sent[$Uri] = [string]$Body
            }
            $cfg = [pscustomobject]@{ WebApi = 'host:1'; Scheme = 'http'; ReportToken = '' }

            $one = New-Object System.Collections.Generic.List[object]
            $one.Add(@{ type = 'Package'; name = 'X' })
            Invoke-VsApi -Config $cfg -Path '/one' -Method POST -Body $one | Out-Null

            $two = New-Object System.Collections.Generic.List[object]
            $two.Add(@{ type = 'Package'; name = 'X' })
            $two.Add(@{ type = 'TaskSequence'; name = 'Y' })
            Invoke-VsApi -Config $cfg -Path '/two' -Method POST -Body $two | Out-Null

            Invoke-VsApi -Config $cfg -Path '/hash' -Method POST -Body @{ deviceid = 1 } | Out-Null

            $script:sent
        }

        # Die Liste bleibt eine Liste, egal wie viele Eintraege sie hat.
        $result['http://host:1/one'].TrimStart() | Should -Match '^\[' -Because 'ein einzelnes Paket ist trotzdem ein Katalog'
        $result['http://host:1/two'].TrimStart() | Should -Match '^\['
        # Und eine Hashtable bleibt ein Objekt: der Fix darf die anderen
        # Aufrufer nicht umdrehen.
        $result['http://host:1/hash'].TrimStart() | Should -Match '^\{'
    }
}

# ---------------------------------------------------------------------------
Describe 'E2 - Undefinierte Variable und falscher Nenner im Device-Sync' -Tag 'Haertung' {

    BeforeAll { $script:DsAst = Get-PsAst -Path $script:DeviceSync }

    It 'E2 - der Device-Sync liest keine Variable, die er nicht zuweist' {
        # Set-StrictMode 1.0 wirft beim Lesen einer nicht gesetzten Variablen.
        # $targets.Count steht ausgerechnet in dem Zweig, der einen anderen
        # Fehler melden will, und macht daraus einen Scan-Abbruch.
        $assigned = @{}
        foreach ($a in (Find-Ast -Ast $script:DsAst -Type ([System.Management.Automation.Language.AssignmentStatementAst]))) {
            $left = $a.Left
            if ($left -is [System.Management.Automation.Language.ConvertExpressionAst]) { $left = $left.Child }
            if ($left -is [System.Management.Automation.Language.VariableExpressionAst]) {
                $assigned[$left.VariablePath.UserPath.ToLowerInvariant()] = $true
            }
        }
        foreach ($f in (Find-Ast -Ast $script:DsAst -Type ([System.Management.Automation.Language.ForEachStatementAst]))) {
            $assigned[$f.Variable.VariablePath.UserPath.ToLowerInvariant()] = $true
        }
        foreach ($p in (Find-Ast -Ast $script:DsAst -Type ([System.Management.Automation.Language.ParameterAst]))) {
            $assigned[$p.Name.VariablePath.UserPath.ToLowerInvariant()] = $true
        }

        # Automatik-Variablen und alles mit Namensraum ($env:, $script:) sind
        # keine Zuweisung dieses Skripts. Die Liste steht hier mit Absicht
        # ausgeschrieben: sie darf nicht stillschweigend wachsen.
        $allow = @('_', 'psitem', 'psscriptroot', 'pscmdlet', 'psboundparameters',
                   'true', 'false', 'null', 'args', 'error', 'lastexitcode',
                   'matches', 'host', 'pwd', 'input', 'foreach', 'switch')

        $unassigned = @()
        foreach ($v in (Find-Ast -Ast $script:DsAst -Type ([System.Management.Automation.Language.VariableExpressionAst]))) {
            $name = $v.VariablePath.UserPath
            if ($name -match ':') { continue }
            $lower = $name.ToLowerInvariant()
            if ($allow -contains $lower) { continue }
            if (-not $assigned.ContainsKey($lower)) { $unassigned += $name }
        }

        @($unassigned | Select-Object -Unique) -join ', ' | Should -BeNullOrEmpty
    }

    It 'E2 - der Nenner der Unvollstaendigkeits-Meldung zaehlt die Entfernungen mit' {
        # $targetsSkipped zaehlt auch fehlgeschlagene Entfernungen, und die
        # stehen nicht in $desired: mit $desired.Count allein meldet das Skript
        # bei einem Missionswechsel "4 von 3 Zuweisungen unvollstaendig".
        $combined = Find-Ast -Ast $script:DsAst `
            -Type ([System.Management.Automation.Language.AssignmentStatementAst]) `
            -Where { $_.Right.Extent.Text -match 'desired' -and $_.Right.Extent.Text -match 'remove' }
        $combined.Count | Should -BeGreaterThan 0 -Because 'der Nenner muss geplante Zuweisungen UND geplante Entfernungen umfassen'
    }
}

# ---------------------------------------------------------------------------
Describe 'E2b - Ein fehlgeschlagener Provenienz-Report entlaesst die VM' -Tag 'Haertung' {

    It 'E2b - der Fehlschlag haelt die VM in der Warteschlange' {
        # updateDevice ist der einzige Weg aus getDeviceList. Laeuft es trotz
        # verlorener Provenienz, darf das Portal die eigenen Mitgliedschaften
        # nie mehr entfernen (ADR-0034 verlangt den Eigentumsnachweis), und die
        # VM behaelt ihre alten Collections fuer immer.
        $ast = Get-PsAst -Path $script:DeviceSync
        $catch = Find-Ast -Ast $ast `
            -Type ([System.Management.Automation.Language.CatchClauseAst]) `
            -Where { $_.Extent.Text -match 'membership_report_failed' }
        $catch.Count | Should -Be 1 -Because 'sonst prueft dieser Test die falsche Stelle'

        $continues = Find-Ast -Ast $catch[0].Body -Type ([System.Management.Automation.Language.ContinueStatementAst])
        $continues.Count | Should -BeGreaterThan 0 -Because 'ohne continue laeuft updateDevice und nimmt die VM aus der Warteschlange'
    }
}

# ---------------------------------------------------------------------------
Describe 'E3 - InstallationBehaviorType ist ungeprueft' -Tag 'Haertung' {

    BeforeAll {
        $script:PkgRoot = Join-Path ([System.IO.Path]::GetTempPath()) ('vs-haertung-' + [guid]::NewGuid().ToString('N'))
        New-Item -ItemType Directory -Path $script:PkgRoot -Force | Out-Null

        function New-PkgFolder {
            param([string]$Name, [string]$Json)
            $dir = Join-Path $script:PkgRoot $Name
            New-Item -ItemType Directory -Path $dir -Force | Out-Null
            Set-Content -Path (Join-Path $dir 'config.json') -Value $Json -Encoding UTF8
            $dir
        }

        function Read-Pkg {
            param([string]$Folder)
            Invoke-InFileScope -Path $script:MecmCommon -Arguments @($Folder) -Body {
                param($f)
                Initialize-VsLog -Component 'pester-haertung' -LogRoot ([System.IO.Path]::GetTempPath())
                Read-VsPackageConfig -Folder $f
            }
        }
    }

    AfterAll {
        if (Test-Path $script:PkgRoot) { Remove-Item -Path $script:PkgRoot -Recurse -Force -ErrorAction SilentlyContinue }
    }

    It 'E3 - Common fuehrt die erlaubten Installationsverhalten als Konstante' {
        $values = Invoke-InFileScope -Path $script:MecmCommon -Body { $script:VsInstallationBehaviorTypes }
        @($values) | Should -Contain 'InstallForSystem'
        @($values) | Should -Contain 'InstallForUser'
    }

    It 'E3 - ein fehlendes InstallationBehaviorType wird auf InstallForSystem normalisiert' {
        # Ein Bestandspaket ohne das Feld darf nicht verschwinden. Es wird
        # kanonisch gesetzt, damit die Vorlage denselben Wert sieht.
        $dir = New-PkgFolder -Name 'ohne-feld' -Json '{"ProjectName":"Firefox","version":"115"}'
        $cfg = Read-Pkg -Folder $dir
        $cfg | Should -Not -BeNullOrEmpty
        $cfg.InstallationBehaviorType | Should -Be 'InstallForSystem'

        # Gegenprobe im selben It, damit sie nicht als eigener, heute schon
        # gruener Test den Fortschritt der Kampagne verwaessert: ein gueltig
        # gesetztes InstallForUser darf die Normalisierung nicht ueberschreiben.
        $peruser = New-PkgFolder -Name 'peruser' -Json '{"ProjectName":"Firefox","version":"117","InstallationBehaviorType":"InstallForUser"}'
        (Read-Pkg -Folder $peruser).InstallationBehaviorType | Should -Be 'InstallForUser'
    }

    It 'E3 - ein unbekanntes InstallationBehaviorType wird abgewiesen' {
        # Ein Tippfehler ist keine Meinungsaeusserung. Heute legt MECM die
        # Detection auf HKLM und die Vorlage schreibt nach HKCU: die App gilt
        # nie als installiert und wird endlos erneut versucht.
        $dir = New-PkgFolder -Name 'tippfehler' -Json '{"ProjectName":"Firefox","version":"116","InstallationBehaviorType":"InstalForSystem"}'
        Read-Pkg -Folder $dir | Should -BeNullOrEmpty
    }

    It 'E3 - die Paketvorlage defaultet in dieselbe Richtung wie der Autoimporter' {
        # Der Autoimporter faellt auf InstallForSystem zurueck, die Vorlage auf
        # InstallForUser. Beide muessen auf denselben Zweig pruefen.
        $code = Get-PsCodeText -Path $script:Template
        $code | Should -Match 'InstallationBehaviorType\s+-eq\s+"?''?InstallForUser' -Because 'sonst raten beide Seiten entgegengesetzt'
    }
}

# ---------------------------------------------------------------------------
Describe 'E4 - Beide Installer melden Erfolg ohne auf das Ergebnis zu schauen' -Tag 'Haertung' {

    It 'E4 - der Clients-Installer meldet Erfolg nur unter Bedingung' {
        $ast = Get-PsAst -Path $script:InstClients
        $success = Find-Ast -Ast $ast `
            -Type ([System.Management.Automation.Language.CommandAst]) `
            -Where { $_.Extent.Text -match 'Client-Applikationen bereit' }
        $success.Count | Should -BeGreaterThan 0 -Because 'sonst prueft dieser Test die falsche Stelle'
        (Test-HasAncestorIf -Node $success[0]) | Should -BeTrue -Because 'jeder App-Fehler ist nur eine Warnung, die Schlusszeile steht trotzdem in Gruen'
    }

    It 'E4 - der MECM-Installer bezieht alle Blocker in die Schlusszeile ein' {
        # Heute haengt sie allein an $allRunning: vier laufende Aufgaben plus
        # ein Portal mit 403 ergeben eine gruene Erstinstallation.
        $ast = Get-PsAst -Path $script:InstMecm
        $ifs = Find-Ast -Ast $ast `
            -Type ([System.Management.Automation.Language.IfStatementAst]) `
            -Where { $_.Extent.Text -match 'Erstinstallation abgeschlossen' }
        $ifs.Count | Should -BeGreaterThan 0 -Because 'sonst prueft dieser Test die falsche Stelle'
        $ifs[0].Clauses[0].Item1.Extent.Text | Should -Match '(?i)blocker'
    }

    It 'E4 - beide Installer koennen mit einem Fehlercode enden' {
        foreach ($path in @($script:InstClients, $script:InstMecm)) {
            $exits = Find-Ast -Ast (Get-PsAst -Path $path) `
                -Type ([System.Management.Automation.Language.ExitStatementAst]) `
                -Where { $_.Extent.Text -notmatch 'exit\s+0\s*$' }
            $exits.Count | Should -BeGreaterThan 0 -Because "$(Split-Path $path -Leaf) endet heute immer mit 0"
        }
    }
}

# ---------------------------------------------------------------------------
Describe 'E5 - Die Paketvorlage liest config.json ungeprueft' -Tag 'Haertung' {

    It 'E5 - die Vorlage prueft config.json vor der Skriptschleife' {
        # Fehlt die Datei, laeuft alles mit $config = $null weiter, der
        # Registry-Pfad wird zu "...\Packages\-", und alle Teilskripte laufen
        # trotzdem als SYSTEM.
        $ast = Get-PsAst -Path $script:Template
        $loops = Find-Ast -Ast $ast -Type ([System.Management.Automation.Language.ForEachStatementAst])
        $loops.Count | Should -BeGreaterThan 0 -Because 'sonst prueft dieser Test die falsche Stelle'
        $firstLoop = ($loops | Sort-Object { $_.Extent.StartOffset })[0].Extent.StartOffset

        $exitsBefore = Find-Ast -Ast $ast `
            -Type ([System.Management.Automation.Language.ExitStatementAst]) `
            -Where { $_.Extent.StartOffset -lt $firstLoop }
        $exitsBefore.Count | Should -BeGreaterThan 0 -Because 'die Pruefung muss vor der ersten Skriptausfuehrung abbrechen koennen'
    }

    It 'E5 - die Vorlage laeuft unter Set-StrictMode' {
        Get-PsCodeText -Path $script:Template | Should -Match 'Set-StrictMode'
    }
}

# ---------------------------------------------------------------------------
Describe 'E6 - Der Neustartcode 3010 erreicht MECM nicht' -Tag 'Haertung' {

    It 'E6 - die Vorlage kann einen Neustartcode weiterreichen' {
        # 3010 wird heute zu "Erfolg" eingeebnet und der Wrapper endet mit 0,
        # obwohl der Deployment-Type auf BasedOnExitCode steht.
        $exits = Find-Ast -Ast (Get-PsAst -Path $script:Template) `
            -Type ([System.Management.Automation.Language.ExitStatementAst]) `
            -Where { $_.Extent.Text -match '3010|1641|reboot|Neustartcode' }
        $exits.Count | Should -BeGreaterThan 0 -Because 'ohne das erfaehrt MECM nie von einem noetigen Neustart'
    }
}

# ---------------------------------------------------------------------------
Describe 'E7 - -NoProfile fehlt an drei von vier Stellen' -Tag 'Haertung' {

    It 'E7 - jede powershell.exe-Aufrufstelle traegt -NoProfile' {
        # Ein maschinenweites Profil ist Fremdcode im Installationsprozess, und
        # alles hier laeuft als SYSTEM. Der Aufgaben-Installer macht es richtig
        # und begruendet es in fuenf Kommentarzeilen; drei Stellen fehlen.
        #
        # Eine Zeile, die $script:VsPowerShellArgs einsetzt, traegt die Schalter
        # per Konstruktion: der Plan verlangt genau diese Indirektion als SSoT,
        # und was in der Konstante steht, pinnt das It darunter. Ohne diese
        # Ausnahme wuerde der Test den vorgesehenen Fix verbieten und nur noch
        # kopierte Literale zulassen.
        $offenders = @()
        $seen = 0
        foreach ($file in (Get-ChildItem -Path $script:PsRoot -Filter '*.ps1' -Recurse)) {
            $code = Get-PsCodeText -Path $file.FullName
            foreach ($line in ($code -split "`r?`n")) {
                if ($line -notmatch '(?i)powershell\.exe') { continue }
                $seen++
                if ($line -match '(?i)-NoProfile' -or $line -match 'VsPowerShellArgs') { continue }
                $offenders += ('{0}: {1}' -f $file.Name, $line.Trim())
            }
        }
        $seen | Should -BeGreaterThan 0 -Because 'ohne Fundstellen prueft dieser Test nichts'
        $offenders -join ' | ' | Should -BeNullOrEmpty
    }

    It 'E7 - Common fuehrt die Kommandozeile als Konstante' {
        $args = Invoke-InFileScope -Path $script:MecmCommon -Body { $script:VsPowerShellArgs }
        [string]$args | Should -Match '(?i)-NoProfile'
        [string]$args | Should -Match '(?i)-NonInteractive'
    }
}

# ---------------------------------------------------------------------------
Describe 'E8 - Ein vorhandener ReportToken laesst sich nicht entfernen' -Tag 'Haertung' {

    BeforeAll { $script:InstAst = Get-PsAst -Path $script:InstMecm }

    It 'E8 - die Wiederherstellung prueft PSBoundParameters' {
        # Sonst stellt Zeile 144 den alten Token wieder her, bevor die
        # Erhaltungslogik ueberhaupt nachschaut, ob '' ausdruecklich kam.
        $ifs = Find-Ast -Ast $script:InstAst `
            -Type ([System.Management.Automation.Language.IfStatementAst]) `
            -Where { $_.Extent.Text -match '\$ReportToken\s*=\s*\$existingToken' }
        $ifs.Count | Should -BeGreaterThan 0 -Because 'sonst prueft dieser Test die falsche Stelle'
        $ifs[0].Clauses[0].Item1.Extent.Text | Should -Match 'PSBoundParameters'
    }

    It 'E8 - die interaktive Abfrage prueft PSBoundParameters' {
        # -ReportToken '' faellt heute in die Abfrage, weil dort nur auf
        # IsNullOrEmpty geprueft wird.
        $ifs = Find-Ast -Ast $script:InstAst `
            -Type ([System.Management.Automation.Language.IfStatementAst]) `
            -Where { $_.Extent.Text -match 'Read-Host' -and $_.Extent.Text -match 'ReportToken' }
        $ifs.Count | Should -BeGreaterThan 0 -Because 'sonst prueft dieser Test die falsche Stelle'
        $ifs[0].Clauses[0].Item1.Extent.Text | Should -Match 'PSBoundParameters'
    }

    It 'E8 - der Kommentar behauptet nichts, was der Code nicht haelt' {
        # Heute steht dort ausdruecklich, ein leeres '' leere den Token. Das
        # stimmt nicht, und ein Kommentar mit einer falschen Zusage ist
        # schlimmer als gar keiner.
        # Der Satz laeuft ueber zwei Kommentarzeilen. Ohne Normalisierung
        # (Rautenpraefix weg, Umbrueche zu Leerzeichen) trifft das Muster ihn
        # nie, und der Test war gruen, ohne etwas zu pruefen.
        $comments = @()
        foreach ($t in (Get-PsTokens -Path $script:InstMecm)) {
            if ([string]$t.Kind -eq 'Comment') { $comments += ($t.Text -replace '^\s*#\s?', '') }
        }
        $flat = (($comments -join ' ') -replace '\s+', ' ')
        $flat | Should -Match 'ReportToken' -Because 'ohne Fundstelle prueft dieser Test nichts'
        $flat | Should -Not -Match "Ausdruecklich '' uebergeben leert ihn weiterhin"
    }
}

# ---------------------------------------------------------------------------
Describe 'E9 - Keine DHCP-Rueckumstellung in client_staticip' -Tag 'Haertung' {

    It 'E9 - client_staticip stellt eine Karte auch auf DHCP zurueck' {
        # Ein Interface mit Mode != Static durchlaeuft den Block ohne jede
        # Aktion und erhoeht trotzdem $applied: die Karte behaelt ihre alte
        # statische Adresse, und der Lauf meldet Erfolg.
        $code = Get-PsCodeText -Path $script:StaticIp
        $code | Should -Match '(?i)Set-NetIPInterface'
        $code | Should -Match '(?i)-Dhcp'
    }

    It 'E9 - ein unbekannter Interface-Modus ist ein Fehlschlag' {
        $code = Get-PsCodeText -Path $script:StaticIp
        $code | Should -Match '(?i)unbekannter Modus'
    }

    It 'E9 - Handprobe noetig: DHCP-Rueckumstellung an einer Test-VM (nicht maschinell pruefbar)' -Skip {
        # Kein Test kann beweisen, dass Windows die Karte wirklich umstellt.
        # Beleg ist die Handprobe aus dem Abnahmeteil des Plans.
    }
}

# ---------------------------------------------------------------------------
Describe 'E10 - Ein Lauf meldet failed und finished fuer denselben Hostnamen' -Tag 'Haertung' {

    BeforeAll { $script:HostAst = Get-PsAst -Path $script:Hostname }

    It 'E10 - der Bereinigungszweig meldet keine Phase' {
        # Er meldet heute failed und der gleiche Lauf danach finished. Was das
        # Portal anzeigt, haengt davon ab, welche Meldung zuletzt ankommt.
        $ifs = Find-Ast -Ast $script:HostAst `
            -Type ([System.Management.Automation.Language.IfStatementAst]) `
            -Where { $_.Clauses[0].Item1.Extent.Text -match '\$sanitized\s+-ne\s+\$newHostname' }
        $ifs.Count | Should -BeGreaterThan 0 -Because 'sonst prueft dieser Test die falsche Stelle'

        $phases = Find-Ast -Ast $ifs[0].Clauses[0].Item2 `
            -Type ([System.Management.Automation.Language.CommandAst]) `
            -Where { $_.GetCommandName() -eq 'Send-VsPhase' }
        $phases.Count | Should -Be 0 -Because 'die Abweichung gehoert ins Detail der einen terminalen Meldung, nicht in eine zweite'
    }

    It 'E10 - ein leerer bereinigter Name wird benannt abgefangen' {
        # Sonst laeuft das Skript in Rename-Computer -NewName '' und meldet
        # eine Framework-Exception statt einer Ursache.
        $code = Get-PsCodeText -Path $script:Hostname
        $code | Should -Match '(?is)\$sanitized.*IsNullOrWhiteSpace|IsNullOrWhiteSpace\(\$sanitized'
    }
}

# ---------------------------------------------------------------------------
Describe 'E11 - Sammeletappe' -Tag 'Haertung' {

    It 'E11.1 - die Log-Retention liest ihren Marker nicht bei jeder Logzeile' {
        # Write-VsLog ruft sie pro Zeile auf, und sie macht jedes Mal Test-Path
        # plus Get-Content. Der Device-Sync schreibt im 10-Sekunden-Takt.
        # Nicht "die Funktion nennt irgendeine $script:-Variable" pruefen: das
        # tut sie ueber $script:VsLogRoot ohnehin, und der Test war gruen, ohne
        # etwas zu pruefen. Die Frage ist, ob Write-VsLog sie bei JEDER Zeile
        # ruft.
        $ast = Get-PsAst -Path $script:MecmCommon
        $fn = Find-Ast -Ast $ast `
            -Type ([System.Management.Automation.Language.FunctionDefinitionAst]) `
            -Where { $_.Name -eq 'Invoke-VsLogRetention' }
        $fn.Count | Should -Be 1 -Because 'sonst prueft dieser Test die falsche Stelle'

        $writer = Find-Ast -Ast $ast `
            -Type ([System.Management.Automation.Language.FunctionDefinitionAst]) `
            -Where { $_.Name -eq 'Write-VsLog' }
        $writer.Count | Should -Be 1 -Because 'sonst prueft dieser Test die falsche Stelle'

        $calls = Find-Ast -Ast $writer[0].Body `
            -Type ([System.Management.Automation.Language.CommandAst]) `
            -Where { $_.GetCommandName() -eq 'Invoke-VsLogRetention' }
        foreach ($call in $calls) {
            (Test-HasAncestorIf -Node $call) | Should -BeTrue -Because 'sonst laeuft die Retention bei jeder einzelnen Logzeile'
        }
    }

    It 'E11.2 - der Packages-Sync legt den Hash-Anbieter nicht je Iteration an' {
        $ast = Get-PsAst -Path $script:PackagesSync
        $creates = Find-Ast -Ast $ast `
            -Type ([System.Management.Automation.Language.InvokeMemberExpressionAst]) `
            -Where { $_.Extent.Text -match 'SHA256' }
        $creates.Count | Should -BeGreaterThan 0 -Because 'sonst prueft dieser Test die falsche Stelle'

        # Gegen ALLE while-Schleifen pruefen, nicht gegen die erste: die erste
        # ist die Selbstheilungs-Warteschleife auf die Registry-Konfiguration,
        # und gegen die verglichen lag der Aufruf nie "in der Schleife". Der
        # Test war dadurch gruen, ohne etwas zu pruefen.
        $loops = Find-Ast -Ast $ast -Type ([System.Management.Automation.Language.WhileStatementAst])
        $loops.Count | Should -BeGreaterThan 0
        $inLoop = @($creates | Where-Object {
            $create = $_
            @($loops | Where-Object {
                $create.Extent.StartOffset -gt $_.Extent.StartOffset -and
                $create.Extent.EndOffset -lt $_.Extent.EndOffset
            }).Count -gt 0
        })
        $inLoop.Count | Should -Be 0 -Because 'eine Endlosschleife legt ihn sonst jede Minute neu an'
    }

    It 'E11.3 - ein fehlgeschlagenes Verschieben im Device-Sync bleibt nicht folgenlos' {
        # Der Catch schreibt heute nur WARN: kein Zaehler, keine Ursache, also
        # meldet der Lauf ok, waehrend die Collection im Wurzelordner liegt.
        $fn = Find-Ast -Ast (Get-PsAst -Path $script:DeviceSync) `
            -Type ([System.Management.Automation.Language.FunctionDefinitionAst]) `
            -Where { $_.Name -eq 'New-VsDeviceCollection' }
        $fn.Count | Should -Be 1 -Because 'sonst prueft dieser Test die falsche Stelle'
        $catches = Find-Ast -Ast $fn[0].Body -Type ([System.Management.Automation.Language.CatchClauseAst])
        $catches.Count | Should -BeGreaterThan 0
        $catches[0].Body.Extent.Text | Should -Match '(?i)folderFailed|\$failed|return|throw' -Because 'der Aufrufer muss den Fehlschlag sehen koennen'
    }

    It 'E11.4 - der Packages-Sync trennt Portal- von MECM-Fehlern' {
        # Der aeussere Catch setzt immer mecm_unavailable, obwohl der
        # Sendeblock mit dem Portal spricht. Der Device-Sync loest das mit
        # einer $phase-Variablen.
        Get-PsCodeText -Path $script:PackagesSync -WithoutStrings | Should -Match '\$phase'
    }

    It 'E11.5 - der Autoimporter meldet eine fehlende Collection als solche' {
        # New-CMDeviceCollection scheitert unter SilentlyContinue lautlos; der
        # Folgefehler erscheint als package_deploy_failed, also unter der
        # falschen Ursache.
        Get-PsCodeText -Path $script:Autoimporter | Should -Match 'collection_missing'
    }

    It 'E11.6 - Einrueckung des Autoimporters (kosmetisch, nicht maschinell pruefbar)' -Skip {
        # Zeilen 180-390 stehen vier Zeichen zu weit links. Die Klammerung ist
        # korrekt, nur das Lesen fuehrt in die Irre.
    }

    It 'E11.7 - client_getinfo normalisiert die MAC vor dem Senden' {
        Get-PsCodeText -Path $script:GetInfo | Should -Match 'ConvertTo-VsNormalizedMac'
    }

    It 'E11.8 - Get-VsReportMac normalisiert die Fallback-MAC' {
        $fn = Find-Ast -Ast (Get-PsAst -Path $script:ClientCommon) `
            -Type ([System.Management.Automation.Language.FunctionDefinitionAst]) `
            -Where { $_.Name -eq 'Get-VsReportMac' }
        $fn.Count | Should -Be 1 -Because 'sonst prueft dieser Test die falsche Stelle'
        $fn[0].Body.Extent.Text | Should -Match 'ConvertTo-VsNormalizedMac'
    }

    It 'E11.9 - der Client-Logpfad kommt aus der Umgebung' {
        # Hart auf C:\Program Files verdrahtet, waehrend die Serverseite
        # $env:ProgramFiles benutzt.
        Get-PsCodeText -Path $script:ClientCommon | Should -Match '\$env:ProgramFiles'
    }

    It 'E11.10 - ein RAW-Datentraeger ohne Groesse wird benannt statt mitgezaehlt' {
        # Er faellt aus beiden Listen, wird aber in ProcessedDisks mitgezaehlt.
        Get-PsCodeText -Path $script:Disks | Should -Match '(?i)ohne Groesse|Size -le 0|Size -eq 0'
    }

    It 'E11.11 - verwaister Kommentarblock in Common (kosmetisch, nicht maschinell pruefbar)' -Skip {
        # Der Block bei Zeile 621 beschreibt Add-VsRunCause, steht aber ueber
        # der Ueberschrift von Get-VsMembershipPlan.
    }

    It 'E11.12 - der Autoimporter verspricht keine Deinstallation' {
        # cmd.exe /s entfernt nichts: /s wirkt nur hinter /c oder /k, beides
        # fehlt. Ausgeloest wird es heute von nichts, aber es sagt etwas zu,
        # das das System nicht haelt.
        Get-PsCodeText -Path $script:Autoimporter | Should -Not -Match '(?i)cmd\.exe'
    }
}

# ---------------------------------------------------------------------------
Describe 'Handproben (nicht maschinell pruefbar)' -Tag 'Haertung' {

    It 'E3 - bereits angelegte Deployment-Types werden nicht repariert' -Skip {
        # Der Autoimporter setzt InstallationBehaviorType nur beim Anlegen. Eine
        # falsch angelegte App muss in der Konsole einmal geloescht werden.
        # Beleg: Sichtpruefung in der MECM-Konsole nach dem Rollout.
    }

    It 'E7 - bestehende Deployment-Types behalten die alte Kommandozeile' -Skip {
        # InstallCommand wird nur beim Anlegen gesetzt. Wer die neue Zeile
        # rueckwirkend will, loescht die App einmal.
    }
}
