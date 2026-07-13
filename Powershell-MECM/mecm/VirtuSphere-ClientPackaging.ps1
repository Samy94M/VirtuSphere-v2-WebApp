# Reine, testbare Bausteine fuer install-VirtuSphere-Clients.ps1.
#
# Bewusst OHNE #Requires und OHNE CM-Cmdlets: keine Seiteneffekte beim Laden,
# damit Pester die Funktionen dot-sourcen und pruefen kann. Die CM-Orchestrierung
# (Applikationen anlegen, Content verteilen) lebt im Hauptskript, das dieses hier
# dot-sourct und das ConfigurationManager-Modul braucht.
Set-StrictMode -Version 1.0

# Die vier Client-Applikationen als Datentabelle - die SSoT fuer das
# Paket-Skript. DetectionKey/-Name/-Values MUESSEN exakt das sein, was das
# jeweilige Client-Skript zur Laufzeit in die Registry schreibt: stimmt es nicht,
# gilt die App nie als "installiert" und MECM fuehrt sie endlos erneut aus. Der
# Pester-Contract-Test prueft genau diese Werte gegen den Skript-Quelltext.
#
# DetectionKey ist relativ zu HKLM (ohne "HKLM:\"), wie es
# New-CMDetectionClauseRegistryKeyValue -Hive LocalMachine -KeyName erwartet.
# Reihenfolge = Ausfuehrungsreihenfolge; DependsOn verdrahtet die Kette.
function Get-VsClientAppSpecs {
    @(
        [pscustomobject]@{
            AppName         = 'client_getInfos'
            Folder          = 'client_getInfos'
            Script          = 'client_getinfo.ps1'
            DetectionKey    = 'SOFTWARE\VirtuSphere'
            DetectionName   = 'SetupState'
            DetectionValues = @('complete')
            DetectionType   = 'String'
            DependsOn       = $null
        }
        [pscustomobject]@{
            AppName         = 'client_hostname'
            Folder          = 'client_hostname'
            Script          = 'client_hostname.ps1'
            DetectionKey    = 'SOFTWARE\aplw-cgn\HostnameUpdate'
            DetectionName   = 'Status'
            # Zwei Erfolgswerte: 'Erfolgreich' (umbenannt/bereits korrekt) und
            # 'Uebersprungen' (Domaenen-Computer). Ohne den zweiten Wert wuerde ein
            # domaenengebundener Client nie als installiert erkannt und liefe endlos.
            DetectionValues = @('Erfolgreich', 'Uebersprungen')
            DetectionType   = 'String'
            DependsOn       = 'client_getInfos'
        }
        [pscustomobject]@{
            AppName         = 'client_staticip'
            Folder          = 'client_staticip'
            Script          = 'client_staticip.ps1'
            DetectionKey    = 'SOFTWARE\APLw-CGN\staticip'
            DetectionName   = 'installed'
            # Als DWORD 1 geschrieben ([int]$Success). PropertyType MUSS 'Int64'
            # sein - New-CMDetectionClauseRegistryKeyValue kennt kein 'Integer'
            # (gueltig: String/Boolean/DateTime/Double/Int64/Version), sonst wirft
            # die Detection-Erstellung am Server.
            DetectionValues = @('1')
            DetectionType   = 'Int64'
            DependsOn       = 'client_hostname'
        }
        [pscustomobject]@{
            AppName         = 'client_VMDisksOnline'
            Folder          = 'client_VMDisksOnline'
            Script          = 'Set-VMDisksOnline.ps1'
            DetectionKey    = 'SOFTWARE\VirtuSphere\VMDiskManagement'
            DetectionName   = 'VMDisksOnlineStatus'
            DetectionValues = @('Success')
            DetectionType   = 'String'
            DependsOn       = 'client_staticip'
        }
    )
}

# Stellt den Content EINES App-Ordners bereit: das Client-Skript UND
# VirtuSphere-Client-Common.ps1. Die Skripte dot-sourcen Common aus ihrem eigenen
# $PSScriptRoot, also muss sie in JEDEN der vier Ordner. Ueberschreibt bestehende
# Dateien (Entscheidung #1: ersetzen). Liefert den Zielordner.
function Copy-VsClientContent {
    param(
        [Parameter(Mandatory)][pscustomobject]$Spec,
        [Parameter(Mandatory)][string]$SourceDir,
        [Parameter(Mandatory)][string]$PackagesBase
    )
    $scriptSource = Join-Path $SourceDir $Spec.Script
    $commonSource = Join-Path $SourceDir 'VirtuSphere-Client-Common.ps1'
    foreach ($src in @($scriptSource, $commonSource)) {
        if (-not (Test-Path $src)) { throw "Quelldatei fehlt: $src (SourceDir stimmt?)" }
    }

    $dest = Join-Path $PackagesBase $Spec.Folder
    if (-not (Test-Path $dest)) { New-Item -ItemType Directory -Path $dest -Force | Out-Null }
    Copy-Item -Path $scriptSource -Destination $dest -Force
    Copy-Item -Path $commonSource -Destination $dest -Force
    return $dest
}

# Die Programm-Befehlszeile eines Deployment-Types (eine Stelle, damit Skript und
# Test dieselbe Zeichenkette nutzen).
function Get-VsClientInstallCommand {
    param([Parameter(Mandatory)][pscustomobject]$Spec)
    return ('powershell.exe -ExecutionPolicy Bypass -File "{0}"' -f $Spec.Script)
}
