#Requires -Version 5.1
#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Legt die vier VirtuSphere-Client-Applikationen in MECM an (falls fehlend) und
    haelt ihren Content aktuell.

.DESCRIPTION
    Fuer jede der vier Client-Phasen (getinfo -> hostname -> staticip -> disks):
      1. stellt den Content bereit: Client-Skript, Common-Fassade UND lokales
         Loggingmodul in <PackagesBase>\<Ordner> (ersetzt bestehende Dateien),
      2. legt die MECM-Application an und ergaenzt einen fehlenden eigenen
         Deployment Type; mehrdeutige/fremd abweichende Definitionen blockieren,
      3. prueft und repariert die Abhaengigkeitskette bei jedem Re-Run,
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
    [string]$SourceDir = '',
    [string]$AppFolder = 'VirtuSphere_Core',
    [string]$WebApi = 'virtusphere.lan:8021',
    [ValidateSet('http', 'https')][string]$Scheme = 'http',
    [string]$CertThumbprint = ''
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 1.0

# Windows PowerShell 5.1 setzt $PSScriptRoot beim Auswerten eines
# Parameter-Defaultausdrucks noch nicht. Den Standard deshalb erst nach der
# Parameterbindung ableiten; ein ausdruecklich uebergebener Wert bleibt exakt
# die Entscheidung des Aufrufers und wird von den vorhandenen Pfadchecks
# beurteilt.
if (-not $PSBoundParameters.ContainsKey('SourceDir')) {
    $SourceDir = Join-Path $PSScriptRoot 'clients'
}

. (Join-Path $PSScriptRoot 'mecm\VirtuSphere-Common.ps1')
. (Join-Path $PSScriptRoot 'mecm\VirtuSphere-ClientPackaging.ps1')

# Zwei Klassen von Warnung, Einordnung an der Aufrufstelle (dieselbe Trennung
# wie in install-VirtuSphere-MECM.ps1):
#
# Write-Warn = BLOCKER. Die Applikation, ihre Content-Verteilung oder die
#   Freigabe hat nicht funktioniert; die Schlusszeile und der Exit-Code lesen
#   den Zaehler.
# Write-Hint = HINWEIS. Der Lauf ist gelungen, es gibt nur etwas zu wissen:
#   keine DP-Gruppe angegeben (der Admin verteilt dann bewusst von Hand), der
#   Paketordner ist fuer Benutzer beschreibbar (eine ACL-Entscheidung), und die
#   explizite manuelle Content-Verteilung ist eine bewusste Betriebsart.
$script:VsInstallBlockers = 0
function Write-Step { param([string]$Message) ; Write-Host "==> $Message" -ForegroundColor Cyan }
function Write-Ok   { param([string]$Message) ; Write-Host "    OK  $Message" -ForegroundColor Green }
function Write-Warn {
    param([string]$Message)
    $script:VsInstallBlockers++
    # Ueber Write-VsLog: das Log wurde bisher initialisiert und nie benutzt,
    # waehrend genau eine Erstinbetriebnahme spaeter nachvollzogen werden muss.
    Write-VsLog -Level WARN -Context 'client-packaging' -Message ("    !!  {0}" -f $Message) -Color Yellow
}
function Write-Hint {
    param([string]$Message)
    Write-VsLog -Level INFO -Context 'client-packaging' -Message ("    ~~  {0}" -f $Message) -Color DarkYellow
}

# Log vor der ersten Warnung initialisieren: die Freigabe- und ACL-Pruefung
# laeuft vor der MECM-Site, ihre Meldungen gehoeren trotzdem ins Tageslog.
# Get-VsConfig braucht kein CM (nur die Registry) und darf $null sein.
$config = Get-VsConfig
$logRoot = if ($config) { $config.LogRoot } else { $null }
Initialize-VsLog -Component 'client-packaging' -LogRoot $logRoot

$ContentShare = $ContentShare.TrimEnd('\')
$WebApi = Convert-VsWebApi $WebApi
$CertThumbprint = ($CertThumbprint -replace '\s', '').ToUpperInvariant()
if ($CertThumbprint -and $CertThumbprint -notmatch '^[0-9A-F]{40}$') { throw 'CertThumbprint muss genau 40 Hexzeichen (SHA-1-Anzeigeform von Windows) enthalten.' }
$bootstrap = @{ Schema = 1; WebAPI = $WebApi; Scheme = $Scheme; CertThumbprint = $CertThumbprint }
$specs = Get-VsClientAppSpecs
Assert-VsClientAppSpecGraph -Specs $specs
$serverLoggingVersion = Get-VsLoggingContractVersion
$clientLoggingVersion = Get-VsClientLoggingPackageVersion -SourceDir $SourceDir
if ($clientLoggingVersion -ne $serverLoggingVersion) {
    throw ('Logging-Vertrag weicht ab: Servermodul Version {0}, Clientmodul Version {1}. Vollstaendiges Paket verwenden.' -f $serverLoggingVersion, $clientLoggingVersion)
}

# --- Content bereitstellen (kein CM noetig, hier scharf pruefbar) -----------
Write-Step 'Stelle Client-Content bereit (Skript + Common + Loggingmodul je Ordner)'
$stagedContent = @{}
foreach ($spec in $specs) {
    $dest = Copy-VsClientContent -Spec $spec -SourceDir $SourceDir -PackagesBase $PackagesBase -Bootstrap $bootstrap
    $stagedContent[[string]$spec.AppName] = $dest
    Write-Ok ("{0}: {1} + Common.ps1 + Logging.ps1 + Bootstrap -> {2}" -f $spec.AppName, $spec.Script, $dest)
}

# ContentShare MUSS die Freigabe von PackagesBase sein: gestaged wird lokal nach
# PackagesBase\<Ordner>, die App-ContentLocation zeigt aber auf ContentShare\<Ordner>.
# Zeigen die beiden auf verschiedene Ordner, hat jede App leeren Content - ohne
# Fehler beim Anlegen, der Client bekommt beim Deploy "Content not found".
# Ein Dateiname beweist weder denselben Share noch aktuellen Inhalt. Fuer jeden
# Ordner wird deshalb das vollstaendige, sortierte Pfad/Laenge/SHA-256-Manifest
# des lokalen Satzes mit dem tatsaechlichen ContentShare verglichen. Auch alte
# Zusatzdateien sind Drift, weil MECM sie sonst weiter verteilen koennte.
$contentManifestBlockers = @()
foreach ($spec in $specs) {
    $publishedPath = Join-Path $ContentShare $spec.Folder
    try {
        $contentIssues = @(Compare-VsClientContentManifest -StagedPath $stagedContent[[string]$spec.AppName] -PublishedPath $publishedPath)
        if ($contentIssues.Count -gt 0) {
            $contentManifestBlockers += [string]$spec.AppName
            Write-Warn ("ContentShare-Manifest fuer '{0}' weicht ab ({1}). ContentLocation wird nicht als aktuell bestaetigt." -f $spec.AppName, ($contentIssues -join ', '))
        } else {
            Write-Ok ("{0}: vollstaendiges ContentShare-Manifest stimmt" -f $spec.AppName)
        }
    } catch {
        $contentManifestBlockers += [string]$spec.AppName
        Write-Warn ("ContentShare fuer '{0}' nicht vollstaendig pruefbar: {1}" -f $spec.AppName, (Get-VsErrorDetail -ErrorRecord $_))
    }
}
if ($contentManifestBlockers.Count -gt 0) {
    throw ("ContentShare-Manifestpruefung fehlgeschlagen ({0}). Vor der ersten MECM-Aenderung abbrechen; Freigabepfad, Leserechte und Inhalt korrigieren und den Installer erneut starten." -f ($contentManifestBlockers -join ', '))
}

# Der Client-Content laeuft als SYSTEM auf jedem Client. Ist PackagesBase fuer
# normale Benutzer beschreibbar, waere das Codeausfuehrung als SYSTEM (dieselbe
# Klasse wie der Paket-files-Ordner beim Server-Installer). Nur warnen - der Fix
# ist eine ACL-Entscheidung des Admins. Bei Nur-Lese-Rechten fuer Benutzer
# (Normalfall) schlaegt der Filter nicht an.
$writableByUsers = Get-VsDangerousFileSystemAclEntries -Acl (Get-Acl -Path $PackagesBase)
if ($writableByUsers) {
    Write-Hint ("PackagesBase '{0}' ist fuer normale Benutzer beschreibbar. Der Content laeuft als SYSTEM auf den Clients: Schreibrechte auf Administratoren/SYSTEM begrenzen." -f $PackagesBase)
}

# --- MECM-Site initialisieren -----------------------------------------------
Write-Step 'Initialisiere MECM-Site'
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
function New-VsManagedClientDeploymentType {
    param([Parameter(Mandatory)]$Spec)
    $clauses = @()
    foreach ($val in $Spec.DetectionValues) {
        $clauses += New-CMDetectionClauseRegistryKeyValue -Hive LocalMachine `
            -KeyName $Spec.DetectionKey -ValueName $Spec.DetectionName `
            -PropertyType $Spec.DetectionType -ExpressionOperator IsEquals `
            -ExpectedValue $val -Value
    }
    $dtParams = @{
        ApplicationName = $Spec.AppName
        DeploymentTypeName = ("{0} Deployment" -f $Spec.AppName)
        ContentLocation = (Join-Path $ContentShare $Spec.Folder)
        InstallCommand = (Get-VsClientInstallCommand -Spec $Spec)
        AddDetectionClause = $clauses
        RebootBehavior = 'BasedOnExitCode'
        InstallationBehaviorType = 'InstallForSystem'
    }
    if ($clauses.Count -gt 1) {
        $dtParams['DetectionClauseConnector'] = @(
            for ($i = 1; $i -lt $clauses.Count; $i++) {
                @{ LogicalName = $clauses[$i].Setting.LogicalName; Connector = 'OR' }
            }
        )
    }
    Add-CMScriptDeploymentType @dtParams -ErrorAction Stop | Out-Null
}

foreach ($spec in $specs) {
    try {
        $applicationMatches = @(Get-CMApplication -Name $spec.AppName -ErrorAction Stop)
        if ($applicationMatches.Count -gt 1) { throw ("Applicationname '{0}' ist mehrdeutig." -f $spec.AppName) }
        $existing = if ($applicationMatches.Count -eq 1) { $applicationMatches[0] } else { $null }
        $wasCreated = $false
        if ($null -eq $existing) {
            Write-Ok ("NEU: erstelle Application '{0}'" -f $spec.AppName)
            New-CMApplication -Name $spec.AppName -Description $spec.ManagedMarker -ErrorAction Stop | Out-Null
            Get-CMApplication -Name $spec.AppName -Fast | Move-CMObject -FolderPath $appFolderPath -ErrorAction SilentlyContinue | Out-Null
            $createdMatches = @(Get-CMApplication -Name $spec.AppName -ErrorAction Stop)
            if ($createdMatches.Count -ne 1) { throw ("Neu angelegte Application '{0}' ist nicht eindeutig lesbar." -f $spec.AppName) }
            $existing = $createdMatches[0]
            $wasCreated = $true
        } else {
            Write-Ok ("'{0}' existiert bereits - verwaltete Pflichtteile werden geprueft." -f $spec.AppName)
        }

        if (-not (Test-VsClientApplicationOwnership -Application $existing -Spec $spec -AppFolder $AppFolder)) {
            throw ("Application '{0}' traegt weder den VirtuSphere-Marker noch den eindeutigen Legacy-Ordnernachweis. Gleichnamigen Fremdbestand nicht veraendern." -f $spec.AppName)
        }

        $deploymentTypeName = "{0} Deployment" -f $spec.AppName
        $allDeploymentTypes = @(Get-CMDeploymentType -ApplicationName $spec.AppName -ErrorAction Stop)
        $deploymentTypes = @($allDeploymentTypes | Where-Object { [string]$_.LocalizedDisplayName -eq $deploymentTypeName -or [string]$_.DeploymentTypeName -eq $deploymentTypeName })
        if ($allDeploymentTypes.Count -ne $deploymentTypes.Count) {
            throw ("Application '{0}' enthaelt fremde oder manuell benannte Deployment Types; keine Definition wird blind ueberschrieben." -f $spec.AppName)
        }
        if ($deploymentTypes.Count -eq 0) {
            New-VsManagedClientDeploymentType -Spec $spec
            Write-Ok ("Fehlenden verwalteten Deployment Type fuer '{0}' ergaenzt" -f $spec.AppName)
            $deploymentTypes = @(Get-CMDeploymentType -ApplicationName $spec.AppName -DeploymentTypeName $deploymentTypeName -ErrorAction Stop)
        } elseif ($deploymentTypes.Count -gt 1) {
            throw ("Deployment Type '{0}' ist mehrdeutig; keine Definition wird blind ueberschrieben." -f $deploymentTypeName)
        }
        if ($deploymentTypes.Count -ne 1) { throw ("Deployment Type '{0}' ist nach Reconciliation nicht eindeutig lesbar." -f $deploymentTypeName) }
        $returnCodes = @(Get-CMDeploymentTypeReturnCode -InputObject $deploymentTypes[0] -ErrorAction Stop)
        $contractIssues = @(Get-VsClientDeploymentTypeContractIssues -DeploymentType $deploymentTypes[0] -Spec $spec `
            -ContentLocation (Join-Path $ContentShare $spec.Folder) -InstallCommand (Get-VsClientInstallCommand -Spec $spec) -ReturnCodes $returnCodes)
        if ($contractIssues.Count -gt 0) {
            throw ("Deployment Type '{0}' weicht vom verwalteten Vertrag ab ({1}). Manuelle/fremde Definition bleibt unveraendert." -f $deploymentTypeName, ($contractIssues -join ', '))
        }
        Write-Ok ("'{0}': DT, Detection, Installationskontext, Content und Returncodes entsprechen dem Vertrag" -f $spec.AppName)

        # Content verteilen bzw. aktualisieren (der DP muss die ersetzten Dateien
        # neu ziehen, sonst serviert er die alten). Nicht fatal.
        if ([string]::IsNullOrWhiteSpace($DpGroupName)) {
            # Hinweis: leer heisst laut Parameterhilfe ausdruecklich "der Admin
            # verteilt manuell", also eine Wahl und kein Fehlschlag.
            Write-Hint ("Keine DP-Gruppe angegeben - '{0}' NICHT verteilt. Manuell verteilen." -f $spec.AppName)
        } else {
            try {
                if ($wasCreated) {
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
# getinfo -> hostname -> staticip -> disks. Every re-run verifies the graph;
# otherwise a half-created application remains permanently installable out of
# order. Existing matching groups are preserved and never duplicated.
Write-Step 'Pruefe und repariere Abhaengigkeitskette'
foreach ($spec in $specs) {
    if (-not $spec.DependsOn) { continue }
    try {
        $dtMatches = @(Get-CMDeploymentType -ApplicationName $spec.AppName -ErrorAction Stop)
        $depMatches = @(Get-CMDeploymentType -ApplicationName $spec.DependsOn -ErrorAction Stop)
        if ($dtMatches.Count -ne 1 -or $depMatches.Count -ne 1) { throw 'Deployment-Type-Aufloesung ist nicht eindeutig.' }
        $groupName = "Requires {0}" -f $spec.DependsOn
        $groups = @(Get-CMDeploymentTypeDependencyGroup -InputObject $dtMatches[0] -ErrorAction Stop |
            Where-Object { [string]$_.GroupName -eq $groupName -or [string]$_.LocalizedDisplayName -eq $groupName })
        if ($groups.Count -gt 1) { throw ("Dependency-Gruppe '{0}' ist mehrdeutig." -f $groupName) }
        if ($groups.Count -eq 0) {
            $group = New-CMDeploymentTypeDependencyGroup -InputObject $dtMatches[0] -GroupName $groupName -ErrorAction Stop
            Add-CMDeploymentTypeDependency -DeploymentTypeDependency $depMatches[0] -IsAutoInstall $true -InputObject $group -ErrorAction Stop | Out-Null
            Write-Ok ("Fehlende Abhaengigkeit {0} -> {1} ergaenzt" -f $spec.AppName, $spec.DependsOn)
        } else {
            $dependencies = @(Get-CMDeploymentTypeDependency -InputObject $groups[0] -ErrorAction Stop)
            if ($dependencies.Count -eq 0) {
                Add-CMDeploymentTypeDependency -DeploymentTypeDependency $depMatches[0] -IsAutoInstall $true -InputObject $groups[0] -ErrorAction Stop | Out-Null
                Write-Ok ("Leere Pflichtgruppe repariert: {0} -> {1}" -f $spec.AppName, $spec.DependsOn)
            } elseif ($dependencies.Count -ne 1 -or $null -eq $dependencies[0].CI_ID -or $null -eq $depMatches[0].CI_ID -or [int]$dependencies[0].CI_ID -ne [int]$depMatches[0].CI_ID) {
                throw ("Dependency-Gruppe '{0}' zeigt nicht eindeutig auf den erwarteten Deployment Type; Fremdgraph bleibt unveraendert." -f $groupName)
            } else {
                Write-Ok ("Abhaengigkeitsgruppe {0} -> {1} eindeutig bestaetigt" -f $spec.AppName, $spec.DependsOn)
            }
        }
    } catch {
        Write-Warn ("Abhaengigkeit {0} -> {1} nicht sicher nachweisbar/reparierbar: {2}" -f $spec.AppName, $spec.DependsOn, (Get-VsErrorDetail -ErrorRecord $_))
    }
}

# --- Abschluss --------------------------------------------------------------
# Jeder App-Fehler war eine blosse Warnung, und die gruene Schlusszeile stand
# unbedingt darunter: eine Erstinstallation, bei der keine einzige Application
# entstanden ist, meldete "bereit" und endete mit 0. Die Zahl steht in der
# Zeile, weil ein Exit-Code fuer einen Menschen vor der Konsole unsichtbar ist.
Write-Host ''
if ($script:VsInstallBlockers -eq 0) {
    Write-Host 'Client-Applikationen bereit.' -ForegroundColor Green
} else {
    Write-Host ('Client-Applikationen mit {0} offene(n) Punkt(en) - die mit "!!" markierten Zeilen oben pruefen.' -f $script:VsInstallBlockers) -ForegroundColor Yellow
}
Write-Host ''
Write-Host 'Naechste Schritte:' -ForegroundColor Gray
Write-Host ('  1. In der Konsole unter Softwarebibliothek > Anwendungen > {0} die vier Apps pruefen (Detection, Abhaengigkeitskette, verteilter Content).' -f $AppFolder) -ForegroundColor Gray
Write-Host '  2. WICHTIG bei client_hostname: dass die Detection BEIDE Werte als ODER prueft - Status = "Erfolgreich" ODER "Uebersprungen". Nur mit beiden wird ein domaenengebundener Client (der "Uebersprungen" schreibt) je als installiert erkannt, sonst laeuft er endlos. Der DetectionClauseConnector wird per Skript gesetzt, ist aber die eine CM-Stelle, die hier nicht testbar war - in der Konsole gegenpruefen.' -ForegroundColor Gray
Write-Host '  3. Deployment an die Ziel-Collection(s) anlegen (Required) - das macht der Admin bewusst, dieses Skript deployt nicht.' -ForegroundColor Gray
Write-Host '  4. Bei client_hostname pruefen, dass der Rueckgabecode 1641 als "Erfolg mit Neustart" gilt (MECM-Standardtabelle deckt das ab).' -ForegroundColor Gray

# Maschinenlesbare Fassung der Schlusszeile. Der Content-Teil weiter oben bricht
# bei einem Fehler weiterhin hart ab ($ErrorActionPreference = 'Stop'); der
# Zaehler betrifft nur die CM-Phase.
if ($script:VsInstallBlockers -gt 0) { exit 1 }
exit 0
