# Pester-Suite fuer den Autoimporter und den MECM-Installer.
#
# Beide melden Zustaende, die niemand nachpruefen kann: der Autoimporter laeuft
# als SYSTEM in einer Endlosschleife auf dem MECM-Server, der Installer laeuft
# einmal und hinterlaesst vier geplante Aufgaben. Was hier gepinnt wird, ist
# nicht "der Code tut X", sondern "ein Lauf, der etwas nicht geschafft hat, sagt
# das auch" - der Stamp, die Ursachenliste und die Trigger-Definition sind die
# drei Stellen, an denen ein Fehlschlag bisher als gelungener Lauf endete.
#
# Statisch ueber den AST sowie mit extrahierten Controllerbloecken und lokalen
# Fixtures, weil kein MECM-Server im Test steht: die echten Provider-, Registry-
# und MECM-Grenzen bleiben ersetzt, die produktive Verzweigung wird ausgefuehrt.

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

    # Fuehrt den originalen Content-Controllerblock fuer genau einen Scan aus.
    # Die Schleifenhuelle macht seine `continue`-Grenzen ausfuehrbar, waehrend
    # Provider, Registry und MECM-Aufrufe als kleine In-Memory-Fixture dienen.
    $controllerSource = Get-Content -LiteralPath $script:Importer -Raw
    $controllerStart = $controllerSource.IndexOf('$packageManifest = Get-VsFilesManifestStamp -Path $pkgFolder')
    $controllerEnd = if ($controllerStart -ge 0) {
        $controllerSource.IndexOf('# --- Collection + Deployments idempotent nachziehen', $controllerStart)
    } else { -1 }
    if ($controllerStart -lt 0 -or $controllerEnd -le $controllerStart) {
        throw 'U09-Controllerblock konnte nicht aus dem Autoimporter extrahiert werden.'
    }
    $script:U09ControllerBlock = [scriptblock]::Create("do {`n" + $controllerSource.Substring($controllerStart, $controllerEnd - $controllerStart) + "`n} while (`$false)")

    function Invoke-U09ControllerScan {
        param(
            [Parameter(Mandatory)]$TrackingBox,
            [Parameter(Mandatory)]$RequestCounters,
            [Parameter(Mandatory)][string]$Manifest,
            [Parameter(Mandatory)][string]$ContentId,
            [Parameter(Mandatory)][ValidateSet('not_started', 'succeeded')][string]$AggregateState,
            [Parameter(Mandatory)][AllowEmptyCollection()][array]$CopyTargets,
            [ValidateSet('none', 'initial', 'update')][string]$RequestFailure = 'none'
        )
        . $script:MecmCommon

        function Get-VsFilesManifestStamp { param($Path) return $Manifest }
        function Get-VsPackageContentTracking { param($ApplicationName) return $TrackingBox.Value }
        function Get-VsContentDistributionSnapshot {
            param($ApplicationName, $Application)
            return [pscustomobject]@{ State = $AggregateState; SourceVersion = 1 }
        }
        function Get-VsDistributionCopySnapshot {
            param($PackageId, $ApplicationModelName, $SiteCode, $ProviderMachine)
            return [pscustomobject]@{ State = 'known'; Targets = @($CopyTargets) }
        }
        function Set-VsPackageContentTracking {
            param(
                $ApplicationName, $State, $Manifest, $BaselineSourceVersion = -1, $SourceVersion = -1,
                $RequestKind, $ApplicationModelName, $ApplicationPackageId, $DeploymentTypeModelName,
                $DeploymentTypeId, $BaselineContentId, $ContentId = '', $RequestConfirmed = $false,
                $DistributionBaseline
            )
            $TrackingBox.Value = [pscustomobject]@{
                State = $State; Manifest = $Manifest; BaselineSourceVersion = [int]$BaselineSourceVersion
                SourceVersion = [int]$SourceVersion; RequestKind = $RequestKind
                ApplicationModelName = $ApplicationModelName; ApplicationPackageId = $ApplicationPackageId
                DeploymentTypeModelName = $DeploymentTypeModelName; DeploymentTypeId = $DeploymentTypeId
                BaselineContentId = $BaselineContentId; ContentId = $ContentId
                RequestConfirmed = [bool]$RequestConfirmed; DistributionBaseline = $DistributionBaseline
            }
        }
        function Update-CMDistributionPoint {
            param($ApplicationName, $DeploymentTypeName, $ErrorAction)
            $RequestCounters.Update++
            if ($RequestFailure -eq 'update') { throw 'simulierter Updatefehler' }
        }
        function Start-CMContentDistribution {
            param($ApplicationName, $DistributionPointGroupName, $ErrorAction)
            $RequestCounters.Initial++
            if ($RequestFailure -eq 'initial') { throw 'simulierter Erstverteilungsfehler' }
        }
        function Write-VsLog { param($Level, $Context, $Message) }
        function Add-VsRunCause { param($Causes, $Cause, $Target) }

        $pkgFolder = 'fixture-package'
        $fullName = 'Agent-1'
        $deploymentTypeName = 'Agent-1 Deployment'
        $siteCode = 'ABC'
        $providerMachine = 'provider.test.invalid'
        $dpGroupName = 'DP-Fixture'
        $scanWarnings = 0
        $causes = New-Object System.Collections.Generic.List[object]
        $app = [pscustomobject]@{
            LocalizedDisplayName = $fullName; ModelName = 'ScopeId_A/Application_A'; PackageID = 'ABC00001'
        }
        $deploymentTypes = @([pscustomobject]@{
            LocalizedDisplayName = $deploymentTypeName; AppModelName = $app.ModelName
            ModelName = 'ScopeId_A/DeploymentType_A'; CI_UniqueID = ('ScopeId_A/DeploymentType_A/{0}' -f $ContentId)
            ContentId = $ContentId
        })

        & $script:U09ControllerBlock
        return $TrackingBox.Value
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
        # mehrwertig (Get-VsContentDistributionSnapshot), und nur `succeeded`
        # laesst den Stamp zu.
        $source = Get-Content -Path $script:Importer -Raw
        $source | Should -Match 'Get-VsContentDistributionSnapshot'
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
        $source | Should -Match 'Get-VsFilesManifestStamp -Path \$basePath -TemplateScript'
    }

    It 'das Vorlagen-Skript wird ausserhalb des $isNew-Zweigs abgeglichen' {
        $if = Get-IfByCondition -Path $script:Importer -ConditionText '$isNew'
        $if | Should -Not -BeNullOrEmpty
        $if.Clauses[0].Item2.Extent.Text | Should -Not -Match 'Test-VsTemplateScriptCurrent'
    }
}

Describe 'Autoimporter U09: Manifest, Intent und providerseitige Contentidentitaet bilden eine Zustandsmaschine' {
    BeforeAll {
        $script:A13Source = Get-Content -Path $script:Importer -Raw
        $script:A13Common = Get-Content -Path $script:MecmCommon -Raw
    }

    It 'speichert Intent vor dem MECM-Aufruf und bestaetigt den Request erst nach dessen Rueckkehr' {
        $intent = $script:A13Source.IndexOf('Set-VsPackageContentTracking -ApplicationName $fullName -State intent')
        $update = $script:A13Source.IndexOf('Update-CMDistributionPoint -ApplicationName $fullName -DeploymentTypeName $deploymentTypeName')
        $intent | Should -BeGreaterOrEqual 0
        $update | Should -BeGreaterThan $intent
        $beforeUpdate = $script:A13Source.Substring($intent, $update - $intent)
        $beforeUpdate | Should -Match '-RequestConfirmed \$false'
        $afterUpdate = $script:A13Source.Substring($update)
        $afterUpdate | Should -Match '(?s)Update-CMDistributionPoint.*-RequestConfirmed \$true'
        $script:A13Source | Should -Match '(?s)State -eq ''intent''.*-not \$tracking\.RequestConfirmed.*package_content_unknown'
    }

    It 'verteilt unabhaengig von der optionalen eigenen Collection' {
        $ownCollectionIf = Get-IfByCondition -Path $script:Importer -ConditionText '"$($cfg.generateOwnDeviceColletion)" -eq ''true'''
        $ownCollectionIf | Should -Not -BeNullOrEmpty
        $body = $ownCollectionIf.Clauses[0].Item2.Extent.Text
        $body | Should -Not -Match 'Start-CMContentDistribution|Update-CMDistributionPoint|Get-VsContentDistributionSnapshot'
        $script:A13Source | Should -Match 'Start-CMContentDistribution'
        $script:A13Source | Should -Match 'Update-CMDistributionPoint'
    }

    It 'bestaetigt ein Manifest ueber ContentId und frische erfolgreiche Kopien aller gleichen DP-Ziele' {
        $script:A13Source | Should -Match 'Get-VsDeploymentTypeContentIdentity'
        $script:A13Source | Should -Match 'Get-VsDistributionCopySnapshot'
        $script:A13Source | Should -Match 'Test-VsDistributionCopyAdvanced'
        $script:A13Source | Should -Match "(?s)State -eq 'succeeded' -and \`$copyAdvanced.*-State complete"
        $script:A13Source | Should -Not -Match 'SourceVersion -gt \[int\]\$tracking\.BaselineSourceVersion'
        $script:A13Source | Should -Match 'Get-VsFilesManifestStamp -Path \$pkgFolder'
    }

    It 'schreibt den Tracking-State als letzten Commit-Marker und akzeptiert nur geschlossene States' {
        $functionText = [regex]::Match($script:A13Common, '(?s)function Set-VsPackageContentTracking \{.*?^\}', [Text.RegularExpressions.RegexOptions]::Multiline).Value
        $readerText = [regex]::Match($script:A13Common, '(?s)function Get-VsPackageContentTracking \{.*?^\}', [Text.RegularExpressions.RegexOptions]::Multiline).Value
        $functionText | Should -Match "ValidateSet\('intent', 'pending', 'complete'\)"
        $functionText | Should -Match "(?s)\`$State -in @\('pending', 'complete'\) -and -not \`$RequestConfirmed"
        $readerText | Should -Match "(?s)\`$state -in @\('pending', 'complete'\) -and \`$requestConfirmed -ne 1"
        $writes = @([regex]::Matches($functionText, 'New-ItemProperty[^\r\n]+-Name State[^\r\n]+'))
        $writes.Count | Should -Be 2
        $writes[0].Value | Should -Match "-Value 'invalid'"
        $writes[1].Value | Should -Match '-Value \$State'
    }

    It 'blockiert mehrdeutige Applications und Deployment Types vor Contentwrites' {
        $script:A13Source | Should -Match '\$appMatches\.Count -gt 1'
        $script:A13Source | Should -Match '\$deploymentTypes\.Count -ne 1'
        $script:A13Source | Should -Match 'package_definition_drift'
    }
}

Describe 'U09 Contentidentitaet und DP-Kopiergrenze' {
    It 'liest nur eine vollstaendige Application-/DT-Bindung als bekannt' {
        $result = Invoke-InFileScope -Path $script:MecmCommon -Body {
            $app = [pscustomobject]@{ LocalizedDisplayName = 'Agent-1'; ModelName = 'ScopeId_A/Application_A'; PackageID = 'ABC00001' }
            $dt = [pscustomobject]@{
                LocalizedDisplayName = 'Agent-1 Deployment'; AppModelName = 'ScopeId_A/Application_A'
                ModelName = 'ScopeId_A/DeploymentType_A'; CI_UniqueID = 'ScopeId_A/DeploymentType_A/1'; ContentId = 'Content_A'
            }
            [pscustomobject]@{
                App = Get-VsApplicationContentIdentity -Application $app -ExpectedName 'Agent-1'
                Dt = Get-VsDeploymentTypeContentIdentity -DeploymentType $dt -ExpectedName 'Agent-1 Deployment' -ExpectedApplicationModelName 'ScopeId_A/Application_A'
                Foreign = Get-VsDeploymentTypeContentIdentity -DeploymentType $dt -ExpectedName 'Agent-1 Deployment' -ExpectedApplicationModelName 'ScopeId_B/Application_B'
            }
        }
        $result.App.State | Should -Be 'known'
        $result.App.PackageId | Should -Be 'ABC00001'
        $result.Dt.State | Should -Be 'known'
        $result.Dt.DeploymentTypeModelName | Should -Be 'ScopeId_A/DeploymentType_A'
        $result.Dt.ContentId | Should -Be 'Content_A'
        $result.Foreign.State | Should -Be 'unknown'
    }

    It 'weist alte succeeded-Aggregate ohne neuere LastCopied-Evidenz ab' {
        $baseline = '[{"site_code":"ABC","server_nal_path":"NAL_A","last_copied_ticks":100}]'
        $sameOldSuccess = [pscustomobject]@{
            State = 'known'; Targets = @([pscustomobject]@{ SiteCode = 'ABC'; ServerNalPath = 'NAL_A'; State = 0; LastCopiedTicks = 100L })
        }
        $newSuccess = [pscustomobject]@{
            State = 'known'; Targets = @([pscustomobject]@{ SiteCode = 'ABC'; ServerNalPath = 'NAL_A'; State = 0; LastCopiedTicks = 101L })
        }
        Invoke-InFileScope -Path $script:MecmCommon -Arguments @($baseline, $sameOldSuccess) -Body {
            param($b, $s) Test-VsDistributionCopyAdvanced -BaselineJson $b -CurrentSnapshot $s
        } | Should -BeFalse
        Invoke-InFileScope -Path $script:MecmCommon -Arguments @($baseline, $newSuccess) -Body {
            param($b, $s) Test-VsDistributionCopyAdvanced -BaselineJson $b -CurrentSnapshot $s
        } | Should -BeTrue
    }

    It 'erlaubt eine leere DP-Baseline nur fuer eine echte Erstverteilung' {
        $empty = [pscustomobject]@{ State = 'known'; Targets = @() }
        Invoke-InFileScope -Path $script:MecmCommon -Arguments @($empty) -Body {
            param($s) Test-VsDistributionCopyBaselineReady -RequestKind initial -Snapshot $s
        } | Should -BeTrue
        Invoke-InFileScope -Path $script:MecmCommon -Arguments @($empty) -Body {
            param($s) Test-VsDistributionCopyBaselineReady -RequestKind update -Snapshot $s
        } | Should -BeFalse
    }

    It 'unterscheidet die leere Baseline von JSON-null und ungueltigen Leerformen' {
        $successfulCopy = [pscustomobject]@{ State = 'known'; Targets = @(
            [pscustomobject]@{ SiteCode = 'ABC'; ServerNalPath = 'NAL_A'; State = 0; LastCopiedTicks = 1L }
        ) }
        Invoke-InFileScope -Path $script:MecmCommon -Arguments @('[]', $successfulCopy) -Body {
            param($b, $s) Test-VsDistributionCopyAdvanced -BaselineJson $b -CurrentSnapshot $s
        } | Should -BeTrue
        $noCopy = [pscustomobject]@{ State = 'known'; Targets = @() }
        Invoke-InFileScope -Path $script:MecmCommon -Arguments @('[]', $noCopy) -Body {
            param($b, $s) Test-VsDistributionCopyAdvanced -BaselineJson $b -CurrentSnapshot $s
        } | Should -BeFalse

        foreach ($invalidBaseline in @('null', '[null]', '[[]]', '[invalid]')) {
            Invoke-InFileScope -Path $script:MecmCommon -Arguments @($invalidBaseline, $successfulCopy) -Body {
                param($b, $s) Test-VsDistributionCopyAdvanced -BaselineJson $b -CurrentSnapshot $s
            } | Should -BeFalse -Because "'$invalidBaseline' keine explizite leere Baseline ist"
        }

        $twoSuccessfulCopies = [pscustomobject]@{ State = 'known'; Targets = @(
            [pscustomobject]@{ SiteCode = 'ABC'; ServerNalPath = 'NAL_A'; State = 0; LastCopiedTicks = 1L }
            [pscustomobject]@{ SiteCode = 'ABC'; ServerNalPath = 'NAL_B'; State = 0; LastCopiedTicks = 1L }
        ) }
        $mixedNullBaseline = '[null,{"site_code":"ABC","server_nal_path":"NAL_B","last_copied_ticks":0}]'
        Invoke-InFileScope -Path $script:MecmCommon -Arguments @($mixedNullBaseline, $twoSuccessfulCopies) -Body {
            param($b, $s) Test-VsDistributionCopyAdvanced -BaselineJson $b -CurrentSnapshot $s
        } | Should -BeFalse
    }

    It 'weist Zielwechsel, Fehler und nur teilweise frische Kopien ab' {
        $baseline = '[{"site_code":"ABC","server_nal_path":"NAL_A","last_copied_ticks":100},{"site_code":"ABC","server_nal_path":"NAL_B","last_copied_ticks":200}]'
        $cases = @(
            [pscustomobject]@{ State = 'known'; Targets = @([pscustomobject]@{ SiteCode = 'ABC'; ServerNalPath = 'NAL_A'; State = 0; LastCopiedTicks = 101L }) }
            [pscustomobject]@{ State = 'known'; Targets = @(
                [pscustomobject]@{ SiteCode = 'ABC'; ServerNalPath = 'NAL_A'; State = 0; LastCopiedTicks = 101L }
                [pscustomobject]@{ SiteCode = 'ABC'; ServerNalPath = 'NAL_B'; State = 3; LastCopiedTicks = 201L }
            ) }
            [pscustomobject]@{ State = 'known'; Targets = @(
                [pscustomobject]@{ SiteCode = 'ABC'; ServerNalPath = 'NAL_A'; State = 0; LastCopiedTicks = 101L }
                [pscustomobject]@{ SiteCode = 'ABC'; ServerNalPath = 'NAL_B'; State = 0; LastCopiedTicks = 200L }
            ) }
        )
        foreach ($snapshot in $cases) {
            Invoke-InFileScope -Path $script:MecmCommon -Arguments @($baseline, $snapshot) -Body {
                param($b, $s) Test-VsDistributionCopyAdvanced -BaselineJson $b -CurrentSnapshot $s
            } | Should -BeFalse
        }
    }

    It 'weist ein korruptes oder doppelt belegtes persistiertes Targetset ab' {
        $current = [pscustomobject]@{ State = 'known'; Targets = @(
            [pscustomobject]@{ SiteCode = 'ABC'; ServerNalPath = 'NAL_A'; State = 0; LastCopiedTicks = 101L }
            [pscustomobject]@{ SiteCode = 'ABC'; ServerNalPath = 'NAL_B'; State = 0; LastCopiedTicks = 201L }
        ) }
        $badBaselines = @(
            '{"site_code":"ABC","server_nal_path":"NAL_A","last_copied_ticks":100}'
            '[{"site_code":"ABC","server_nal_path":"NAL_A","last_copied_ticks":-1},{"site_code":"ABC","server_nal_path":"NAL_B","last_copied_ticks":200}]'
            '[{"site_code":"ABC","server_nal_path":"NAL_A","last_copied_ticks":100},{"site_code":"ABC","server_nal_path":"NAL_A","last_copied_ticks":100}]'
        )
        foreach ($baseline in $badBaselines) {
            Invoke-InFileScope -Path $script:MecmCommon -Arguments @($baseline, $current) -Body {
                param($b, $s) Test-VsDistributionCopyAdvanced -BaselineJson $b -CurrentSnapshot $s
            } | Should -BeFalse
        }
    }

    It 'schliesst zwei Contentupdates bei Package-SourceVersion 1 erst nach neuer ContentId und neuer DP-Kopie ab' {
        $manifestA = ('A' * 64) -join ''
        $manifestB = ('B' * 64) -join ''
        $manifestC = ('C' * 64) -join ''
        $baselineA = '[{"site_code":"ABC","server_nal_path":"NAL_A","last_copied_ticks":90}]'
        $tracking = [pscustomobject]@{ Value = [pscustomobject]@{
            State = 'complete'; Manifest = $manifestA; BaselineSourceVersion = 1; SourceVersion = 1; RequestKind = 'update'
            ApplicationModelName = 'ScopeId_A/Application_A'; ApplicationPackageId = 'ABC00001'
            DeploymentTypeModelName = 'ScopeId_A/DeploymentType_A'; DeploymentTypeId = 'ScopeId_A/DeploymentType_A/ContentA'
            BaselineContentId = 'BeforeA'; ContentId = 'ContentA'; RequestConfirmed = $true; DistributionBaseline = $baselineA
        } }
        $requests = [pscustomobject]@{ Initial = 0; Update = 0 }
        $copy100 = @([pscustomobject]@{ SiteCode = 'ABC'; ServerNalPath = 'NAL_A'; State = 0; LastCopiedTicks = 100L })
        $copy101 = @([pscustomobject]@{ SiteCode = 'ABC'; ServerNalPath = 'NAL_A'; State = 0; LastCopiedTicks = 101L })
        $copy102 = @([pscustomobject]@{ SiteCode = 'ABC'; ServerNalPath = 'NAL_A'; State = 0; LastCopiedTicks = 102L })

        Invoke-U09ControllerScan -TrackingBox $tracking -RequestCounters $requests -Manifest $manifestB -ContentId ContentA -AggregateState succeeded -CopyTargets $copy100 | Out-Null
        $requests.Update | Should -Be 1
        $tracking.Value.State | Should -Be 'intent'
        $tracking.Value.RequestConfirmed | Should -BeTrue
        $tracking.Value.SourceVersion | Should -Be -1

        Invoke-U09ControllerScan -TrackingBox $tracking -RequestCounters $requests -Manifest $manifestB -ContentId ContentB -AggregateState succeeded -CopyTargets $copy100 | Out-Null
        $tracking.Value.State | Should -Be 'pending'
        $tracking.Value.ContentId | Should -Be 'ContentB'
        Invoke-U09ControllerScan -TrackingBox $tracking -RequestCounters $requests -Manifest $manifestB -ContentId ContentB -AggregateState succeeded -CopyTargets $copy100 | Out-Null
        $tracking.Value.State | Should -Be 'pending' -Because 'das alte succeeded-Aggregat keine frische Kopie der neuen ContentId beweist'
        Invoke-U09ControllerScan -TrackingBox $tracking -RequestCounters $requests -Manifest $manifestB -ContentId ContentB -AggregateState succeeded -CopyTargets $copy101 | Out-Null
        $tracking.Value.State | Should -Be 'complete'
        $tracking.Value.SourceVersion | Should -Be 1

        Invoke-U09ControllerScan -TrackingBox $tracking -RequestCounters $requests -Manifest $manifestC -ContentId ContentB -AggregateState succeeded -CopyTargets $copy101 | Out-Null
        $requests.Update | Should -Be 2
        $tracking.Value.State | Should -Be 'intent'
        Invoke-U09ControllerScan -TrackingBox $tracking -RequestCounters $requests -Manifest $manifestC -ContentId ContentC -AggregateState succeeded -CopyTargets $copy101 | Out-Null
        $tracking.Value.State | Should -Be 'pending'
        Invoke-U09ControllerScan -TrackingBox $tracking -RequestCounters $requests -Manifest $manifestC -ContentId ContentC -AggregateState succeeded -CopyTargets $copy101 | Out-Null
        $tracking.Value.State | Should -Be 'pending'
        Invoke-U09ControllerScan -TrackingBox $tracking -RequestCounters $requests -Manifest $manifestC -ContentId ContentC -AggregateState succeeded -CopyTargets $copy102 | Out-Null
        $tracking.Value.State | Should -Be 'complete'
        $tracking.Value.SourceVersion | Should -Be 1
        $requests.Update | Should -Be 2
    }

    It 'wiederholt einen fehlgeschlagenen initialen oder Update-Aufruf nicht blind' {
        $manifestA = ('A' * 64) -join ''
        $manifestB = ('B' * 64) -join ''
        $copy100 = @([pscustomobject]@{ SiteCode = 'ABC'; ServerNalPath = 'NAL_A'; State = 0; LastCopiedTicks = 100L })

        $updateTracking = [pscustomobject]@{ Value = [pscustomobject]@{
            State = 'complete'; Manifest = $manifestA; BaselineSourceVersion = 1; SourceVersion = 1; RequestKind = 'update'
            ApplicationModelName = 'ScopeId_A/Application_A'; ApplicationPackageId = 'ABC00001'
            DeploymentTypeModelName = 'ScopeId_A/DeploymentType_A'; DeploymentTypeId = 'ScopeId_A/DeploymentType_A/ContentA'
            BaselineContentId = 'BeforeA'; ContentId = 'ContentA'; RequestConfirmed = $true
            DistributionBaseline = '[{"site_code":"ABC","server_nal_path":"NAL_A","last_copied_ticks":90}]'
        } }
        $updateRequests = [pscustomobject]@{ Initial = 0; Update = 0 }
        Invoke-U09ControllerScan -TrackingBox $updateTracking -RequestCounters $updateRequests -Manifest $manifestB -ContentId ContentA -AggregateState succeeded -CopyTargets $copy100 -RequestFailure update | Out-Null
        $updateTracking.Value.State | Should -Be 'intent'
        $updateTracking.Value.RequestConfirmed | Should -BeFalse
        Invoke-U09ControllerScan -TrackingBox $updateTracking -RequestCounters $updateRequests -Manifest $manifestB -ContentId ContentB -AggregateState succeeded -CopyTargets $copy100 | Out-Null
        $updateRequests.Update | Should -Be 1
        $updateTracking.Value.State | Should -Be 'intent'
        $updateTracking.Value.RequestConfirmed | Should -BeFalse

        $initialTracking = [pscustomobject]@{ Value = $null }
        $initialRequests = [pscustomobject]@{ Initial = 0; Update = 0 }
        Invoke-U09ControllerScan -TrackingBox $initialTracking -RequestCounters $initialRequests -Manifest $manifestA -ContentId ContentA -AggregateState not_started -CopyTargets @() -RequestFailure initial | Out-Null
        $initialTracking.Value.State | Should -Be 'intent'
        $initialTracking.Value.RequestConfirmed | Should -BeFalse
        Invoke-U09ControllerScan -TrackingBox $initialTracking -RequestCounters $initialRequests -Manifest $manifestA -ContentId ContentA -AggregateState not_started -CopyTargets @() | Out-Null
        $initialRequests.Initial | Should -Be 1
        $initialTracking.Value.State | Should -Be 'intent'
        $initialTracking.Value.RequestConfirmed | Should -BeFalse
    }
}

Describe 'Ursachenvokabular: kein Code ohne Aufrufer, kein Aufruf ohne Code' {
    It 'jeder Code des Vokabulars wird von mindestens einem Skript benutzt' {
        # Ein Code, den niemand setzt, ist eine Zeile Doku ohne Wirkung: der Fall,
        # den er beschreiben soll, laeuft weiter still durch.
        #
        # Seit Etappe 14D gibt es drei Formen, in denen ein Code entsteht: direkt
        # am Aufruf (`-Cause 'x'`), als Rueckgabe der reinen Identitaets-
        # aufloesung in VirtuSphere-Common.ps1, die der Device-Sync als
        # `-Cause $identity.Cause` durchreicht, und als Zweigauswahl vor dem
        # Aufruf (`$cause = if (...) { 'x' } else { 'y' }`). Alle drei sind echte
        # Produzenten, also wird auf das Vorkommen des LITERALS geprueft.
        #
        # Damit das etwas prueft, muessen vorher BEIDE Selbstnennungen des Codes
        # herausgeschnitten werden: die Vokabelliste und der spiegelgleiche
        # ValidateSet in Add-VsRunCause. Ohne diese Schnitte erfuellte sich die
        # Liste selbst, und der Test waere dauerhaft und unsichtbar gruen -
        # genau die Sorte Waechter, die aussieht wie ein sauberes Repo. Beide
        # Schnitte werden deshalb ueberprueft, bevor irgendetwas gesucht wird.
        $vocabulary = Invoke-InFileScope -Path $script:MecmCommon -Body { $script:VsRunCauseVocabulary }
        $commonText = Get-Content -Path $script:MecmCommon -Raw
        $withoutSelfReference = [regex]::Replace($commonText, '(?s)\$script:VsRunCauseVocabulary\s*=\s*@\(.*?\r?\n\)', '<vokabular entfernt>')
        # Das innere `(?:(?!\[ValidateSet\().)*?` ist notwendig, nicht schmueckend:
        # ein schlichtes `.*?` beginnt beim ERSTEN ValidateSet der Datei (dem von
        # Invoke-VsApi) und frisst alles bis zu diesem hier - samt der
        # Identitaetsaufloesung dazwischen. Der Schnitt loeschte damit genau die
        # Produzenten, die er finden lassen sollte, und der Test wurde rot fuer
        # einen Code, den es sehr wohl gibt.
        $withoutSelfReference = [regex]::Replace($withoutSelfReference, '(?s)\[ValidateSet\((?:(?!\[ValidateSet\().)*?\)\]\s*\r?\n\s*\[string\]\$Cause', '<validateset entfernt>')
        $withoutSelfReference | Should -Not -Match 'VsRunCauseVocabulary\s*=\s*@\(' -Because 'ohne diesen Schnitt erfuellt die Liste sich selbst'
        $withoutSelfReference | Should -Not -Match "ValidateSet\('mission_missing'" -Because 'ohne diesen Schnitt erfuellt der ValidateSet die Liste'
        $withoutSelfReference | Should -Match 'Resolve-VsDeviceIdentity' -Because 'der Schnitt darf die Aufloesung nicht mitnehmen, sonst prueft der Test das Gegenteil'

        $sources = ((Get-ChildItem -Path $script:MecmDir -Filter 'mecm_*.ps1' |
            ForEach-Object { Get-Content -Path $_.FullName -Raw }) + @($withoutSelfReference)) -join "`n"

        foreach ($code in $vocabulary) {
            $sources | Should -Match ("'{0}'" -f [regex]::Escape($code)) -Because ("'{0}' setzt niemand" -f $code)
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

    It 'ein Paket ohne erwartete install.ps1 verlangt einen neuen Versuch' {
        Set-Content -Path $script:TemplateFile -Value 'exit 0' -Encoding UTF8
        $absent = Join-Path $script:Sandbox 'fehlt.ps1'

        Invoke-InFileScope -Path $script:MecmCommon -Arguments @($script:TemplateFile, $absent) -Body {
            param($t, $p)
            Test-VsTemplateScriptCurrent -TemplateFile $t -PackageFile $p
        } | Should -BeFalse
    }

    It 'fehlende Vorlage und fehlende Paket-install.ps1 gelten nicht als aktuell' {
        $absentTemplate = Join-Path $script:Sandbox 'keine-vorlage.ps1'
        $absentPackage = Join-Path $script:Sandbox 'kein-paket.ps1'
        Invoke-InFileScope -Path $script:MecmCommon -Arguments @($absentTemplate, $absentPackage) -Body {
            param($t, $p) Test-VsTemplateScriptCurrent -TemplateFile $t -PackageFile $p
        } | Should -BeFalse
    }

    It 'vorhandene Paket-install.ps1 bleibt ohne zentrale Vorlage ein gueltiges Bestandspaket' {
        $absentTemplate = Join-Path $script:Sandbox 'keine-vorlage.ps1'
        Set-Content -Path $script:PackageFile -Value 'exit 0' -Encoding UTF8
        Invoke-InFileScope -Path $script:MecmCommon -Arguments @($absentTemplate, $script:PackageFile) -Body {
            param($t, $p) Test-VsTemplateScriptCurrent -TemplateFile $t -PackageFile $p
        } | Should -BeTrue
    }

    It 'ein Verzeichnis namens install.ps1 gilt nicht als lesbares Skript' {
        $absentTemplate = Join-Path $script:Sandbox 'keine-vorlage.ps1'
        $directoryScript = Join-Path $script:Sandbox 'ordner-install.ps1'
        New-Item -Path $directoryScript -ItemType Directory -Force | Out-Null
        Invoke-InFileScope -Path $script:MecmCommon -Arguments @($absentTemplate, $directoryScript) -Body {
            param($t, $p) Test-VsTemplateScriptCurrent -TemplateFile $t -PackageFile $p
        } | Should -BeFalse
    }
}

Describe 'Get-VsFilesManifestStamp (Inhalt statt mtime)' {
    BeforeAll {
        function script:Get-ManifestStampForTest {
            Invoke-InFileScope -Path $script:MecmCommon -Arguments @($script:ManifestRoot, $script:Template) -Body {
                param($root, $template) Get-VsFilesManifestStamp -Path $root -TemplateScript $template
            }
        }
    }

    BeforeEach {
        $script:ManifestRoot = Join-Path $TestDrive 'files'
        New-Item -Path (Join-Path $script:ManifestRoot 'Pkg') -ItemType Directory -Force | Out-Null
        $script:Payload = Join-Path (Join-Path $script:ManifestRoot 'Pkg') 'payload.bin'
        $script:Template = Join-Path $TestDrive 'template.ps1'
        Set-Content -Path $script:Payload -Value 'eins' -Encoding UTF8
        Set-Content -Path $script:Template -Value 'exit 0' -Encoding UTF8
    }

    It 'bleibt bei rein geaenderter mtime stabil' {
        $before = Get-ManifestStampForTest
        (Get-Item $script:Payload).LastWriteTimeUtc = (Get-Item $script:Payload).LastWriteTimeUtc.AddDays(-1)
        Get-ManifestStampForTest | Should -Be $before
    }

    It 'erkennt Payloadinhalt auch bei wiederhergestellter mtime' {
        $before = Get-ManifestStampForTest
        $stamp = (Get-Item $script:Payload).LastWriteTimeUtc
        Set-Content -Path $script:Payload -Value 'zwei' -Encoding UTF8
        (Get-Item $script:Payload).LastWriteTimeUtc = $stamp
        Get-ManifestStampForTest | Should -Not -Be $before
    }

    It 'erkennt geloeschte und neu hinzugefuegte Dateien sowie die Vorlage' {
        $before = Get-ManifestStampForTest
        Set-Content -Path (Join-Path (Split-Path $script:Payload -Parent) 'neu.txt') -Value 'neu' -Encoding UTF8
        $withNew = Get-ManifestStampForTest
        $withNew | Should -Not -Be $before
        Remove-Item -LiteralPath $script:Payload -Force
        Get-ManifestStampForTest | Should -Not -Be $withNew
        Set-Content -Path $script:Template -Value 'exit 2' -Encoding UTF8
        Get-ManifestStampForTest | Should -Not -Be $withNew
    }
}

Describe 'Installer: die vier Aufgaben ueberleben ihren eigenen Neustartzaehler' {
    BeforeAll {
        $script:InstallerText = Get-Content -Path $script:Installer -Raw
    }

    It 'die Aufgaben starten ohne Profil' {
        # SYSTEM kann ein AllUsersAllHosts-Profil haben: Fremdcode im Sync-Prozess,
        # der Kodierung, PSModulePath oder $ErrorActionPreference verstellt.
        #
        # Die Schalter selbst stehen seit E7 in $script:VsPowerShellArgs
        # (VirtuSphere-Common.ps1), weil drei weitere Aufrufstellen sie nicht
        # setzten. Was in der Konstante steht und dass JEDE powershell.exe-Zeile
        # im Baum sie traegt, pinnt die Describe
        # "Jede powershell.exe-Aufrufstelle laeuft ohne Profil und nicht
        # interaktiv" in ErrorPaths; hier bleibt die Frage, ob die Aufgabe ihre
        # Kommandozeile von dort bezieht.
        $script:InstallerText | Should -Match "New-ScheduledTaskAction[^\r\n]*(-NoProfile|VsPowerShellArgs)"
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

    It 'Trigger werden deaktiviert und laufende Aufgaben vor dem Live-Austausch beendet' {
        # Ein Re-Run, der die .ps1 unter einer laufenden Instanz austauscht, laesst
        # diese mit dem alten dot-gesourcten Common weiterlaufen, waehrend die
        # neue Registry-Konfiguration schon da ist. Der stuendliche Trigger muss
        # vor dem Stop deaktiviert sein, damit im Stop/Move-Fenster kein neuer
        # Lauf startet. Das vorbereitende Staging darf vorher stattfinden; erst
        # der Move ins Live-Verzeichnis muss nach Disable und Stop kommen.
        $lines = Get-Content -Path $script:Installer
        $disableLine = ($lines | Select-String -Pattern 'Disable-ScheduledTask' | Select-Object -First 1).LineNumber
        $stopLine = ($lines | Select-String -Pattern 'Stop-ScheduledTask' | Select-Object -First 1).LineNumber
        $moveLine = ($lines | Select-String -Pattern 'Move-Item -Path \(Join-Path \$installStage' | Select-Object -First 1).LineNumber

        $disableLine | Should -Not -BeNullOrEmpty
        $stopLine | Should -Not -BeNullOrEmpty
        $moveLine | Should -Not -BeNullOrEmpty
        $disableLine | Should -BeLessThan $stopLine
        $stopLine | Should -BeLessThan $moveLine
        ($lines -join "`n") | Should -Match 'konnte vor dem sicheren Skriptaustausch nicht deaktiviert und beendet werden'
    }
}

Describe 'Installer A11: gemeinsame Aktivierungs- und Rollbackgrenze' {
    BeforeAll {
        $script:A11Text = Get-Content -Path $script:Installer -Raw
        $script:A11Lines = Get-Content -Path $script:Installer
    }

    It 'serialisiert konkurrierende Installer und akzeptiert nur den festen lokalen Installationspfad' {
        $script:A11Text | Should -Match "Global\\VirtuSphere\.MECMInstaller"
        $script:A11Text | Should -Match 'Assert-VsOwnedInstallDirectory'
        $script:A11Text | Should -Match "StringComparison\]::OrdinalIgnoreCase"
        $script:A11Text | Should -Match 'FileAttributes\]::ReparsePoint'
    }

    It 'sichert Registrywerte mit Typ und ACL sowie Task-XML und Laufzustand vor dem ersten Write' {
        $registrySnapshot = ($script:A11Lines | Select-String -SimpleMatch '$registryRollback = New-VsRegistryRollbackSnapshot').LineNumber
        $taskSnapshot = ($script:A11Lines | Select-String -SimpleMatch '$taskRollbacks = @(New-VsTaskRollbackSnapshots').LineNumber
        $registryWrite = ($script:A11Lines | Select-String -SimpleMatch "Write-Step 'Lege Registry-Schluessel").LineNumber
        $registrySnapshot | Should -BeLessThan $registryWrite
        $taskSnapshot | Should -BeLessThan $registryWrite
        $script:A11Text | Should -Match 'GetValueKind'
        $script:A11Text | Should -Match '\$acl = \$key\.GetAccessControl\(\)'
        $script:A11Text | Should -Match 'Export-ScheduledTask'
        $script:A11Text | Should -Match 'WasRunning'
    }

    It 'haelt den Alt-Dateisatz bis nach Registrymarker und Aufgabenstart rollbackfaehig' {
        $backupCreate = ($script:A11Lines | Select-String -SimpleMatch "New-Item -ItemType Directory -Path `$installBackup").LineNumber
        $marker = ($script:A11Lines | Select-String -SimpleMatch "New-ItemProperty -Path `$registryPath -Name 'SetupCompleted'").LineNumber
        $successCleanup = ($script:A11Lines | Select-String -SimpleMatch "Remove-Item -LiteralPath `$installBackup -Recurse -Force -ErrorAction Stop").LineNumber | Select-Object -First 1
        $backupCreate | Should -BeLessThan $marker
        $successCleanup | Should -BeGreaterThan $marker
        $script:A11Text | Should -Match 'ActivatedFiles'
    }

    It 'stoppt neue Prozesse vor dem Datei-Rollback und startet alte Tasks nur nach vollstaendigem Rueckfall' {
        $restore = [regex]::Match($script:A11Text, '(?s)function Restore-VsInstallTransaction \{.*?^\}', [Text.RegularExpressions.RegexOptions]::Multiline).Value
        $disable = $restore.IndexOf('Disable-ScheduledTask')
        $wait = $restore.IndexOf('Wait-VsScheduledScriptStopped')
        $fileRestore = $restore.IndexOf('foreach ($name in $ActivatedFiles.ToArray())')
        $taskStart = $restore.IndexOf('Start-ScheduledTask')
        $disable | Should -BeGreaterThan -1
        $wait | Should -BeGreaterThan $disable
        $fileRestore | Should -BeGreaterThan $wait
        $taskStart | Should -BeGreaterThan $fileRestore
        $restore | Should -Match '\$quiesced -and \$filesRestored -and \$registryRestored'
        $restore | Should -Match 'Aufgaben bleiben deaktiviert'
    }

    It 'stellt exakte Registrywerte und alte Aufgaben wieder her und entfernt nur neu angelegte eigene Tasks' {
        $script:A11Text | Should -Match 'Remove-ItemProperty -LiteralPath \$registryPath'
        $script:A11Text | Should -Match 'New-ItemProperty -Path \$registryPath -Name \$value\.Name.*-PropertyType \$value\.Kind.*-ErrorAction Stop'
        $script:A11Text | Should -Match 'Set-VsRegistrySecurityDescriptor -Path \$registryPath -Acl \$RegistrySnapshot\.Acl'
        $script:A11Text | Should -Match 'Register-ScheduledTask -TaskName \$snapshot\.Name -Xml \$snapshot\.Xml -Force -ErrorAction Stop'
        $script:A11Text | Should -Match 'Unregister-ScheduledTask -TaskName \$snapshot\.Name -Confirm:\$false -ErrorAction Stop'
    }
}

Describe 'Installer U09: Package_Vorlage teilt Stage-, Hash- und Rollbackgrenze' {
    BeforeAll {
        $script:U09InstallerText = Get-Content -Path $script:Installer -Raw
        $script:U09InstallerLines = Get-Content -Path $script:Installer
    }

    It 'verlangt install.ps1 und config.json vor der ersten Taskmutation' {
        $required = ($script:U09InstallerLines | Select-String -SimpleMatch '$requiredTemplateFiles = @(''install.ps1'', ''config.json'')').LineNumber
        $disable = ($script:U09InstallerLines | Select-String -SimpleMatch '$taskMutationStarted = $true').LineNumber
        $required | Should -Not -BeNullOrEmpty
        $required | Should -BeLessThan $disable
    }

    It 'staged die Vorlage als eindeutigen Geschwisterpfad auf dem PackagesRoot-Volume und prueft jeden Dateiinhalt' {
        $script:U09InstallerText | Should -Match "Join-Path \`$PackagesRoot \('\.virtusphere-template-stage-'"
        $script:U09InstallerText | Should -Not -Match "Join-Path \`$installStage 'Package_Vorlage'"
        $script:U09InstallerText | Should -Match 'Get-FileHash -Algorithm SHA256 -LiteralPath \$source\.FullName'
        $script:U09InstallerText | Should -Match 'Get-FileHash -Algorithm SHA256 -LiteralPath \$staged'
        $script:U09InstallerText | Should -Match 'templateDirectoryManifest'
    }

    It 'aktiviert die Vorlage ueber Backup und verifiziert den vollstaendigen Live-Datei- und Verzeichnissatz' {
        $backup = $script:U09InstallerText.IndexOf('Move-Item -LiteralPath $templateDest -Destination $templateBackup')
        $activate = $script:U09InstallerText.IndexOf('Move-Item -LiteralPath $templateStage -Destination $templateDest')
        $verify = $script:U09InstallerText.IndexOf("throw 'Aktivierte Paketvorlage enthaelt nicht den vollstaendigen geprueften Dateisatz.'")
        $backup | Should -BeGreaterThan -1
        $activate | Should -BeGreaterThan $backup
        $verify | Should -BeGreaterThan $activate
        $script:U09InstallerText | Should -Not -Match "Copy-Item -Path \(Join-Path \`$templateSource '\*'\)"
    }

    It 'stellt das alte Template vor einem Aufgabenrestart wieder her und behaelt unvollstaendigen Rollback sichtbar' {
        $restore = [regex]::Match($script:U09InstallerText, '(?s)function Restore-VsInstallTransaction \{.*?^\}', [Text.RegularExpressions.RegexOptions]::Multiline).Value
        $removeNew = $restore.IndexOf('Remove-Item -LiteralPath $TemplateDestination -Recurse')
        $restoreOld = $restore.IndexOf('Move-Item -LiteralPath $TemplateBackupPath -Destination $TemplateDestination')
        $taskStart = $restore.IndexOf('Start-ScheduledTask')
        $removeNew | Should -BeGreaterThan -1
        $restoreOld | Should -BeGreaterThan $removeNew
        $taskStart | Should -BeGreaterThan $restoreOld
        $restore | Should -Match '\$quiesced -and \$filesRestored -and \$registryRestored'
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

        # Die Schluessel des zentralen Resolver-Aufrufs: Parameter -> Setting.
        function Get-PreservedMap {
            $assignment = $script:InstallerAst.FindAll({ param($n)
                $n -is [System.Management.Automation.Language.AssignmentStatementAst] -and
                $n.Left.Extent.Text -eq '$textSettingMap'
            }, $true) | Select-Object -First 1
            $map = @{}
            foreach ($pair in $assignment.Right.Expression.KeyValuePairs) {
                $parameterName = $pair.Item1.Extent.Text
                if ($parameterName -eq 'ProviderMachine') { continue } # eigene Clear-/Omit-Semantik
                $map[$parameterName] = $pair.Item2.Extent.Text.Trim("'")
            }
            $map['ReportToken'] = 'ReportToken' # interaktive Quelle, derselbe Resolver
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

    It 'ein ausdruecklich leerer ReportToken loescht ihn' {
        # Der zentrale Resolver erhaelt die Herkunft explizit. Dadurch kann eine
        # interaktive Eingabe nicht mehr von einer spaeteren Erhaltungsschleife
        # ueberschrieben werden und ein gebundenes Leer bleibt "cleared".
        $resolution = @($script:InstallerAst.FindAll({
            param($n) $n -is [System.Management.Automation.Language.AssignmentStatementAst] -and
                      $n.Left.Extent.Text -eq '$tokenResolution'
        }, $true))
        $resolution.Count | Should -Be 1
        $resolution[0].Extent.Text | Should -Match 'Resolve-VsInstallerSetting'
        $resolution[0].Extent.Text | Should -Match 'PSBoundParameters'
        $resolution[0].Extent.Text | Should -Match 'ExplicitEmptyClears'
        $resolution[0].Extent.Text | Should -Match 'InteractiveEmptyKeepsExisting'

        # Dieselbe Frage an der interaktiven Abfrage: -ReportToken '' fiel dort
        # in den Read-Host, obwohl der Aufrufer den Parameter genannt hat.
        # Hier die Bedingung selbst filtern: das innerste if um den Read-Host
        # ist [Environment]::UserInteractive und beantwortet eine andere Frage.
        $prompt = @($script:InstallerAst.FindAll({
            param($n) $n -is [System.Management.Automation.Language.IfStatementAst] -and
                      $n.Extent.Text -match 'Read-Host' -and
                      $n.Clauses[0].Item1.Extent.Text -match 'ReportToken'
        }, $true))
        $prompt.Count | Should -BeGreaterThan 0 -Because 'sonst prueft dieser Test die falsche Stelle'
        $prompt[0].Clauses[0].Item1.Extent.Text | Should -Match 'PSBoundParameters'
    }

    It 'die Loeschung des Tokens steht im Log' {
        # Ein still verschwindender Rueckkanal ist der Fehler, den die
        # Erhaltungslogik urspruenglich behoben hat: auch die GEWOLLTE Loeschung
        # muss sichtbar sein. Kein Blocker, weil sie eine Bedienhandlung ist.
        $text = Get-Content -Path $script:Installer -Raw
        $text | Should -Match "(?i)Rueckkanal-Token wird GELOESCHT"
        $text | Should -Match "(?s)Rueckkanal-Token wird GELOESCHT[^\r\n]*"
        # Nie der Wert selbst.
        $text | Should -Not -Match '(?i)Token[^\r\n]{0,40}\{0\}[^\r\n]{0,40}-f \$ReportToken'
    }

    It 'der Autoimporter verspricht keine Deinstallation, die es nicht gibt' {
        # 'cmd.exe /s' als UninstallCommand: /s wirkt nur hinter /c oder /k,
        # beides fehlte. Uebrig blieb eine Shell, die nichts liest und mit 0
        # endet, also eine erfolgreich gemeldete Deinstallation, die nichts
        # entfernt hat. Ausgeloest wurde sie von nichts, aber ein Feld, das eine
        # Zusage macht, die das System nicht haelt, gehoert nicht in einen
        # Deployment-Type. Eine echte Deinstallation waere eine Funktion mit
        # eigener Vorlage und eigenem Vertrag.
        $text = Get-Content -Path $script:Importer -Raw
        $text | Should -Not -Match '(?i)UninstallCommand\s*='
        # Der Test darf nicht daran haengen, dass die Datei irgendwo 'cmd.exe'
        # nennt: geprueft wird die Zuweisung, und dass $dtParams noch da ist.
        $text | Should -Match '\$dtParams\s*=\s*@\{'
    }

    It 'A14a behaelt erkannte Alt-Versionen und fuehrt im Cleanup-Zweig keine MECM-Loeschung aus' {
        $text = Get-Content -Path $script:Importer -Raw
        $text | Should -Match 'Alt-Version bleibt erhalten'
        $text | Should -Match 'Eigentums-, Referenz- und Ersatznachweis'

        $tokens = $null
        $errors = $null
        $ast = [System.Management.Automation.Language.Parser]::ParseFile($script:Importer, [ref]$tokens, [ref]$errors)
        @($errors).Count | Should -Be 0
        $cleanupIf = @($ast.FindAll({
            param($node)
            $node -is [System.Management.Automation.Language.IfStatementAst] -and
                $node.Extent.Text -match 'removeOldVersion' -and
                $node.Extent.Text -match 'Alt-Version bleibt erhalten'
        }, $true) | Sort-Object { $_.Extent.Text.Length } | Select-Object -First 1)
        $cleanupIf.Count | Should -Be 1
        $cleanupIf[0].Extent.Text | Should -Not -Match 'Remove-CM(?:Application|ApplicationDeployment|DeviceCollection)'
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

    It 'fuehrt die Erfolgscodeliste genau einmal' {
        # Sie stand doppelt: einmal als Kommentar, einmal als vierfache
        # -eq-Kette. Ueber den AST gezaehlt, damit der erklaerende Kommentar
        # nicht mitzaehlt.
        $literals = @($script:TemplateAst.FindAll({
            param($n) $n -is [System.Management.Automation.Language.ConstantExpressionAst] -and
                      $n.Value -is [int] -and $n.Value -eq 1707
        }, $true))
        $literals.Count | Should -Be 1 -Because '1707 kommt nur in der Erfolgscodeliste vor'
        $script:TemplateText | Should -Match '\$successExitCodes\s+-contains\s+\$childExitCode'
    }

    It 'kann einen Neustartcode an MECM weiterreichen' {
        # 3010 und 1641 wurden auf "Erfolg" eingeebnet und der Wrapper endete
        # mit 0, obwohl der Deployment-Type auf BasedOnExitCode steht: das
        # Neustartverhalten war konfiguriert und unerreichbar gemacht.
        $exits = @($script:TemplateAst.FindAll({
            param($n) $n -is [System.Management.Automation.Language.ExitStatementAst]
        }, $true) | Where-Object { $_.Extent.Text -match 'rebootCode' })
        $exits.Count | Should -BeGreaterThan 0

        # Rangfolge: der Fehlschlag wird VOR dem Neustartcode geprueft, sonst
        # meldet ein Paket "bitte neu starten" statt "hat nicht funktioniert".
        $script:TemplateText | Should -Match '(?s)-not \$Fullsuccess.*?exit 1.*?\$rebootCode -ne 0.*?exit \$rebootCode'
        # 1641 vor 3010: dort ist der Neustart bereits eingeleitet, und MECM
        # darf keinen zweiten planen.
        $script:TemplateText | Should -Match '\$rebootExitCodes\s*=\s*@\(1641,\s*3010\)'
    }

    It 'merkt keinen Neustartcode fuer ein uebersprungenes Teilskript' {
        # Sonst forderte ein Paket, das beim ersten Lauf 3010 lieferte und beim
        # zweiten den Skip-Zweig nimmt, bei jedem Lauf erneut einen Neustart.
        $ifs = @($script:TemplateAst.FindAll({
            param($n) $n -is [System.Management.Automation.Language.IfStatementAst] -and
                      $n.Extent.Text -match 'bereits erfolgreich ausgefuehrt'
        }, $true) | Sort-Object { $_.Extent.Text.Length })
        $ifs.Count | Should -BeGreaterThan 0 -Because 'sonst prueft dieser Test die falsche Stelle'
        $ifs[0].ElseClause.Extent.Text | Should -Not -Match 'rebootCode'
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
