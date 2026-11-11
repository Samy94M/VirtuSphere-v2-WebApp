# Pester-Suite fuer die Autoimporter-Robustheit (AR1, 10.11.2026).
#
# Drei Feldbefunde (qa-artifacts/audit-mecm-2026-11-09/autoimporter.md):
#  F-1  offene Punkte hielten den Dateistand zurueck: jede Minute Vollscan;
#  F-3  DeployTo wurde weder geprueft noch auf Existenz gelesen, dauerhaft und
#       voruebergehend waren nicht zu unterscheiden (dazu F-16: DeployTo auf die
#       eigene Collection wurde still ignoriert);
#  F-4  ein Fehler in einem Paket brach den ganzen Scan ab und meldete
#       mecm_unavailable, obwohl MECM antwortete.
#
# Die Zyklusfaelle fuehren den originalen Schleifenrumpf von
# mecm_autoimporter.ps1 aus (nur die while-Huelle faellt weg); MECM, Registry
# und Dateisystem sind kleine In-Memory-Fixtures mit synthetischen Namen.

BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $script:MecmDir = Join-Path (Join-Path $script:RepoRoot 'Powershell-MECM') 'mecm'
    $script:MecmCommon = Join-Path $script:MecmDir 'VirtuSphere-Common.ps1'
    $script:Importer = Join-Path $script:MecmDir 'mecm_autoimporter.ps1'

    function Invoke-InFileScope {
        param([string]$Path, [scriptblock]$Body, [object[]]$Arguments = @())
        & {
            param($p, $b, $a)
            . $p
            & $b @a
        } $Path $Body $Arguments
    }

    $tokens = $null; $errors = $null
    $ast = [System.Management.Automation.Language.Parser]::ParseFile($script:Importer, [ref]$tokens, [ref]$errors)
    $mainLoop = @($ast.FindAll({ param($node)
        $node -is [System.Management.Automation.Language.WhileStatementAst] -and
        $node.Condition.Extent.Text -eq '$true' -and $node.Extent.StartLineNumber -gt 70
    }, $true))
    if ($mainLoop.Count -ne 1) { throw 'Autoimporter-Hauptschleife konnte nicht eindeutig extrahiert werden.' }
    $mainBody = $mainLoop[0].Body.Extent.Text
    $script:CycleBlock = [scriptblock]::Create($mainBody.Substring(1, $mainBody.Length - 2))

    # Synthetischer Paketbestand. Jedes Paket: Folder, Product, Own
    # (generateOwnDeviceColletion), DeployTo, Invalid (config.json ungueltig).
    function New-ArBox {
        param([object[]]$Packages, [string[]]$Collections = @())
        $collectionList = New-Object System.Collections.Generic.List[string]
        foreach ($c in $Collections) { $collectionList.Add($c) }
        return [pscustomobject]@{
            Packages = [System.Collections.Generic.List[object]]@($Packages)
            Collections = $collectionList
            Deployments = (New-Object System.Collections.Generic.List[string])
            Stamp = 'stamp-1'
            Poison = (New-Object System.Collections.Generic.List[string])
            CollectionLookupThrows = $false
            ProviderDown = $false
            Inits = 0
            Iteration = 0
        }
    }

    function New-ArPackage {
        param([string]$Folder, [string]$Product, [bool]$Own = $false, [string]$DeployTo = '', [bool]$Invalid = $false)
        return [pscustomobject]@{ Folder = $Folder; Product = $Product; Own = $Own; DeployTo = $DeployTo; Invalid = $Invalid }
    }

    # Fuehrt $Iterations Laeufe der Hauptschleife im selben Prozesszustand aus.
    # $Between laeuft vor jedem weiteren Lauf im selben Scope (Zugriff auf
    # $openPackages, $Box) und bekommt die Laufnummer.
    function Invoke-ArCycles {
        param([Parameter(Mandatory)]$Box, [int]$Iterations = 1, [scriptblock]$Between = {})
        . $script:MecmCommon
        $reports = New-Object System.Collections.Generic.List[object]
        $logs = New-Object System.Collections.Generic.List[object]
        $calls = New-Object System.Collections.Generic.List[string]
        $sleeps = New-Object System.Collections.Generic.List[int]

        function Initialize-VsCmSite { param($Config) $Box.Inits++; return 'XYZ' }
        function Get-VsProviderMachine { param($Config, $ProviderMachine) return 'provider.example.test' }
        function Get-CMFolder { param($FolderPath, $ErrorAction) return [pscustomobject]@{ Name = $FolderPath } }
        function Get-VsFilesManifestStamp {
            param($Path, $TemplatePath)
            if ($Path -eq $basePath) { return $Box.Stamp }
            return ('A' * 64)
        }
        function Test-Path { param($Path, $LiteralPath) return $true }
        function Get-ChildItem {
            param($Path, [switch]$Directory)
            return @($Box.Packages | ForEach-Object { [pscustomobject]@{ Name = $_.Folder; FullName = (Join-Path $basePath $_.Folder) } })
        }
        function Read-VsPackageConfig {
            param($Folder)
            $leaf = Split-Path $Folder -Leaf
            $calls.Add(('{0}|config|{1}' -f $Box.Iteration, $leaf))
            $p = @($Box.Packages | Where-Object { $_.Folder -eq $leaf })[0]
            if ($p.Invalid) { return $null }
            return [pscustomobject]@{
                ProjectName = $p.Product; version = '1'; FolderName = $leaf; removeOldVersion = 'false'
                generateOwnDeviceColletion = $(if ($p.Own) { 'true' } else { 'false' }); DeployTo = $p.DeployTo
                InstallationBehaviorType = 'InstallForSystem'; ErrorAction = 'Stop'
            }
        }
        function Get-ArProduct { param([string]$Name) return $Name.Substring(0, $Name.LastIndexOf('-')) }
        function Get-CMApplication {
            param($Name, [switch]$Fast, $ErrorAction)
            $calls.Add(('{0}|app|{1}' -f $Box.Iteration, $Name))
            $product = Get-ArProduct $Name
            return [pscustomobject]@{
                LocalizedDisplayName = $Name; ModelName = "ScopeId_A/Application_$product"; PackageID = 'XYZ00001'
                LocalizedDescription = $script:VsManagedPackageApplicationMarker
            }
        }
        function Get-CMDeploymentType {
            param($ApplicationName, $ErrorAction)
            $product = Get-ArProduct $ApplicationName
            if ($Box.Poison.Contains($product)) { throw 'SMS Provider: Objekt nicht lesbar (synthetisch)' }
            return [pscustomobject]@{
                LocalizedDisplayName = "$ApplicationName Deployment"; AppModelName = "ScopeId_A/Application_$product"
                ModelName = "ScopeId_A/DeploymentType_$product"; CI_UniqueID = "ScopeId_A/DeploymentType_$product/Content1"; ContentId = 'Content1'
            }
        }
        function Get-VsPackageContentRequest {
            param($ApplicationName)
            $product = Get-ArProduct $ApplicationName
            return [pscustomobject]@{
                State = 'requested'; Origin = 'current'; Manifest = ('A' * 64); RequestKind = 'update'
                ApplicationModelName = "ScopeId_A/Application_$product"; ApplicationPackageId = 'XYZ00001'; BaselineContentId = 'Content1'
            }
        }
        function Get-VsContentDistributionSnapshot { param($ApplicationName, $Application) return [pscustomobject]@{ State = 'succeeded'; SourceVersion = 1; TargetCount = 2 } }
        function Test-VsPackageTemplateSetCurrent { param($TemplateRoot, $PackageRoot) return $true }
        function Get-CMSite {
            param($SiteCode, $ErrorAction)
            if ($Box.ProviderDown) { throw 'The RPC server is unavailable. (synthetisch)' }
            return [pscustomobject]@{ SiteCode = $SiteCode }
        }
        function Get-CMCollection {
            param($Name, $ErrorAction)
            $calls.Add(('{0}|collection|{1}|{2}' -f $Box.Iteration, $Name, $ErrorAction))
            if ($Box.CollectionLookupThrows) { throw 'SMS Provider: Abfrage abgebrochen (synthetisch)' }
            return @($Box.Collections | Where-Object { $_ -eq $Name } | ForEach-Object { [pscustomobject]@{ Name = $_ } })
        }
        function Get-CMDeviceCollection { param($Name, $ErrorAction) return [pscustomobject]@{ Name = $Name } }
        function New-CMDeviceCollection { param($Name, $LimitingCollectionName, $Comment, $ErrorAction) }
        function Test-VsInOrgFolder { param($Collection, $FolderPath) return $true }
        function Get-CMApplicationDeployment {
            param($Name, $CollectionName, $ErrorAction)
            $calls.Add(('{0}|deployment-get|{1}|{2}' -f $Box.Iteration, $CollectionName, $ErrorAction))
            return @($Box.Deployments | Where-Object { $_ -eq ('{0}|{1}' -f $Name, $CollectionName) })
        }
        function New-CMApplicationDeployment {
            param($Name, $CollectionName, $DeployAction, $DeployPurpose, $UserNotification, $ErrorAction)
            $calls.Add(('{0}|deployment-new|{1}|{2}' -f $Box.Iteration, $CollectionName, $DeployPurpose))
            $Box.Deployments.Add(('{0}|{1}' -f $Name, $CollectionName))
        }
        function New-VsRunId { return ('{0:D32}' -f $Box.Iteration) }
        function Get-VsRunDurationMilliseconds { param($StartedAt) return 25 }
        function Send-VsRunReport {
            param($Config, $Source, $RunEvent, $RunId, $IntervalSeconds, $Outcome, $ErrorCategory, $DurationMs, $Detail, $Summary, $ScriptVersion)
            if ($RunEvent -eq 'completed') {
                [void]$reports.Add([pscustomobject]@{ Iteration = $Box.Iteration; Outcome = $Outcome; Category = $ErrorCategory; Detail = $Detail; Summary = $Summary })
            }
        }
        function Write-VsLog { param($Level, $Context, $Message) [void]$logs.Add([pscustomobject]@{ Iteration = $Box.Iteration; Level = $Level; Context = $Context; Message = $Message }) }
        function Write-Host { param($Object, $ForegroundColor) }
        function Start-Sleep { param($Seconds) $sleeps.Add([int]$Seconds) }

        $fixtureRoot = Join-Path ([IO.Path]::GetTempPath()) 'fixture-ar1'
        $config = [pscustomobject]@{ PackagesRoot = $fixtureRoot; PackagesShare = '\\fileserver.example.test\packages' }
        $basePath = Join-Path $fixtureRoot 'files'
        $templatePath = Join-Path $fixtureRoot 'Package_Vorlage'
        $networkPath = $config.PackagesShare
        $appFolderName = $script:VsApplicationsFolderName
        $dpGroupName = 'DP-Fixture'
        $intervalSeconds = 300
        $SCRIPT_VERSION = 'autoimporter/fixture'
        $siteCode = $null
        $providerMachine = $null
        $lastFilesStamp = ''
        $loop = 0
        $pendingAppFolderMoves = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::Ordinal)
        $rememberedDistributionHints = New-Object 'System.Collections.Generic.Dictionary[string,string]' ([StringComparer]::Ordinal)
        $lastHintRefresh = Get-Date
        $openPackages = New-VsImporterOpenPackageSet

        for ($i = 1; $i -le $Iterations; $i++) {
            $Box.Iteration = $i
            if ($i -gt 1) { . $Between $i }
            $loop++
            # Der Rumpf endet selbst mit Start-Sleep -Seconds $sleepSeconds.
            . $script:CycleBlock
        }
        return [pscustomobject]@{
            Reports = $reports.ToArray(); Logs = $logs.ToArray(); Calls = $calls.ToArray(); Sleeps = $sleeps.ToArray()
            Open = $openPackages
        }
    }
}

Describe 'AR1 F-3: Read-VsPackageConfig prueft DeployTo wie ProjectName und version' {
    BeforeAll {
        function Read-ConfigWithDeployTo {
            param([string]$DeployToJson, [switch]$Omit)
            $folder = Join-Path $TestDrive ('pkg-' + [guid]::NewGuid().ToString('N'))
            New-Item -ItemType Directory -Path $folder | Out-Null
            $deployPart = if ($Omit) { '' } else { ', "DeployTo": ' + $DeployToJson }
            $json = '{ "ProjectName": "Agent", "version": "1.0", "ErrorAction": "Stop"' + $deployPart + ' }'
            [IO.File]::WriteAllText((Join-Path $folder 'config.json'), $json)
            Invoke-InFileScope -Path $script:MecmCommon -Arguments @($folder) -Body {
                param($f)
                $script:arLogs = New-Object System.Collections.Generic.List[string]
                function Write-VsLog { param($Level, $Context, $Message) $script:arLogs.Add([string]$Message) }
                [pscustomobject]@{ Config = (Read-VsPackageConfig -Folder $f); Logs = $script:arLogs.ToArray() }
            }
        }
    }

    It 'nimmt <name> an' -ForEach @(
        @{ name = 'einen exakten Namen'; json = '"Abteilung Test"'; expected = 'Abteilung Test' }
        @{ name = 'einen leeren Wert (kein Zusatz-Deployment)'; json = '""'; expected = '' }
        @{ name = 'null'; json = 'null'; expected = $null }
    ) {
        $r = Read-ConfigWithDeployTo -DeployToJson $json
        $r.Config | Should -Not -BeNullOrEmpty
        $r.Config.DeployTo | Should -Be $expected
    }

    It 'nimmt eine config.json ohne DeployTo an' {
        $r = Read-ConfigWithDeployTo -Omit
        $r.Config | Should -Not -BeNullOrEmpty
    }

    It 'ueberspringt das Paket bei <name> und sagt warum' -ForEach @(
        @{ name = 'einer Liste'; json = '["Gruppe A", "Gruppe B"]'; text = 'JSON-Zeichenkette' }
        @{ name = 'einer Zahl'; json = '5'; text = 'JSON-Zeichenkette' }
        @{ name = 'einem Objekt'; json = '{ "Name": "Gruppe A" }'; text = 'JSON-Zeichenkette' }
        @{ name = 'einem Wahrheitswert'; json = 'true'; text = 'JSON-Zeichenkette' }
        @{ name = 'fuehrendem Leerzeichen'; json = '" Gruppe A"'; text = 'Rand-Leerraum' }
        @{ name = 'abschliessendem Leerzeichen'; json = '"Gruppe A "'; text = 'Rand-Leerraum' }
        @{ name = 'nur Leerraum'; json = '"   "'; text = 'Rand-Leerraum' }
        @{ name = 'Stern-Wildcard'; json = '"Gruppe*"'; text = 'Wildcards' }
        @{ name = 'Fragezeichen-Wildcard'; json = '"Gruppe?"'; text = 'Wildcards' }
        @{ name = 'eckigen Klammern'; json = '"Gruppe [A]"'; text = 'Wildcards' }
        @{ name = 'einem Steuerzeichen'; json = '"Gruppe\tA"'; text = 'Steuerzeichen' }
    ) {
        $r = Read-ConfigWithDeployTo -DeployToJson $json
        $r.Config | Should -BeNullOrEmpty -Because 'ein ungueltiges DeployTo ist package_config_invalid, kein geratener Collection-Name'
        ($r.Logs -join "`n") | Should -Match $text
    }
}

Describe 'AR1 F-3: Get-VsDeployToState liest exakt und verschluckt keinen Providerfehler' {
    It 'liefert <expected> fuer <name>' -ForEach @(
        @{ name = 'kein DeployTo'; deployTo = ''; own = $true; found = @(); expected = 'none' }
        @{ name = 'die eigene Collection'; deployTo = 'Agent-1'; own = $true; found = @('Agent-1'); expected = 'self' }
        @{ name = 'die eigene Collection in anderer Schreibweise'; deployTo = 'AGENT-1'; own = $true; found = @('Agent-1'); expected = 'self' }
        @{ name = 'den eigenen Namen ohne eigene Collection'; deployTo = 'Agent-1'; own = $false; found = @('Agent-1'); expected = 'ready' }
        @{ name = 'eine fehlende Collection'; deployTo = 'Gruppe A'; own = $false; found = @(); expected = 'missing' }
        @{ name = 'zwei gleichnamige Collections'; deployTo = 'Gruppe A'; own = $true; found = @('Gruppe A', 'Gruppe A'); expected = 'ambiguous' }
        @{ name = 'eine vorhandene Collection'; deployTo = 'Gruppe A'; own = $true; found = @('Gruppe A'); expected = 'ready' }
        @{ name = 'einen Treffer mit anderem Namen'; deployTo = 'Gruppe A'; own = $true; found = @('Gruppe AB'); expected = 'missing' }
    ) {
        $state = Invoke-InFileScope -Path $script:MecmCommon -Arguments @($deployTo, $own, $found) -Body {
            param($d, $o, $f)
            Get-VsDeployToState -DeployTo $d -FullName 'Agent-1' -OwnCollection $o -Lookup {
                param($name) @($f | ForEach-Object { [pscustomobject]@{ Name = $_ } })
            }.GetNewClosure()
        }
        $state | Should -Be $expected
    }

    It 'fragt MECM nicht, wenn DeployTo leer ist oder die eigene Collection nennt' {
        $r = Invoke-InFileScope -Path $script:MecmCommon -Body {
            $box = [pscustomobject]@{ Calls = 0 }
            $lookup = { param($name) $box.Calls++; @() }.GetNewClosure()
            [void](Get-VsDeployToState -DeployTo '' -FullName 'Agent-1' -OwnCollection $true -Lookup $lookup)
            [void](Get-VsDeployToState -DeployTo 'Agent-1' -FullName 'Agent-1' -OwnCollection $true -Lookup $lookup)
            $box.Calls
        }
        $r | Should -Be 0
    }

    It 'reicht einen Providerfehler weiter, statt "fehlt" zu melden' {
        {
            Invoke-InFileScope -Path $script:MecmCommon -Body {
                Get-VsDeployToState -DeployTo 'Gruppe A' -FullName 'Agent-1' -Lookup { param($name) throw 'SMS Provider: Zeitlimit (synthetisch)' }
            }
        } | Should -Throw '*Zeitlimit*'
    }
}

Describe 'AR1 F-4: Test-VsCmProviderConnection fragt MECM statt den Fehlertext zu deuten' {
    It 'meldet <expected>, wenn Get-CMSite <name>' -ForEach @(
        @{ name = 'antwortet'; mode = 'ok'; expected = $true }
        @{ name = 'nichts liefert'; mode = 'empty'; expected = $false }
        @{ name = 'wirft'; mode = 'throw'; expected = $false }
    ) {
        $r = Invoke-InFileScope -Path $script:MecmCommon -Arguments @($mode) -Body {
            param($m)
            function Get-CMSite {
                param($SiteCode, $ErrorAction)
                if ($m -eq 'throw') { throw 'The RPC server is unavailable. (synthetisch)' }
                if ($m -eq 'empty') { return $null }
                [pscustomobject]@{ SiteCode = $SiteCode }
            }
            Test-VsCmProviderConnection -SiteCode 'XYZ'
        }
        $r | Should -Be $expected
    }

    It 'meldet ohne Site-Code keine Verbindung' {
        Invoke-InFileScope -Path $script:MecmCommon -Body { Test-VsCmProviderConnection -SiteCode '' } | Should -BeFalse
    }
}

Describe 'AR1 F-1: Wiederholliste mit Abstand' {
    It 'verdoppelt den Abstand voruebergehender Ursachen ab dem Takt bis zur Hoechstgrenze' {
        $r = Invoke-InFileScope -Path $script:MecmCommon -Body {
            [pscustomobject]@{
                Delays = @(1..8 | ForEach-Object { Get-VsImporterRetryDelaySeconds -Attempt $_ -IntervalSeconds 60 })
                MecmFix = Get-VsImporterRetryDelaySeconds -Attempt 1 -IntervalSeconds 60 -MecmFix $true
                Max = $script:VsImporterRetryMaxSeconds
            }
        }
        $r.Delays | Should -Be @(60, 120, 240, 480, 960, 1920, 3600, 3600)
        $r.MecmFix | Should -Be $r.Max
        $r.Max | Should -Be 3600
    }

    It 'traegt offene, dauerhafte und behobene Pakete ein' {
        $r = Invoke-InFileScope -Path $script:MecmCommon -Body {
            $set = New-VsImporterOpenPackageSet
            $now = [datetime]'2026-11-10T10:00:00'
            $states = @(
                Set-VsImporterOpenPackage -OpenPackages $set -Folder 'beta' -IntervalSeconds 60 -Now $now -Causes @('package_scan_failed target=Beta-1')
                Set-VsImporterOpenPackage -OpenPackages $set -Folder 'alpha' -IntervalSeconds 60 -Now $now -Causes @('package_deployto_missing target=Alpha-1 collection=Gruppe A')
                Set-VsImporterOpenPackage -OpenPackages $set -Folder 'broken' -IntervalSeconds 60 -Now $now -Causes @('package_config_invalid target=broken')
                Set-VsImporterOpenPackage -OpenPackages $set -Folder 'gamma' -IntervalSeconds 60 -Now $now -Causes @()
            )
            $second = Set-VsImporterOpenPackage -OpenPackages $set -Folder 'beta' -IntervalSeconds 60 -Now $now -Causes @('package_scan_failed target=Beta-1')
            [pscustomobject]@{
                States = $states; Second = $second
                BetaAttempts = $set['beta'].Attempts; BetaDue = $set['beta'].DueAt
                AlphaDue = $set['alpha'].DueAt; BrokenDue = $set['broken'].DueAt
                DueSoon = @((Get-VsImporterDueFolders -OpenPackages $set -Now $now.AddSeconds(150)) | ForEach-Object { $_ })
                DueLate = @((Get-VsImporterDueFolders -OpenPackages $set -Now $now.AddDays(1)) | ForEach-Object { $_ } | Sort-Object)
                Causes = @(Get-VsImporterOpenCauses -OpenPackages $set)
                Healed = Set-VsImporterOpenPackage -OpenPackages $set -Folder 'beta' -IntervalSeconds 60 -Now $now -Causes @()
                After = @($set.Keys | Sort-Object)
            }
        }
        $r.States | Should -Be @('open', 'open', 'open', 'clean')
        $r.Second | Should -Be 'open'
        $r.BetaAttempts | Should -Be 2
        $r.BetaDue | Should -Be ([datetime]'2026-11-10T10:02:00') -Because 'zweiter Versuch: doppelter Takt'
        $r.AlphaDue | Should -Be ([datetime]'2026-11-10T11:00:00') -Because 'eine fehlende Collection behebt nur MECM: Hoechstabstand, kein Rauschen'
        $r.BrokenDue | Should -Be ([datetime]::MaxValue) -Because 'eine ungueltige config.json behebt nur eine Dateiaenderung, und die loest ohnehin einen Vollscan aus'
        $r.DueSoon | Should -Be @('beta')
        $r.DueLate | Should -Be @('alpha', 'beta')
        $r.Causes | Should -Be @('package_deployto_missing target=Alpha-1 collection=Gruppe A', 'package_scan_failed target=Beta-1', 'package_config_invalid target=broken')
        $r.Healed | Should -Be 'healed'
        $r.After | Should -Be @('alpha', 'broken')
    }

    It 'eine Mischung aus dauerhafter und voruebergehender Ursache wird nach dem Takt wiederholt' {
        $due = Invoke-InFileScope -Path $script:MecmCommon -Body {
            $set = New-VsImporterOpenPackageSet
            $now = [datetime]'2026-11-10T10:00:00'
            [void](Set-VsImporterOpenPackage -OpenPackages $set -Folder 'alpha' -IntervalSeconds 60 -Now $now `
                -Causes @('package_deployto_invalid target=Alpha-1 collection=Alpha-1', 'package_content_unknown target=Alpha-1'))
            $set['alpha'].DueAt
        }
        $due | Should -Be ([datetime]'2026-11-10T10:01:00')
    }
}

Describe 'AR1 F-1: ein Hash je Datei je Lauf' {
    BeforeEach {
        $script:RunRoot = Join-Path $TestDrive ('files-' + [guid]::NewGuid().ToString('N'))
        $script:RunPkg = Join-Path $script:RunRoot 'PkgA'
        New-Item -ItemType Directory -Force -Path $script:RunPkg | Out-Null
        [IO.File]::WriteAllText((Join-Path $script:RunPkg 'setup.bin'), 'frisch kopiert')
    }

    It 'hasht eine gerade geschriebene Datei im selben Lauf nur einmal (<name>)' -ForEach @(
        @{ name = 'Lauf aktiv'; startRun = $true; changeTicks = 'real'; expected = 1 }
        @{ name = 'ohne Laufgrenze (AL1-Verhalten)'; startRun = $false; changeTicks = 'real'; expected = 2 }
        @{ name = 'ohne lesbare ChangeTime'; startRun = $true; changeTicks = 'none'; expected = 2 }
    ) {
        $r = Invoke-InFileScope -Path $script:MecmCommon -Arguments @($script:RunRoot, $script:RunPkg, $startRun, $changeTicks) -Body {
            param($root, $pkg, $start, $ticks)
            $calls = New-Object System.Collections.Generic.List[string]
            $originalHash = ${function:Get-VsStampFileHash}
            function Get-VsStampFileHash { param($Path, $Relative) $calls.Add([string]$Relative); & $originalHash -Path $Path -Relative $Relative }
            if ($ticks -eq 'none') { function Get-VsFileChangeTicks { param($Path) 0L } }
            if ($start) { Start-VsStampHashRun }
            $global = Get-VsFilesManifestStamp -Path $root
            $package = Get-VsFilesManifestStamp -Path $pkg
            [pscustomobject]@{ Calls = $calls.Count; Global = $global; Package = $package; Cached = $script:VsStampHashCache.Count }
        }
        $r.Calls | Should -Be $expected
        $r.Cached | Should -Be 0 -Because 'die Datei ist juenger als die Ruhezeit; der Laufspeicher ersetzt den Cache nicht'
    }

    It 'der naechste Lauf hasht die junge Datei wieder, und eine Aenderung im Lauf wird gesehen' {
        $r = Invoke-InFileScope -Path $script:MecmCommon -Arguments @($script:RunRoot, $script:RunPkg) -Body {
            param($root, $pkg)
            $calls = New-Object System.Collections.Generic.List[string]
            $originalHash = ${function:Get-VsStampFileHash}
            function Get-VsStampFileHash { param($Path, $Relative) $calls.Add([string]$Relative); & $originalHash -Path $Path -Relative $Relative }
            Start-VsStampHashRun
            $first = Get-VsFilesManifestStamp -Path $pkg
            Start-VsStampHashRun
            [void](Get-VsFilesManifestStamp -Path $pkg)
            $afterBoundary = $calls.Count
            # Gleiche Laenge, neuer Inhalt im selben Lauf: ChangeTime bewegt sich.
            [IO.File]::WriteAllText((Join-Path $pkg 'setup.bin'), 'frisch KOPIERT')
            $changed = Get-VsFilesManifestStamp -Path $pkg
            [pscustomobject]@{ AfterBoundary = $afterBoundary; Total = $calls.Count; Same = ($changed -eq $first) }
        }
        $r.AfterBoundary | Should -Be 2
        $r.Total | Should -Be 3
        $r.Same | Should -BeFalse
    }
}

Describe 'AR1 F-4: ein vergiftetes Paket stoppt die folgenden nicht' {
    It 'bearbeitet das Folgepaket, nennt das Paket und behaelt die MECM-Verbindung' {
        $box = New-ArBox -Packages @(
            (New-ArPackage -Folder 'alpha' -Product 'Alpha'),
            (New-ArPackage -Folder 'beta' -Product 'Beta'),
            (New-ArPackage -Folder 'gamma' -Product 'Gamma')
        )
        $box.Poison.Add('Beta')
        $r = Invoke-ArCycles -Box $box -Iterations 2

        $r.Reports[0].Outcome | Should -Be 'warning'
        $r.Reports[0].Category | Should -Be 'partial_failure' -Because 'MECM antwortet, also kein mecm_unavailable'
        $r.Reports[0].Detail | Should -Be 'package_scan_failed target=Beta-1'
        $r.Reports[0].Summary.open_points | Should -Be 1
        $r.Reports[0].Summary.folders | Should -Be 3
        $r.Calls | Should -Contain '1|app|Gamma-1' -Because 'das alphabetisch folgende Paket wird erreicht'
        $box.Inits | Should -Be 1 -Because 'kein Verbindungsreset bei einem Paketfehler'
        $r.Sleeps[0] | Should -Be 300 -Because 'keine Fehlerpause von 60 s'
        @($r.Logs | Where-Object { $_.Level -eq 'WARN' -and $_.Context -eq 'Beta-1' -and $_.Message -match 'Objekt nicht lesbar' }).Count | Should -Be 1
        $r.Reports[1].Summary.unchanged | Should -Be 1 -Because 'der Stamp ist gemerkt, der Folgelauf scannt nicht voll'
        $r.Reports[1].Outcome | Should -Be 'warning'
        $r.Reports[1].Detail | Should -Be 'package_scan_failed target=Beta-1'
    }

    It 'meldet mecm_unavailable und setzt die Verbindung zurueck, wenn MECM nicht mehr antwortet' {
        $box = New-ArBox -Packages @(
            (New-ArPackage -Folder 'alpha' -Product 'Alpha'),
            (New-ArPackage -Folder 'beta' -Product 'Beta'),
            (New-ArPackage -Folder 'gamma' -Product 'Gamma')
        )
        $box.Poison.Add('Beta')
        $box.ProviderDown = $true
        $r = Invoke-ArCycles -Box $box -Iterations 2

        $r.Reports[0].Outcome | Should -Be 'fail'
        $r.Reports[0].Category | Should -Be 'mecm_unavailable'
        $r.Calls | Should -Not -Contain '1|app|Gamma-1'
        $r.Sleeps[0] | Should -Be 60
        $box.Inits | Should -Be 2 -Because 'der naechste Lauf baut die Verbindung neu auf'
        $r.Open.Count | Should -Be 0 -Because 'ein abgebrochener Lauf traegt nichts in die Wiederholliste ein'
        $r.Calls | Should -Contain '2|app|Alpha-1' -Because 'der Dateistand wurde verworfen, der naechste Lauf scannt voll'
    }

    It 'wiederholt das Paket nach dem Takt allein und meldet es behoben' {
        $box = New-ArBox -Packages @(
            (New-ArPackage -Folder 'alpha' -Product 'Alpha'),
            (New-ArPackage -Folder 'beta' -Product 'Beta')
        )
        $box.Poison.Add('Beta')
        $between = {
            param($n)
            if ($n -eq 2) { $openPackages['beta'].DueAt = (Get-Date).AddSeconds(-1) }
            if ($n -eq 3) { $Box.Poison.Clear(); $openPackages['beta'].DueAt = (Get-Date).AddSeconds(-1) }
        }
        $r = Invoke-ArCycles -Box $box -Iterations 3 -Between $between

        $r.Reports[1].Detail | Should -Be 'package_scan_failed target=Beta-1'
        $r.Reports[1].Summary.unchanged | Should -Be 0
        $r.Reports[1].Summary.folders | Should -Be 1 -Because 'nur das faellige Paket wird bearbeitet'
        @($r.Calls | Where-Object { $_ -like '2|app|Alpha-1' }).Count | Should -Be 0
        @($r.Logs | Where-Object { $_.Iteration -eq 2 -and $_.Message -match 'Versuch 2, naechste Pruefung in 600 s' }).Count | Should -Be 1
        $r.Reports[2].Outcome | Should -Be 'ok'
        $r.Reports[2].Detail | Should -BeNullOrEmpty
        $r.Reports[2].Summary.open_points | Should -Be 0
        @($r.Logs | Where-Object { $_.Iteration -eq 3 -and $_.Context -eq 'Beta-1' -and $_.Message -match 'behoben' }).Count | Should -Be 1
    }
}

Describe 'AR1 F-3 im Lauf: DeployTo wird vor dem Deployment exakt geprueft' {
    It 'eine fehlende Collection ist eine eigene Ursache mit Name, ohne Deployment-Versuch und ohne Retry-Rauschen' {
        $box = New-ArBox -Packages @(
            (New-ArPackage -Folder 'alpha' -Product 'Alpha' -DeployTo 'Gruppe Test'),
            (New-ArPackage -Folder 'beta' -Product 'Beta')
        )
        $r = Invoke-ArCycles -Box $box -Iterations 3

        $r.Reports[0].Outcome | Should -Be 'warning'
        $r.Reports[0].Detail | Should -Be 'package_deployto_missing target=Alpha-1 collection=Gruppe Test'
        $r.Calls | Should -Contain '1|collection|Gruppe Test|Stop' -Because 'Get-CMCollection deckt Device- und User-Collections ab, ohne SilentlyContinue'
        @($r.Calls | Where-Object { $_ -like '*|deployment-new|Gruppe Test|*' }).Count | Should -Be 0
        foreach ($n in 2, 3) {
            $r.Reports[$n - 1].Summary.unchanged | Should -Be 1 -Because 'nicht faellig: kein Scan, keine MECM-Abfrage'
            $r.Reports[$n - 1].Outcome | Should -Be 'warning'
            $r.Reports[$n - 1].Detail | Should -Be 'package_deployto_missing target=Alpha-1 collection=Gruppe Test'
            $r.Reports[$n - 1].Summary.open_points | Should -Be 1
            @($r.Calls | Where-Object { $_ -like ('{0}|*' -f $n) }).Count | Should -Be 0
            @($r.Logs | Where-Object { $_.Iteration -eq $n -and $_.Level -eq 'WARN' }).Count | Should -Be 0
        }
        $r.Open['alpha'].DelaySeconds | Should -Be 3600
    }

    It 'legt das Deployment an, sobald die Collection existiert, und meldet den Punkt behoben' {
        $box = New-ArBox -Packages @(
            (New-ArPackage -Folder 'alpha' -Product 'Alpha' -DeployTo 'Gruppe Test'),
            (New-ArPackage -Folder 'beta' -Product 'Beta')
        )
        $between = { param($n) $Box.Collections.Add('Gruppe Test'); $openPackages['alpha'].DueAt = (Get-Date).AddSeconds(-1) }
        $r = Invoke-ArCycles -Box $box -Iterations 2 -Between $between

        $r.Calls | Should -Contain '2|deployment-get|Gruppe Test|Stop'
        $r.Calls | Should -Contain '2|deployment-new|Gruppe Test|Available'
        @($r.Calls | Where-Object { $_ -like '2|app|Beta-1' }).Count | Should -Be 0
        $r.Reports[1].Outcome | Should -Be 'ok'
        $r.Reports[1].Summary.open_points | Should -Be 0
    }

    It 'zwei gleichnamige Collections sind eine eigene Ursache' {
        $box = New-ArBox -Packages @((New-ArPackage -Folder 'alpha' -Product 'Alpha' -DeployTo 'Gruppe Test')) -Collections @('Gruppe Test', 'Gruppe Test')
        $r = Invoke-ArCycles -Box $box
        $r.Reports[0].Detail | Should -Be 'package_deployto_ambiguous target=Alpha-1 collection=Gruppe Test'
        @($r.Calls | Where-Object { $_ -like '*|deployment-new|Gruppe Test|*' }).Count | Should -Be 0
    }

    It 'DeployTo auf die eigene Collection ist eine ungueltige Konfiguration und wird nicht wiederholt (F-16)' {
        $box = New-ArBox -Packages @((New-ArPackage -Folder 'alpha' -Product 'Alpha' -Own $true -DeployTo 'Alpha-1'))
        $r = Invoke-ArCycles -Box $box
        $r.Reports[0].Detail | Should -Be 'package_deployto_invalid target=Alpha-1 collection=Alpha-1'
        $r.Calls | Should -Contain '1|deployment-new|Alpha-1|Required'
        @($r.Calls | Where-Object { $_ -like '*|deployment-new|Alpha-1|Available' }).Count | Should -Be 0
        @($r.Calls | Where-Object { $_ -like '*|collection|*' }).Count | Should -Be 0
        $r.Open['alpha'].DueAt | Should -Be ([datetime]::MaxValue)
    }

    It 'ein Providerfehler der Existenzpruefung ist voruebergehend, nicht package_deploy_failed' {
        $box = New-ArBox -Packages @(
            (New-ArPackage -Folder 'alpha' -Product 'Alpha' -DeployTo 'Gruppe Test'),
            (New-ArPackage -Folder 'beta' -Product 'Beta')
        ) -Collections @('Gruppe Test')
        $box.CollectionLookupThrows = $true
        $r = Invoke-ArCycles -Box $box
        $r.Reports[0].Detail | Should -Be 'package_scan_failed target=Alpha-1'
        $r.Reports[0].Detail | Should -Not -Match 'package_deploy_failed'
        $r.Calls | Should -Contain '1|app|Beta-1'
        $r.Open['alpha'].DelaySeconds | Should -Be 300
    }
}

Describe 'AR1 F-1 im Lauf: Vollscan, Wiederholung und verschwundene Ordner' {
    It 'eine ungueltige config.json bleibt gemeldet, wird im Wiederholungslauf nicht neu gelesen und faellt nach dem Loeschen weg' {
        $box = New-ArBox -Packages @(
            (New-ArPackage -Folder 'alpha' -Product 'Alpha'),
            (New-ArPackage -Folder 'broken' -Product 'Broken' -Invalid $true)
        )
        $box.Poison.Add('Alpha')
        $between = {
            param($n)
            if ($n -eq 2) { $openPackages['alpha'].DueAt = (Get-Date).AddSeconds(-1) }
            if ($n -eq 3) { $Box.Packages.RemoveAt(1); $Box.Poison.Clear(); $Box.Stamp = 'stamp-2' }
        }
        $r = Invoke-ArCycles -Box $box -Iterations 3 -Between $between

        $r.Reports[0].Detail | Should -Be 'package_scan_failed target=Alpha-1; package_config_invalid target=broken'
        $r.Reports[0].Summary.open_points | Should -Be 2
        $r.Calls | Should -Not -Contain '2|config|broken' -Because 'unveraenderte ungueltige config.json wird nicht je Lauf neu gelesen'
        $r.Reports[1].Detail | Should -Match 'package_config_invalid target=broken'
        $r.Reports[2].Outcome | Should -Be 'ok' -Because 'der Vollscan streicht den Eintrag des geloeschten Ordners'
        $r.Open.Count | Should -Be 0
    }
}
