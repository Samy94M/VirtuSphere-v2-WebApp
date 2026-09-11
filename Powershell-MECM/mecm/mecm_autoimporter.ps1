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
#  - kein Clear-Host (laeuft ohne Konsole), Heartbeat je Durchlauf
#
# Hinweis: Die Vorlage <PackagesRoot>\Package_Vorlage\install.ps1 ueberschreibt
# bewusst die paketeigene install.ps1 (Self-Healing der Standard-Installation).
# ============================================================================

. "$PSScriptRoot\VirtuSphere-Common.ps1"

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
$SCRIPT_VERSION = 'autoimporter/2.0'

$siteCode = $null
$providerMachine = $null
$lastFilesStamp = ''
$loop = 0

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
    $scanWarnings = 0   # offene Punkte -> Stamp nicht merken, naechster Lauf wiederholt
    $unchanged = 0
    $sleepSeconds = $intervalSeconds
    # Ursachen dieses Laufs mit Paket- und Ordnernamen; ohne sie zeigte die Karte
    # "offene Punkte: 3" ohne ein einziges Paket zu nennen.
    $causes = New-VsRunCauseList

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
        # Vorlagen-Skript zaehlt mit (B7): eine neue Vorlage muss den Abgleich
        # in jeden Paketordner ausloesen, nicht erst die naechste config.json.
        $stamp = Get-VsFilesManifestStamp -Path $basePath -TemplateScript (Join-Path $templatePath 'install.ps1')
        if ($stamp -eq $lastFilesStamp) {
            # Unveraendert: ein gelungener No-op-Lauf.
            $unchanged = 1
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
            Write-VsLog -Message ("Aenderung erkannt - Scan #{0}." -f $loop)

            $appOrgFolder = "{0}:\Application\{1}" -f $siteCode, $appFolderName
            $collectionOrgFolder = "{0}:\DeviceCollection\{1}" -f $siteCode, $appFolderName

            # A14b: erst den gesamten Quellenstand lesen, dann pro Produkt genau
            # einen Zielstand bestimmen. So koennen zwei gleichzeitig gelieferte
            # Versionen einander niemals im selben Scan als "alt" behandeln.
            $packageEntries = New-Object System.Collections.Generic.List[object]
            foreach ($dir in @(Get-ChildItem -Path $basePath -Directory)) {
                $cfg = Read-VsPackageConfig -Folder $dir.FullName
                if (-not $cfg) {
                    # Ein unlesbares oder unvollstaendiges config.json loggte WARN
                    # und erhoehte keinen Zaehler: der Lauf blieb ok, der Stamp
                    # wurde gemerkt, und der Ordner wurde nie wieder gescannt. Ein
                    # Paket, das so nie entsteht, ist kein gelungener Lauf.
                    $scanWarnings++
                    Add-VsRunCause -Causes $causes -Cause 'package_config_invalid' -Target $dir.Name
                    continue
                }
                $packageEntries.Add([pscustomobject]@{ Directory = $dir; Config = $cfg })
            }
            $sourceSelections = @(Get-VsPackageSourceSelections -Packages @($packageEntries | ForEach-Object { $_.Config }))

            # List[object] nicht ueber @($list) materialisieren: der
            # PSEnumerableBinder von Windows PowerShell 5.1 wirft dabei
            # "Die Argumenttypen stimmen nicht ueberein".
            foreach ($entry in $packageEntries.ToArray()) {
                $dir = $entry.Directory
                $cfg = $entry.Config
                $folders++

                $appName = [string]$cfg.ProjectName
                $version = [string]$cfg.version
                $fullName = "{0}-{1}" -f $appName, $version
                $folderName = $cfg.FolderName

                # --- Alt-Versionen nur erkennen, niemals im Importlauf loeschen --
                if ("$($cfg.removeOldVersion)" -eq 'true') {
                    # Der Name beweist weder Eigentum noch, dass $fullName ein
                    # sicherer Ersatz ist. Der fruehere Inline-Cleanup entfernte
                    # Deployment, Collection und Application noch bevor Content
                    # und Verteilung des Ersatzes belegt waren. Bis A14b einen
                    # geprueften Plan mit IDs, Ownership und Referenzen besitzt,
                    # bleibt der Bestand deshalb unveraendert.
                    $selection = @($sourceSelections | Where-Object { [string]$_.ProductName -ceq $appName })
                    if ($selection.Count -ne 1 -or [string]$selection[0].State -ne 'ready') {
                        Write-VsLog -Level WARN -Context $appName -Message 'Quellversionen sind nicht eindeutig und sicher numerisch ordnungsfaehig; automatische Bereinigungsplanung bleibt gesperrt.'
                        $scanWarnings++
                        Add-VsRunCause -Causes $causes -Cause 'package_cleanup_failed' -Target $appName
                    } elseif ([string]$selection[0].TargetName -cne $fullName) {
                        Write-VsLog -Context $fullName -Message ("Parallele Quellversion bleibt erhalten; eindeutiger Zielstand dieses Produkts ist {0}." -f $selection[0].TargetName)
                    }
                    $pattern = Get-VsSupersededNamePattern -AppName $appName
                    $oldNames = @{}
                    foreach ($c in @(Get-CMDeviceCollection -Name ("{0}-*" -f $appName) -ErrorAction SilentlyContinue)) {
                        if ($c.Name -match $pattern -and $c.Name -ne $fullName) { $oldNames[$c.Name] = $true }
                    }
                    foreach ($a in @(Get-CMApplication -Name ("{0}-*" -f $appName) -Fast -ErrorAction SilentlyContinue)) {
                        $n = [string]$a.LocalizedDisplayName
                        if ($n -match $pattern -and $n -ne $fullName) { $oldNames[$n] = $true }
                    }
                    foreach ($old in $oldNames.Keys) {
                        Write-VsLog -Level WARN -Context $old -Message ("Alt-Version bleibt erhalten; automatische Bereinigung ist ohne Eigentums-, Referenz- und Ersatznachweis gesperrt (angeforderter Zielstand: {0})." -f $fullName)
                        $scanWarnings++
                        Add-VsRunCause -Causes $causes -Cause 'package_cleanup_failed' -Target $old
                    }
                }

                # --- Self-Healing der install.ps1 (bei JEDEM Scan) -------------
                #
                # Nicht mehr nur bei $isNew: die Vorlage gewinnt laut Vertrag, aber der
                # $isNew-Zweig gab ihr genau einen Versuch. Ein Fehlschlag dort war
                # endgueltig, weil die Application danach existierte. Wiederholt wird,
                # solange der Inhalt abweicht, und ein Fehlschlag ist ein offener Punkt.
                $pkgFolder = Join-Path (Join-Path $config.PackagesRoot 'files') $folderName
                $templateScript = Join-Path $templatePath 'install.ps1'
                $packageScript = Join-Path $pkgFolder 'install.ps1'
                $templateCurrent = Test-VsTemplateScriptCurrent -TemplateFile $templateScript -PackageFile $packageScript
                if (-not $templateCurrent) {
                    try {
                        Copy-Item $templateScript -Destination $packageScript -Force -ErrorAction Stop
                        if (-not (Test-VsTemplateScriptCurrent -TemplateFile $templateScript -PackageFile $packageScript)) {
                            throw 'Kopie stimmt nach dem Schreiben nicht mit der Vorlage ueberein.'
                        }
                        $templateCurrent = $true
                        Write-VsLog -Context $fullName -Message 'Vorlagen-install.ps1 uebernommen und per SHA-256 bestaetigt.'
                    } catch {
                        Write-VsLog -Level WARN -Context $fullName -Message ("Vorlagen-install.ps1 nicht kopiert - Wiederholung im naechsten Durchlauf: {0}" -f $_.Exception.Message)
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
                        Write-VsLog -Level WARN -Context $fullName -Message ('Deployment-Type-Erstellung fehlgeschlagen, entferne unvollstaendige Application fuer erneuten Versuch: {0}' -f $_.Exception.Message)
                        Remove-CMApplication -Name $fullName -Force -ErrorAction SilentlyContinue | Out-Null
                        throw
                    }

                    # New-CMApplication/DT can return before the provider's
                    # distribution projection is queryable. Re-read the exact
                    # current object and fail closed if identity is ambiguous.
                    $freshApps = @(Get-CMApplication -Name $fullName -Fast -ErrorAction Stop)
                    if ($freshApps.Count -ne 1) {
                        throw ("Application '{0}' nach Erstellung nicht eindeutig lesbar." -f $fullName)
                    }
                    $app = $freshApps[0]

                    Get-CMApplication -Name $fullName | Move-CMObject -FolderPath $appOrgFolder -ErrorAction SilentlyContinue | Out-Null
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

                # --- Contentmanifest -> konkrete Application-/DT-Identitaet ---
                # Tracking wird vor dem Update als Intent gespeichert. Dadurch
                # loest ein Crash zwischen Update-CMDistributionPoint und ACK
                # keine blinde zweite Redistribution aus. Bei Applications bleibt
                # die Package-SourceVersion auch nach einem Contentupdate 1;
                # MECM erzeugt stattdessen eine neue ContentId am Deployment
                # Type. Manifestabschluss bindet deshalb Application ModelName +
                # PackageID sowie CI_UniqueID + ContentId des exakten DT. Die
                # Verteilung ist absichtlich NICHT an generateOwnDeviceColletion
                # gekoppelt.
                $packageManifest = Get-VsFilesManifestStamp -Path $pkgFolder
                $tracking = Get-VsPackageContentTracking -ApplicationName $fullName
                $snapshot = Get-VsContentDistributionSnapshot -ApplicationName $fullName -Application $app
                $applicationIdentity = Get-VsApplicationContentIdentity -Application $app -ExpectedName $fullName
                $deploymentTypeIdentity = if ($applicationIdentity.State -eq 'known') {
                    Get-VsDeploymentTypeContentIdentity -DeploymentType $deploymentTypes[0] -ExpectedName $deploymentTypeName `
                        -ExpectedApplicationModelName $applicationIdentity.ModelName
                } else { [pscustomobject]@{ State = 'unknown'; DeploymentTypeModelName = ''; DeploymentTypeId = ''; ContentId = '' } }
                if ($applicationIdentity.State -ne 'known' -or $deploymentTypeIdentity.State -ne 'known') {
                    Write-VsLog -Level WARN -Context $fullName -Message 'Application-/Deployment-Type-Contentidentitaet ist nicht eindeutig lesbar; Content wird nicht veraendert.'
                    $scanWarnings++
                    Add-VsRunCause -Causes $causes -Cause 'package_content_unknown' -Target $fullName
                    continue
                }
                $copySnapshot = Get-VsDistributionCopySnapshot -PackageId $applicationIdentity.PackageId `
                    -ApplicationModelName $applicationIdentity.ModelName -SiteCode $siteCode -ProviderMachine $providerMachine
                if ($copySnapshot.State -ne 'known') {
                    Write-VsLog -Level WARN -Context $fullName -Message 'DP-Kopiernachweis ist nicht sicher lesbar; Content wird nicht veraendert oder bestaetigt.'
                    $scanWarnings++
                    Add-VsRunCause -Causes $causes -Cause 'package_content_unknown' -Target $fullName
                    continue
                }
                if ($tracking -and $tracking.State -eq 'invalid') {
                    Write-VsLog -Level WARN -Context $fullName -Message 'Content-Tracking ist unvollstaendig oder unlesbar; keine Redistribution ohne geklaerten Intent.'
                    $scanWarnings++
                    Add-VsRunCause -Causes $causes -Cause 'package_content_unknown' -Target $fullName
                    continue
                }

                if ($tracking -and $tracking.State -eq 'legacy') {
                    # Auch ein alter complete-Eintrag enthaelt weder Application-
                    # noch DT-/Contentidentitaet oder eine per-DP-LastCopied-
                    # Baseline. Diese Evidenz kann nicht nachtraeglich aus einem
                    # namensgleichen aktuellen Objekt konstruiert werden.
                    Write-VsLog -Level WARN -Context $fullName -Message ("Altes Content-Tracking ({0}) besitzt keine Application-/Deployment-Type-/DP-Identitaetsgrenze; vor einer Migration ist manuelle Validierung erforderlich." -f $tracking.LegacyState)
                    $scanWarnings++
                    Add-VsRunCause -Causes $causes -Cause 'package_content_unknown' -Target $fullName
                    continue
                }

                if ($tracking -and ($tracking.ApplicationModelName -cne $applicationIdentity.ModelName -or
                    $tracking.ApplicationPackageId -cne $applicationIdentity.PackageId)) {
                    Write-VsLog -Level WARN -Context $fullName -Message 'Application-Contentidentitaet weicht vom gespeicherten Auftrag ab; keine automatische Uebernahme eines namensgleichen Objekts.'
                    $scanWarnings++
                    Add-VsRunCause -Causes $causes -Cause 'package_content_unknown' -Target $fullName
                    continue
                }
                if ($tracking -and $tracking.DeploymentTypeModelName -cne $deploymentTypeIdentity.DeploymentTypeModelName) {
                    Write-VsLog -Level WARN -Context $fullName -Message 'Deployment-Type-Modellidentitaet weicht vom gespeicherten Auftrag ab; ein namensgleich neu angelegter DT wird nicht uebernommen.'
                    $scanWarnings++
                    Add-VsRunCause -Causes $causes -Cause 'package_content_unknown' -Target $fullName
                    continue
                }

                if ($tracking -and $tracking.State -eq 'intent') {
                    if (-not $tracking.RequestConfirmed) {
                        Write-VsLog -Level WARN -Context $fullName -Message 'Content-Intent wurde nicht durch einen erfolgreich zurueckgekehrten MECM-Aufruf bestaetigt; keine automatische Wiederholung oder Uebernahme.'
                        $scanWarnings++
                        Add-VsRunCause -Causes $causes -Cause 'package_content_unknown' -Target $fullName
                        continue
                    }
                    $intentCanBind = $tracking.RequestKind -eq 'initial' -or $deploymentTypeIdentity.ContentId -cne $tracking.BaselineContentId
                    if ($intentCanBind) {
                        Set-VsPackageContentTracking -ApplicationName $fullName -State pending -Manifest $tracking.Manifest `
                            -BaselineSourceVersion $tracking.BaselineSourceVersion -SourceVersion -1 -RequestKind $tracking.RequestKind `
                            -ApplicationModelName $tracking.ApplicationModelName -ApplicationPackageId $tracking.ApplicationPackageId `
                            -DeploymentTypeModelName $tracking.DeploymentTypeModelName -DeploymentTypeId $deploymentTypeIdentity.DeploymentTypeId `
                            -BaselineContentId $tracking.BaselineContentId `
                            -ContentId $deploymentTypeIdentity.ContentId -RequestConfirmed $true -DistributionBaseline $tracking.DistributionBaseline
                        Write-VsLog -Level WARN -Context $fullName -Message ("Angeforderte Deployment-Type-ContentId {0} gelesen; Verteilstatus wird im naechsten Durchlauf bestaetigt." -f $deploymentTypeIdentity.ContentId)
                    } else {
                        Write-VsLog -Level WARN -Context $fullName -Message ("Contentauftrag ist noch nicht als neue Deployment-Type-ContentId sichtbar (Baseline {0})." -f $tracking.BaselineContentId)
                    }
                    $scanWarnings++
                    Add-VsRunCause -Causes $causes -Cause 'package_content_in_progress' -Target $fullName
                    continue
                }

                if ($tracking -and ($tracking.DeploymentTypeModelName -cne $deploymentTypeIdentity.DeploymentTypeModelName -or
                    $tracking.DeploymentTypeId -cne $deploymentTypeIdentity.DeploymentTypeId -or
                    $tracking.ContentId -cne $deploymentTypeIdentity.ContentId)) {
                    Write-VsLog -Level WARN -Context $fullName -Message 'Deployment-Type-/Contentidentitaet weicht vom gespeicherten Auftrag ab; externer oder weiterer Contentwechsel muss zuerst geklaert werden.'
                    $scanWarnings++
                    Add-VsRunCause -Causes $causes -Cause 'package_content_unknown' -Target $fullName
                    continue
                }

                $needsContentRequest = $null -eq $tracking -or $tracking.Manifest -cne $packageManifest
                if ($needsContentRequest -and $tracking -and $tracking.State -eq 'pending') {
                    $previousConfirmed = $snapshot.State -eq 'succeeded' -and
                        (Test-VsDistributionCopyAdvanced -BaselineJson $tracking.DistributionBaseline -CurrentSnapshot $copySnapshot)
                    if (-not $previousConfirmed) {
                        Write-VsLog -Level WARN -Context $fullName -Message 'Quelle hat sich waehrend einer noch nicht bestaetigten Contentaktualisierung erneut geaendert; zuerst den laufenden/unklaren Stand in MECM klaeren.'
                        $scanWarnings++
                        Add-VsRunCause -Causes $causes -Cause 'package_content_in_progress' -Target $fullName
                        continue
                    }
                }

                if ($needsContentRequest) {
                    if ($snapshot.State -eq 'unknown') {
                        Write-VsLog -Level WARN -Context $fullName -Message 'Verteilstatus/SourceVersion nicht sicher lesbar; Contentaktualisierung wird nicht blind angestossen.'
                        $scanWarnings++
                        Add-VsRunCause -Causes $causes -Cause 'package_content_unknown' -Target $fullName
                        continue
                    }
                    if ($snapshot.State -eq 'failed') {
                        Write-VsLog -Level WARN -Context $fullName -Message 'Vorhandene Content-Verteilung ist fehlgeschlagen; zuerst in MECM reparieren, keine blinde Redistribution.'
                        $scanWarnings++
                        Add-VsRunCause -Causes $causes -Cause 'package_content_failed' -Target $fullName
                        continue
                    }
                    if ($snapshot.State -eq 'in_progress') {
                        Write-VsLog -Level WARN -Context $fullName -Message 'Vorhandene Content-Verteilung laeuft noch; kein neuer Contentauftrag ueberlappt einen fremden oder aelteren Kopiervorgang.'
                        $scanWarnings++
                        Add-VsRunCause -Causes $causes -Cause 'package_content_in_progress' -Target $fullName
                        continue
                    }
                    $baselineSourceVersion = if ($null -eq $snapshot.SourceVersion) { -1 } else { [int]$snapshot.SourceVersion }
                    $requestKind = if ($snapshot.State -eq 'not_started') { 'initial' } else { 'update' }
                    if ($requestKind -eq 'initial' -and [string]::IsNullOrWhiteSpace($dpGroupName)) {
                        Write-VsLog -Level WARN -Context $fullName -Message 'DP-Gruppe fehlt; Erstverteilung wird ohne Voraussetzung nicht als Content-Intent gespeichert.'
                        $scanWarnings++
                        Add-VsRunCause -Causes $causes -Cause 'package_content_failed' -Target $fullName
                        continue
                    }
                    if (-not (Test-VsDistributionCopyBaselineReady -RequestKind $requestKind -Snapshot $copySnapshot)) {
                        Write-VsLog -Level WARN -Context $fullName -Message 'DP-Kopierbaseline passt nicht zum Start-/Updatezustand; kein Contentauftrag wird auf eine leere oder unvollstaendige Projektion gebaut.'
                        $scanWarnings++
                        Add-VsRunCause -Causes $causes -Cause 'package_content_unknown' -Target $fullName
                        continue
                    }
                    $distributionBaseline = ConvertTo-VsDistributionBaselineJson -Snapshot $copySnapshot
                    Set-VsPackageContentTracking -ApplicationName $fullName -State intent -Manifest $packageManifest `
                        -BaselineSourceVersion $baselineSourceVersion -RequestKind $requestKind `
                        -ApplicationModelName $applicationIdentity.ModelName -ApplicationPackageId $applicationIdentity.PackageId `
                        -DeploymentTypeModelName $deploymentTypeIdentity.DeploymentTypeModelName -DeploymentTypeId $deploymentTypeIdentity.DeploymentTypeId `
                        -BaselineContentId $deploymentTypeIdentity.ContentId `
                        -RequestConfirmed $false -DistributionBaseline $distributionBaseline
                    try {
                        if ($snapshot.State -eq 'not_started') {
                            Start-CMContentDistribution -ApplicationName $fullName -DistributionPointGroupName $dpGroupName -ErrorAction Stop | Out-Null
                            Write-VsLog -Context $fullName -Message ("Contentversion/Erstverteilung an DP-Gruppe '{0}' angefordert." -f $dpGroupName)
                        } else {
                            Update-CMDistributionPoint -ApplicationName $fullName -DeploymentTypeName $deploymentTypeName -ErrorAction Stop | Out-Null
                            Write-VsLog -Context $fullName -Message ("Neue Contentversion fuer Deployment Type '{0}' angefordert." -f $deploymentTypeName)
                        }
                        Set-VsPackageContentTracking -ApplicationName $fullName -State intent -Manifest $packageManifest `
                            -BaselineSourceVersion $baselineSourceVersion -RequestKind $requestKind `
                            -ApplicationModelName $applicationIdentity.ModelName -ApplicationPackageId $applicationIdentity.PackageId `
                            -DeploymentTypeModelName $deploymentTypeIdentity.DeploymentTypeModelName -DeploymentTypeId $deploymentTypeIdentity.DeploymentTypeId `
                            -BaselineContentId $deploymentTypeIdentity.ContentId `
                            -RequestConfirmed $true -DistributionBaseline $distributionBaseline
                    } catch {
                        Write-VsLog -Level WARN -Context $fullName -Message ("Contentversion konnte nicht sicher angefordert/bestaetigt werden; Intent bleibt zur manuellen Klaerung stehen: {0}" -f $_.Exception.Message)
                        $scanWarnings++
                        Add-VsRunCause -Causes $causes -Cause 'package_content_unknown' -Target $fullName
                        continue
                    }
                    # Kein sofortiges ACK: der Provider kann Applicationrevision,
                    # DT-ContentId und Distributionprojektion zeitversetzt zeigen.
                    # Der naechste Scan bindet den Intent an genau eine ContentId.
                    $scanWarnings++
                    Add-VsRunCause -Causes $causes -Cause 'package_content_in_progress' -Target $fullName
                    continue
                }

                if ($tracking.State -eq 'pending') {
                    $copyAdvanced = Test-VsDistributionCopyAdvanced -BaselineJson $tracking.DistributionBaseline -CurrentSnapshot $copySnapshot
                    if ($snapshot.State -eq 'succeeded' -and $copyAdvanced) {
                        $confirmedSourceVersion = if ($null -eq $snapshot.SourceVersion) { -1 } else { [int]$snapshot.SourceVersion }
                        Set-VsPackageContentTracking -ApplicationName $fullName -State complete -Manifest $packageManifest `
                            -BaselineSourceVersion $tracking.BaselineSourceVersion -SourceVersion $confirmedSourceVersion -RequestKind $tracking.RequestKind `
                            -ApplicationModelName $tracking.ApplicationModelName -ApplicationPackageId $tracking.ApplicationPackageId `
                            -DeploymentTypeModelName $tracking.DeploymentTypeModelName -DeploymentTypeId $tracking.DeploymentTypeId `
                            -BaselineContentId $tracking.BaselineContentId -ContentId $tracking.ContentId `
                            -RequestConfirmed $true -DistributionBaseline $tracking.DistributionBaseline
                        Write-VsLog -Context $fullName -Message ("Deployment-Type-ContentId {0} vollstaendig verteilt; Manifest bestaetigt." -f $tracking.ContentId)
                    } else {
                        $contentCause = if ($snapshot.State -eq 'failed') { 'package_content_failed' } elseif ($snapshot.State -eq 'unknown') { 'package_content_unknown' } else { 'package_content_in_progress' }
                        Write-VsLog -Level WARN -Context $fullName -Message ("Deployment-Type-ContentId {0} noch nicht vollstaendig verteilt (Status {1}, Package-SourceVersion {2}, DP-Kopie neuer als Baseline: {3})." -f $tracking.ContentId, $snapshot.State, $snapshot.SourceVersion, $copyAdvanced)
                        $scanWarnings++
                        Add-VsRunCause -Causes $causes -Cause $contentCause -Target $fullName
                        continue
                    }
                } elseif ($tracking.State -eq 'complete') {
                    $copyAdvanced = Test-VsDistributionCopyAdvanced -BaselineJson $tracking.DistributionBaseline -CurrentSnapshot $copySnapshot
                    if ($snapshot.State -ne 'succeeded' -or -not $copyAdvanced) {
                        $contentCause = if ($snapshot.State -eq 'failed') { 'package_content_failed' } elseif ($snapshot.State -eq 'unknown') { 'package_content_unknown' } else { 'package_content_in_progress' }
                        Write-VsLog -Level WARN -Context $fullName -Message ("Verteilnachweis fuer gespeicherte ContentId {0} ist nicht mehr vollstaendig (Status {1})." -f $tracking.ContentId, $snapshot.State)
                        $scanWarnings++
                        Add-VsRunCause -Causes $causes -Cause $contentCause -Target $fullName
                        continue
                    }
                }

                # --- Collection + Deployments idempotent nachziehen -------------
                # Laeuft auch fuer bestehende Apps und heilt damit fruehere
                # Teilfehler (App vorhanden, aber Collection/Deployment fehlt).
                if ("$($cfg.generateOwnDeviceColletion)" -eq 'true') {
                    $collection = Get-CMDeviceCollection -Name $fullName -ErrorAction SilentlyContinue
                    if (-not $collection) {
                        New-CMDeviceCollection -Name $fullName -LimitingCollectionName 'All Systems' -Comment $script:VsManagedPackageCollectionMarker -ErrorAction SilentlyContinue | Out-Null
                        Start-Sleep -Seconds 2
                        $collection = Get-CMDeviceCollection -Name $fullName -ErrorAction SilentlyContinue
                    }
                    # New-CMDeviceCollection scheitert unter SilentlyContinue lautlos.
                    # Ohne diese Pruefung lief der Block weiter, das Deployment
                    # scheiterte an der fehlenden Collection, und der offene Punkt
                    # hiess package_deploy_failed: die Ursache stand unter dem
                    # falschen Namen, und der Operator suchte am falschen Ende.
                    if (-not $collection) {
                        Write-VsLog -Level WARN -Context $fullName -Message ("Device-Collection '{0}' konnte nicht angelegt werden - Deployment uebersprungen, Wiederholung im naechsten Durchlauf." -f $fullName)
                        $scanWarnings++
                        Add-VsRunCause -Causes $causes -Cause 'collection_missing' -Target $fullName -Collection $fullName
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
                    # steht bereits als collection_missing.
                    if ($collection -and -not (Get-CMApplicationDeployment -Name $fullName -CollectionName $fullName -ErrorAction SilentlyContinue)) {
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
                if (-not [string]::IsNullOrWhiteSpace($cfg.DeployTo)) {
                    if (-not (Get-CMApplicationDeployment -Name $fullName -CollectionName ([string]$cfg.DeployTo) -ErrorAction SilentlyContinue)) {
                        try {
                            New-CMApplicationDeployment -Name $fullName -CollectionName ([string]$cfg.DeployTo) -DeployAction Install -DeployPurpose Available -UserNotification DisplaySoftwareCenterOnly -ErrorAction Stop | Out-Null
                        } catch {
                            # Zaehlt jetzt (B7): ohne Zaehler wurde der Stamp gemerkt
                            # und der Fehlschlag war ab dem zweiten Lauf unsichtbar -
                            # eine spaeter angelegte DeployTo-Collection bekam ihr
                            # Deployment nie. Die Meldung nennt die echte Ursache
                            # statt der Vermutung "nicht gefunden".
                            Write-VsLog -Level WARN -Context $fullName -Message ("Zusatz-Deployment auf '{0}' fehlgeschlagen - Wiederholung im naechsten Durchlauf: {1}" -f $cfg.DeployTo, $_.Exception.Message)
                            $scanWarnings++
                            Add-VsRunCause -Causes $causes -Cause 'package_deploy_failed' -Target $fullName -Collection ([string]$cfg.DeployTo)
                        }
                    }
                }
            }

            if ($scanWarnings -gt 0) {
                # Stamp nicht merken: naechster Durchlauf wiederholt die offenen
                # Punkte (Collection/Deployment-Nachzug ist idempotent).
                Write-VsLog -Level WARN -Message ("Scan #{0} mit {1} offenen Punkten - Wiederholung im naechsten Intervall." -f $loop, $scanWarnings)
                $outcome = 'warning'
                $category = 'partial_failure'
                $detail = Format-VsRunDetail -Causes $causes
            } else {
                $lastFilesStamp = $stamp
            }
            if ($newCount -gt 0 -or $deletedCount -gt 0) {
                Write-VsLog -Message ("Scan #{0} fertig: {1} neu, {2} alte Versionen entfernt." -f $loop, $newCount, $deletedCount)
            }
        }
    } catch {
        $detail = Get-VsErrorDetail -ErrorRecord $_
        Write-VsLog -Level ERROR -Message ("Scan-Fehler: {0}" -f $detail)
        # MECM-Init, zentrale Abfrage oder ein abgebrochener Scan.
        $outcome = 'fail'
        $category = 'mecm_unavailable'
        $siteCode = $null
        $providerMachine = $null
        $lastFilesStamp = ''
        $sleepSeconds = 60
    } finally {
        # Genau EINE Abschlussmeldung pro Iteration, auch bei continue/throw.
        $durationMs = Get-VsRunDurationMilliseconds -StartedAt $cycleStart
        $summary = @{
            folders     = $folders
            created     = $newCount
            removed     = $deletedCount
            open_points = $scanWarnings
            unchanged   = $unchanged
        }
        Send-VsRunReport -Config $config -Source 'autoimporter' -RunEvent 'completed' -RunId $runId `
            -IntervalSeconds $intervalSeconds -Outcome $outcome -ErrorCategory $category `
            -DurationMs $durationMs -Detail $detail -Summary $summary -ScriptVersion $SCRIPT_VERSION
    }

    Start-Sleep -Seconds $sleepSeconds
}
