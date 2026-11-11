#Requires -Version 5.1
# ============================================================================
# mecm_autoimporter.ps1 - erzeugt aus config.json-Paketordnern automatisch
# MECM-Applications, Collections und Deployments.
# Laeuft als geplante Aufgabe "VirtuSphere MECM Package Import".
#
# Quelle: <PackagesRoot>\files\<Paket>\config.json
# Content: <PackagesShare>\<Paket>  (UNC, aus Registry)
#
# Haertung/Optimierung gegenueber v1.8.2:
#  - Konfiguration aus Registry (keine harten Pfade/Site-Codes/UNCs)
#  - Wildcard-Fix: Alt-Versions-Bereinigung matcht exakt '^Name-<Version>$'
#    statt 'Name*' (frueher loeschte ein 'Firefox'-Update auch 'Firefox-ESR-*')
#  - config.json-Pflichtfeldpruefung (ProjectName, version) - fehlerhafte
#    Ordner werden uebersprungen statt Apps wie '-1.0' zu erzeugen
#  - Change-Detection ueber SHA-256-Manifest des files-Verzeichnisses -
#    Voll-Scan nur bei Inhaltsaenderung, sonst Millisekunden-Leerlauf
#  - LogonRequirementType korrigiert (WhetherOrNotUserLoggedOn)
#  - kein Clear-Host (laeuft ohne Konsole), Ergebnisbericht (reportRun) je Durchlauf
#
# Hinweis: Der verwaltete Satz aus <PackagesRoot>\Package_Vorlage (install.ps1
# plus versioniertes reporting-Buendel) heilt sich bei jedem Scan selbst. Die
# paketeigene config.json und powershell-Nutzlast werden nie ueberschrieben.
# ============================================================================

$script:VsRequiredMecmServerContractVersion = 4
. "$PSScriptRoot\VirtuSphere-Common.ps1"
$loadedMecmServerContract = Get-Variable -Name VsMecmServerContractVersion -Scope Script -ErrorAction SilentlyContinue
if (-not $loadedMecmServerContract -or [int]$loadedMecmServerContract.Value -ne $script:VsRequiredMecmServerContractVersion) {
    $loadedVersion = if ($loadedMecmServerContract) { [string]$loadedMecmServerContract.Value } else { 'nicht deklariert' }
    throw ("MECM-Serverpaket inkompatibel: Autoimporter benoetigt Common-Vertrag {0}, geladen ist {1}. Den vollstaendigen Ordner 'Powershell-MECM' bereitstellen und install-VirtuSphere-MECM.ps1 -Upgrade ausfuehren; keine einzelne Skriptdatei kopieren." -f $script:VsRequiredMecmServerContractVersion, $loadedVersion)
}

$config = Get-VsConfig
if (-not $config) {
    # Auch ohne Registry-Konfiguration ins Dateilog schreiben (Default-LogRoot),
    # damit der Fehler bei einem SYSTEM-Task ohne Konsole sichtbar bleibt.
    Initialize-VsLog -Component 'autoimporter'
    Write-VsLog -Level ERROR -Message 'Registry-Konfiguration fehlt (HKLM:\SOFTWARE\VirtuSphere\MECM). install-VirtuSphere-MECM.ps1 ausfuehren - warte auf Konfiguration.'
    # Selbstheilung statt exit 1: die 3 Taskplaner-Neustarts waeren nach
    # wenigen Minuten aufgebraucht, danach bliebe der Task bis zum Reboot tot.
    while (-not $config) {
        Start-Sleep -Seconds 60
        $config = Get-VsConfig
    }
    Write-VsLog -Message 'Registry-Konfiguration gefunden - starte.'
}
Initialize-VsLog -Component 'autoimporter' -LogRoot $config.LogRoot
# TLS 1.2 und, bei Scheme=https mit hinterlegtem Fingerabdruck, das Pinning. Muss
# in JEDEM Aufgabenprozess passieren: der Installer setzte es nur in seinem.
Initialize-VsTls -Config $config
Write-VsLog -Message '=== Autoimporter gestartet ==='

if ([string]::IsNullOrWhiteSpace($config.PackagesShare)) {
    Write-VsLog -Level ERROR -Message 'PackagesShare (UNC ContentLocation) fehlt in der Registry - warte auf Installer-Lauf.'
    while ([string]::IsNullOrWhiteSpace($config.PackagesShare)) {
        Start-Sleep -Seconds 60
        $reread = Get-VsConfig
        if ($reread) { $config = $reread }
    }
    Write-VsLog -Message 'PackagesShare gefunden - starte.'
}

$basePath = Join-Path $config.PackagesRoot 'files'
$templatePath = Join-Path $config.PackagesRoot 'Package_Vorlage'
$networkPath = $config.PackagesShare
$appFolderName = $script:VsApplicationsFolderName   # SSoT in VirtuSphere-Common.ps1
$dpGroupName = $config.DpGroupName
$intervalSeconds = Resolve-VsInterval -Source 'autoimporter' -Configured $config.ImporterInterval

# Skript-Version fuer den Run-Report (script_version, <=32 Zeichen).
$SCRIPT_VERSION = 'autoimporter/2.4'
# Build und Dateisatz aus manifest.json des Installers an die Kennung haengen
# ("+<Commit>", "-abw" bei abweichender Datei); schreibt eine Logzeile.
$SCRIPT_VERSION = Resolve-VsReportedScriptVersion -BaseVersion $SCRIPT_VERSION -InstallDir $PSScriptRoot
if (Test-VsLegacyPackageTemplate -TemplateRoot $templatePath) {
    Write-VsLog -Message ("Paketvorlage {0} ohne Reporter-Satz: Uebergangsmodus bis zum Cutover, nur install.ps1 wird in die Pakete abgeglichen." -f $templatePath)
}

$siteCode = $null
$providerMachine = $null
$lastFilesStamp = ''
$loop = 0
# Applications, deren Verschub in den Ordner fehlschlug (PS-09, AV-F28). Der
# naechste Durchlauf wiederholt ihn; prozessintern, ein Neustart verwirft die Liste.
$pendingAppFolderMoves = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::Ordinal)
# Verteilhinweise des letzten Vollscans (AV2): ruhige Laeufe melden sie weiter und
# lesen sie hoechstens alle $script:VsDistributionHintRefreshSeconds nur neu.
# Prozessintern; nach einem Neustart baut der erste Vollscan sie neu auf.
$rememberedDistributionHints = New-Object 'System.Collections.Generic.Dictionary[string,string]' ([StringComparer]::Ordinal)
$lastHintRefresh = Get-Date
# Wiederholliste offener Punkte je Paketordner (AR1, F-1): Ursachen, Versuch und
# naechster Termin. Prozessintern; nach einem Neustart scannt der erste Lauf voll.
$openPackages = New-VsImporterOpenPackageSet

# Read-VsPackageConfig und Get-VsSupersededNamePattern liegen in
# VirtuSphere-Common.ps1: dieses Skript ist eine Endlosschleife, was in ihm steht
# kann kein Test aufrufen, ohne sie zu starten. Dort deckt Pester beide ab.

while ($true) {
    $loop++
    $cycleStart = Get-Date
    # Neuer Lauf: run_id minten, Start melden, Abschluss im finally garantieren.
    $runId = New-VsRunId
    Send-VsRunReport -Config $config -Source 'autoimporter' -RunEvent 'started' -RunId $runId -IntervalSeconds $intervalSeconds -ScriptVersion $SCRIPT_VERSION

    $outcome = 'ok'
    $category = $null
    $detail = $null
    $folders = 0
    $newCount = 0
    $deletedCount = 0
    $scanWarnings = 0   # offene Punkte, die DIESER Lauf gefunden hat
    $openPointCount = 0 # alle offenen Punkte der Wiederholliste: das meldet der Lauf
    $unchanged = 0
    $sleepSeconds = $intervalSeconds
    # Ursachen dieses Laufs mit Paket- und Ordnernamen; ohne sie zeigte die Karte
    # "offene Punkte: 3" ohne ein einziges Paket zu nennen.
    $causes = New-VsRunCauseList
    # Verteilhinweise (AV2) stehen hinter den offenen Punkten, damit die Kuerzung
    # des Details nie einen echten Befund verdraengt. Sie zaehlen nicht als
    # offener Punkt und halten den Stamp nicht zurueck.
    $hintCauses = New-VsRunCauseList
    $distributionHints = New-Object 'System.Collections.Generic.Dictionary[string,string]' ([StringComparer]::Ordinal)

    try {
        if (-not $siteCode) {
            $siteCode = Initialize-VsCmSite -Config $config
            if (-not $siteCode) { throw 'MECM-Site nicht initialisierbar.' }
            $providerMachine = Get-VsProviderMachine -Config $config -ProviderMachine $config.ProviderMachine
            if ([string]::IsNullOrWhiteSpace($providerMachine)) { throw 'SMS-Provider-Rechner nicht eindeutig aufloesbar.' }
            Write-VsLog -Message ("Site-Drive {0} aktiv" -f $siteCode)

            # Self-Healing: die VirtuSphere_Applications-Ordner (Applications
            # und Device Collections) anlegen, falls sie fehlen. Der Packages-
            # Sync liest den Collections-Ordner als Katalogquelle; ohne ihn
            # wuerde dessen Sende-Guard dauerhaft warnen.
            # Get-/New-CMFolder erwarten laut MS-Doku RELATIVE Pfade ohne
            # Site-Drive-Praefix (anders als Move-CMObject); MECM >= 2111.
            foreach ($parentNode in @('Application', 'DeviceCollection')) {
                $fp = "{0}\{1}" -f $parentNode, $appFolderName
                if (-not (Get-CMFolder -FolderPath $fp -ErrorAction SilentlyContinue)) {
                    try {
                        New-CMFolder -Name $appFolderName -ParentFolderPath $parentNode -ErrorAction Stop | Out-Null
                        Write-VsLog -Context $fp -Message 'Ordner angelegt (Self-Healing).'
                    } catch {
                        # throw -> aeusserer catch setzt $siteCode zurueck,
                        # der naechste Durchlauf wiederholt die Pruefung.
                        throw ("Ordner '{0}' konnte nicht angelegt werden: {1}" -f $fp, $_.Exception.Message)
                    }
                }
            }
        }

        # Change-Detection: nur bei geaendertem files-Baum voll scannen. Das
        # Der vollstaendige verwaltete Vorlagensatz zaehlt mit (B7/T3): eine
        # Aenderung an Common, Logging, Adapter, Host, Manifest, Deskriptor oder
        # Wrapper muss den Abgleich ausloesen, nicht erst die naechste config.json.
        # Ein Hash je Datei je Lauf (AR1): Gesamtstamp und Paketstamps teilen sich
        # den AL1-Cache und, fuer gerade geschriebene Dateien, den Laufspeicher.
        Start-VsStampHashRun
        $stamp = Get-VsFilesManifestStamp -Path $basePath -TemplatePath $templatePath
        # Offene Punkte halten den Dateistand nicht mehr zurueck (AR1, F-1): ein
        # faelliger Eintrag der Wiederholliste wird allein erneut bearbeitet.
        $dueFolders = Get-VsImporterDueFolders -OpenPackages $openPackages
        $reportOpenPoints = $false
        if ($stamp -eq $lastFilesStamp -and $dueFolders.Count -eq 0) {
            # Unveraendert und nichts faellig: ein No-op-Lauf. Offene Punkte und
            # Verteilhinweise des letzten Scans bleiben sichtbar; der Verteilstand
            # wird nur gelesen.
            $unchanged = 1
            $reportOpenPoints = $true
            if ($rememberedDistributionHints.Count -gt 0 -and
                ((Get-Date) - $lastHintRefresh).TotalSeconds -ge $script:VsDistributionHintRefreshSeconds) {
                $rememberedDistributionHints = Update-VsDistributionHints -Hints $rememberedDistributionHints
                $lastHintRefresh = Get-Date
            }
        } elseif (-not (Test-Path $basePath)) {
            # Stamp NICHT merken. Er wurde gemerkt, also war der naechste Lauf
            # "unveraendert" und meldete ok: ein fehlender Paketpfad war damit ab
            # dem zweiten Durchlauf unsichtbar, obwohl nichts behoben war und
            # nichts mehr importiert wurde. Ein Zustand, den niemand geheilt hat,
            # muss weiter gemeldet werden.
            Write-VsLog -Level WARN -Message ("Paket-Pfad nicht gefunden: {0}" -f $basePath)
            $outcome = 'warning'
            $category = 'source_missing'
            Add-VsRunCause -Causes $causes -Cause 'package_source_missing' -Target $basePath
        } else {
            # Vollscan bei geaendertem Dateistand, sonst Wiederholung nur der
            # faelligen Pakete. Beide lesen alle config.json, weil die Auswahl der
            # hoechsten Version je Produkt (removeOldVersion) den ganzen Stand braucht.
            $fullScan = $stamp -ne $lastFilesStamp
            $reportOpenPoints = $true
            if ($fullScan) {
                Write-VsLog -Message ("Aenderung erkannt - Scan #{0}." -f $loop)
            } else {
                Write-VsLog -Message ("Wiederholung offener Punkte - Scan #{0} fuer {1} Paket(e): {2}." -f $loop, $dueFolders.Count, (($dueFolders | Sort-Object) -join ', '))
            }

            $appOrgFolder = "{0}:\Application\{1}" -f $siteCode, $appFolderName
            $collectionOrgFolder = "{0}:\DeviceCollection\{1}" -f $siteCode, $appFolderName
            $seenFolders = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::Ordinal)
            # Ein Wiederholungslauf behaelt die Verteilhinweise der nicht
            # bearbeiteten Pakete; jedes bearbeitete Paket ersetzt seinen eigenen.
            if (-not $fullScan) {
                foreach ($hintName in @($rememberedDistributionHints.Keys)) { $distributionHints[$hintName] = $rememberedDistributionHints[$hintName] }
            }

            # A14b: erst den gesamten Quellenstand lesen, dann pro Produkt genau
            # einen Zielstand bestimmen. So koennen zwei gleichzeitig gelieferte
            # Versionen einander niemals im selben Scan als "alt" behandeln.
            $packageEntries = New-Object System.Collections.Generic.List[object]
            foreach ($dir in @(Get-ChildItem -Path $basePath -Directory)) {
                [void]$seenFolders.Add($dir.Name)
                $knownOpen = $null
                if (-not $fullScan -and $openPackages.TryGetValue($dir.Name, [ref]$knownOpen) -and
                    @($knownOpen.Causes | Where-Object { (Get-VsRunCauseCode -Cause $_) -ne 'package_config_invalid' }).Count -eq 0) {
                    # Unveraenderte ungueltige config.json: im Wiederholungslauf nicht
                    # erneut lesen (sonst je Lauf eine WARN-Zeile); der Eintrag bleibt.
                    continue
                }
                $cfg = Read-VsPackageConfig -Folder $dir.FullName
                if (-not $cfg) {
                    # Ein unlesbares oder unvollstaendiges config.json loggte WARN
                    # und erhoehte keinen Zaehler: der Lauf blieb ok, der Stamp
                    # wurde gemerkt, und der Ordner wurde nie wieder gescannt. Ein
                    # Paket, das so nie entsteht, ist kein gelungener Lauf. Es steht
                    # in der Wiederholliste, bis eine Dateiaenderung es behebt.
                    $configCauseStart = $causes.Count
                    $scanWarnings++
                    Add-VsRunCause -Causes $causes -Cause 'package_config_invalid' -Target $dir.Name
                    [void](Set-VsImporterOpenPackage -OpenPackages $openPackages -Folder $dir.Name -IntervalSeconds $intervalSeconds `
                        -Causes (Get-VsRunCausesSince -Causes $causes -Start $configCauseStart))
                    continue
                }
                $packageEntries.Add([pscustomobject]@{ Directory = $dir; Config = $cfg })
            }
            $sourceSelections = @(Get-VsPackageSourceSelections -Packages @($packageEntries | ForEach-Object { $_.Config }))
            $cleanupRequestedProducts = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::Ordinal)
            foreach ($packageEntry in $packageEntries.ToArray()) {
                if ("$($packageEntry.Config.removeOldVersion)" -eq 'true') {
                    [void]$cleanupRequestedProducts.Add([string]$packageEntry.Config.ProjectName)
                }
            }
            $cleanupProcessedProducts = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::Ordinal)

            # List[object] nicht ueber @($list) materialisieren: der
            # PSEnumerableBinder von Windows PowerShell 5.1 wirft dabei
            # "Die Argumenttypen stimmen nicht ueberein".
            foreach ($entry in $packageEntries.ToArray()) {
                $dir = $entry.Directory
                $cfg = $entry.Config
                if (-not $fullScan -and -not $dueFolders.Contains($dir.Name)) { continue }
                $folders++

                $appName = [string]$cfg.ProjectName
                $version = [string]$cfg.version
                $fullName = "{0}-{1}" -f $appName, $version
                $folderName = $cfg.FolderName
                [void]$distributionHints.Remove($fullName)
                $packageCauseStart = $causes.Count
                $packageAborted = $false

                # Je Paket ein eigener Fehlerrahmen (AR1, F-4): ein "vergiftetes"
                # Paket stoppte frueher mit -ErrorAction Stop den ganzen Scan, der
                # aeussere Catch meldete mecm_unavailable und baute die Verbindung
                # neu auf, obwohl MECM antwortete, und alle alphabetisch folgenden
                # Pakete blieben liegen. Der Catch unten fragt MECM selbst.
                try {

                    # --- Self-Healing des Vorlagensatzes (bei JEDEM Scan) ----------
                    #
                    # Nicht mehr nur bei $isNew: die Vorlage gewinnt laut Vertrag, aber der
                    # $isNew-Zweig gab ihr genau einen Versuch. Ein Fehlschlag dort war
                    # endgueltig, weil die Application danach existierte. Wiederholt wird,
                    # solange der Inhalt abweicht, und ein Fehlschlag ist ein offener Punkt.
                    $pkgFolder = Join-Path (Join-Path $config.PackagesRoot 'files') $folderName
                    $templateCurrent = Test-VsPackageTemplateSetCurrent -TemplateRoot $templatePath -PackageRoot $pkgFolder
                    if (-not $templateCurrent) {
                        try {
                            Sync-VsPackageTemplateSet -TemplateRoot $templatePath -PackageRoot $pkgFolder
                            $templateCurrent = $true
                            $templateKind = if (Test-VsLegacyPackageTemplate -TemplateRoot $templatePath) { 'Altvorlage (nur install.ps1)' } else { 'Vorlagensatz samt Reporter-Generation' }
                            Write-VsLog -Context $fullName -Message ("{0} uebernommen und per SHA-256 bestaetigt." -f $templateKind)
                        } catch {
                            Write-VsLog -Level WARN -Context $fullName -Message ("Vorlagensatz nicht vollstaendig publiziert - Wiederholung im naechsten Durchlauf: {0}" -f $_.Exception.Message)
                            $scanWarnings++
                            Add-VsRunCause -Causes $causes -Cause 'package_template_failed' -Target $fullName
                        }
                    }
                    if (-not $templateCurrent) {
                        # Insbesondere Template fehlt + Paketdatei fehlt: ohne das
                        # Installationsskript darf keine ausfuehrbare MECM-Definition
                        # angelegt oder aktualisiert werden.
                        continue
                    }

                    # --- Application anlegen (falls neu) ---------------------------
                    # Das Objekt wird behalten: der Verteilstatus unten adressiert per
                    # CI_ID statt -Name, wo es eines gibt (B7).
                    $appMatches = @(Get-CMApplication -Name $fullName -Fast -ErrorAction Stop)
                    if ($appMatches.Count -gt 1) {
                        Write-VsLog -Level WARN -Context $fullName -Message 'Applicationname ist mehrdeutig; keine Definition und kein Content werden veraendert.'
                        $scanWarnings++
                        Add-VsRunCause -Causes $causes -Cause 'package_definition_drift' -Target $fullName
                        continue
                    }
                    $app = if ($appMatches.Count -eq 1) { $appMatches[0] } else { $null }
                    $isNew = $null -eq $app
                    if ($isNew) {
                        Write-VsLog -Context $fullName -Message 'NEU: erstelle Application.'
                        New-CMApplication -Name $fullName -Description $script:VsManagedPackageApplicationMarker -ErrorAction Stop | Out-Null

                        $registryDetection = "SOFTWARE\VirtuSphere\Packages\{0}-{1}" -f $appName, $version
                        $dtParams = @{
                            ApplicationName      = $fullName
                            DeploymentTypeName   = "{0} Deployment" -f $fullName
                            ContentLocation      = (Join-Path $networkPath $folderName)
                            InstallCommand       = (Get-VsPowerShellCommandLine -ScriptPath 'install.ps1')
                            # Kein UninstallCommand. Der frueher hier stehende Wert
                            # 'cmd.exe /s' versprach eine Deinstallation, die es nicht
                            # gibt: /s wirkt nur hinter /c oder /k, beides fehlte, uebrig
                            # blieb eine Shell, die nichts liest und mit 0 endet - also
                            # eine erfolgreich gemeldete Deinstallation, die nichts
                            # entfernt hat. Ausgeloest wurde sie von nichts, weil dieses
                            # Skript ausschliesslich Install-Deployments anlegt und
                            # Altversionen aus MECM loescht statt sie zu deinstallieren.
                            # Ein Paket ist definiert als "fuehre diese Skripte aus", und
                            # dazu gibt es keine allgemeine Umkehrung; eine echte
                            # Deinstallation waere eine Funktion mit eigener Vorlage
                            # (uninstall.ps1), eigenem Vertrag und eigenen Tests.
                            EstimatedRuntimeMins = 10
                            RebootBehavior       = 'BasedOnExitCode'
                        }
                        if ($cfg.InstallationBehaviorType -eq 'InstallForUser') {
                            $clause = New-CMDetectionClauseRegistryKeyValue -Hive CurrentUser -KeyName $registryDetection -PropertyType String -ValueName 'Version' -ExpressionOperator IsEquals -ExpectedValue $version -Is64Bit -Value
                            $dtParams['AddDetectionClause'] = $clause
                            $dtParams['InstallationBehaviorType'] = 'InstallForUser'
                            $dtParams['LogonRequirementType'] = 'OnlyWhenUserLoggedOn'
                        } else {
                            $clause = New-CMDetectionClauseRegistryKeyValue -Hive LocalMachine -KeyName $registryDetection -PropertyType String -ValueName 'Version' -ExpressionOperator IsEquals -ExpectedValue $version -Is64Bit -Value
                            $dtParams['AddDetectionClause'] = $clause
                            $dtParams['InstallationBehaviorType'] = 'InstallForSystem'
                            # Korrigierter Enum-Wert (frueher 'WhereOrNotUserLoggedOn').
                            $dtParams['LogonRequirementType'] = 'WhetherOrNotUserLoggedOn'
                        }
                        try {
                            Add-CMScriptDeploymentType @dtParams -ErrorAction Stop | Out-Null
                        } catch {
                            # Ohne Deployment-Type ist die Application unbrauchbar, und der naechste
                            # Scan wuerde sie ueber den else-Zweig faelschlich als "vorhanden"
                            # behandeln und den Teilzustand nie heilen. Daher die unvollstaendige
                            # Application entfernen, damit sie beim naechsten Lauf neu entsteht.
                            # Der Originalfehler wird festgehalten: die Entfernung kann ihren
                            # eigenen Fehler werfen, und der Lauf meldet weiter den ersten.
                            $originalError = $_
                            Write-VsLog -Level WARN -Context $fullName -Message ('Deployment-Type-Erstellung fehlgeschlagen, entferne unvollstaendige Application fuer erneuten Versuch: {0}' -f $originalError.Exception.Message)
                            try {
                                Remove-CMApplication -Name $fullName -Force -ErrorAction Stop | Out-Null
                            } catch {
                                # Die unvollstaendige Application bleibt dann bestehen. Der
                                # Originalfehler wird unten unveraendert weitergereicht und
                                # beendet den Scan; der Zaehler haelt den Befund fest, falls
                                # der Abbruch spaeter nicht mehr den ganzen Lauf beendet.
                                Write-VsLog -Level WARN -Context $fullName -Message ('Entfernen der unvollstaendigen Application fehlgeschlagen - sie bleibt bestehen: {0}' -f $_.Exception.Message)
                                $scanWarnings++
                                Add-VsRunCause -Causes $causes -Cause 'package_cleanup_failed' -Target $fullName
                            }
                            throw $originalError
                        }

                        # New-CMApplication/DT can return before the provider's
                        # distribution projection is queryable. Re-read the exact
                        # current object and fail closed if identity is ambiguous.
                        $freshApps = @(Get-CMApplication -Name $fullName -Fast -ErrorAction Stop)
                        if ($freshApps.Count -ne 1) {
                            throw ("Application '{0}' nach Erstellung nicht eindeutig lesbar." -f $fullName)
                        }
                        $app = $freshApps[0]

                        # Der Verschub laeuft unten zusammen mit jeder Wiederholung. Ein
                        # SilentlyContinue hier verschluckte den Fehler ohne Zaehler (PS-09).
                        [void]$pendingAppFolderMoves.Add($fullName)
                        $newCount++
                    } else {
                        Write-Host ("  {0} bereits vorhanden" -f $fullName) -ForegroundColor DarkGray
                    }

                    $deploymentTypeName = '{0} Deployment' -f $fullName
                    $allDeploymentTypes = @(Get-CMDeploymentType -ApplicationName $fullName -ErrorAction Stop)
                    $deploymentTypes = @($allDeploymentTypes |
                        Where-Object { [string]$_.LocalizedDisplayName -eq $deploymentTypeName -or [string]$_.DeploymentTypeName -eq $deploymentTypeName })
                    if ($deploymentTypes.Count -ne 1 -or $allDeploymentTypes.Count -ne 1) {
                        Write-VsLog -Level WARN -Context $fullName -Message ("Deployment Type '{0}' fehlt, ist mehrdeutig oder ein fremder Deployment Type ist vorhanden; Contentversion wird nicht angefordert." -f $deploymentTypeName)
                        $scanWarnings++
                        Add-VsRunCause -Causes $causes -Cause 'package_definition_drift' -Target $fullName
                        continue
                    }
                    # -Fast laesst bei Get-CMApplication lazy Properties aus. Fuer
                    # ModelName und PackageID, die den Contentauftrag binden, wird
                    # das eindeutige Objekt deshalb bewusst vollstaendig neu gelesen.
                    $contentApplications = @(Get-CMApplication -Name $fullName -ErrorAction Stop)
                    if ($contentApplications.Count -ne 1) {
                        Write-VsLog -Level WARN -Context $fullName -Message 'Application ist fuer die Contentidentitaet nicht eindeutig vollstaendig lesbar.'
                        $scanWarnings++
                        Add-VsRunCause -Causes $causes -Cause 'package_content_unknown' -Target $fullName
                        continue
                    }
                    $app = $contentApplications[0]

                    # --- Verschub der Application in den Ordner (PS-09, AV-F28) ---
                    # Wie bei Collections: ein fehlgeschlagener Verschub wird im naechsten
                    # Durchlauf wiederholt und mit eigener Ursache gezaehlt. Bis dahin liegt
                    # die Application im Wurzelordner. Der Paket-Sync liest nur den
                    # Collection-Ordner, deshalb ist der Befund fuer den Katalog kosmetisch.
                    if ($pendingAppFolderMoves.Contains($fullName)) {
                        try {
                            $app | Move-CMObject -FolderPath $appOrgFolder -ErrorAction Stop | Out-Null
                            [void]$pendingAppFolderMoves.Remove($fullName)
                        } catch {
                            Write-VsLog -Level WARN -Context $fullName -Message ("Application nicht in '{0}' verschoben - Wiederholung im naechsten Durchlauf: {1}" -f $appOrgFolder, $_.Exception.Message)
                            $scanWarnings++
                            Add-VsRunCause -Causes $causes -Cause 'package_folder_failed' -Target $fullName
                        }
                    }

                    # --- Contentauftrag (AV2, ADR-0034 Amendment 7) ---------------
                    # Die Verteilung an die DPs ist Sache von MECM. Der Autoimporter
                    # fordert je neuem Manifest hoechstens einen Auftrag an
                    # (Erstverteilung an die DP-Gruppe oder neue Contentversion) und
                    # liest danach nur den Verteilstand. failed und in_progress sind
                    # Hinweise: sie halten weder Collection, Deployments, Bereinigung,
                    # Dateistand noch Ampel auf. Vor dem Aufruf steht ein Intent; ein
                    # nicht bestaetigter Aufruf wird nur wiederholt, wenn MECM ihn
                    # nachweislich nicht angenommen hat. Die Verteilung ist absichtlich
                    # NICHT an generateOwnDeviceColletion gekoppelt.
                    $packageManifest = Get-VsFilesManifestStamp -Path $pkgFolder
                    $snapshot = Get-VsContentDistributionSnapshot -ApplicationName $fullName -Application $app
                    $applicationIdentity = Get-VsApplicationContentIdentity -Application $app -ExpectedName $fullName
                    $deploymentTypeIdentity = if ($applicationIdentity.State -eq 'known') {
                        Get-VsDeploymentTypeContentIdentity -DeploymentType $deploymentTypes[0] -ExpectedName $deploymentTypeName `
                            -ExpectedApplicationModelName $applicationIdentity.ModelName
                    } else { [pscustomobject]@{ State = 'unknown'; DeploymentTypeModelName = ''; DeploymentTypeId = ''; ContentId = '' } }
                    $effectiveRequestConfirmed = $false
                    $requestedNow = $false
                    if ($applicationIdentity.State -ne 'known' -or $deploymentTypeIdentity.State -ne 'known') {
                        Write-VsLog -Level WARN -Context $fullName -Message 'Application-/Deployment-Type-Identitaet ist nicht eindeutig lesbar; kein Contentauftrag in diesem Lauf, Collection und Deployments werden weiter abgeglichen.'
                        $scanWarnings++
                        Add-VsRunCause -Causes $causes -Cause 'package_content_unknown' -Target $fullName
                    } else {
                        $contentRequest = Get-VsPackageContentRequest -ApplicationName $fullName
                        if ($contentRequest.Origin -eq 'migrated') {
                            Write-VsLog -Context $fullName -Message ("Altes Content-Tracking uebernommen (Zustand {0}); nur Manifest und Identitaet zaehlen, der alte Registryeintrag bleibt unveraendert." -f $contentRequest.State)
                        }
                        $decision = Get-VsContentRequestDecision -Request $contentRequest -Manifest $packageManifest `
                            -ApplicationModelName $applicationIdentity.ModelName -ApplicationPackageId $applicationIdentity.PackageId `
                            -DeploymentTypeContentId $deploymentTypeIdentity.ContentId `
                            -ApplicationOwned (Test-VsManagedPackageApplication -Application $app) `
                            -DistributionState ([string]$snapshot.State) -DpGroupName ([string]$dpGroupName)
                        $effectiveRequestConfirmed = [bool]$decision.Confirmed

                        if ($decision.Action -eq 'foreign') {
                            # Keine Uebernahme einer namensgleichen fremden Application
                            # (ADR-0034 Amendment 1): kein Content, kein Deployment.
                            Write-VsLog -Level WARN -Context $fullName -Message ("Application wurde neu angelegt und gehoert nicht zu VirtuSphere (kein Marker, nicht im Ordner '{0}'); kein Contentauftrag und kein Deployment." -f $appFolderName)
                            $scanWarnings++
                            Add-VsRunCause -Causes $causes -Cause 'package_definition_drift' -Target $fullName
                            continue
                        }
                        if ($decision.Action -eq 'blocked') {
                            $blockedText = switch ($decision.Reason) {
                                'dp_group_missing' { 'DP-Gruppe ist nicht konfiguriert; keine Erstverteilung.' }
                                'dp_group_empty' { ("DP-Gruppe '{0}' hat keine Mitglieder; der Inhalt hat kein Ziel, bis ein Verteilungspunkt in der Gruppe ist." -f $dpGroupName) }
                                'request_unreadable' { 'Contentauftrag in der Registry nicht lesbar; kein Auftrag in diesem Lauf.' }
                                default { 'Verteilstand nicht lesbar; ob Erst- oder Updateauftrag noetig ist, entscheidet der naechste Lauf.' }
                            }
                            Write-VsLog -Level WARN -Context $fullName -Message ("{0} Collection und Deployments werden weiter abgeglichen." -f $blockedText)
                            $scanWarnings++
                            Add-VsRunCause -Causes $causes -Cause $decision.Cause -Target $fullName
                        } elseif ($decision.Action -eq 'adopt') {
                            $adoptBaseline = if ($decision.Reason -eq 'intent_accepted') { [string]$contentRequest.BaselineContentId } else { [string]$deploymentTypeIdentity.ContentId }
                            try {
                                Set-VsPackageContentRequest -ApplicationName $fullName -State requested -Manifest $packageManifest `
                                    -RequestKind $decision.RequestKind -ApplicationModelName $applicationIdentity.ModelName `
                                    -ApplicationPackageId $applicationIdentity.PackageId -BaselineContentId $adoptBaseline
                                $adoptText = if ($decision.Reason -eq 'intent_accepted') { 'MECM hat den frueheren Contentauftrag angenommen' } else { 'Neu angelegte VirtuSphere-Application mit unveraendertem Inhalt uebernommen' }
                                Write-VsLog -Context $fullName -Message ("{0}; kein weiterer Auftrag." -f $adoptText)
                            } catch {
                                Write-VsLog -Level WARN -Context $fullName -Message ("Contentauftrag konnte nicht gespeichert werden; der naechste Lauf prueft erneut: {0}" -f $_.Exception.Message)
                                $scanWarnings++
                                Add-VsRunCause -Causes $causes -Cause 'package_content_unknown' -Target $fullName
                            }
                        } elseif ($decision.Action -eq 'request') {
                            try {
                                Set-VsPackageContentRequest -ApplicationName $fullName -State intent -Manifest $packageManifest `
                                    -RequestKind $decision.RequestKind -ApplicationModelName $applicationIdentity.ModelName `
                                    -ApplicationPackageId $applicationIdentity.PackageId -BaselineContentId $deploymentTypeIdentity.ContentId
                                if ($decision.RequestKind -eq 'initial') {
                                    Start-CMContentDistribution -ApplicationName $fullName -DistributionPointGroupName $dpGroupName -ErrorAction Stop | Out-Null
                                    Write-VsLog -Context $fullName -Message ("Erstverteilung an DP-Gruppe '{0}' angefordert; den Rest uebernimmt MECM." -f $dpGroupName)
                                } else {
                                    Update-CMDistributionPoint -ApplicationName $fullName -DeploymentTypeName $deploymentTypeName -ErrorAction Stop | Out-Null
                                    Write-VsLog -Context $fullName -Message ("Neue Contentversion fuer Deployment Type '{0}' angefordert (bisheriger Verteilstand {1}); den Rest uebernimmt MECM." -f $deploymentTypeName, $snapshot.State)
                                }
                                Confirm-VsPackageContentRequest -ApplicationName $fullName
                                $effectiveRequestConfirmed = $true
                                $requestedNow = $true
                            } catch {
                                Write-VsLog -Level WARN -Context $fullName -Message ("Contentauftrag nicht sicher angefordert oder bestaetigt; der naechste Lauf wiederholt ihn nur, wenn MECM ihn nicht angenommen hat: {0}" -f $_.Exception.Message)
                                $scanWarnings++
                                Add-VsRunCause -Causes $causes -Cause 'package_content_request_failed' -Target $fullName
                            }
                        }

                        # Verteilstand nur anzeigen: ein Hinweis, kein offener Punkt.
                        $distributionHint = Get-VsDistributionHintCause -DistributionState ([string]$snapshot.State) -RequestedNow $requestedNow
                        if ($distributionHint -ne '') {
                            Add-VsRunCause -Causes $hintCauses -Cause $distributionHint -Target $fullName
                            $distributionHints[$fullName] = $distributionHint
                            Write-VsLog -Context $fullName -Message ("Hinweis: Verteilstand {0} ({1} Ziele laut MECM); Collection und Deployments laufen weiter." -f $(if ($requestedNow) { 'angefordert' } else { $snapshot.State }), $snapshot.TargetCount)
                        }
                    }

                    # --- Collection + Deployments idempotent nachziehen -------------
                    # Laeuft auch fuer bestehende Apps und heilt damit fruehere
                    # Teilfehler (App vorhanden, aber Collection/Deployment fehlt).
                    if ("$($cfg.generateOwnDeviceColletion)" -eq 'true') {
                        # Die Abfrage laeuft mit -ErrorAction Stop und ohne lokalen Catch. Ein
                        # Providerfehler ist kein "keine Collection": frueher wurde nach dem
                        # stillen Fehler trotzdem angelegt, und der Lauf meldete
                        # collection_missing. Jetzt geht der Fehler in den Scan-Catch und wird
                        # dort als mecm_unavailable gemeldet (Phase scan; der Rohtext bleibt
                        # lokal im Log). Die Anlage hat einen eigenen Catch, dessen MECM-Text
                        # in der bestehenden WARN-Zeile steht.
                        $collection = Get-CMDeviceCollection -Name $fullName -ErrorAction Stop
                        if (-not $collection) {
                            # Jeder Fehlschlag zaehlt im Catch selbst: dort steht der MECM-Text,
                            # und der offene Punkt ist collection_missing. Ohne Collection gibt
                            # es nichts zu deployen.
                            $createFailed = $false
                            try {
                                New-CMDeviceCollection -Name $fullName -LimitingCollectionName 'All Systems' -Comment $script:VsManagedPackageCollectionMarker -ErrorAction Stop | Out-Null
                                Start-Sleep -Seconds 2
                                $collection = Get-CMDeviceCollection -Name $fullName -ErrorAction Stop
                            } catch {
                                $createFailed = $true
                                Write-VsLog -Level WARN -Context $fullName -Message ("Device-Collection '{0}' konnte nicht angelegt werden - Deployment uebersprungen, Wiederholung im naechsten Durchlauf: {1}" -f $fullName, $_.Exception.Message)
                                $scanWarnings++
                                Add-VsRunCause -Causes $causes -Cause 'collection_missing' -Target $fullName -Collection $fullName
                            }
                            if (-not $collection -and -not $createFailed) {
                                Write-VsLog -Level WARN -Context $fullName -Message ("Device-Collection '{0}' ist nach dem Anlegen nicht lesbar - Deployment uebersprungen, Wiederholung im naechsten Durchlauf." -f $fullName)
                                $scanWarnings++
                                Add-VsRunCause -Causes $causes -Cause 'collection_missing' -Target $fullName -Collection $fullName
                            }
                        }
                        # Das Verschieben wird WIEDERHOLT, nicht nur beim Anlegen versucht.
                        # Es lief unter SilentlyContinue genau einmal; scheiterte es, lag
                        # die Collection dauerhaft im Wurzelordner. Der Paket-Sync liest
                        # aber genau VirtuSphere_Applications als Katalogquelle, also fehlte
                        # das Paket dauerhaft im Portal, obwohl in MECM alles da war.
                        if ($collection -and -not (Test-VsInOrgFolder -Collection $collection -FolderPath $collectionOrgFolder)) {
                            try {
                                $collection | Move-CMObject -FolderPath $collectionOrgFolder -ErrorAction Stop | Out-Null
                            } catch {
                                Write-VsLog -Level WARN -Context $fullName -Message ("Collection nicht in '{0}' verschoben - Wiederholung im naechsten Durchlauf: {1}" -f $collectionOrgFolder, $_.Exception.Message)
                                $scanWarnings++
                                Add-VsRunCause -Causes $causes -Cause 'collection_folder_failed' -Collection $fullName
                            }
                        }

                        # Ohne Collection gibt es nichts zu deployen; der offene Punkt
                        # steht bereits als collection_missing. Die Abfrage verschluckt
                        # keinen Providerfehler mehr (AR1, F-3): "nicht lesbar" ist nicht
                        # "kein Deployment", und der Paket-Catch ordnet den Fehler zu.
                        if ($collection -and -not (Get-CMApplicationDeployment -Name $fullName -CollectionName $fullName -ErrorAction Stop)) {
                            try {
                                New-CMApplicationDeployment -Name $fullName -CollectionName $fullName -DeployAction Install -DeployPurpose Required -UserNotification DisplaySoftwareCenterOnly -ErrorAction Stop | Out-Null
                            } catch {
                                Write-VsLog -Level WARN -Context $fullName -Message ("Deployment fehlgeschlagen - Wiederholung im naechsten Durchlauf: {0}" -f $_.Exception.Message)
                                $scanWarnings++
                                Add-VsRunCause -Causes $causes -Cause 'package_deploy_failed' -Target $fullName
                            }
                        }
                    }

                    # --- Zusatz-Deployment (Available) -----------------------------
                    # Erst exakt pruefen, ob die DeployTo-Collection existiert (AR1, F-3):
                    # frueher scheiterte jede fehlende Collection als package_deploy_failed
                    # mit rohem Cmdlet-Text, und dauerhaft und voruebergehend waren nicht
                    # zu unterscheiden. Ein Providerfehler der Abfrage wirft in den
                    # Paket-Catch; die Syntax hat Read-VsPackageConfig geprueft.
                    $deployTo = [string]$cfg.DeployTo
                    $deployToState = Get-VsDeployToState -DeployTo $deployTo -FullName $fullName `
                        -OwnCollection ("$($cfg.generateOwnDeviceColletion)" -eq 'true') `
                        -Lookup { param($name) Get-CMCollection -Name $name -ErrorAction Stop }
                    if ($deployToState -eq 'self') {
                        # DeployTo auf die eigene Collection: dort steht schon das
                        # Required-Deployment, ein Available-Deployment kann es nicht
                        # geben. Frueher still uebersprungen (F-16).
                        Write-VsLog -Level WARN -Context $fullName -Message ("DeployTo nennt die eigene Collection '{0}' des Pakets; dort besteht schon das Deployment 'Erforderlich'. DeployTo leeren oder eine andere Collection nennen." -f $deployTo)
                        $scanWarnings++
                        Add-VsRunCause -Causes $causes -Cause 'package_deployto_invalid' -Target $fullName -Collection $deployTo
                    } elseif ($deployToState -eq 'missing') {
                        Write-VsLog -Level WARN -Context $fullName -Message ("DeployTo-Collection '{0}' existiert in MECM nicht (Device- oder User-Collection); kein Zusatz-Deployment. Collection anlegen oder DeployTo in config.json korrigieren; erneute Pruefung spaetestens nach {1} s oder sofort bei einer Dateiaenderung." -f $deployTo, $script:VsImporterRetryMaxSeconds)
                        $scanWarnings++
                        Add-VsRunCause -Causes $causes -Cause 'package_deployto_missing' -Target $fullName -Collection $deployTo
                    } elseif ($deployToState -eq 'ambiguous') {
                        Write-VsLog -Level WARN -Context $fullName -Message ("Mehrere Collections heissen '{0}'; kein Zusatz-Deployment, bis der Name in MECM eindeutig ist." -f $deployTo)
                        $scanWarnings++
                        Add-VsRunCause -Causes $causes -Cause 'package_deployto_ambiguous' -Target $fullName -Collection $deployTo
                    } elseif ($deployToState -eq 'ready' -and
                        -not (Get-CMApplicationDeployment -Name $fullName -CollectionName $deployTo -ErrorAction Stop)) {
                        try {
                            New-CMApplicationDeployment -Name $fullName -CollectionName $deployTo -DeployAction Install -DeployPurpose Available -UserNotification DisplaySoftwareCenterOnly -ErrorAction Stop | Out-Null
                        } catch {
                            # Zaehlt (B7): ohne Zaehler war der Fehlschlag ab dem zweiten
                            # Lauf unsichtbar. Die Collection existiert hier nachweislich;
                            # die Meldung nennt den MECM-Text.
                            Write-VsLog -Level WARN -Context $fullName -Message ("Zusatz-Deployment auf '{0}' fehlgeschlagen - Wiederholung mit Abstand: {1}" -f $deployTo, $_.Exception.Message)
                            $scanWarnings++
                            Add-VsRunCause -Causes $causes -Cause 'package_deploy_failed' -Target $fullName -Collection $deployTo
                        }
                    }

                    # --- removeOldVersion: belegten Ersatz automatisch bereinigen --
                    # Die JSON-Anforderung ist massgeblich, ausgefuehrt wird sie aber
                    # nur einmal am eindeutigen hoechsten Quellstand. Der Plan wird
                    # unmittelbar zweimal vollstaendig gelesen; nur ein identischer
                    # Hash darf Deployment, Application und Collection entfernen.
                    $selection = @($sourceSelections | Where-Object { [string]$_.ProductName -ceq $appName })
                    if ($cleanupRequestedProducts.Contains($appName) -and $selection.Count -eq 1 -and
                        [string]$selection[0].State -eq 'ready' -and [string]$selection[0].TargetName -ceq $fullName -and
                        $cleanupProcessedProducts.Add($appName)) {
                        $expectedDeploymentCollections = New-Object System.Collections.Generic.List[string]
                        if ("$($cfg.generateOwnDeviceColletion)" -eq 'true') { $expectedDeploymentCollections.Add($fullName) }
                        # DeployTo auf die eigene Collection zaehlt nicht doppelt; eine
                        # fehlende oder mehrdeutige DeployTo-Collection sperrt die
                        # Bereinigung wie zuvor, ohne sie abzufragen.
                        if ($deployToState -eq 'ready') { $expectedDeploymentCollections.Add($deployTo) }
                        $replacementDeploymentReady = $expectedDeploymentCollections.Count -gt 0 -and $deployToState -notin @('missing', 'ambiguous')
                        foreach ($expectedCollection in $expectedDeploymentCollections) {
                            if (@(Get-CMApplicationDeployment -Name $fullName -CollectionName $expectedCollection -ErrorAction Stop).Count -ne 1) {
                                $replacementDeploymentReady = $false
                            }
                        }
                        $replacement = [pscustomobject]@{
                            Name = $fullName
                            Owned = ([string]$app.LocalizedDescription -ceq $script:VsManagedPackageApplicationMarker)
                            DeploymentTypeCount = $deploymentTypes.Count
                            RequestConfirmed = [bool]$effectiveRequestConfirmed
                            ContentState = $(if ($effectiveRequestConfirmed) { 'requested' } else { 'none' })
                            DistributionState = [string]$snapshot.State
                            DeploymentReady = $replacementDeploymentReady
                        }
                        $readRetirementPlan = {
                            $applications = @(Get-CMApplication -Name ("{0}-*" -f $appName) -ErrorAction Stop)
                            $collections = @(Get-CMDeviceCollection -Name ("{0}-*" -f $appName) -ErrorAction Stop)
                            $allNames = @(
                                @($applications | ForEach-Object { [string]$_.LocalizedDisplayName }) +
                                @($collections | ForEach-Object { [string]$_.Name })
                            )
                            $retainedNames = @(Get-VsPackageRetainedNames -ProductName $appName `
                                -SourceVersions @($selection[0].SourceVersions) -Selections $selection -Names $allNames)
                            $retainedSet = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::Ordinal)
                            foreach ($retainedName in $retainedNames) { [void]$retainedSet.Add([string]$retainedName) }
                            $deployments = New-Object System.Collections.Generic.List[object]
                            $references = New-Object System.Collections.Generic.List[object]
                            $deploymentIds = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::Ordinal)

                            foreach ($candidate in @($applications | Where-Object { $retainedSet.Contains([string]$_.LocalizedDisplayName) })) {
                                $candidateName = [string]$candidate.LocalizedDisplayName
                                foreach ($property in @('CI_ID', 'NumberOfDeployments', 'NumberOfDependentDTs', 'NumberOfDependentTS', 'NumberOfVirtualEnvironments')) {
                                    if (-not $candidate.PSObject.Properties[$property]) { throw ("Application-Referenzfeld fehlt: {0}.{1}" -f $candidateName, $property) }
                                }
                                if ([int]$candidate.NumberOfDependentDTs -gt 0 -or [int]$candidate.NumberOfDependentTS -gt 0 -or
                                    [int]$candidate.NumberOfVirtualEnvironments -gt 0) {
                                    $references.Add([pscustomobject]@{ TargetType = 'application'; TargetId = [string]$candidate.CI_ID })
                                }
                                $candidateDeployments = @(Get-CMApplicationDeployment -Name $candidateName -ErrorAction Stop)
                                if ($candidateDeployments.Count -ne [int]$candidate.NumberOfDeployments) {
                                    throw ("Application-Deploymentinventar ist unvollstaendig: {0}" -f $candidateName)
                                }
                                foreach ($deployment in $candidateDeployments) {
                                    $deploymentId = [string]$deployment.AssignmentUniqueID
                                    if ([string]::IsNullOrWhiteSpace($deploymentId)) { $deploymentId = [string]$deployment.DeploymentID }
                                    $collectionName = [string]$deployment.CollectionName
                                    if ([string]::IsNullOrWhiteSpace($deploymentId) -or [string]::IsNullOrWhiteSpace($collectionName) -or
                                        -not $deploymentIds.Add($deploymentId)) {
                                        throw ("Application-Deploymentidentitaet ist unvollstaendig oder doppelt: {0}" -f $candidateName)
                                    }
                                    $deployments.Add([pscustomobject]@{
                                        ApplicationName = $candidateName; DeploymentId = $deploymentId; CollectionName = $collectionName
                                    })
                                }
                            }

                            foreach ($candidateCollection in @($collections | Where-Object { $retainedSet.Contains([string]$_.Name) })) {
                                $candidateCollectionId = [string]$candidateCollection.CollectionID
                                if ($candidateCollectionId -cnotmatch '^[A-Za-z0-9]{8}$') {
                                    throw ("Collection-ID ist ungueltig: {0}" -f $candidateCollection.Name)
                                }
                                $dependencyParams = @{
                                    Namespace = ('root\SMS\site_{0}' -f $siteCode)
                                    ClassName = 'SMS_CollectionDependencies'
                                    Filter = ("SourceCollectionID = '{0}'" -f $candidateCollectionId)
                                    ErrorAction = 'Stop'
                                }
                                if (-not [string]::IsNullOrWhiteSpace($providerMachine) -and $providerMachine -ne $env:COMPUTERNAME) {
                                    $dependencyParams['ComputerName'] = $providerMachine
                                }
                                if (@(Get-CimInstance @dependencyParams).Count -gt 0) {
                                    $references.Add([pscustomobject]@{ TargetType = 'collection'; TargetId = $candidateCollectionId })
                                }
                                foreach ($deployment in @(Get-CMDeployment -CollectionName ([string]$candidateCollection.Name) -ErrorAction Stop)) {
                                    $deploymentId = [string]$deployment.AssignmentUniqueID
                                    if ([string]::IsNullOrWhiteSpace($deploymentId)) { $deploymentId = [string]$deployment.DeploymentID }
                                    if ([string]::IsNullOrWhiteSpace($deploymentId) -or -not $deploymentIds.Contains($deploymentId)) {
                                        $references.Add([pscustomobject]@{ TargetType = 'collection'; TargetId = $candidateCollectionId })
                                    }
                                }
                            }
                            Get-VsPackageRetirementPlan -Selection $selection[0] -Applications $applications -Collections $collections `
                                -Deployments $deployments.ToArray() -Replacement $replacement -References $references.ToArray() `
                                -ReferenceScanComplete $true
                        }

                        try {
                            $approvedPlan = & $readRetirementPlan
                            $onlyReplacementBlockerWithoutCandidate = @($approvedPlan.Items).Count -eq 0 -and
                                @($approvedPlan.Blockers).Count -eq 1 -and
                                [string]$approvedPlan.Blockers[0] -eq 'replacement_not_ready'
                            if ([string]$approvedPlan.State -ne 'ready' -and -not $onlyReplacementBlockerWithoutCandidate) {
                                Write-VsLog -Level WARN -Context $appName -Message ("Automatische Altversionsbereinigung ist noch gesperrt: {0}" -f (@($approvedPlan.Blockers) -join ', '))
                                $scanWarnings++
                                Add-VsRunCause -Causes $causes -Cause 'package_cleanup_failed' -Target $appName
                            } elseif (@($approvedPlan.Items).Count -gt 0) {
                                $currentPlan = & $readRetirementPlan
                                $cleanupResults = @(Invoke-VsPackageRetirementPlan -ApprovedPlan $approvedPlan -CurrentPlan $currentPlan `
                                    -RemoveDeployment { param($item) Remove-CMApplicationDeployment -DeploymentId $item.Id -Force -ErrorAction Stop | Out-Null } `
                                    -RemoveApplication { param($item) Remove-VsPackageContentTracking -ApplicationName $item.Name; Remove-CMApplication -Id ([int]$item.Id) -Force -ErrorAction Stop | Out-Null } `
                                    -RemoveCollection { param($item) Remove-CMCollection -Id $item.Id -Force -ErrorAction Stop | Out-Null })
                                foreach ($cleanupResult in $cleanupResults) {
                                    if ([string]$cleanupResult.State -eq 'removed') {
                                        Write-VsLog -Context $cleanupResult.Name -Message ("Altes MECM-{0} automatisch entfernt; removeOldVersion=true und Ersatz {1} sind belegt." -f $cleanupResult.Kind, $fullName)
                                        if ([string]$cleanupResult.Kind -eq 'application') { $deletedCount++ }
                                    } else {
                                        Write-VsLog -Level WARN -Context $cleanupResult.Name -Message ("Automatische Altversionsbereinigung fehlgeschlagen: {0}" -f $cleanupResult.Error)
                                        $scanWarnings++
                                        Add-VsRunCause -Causes $causes -Cause 'package_cleanup_failed' -Target $cleanupResult.Name
                                    }
                                }
                            }
                        } catch {
                            Write-VsLog -Level WARN -Context $appName -Message ("Automatische Altversionsbereinigung konnte nicht sicher geplant/revalidiert werden; Content und neues Deployment bleiben bestehen: {0}" -f $_.Exception.Message)
                            $scanWarnings++
                            Add-VsRunCause -Causes $causes -Cause 'package_cleanup_failed' -Target $appName
                        }
                    } elseif ($cleanupRequestedProducts.Contains($appName) -and $selection.Count -ne 1) {
                        $scanWarnings++
                        Add-VsRunCause -Causes $causes -Cause 'package_cleanup_failed' -Target $appName
                    }

                } catch {
                    # Ein markierter Quellzustand (source_changing) bleibt Sache des
                    # aeusseren Catch, ebenso ein Fehler, nach dem MECM nicht mehr
                    # antwortet: nur dann Verbindungsreset und mecm_unavailable.
                    # Antwortet MECM, scheiterte nur dieses Paket; die folgenden laufen.
                    if ((Get-VsExceptionRunCategory -ErrorRecord $_) -or -not (Test-VsCmProviderConnection -SiteCode $siteCode)) {
                        $packageAborted = $true
                        throw
                    }
                    Write-VsLog -Level WARN -Context $fullName -Message ("Paket nicht vollstaendig verarbeitet, MECM antwortet; die folgenden Pakete laufen weiter, Wiederholung mit Abstand: {0}" -f (Get-VsErrorDetail -ErrorRecord $_))
                    $scanWarnings++
                    Add-VsRunCause -Causes $causes -Cause 'package_scan_failed' -Target $fullName
                } finally {
                    # Ergebnis dieses Pakets in die Wiederholliste: mit Ursachen
                    # offen samt naechstem Termin, ohne Ursachen behoben. Ein
                    # abgebrochener Lauf traegt nichts ein; der naechste scannt voll.
                    if (-not $packageAborted) {
                        $packageState = Set-VsImporterOpenPackage -OpenPackages $openPackages -Folder $dir.Name -IntervalSeconds $intervalSeconds `
                            -Causes (Get-VsRunCausesSince -Causes $causes -Start $packageCauseStart)
                        if ($packageState -eq 'healed') {
                            Write-VsLog -Context $fullName -Message 'Offene Punkte dieses Pakets sind behoben.'
                        } elseif ($packageState -eq 'open') {
                            $openEntry = $openPackages[$dir.Name]
                            if ($openEntry.DueAt -eq [datetime]::MaxValue) {
                                Write-VsLog -Context $fullName -Message 'Offener Punkt bleibt bis zu einer Aenderung an config.json; keine Wiederholung bis dahin.'
                            } else {
                                Write-VsLog -Context $fullName -Message ("Offene Punkte bleiben in der Wiederholliste; Versuch {0}, naechste Pruefung in {1} s." -f $openEntry.Attempts, $openEntry.DelaySeconds)
                            }
                        }
                    }
                }
            }

            # Der Dateistand wird gemerkt, auch mit offenen Punkten (AR1, F-1): sie
            # stehen in der Wiederholliste, werden weiter gemeldet und mit Abstand
            # wiederholt. Ein Vollscan streicht Eintraege verschwundener Ordner.
            $lastFilesStamp = $stamp
            if ($fullScan) {
                Limit-VsImporterOpenPackages -OpenPackages $openPackages -Seen $seenFolders
                $lastHintRefresh = Get-Date
            }
            # Die Verteilhinweise dieses Scans ersetzen die gemerkten (AV2).
            $rememberedDistributionHints = $distributionHints
            if ($scanWarnings -gt 0) {
                Write-VsLog -Level WARN -Message ("Scan #{0} mit {1} offenen Punkten - Wiederholung mit Abstand, ohne Vollscan." -f $loop, $scanWarnings)
            }
            if ($newCount -gt 0 -or $deletedCount -gt 0) {
                Write-VsLog -Message ("Scan #{0} fertig: {1} neu, {2} alte Versionen entfernt." -f $loop, $newCount, $deletedCount)
            }
        }

        # Jeder vollstaendige Lauf meldet den ganzen Stand: alle offenen Punkte der
        # Wiederholliste (auch die nicht faelligen) vor den Verteilhinweisen. Ein
        # ruhiger Lauf mit offenen Punkten bleibt so "warning", statt nach dem
        # ersten Mal unsichtbar zu werden.
        if ($reportOpenPoints) {
            $openCauses = @(Get-VsImporterOpenCauses -OpenPackages $openPackages)
            $openPointCount = $openCauses.Count
            $hintCauses = New-VsRunCauseList
            foreach ($hintName in @($rememberedDistributionHints.Keys | Sort-Object)) {
                Add-VsRunCause -Causes $hintCauses -Cause $rememberedDistributionHints[$hintName] -Target $hintName
            }
            $allCauses = New-VsRunCauseList
            foreach ($cause in @($openCauses) + @($hintCauses)) { [void]$allCauses.Add([string]$cause) }
            if ($openPointCount -gt 0) {
                $outcome = 'warning'
                $category = 'partial_failure'
            }
            $detail = Format-VsRunDetail -Causes $allCauses
        }
    } catch {
        # Ein Paket, das sich waehrend des Scans aendert oder gesperrt ist, ist ein
        # Quellzustand (source_changing): Warnung, Verbindung bleibt bestehen, keine
        # Pause. Alles andere ist MECM-Init, zentrale Abfrage oder ein abgebrochener Scan.
        $category = Get-VsSyncFailureCategory -Phase 'scan' -ErrorRecord $_
        # Der Rohtext bleibt im lokalen Log; die Portalseite bekommt Kategorie und Kuerzung.
        $detail = Get-VsRunFailureDetail -Category $category -ErrorRecord $_
        $lastFilesStamp = ''
        if ($category -eq 'source_changing') {
            Write-VsLog -Level WARN -Message ("Paketquelle waehrend des Scans geaendert - naechster Lauf prueft erneut: {0}" -f (Get-VsErrorDetail -ErrorRecord $_))
            $outcome = 'warning'
        } else {
            Write-VsLog -Level ERROR -Message ("Scan-Fehler: {0}" -f (Get-VsErrorDetail -ErrorRecord $_))
            $outcome = 'fail'
            $siteCode = $null
            $providerMachine = $null
            $sleepSeconds = 60
        }
    } finally {
        # Genau EINE Abschlussmeldung pro Iteration, auch bei continue/throw.
        $durationMs = Get-VsRunDurationMilliseconds -StartedAt $cycleStart
        $summary = @{
            folders     = $folders
            created     = $newCount
            removed     = $deletedCount
            open_points = $openPointCount
            unchanged   = $unchanged
        }
        Send-VsRunReport -Config $config -Source 'autoimporter' -RunEvent 'completed' -RunId $runId `
            -IntervalSeconds $intervalSeconds -Outcome $outcome -ErrorCategory $category `
            -DurationMs $durationMs -Detail $detail -Summary $summary -ScriptVersion $SCRIPT_VERSION
    }

    Start-Sleep -Seconds $sleepSeconds
}
