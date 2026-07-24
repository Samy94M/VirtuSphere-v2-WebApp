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
#  - Change-Detection ueber files-Verzeichnis (mtime) - Voll-Scan nur bei
#    Aenderung, sonst Millisekunden-Leerlauf
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
$intervalSeconds = [Math]::Max(30, $config.ImporterInterval)

# Skript-Version fuer den Run-Report (script_version, <=32 Zeichen).
$SCRIPT_VERSION = 'autoimporter/2.0'

$siteCode = $null
$lastFilesStamp = ''
$loop = 0

# Read-VsPackageConfig und Get-VsSupersededNamePattern liegen in
# VirtuSphere-Common.ps1: dieses Skript ist eine Endlosschleife, was in ihm steht
# kann kein Test aufrufen, ohne sie zu starten. Dort deckt Pester beide ab.

# Fingerabdruck des files-Baums fuer Change-Detection.
function Get-VsFilesStamp {
    param([string]$Path)
    if (-not (Test-Path $Path)) { return 'missing' }
    $items = Get-ChildItem -Path $Path -Recurse -File -Filter 'config.json' -ErrorAction SilentlyContinue
    if (-not $items) { return 'empty' }
    ($items | Sort-Object FullName | ForEach-Object { '{0}:{1}' -f $_.FullName, $_.LastWriteTimeUtc.Ticks }) -join '|'
}

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

    try {
        if (-not $siteCode) {
            $siteCode = Initialize-VsCmSite -Config $config
            if (-not $siteCode) { throw 'MECM-Site nicht initialisierbar.' }
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

        # Change-Detection: nur bei geaendertem files-Baum voll scannen.
        $stamp = Get-VsFilesStamp -Path $basePath
        if ($stamp -eq $lastFilesStamp) {
            # Unveraendert: ein gelungener No-op-Lauf.
            $unchanged = 1
        } elseif (-not (Test-Path $basePath)) {
            Write-VsLog -Level WARN -Message ("Paket-Pfad nicht gefunden: {0}" -f $basePath)
            $lastFilesStamp = $stamp
            $outcome = 'warning'
            $category = 'source_missing'
        } else {
            Write-VsLog -Message ("Aenderung erkannt - Scan #{0}." -f $loop)

            $appOrgFolder = "{0}:\Application\{1}" -f $siteCode, $appFolderName
            $collectionOrgFolder = "{0}:\DeviceCollection\{1}" -f $siteCode, $appFolderName

            foreach ($dir in @(Get-ChildItem -Path $basePath -Directory)) {
                $cfg = Read-VsPackageConfig -Folder $dir.FullName
                if (-not $cfg) { continue }
                $folders++

            $appName = [string]$cfg.ProjectName
            $version = [string]$cfg.version
            $fullName = "{0}-{1}" -f $appName, $version
            $folderName = $cfg.FolderName

            # --- Alt-Versions-Bereinigung (EXAKTES Muster!) ----------------
            if ("$($cfg.removeOldVersion)" -eq 'true') {
                # Nur den exakten Stamm 'Name-<version>' entfernen, nicht die
                # aktuelle Version und keine Fremdpakete wie 'Name-ESR-*'.
                # Suche ueber Collections UND Applications, damit auch Pakete
                # ohne eigene Collection (generateOwnDeviceColletion=false)
                # bereinigt werden.
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
                    Write-VsLog -Context $old -Message 'Entferne alte Version.'
                    try {
                        Get-CMApplicationDeployment -Name $old -ErrorAction SilentlyContinue | Remove-CMApplicationDeployment -Force -ErrorAction Stop
                        if (Get-CMDeviceCollection -Name $old -ErrorAction SilentlyContinue) {
                            Remove-CMDeviceCollection -Name $old -Force -ErrorAction Stop
                        }
                        if (Get-CMApplication -Name $old -Fast -ErrorAction SilentlyContinue) {
                            Remove-CMApplication -Name $old -Force -ErrorAction Stop
                        }
                        $deletedCount++
                    } catch {
                        Write-VsLog -Level WARN -Context $old -Message ("Alt-Version nicht vollstaendig entfernt - Wiederholung im naechsten Durchlauf: {0}" -f $_.Exception.Message)
                        $scanWarnings++
                    }
                }
            }

            # --- Application anlegen (falls neu) ---------------------------
            $isNew = -not (Get-CMApplication -Name $fullName -Fast -ErrorAction SilentlyContinue)
            if ($isNew) {
                Write-VsLog -Context $fullName -Message 'NEU: erstelle Application.'
                New-CMApplication -Name $fullName -ErrorAction Stop | Out-Null

                # Self-Healing: Standard-install.ps1 aus der Vorlage ueberschreibt
                # bewusst die paketeigene Datei.
                $pkgFolder = Join-Path (Join-Path $config.PackagesRoot 'files') $folderName
                if ((Test-Path (Join-Path $templatePath 'install.ps1')) -and (Test-Path (Join-Path $pkgFolder 'install.ps1'))) {
                    try {
                        Copy-Item (Join-Path $templatePath 'install.ps1') -Destination (Join-Path $pkgFolder 'install.ps1') -Force -ErrorAction Stop
                    } catch {
                        Write-VsLog -Level WARN -Context $fullName -Message ("Vorlagen-install.ps1 nicht kopiert (Paket nutzt eigene Datei): {0}" -f $_.Exception.Message)
                    }
                }

                $registryDetection = "SOFTWARE\APLw\{0}-{1}" -f $appName, $version
                $dtParams = @{
                    ApplicationName      = $fullName
                    DeploymentTypeName   = "{0} Deployment" -f $fullName
                    ContentLocation      = (Join-Path $networkPath $folderName)
                    InstallCommand       = 'powershell.exe -ExecutionPolicy Bypass -File install.ps1'
                    UninstallCommand     = 'cmd.exe /s'
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

                Get-CMApplication -Name $fullName | Move-CMObject -FolderPath $appOrgFolder -ErrorAction SilentlyContinue | Out-Null
                $newCount++
            } else {
                Write-Host ("  {0} bereits vorhanden" -f $fullName) -ForegroundColor DarkGray
            }

            # --- Collection + Deployments idempotent nachziehen -------------
            # Laeuft auch fuer bestehende Apps und heilt damit fruehere
            # Teilfehler (App vorhanden, aber Collection/Deployment fehlt).
            if ("$($cfg.generateOwnDeviceColletion)" -eq 'true') {
                if (-not (Get-CMDeviceCollection -Name $fullName -ErrorAction SilentlyContinue)) {
                    New-CMDeviceCollection -Name $fullName -LimitingCollectionName 'All Systems' -ErrorAction SilentlyContinue | Out-Null
                    Start-Sleep -Seconds 2
                    Get-CMDeviceCollection -Name $fullName -ErrorAction SilentlyContinue | Move-CMObject -FolderPath $collectionOrgFolder -ErrorAction SilentlyContinue | Out-Null
                }

                if ($isNew) {
                    try {
                        Start-CMContentDistribution -ApplicationName $fullName -DistributionPointGroupName $dpGroupName -ErrorAction Stop | Out-Null
                    } catch {
                        # Kein Retry (erneutes Verteilen bereits verteilter Inhalte
                        # wirft selbst Fehler) - Verteilung dann manuell anstossen.
                        Write-VsLog -Level WARN -Context $fullName -Message ("Content-Verteilung fehlgeschlagen - manuell verteilen (DP-Gruppe '{0}'): {1}" -f $dpGroupName, $_.Exception.Message)
                    }
                }

                if (-not (Get-CMApplicationDeployment -Name $fullName -CollectionName $fullName -ErrorAction SilentlyContinue)) {
                    try {
                        New-CMApplicationDeployment -Name $fullName -CollectionName $fullName -DeployAction Install -DeployPurpose Required -UserNotification DisplaySoftwareCenterOnly -ErrorAction Stop | Out-Null
                    } catch {
                        Write-VsLog -Level WARN -Context $fullName -Message ("Deployment fehlgeschlagen - Wiederholung im naechsten Durchlauf: {0}" -f $_.Exception.Message)
                        $scanWarnings++
                    }
                }
            }

            # --- Zusatz-Deployment (Available) -----------------------------
            if (-not [string]::IsNullOrWhiteSpace($cfg.DeployTo)) {
                if (-not (Get-CMApplicationDeployment -Name $fullName -CollectionName ([string]$cfg.DeployTo) -ErrorAction SilentlyContinue)) {
                    try {
                        New-CMApplicationDeployment -Name $fullName -CollectionName ([string]$cfg.DeployTo) -DeployAction Install -DeployPurpose Available -UserNotification DisplaySoftwareCenterOnly -ErrorAction Stop | Out-Null
                    } catch {
                        # Bewusst ohne Retry-Zaehler: fehlende Ziel-Collection ist
                        # ein Konfigurationsfehler, Dauer-Retry wuerde nur spammen.
                        Write-VsLog -Level WARN -Context $fullName -Message ("Ziel-Collection '{0}' nicht gefunden." -f $cfg.DeployTo)
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
        $lastFilesStamp = ''
        $sleepSeconds = 60
    } finally {
        # Genau EINE Abschlussmeldung pro Iteration, auch bei continue/throw.
        $durationMs = [int]((Get-Date) - $cycleStart).TotalMilliseconds
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
