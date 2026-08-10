# Kein param-Block: der frueher hier reservierte Schalter -repair wurde von
# niemandem gesetzt und von diesem Skript nie gelesen. Ein Parameter, der nichts
# tut, ist eine Zusage an den Paketautor, die das Skript nicht einhaelt.
# Aufgerufen wird immer parameterlos:
#   powershell.exe -ExecutionPolicy Bypass -File install.ps1

# Set-StrictMode: ein vertippter Variablenname ist sonst ein stilles $null.
# Dieses Skript laeuft als SYSTEM und startet fremde Teilskripte; ein stilles
# $null entscheidet hier ueber Registry-Zweig und Detection-Wert. Version 1.0
# aus demselben Grund wie in VirtuSphere-Common.ps1: ab 2.0 wuerde auch der
# Zugriff auf ein legitim fehlendes JSON-Feld werfen.
#
# Fuer die Teilskripte aendert sich nichts: sie laufen ueber
# & PowerShell.exe -File in einem eigenen Prozess, StrictMode wirkt nicht hinein.
# $LASTEXITCODE wird erst NACH dem ersten &-Aufruf gelesen, ist dort also
# gesetzt; eine spaetere Umstellung auf Version 2.0 muesste das pruefen.
Set-StrictMode -Version 1.0

# Set-Location: Wechselt das Arbeitsverzeichnis auf den Ordner, in dem install.ps1 liegt.
# $PSScriptRoot: Automatische Variable - enthaelt immer den vollstaendigen Pfad des aktuellen Skripts.
# Wichtig damit alle relativen Pfade (.\powershell\, .\config.json) korrekt aufgeloest werden.
Set-Location $PSScriptRoot

# Konfigurationsdatei einlesen und PRUEFEN, bevor irgendein Teilskript laeuft.
#
# Ohne diese Pruefung lief das Skript mit $config = $null weiter: der
# Registry-Pfad wurde zu "...\Packages\-", alle Teilskripte liefen trotzdem als
# SYSTEM, und der Wrapper konnte mit 0 enden. MECM faengt das am Ende ueber die
# nicht erfuellte Detection ab - da sind die Skripte aber schon gelaufen.
# Dasselbe Skript hat weiter unten einen ausfuehrlich begruendeten Guard dafuer,
# dass ein leerer powershell-Ordner ein Fehlschlag ist; fuer seine eigene
# Konfigurationsdatei hatte es keinen.
#
# Bewusst ohne Helfer aus VirtuSphere-Common.ps1: diese Datei wird einzeln in
# den Paketordner kopiert und laeuft dort ohne die Bibliothek (ADR-0029). Die
# Pflichtfelder sind dieselben, die Read-VsPackageConfig serverseitig prueft;
# ein Test haelt beide Listen gegeneinander.
$configPath = ".\config.json"
if (-not (Test-Path $configPath)) {
    Write-Host "config.json fehlt im Paketordner ($PSScriptRoot) - ohne sie ist weder der Registry-Pfad noch die Version bestimmbar. Installation abgebrochen." -ForegroundColor Red
    exit 1
}
try {
    $config = Get-Content $configPath -Raw | ConvertFrom-Json
} catch {
    Write-Host "config.json ist kein gueltiges JSON ($configPath): $($_.Exception.Message). Installation abgebrochen." -ForegroundColor Red
    exit 1
}
if (-not $config -or -not $config.ProjectName -or -not $config.version) {
    Write-Host "config.json ohne ProjectName/version ($configPath) - beide bilden den Registry-Pfad und den Detection-Wert. Installation abgebrochen." -ForegroundColor Red
    exit 1
}

# Skriptverzeichnis und Konfigurationswerte aus der config.json uebernehmen
$scriptDirectory = ".\powershell\"
$projectName     = $config.ProjectName

# $ErrorAction: Steuert das Verhalten bei Skriptfehlern.
# Moegliche Werte aus config.json:
#   "Stop"     - Bricht die gesamte Installation ab sobald ein Skript fehlschlaegt
#   "Continue" - Faehrt mit dem naechsten Skript fort auch wenn eines fehlschlaegt
$ErrorAction = $config.ErrorAction

# Registrierungspfad je nach Installationstyp setzen
# Erlaubt sind genau zwei Werte (SSoT: $script:VsInstallationBehaviorTypes in
# VirtuSphere-Common.ps1, das diese Datei nie sieht - sie wird allein in den
# Paketordner kopiert):
# InstallForSystem: Installation fuer alle Benutzer - Registry unter HKLM (HKEY_LOCAL_MACHINE)
# InstallForUser:   Installation nur fuer den aktuellen Benutzer - Registry unter HKCU (HKEY_CURRENT_USER)
# Der Pfad enthaelt ProjectName und Version damit verschiedene Versionen nebeneinander
# in der Registry erfasst werden koennen ohne sich zu ueberschreiben.
#
# Auf InstallForUser geprueft, nicht auf InstallForSystem: der Autoimporter legt
# die Detection-Klausel genau so herum an. Andersherum fielen beide Seiten bei
# einem fehlenden Feld in verschiedene Zweige - MECM suchte in HKLM, dieses
# Skript schrieb nach HKCU, und die App galt nie als installiert.
if($config.InstallationBehaviorType -eq "InstallForUser"){
    $registryPath = "HKCU:\Software\VirtuSphere\Packages\$($projectName)-$($config.version)"
} else {
    $registryPath = "HKLM:\Software\VirtuSphere\Packages\$($projectName)-$($config.version)"
}

# Verzeichnis fuer Log-Dateien der einzelnen Teilskripte
# $env:ProgramFiles: Umgebungsvariable - zeigt auf C:\Program Files
$logDirectory = "$($env:ProgramFiles)\VirtuSphere\Logs\"

############ Ab hier nichts aendern

# $Fullsuccess: Gesamtstatus der Installation.
# Startet als $true. Wird auf $false gesetzt sobald ein Teilskript fehlschlaegt.
# Entscheidet am Ende ob der MECM-Detection-Key (Version) in die Registry geschrieben wird.
$Fullsuccess = $true

Write-Host @"



    A      P P P    L       W   W   W
   A A     P     P  L       W   W   W
  AAAAA    P P P    L       W W W W W
 A     A   P        L       W W W W W
A       A  P        L L L   W   W   W


VirtuSphere
install.ps1
"@

write-host "ErrorAction: $ErrorAction`n`n"
write-host "install.ps1 beginn" -ForegroundColor Magenta
write-host "----------------------------" -ForegroundColor Magenta

# Registry-Schluessel erstellen falls noch nicht vorhanden
# Test-Path: Prueft ob ein Pfad (Datei, Ordner oder Registry-Key) existiert. Gibt $true oder $false zurueck.
# New-Item: Erstellt einen neuen Eintrag - hier einen Registry-Schluessel.
# -Force: Erstellt auch fehlende uebergeordnete Schluessel automatisch mit.
if (!(Test-Path $registryPath)) {
    New-Item -Path $registryPath -Force
}

# Log-Verzeichnis erstellen falls nicht vorhanden
# -ItemType Directory: Gibt an dass ein Ordner erstellt werden soll, kein Schluessel oder Datei.
if (!(Test-Path $logDirectory)) {
    New-Item -Path $logDirectory -ItemType Directory -Force
}

# Alle PowerShell-Skripte im powershell-Unterordner alphabetisch abarbeiten
# Get-ChildItem: Listet Dateien und Ordner auf - entspricht dem dir-Befehl in CMD.
# -Filter *.ps1: Nur Dateien mit der Endung .ps1 werden zurueckgegeben.
# Sort-Object Name: Sortiert die Ergebnisse alphabetisch nach Dateiname.
#   Wichtig: 01.check-dcready.ps1 muss vor 02.dc-dns-konfig.ps1 laufen.
#   Die Nummerierung im Dateinamen steuert die Reihenfolge.
$dir_script = Get-ChildItem $scriptDirectory -Filter *.ps1 | Sort-Object Name

# foreach-Statement statt "| ForEach-Object": beide teilen sich zwar denselben
# Scope (das Setzen von $Fullsuccess wirkt in beiden Varianten nach aussen), aber
# nur beim foreach-Statement ist das auch ohne PowerShell-Detailwissen ablesbar.
# Genau diese Frage - "wirkt das $false ueberhaupt nach draussen?" - entscheidet
# hier darueber, ob MECM eine fehlgeschlagene Installation als Erfolg meldet.
foreach ($scriptFile in $dir_script) {
    # Zeitstempel fuer den Log-Dateinamen
    # Get-Date -Format: Formatiert das aktuelle Datum und die Uhrzeit als Text.
    # Das Format yyyy-MM-dd_HH-mm-ss erzeugt z.B. "2025-04-08_14-30-00"
    # Bindestriche statt Doppelpunkte weil Doppelpunkte in Dateinamen unter Windows nicht erlaubt sind.
    $currentDateTime = Get-Date -Format "yyyy-MM-dd_HH-mm-ss"

    $scriptName      = $scriptFile.Name        # z.B. "01.check-dcready.ps1"
    $scriptFullPath  = $scriptFile.FullName    # Vollstaendiger Pfad inkl. Laufwerk und Ordner
    $logPath         = Join-Path $logDirectory "$($scriptName)_$currentDateTime.log"
    # Join-Path: Verbindet Pfadteile korrekt mit dem richtigen Trennzeichen (\).
    # Ergebnis z.B.: C:\Program Files\VirtuSphere\Logs\01.check-dcready.ps1_2025-04-08_14-30-00.log

    # Registry-Wertname pro Skript - enthaelt Projektname und Skriptname
    # Jedes Teilskript bekommt seinen eigenen Eintrag unter dem Registry-Schluessel.
    # Beispiel: "DC-Setup-01.check-dcready.ps1"
    $registryValueName = "$projectName-$scriptName"

    # Pruefen ob das Skript bereits erfolgreich ausgefuehrt wurde
    # Get-ItemPropertyValue: Liest den Wert eines Registry-Eintrags.
    # -ea 0: Kurzform fuer -ErrorAction SilentlyContinue - Fehler werden still ignoriert.
    # Wenn der Wert nicht existiert wirft Get-ItemPropertyValue eine Exception,
    # die der catch-Block abfaengt und $status auf "not installed" setzt.
    $fullregistrypath = "$registryPath"
    if(Test-Path -Path "$fullregistrypath"){
        try{
            $status = Get-ItemPropertyValue -Path $registryPath -Name $registryValueName -ea 0
        } catch {
            $status = "not installed"
        }
    } else {
        $status = "not installed"
    }

    write-host "Status $scriptName : $status" -ForegroundColor Gray

    # Skript ausfuehren wenn es noch nicht erfolgreich war
    # -notlike "Erfolg*": Der *-Platzhalter steht fuer beliebige Zeichen.
    # Prueft ob der gespeicherte Status NICHT mit "Erfolg" beginnt.
    # Gespeichertes Format ist "Erfolg - 2025-04-08 14:30:00" - daher der Platzhalter.
    if ($status -eq "not installed" -or $status -notlike "Erfolg*") {

        try {
            Write-Host "Fuehre Skript aus." -ForegroundColor Green

            # Skript in einem neuen PowerShell-Prozess ausfuehren
            # & (Aufrufoperator): Fuehrt einen Befehl oder eine Datei aus.
            # PowerShell.exe -ExecutionPolicy Bypass: Startet einen neuen PowerShell-Prozess
            #   und umgeht die Ausfuehrungsrichtlinie fuer diesen Prozess.
            # -File: Gibt an dass eine Skriptdatei ausgefuehrt werden soll.
            # *> $logPath: Leitet alle Ausgaben (stdout und stderr) in die Log-Datei um.
            & PowerShell.exe -ExecutionPolicy Bypass -File $scriptFullPath *> $logPath

            # $LASTEXITCODE: Automatische Variable - enthaelt den Exit-Code des zuletzt
            # ausgefuehrten externen Prozesses (hier: PowerShell.exe).
            #
            # Bedeutung der Erfolgs-Exit-Codes:
            #   0    - Erfolgreich abgeschlossen, kein Neustart notwendig
            #   1707 - MSI-Installationspaket erfolgreich installiert
            #   3010 - Erfolgreich, aber ein Neustart wird empfohlen
            #   1641 - Erfolgreich, Neustart wurde eingeleitet
            #
            # Alle anderen Exit-Codes werden als Fehler gewertet.
            # Exit-Code 1 ist ein generischer Windows-Fehlercode und bedeutet FEHLER.
            if ($LASTEXITCODE -eq 0 -or $LASTEXITCODE -eq 1707 -or $LASTEXITCODE -eq 3010 -or $LASTEXITCODE -eq 1641) {
                $success = "Erfolg"
                Write-Host "Skript $scriptName wurde erfolgreich durchgelaufen. Exit-Code: $LASTEXITCODE" -ForegroundColor Green
            } else {
                $success = "Fehler"
                $Fullsuccess = $false
                Write-Host "Fehler beim Ausfuehren von Skript $scriptName. Exit-Code: $LASTEXITCODE" -ForegroundColor Red

                # Verhalten bei Fehler abhaengig von ErrorAction in config.json
                if($ErrorAction -eq "Stop"){
                    $Fullsuccess = $false
                    write-host "ErrorAction ist auf $ErrorAction. Exit" -ForegroundColor DarkYellow
                    # exit 1: Beendet install.ps1 sofort mit Fehlercode 1.
                    # MECM wertet jeden Exit-Code ungleich 0 als fehlgeschlagene Installation.
                    # Die Detection Clause wird nicht erfuellt - MECM zeigt "Fehler" an.
                    exit 1
                } else {
                    write-host "ErrorAction ist auf $ErrorAction. Fahre mit naechstem Skript fort..." -ForegroundColor DarkYellow
                }
            }

            write-host "Log: $logPath" -ForegroundColor Cyan

        } catch {
            # catch: Faengt Fehler ab die PowerShell selbst ausloest (z.B. Datei nicht gefunden).
            # Wird nicht ausgeloest durch exit-Codes der Teilskripte - nur durch echte PS-Exceptions.
            $success = "Fehler"
            $Fullsuccess = $false
        }

        # Ergebnis des Skripts in der Registry speichern
        # Set-ItemProperty: Schreibt einen Wert in einen Registry-Schluessel.
        # Format: "Erfolg - 2025-04-08 14:30:00" oder "Fehler - 2025-04-08 14:30:00"
        # Beim naechsten Aufruf von install.ps1 wird dieser Wert gelesen.
        # Skripte mit Status "Erfolg*" werden uebersprungen (Skip-Logik oben).
        $currentDateTime = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
        Set-ItemProperty -Path $registryPath -Name $registryValueName -Value "$success - $currentDateTime"

    } else {
        write-host "Skript $scriptFullPath wurde bereits erfolgreich ausgefuehrt. Skip" -ForegroundColor Gray
    }
}

# Ein leerer powershell-Ordner ist ein FEHLSCHLAG, keine Warnung.
#
# Vorher wurde nur rot geschrieben und danach trotzdem der Detection-Wert
# gesetzt: MECM meldete das Paket als installiert, obwohl nichts installiert
# wurde. Genau die Lage, in die ein Paket geraet, dessen Inhalt beim Kopieren
# fehlte oder dessen Content-Verteilung nicht durchlief - und weil die Erkennung
# erfuellt war, versuchte MECM es nie erneut.
if((Get-ChildItem $scriptDirectory -Filter *.ps1 -ErrorAction SilentlyContinue).count -eq 0){
    write-host "Keine Skripte im Ordner $scriptDirectory gefunden - Installation gilt als fehlgeschlagen." -ForegroundColor Red
    $Fullsuccess = $false
}

# MECM Detection Clause schreiben
# Nur wenn ALLE Skripte erfolgreich waren wird der Version-Wert in die Registry geschrieben.
# MECM prueft nach der Installation genau diesen Registry-Wert als Detection Clause:
#   Pfad:  HKLM\Software\VirtuSphere\Packages\{ProjectName}-{Version}
#   Wert:  Version = "{version}"
# Existiert dieser Wert, markiert MECM die Application als "Installiert".
# Fehlt er (weil $Fullsuccess = $false), zeigt MECM "Fehler" an.
if($Fullsuccess){
    Set-ItemProperty -Path $registryPath -Name "Version" -Value $config.version
}

write-host "----------------------------" -ForegroundColor Magenta
write-host "install.ps1 finish" -ForegroundColor Magenta

# Gesamtergebnis als Exit-Code zurueckgeben
# exit 0: Alle Skripte erfolgreich - MECM wertet die Installation als erfolgreich
# exit 1: Mindestens ein Skript fehlgeschlagen - MECM zeigt Fehler an
if($Fullsuccess){
    exit 0
} else {
    exit 1
}