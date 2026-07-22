#Requires -Version 5.1
#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Legt die vier VirtuSphere-Client-Applikationen in MECM an (falls fehlend) und
    haelt ihren Content aktuell.

.DESCRIPTION
    Fuer jede der vier Client-Phasen (getinfo -> hostname -> staticip -> disks):
      1. stellt den Content bereit: das Client-Skript UND
         VirtuSphere-Client-Common.ps1 in <PackagesBase>\<Ordner> (ersetzt
         bestehende Dateien),
      2. legt die MECM-Application im Ordner <AppFolder> an, WENN sie fehlt
         (Self-Healing; bestehende Apps werden nicht angetastet),
      3. verdrahtet die Abhaengigkeitskette,
      4. verteilt den Content an die DP-Gruppe.

    Es wird KEIN Deployment an eine Collection angelegt - das entscheidet der
    MECM-Admin. Der WebAPI-Name wird NICHT in die Common-ps1 gestempelt (Common
    bleibt unveraendert); die Adress-Aufloesung laeuft ueber DNS.

    Muss auf dem MECM-Server mit installierter MECM-Konsole laufen.
    Idempotent: erneutes Ausfuehren aktualisiert Content und heilt fehlende Apps.

.PARAMETER ContentShare
    UNC-Freigabe von PackagesBase, z. B. \\MECM-SERVER\VirtuSphere\Base\Packages.
    Wird als ContentLocation der Deployment-Types gesetzt.

.PARAMETER PackagesBase
    Lokale Wurzel der Client-Paketordner. Standard: D:\VirtuSphere\Base\Packages.

.PARAMETER DpGroupName
    Distribution-Point-GRUPPE fuer die Content-Verteilung. Leer = nicht verteilen
    (der Admin verteilt dann manuell). Standard wie beim Server-Installer.

.PARAMETER SourceDir
    Quelle der Client-Skripte. Standard: der clients-Ordner neben diesem Skript.

.PARAMETER AppFolder
    Application-Ordner in der Konsole. Standard: VirtuSphere_Core.

.EXAMPLE
    .\install-VirtuSphere-Clients.ps1 -ContentShare \\MECM-01\VirtuSphere\Base\Packages
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$ContentShare,
    [string]$PackagesBase = 'D:\VirtuSphere\Base\Packages',
    [string]$DpGroupName = 'DP Group - VirtuSphere-Applications',
    [string]$SourceDir = (Join-Path $PSScriptRoot 'clients'),
    [string]$AppFolder = 'VirtuSphere_Core'
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 1.0

. (Join-Path $PSScriptRoot 'mecm\VirtuSphere-Common.ps1')
. (Join-Path $PSScriptRoot 'mecm\VirtuSphere-ClientPackaging.ps1')

function Write-Step { param([string]$Message) ; Write-Host "==> $Message" -ForegroundColor Cyan }
function Write-Ok   { param([string]$Message) ; Write-Host "    OK  $Message" -ForegroundColor Green }
function Write-Warn { param([string]$Message) ; Write-Host "    !!  $Message" -ForegroundColor Yellow }

$ContentShare = $ContentShare.TrimEnd('\')
$specs = Get-VsClientAppSpecs

# --- Content bereitstellen (kein CM noetig, hier scharf pruefbar) -----------
Write-Step 'Stelle Client-Content bereit (Skript + Common je Ordner)'
foreach ($spec in $specs) {
    $dest = Copy-VsClientContent -Spec $spec -SourceDir $SourceDir -PackagesBase $PackagesBase
    Write-Ok ("{0}: {1} + Common.ps1 -> {2}" -f $spec.AppName, $spec.Script, $dest)
}

# ContentShare MUSS die Freigabe von PackagesBase sein: gestaged wird lokal nach
# PackagesBase\<Ordner>, die App-ContentLocation zeigt aber auf ContentShare\<Ordner>.
# Zeigen die beiden auf verschiedene Ordner, hat jede App leeren Content - ohne
# Fehler beim Anlegen, der Client bekommt beim Deploy "Content not found".
# Geprueft an einer gerade gestageten Datei (kein separater Marker noetig).
$probeSpec = $specs[0]
$probeVia = Join-Path (Join-Path $ContentShare $probeSpec.Folder) $probeSpec.Script
if (Test-Path $probeVia) {
    Write-Ok 'ContentShare zeigt auf PackagesBase (ContentLocation stimmt)'
} else {
    Write-Warn ("ContentShare '{0}' zeigt NICHT auf PackagesBase '{1}': die gestagete Datei '{2}' ist ueber die Freigabe nicht sichtbar. Jede App bekaeme leeren Content. Freigabe pruefen." -f $ContentShare, $PackagesBase, $probeSpec.Script)
}

# Der Client-Content laeuft als SYSTEM auf jedem Client. Ist PackagesBase fuer
# normale Benutzer beschreibbar, waere das Codeausfuehrung als SYSTEM (dieselbe
# Klasse wie der Paket-files-Ordner beim Server-Installer). Nur warnen - der Fix
# ist eine ACL-Entscheidung des Admins. Bei Nur-Lese-Rechten fuer Benutzer
# (Normalfall) schlaegt der Filter nicht an.
$writableByUsers = (Get-Acl -Path $PackagesBase).Access | Where-Object {
    $_.AccessControlType -eq 'Allow' -and
    $_.FileSystemRights -match 'Write|Modify|FullControl' -and
    $_.IdentityReference -match 'Users|Everyone|Authenticated Users'
}
if ($writableByUsers) {
    Write-Warn ("PackagesBase '{0}' ist fuer normale Benutzer beschreibbar. Der Content laeuft als SYSTEM auf den Clients: Schreibrechte auf Administratoren/SYSTEM begrenzen." -f $PackagesBase)
}

# --- MECM-Site initialisieren -----------------------------------------------
Write-Step 'Initialisiere MECM-Site'
$config = Get-VsConfig   # fuer SiteCodeFallback/LogRoot; darf $null sein
$logRoot = if ($config) { $config.LogRoot } else { $null }
Initialize-VsLog -Component 'client-packaging' -LogRoot $logRoot
$siteCode = Initialize-VsCmSite -Config $config
if (-not $siteCode) { throw 'MECM-Site nicht initialisierbar (MECM-Konsole/Site-Drive pruefen).' }
Write-Ok "Site-Drive $siteCode aktiv"

# Application-Ordner sicherstellen (Get-/New-CMFolder erwarten RELATIVE Pfade).
$appFolderRelative = "Application\{0}" -f $AppFolder
if (-not (Get-CMFolder -FolderPath $appFolderRelative -ErrorAction SilentlyContinue)) {
    try {
        New-CMFolder -Name $AppFolder -ParentFolderPath 'Application' -ErrorAction Stop | Out-Null
        Write-Ok ("Application-Ordner '{0}' angelegt" -f $AppFolder)
    } catch {
        Write-Warn ("Application-Ordner '{0}' nicht anlegbar: {1}" -f $AppFolder, (Get-VsErrorDetail -ErrorRecord $_))
    }
}
$appFolderPath = "{0}:\Application\{1}" -f $siteCode, $AppFolder

# --- Je App: anlegen wenn fehlend, Content verteilen ------------------------
Write-Step 'Applikationen anlegen (falls fehlend) und Content verteilen'
# Nur neu angelegte Apps bekommen spaeter die Abhaengigkeit verdrahtet: eine
# bestehende Kette wird nicht angetastet (#4), und ein Re-Run wuerde sonst bei
# jedem Lauf eine weitere "Requires X"-Gruppe anhaengen.
$createdApps = @()
foreach ($spec in $specs) {
    try {
        $existing = Get-CMApplication -Name $spec.AppName -Fast -ErrorAction SilentlyContinue
        if (-not $existing) {
            Write-Ok ("NEU: erstelle Application '{0}'" -f $spec.AppName)
            New-CMApplication -Name $spec.AppName -ErrorAction Stop | Out-Null

            # Detection-Klausel(n). Mehrere Werte (hostname) werden mit ODER
            # verbunden, sonst gilt die App fuer einen der Faelle nie als installiert.
            $clauses = @()
            foreach ($val in $spec.DetectionValues) {
                $clauses += New-CMDetectionClauseRegistryKeyValue -Hive LocalMachine `
                    -KeyName $spec.DetectionKey -ValueName $spec.DetectionName `
                    -PropertyType $spec.DetectionType -ExpressionOperator IsEquals `
                    -ExpectedValue $val -Value
            }

            $dtParams = @{
                ApplicationName    = $spec.AppName
                DeploymentTypeName = ("{0} Deployment" -f $spec.AppName)
                ContentLocation    = (Join-Path $ContentShare $spec.Folder)
                InstallCommand     = (Get-VsClientInstallCommand -Spec $spec)
                AddDetectionClause = $clauses
                # 1641 (harter Neustart) und 3010 (weicher) stehen in der MECM-
                # Standard-Rueckgabecode-Tabelle bereits als Neustart-Erfolg, daher
                # keine eigene Return-Code-Pflege noetig (client_hostname liefert 1641).
                RebootBehavior     = 'BasedOnExitCode'
                InstallationBehaviorType = 'InstallForSystem'
            }
            if ($clauses.Count -gt 1) {
                # Alle Klauseln mit ODER verketten.
                $dtParams['DetectionClauseConnector'] = @(
                    for ($i = 1; $i -lt $clauses.Count; $i++) {
                        @{ LogicalName = $clauses[$i].Setting.LogicalName; Connector = 'OR' }
                    }
                )
            }
            Add-CMScriptDeploymentType @dtParams -ErrorAction Stop | Out-Null

            Get-CMApplication -Name $spec.AppName -Fast | Move-CMObject -FolderPath $appFolderPath -ErrorAction SilentlyContinue | Out-Null
            $createdApps += $spec.AppName
        } else {
            Write-Ok ("'{0}' existiert bereits - Definition unangetastet, nur Content aktualisiert." -f $spec.AppName)
        }

        # Content verteilen bzw. aktualisieren (der DP muss die ersetzten Dateien
        # neu ziehen, sonst serviert er die alten). Nicht fatal.
        if ([string]::IsNullOrWhiteSpace($DpGroupName)) {
            Write-Warn ("Keine DP-Gruppe angegeben - '{0}' NICHT verteilt. Manuell verteilen." -f $spec.AppName)
        } else {
            try {
                if (-not $existing) {
                    Start-CMContentDistribution -ApplicationName $spec.AppName -DistributionPointGroupName $DpGroupName -ErrorAction Stop | Out-Null
                    Write-Ok ("'{0}' an DP-Gruppe '{1}' verteilt" -f $spec.AppName, $DpGroupName)
                } else {
                    Update-CMDistributionPoint -ApplicationName $spec.AppName -DeploymentTypeName ("{0} Deployment" -f $spec.AppName) -ErrorAction Stop | Out-Null
                    Write-Ok ("Content von '{0}' neu verteilt (ersetzte Dateien)" -f $spec.AppName)
                }
            } catch {
                Write-Warn ("Content-Verteilung fuer '{0}' fehlgeschlagen - ggf. schon verteilt oder manuell anstossen: {1}" -f $spec.AppName, (Get-VsErrorDetail -ErrorRecord $_))
            }
        }
    } catch {
        Write-Warn ("Application '{0}' fehlgeschlagen: {1}" -f $spec.AppName, (Get-VsErrorDetail -ErrorRecord $_))
    }
}

# --- Abhaengigkeitskette verdrahten -----------------------------------------
# getinfo -> hostname -> staticip -> disks. Nur fuer neu angelegte Apps sinnvoll;
# nicht fatal, weil die CM-Dependency-Cmdlets fiddelig sind und eine bestehende
# Kette nicht angetastet werden soll.
Write-Step 'Verdrahte Abhaengigkeitskette (best effort)'
foreach ($spec in $specs) {
    if (-not $spec.DependsOn) { continue }
    # Nur fuer in DIESEM Lauf neu angelegte Apps: eine bestehende Kette bleibt
    # unangetastet, und ein Re-Run haengt nicht bei jedem Mal eine weitere
    # Dependency-Gruppe an.
    if ($createdApps -notcontains $spec.AppName) { continue }
    try {
        $dt = Get-CMDeploymentType -ApplicationName $spec.AppName -ErrorAction Stop | Select-Object -First 1
        if (-not $dt) { continue }
        $group = New-CMDeploymentTypeDependencyGroup -InputObject $dt -GroupName ("Requires {0}" -f $spec.DependsOn) -ErrorAction Stop
        $depDt = Get-CMDeploymentType -ApplicationName $spec.DependsOn -ErrorAction Stop | Select-Object -First 1
        Add-CMDeploymentTypeDependency -DeploymentTypeDependency $depDt -IsAutoInstall $true -InputObject $group -ErrorAction Stop | Out-Null
        Write-Ok ("{0} haengt ab von {1}" -f $spec.AppName, $spec.DependsOn)
    } catch {
        Write-Warn ("Abhaengigkeit {0} -> {1} nicht gesetzt (ggf. schon vorhanden): {2}" -f $spec.AppName, $spec.DependsOn, (Get-VsErrorDetail -ErrorRecord $_))
    }
}

# --- Abschluss --------------------------------------------------------------
Write-Host ''
Write-Host 'Client-Applikationen bereit.' -ForegroundColor Green
Write-Host ''
Write-Host 'Naechste Schritte:' -ForegroundColor Gray
Write-Host ('  1. In der Konsole unter Softwarebibliothek > Anwendungen > {0} die vier Apps pruefen (Detection, Abhaengigkeitskette, verteilter Content).' -f $AppFolder) -ForegroundColor Gray
Write-Host '  2. WICHTIG bei client_hostname: dass die Detection BEIDE Werte als ODER prueft - Status = "Erfolgreich" ODER "Uebersprungen". Nur mit beiden wird ein domaenengebundener Client (der "Uebersprungen" schreibt) je als installiert erkannt, sonst laeuft er endlos. Der DetectionClauseConnector wird per Skript gesetzt, ist aber die eine CM-Stelle, die hier nicht testbar war - in der Konsole gegenpruefen.' -ForegroundColor Gray
Write-Host '  3. Deployment an die Ziel-Collection(s) anlegen (Required) - das macht der Admin bewusst, dieses Skript deployt nicht.' -ForegroundColor Gray
Write-Host '  4. Bei client_hostname pruefen, dass der Rueckgabecode 1641 als "Erfolg mit Neustart" gilt (MECM-Standardtabelle deckt das ab).' -ForegroundColor Gray
