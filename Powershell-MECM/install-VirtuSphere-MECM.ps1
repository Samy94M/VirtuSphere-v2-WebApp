#Requires -Version 5.1
#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Erstinstallation der VirtuSphere-MECM-Integration auf dem MECM-Server.

.DESCRIPTION
    Schreibt die Konfiguration in die Registry, legt Verzeichnisse an, beendet
    laufende Aufgaben, kopiert die Sync-Skripte nach
    %ProgramFiles%\VirtuSphere\mecm, registriert die vier geplanten Aufgaben
    (SYSTEM, hoechste Rechte, ohne Profil, beim Systemstart UND stuendlich,
    ohne Laufzeitlimit) und verifiziert die Erstinstallation.

    Idempotent: erneutes Ausfuehren aktualisiert Konfiguration und Skripte; die
    vier Intervalle behalten dabei ihren eingestellten Wert, wenn der jeweilige
    Parameter nicht angegeben wird (siehe .NOTES).

.PARAMETER WebApi
    Adresse der VirtuSphere-WebApp, z. B. "virtusphere.lan:8021" oder "10.0.0.5:8021".

.PARAMETER PackagesRoot
    Wurzel der Paketablage auf dem MECM-Server. Standard: D:\VirtuSphere\Packages.

.PARAMETER PackagesShare
    UNC-Pfad auf den files-Ordner (ContentLocation der MECM-Applications),
    z. B. \\MECM-SERVER\VirtuSphere\Packages\files.

.PARAMETER Scheme
    Schema der WebAPI: "http" (LAN-Default) oder "https", sobald das Portal auf
    TLS umgestellt ist. Die Maschinen-API ist vom HTTP->HTTPS-Redirect zwar
    ausgenommen, aber wer HTTP abschaltet, schaltet ohne diesen Schalter die
    gesamte MECM-Integration mit ab.

.PARAMETER ReportToken
    Optionaler Rueckkanal-Token (im Portal generiert). Leer = ohne Token.

.PARAMETER DpGroupName
    Name der Distribution-Point-Gruppe fuer die Content-Verteilung.

.PARAMETER ProviderMachine
    Optionaler Rechnername des SMS-Providers fuer die Site-Health-Abfrage. Leer
    lassen, wenn der MECM-Server selbst der Provider ist: das Site-Health-Skript
    erkennt ihn dann per WMI/PSDrive. Ein Re-Run ohne diesen Parameter BEHAELT
    einen zuvor gesetzten Wert.

.PARAMETER DeviceSyncIntervalSeconds
    Abfrageintervall des Device-Sync-Tasks in Sekunden (5..3600, Standard 10).

.PARAMETER PackagesSyncIntervalSeconds
    Abfrageintervall des Packages-Sync-Tasks in Sekunden (10..3600, Standard 60).

.PARAMETER ImporterIntervalSeconds
    Abfrageintervall des Autoimporters in Sekunden (30..3600, Standard 60).

.PARAMETER SiteHealthIntervalSeconds
    Abfrageintervall des Site-Health-Tasks in Sekunden (60..3600, Standard 300).

.EXAMPLE
    .\install-VirtuSphere-MECM.ps1 -WebApi virtusphere.lan:8021 `
        -PackagesShare \\MECM-01\VirtuSphere\Packages\files

.NOTES
    Die vier Intervalle behalten bei einem Re-Run ohne den jeweiligen Parameter
    ihren eingestellten Wert (wie -ProviderMachine). Ein Skript-Update setzt
    einen bewusst getunten Takt also nicht auf den Standard zurueck.
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$WebApi,
    [ValidateSet('http', 'https')][string]$Scheme = 'http',
    # SHA-1-Fingerabdruck des Portal-Zertifikats, ohne Trennzeichen (so wie
    # certlm.msc ihn anzeigt). Nur mit -Scheme https sinnvoll und dann der EINZIGE
    # vorgesehene Weg, einem selbstsignierten Zertifikat zu vertrauen: hinterlegt
    # statt Pruefung abgeschaltet. Leer lassen, wenn das Zertifikat aus einer PKI
    # kommt, der dieser Server schon vertraut.
    [ValidatePattern('^([0-9A-Fa-f]{40})?$')][string]$CertThumbprint = '',
    [string]$PackagesRoot = 'D:\VirtuSphere\Packages',
    [Parameter(Mandatory)][string]$PackagesShare,
    [string]$ReportToken = '',
    [string]$DpGroupName = 'DP Group - VirtuSphere-Applications',
    # Die Spannen spiegeln $script:VsIntervalBounds in mecm\VirtuSphere-Common.ps1
    # (Untergrenze je Aufgabe, Obergrenze aus dem Wire-Contract). Hier abzulehnen
    # statt spaeter still zu klemmen: sonst laeuft die Aufgabe in einem anderen
    # Takt als dem, den der Administrator gesetzt und die Statusseite zeigt.
    [ValidateRange(5, 3600)][int]$DeviceSyncIntervalSeconds = 10,
    [ValidateRange(10, 3600)][int]$PackagesSyncIntervalSeconds = 60,
    [ValidateRange(30, 3600)][int]$ImporterIntervalSeconds = 60,
    [string]$ProviderMachine = '',
    [ValidateRange(60, 3600)][int]$SiteHealthIntervalSeconds = 300
)

$ErrorActionPreference = 'Stop'
# Version 1.0, nicht Latest: siehe die Begruendung in mecm\VirtuSphere-Common.ps1
# (die Sync-Skripte lesen JSON mit legitim fehlenden Feldern).
Set-StrictMode -Version 1.0
$registryPath = 'HKLM:\SOFTWARE\VirtuSphere\MECM'
$installDir = Join-Path $env:ProgramFiles 'VirtuSphere\mecm'
$logRoot = Join-Path $env:ProgramFiles 'VirtuSphere\Logs'

# Zwei Klassen von Warnung, und die Einordnung steht an der Aufrufstelle statt
# in einer zentralen Liste: nur so ist sie beim Lesen der Zeile sichtbar und im
# Test pruefbar.
#
# Write-Warn = BLOCKER. Die Erstinstallation hat ihre Arbeit nicht geleistet:
#   Aufgabe laeuft nicht, Portal antwortet nicht oder mit 403, kein frisches
#   Log, Freigabe zeigt nicht auf den Paketpfad. Faerbt die Schlusszeile und
#   setzt den Exit-Code.
# Write-Hint = HINWEIS. Etwas ist bemerkenswert, aber der Lauf ist trotzdem
#   gelungen: die DP-Gruppe darf legitim erst spaeter entstehen, DNS loest hier
#   anders auf als im Deploy-VLAN, und ein nicht abfragbarer Site-Health-Provider
#   heisst laut Common ausdruecklich "nicht abfragbar", nicht "Site krank".
#
# Ohne diese Trennung waere ein blindes Mitzaehlen aller Warnungen die falsche
# Korrektur: ein voellig korrekter Erstlauf ginge gelb.
$script:VsInstallBlockers = 0
function Write-Step { param([string]$Message) ; Write-Host "==> $Message" -ForegroundColor Cyan }
function Write-Ok   { param([string]$Message) ; Write-Host "    OK  $Message" -ForegroundColor Green }
function Write-Warn {
    param([string]$Message)
    $script:VsInstallBlockers++
    # Ueber Write-VsLog statt Write-Host: das Konsolenfenster ueberlebt den
    # Feierabend nicht, und eine Erstinbetriebnahme wird oft erst am naechsten
    # Tag nachvollzogen.
    Write-VsLog -Level WARN -Context 'setup' -Message ("    !!  {0}" -f $Message) -Color Yellow
}
function Write-Hint {
    param([string]$Message)
    Write-VsLog -Level INFO -Context 'setup' -Message ("    ~~  {0}" -f $Message) -Color DarkYellow
}

# Common frueh dot-sourcen: liefert Convert-VsWebApi (Normalisierung) sowie
# Get-VsErrorDetail/-StatusCode fuer die spaetere Verifikation. Nur Funktionen und
# $script:-Variablen, keine Schleife - unschaedlich.
. (Join-Path $PSScriptRoot 'mecm\VirtuSphere-Common.ps1')

# Vor der ersten Warnung, damit auch sie im Tageslog landet.
Initialize-VsLog -Component 'setup' -LogRoot $logRoot

# --- WebApi normalisieren (host:port, kein Schema/Pfad) ---------------------
$WebApi = Convert-VsWebApi $WebApi
$webApiHost = ($WebApi -split ':', 2)[0]
$ipRef = [System.Net.IPAddress]::Any
$webApiIsIp = [System.Net.IPAddress]::TryParse($webApiHost, [ref]$ipRef)

# --- Rueckkanal-Token -------------------------------------------------------
# Bestehenden Token lesen, BEVOR irgendetwas geschrieben wird: ein Re-Run ohne
# -ReportToken soll den konfigurierten Token BEHALTEN, nicht loeschen. (Frueher
# wischte New-Item -Force die Werte, und ein leerer Parameter ueberschrieb den
# Token mit Leer - ein Re-Run zum Aendern eines Intervalls kappte still den
# Rueckkanal.)
$existingToken = ''
if (Test-Path $registryPath) {
    try { $existingToken = [string](Get-ItemProperty -Path $registryPath -Name 'ReportToken' -ErrorAction Stop).ReportToken } catch { Write-Debug $_ }
}
$tokenExists = -not [string]::IsNullOrEmpty($existingToken)

# Sichere interaktive Eingabe statt Klartext-CLI-Argument (History/Prozessliste).
#
# Auf $PSBoundParameters, nicht auf den Wert: `-ReportToken ''` fiel sonst in
# die Abfrage, obwohl der Aufrufer den Parameter ausdruecklich genannt hat.
if (-not $PSBoundParameters.ContainsKey('ReportToken')) {
    if ([Environment]::UserInteractive) {
        $tokenPrompt = if ($tokenExists) { 'Rueckkanal-Token (leer lassen = bestehenden behalten)' } else { 'Rueckkanal-Token (im Portal generiert, leer lassen fuer ohne Token)' }
        $secureToken = Read-Host -AsSecureString $tokenPrompt
        $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureToken)
        try {
            $ReportToken = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
        } finally {
            [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
        }
    }
} else {
    Write-Warning 'ReportToken wurde als Kommandozeilen-Argument uebergeben und ist damit in der PowerShell-History und Prozessliste sichtbar. Fuer Produktivumgebungen das Skript ohne -ReportToken starten und den Token interaktiv eingeben.'
}

# Effektiver Token. Drei Ausgaenge, und alle drei werden protokolliert, nie der
# Wert selbst:
#
#   behalten  - der Parameter wurde nicht genannt und es gibt einen Token.
#   ersetzt   - ein neuer Wert kam (per Parameter oder aus der Abfrage).
#   geloescht - der Parameter wurde ausdruecklich leer uebergeben.
#
# Die Bedingung liest $PSBoundParameters, nicht nur den Wert. Vorher stellte
# diese Zeile den alten Token schon wieder her, bevor die Erhaltungslogik weiter
# unten ueberhaupt nachschauen konnte, ob '' ausdruecklich kam: ein einmal
# gesetzter Token liess sich nur noch durch Loeschen des Registry-Werts
# entfernen, waehrend der Kommentar dort das Gegenteil zusagte.
#
# Die Loeschung ist auf WARN-Ebene sichtbar: ein still verschwindender
# Rueckkanal ist genau der Fehler, den die Erhaltungslogik urspruenglich behoben
# hat, also muss auch die GEWOLLTE Loeschung im Tageslog stehen. Sie geht
# bewusst nicht ueber Write-Warn: sie ist eine ausdrueckliche Bedienhandlung und
# kein offener Punkt, und ein Blocker wuerde den Installer dafuer mit 1 enden
# lassen.
if (-not $PSBoundParameters.ContainsKey('ReportToken') -and [string]::IsNullOrEmpty($ReportToken) -and $tokenExists) {
    $ReportToken = $existingToken
    Write-Ok 'Bestehenden Rueckkanal-Token behalten (kein neuer uebergeben).'
} elseif ($PSBoundParameters.ContainsKey('ReportToken') -and [string]::IsNullOrEmpty($ReportToken) -and $tokenExists) {
    Write-VsLog -Level WARN -Context 'setup' -Message '    !!  Rueckkanal-Token wird GELOESCHT (-ReportToken ausdruecklich leer uebergeben). Die Sync-Tasks melden ab sofort ohne Authentisierung.' -Color Yellow
} elseif (-not [string]::IsNullOrEmpty($ReportToken)) {
    Write-Ok 'Rueckkanal-Token gesetzt.'
}

# Provider-Rechner ebenso behandeln wie den Token: ein Re-Run ohne
# -ProviderMachine soll einen zuvor gesetzten Wert BEHALTEN, nicht mit Leer
# ueberschreiben. Nur schreiben, wenn ein Wert vorliegt (create-if-missing).
$existingProvider = ''
if (Test-Path $registryPath) {
    try { $existingProvider = [string](Get-ItemProperty -Path $registryPath -Name 'MECM_ProviderMachine' -ErrorAction Stop).MECM_ProviderMachine } catch { Write-Debug $_ }
}
if ([string]::IsNullOrWhiteSpace($ProviderMachine) -and -not [string]::IsNullOrWhiteSpace($existingProvider)) {
    $ProviderMachine = $existingProvider
    Write-Ok 'Bestehenden SMS-Provider-Rechner behalten (kein neuer uebergeben).'
}

# --- Voraussetzungen --------------------------------------------------------
Write-Step 'Pruefe Voraussetzungen'
if (-not $env:SMS_ADMIN_UI_PATH) {
    throw 'SMS_ADMIN_UI_PATH nicht gesetzt - die MECM-Konsole muss installiert sein und dieses Skript auf dem MECM-Server laufen.'
}
Write-Ok 'MECM-Konsole gefunden'

$siteCode = $null
try {
    $ns = Get-CimInstance -Namespace 'root\SMS' -ClassName '__NAMESPACE' -ErrorAction Stop |
        Where-Object { $_.Name -like 'site_*' } | Select-Object -First 1
    if ($ns) { $siteCode = $ns.Name -replace '^site_', '' }
} catch { Write-Debug $_ }
# Hinweis, kein Blocker: die Skripte ermitteln den Site-Code zur Laufzeit selbst
# ueber WMI bzw. PSDrive; der Installer braucht ihn nur fuer seine eigene Anzeige.
if ($siteCode) { Write-Ok "Site-Code erkannt: $siteCode" } else { Write-Hint 'Site-Code nicht automatisch erkennbar - Skripte nutzen WMI/PSDrive zur Laufzeit.' }

# DP-Gruppe pruefen: der Installer nimmt den Namen nur entgegen, verteilt selbst
# nichts. Ein Tippfehler oder ein Namenszusatz der Umgebung (im Feld gesehen: die
# Gruppe trug ein Kuerzel der Organisation im Namen, konfiguriert war sie ohne)
# faellt daher erst beim Autoimporter auf, und dort nur als WARN,
# waehrend App und Deployment trotzdem entstehen. Ergebnis waere eine Anwendung
# ohne Content auf den Verteilungspunkten: der Client bekommt eine Installation
# angeboten, die nie startet. Nur warnen, nicht abbrechen - die Gruppe darf
# legitim erst nach der Installation angelegt werden.
if ($siteCode) {
    try {
        $dpGroups = @(Get-CimInstance -Namespace ('root\SMS\site_{0}' -f $siteCode) `
            -ClassName 'SMS_DistributionPointGroup' -ErrorAction Stop)
        $dpGroup = $dpGroups | Where-Object { $_.Name -eq $DpGroupName } | Select-Object -First 1
        if (-not $dpGroup) {
            $known = ($dpGroups | ForEach-Object { "'{0}'" -f $_.Name }) -join ', '
            if (-not $known) { $known = '(keine)' }
            Write-Hint ("DP-Gruppe '{0}' existiert in Site {1} NICHT. Der Autoimporter kann neue Anwendungen dann nicht verteilen: App und Deployment entstehen, der Content fehlt. Vorhandene Gruppen: {2}. Gruppe anlegen oder -DpGroupName korrigieren." -f $DpGroupName, $siteCode, $known)
        } elseif ([int]$dpGroup.MemberCount -eq 0) {
            Write-Hint ("DP-Gruppe '{0}' existiert, hat aber keine Verteilungspunkte. Verteilte Anwendungen erreichen damit keinen Client." -f $DpGroupName)
        } else {
            Write-Ok ("DP-Gruppe '{0}' gefunden ({1} Verteilungspunkte)" -f $DpGroupName, [int]$dpGroup.MemberCount)
        }
    } catch {
        Write-Hint ("DP-Gruppe '{0}' nicht pruefbar: {1}" -f $DpGroupName, (Get-VsErrorDetail -ErrorRecord $_))
    }
}

# WebAPI-Name gegen DNS pruefen. Der haeufigste Rollout-Fehler ist eine Zone, die
# die per PXE frisch installierten Clients (DNS kommt bei ihnen per DHCP) nicht
# aufloesen. Nur Hinweis, kein Abbruch: der MECM-Server nutzt evtl. einen anderen
# Resolver als das Deploy-VLAN. [System.Net.Dns] statt Resolve-DnsName, damit es
# nicht am DnsClient-Modul haengt.
if ($webApiIsIp) {
    Write-Hint ("WebApi ist eine IP ({0}). Besser ein DNS-Name (z.B. virtusphere.lan:8021): dann ist eine spaetere IP-Aenderung ein reiner DNS-Eintrag, und die Client-Skripte loesen denselben Namen auf. So besitzt DNS die IP statt zweier Konfig-Stellen." -f $webApiHost)
} else {
    $dnsOk = $false
    try { $dnsOk = @([System.Net.Dns]::GetHostAddresses($webApiHost)).Count -gt 0 } catch { Write-Debug $_ }
    if ($dnsOk) { Write-Ok ("DNS-Name '{0}' loest vom MECM-Server aus auf" -f $webApiHost) }
    else { Write-Hint ("DNS-Name '{0}' loest vom MECM-Server NICHT auf. Im Deploy-VLAN-DNS einen Eintrag anlegen, sonst finden die Clients die WebAPI nicht (ihr DNS kommt per DHCP)." -f $webApiHost) }
}

# --- Registry ---------------------------------------------------------------
# Key nur anlegen, wenn er fehlt - NICHT per New-Item -Force. Force wischt auf
# einem bestehenden Key alle Werte weg und setzt die ACL zurueck (in einem
# HKCU-Wegwerftest bestaetigt): ein Re-Run wuerde damit still den ReportToken und
# den zwischengespeicherten Site-Code loeschen. So aktualisiert der Re-Run in
# place statt zu zerstoeren-und-neu-anzulegen.
#
# Reihenfolge bleibt sicherheitsrelevant: Schluessel -> ACL haerten -> Token
# schreiben. Beim Erstlauf ist der Key zwischen Anlage und Haertung leer (kein
# Token), beim Re-Run schon gehaertet, also gibt es nie ein Users:Read-Fenster
# ueber dem Token.
Write-Step 'Lege Registry-Schluessel an und haerte die Berechtigungen'
if (-not (Test-Path $registryPath)) {
    New-Item -Path $registryPath -Force | Out-Null
}

# Der ReportToken liegt als Klartext in der Registry (die SYSTEM-Tasks brauchen
# ihn zur Laufzeit). Daher die Vererbung abschalten und den Zugriff auf SYSTEM
# und Administratoren begrenzen (idempotent bei Re-Run).
$acl = Get-Acl -Path $registryPath
$acl.SetAccessRuleProtection($true, $false)
# Well-Known-SIDs statt lokalisierter Kontonamen: auf einem deutschen Server
# heissen die Anzeigenamen z. B. NT-AUTORITAET\SYSTEM und
# VORDEFINIERT\Administratoren. LookupAccountName auf die englischen Namen
# wirft dort IdentityNotMappedException, bevor die Installation beginnen kann.
foreach ($sidValue in @('S-1-5-18', 'S-1-5-32-544')) {
    $identity = New-Object System.Security.Principal.SecurityIdentifier($sidValue)
    $rule = New-Object System.Security.AccessControl.RegistryAccessRule(
        $identity, 'FullControl', 'ContainerInherit', 'None', 'Allow')
    $acl.AddAccessRule($rule)
}
Set-Acl -Path $registryPath -AclObject $acl
Write-Ok 'Registry-Berechtigungen gehaertet (nur SYSTEM und Administratoren)'

Write-Step 'Schreibe Registry-Konfiguration'
$settings = @{
    VirtuSphere_WebAPI          = $WebApi
    Scheme                      = $Scheme
    CertThumbprint              = $CertThumbprint.ToUpperInvariant()
    PackagesRoot                = $PackagesRoot
    PackagesShare               = $PackagesShare
    ReportToken                 = $ReportToken
    DpGroupName                 = $DpGroupName
    LogRoot                     = $logRoot
    DeviceSyncIntervalSeconds   = $DeviceSyncIntervalSeconds
    PackagesSyncIntervalSeconds = $PackagesSyncIntervalSeconds
    ImporterIntervalSeconds     = $ImporterIntervalSeconds
    SiteHealthIntervalSeconds   = $SiteHealthIntervalSeconds
}
# Ein Re-Run ohne den Parameter BEHAELT das eingestellte Intervall (wie
# -ProviderMachine eine Zeile tiefer). Sonst setzt jedes Skript-Update einen
# bewusst getunten Takt stillschweigend auf den Standard zurueck, und die
# Statusseite meldet den neuen Wert als Tatsache. Wer zurueck auf den Standard
# will, gibt den Parameter ausdruecklich an.
$existingConfig = Get-ItemProperty -Path $registryPath -ErrorAction SilentlyContinue
# Die Namen kommen aus $settings, nicht aus einer zweiten Liste: ein kuenftiges
# fuenftes Intervall waere sonst genau hier vergessen.
foreach ($intervalName in @($settings.Keys | Where-Object { $_ -like '*IntervalSeconds' })) {
    if ($PSBoundParameters.ContainsKey($intervalName)) { continue }
    $keep = if ($existingConfig) { $existingConfig.$intervalName } else { $null }
    if ($null -eq $keep -or [int]$keep -eq $settings[$intervalName]) { continue }
    $settings[$intervalName] = [int]$keep
    Write-Ok ('{0}: eingestellte {1} s behalten (Parameter nicht angegeben)' -f $intervalName, [int]$keep)
}
# Dieselbe Regel fuer die Textwerte, die ein Administrator bewusst setzt. Nur die
# vier Intervalle und -ProviderMachine ueberlebten einen Re-Run: ein
# Skript-Update mit dem Pflichtminimum an Parametern setzte damit stillschweigend
# das Schema auf http zurueck (und schaltete auf einem TLS-Portal die ganze
# Integration ab), ebenso den Fingerabdruck, die DP-Gruppe und den Paketpfad.
# Ein Wert, den man einstellen kann, muss ein Update ueberleben, das ihn nicht
# nennt - genau wie ein Intervall.
$parameterToSetting = @{
    Scheme         = 'Scheme'
    CertThumbprint = 'CertThumbprint'
    DpGroupName    = 'DpGroupName'
    PackagesRoot   = 'PackagesRoot'
    # Ein Re-Run ohne -ReportToken loeschte den Token: der Meldekanal verlor
    # damit still seine Authentisierung. Die Sonderbehandlung weiter oben (beim
    # Einlesen des bestehenden Werts) liest inzwischen ebenfalls
    # $PSBoundParameters, weshalb ein ausdrueckliches -ReportToken '' den Token
    # tatsaechlich leert. Vorher stellte jene Zeile den alten Wert wieder her,
    # bevor diese Tabelle ueberhaupt drankam.
    ReportToken    = 'ReportToken'
}
foreach ($parameterName in $parameterToSetting.Keys) {
    if ($PSBoundParameters.ContainsKey($parameterName)) { continue }
    $settingName = $parameterToSetting[$parameterName]
    $keep = if ($existingConfig) { [string]$existingConfig.$settingName } else { '' }
    if ([string]::IsNullOrWhiteSpace($keep) -or $keep -eq [string]$settings[$settingName]) { continue }
    $settings[$settingName] = $keep
    # Den Token nicht ins Log schreiben.
    $shown = if ($settingName -eq 'ReportToken') { '[vorhanden]' } else { $keep }
    Write-Ok ('{0}: eingestellten Wert "{1}" behalten (Parameter nicht angegeben)' -f $settingName, $shown)
    # Die Variablen nachziehen, die der Rest des Skripts benutzt (Verzeichnisse
    # unter PackagesRoot, die Portal-Probe mit Scheme). Sonst schreibt die
    # Registry den behaltenen Wert, waehrend das Skript mit dem Default weiterlaeuft.
    Set-Variable -Name $parameterName -Value $keep -Scope Script
}
if ($siteCode) { $settings['MECM_SiteCode'] = $siteCode }
# Nur schreiben, wenn ein Provider vorliegt: ein leerer Wert wuerde die lokale
# WMI/PSDrive-Erkennung des Site-Health-Skripts aushebeln.
if (-not [string]::IsNullOrWhiteSpace($ProviderMachine)) { $settings['MECM_ProviderMachine'] = $ProviderMachine.Trim() }
foreach ($key in $settings.Keys) {
    $type = if ($settings[$key] -is [int]) { 'DWord' } else { 'String' }
    New-ItemProperty -Path $registryPath -Name $key -Value $settings[$key] -PropertyType $type -Force | Out-Null
}
Write-Ok "Konfiguration unter $registryPath gespeichert"

# --- Verzeichnisse + Skripte ------------------------------------------------
Write-Step 'Lege Verzeichnisse an und kopiere Skripte'
foreach ($dir in @($installDir, $logRoot, (Join-Path $PackagesRoot 'files'), (Join-Path $PackagesRoot 'Package_Vorlage'))) {
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
}

# Die paketeigene install.ps1 wird als SYSTEM mit ExecutionPolicy Bypass aus der
# ContentLocation ausgefuehrt. Ist der files-Ordner fuer normale Benutzer
# beschreibbar, waere das Codeausfuehrung als SYSTEM. Nur warnen (den DP-Zugriff
# nicht durch automatische ACL-Aenderungen gefaehrden).
$filesDir = Join-Path $PackagesRoot 'files'
$writableByUsers = (Get-Acl -Path $filesDir).Access | Where-Object {
    $_.AccessControlType -eq 'Allow' -and
    $_.FileSystemRights -match 'Write|Modify|FullControl' -and
    $_.IdentityReference -match 'Users|Everyone|Authenticated Users'
}
if ($writableByUsers) {
    Write-Hint ('Paket-Ordner {0} ist fuer normale Benutzer beschreibbar. Paket-Skripte laufen als SYSTEM: Schreibrechte auf Administratoren/SYSTEM begrenzen.' -f $filesDir)
}

# PackagesShare MUSS die Freigabe von PackagesRoot\files sein: der Autoimporter
# LIEST config.json aus PackagesRoot\files (lokal), setzt aber die ContentLocation
# der MECM-App auf PackagesShare\<pkg> (UNC). Zeigen die beiden auf verschiedene
# Ordner, hat jede erzeugte App leeren Content - ohne Fehler beim Anlegen, der
# Client bekommt beim Deploy "Content not found". Definitiv geprueft: lokal einen
# Marker schreiben und sehen, ob er ueber die Freigabe auftaucht.
$probeName = '.vs_share_probe_{0}' -f ([guid]::NewGuid().ToString('N'))
$localProbe = Join-Path $filesDir $probeName
try {
    Set-Content -Path $localProbe -Value 'probe' -ErrorAction Stop
    if (Test-Path (Join-Path $PackagesShare $probeName)) {
        Write-Ok 'PackagesShare zeigt auf PackagesRoot\files (ContentLocation stimmt)'
    } else {
        Write-Warn ("PackagesShare '{0}' zeigt NICHT auf '{1}'. Der Autoimporter liest die Pakete lokal, setzt die ContentLocation aber auf die Freigabe: zeigen sie auf verschiedene Ordner, hat jede erzeugte App leeren Content. Freigabe pruefen." -f $PackagesShare, $filesDir)
    }
} catch {
    Write-Warn ("PackagesShare '{0}' ist nicht erreichbar/pruefbar: {1}" -f $PackagesShare, $_.Exception.Message)
} finally {
    Remove-Item -Path $localProbe -Force -ErrorAction SilentlyContinue
}

# Die Aufgabenliste steht VOR dem Kopieren, weil die laufenden Instanzen erst
# beendet werden muessen (siehe unten) und beides denselben Namen braucht.
$tasks = @(
    @{ Name = 'VirtuSphere MECM Devices Sync';   Script = 'mecm_new-device-sync.ps1' }
    @{ Name = 'VirtuSphere MECM Packages Sync';  Script = 'mecm_Packages-TaskSeq-sync.ps1' }
    @{ Name = 'VirtuSphere MECM Package Import'; Script = 'mecm_autoimporter.ps1' }
    @{ Name = 'VirtuSphere MECM Site Health';    Script = 'mecm_site-health.ps1' }
)

# --- Laufende Aufgaben VOR dem Kopieren beenden -----------------------------
#
# Die Skripte sind Endlosschleifen und dot-sourcen VirtuSphere-Common.ps1 nur
# einmal beim Start. Ein Re-Run, der die Dateien unter einer laufenden Instanz
# austauscht, laesst diese Instanz mit dem ALTEN Common weiterlaufen, waehrend
# die neue Registry-Konfiguration schon da ist: eine Aufgabe, die eine gerade
# eingefuehrte Wire-Aenderung nicht kennt, meldet weiter im alten Format, und
# die Systemstatus-Seite zeigt einen frisch installierten Stand, der nicht
# laeuft. Ausserdem haelt eine laufende Instanz die .ps1 nicht offen, aber die
# Logdatei: Copy-Item scheitert nicht, das Ergebnis ist nur unbestimmt.
# Stoppen ist hier immer richtig, weil unten jede Aufgabe neu gestartet wird.
Write-Step 'Beende laufende Aufgaben'
foreach ($task in $tasks) {
    $existing = Get-ScheduledTask -TaskName $task.Name -ErrorAction SilentlyContinue
    if (-not $existing) { continue }
    if ($existing.State -eq 'Running') {
        try {
            Stop-ScheduledTask -TaskName $task.Name -ErrorAction Stop
            Write-Ok ("Aufgabe beendet: {0}" -f $task.Name)
        } catch {
            # Kein Abbruch: der Neustart unten setzt die Aufgabe ohnehin neu auf.
            # Die Warnung sagt aber, dass die alte Instanz kurz weiterlaufen kann.
            Write-Hint ("Aufgabe '{0}' liess sich nicht beenden: {1}" -f $task.Name, $_.Exception.Message)
        }
    }
}
# Der Scheduler meldet 'Ready' bevor der powershell.exe-Prozess weg ist. Ohne
# diese kurze Wartezeit kopieren wir in genau das Fenster hinein, das wir
# gerade geschlossen haben.
Start-Sleep -Seconds 2

$sourceDir = Join-Path $PSScriptRoot 'mecm'
Copy-Item -Path (Join-Path $sourceDir '*.ps1') -Destination $installDir -Force
Write-Ok "Skripte nach $installDir kopiert"

# Package-Vorlage (Standard-install.ps1 + config.json-Blaupause) bereitstellen.
# Der Autoimporter kopiert Package_Vorlage\install.ps1 per Self-Healing ueber die
# paketeigene install.ps1; ohne diese Vorlage liefe das Self-Healing ins Leere.
$templateSource = Join-Path $PSScriptRoot 'Package_Vorlage'
$templateDest   = Join-Path $PackagesRoot 'Package_Vorlage'
if (Test-Path (Join-Path $templateSource 'install.ps1')) {
    Copy-Item -Path (Join-Path $templateSource '*') -Destination $templateDest -Recurse -Force -Exclude '.gitkeep'
    Write-Ok "Package-Vorlage nach $templateDest kopiert"
} else {
    Write-Warn "Package-Vorlage-Quelle fehlt ($templateSource) - Self-Healing der install.ps1 bleibt inaktiv."
}

# --- Geplante Aufgaben ------------------------------------------------------
Write-Step 'Registriere geplante Aufgaben'
# Auch der Task-Principal verwendet die sprachunabhaengige SYSTEM-SID.
$principal = New-ScheduledTaskPrincipal -UserId 'S-1-5-18' -RunLevel Highest
foreach ($task in $tasks) {
    $scriptFile = Join-Path $installDir $task.Script
    # Schalter aus $script:VsPowerShellArgs (Common), wo auch die Begruendung
    # steht: -NoProfile gegen Fremdcode im SYSTEM-Prozess, -NonInteractive
    # gegen eine Rueckfrage, die niemand sieht. Diese Zeile war die einzige, die
    # es richtig machte, waehrend drei andere Aufrufstellen beides nicht setzten.
    $action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument ('{0} -File "{1}"' -f $script:VsPowerShellArgs, $scriptFile)
    # Zwei Trigger, nicht einer. -AtStartup allein hiess: nach den drei
    # Neustartversuchen (-RestartCount 3) ist die Aufgabe bis zum naechsten
    # Reboot tot, und ein MECM-Server bootet selten. Der Ausfall sieht dann
    # aus wie eine stille Integration, nicht wie ein Fehler. Der zweite
    # Trigger holt sie stuendlich zurueck; -MultipleInstances IgnoreNew
    # sorgt dafuer, dass er nichts tut, solange die Aufgabe laeuft.
    $triggers = @(
        (New-ScheduledTaskTrigger -AtStartup)
        (New-ScheduledTaskTrigger -Once -At (Get-Date).Date -RepetitionInterval (New-TimeSpan -Hours 1))
    )
    # -MultipleInstances IgnoreNew ist der Doppelstart-Schutz (AP5): die
    # Skripte sind Endlosschleifen, eine zweite Instanz wuerde denselben Sync
    # parallel fahren (doppelte Imports, konkurrierende Registry-Writes).
    # IgnoreNew ist zwar der Scheduler-Default, steht aber explizit hier, damit
    # der Schutz ein gepinnter Vertrag ist und kein Zufall der Plattform.
    $set = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1) -MultipleInstances IgnoreNew
    # Endlosschleifen: kein Laufzeitlimit (Standard 72h wuerde sie killen).
    $set.ExecutionTimeLimit = 'PT0S'
    $definition = New-ScheduledTask -Action $action -Trigger $triggers -Principal $principal -Settings $set -Description 'VirtuSphere MECM Integration'
    Register-ScheduledTask -TaskName $task.Name -InputObject $definition -Force | Out-Null
    Start-ScheduledTask -TaskName $task.Name
    Write-Ok ("Aufgabe registriert und gestartet: {0}" -f $task.Name)
}

# --- Verifikation -----------------------------------------------------------
# Poll statt festem Schlaf: auf einem langsamen Server braucht ein Task laenger,
# bis er auf 'Running' steht, und ein starrer Sleep meldet ihn faelschlich als tot.
Write-Step 'Verifiziere Erstinstallation'
$allRunning = $false
for ($i = 0; $i -lt 8 -and -not $allRunning; $i++) {
    Start-Sleep -Seconds 3
    $allRunning = $true
    foreach ($task in $tasks) {
        if ((Get-ScheduledTask -TaskName $task.Name -ErrorAction SilentlyContinue).State -ne 'Running') { $allRunning = $false }
    }
}
foreach ($task in $tasks) {
    $state = (Get-ScheduledTask -TaskName $task.Name -ErrorAction SilentlyContinue).State
    if ($state -eq 'Running') { Write-Ok ("laeuft: {0}" -f $task.Name) }
    else { Write-Warn ("Status '{0}': {1}" -f $state, $task.Name) }
}

# Erreichbarkeit des Portals und Abfragbarkeit des SMS-Providers sind ZWEI
# getrennte Aussagen: das Portal kann erreichbar sein, waehrend Site-Health den
# Provider nicht abfragen darf (oder umgekehrt). Beide separat melden, und ein
# laufender Task ist NICHT gleichbedeutend mit einem gelungenen Lauf.
Write-Step 'Pruefe Portal-Erreichbarkeit'
# Common ist bereits frueh dot-gesourct (Get-VsErrorDetail/-StatusCode verfuegbar).
# Dieselbe Vorbereitung wie in jedem Aufgabenprozess, ueber dieselbe Funktion:
# TLS 1.2 plus, bei hinterlegtem Fingerabdruck, das Pinning. Vorher setzte der
# Installer nur das Protokoll und tat das als Einziger, weshalb die vier Aufgaben
# auf einem TLS-Portal am Handshake scheiterten, die Installation aber gruen
# meldete - der Probelauf des Installers bewies etwas, das seine Aufgaben nicht
# konnten.
Initialize-VsTls -Config ([pscustomobject]@{ Scheme = $Scheme; CertThumbprint = $CertThumbprint })
try {
    $health = Invoke-RestMethod -Uri ('{0}://{1}/portal/health.php' -f $Scheme, $WebApi) -TimeoutSec 5
    Write-Ok ("Portal erreichbar (Status: {0})" -f $health.status)
} catch {
    $code = Get-VsErrorStatusCode -ErrorRecord $_
    if ($code -eq 403) {
        Write-Warn 'Portal antwortet mit 403 - IP dieses Servers im Portal unter Einstellungen > IP-Freigaben freischalten.'
    } else {
        # Get-VsErrorDetail statt .Exception.Message: bei einem Fehler der WebApp
        # steht der Grund in ihrer JSON-Envelope, nicht in der Statuszeile.
        Write-Warn ("Portal nicht erreichbar: {0} (Adresse/Port/Firewall/Schema pruefen)" -f (Get-VsErrorDetail -ErrorRecord $_))
    }
}

Write-Step 'Pruefe MECM-Site-Provider (Site-Health)'
# Get-VsMecmSiteHealth wirft nie; ein unknown-Outcome bedeutet, dass der Provider
# nicht abgefragt werden konnte (Rechte/Erreichbarkeit), NICHT dass die Site
# krank ist. Das ist unabhaengig davon, ob der Site-Health-Task laeuft.
$siteHealthProbeConfig = [pscustomobject]@{ SiteCodeFallback = $siteCode; ProviderMachine = $ProviderMachine }
$siteHealth = Get-VsMecmSiteHealth -Config $siteHealthProbeConfig -ProviderMachine $ProviderMachine
if ($siteHealth.Outcome -eq 'unknown') {
    # Hinweis, kein Blocker: `unknown` heisst laut Common ausdruecklich "nicht
    # abfragbar", nicht "Site krank", und die Rechte dafuer sind oft eine
    # getrennte Freigabe, die nach der Installation nachgereicht wird.
    Write-Hint ("Site-Health konnte den SMS-Provider '{0}' nicht abfragen (Kategorie {1}). Rechte und Erreichbarkeit des Providers pruefen." -f $siteHealth.Provider, $siteHealth.ErrorCategory)
} else {
    Write-Ok ("Site-Health erreicht den Provider '{0}': Site {1}, Rohstatus {2} -> {3}." -f $siteHealth.Provider, $siteHealth.SiteCode, $siteHealth.RawStatus, $siteHealth.Outcome)
}

Write-Step 'Pruefe Log-Aktivitaet aller vier Tasks (bis zu 40s)'
# Ein laufender Task beweist noch keinen gelungenen Lauf. Nur ein NEUER
# Schreibvorgang je Tageslog zeigt, dass die Schleife tatsaechlich arbeitet;
# die blosse Existenz der Datei (Re-Run am selben Tag) reicht nicht.
$logComponents = @('device-sync', 'packages-sync', 'autoimporter', 'site-health')
$logPaths = @{}
$logBaselines = @{}
$logSeen = @{}
foreach ($comp in $logComponents) {
    $p = Join-Path $logRoot ('{0}_{1}.log' -f (Get-Date -Format 'yyyy-MM-dd'), $comp)
    $logPaths[$comp] = $p
    $logBaselines[$comp] = if (Test-Path $p) { (Get-Item $p).LastWriteTimeUtc } else { [datetime]::MinValue }
    $logSeen[$comp] = $false
}
for ($i = 0; $i -lt 8; $i++) {
    Start-Sleep -Seconds 5
    $allSeen = $true
    foreach ($comp in $logComponents) {
        if (-not $logSeen[$comp]) {
            $p = $logPaths[$comp]
            if ((Test-Path $p) -and (Get-Item $p).LastWriteTimeUtc -gt $logBaselines[$comp]) { $logSeen[$comp] = $true }
            else { $allSeen = $false }
        }
    }
    if ($allSeen) { break }
}
foreach ($comp in $logComponents) {
    if ($logSeen[$comp]) { Write-Ok ("Log aktiv: {0}" -f $comp) }
    else { Write-Warn ("Noch kein frisches Log: {0} - Aufgabenplanung und Portal-Statusseite pruefen." -f $comp) }
}

# --- Abschluss-Marker -------------------------------------------------------
New-ItemProperty -Path $registryPath -Name 'SetupCompleted' -Value (Get-Date -Format 'o') -PropertyType String -Force | Out-Null

Write-Host ''
# Die Schlusszeile haengt an ALLEN Blockern, nicht mehr allein an $allRunning.
# Vier laufende Aufgaben plus ein Portal, das mit 403 antwortet, ergaben vorher
# eine gruene Erstinstallation - und ausgerechnet dieser 403 ist der
# Naechste-Schritte-Punkt 1 desselben Skripts.
#
# Die Zahl steht in der Zeile, nicht nur die Farbe: fuer einen Menschen vor der
# Konsole ist ein Exit-Code unsichtbar.
if ($script:VsInstallBlockers -eq 0) {
    Write-Host 'Erstinstallation abgeschlossen.' -ForegroundColor Green
} else {
    Write-Host ('Erstinstallation abgeschlossen, aber {0} offene(r) Punkt(e) - die mit "!!" markierten Zeilen oben pruefen.' -f $script:VsInstallBlockers) -ForegroundColor Yellow
}
Write-Host ('Logs: {0}' -f $logRoot) -ForegroundColor Gray
Write-Host ''
Write-Host 'Naechste Schritte:' -ForegroundColor Gray
Write-Host '  1. Im Portal die IP DIESES MECM-Servers freischalten (Einstellungen > IP-Freigaben).' -ForegroundColor Gray
Write-Host '  2. Auch die IP des ANSIBLE-Hosts freischalten - sonst laeuft ein Deploy durch, ohne dass je eine MAC zurueckkommt (der Ansible-Host meldet die MACs ueber db_importMAC.php).' -ForegroundColor Gray
if (-not $webApiIsIp) {
    Write-Host ('  3. DNS-Eintrag "{0}" -> WebApp-Host im Deploy-VLAN-DNS anlegen (die per PXE frisch installierten Clients bekommen ihren DNS per DHCP und loesen darueber auf).' -f $webApiHost) -ForegroundColor Gray
}
Write-Host ('  4. Client-Paritaet: die Client-Skripte lesen den WebAPI-Namen aus $VsDefaultDnsApi in VirtuSphere-Client-Common.ps1. Beim Default "virtusphere.lan:8021" ist nichts zu tun; weicht euer Name ab, dort denselben Wert wie hier (-WebApi "{0}") setzen.' -f $WebApi) -ForegroundColor Gray
Write-Host '  5. Seite "Systemstatus" im Portal beobachten - die Ampeln werden gruen.' -ForegroundColor Gray

# Exit-Code als maschinenlesbare Fassung der Schlusszeile: Voraussetzung dafuer,
# dass ein Rollout-Skript den Installer je pruefen kann.
if ($script:VsInstallBlockers -gt 0) { exit 1 }
exit 0
