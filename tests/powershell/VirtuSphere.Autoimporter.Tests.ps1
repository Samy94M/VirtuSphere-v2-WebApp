# Pester-Suite fuer den Autoimporter und den MECM-Installer.
#
# Beide melden Zustaende, die niemand nachpruefen kann: der Autoimporter laeuft
# als SYSTEM in einer Endlosschleife auf dem MECM-Server, der Installer laeuft
# einmal und hinterlaesst vier geplante Aufgaben. Was hier gepinnt wird, ist
# nicht "der Code tut X", sondern "ein Lauf, der etwas nicht geschafft hat, sagt
# das auch" - der Stamp, die Ursachenliste und die Trigger-Definition sind die
# drei Stellen, an denen ein Fehlschlag bisher als gelungener Lauf endete.
#
# Statisch ueber den AST, weil kein MECM-Server im Test steht: die MECM-Cmdlets
# existieren hier nicht, die Struktur der Verzweigungen schon.

BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $script:PsRoot = Join-Path $script:RepoRoot 'Powershell-MECM'
    $script:MecmDir = Join-Path $script:PsRoot 'mecm'
    $script:MecmCommon = Join-Path $script:MecmDir 'VirtuSphere-Common.ps1'
    $script:Importer = Join-Path $script:MecmDir 'mecm_autoimporter.ps1'
    $script:Installer = Join-Path $script:PsRoot 'install-VirtuSphere-MECM.ps1'

    function Invoke-InFileScope {
        param([string]$Path, [scriptblock]$Body, [object[]]$Arguments = @())
        & {
            param($p, $b, $a)
            . $p
            & $b @a
        } $Path $Body $Arguments
    }

    function Get-Ast {
        param([string]$Path)
        $tokens = $null; $errors = $null
        [System.Management.Automation.Language.Parser]::ParseFile($Path, [ref]$tokens, [ref]$errors)
    }

    # Die if-Anweisung, deren Bedingung den gesuchten Text traegt. Ueber den AST
    # statt per Regex, weil die Frage "liegt diese Zuweisung im then- oder im
    # else-Zweig" genau die Frage des Defekts ist und Textsuche sie nicht stellt.
    function Get-IfByCondition {
        param([string]$Path, [string]$ConditionText)
        (Get-Ast -Path $Path).FindAll({ param($n)
            $n -is [System.Management.Automation.Language.IfStatementAst] -and
            $n.Clauses[0].Item1.Extent.Text -like ('*' + $ConditionText + '*')
        }, $true) | Select-Object -First 1
    }

    # Alle Vorkommen von '$<Name>++' als AST-Knoten, samt dem Statement-Block,
    # in dem sie stehen.
    function Get-IncrementBlocks {
        param([string]$Path, [string]$VariableName)
        $ast = Get-Ast -Path $Path
        $ast.FindAll({ param($n)
            $n -is [System.Management.Automation.Language.UnaryExpressionAst] -and
            $n.TokenKind -eq [System.Management.Automation.Language.TokenKind]::PostfixPlusPlus -and
            $n.Child -is [System.Management.Automation.Language.VariableExpressionAst] -and
            $n.Child.VariablePath.UserPath -eq $VariableName
        }, $true) | ForEach-Object {
            $parent = $_.Parent
            while ($null -ne $parent -and -not ($parent -is [System.Management.Automation.Language.StatementBlockAst])) {
                $parent = $parent.Parent
            }
            [pscustomobject]@{ Line = $_.Extent.StartLineNumber; Block = $parent }
        }
    }
}

Describe 'Autoimporter: ein Lauf mit offenen Punkten merkt den Stamp nicht' {
    It 'die Stamp-Zuweisung liegt im else-Zweig von $scanWarnings -gt 0' {
        # Der Stamp ist die Change-Detection: wird er nach einem Lauf mit offenen
        # Punkten gemerkt, ist der naechste Lauf "unveraendert" und meldet ok.
        # Ein Paket, das nie entstanden ist, war damit ab dem zweiten Durchlauf
        # unsichtbar. Nur ein Lauf ohne offene Punkte darf ihn merken.
        $if = Get-IfByCondition -Path $script:Importer -ConditionText '$scanWarnings -gt 0'
        $if | Should -Not -BeNullOrEmpty

        $then = $if.Clauses[0].Item2.Extent.Text
        $then | Should -Not -Match 'lastFilesStamp\s*='
        $if.ElseClause | Should -Not -BeNullOrEmpty
        $if.ElseClause.Extent.Text | Should -Match '\$lastFilesStamp\s*=\s*\$stamp'
    }

    It 'merkt den Stamp genau an einer Stelle im Erfolgsfall' {
        # Eine zweite Zuweisung irgendwo im Scan waere der Defekt zurueck, ohne
        # dass der Test oben es merkt. Der Reset auf '' bei einem Fehler zaehlt
        # nicht mit: er verwirft den Stamp, er merkt ihn nicht.
        $assignments = (Get-Ast -Path $script:Importer).FindAll({ param($n)
            $n -is [System.Management.Automation.Language.AssignmentStatementAst] -and
            $n.Left.Extent.Text -eq '$lastFilesStamp' -and
            $n.Right.Extent.Text -ne "''"
        }, $true)

        @($assignments).Count | Should -Be 1
    }

    It 'der Zweig fuer den fehlenden Paket-Pfad merkt den Stamp nicht' {
        $if = Get-IfByCondition -Path $script:Importer -ConditionText '$stamp -eq $lastFilesStamp'
        $if | Should -Not -BeNullOrEmpty

        # Der elseif-Zweig (Paket-Pfad fehlt) ist Clauses[1].
        @($if.Clauses).Count | Should -BeGreaterThan 1
        $missing = $if.Clauses[1].Item2.Extent.Text
        $missing | Should -Match 'package_source_missing'
        $missing | Should -Not -Match 'lastFilesStamp\s*='
    }
}

Describe 'Autoimporter: jeder offene Punkt nennt seine Ursache' {
    It 'jedes $scanWarnings++ steht im selben Block wie ein Add-VsRunCause' {
        # Ein Zaehler ohne Ursache meldet "N offene Punkte" ohne zu sagen, welche:
        # der Operator sieht eine gelbe Karte und hat nichts, wonach er suchen
        # kann. Die Blockgleichheit ist die Pruefung, weil eine Ursache im
        # Nachbarzweig die falsche Zeile beschreibt.
        $increments = @(Get-IncrementBlocks -Path $script:Importer -VariableName 'scanWarnings')
        $increments.Count | Should -BeGreaterThan 5

        foreach ($increment in $increments) {
            $increment.Block | Should -Not -BeNullOrEmpty
            $increment.Block.Extent.Text |
                Should -Match 'Add-VsRunCause' -Because ("das \$scanWarnings++ in Zeile {0} nennt keine Ursache" -f $increment.Line)
        }
    }

    It 'ein unlesbares config.json ist ein offener Punkt' {
        # Vorher: WARN, kein Zaehler, Stamp gemerkt - der Ordner wurde nie wieder
        # gescannt, obwohl kein Paket entstanden war.
        $if = Get-IfByCondition -Path $script:Importer -ConditionText '-not $cfg'
        $if | Should -Not -BeNullOrEmpty

        $body = $if.Clauses[0].Item2.Extent.Text
        $body | Should -Match '\$scanWarnings\+\+'
        $body | Should -Match 'package_config_invalid'
    }

    It 'Content-Verteilung und Ordner-Verschieben haengen nicht an $isNew' {
        # Beide liefen nur beim ersten Anlegen. Schlug es dort fehl, existierte
        # die Application danach, der naechste Durchlauf war nicht mehr "neu",
        # und die Verteilung wurde nie wiederholt: das Paket schlaegt auf jedem
        # Client fehl, waehrend die Karte gruen ist. Seit B7 ist die Frage
        # mehrwertig (Get-VsContentDistributionState), und nur `succeeded`
        # laesst den Stamp zu.
        $source = Get-Content -Path $script:Importer -Raw
        $source | Should -Match 'Get-VsContentDistributionState'
        $source | Should -Not -Match 'Test-VsContentDistributed'
        $source | Should -Match 'Test-VsInOrgFolder'
        $source | Should -Match 'Test-VsTemplateScriptCurrent'
    }

    It 'jeder Verteilzustand ausser succeeded haelt den Stamp zurueck' {
        # B7: Targeted > 0 galt als fertig, NumberErrors las niemand. Jetzt
        # zaehlt jeder Nicht-succeeded-Zweig einen offenen Punkt, und der Stamp
        # (der nur bei $scanWarnings -eq 0 gemerkt wird) wartet auf die
        # vollstaendige Zielverteilung.
        $source = Get-Content -Path $script:Importer -Raw
        foreach ($needle in @('package_content_in_progress', 'package_content_unknown', 'package_content_failed')) {
            $source | Should -Match $needle
        }
        # Der Stamp umfasst das Vorlagen-Skript: eine neue Vorlage loest den
        # Scan aus, nicht erst die naechste config.json.
        $source | Should -Match 'Get-VsFilesStamp -Path \$basePath -TemplateScript'
    }

    It 'das Vorlagen-Skript wird ausserhalb des $isNew-Zweigs abgeglichen' {
        $if = Get-IfByCondition -Path $script:Importer -ConditionText '$isNew'
        $if | Should -Not -BeNullOrEmpty
        $if.Clauses[0].Item2.Extent.Text | Should -Not -Match 'Test-VsTemplateScriptCurrent'
    }
}

Describe 'Ursachenvokabular: kein Code ohne Aufrufer, kein Aufruf ohne Code' {
    It 'jeder Code des Vokabulars wird von mindestens einem Skript benutzt' {
        # Ein Code, den niemand setzt, ist eine Zeile Doku ohne Wirkung: der Fall,
        # den er beschreiben soll, laeuft weiter still durch.
        $vocabulary = Invoke-InFileScope -Path $script:MecmCommon -Body { $script:VsRunCauseVocabulary }
        $sources = (Get-ChildItem -Path $script:MecmDir -Filter 'mecm_*.ps1' |
            ForEach-Object { Get-Content -Path $_.FullName -Raw }) -join "`n"

        foreach ($code in $vocabulary) {
            $sources | Should -Match ("Cause\s+'{0}'" -f [regex]::Escape($code)) -Because ("'{0}' setzt niemand" -f $code)
        }
    }

    It 'jeder gesetzte Code steht im Vokabular' {
        # Das ValidateSet faellt erst beim Aufruf auf dem MECM-Server auf; ein
        # Tippfehler in einem Zweig, den nur ein Fehlschlag betritt, wuerde dort
        # den Lauf abbrechen statt ihn zu melden.
        $vocabulary = @(Invoke-InFileScope -Path $script:MecmCommon -Body { $script:VsRunCauseVocabulary })
        foreach ($file in (Get-ChildItem -Path $script:MecmDir -Filter 'mecm_*.ps1')) {
            $text = Get-Content -Path $file.FullName -Raw
            foreach ($match in [regex]::Matches($text, "-Cause\s+'([a-z_]+)'")) {
                $vocabulary | Should -Contain $match.Groups[1].Value -Because ("{0} setzt einen unbekannten Code" -f $file.Name)
            }
        }
    }
}

Describe 'Test-VsTemplateScriptCurrent (Inhalt, nicht Zeitstempel)' {
    BeforeAll {
        $script:Sandbox = Join-Path ([System.IO.Path]::GetTempPath()) ('vs-tpl-' + [guid]::NewGuid().ToString('N'))
        New-Item -Path $script:Sandbox -ItemType Directory -Force | Out-Null
        $script:TemplateFile = Join-Path $script:Sandbox 'template.ps1'
        $script:PackageFile = Join-Path $script:Sandbox 'package.ps1'
    }
    AfterAll {
        Remove-Item -Path $script:Sandbox -Recurse -Force -ErrorAction SilentlyContinue
    }

    It 'gleicher Inhalt bei verschiedenem Zeitstempel gilt als aktuell' {
        # Copy-Item -Force setzt die Zeitstempel neu. Ein Datumsvergleich haette
        # deshalb bei jedem Scan erneut kopiert.
        Set-Content -Path $script:TemplateFile -Value 'exit 0' -Encoding UTF8
        Set-Content -Path $script:PackageFile -Value 'exit 0' -Encoding UTF8
        (Get-Item $script:PackageFile).LastWriteTime = (Get-Item $script:TemplateFile).LastWriteTime.AddDays(-30)

        Invoke-InFileScope -Path $script:MecmCommon -Arguments @($script:TemplateFile, $script:PackageFile) -Body {
            param($t, $p)
            Test-VsTemplateScriptCurrent -TemplateFile $t -PackageFile $p
        } | Should -BeTrue
    }

    It 'abweichender Inhalt verlangt einen neuen Versuch' {
        Set-Content -Path $script:TemplateFile -Value 'exit 0' -Encoding UTF8
        Set-Content -Path $script:PackageFile -Value 'exit 1' -Encoding UTF8

        Invoke-InFileScope -Path $script:MecmCommon -Arguments @($script:TemplateFile, $script:PackageFile) -Body {
            param($t, $p)
            Test-VsTemplateScriptCurrent -TemplateFile $t -PackageFile $p
        } | Should -BeFalse
    }

    It 'ein Paket ohne eigene install.ps1 gilt als aktuell' {
        # Die Vorlage ueberschreibt eine vorhandene Datei; sie legt keine an, wo
        # das Paket bewusst keine hat.
        Set-Content -Path $script:TemplateFile -Value 'exit 0' -Encoding UTF8
        $absent = Join-Path $script:Sandbox 'fehlt.ps1'

        Invoke-InFileScope -Path $script:MecmCommon -Arguments @($script:TemplateFile, $absent) -Body {
            param($t, $p)
            Test-VsTemplateScriptCurrent -TemplateFile $t -PackageFile $p
        } | Should -BeTrue
    }
}

Describe 'Installer: die vier Aufgaben ueberleben ihren eigenen Neustartzaehler' {
    BeforeAll {
        $script:InstallerText = Get-Content -Path $script:Installer -Raw
    }

    It 'die Aufgaben starten ohne Profil' {
        # SYSTEM kann ein AllUsersAllHosts-Profil haben: Fremdcode im Sync-Prozess,
        # der Kodierung, PSModulePath oder $ErrorActionPreference verstellt.
        $script:InstallerText | Should -Match "New-ScheduledTaskAction[^\r\n]*-NoProfile"
    }

    It 'jede Aufgabe hat zwei Trigger: Systemstart und Wiederholung' {
        # -AtStartup allein hiess: nach -RestartCount 3 ist die Aufgabe bis zum
        # naechsten Reboot tot, und ein MECM-Server bootet selten. Der Ausfall
        # sieht dann aus wie eine stille Integration.
        $ast = Get-Ast -Path $script:Installer
        $triggers = $ast.FindAll({ param($n)
            $n -is [System.Management.Automation.Language.AssignmentStatementAst] -and
            $n.Left.Extent.Text -eq '$triggers'
        }, $true) | Select-Object -First 1
        $triggers | Should -Not -BeNullOrEmpty

        $definition = $triggers.Right.Extent.Text
        $definition | Should -Match '-AtStartup'
        $definition | Should -Match '-RepetitionInterval'

        # Und die Definition benutzt sie auch, nicht nur einen davon.
        $script:InstallerText | Should -Match 'New-ScheduledTask[^\r\n]*-Trigger \$triggers'
    }

    It 'IgnoreNew bleibt, weil der zweite Trigger sonst doppelt startet' {
        $script:InstallerText | Should -Match '-MultipleInstances IgnoreNew'
    }

    It 'die laufenden Aufgaben werden vor dem Kopieren beendet' {
        # Ein Re-Run, der die .ps1 unter einer laufenden Instanz austauscht, laesst
        # diese mit dem alten dot-gesourcten Common weiterlaufen, waehrend die
        # neue Registry-Konfiguration schon da ist.
        $lines = Get-Content -Path $script:Installer
        $stopLine = ($lines | Select-String -Pattern 'Stop-ScheduledTask' | Select-Object -First 1).LineNumber
        $copyLine = ($lines | Select-String -Pattern "Copy-Item -Path \(Join-Path \`$sourceDir" | Select-Object -First 1).LineNumber

        $stopLine | Should -Not -BeNullOrEmpty
        $copyLine | Should -Not -BeNullOrEmpty
        $stopLine | Should -BeLessThan $copyLine
    }
}

Describe 'Installer: ein Re-Run ohne Parameter aendert keinen eingestellten Wert' {
    BeforeAll {
        $script:InstallerAst = Get-Ast -Path $script:Installer

        # Die Schluessel der $settings-Hashtable, also alles, was der Installer in
        # die Registry schreibt.
        function Get-SettingsKeys {
            $assignment = $script:InstallerAst.FindAll({ param($n)
                $n -is [System.Management.Automation.Language.AssignmentStatementAst] -and
                $n.Left.Extent.Text -eq '$settings' -and
                $n.Right.Expression -is [System.Management.Automation.Language.HashtableAst]
            }, $true) | Select-Object -First 1
            $assignment.Right.Expression.KeyValuePairs | ForEach-Object { $_.Item1.Extent.Text }
        }

        # Die Schluessel der Erhaltungs-Tabelle: Parametername -> Settingname.
        function Get-PreservedMap {
            $assignment = $script:InstallerAst.FindAll({ param($n)
                $n -is [System.Management.Automation.Language.AssignmentStatementAst] -and
                $n.Left.Extent.Text -eq '$parameterToSetting'
            }, $true) | Select-Object -First 1
            $map = @{}
            foreach ($pair in $assignment.Right.Expression.KeyValuePairs) {
                $map[$pair.Item1.Extent.Text] = $pair.Item2.Extent.Text.Trim("'")
            }
            $map
        }

        function Get-InstallerParameterNames {
            $script:InstallerAst.ParamBlock.Parameters | ForEach-Object { $_.Name.VariablePath.UserPath }
        }

        # Werte, die einen Re-Run bewusst NICHT ueberleben muessen, jeder mit dem
        # Grund. Zwei Tests lesen diese Liste, in beide Richtungen.
        $script:ReRunExempt = @{
            'VirtuSphere_WebAPI'   = 'Pflichtparameter, wird bei jedem Lauf angegeben'
            'PackagesShare'        = 'Pflichtparameter, wird bei jedem Lauf angegeben'
            'LogRoot'              = 'aus PackagesRoot abgeleitet, kein eigener Parameter'
            'MECM_SiteCode'        = 'wird erkannt, nicht eingestellt'
            'MECM_ProviderMachine' = 'hat seine eigene Erhaltung vor der Tabelle'
        }
    }

    It 'jeder erhaltene Settingname ist ein echter Installer-Parameter' {
        $parameters = @(Get-InstallerParameterNames)
        foreach ($entry in (Get-PreservedMap).GetEnumerator()) {
            $parameters | Should -Contain $entry.Key
        }
    }

    It 'jeder erhaltene Settingname wird auch geschrieben' {
        # Sonst liest die Erhaltungsschleife einen Registry-Wert, den niemand
        # anlegt, und behaelt still nichts.
        $keys = @(Get-SettingsKeys)
        foreach ($entry in (Get-PreservedMap).GetEnumerator()) {
            $keys | Should -Contain $entry.Value
        }
    }

    It 'jeder erhaltene Settingname kommt bei Get-VsConfig zurueck' {
        # Die Schleife liest ihn per $existingConfig.$settingName; ein Name, den
        # Get-VsConfig nicht kennt, wuerde einen leeren Wert behalten.
        $commonText = Get-Content -Path $script:MecmCommon -Raw
        foreach ($entry in (Get-PreservedMap).GetEnumerator()) {
            $commonText | Should -Match ([regex]::Escape($entry.Value)) -Because ("Get-VsConfig kennt '{0}' nicht" -f $entry.Value)
        }
    }

    It 'jeder geschriebene Wert ist entweder erhalten oder begruendet ausgenommen' {
        # Der Walk ueber die Tabelle statt einer festen Liste: ein kuenftiger
        # fuenfter Textwert faellt hier auf, statt still bei jedem Skript-Update
        # auf seinen Standard zurueckzufallen.
        $preserved = (Get-PreservedMap).Values

        foreach ($key in (Get-SettingsKeys)) {
            if ($key -like '*IntervalSeconds') { continue }   # eigene Schleife, eigener Test
            if ($preserved -contains $key) { continue }
            $script:ReRunExempt.ContainsKey($key) |
                Should -BeTrue -Because ("'{0}' ueberlebt einen Re-Run nicht und steht auch nicht mit Grund in der Ausnahmeliste" -f $key)
        }
    }

    It 'die Ausnahmeliste nennt keinen Wert, den der Installer nicht mehr schreibt' {
        # Andersrum gelesen: eine Ausnahme, die ins Leere zeigt, deckt kuenftig
        # den falschen Schluessel. Zwei der fuenf werden bedingt geschrieben und
        # stehen deshalb nicht in der Hashtable-Literalform.
        $keys = @(Get-SettingsKeys)
        $text = Get-Content -Path $script:Installer -Raw

        foreach ($name in $script:ReRunExempt.Keys) {
            $written = ($keys -contains $name) -or
                ($text -match ("\`$settings\['{0}'\]\s*=" -f [regex]::Escape($name)))
            $written | Should -BeTrue -Because ("'{0}' wird nicht mehr geschrieben, die Ausnahme zeigt ins Leere" -f $name)
        }
    }
}


# Die Paketvorlage ist der Zwilling von Read-VsPackageConfig auf der Clientseite:
# sie wird einzeln in den Paketordner kopiert und sieht VirtuSphere-Common.ps1
# nie (ADR-0029). Genau deshalb muss ein Test die beiden Pflichtfeldlisten
# gegeneinander halten, statt sie zu einer Datei zusammenzuziehen.
Describe 'Package_Vorlage: config.json wird vor der ersten Skriptausfuehrung geprueft' {

    BeforeAll {
        $script:Template = Join-Path (Join-Path $script:PsRoot 'Package_Vorlage') 'install.ps1'
        $script:TemplateAst = Get-Ast -Path $script:Template
        $script:TemplateText = Get-Content -Raw -Path $script:Template
    }

    It 'bricht ab, bevor das erste Teilskript laeuft' {
        # Ohne Guard lief das Skript mit $config = $null weiter: der
        # Registry-Pfad wurde zu "...\Packages\-", und alle Teilskripte liefen
        # trotzdem als SYSTEM. MECM faengt das erst ueber die nicht erfuellte
        # Detection ab, also nach der Ausfuehrung.
        $loops = @($script:TemplateAst.FindAll({
            param($n) $n -is [System.Management.Automation.Language.ForEachStatementAst]
        }, $true))
        $loops.Count | Should -BeGreaterThan 0 -Because 'sonst prueft dieser Test die falsche Stelle'
        $firstLoop = ($loops | Sort-Object { $_.Extent.StartOffset })[0].Extent.StartOffset

        $exitsBefore = @($script:TemplateAst.FindAll({
            param($n) $n -is [System.Management.Automation.Language.ExitStatementAst]
        }, $true) | Where-Object { $_.Extent.StartOffset -lt $firstLoop })
        $exitsBefore.Count | Should -BeGreaterThan 0
    }

    It 'laeuft unter Set-StrictMode' {
        # Als einzige Datei im Baum lief sie ohne. Sie startet fremde Skripte
        # als SYSTEM; ein stilles $null entscheidet hier ueber Registry-Zweig
        # und Detection-Wert.
        $script:TemplateText | Should -Match 'Set-StrictMode -Version 1\.0'
    }

    It 'liest die Datei erst nach einem Test-Path und faengt kaputtes JSON ab' {
        $script:TemplateText | Should -Match 'Test-Path \$configPath'
        $script:TemplateText | Should -Match '(?s)try\s*\{[^}]*ConvertFrom-Json.*?\}\s*catch'
    }

    It 'prueft dieselben Pflichtfelder wie Read-VsPackageConfig' {
        # Zwillingsbeziehung, keine gemeinsame Datei: die Vorlage sieht Common
        # nie. Driftet eine der beiden Listen, akzeptiert eine Seite ein Paket,
        # das die andere ablehnt.
        $commonText = Get-Content -Raw -Path $script:MecmCommon
        $required = @('ProjectName', 'version')
        foreach ($field in $required) {
            $commonText | Should -Match ([regex]::Escape('$cfg.' + $field))
            $script:TemplateText | Should -Match ([regex]::Escape('$config.' + $field))
        }
    }
}
