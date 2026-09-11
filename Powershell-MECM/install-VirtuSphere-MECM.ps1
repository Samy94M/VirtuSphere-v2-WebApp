#Requires -Version 5.1
#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Erstinstallation der VirtuSphere-MECM-Integration auf dem MECM-Server.

.DESCRIPTION
    Schreibt die Konfiguration in die Registry, legt Verzeichnisse an,
    deaktiviert und beendet laufende Aufgaben, kopiert die Sync-Skripte nach
    %ProgramFiles%\VirtuSphere\mecm, registriert die vier geplanten Aufgaben
    (SYSTEM, hoechste Rechte, ohne Profil, beim Systemstart UND stuendlich,
    ohne Laufzeitlimit) und verifiziert die Erstinstallation.

    Upgrade und Re-Run sind transaktional fuer die verwaltete Registry-
    Konfiguration, den vollstaendigen Serverdateisatz und die vier Aufgaben.
    Ein Fehler stellt den vorherigen Stand wieder her. Kann ein Prozess oder
    Rollback nicht vollstaendig bestaetigt werden, bleiben die Aufgaben
    deaktiviert, damit keine gemischte Skriptgeneration startet.

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

# Vertragsversionen fremder Staging- und Live-Dateien nur lesen, nie ausfuehren.
# Dot-Sourcing wuerde deren $script:-Zustand in den laufenden Installer tragen
# und damit unter anderem Komponente, Korrelation und Sinkstatus zuruecksetzen.
function Get-VsDeclaredScriptInteger {
    param(
        [Parameter(Mandatory)][string]$Path,
        [Parameter(Mandatory)][string]$VariableName
    )
    $tokens = $null
    $parseErrors = $null
    $ast = [System.Management.Automation.Language.Parser]::ParseFile(
        (Resolve-Path -Path $Path -ErrorAction Stop).Path,
        [ref]$tokens,
        [ref]$parseErrors
    )
    if (@($parseErrors).Count -gt 0) {
        throw ('PowerShell-Vertragsdatei ist syntaktisch ungueltig: {0}' -f $Path)
    }
    $needle = '$script:' + $VariableName
    $assignments = @($ast.FindAll({
        param($node)
        $node -is [System.Management.Automation.Language.AssignmentStatementAst] -and
            $node.Left.Extent.Text -eq $needle
    }, $true))
    $literal = if ($assignments.Count -eq 1 -and
        $assignments[0].Right -is [System.Management.Automation.Language.CommandExpressionAst]) {
        $assignments[0].Right.Expression
    } elseif ($assignments.Count -eq 1) {
        $assignments[0].Right
    } else {
        $null
    }
    if ($literal -isnot [System.Management.Automation.Language.ConstantExpressionAst] -or
        $literal.Value -isnot [int]) {
        throw ('Vertragskonstante {0} muss in {1} genau einmal als ganzzahliges Literal deklariert sein.' -f $needle, $Path)
    }
    return [int]$literal.Value
}

function Resolve-VsInstallerSetting {
    param(
        [Parameter(Mandatory)][string]$Name,
        [AllowNull()]$SuppliedValue,
        [AllowNull()]$ExistingValue,
        [Parameter(Mandatory)][bool]$ParameterBound,
        [Parameter(Mandatory)][bool]$InteractiveSupplied,
        [AllowNull()]$InteractiveValue,
        [switch]$ExplicitEmptyClears,
        [switch]$InteractiveEmptyKeepsExisting
    )

    if ($InteractiveSupplied) {
        if (-not [string]::IsNullOrEmpty([string]$InteractiveValue)) {
            return [pscustomobject]@{ Name = $Name; Value = $InteractiveValue; Source = 'interactive' }
        }
        if ($InteractiveEmptyKeepsExisting -and -not [string]::IsNullOrEmpty([string]$ExistingValue)) {
            return [pscustomobject]@{ Name = $Name; Value = $ExistingValue; Source = 'kept' }
        }
        return [pscustomobject]@{ Name = $Name; Value = $SuppliedValue; Source = 'default' }
    }

    if ($ParameterBound) {
        if ($ExplicitEmptyClears -and [string]::IsNullOrEmpty([string]$SuppliedValue)) {
            return [pscustomobject]@{ Name = $Name; Value = ''; Source = 'cleared' }
        }
        return [pscustomobject]@{ Name = $Name; Value = $SuppliedValue; Source = 'parameter' }
    }

    if ($null -ne $ExistingValue -and -not [string]::IsNullOrWhiteSpace([string]$ExistingValue)) {
        return [pscustomobject]@{ Name = $Name; Value = $ExistingValue; Source = 'kept' }
    }
    return [pscustomobject]@{ Name = $Name; Value = $SuppliedValue; Source = 'default' }
}

# Read the complete old configuration once, before any validation-dependent
# probe or write. Every optional setting is resolved once below and is never
# restored by a later loop.
$existingConfig = if (Test-Path $registryPath) {
    Get-ItemProperty -Path $registryPath -ErrorAction Stop
} else {
    $null
}

function Get-VsExistingInstallerValue {
    param([Parameter(Mandatory)][string]$Name)
    if ($existingConfig -and $existingConfig.PSObject.Properties[$Name]) {
        return $existingConfig.$Name
    }
    return $null
}

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
$existingToken = [string](Get-VsExistingInstallerValue -Name 'ReportToken')
$tokenExists = -not [string]::IsNullOrEmpty($existingToken)
$reportTokenPrompted = $false
$reportTokenInteractiveValue = $null

# Sichere interaktive Eingabe statt Klartext-CLI-Argument (History/Prozessliste).
#
# Auf $PSBoundParameters, nicht auf den Wert: `-ReportToken ''` fiel sonst in
# die Abfrage, obwohl der Aufrufer den Parameter ausdruecklich genannt hat.
if (-not $PSBoundParameters.ContainsKey('ReportToken')) {
    if ([Environment]::UserInteractive) {
        $reportTokenPrompted = $true
        $tokenPrompt = if ($tokenExists) { 'Rueckkanal-Token (leer lassen = bestehenden behalten)' } else { 'Rueckkanal-Token (im Portal generiert, leer lassen fuer ohne Token)' }
        $secureToken = Read-Host -AsSecureString $tokenPrompt
        $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureToken)
        try {
            $reportTokenInteractiveValue = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
        } finally {
            [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
        }
    }
} else {
    Write-Warning 'ReportToken wurde als Kommandozeilen-Argument uebergeben und ist damit in der PowerShell-History und Prozessliste sichtbar. Fuer Produktivumgebungen das Skript ohne -ReportToken starten und den Token interaktiv eingeben.'
}

$tokenResolution = Resolve-VsInstallerSetting -Name 'ReportToken' -SuppliedValue $ReportToken `
    -ExistingValue $existingToken -ParameterBound ($PSBoundParameters.ContainsKey('ReportToken')) `
    -InteractiveSupplied $reportTokenPrompted -InteractiveValue $reportTokenInteractiveValue `
    -ExplicitEmptyClears -InteractiveEmptyKeepsExisting
$ReportToken = [string]$tokenResolution.Value
if ($tokenResolution.Source -eq 'kept') {
    Write-Ok 'Bestehenden Rueckkanal-Token behalten (kein neuer uebergeben).'
} elseif ($tokenResolution.Source -eq 'cleared' -and $tokenExists) {
    Write-VsLog -Level WARN -Context 'setup' -Message '    !!  Rueckkanal-Token wird GELOESCHT (-ReportToken ausdruecklich leer uebergeben). Die Sync-Tasks melden ab sofort ohne Authentisierung.' -Color Yellow
} elseif ($tokenResolution.Source -in @('parameter', 'interactive') -and -not [string]::IsNullOrEmpty($ReportToken)) {
    Write-Ok 'Rueckkanal-Token gesetzt.'
}

# Resolve every remaining optional value through the same owner. Explicitly
# empty ProviderMachine now means remove the override; omission keeps it.
$textSettingMap = @{
    Scheme         = 'Scheme'
    CertThumbprint = 'CertThumbprint'
    PackagesRoot   = 'PackagesRoot'
    DpGroupName    = 'DpGroupName'
    ProviderMachine = 'MECM_ProviderMachine'
}
$configurationSources = @{ ReportToken = $tokenResolution.Source }
foreach ($parameterName in $textSettingMap.Keys) {
    $settingName = $textSettingMap[$parameterName]
    $resolutionParams = @{
        Name = $settingName
        SuppliedValue = (Get-Variable -Name $parameterName -ValueOnly)
        ExistingValue = (Get-VsExistingInstallerValue -Name $settingName)
        ParameterBound = ($PSBoundParameters.ContainsKey($parameterName))
        InteractiveSupplied = $false
        InteractiveValue = $null
    }
    if ($parameterName -eq 'ProviderMachine') { $resolutionParams['ExplicitEmptyClears'] = $true }
    $resolution = Resolve-VsInstallerSetting @resolutionParams
    Set-Variable -Name $parameterName -Value $resolution.Value -Scope Script
    $configurationSources[$settingName] = $resolution.Source
}
if ($configurationSources['MECM_ProviderMachine'] -eq 'kept') {
    Write-Ok 'Bestehenden SMS-Provider-Rechner behalten (Parameter nicht angegeben).'
}

$intervalBounds = @{
    DeviceSyncIntervalSeconds = @(5, 3600)
    PackagesSyncIntervalSeconds = @(10, 3600)
    ImporterIntervalSeconds = @(30, 3600)
    SiteHealthIntervalSeconds = @(60, 3600)
}
foreach ($intervalName in $intervalBounds.Keys) {
    $resolution = Resolve-VsInstallerSetting -Name $intervalName `
        -SuppliedValue (Get-Variable -Name $intervalName -ValueOnly) `
        -ExistingValue (Get-VsExistingInstallerValue -Name $intervalName) `
        -ParameterBound ($PSBoundParameters.ContainsKey($intervalName)) `
        -InteractiveSupplied $false -InteractiveValue $null
    $parsed = 0
    $bounds = $intervalBounds[$intervalName]
    if (-not [int]::TryParse([string]$resolution.Value, [ref]$parsed) -or $parsed -lt $bounds[0] -or $parsed -gt $bounds[1]) {
        throw ('Vorhandener oder angegebener Wert fuer {0} liegt ausserhalb {1}..{2}; es wurde nichts geschrieben.' -f $intervalName, $bounds[0], $bounds[1])
    }
    Set-Variable -Name $intervalName -Value $parsed -Scope Script
    $configurationSources[$intervalName] = $resolution.Source
}

if ($Scheme -notin @('http', 'https')) { throw 'Vorhandenes Scheme muss http oder https sein; es wurde nichts geschrieben.' }
if ([string]$CertThumbprint -notmatch '^([0-9A-Fa-f]{40})?$') { throw 'Vorhandener CertThumbprint ist ungueltig; es wurde nichts geschrieben.' }

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

# --- Transaktionskontext ----------------------------------------------------
# Diese Namen sind zugleich der vollstaendige Ownership-Rahmen des Installers.
# Fremde Aufgaben werden weder gesichert noch ersetzt.
$tasks = @(
    @{ Name = 'VirtuSphere MECM Devices Sync';   Script = 'mecm_new-device-sync.ps1' }
    @{ Name = 'VirtuSphere MECM Packages Sync';  Script = 'mecm_Packages-TaskSeq-sync.ps1' }
    @{ Name = 'VirtuSphere MECM Package Import'; Script = 'mecm_autoimporter.ps1' }
    @{ Name = 'VirtuSphere MECM Site Health';    Script = 'mecm_site-health.ps1' }
)

function Assert-VsOwnedInstallDirectory {
    param([Parameter(Mandatory)][string]$Path)
    $expected = [IO.Path]::GetFullPath((Join-Path $env:ProgramFiles 'VirtuSphere\mecm')).TrimEnd('\')
    $actual = [IO.Path]::GetFullPath($Path).TrimEnd('\')
    if (-not [string]::Equals($actual, $expected, [StringComparison]::OrdinalIgnoreCase)) {
        throw ("Nicht erlaubter Installationspfad: '{0}', erwartet '{1}'." -f $actual, $expected)
    }
    if (Test-Path -LiteralPath $actual) {
        $item = Get-Item -LiteralPath $actual -Force -ErrorAction Stop
        if (-not $item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
            throw ("Installationspfad ist kein echtes lokales Verzeichnis: {0}" -f $actual)
        }
    }
    return $actual
}

function New-VsRegistryRollbackSnapshot {
    param([Parameter(Mandatory)][string]$Path)
    if (-not (Test-Path -LiteralPath $Path)) {
        return [pscustomobject]@{ Existed = $false; Values = @(); Acl = $null }
    }
    $key = Get-Item -LiteralPath $Path -ErrorAction Stop
    $values = New-Object System.Collections.Generic.List[object]
    foreach ($name in @($key.GetValueNames())) {
        [void]$values.Add([pscustomobject]@{
            Name = [string]$name
            Value = $key.GetValue($name, $null, [Microsoft.Win32.RegistryValueOptions]::DoNotExpandEnvironmentNames)
            Kind = [string]$key.GetValueKind($name)
        })
    }
    # `@($genericList)` trifft in Windows PowerShell 5.1 den fehlerhaften
    # PSEnumerableBinder ("Die Argumenttypen stimmen nicht ueberein"). Der
    # Installer laeuft genau dort und muss den Snapshot deshalb ueber die
    # typsichere List<T>-API materialisieren.
    return [pscustomobject]@{ Existed = $true; Values = $values.ToArray(); Acl = (Get-Acl -LiteralPath $Path -ErrorAction Stop) }
}

function New-VsTaskRollbackSnapshots {
    param([Parameter(Mandatory)][array]$TaskSpecs)
    $snapshots = New-Object System.Collections.Generic.List[object]
    foreach ($task in $TaskSpecs) {
        $existing = Get-ScheduledTask -TaskName $task.Name -ErrorAction SilentlyContinue
        if ($existing) {
            [void]$snapshots.Add([pscustomobject]@{
                Name = $task.Name
                Script = $task.Script
                Existed = $true
                Xml = (Export-ScheduledTask -TaskName $task.Name -ErrorAction Stop)
                WasRunning = ([string]$existing.State -eq 'Running')
            })
        } else {
            [void]$snapshots.Add([pscustomobject]@{ Name = $task.Name; Script = $task.Script; Existed = $false; Xml = ''; WasRunning = $false })
        }
    }
    return $snapshots.ToArray()
}

function Restore-VsInstallTransaction {
    param(
        [Parameter(Mandatory)][object]$RegistrySnapshot,
        [Parameter(Mandatory)][array]$TaskSnapshots,
        [AllowNull()][string]$BackupPath,
        [Parameter(Mandatory)][System.Collections.Generic.List[string]]$ActivatedFiles,
        [AllowNull()][string]$TemplateBackupPath,
        [AllowNull()][string]$TemplateDestination,
        [bool]$TemplateActivationStarted = $false
    )
    $errors = New-Object System.Collections.Generic.List[string]
    $quiesced = $true
    $filesRestored = $false
    $registryRestored = $false

    # Auch ein Fehler waehrend Registrierung oder Start kann bereits neue
    # Prozesse erzeugt haben. Vor dem Datei-Rollback alle eigenen Trigger und
    # Prozesse erneut fail-closed stilllegen.
    foreach ($snapshot in $TaskSnapshots) {
        try {
            $current = Get-ScheduledTask -TaskName $snapshot.Name -ErrorAction SilentlyContinue
            if ($current) {
                Disable-ScheduledTask -TaskName $snapshot.Name -ErrorAction Stop | Out-Null
                $current = Get-ScheduledTask -TaskName $snapshot.Name -ErrorAction Stop
                if ([string]$current.State -eq 'Running') { Stop-ScheduledTask -TaskName $snapshot.Name -ErrorAction Stop }
                Wait-VsScheduledScriptStopped -ScriptPath (Join-Path $installDir $snapshot.Script)
            }
        } catch {
            $quiesced = $false
            [void]$errors.Add("Rollback konnte Task '$($snapshot.Name)' nicht sicher beenden: $($_.Exception.Message)")
        }
    }

    if ($quiesced) { try {
        foreach ($name in $ActivatedFiles.ToArray()) {
            $livePath = Join-Path $installDir $name
            if (Test-Path -LiteralPath $livePath) { Remove-Item -LiteralPath $livePath -Force -ErrorAction Stop }
        }
        if ($BackupPath -and (Test-Path -LiteralPath $BackupPath)) {
            foreach ($old in @(Get-ChildItem -LiteralPath $BackupPath -File -ErrorAction Stop)) {
                Move-Item -LiteralPath $old.FullName -Destination (Join-Path $installDir $old.Name) -Force -ErrorAction Stop
            }
        }
        if ($TemplateActivationStarted) {
            if ([string]::IsNullOrWhiteSpace($TemplateDestination) -or [string]::IsNullOrWhiteSpace($TemplateBackupPath)) {
                throw 'Paketvorlagen-Rollback besitzt keine vollstaendigen Pfade.'
            }
            if (Test-Path -LiteralPath $TemplateDestination) {
                Remove-Item -LiteralPath $TemplateDestination -Recurse -Force -ErrorAction Stop
            }
            if (-not (Test-Path -LiteralPath $TemplateBackupPath)) {
                throw ("Paketvorlagen-Backup fehlt: {0}" -f $TemplateBackupPath)
            }
            Move-Item -LiteralPath $TemplateBackupPath -Destination $TemplateDestination -ErrorAction Stop
        }
        $filesRestored = $true
    } catch { [void]$errors.Add("Datei-Rollback: $($_.Exception.Message)") } }

    try {
        if (-not $RegistrySnapshot.Existed) {
            if (Test-Path -LiteralPath $registryPath) { Remove-Item -LiteralPath $registryPath -Recurse -Force -ErrorAction Stop }
        } else {
            if (-not (Test-Path -LiteralPath $registryPath)) { New-Item -Path $registryPath -Force -ErrorAction Stop | Out-Null }
            $key = Get-Item -LiteralPath $registryPath -ErrorAction Stop
            $wantedNames = @($RegistrySnapshot.Values | ForEach-Object { [string]$_.Name })
            foreach ($name in @($key.GetValueNames())) {
                if ([string]$name -cnotin $wantedNames) { Remove-ItemProperty -LiteralPath $registryPath -Name $name -ErrorAction Stop }
            }
            foreach ($value in $RegistrySnapshot.Values) {
                New-ItemProperty -Path $registryPath -Name $value.Name -Value $value.Value -PropertyType $value.Kind -Force -ErrorAction Stop | Out-Null
            }
            Set-Acl -LiteralPath $registryPath -AclObject $RegistrySnapshot.Acl -ErrorAction Stop
        }
        $registryRestored = $true
    } catch { [void]$errors.Add("Registry-Rollback: $($_.Exception.Message)") }

    if ($quiesced -and $filesRestored -and $registryRestored) { foreach ($snapshot in $TaskSnapshots) {
        try {
            $current = Get-ScheduledTask -TaskName $snapshot.Name -ErrorAction SilentlyContinue
            if ($snapshot.Existed) {
                Register-ScheduledTask -TaskName $snapshot.Name -Xml $snapshot.Xml -Force -ErrorAction Stop | Out-Null
                if ($snapshot.WasRunning) { Start-ScheduledTask -TaskName $snapshot.Name -ErrorAction Stop }
            } elseif ($current) {
                Unregister-ScheduledTask -TaskName $snapshot.Name -Confirm:$false -ErrorAction Stop
            }
        } catch { [void]$errors.Add("Task-Rollback '$($snapshot.Name)': $($_.Exception.Message)") }
    } } elseif ($TaskSnapshots.Count -gt 0) {
        [void]$errors.Add('Aufgaben bleiben deaktiviert, weil Prozess-, Datei- oder Registry-Rollback nicht vollstaendig belegt ist.')
    }
    return $errors.ToArray()
}

$installMutex = $null
$installMutexHeld = $false
$transactionStarted = $false
$taskMutationStarted = $false
$registryRollback = $null
$taskRollbacks = @()
$installBackup = $null
$installStage = $null
$templateStage = $null
$templateBackup = $null
$templateDest = Join-Path $PackagesRoot 'Package_Vorlage'
$templateActivationStarted = $false
$activatedNames = New-Object System.Collections.Generic.List[string]
$finalExitCode = 0

try {
    $installMutex = New-Object System.Threading.Mutex($false, 'Global\VirtuSphere.MECMInstaller')
    try { $installMutexHeld = $installMutex.WaitOne(0) } catch [System.Threading.AbandonedMutexException] { $installMutexHeld = $true }
    if (-not $installMutexHeld) { throw 'Ein anderer VirtuSphere-MECM-Installer ist bereits aktiv.' }

    $installDir = Assert-VsOwnedInstallDirectory -Path $installDir
    $registryRollback = New-VsRegistryRollbackSnapshot -Path $registryPath
    $taskRollbacks = @(New-VsTaskRollbackSnapshots -TaskSpecs $tasks)
    $transactionStarted = $true

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
# Der Key ist vollstaendig VirtuSphere-owned. SetAccessRuleProtection entfernt
# nur geerbte Regeln; vorhandene breite explizite ACEs blieben sonst erhalten.
foreach ($existingRule in @($acl.Access)) {
    $acl.RemoveAccessRuleSpecific($existingRule)
}
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
if ($siteCode) { $settings['MECM_SiteCode'] = $siteCode }
# Nur schreiben, wenn ein Provider vorliegt: ein leerer Wert wuerde die lokale
# WMI/PSDrive-Erkennung des Site-Health-Skripts aushebeln.
if (-not [string]::IsNullOrWhiteSpace($ProviderMachine)) { $settings['MECM_ProviderMachine'] = $ProviderMachine.Trim() }
elseif ($configurationSources['MECM_ProviderMachine'] -eq 'cleared') {
    Remove-ItemProperty -Path $registryPath -Name 'MECM_ProviderMachine' -ErrorAction SilentlyContinue
    Write-Ok 'SMS-Provider-Override ausdruecklich entfernt; Laufzeiterkennung ist wieder aktiv.'
}
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
$writableByUsers = Get-VsDangerousFileSystemAclEntries -Acl (Get-Acl -Path $filesDir)
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

function Get-VsScheduledScriptProcesses {
    param([Parameter(Mandatory)][string]$ScriptPath)
    $escaped = [regex]::Escape($ScriptPath)
    return @(Get-CimInstance -ClassName Win32_Process -Filter "Name='powershell.exe' OR Name='pwsh.exe'" -ErrorAction Stop |
        Where-Object { [string]$_.CommandLine -match ('(?i)(^|[\s\"''])' + $escaped + '([\s\"'']|$)') })
}

function Wait-VsScheduledScriptStopped {
    param([Parameter(Mandatory)][string]$ScriptPath, [int]$TimeoutSeconds = 30)
    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    do {
        $running = @(Get-VsScheduledScriptProcesses -ScriptPath $ScriptPath)
        if ($running.Count -eq 0) { return }
        Start-Sleep -Milliseconds 500
    } while ((Get-Date) -lt $deadline)
    $pids = @($running | ForEach-Object { [string]$_.ProcessId }) -join ','
    throw ("Alter Aufgabenprozess fuer '{0}' ist nach {1}s noch aktiv (PID {2}); Aktivierung abgebrochen." -f $ScriptPath, $TimeoutSeconds, $pids)
}

# Den vollstaendigen Satz VOR dem Stoppen der laufenden Aufgaben in ein lokales
# Stagingverzeichnis kopieren und bytegenau pruefen. So beendet ein fehlendes
# oder versionsfalsches Loggingmodul die Installation sichtbar, ohne zuerst die
# funktionierende Altinstallation anzuhalten.
$sourceDir = Join-Path $PSScriptRoot 'mecm'
$templateSource = Join-Path $PSScriptRoot 'Package_Vorlage'
$requiredServerFiles = @('VirtuSphere-Common.ps1', 'VirtuSphere-Logging.ps1', 'VirtuSphere-MembershipJournal.ps1') + @($tasks | ForEach-Object { $_.Script })
foreach ($name in $requiredServerFiles) {
    $requiredPath = Join-Path $sourceDir $name
    if (-not (Test-Path $requiredPath)) { throw ('MECM-Serverpaket unvollstaendig: {0} fehlt.' -f $requiredPath) }
}
$requiredTemplateFiles = @('install.ps1', 'config.json')
foreach ($name in $requiredTemplateFiles) {
    $requiredPath = Join-Path $templateSource $name
    if (-not (Test-Path -LiteralPath $requiredPath -PathType Leaf)) { throw ('MECM-Serverpaket unvollstaendig: Paketvorlage {0} fehlt.' -f $requiredPath) }
}
$installStage = Join-Path $installDir ('.virtusphere-stage-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $installStage -Force -ErrorAction Stop | Out-Null
$templateStage = Join-Path $PackagesRoot ('.virtusphere-template-stage-' + [guid]::NewGuid().ToString('N'))
try {
    $serverSources = @(Get-ChildItem -Path $sourceDir -Filter '*.ps1' -File -ErrorAction Stop)
    foreach ($source in $serverSources) {
        Copy-Item -Path $source.FullName -Destination $installStage -Force -ErrorAction Stop
        $staged = Join-Path $installStage $source.Name
        if ((Get-FileHash -Algorithm SHA256 -Path $source.FullName).Hash -ne (Get-FileHash -Algorithm SHA256 -Path $staged).Hash) {
            throw ('MECM-Serverpaket-Pruefsumme weicht ab: {0}' -f $source.Name)
        }
    }
    New-Item -ItemType Directory -Path $templateStage -Force -ErrorAction Stop | Out-Null
    $templateRoot = (Get-Item -LiteralPath $templateSource -Force -ErrorAction Stop)
    if (-not $templateRoot.PSIsContainer -or ($templateRoot.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        throw ("Paketvorlagen-Quelle ist kein echtes Verzeichnis: {0}" -f $templateSource)
    }
    $templateSources = @(Get-ChildItem -LiteralPath $templateSource -Recurse -File -Force -ErrorAction Stop |
        Where-Object { $_.Name -ne '.gitkeep' } | Sort-Object FullName)
    $templateDirectories = @(Get-ChildItem -LiteralPath $templateSource -Recurse -Directory -Force -ErrorAction Stop | Sort-Object FullName)
    $templateDirectoryManifest = New-Object System.Collections.Generic.List[string]
    foreach ($directory in $templateDirectories) {
        $relative = $directory.FullName.Substring($templateRoot.FullName.TrimEnd('\').Length).TrimStart('\')
        New-Item -ItemType Directory -Path (Join-Path $templateStage $relative) -Force -ErrorAction Stop | Out-Null
        [void]$templateDirectoryManifest.Add($relative)
    }
    $templateManifest = New-Object System.Collections.Generic.List[object]
    foreach ($source in $templateSources) {
        $relative = $source.FullName.Substring($templateRoot.FullName.TrimEnd('\').Length).TrimStart('\')
        $staged = Join-Path $templateStage $relative
        $stagedParent = Split-Path -Parent $staged
        if (-not (Test-Path -LiteralPath $stagedParent)) { New-Item -ItemType Directory -Path $stagedParent -Force -ErrorAction Stop | Out-Null }
        Copy-Item -LiteralPath $source.FullName -Destination $staged -Force -ErrorAction Stop
        $sourceHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $source.FullName -ErrorAction Stop).Hash
        $stagedHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $staged -ErrorAction Stop).Hash
        if ($source.Length -ne (Get-Item -LiteralPath $staged -ErrorAction Stop).Length -or $sourceHash -cne $stagedHash) {
            throw ('Paketvorlagen-Pruefsumme weicht ab: {0}' -f $relative)
        }
        [void]$templateManifest.Add([pscustomobject]@{ RelativePath = $relative; Length = $source.Length; Sha256 = $sourceHash })
    }
    $stagedExpectedVersion = Get-VsDeclaredScriptInteger -Path (Join-Path $installStage 'VirtuSphere-Common.ps1') -VariableName 'VsExpectedLoggingContractVersion'
    $stagedVersion = Get-VsDeclaredScriptInteger -Path (Join-Path $installStage 'VirtuSphere-Logging.ps1') -VariableName 'VsLoggingContractVersion'
    $installerVersion = Get-VsLoggingContractVersion
    if ($stagedVersion -ne $stagedExpectedVersion -or $stagedVersion -ne $installerVersion) {
        throw ('Logging-Vertrag des Stagings hat Version {0}, Common erwartet {1}, der Installer erwartet {2}.' -f $stagedVersion, $stagedExpectedVersion, $installerVersion)
    }
} catch {
    Remove-Item -Path $installStage -Recurse -Force -ErrorAction SilentlyContinue
    throw
}

# --- Aufgaben VOR dem Kopieren deaktivieren und beenden ---------------------
#
# Die Skripte sind Endlosschleifen und dot-sourcen VirtuSphere-Common.ps1 nur
# einmal beim Start. Ein Re-Run, der die Dateien unter einer laufenden Instanz
# austauscht, laesst diese Instanz mit dem ALTEN Common weiterlaufen, waehrend
# die neue Registry-Konfiguration schon da ist: eine Aufgabe, die eine gerade
# eingefuehrte Wire-Aenderung nicht kennt, meldet weiter im alten Format, und
# die Systemstatus-Seite zeigt einen frisch installierten Stand, der nicht
# laeuft. Ausserdem haelt eine laufende Instanz die .ps1 nicht offen, aber die
# Logdatei: Copy-Item scheitert nicht, das Ergebnis ist nur unbestimmt.
# Die stuendlichen Trigger werden zuerst deaktiviert. Andernfalls kann zwischen
# Stop und Live-Move eine neue Instanz anlaufen. Jeder Fehler bricht fail-closed
# ab; ein gemischter Satz darf niemals unter einer weiterlaufenden Aufgabe
# sichtbar werden. Unten werden alle Aufgaben vollstaendig neu registriert.
Write-Step 'Deaktiviere und beende laufende Aufgaben'
$taskMutationStarted = $true
foreach ($task in $tasks) {
    $existing = Get-ScheduledTask -TaskName $task.Name -ErrorAction SilentlyContinue
    if (-not $existing) { continue }
    try {
        Disable-ScheduledTask -TaskName $task.Name -ErrorAction Stop | Out-Null
        Write-Ok ("Aufgabe deaktiviert: {0}" -f $task.Name)
        # Nach dem Disable erneut lesen, damit eine gerade angelaufene Instanz
        # ebenfalls sicher gestoppt wird.
        $existing = Get-ScheduledTask -TaskName $task.Name -ErrorAction Stop
        if ($existing.State -eq 'Running') {
            Stop-ScheduledTask -TaskName $task.Name -ErrorAction Stop
            Write-Ok ("Aufgabe beendet: {0}" -f $task.Name)
        }
        Wait-VsScheduledScriptStopped -ScriptPath (Join-Path $installDir $task.Script)
    } catch {
        throw ("Aufgabe '{0}' konnte vor dem sicheren Skriptaustausch nicht deaktiviert und beendet werden: {1}" -f $task.Name, $_.Exception.Message)
    }
}
# Das Loggingmodul kommt zuerst, Common als versionspruefende Fassade zuletzt.
# Die Tasks sind gestoppt und werden erst nach der Live-Verifikation gestartet;
# ein Abbruch mitten im Satz fuehrt daher beim naechsten Laden fail-closed statt
# zu einer gemischten, weiterlaufenden Version.
$installBackup = Join-Path $installDir ('.virtusphere-backup-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $installBackup -Force -ErrorAction Stop | Out-Null
try {
    $orderedNames = @($serverSources.Name | Sort-Object @{ Expression = {
        if ($_ -eq 'VirtuSphere-Logging.ps1') { 0 }
        elseif ($_ -eq 'VirtuSphere-Common.ps1') { 2 }
        else { 1 }
    } }, @{ Expression = { $_ } })
    foreach ($name in $orderedNames) {
        $livePath = Join-Path $installDir $name
        if (Test-Path -LiteralPath $livePath) {
            $liveItem = Get-Item -LiteralPath $livePath -Force -ErrorAction Stop
            if ($liveItem.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw ("Installationsdatei ist ein Reparse Point: {0}" -f $livePath) }
            Move-Item -LiteralPath $livePath -Destination (Join-Path $installBackup $name) -ErrorAction Stop
        }
        Move-Item -Path (Join-Path $installStage $name) -Destination (Join-Path $installDir $name) -Force -ErrorAction Stop
        $activatedNames.Add($name)
    }
    $templateBackup = Join-Path $PackagesRoot ('.virtusphere-template-backup-' + [guid]::NewGuid().ToString('N'))
    $templateLive = Get-Item -LiteralPath $templateDest -Force -ErrorAction Stop
    if (-not $templateLive.PSIsContainer -or ($templateLive.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        throw ("Paketvorlagen-Ziel ist kein echtes Verzeichnis: {0}" -f $templateDest)
    }
    Move-Item -LiteralPath $templateDest -Destination $templateBackup -ErrorAction Stop
    $templateActivationStarted = $true
    Move-Item -LiteralPath $templateStage -Destination $templateDest -ErrorAction Stop

    $liveTemplateFiles = @(Get-ChildItem -LiteralPath $templateDest -Recurse -File -Force -ErrorAction Stop | Sort-Object FullName)
    if ($liveTemplateFiles.Count -ne $templateManifest.Count) {
        throw 'Aktivierte Paketvorlage enthaelt nicht den vollstaendigen geprueften Dateisatz.'
    }
    foreach ($entry in $templateManifest) {
        $livePath = Join-Path $templateDest $entry.RelativePath
        if (-not (Test-Path -LiteralPath $livePath -PathType Leaf) -or
            (Get-Item -LiteralPath $livePath -ErrorAction Stop).Length -ne $entry.Length -or
            (Get-FileHash -Algorithm SHA256 -LiteralPath $livePath -ErrorAction Stop).Hash -cne $entry.Sha256) {
            throw ('Aktivierte Paketvorlagen-Pruefsumme weicht ab: {0}' -f $entry.RelativePath)
        }
    }
    $liveTemplateDirectories = @(Get-ChildItem -LiteralPath $templateDest -Recurse -Directory -Force -ErrorAction Stop | ForEach-Object {
        $_.FullName.Substring($templateDest.TrimEnd('\').Length).TrimStart('\')
    } | Sort-Object)
    if (($liveTemplateDirectories -join "`n") -cne (@($templateDirectoryManifest | Sort-Object) -join "`n")) {
        throw 'Aktivierte Paketvorlage enthaelt nicht den vollstaendigen geprueften Verzeichnissatz.'
    }
} finally {
    if (Test-Path $installStage) { Remove-Item -Path $installStage -Recurse -Force -ErrorAction SilentlyContinue }
    if ($templateStage -and (Test-Path -LiteralPath $templateStage)) { Remove-Item -LiteralPath $templateStage -Recurse -Force -ErrorAction SilentlyContinue }
}
$installedExpectedVersion = Get-VsDeclaredScriptInteger -Path (Join-Path $installDir 'VirtuSphere-Common.ps1') -VariableName 'VsExpectedLoggingContractVersion'
$installedVersion = Get-VsDeclaredScriptInteger -Path (Join-Path $installDir 'VirtuSphere-Logging.ps1') -VariableName 'VsLoggingContractVersion'
if ($installedVersion -ne $installedExpectedVersion -or $installedVersion -ne $installerVersion) {
    throw ('Installiertes Loggingmodul hat Version {0}, Common erwartet {1}, der Installer erwartet {2}. Aufgaben bleiben deaktiviert.' -f $installedVersion, $installedExpectedVersion, $installerVersion)
}
Write-Ok "Skripte samt Loggingmodul nach $installDir kopiert und verifiziert"
Write-Ok "Package-Vorlage nach $templateDest aktiviert und per SHA-256 verifiziert"

# --- Geplante Aufgaben ------------------------------------------------------
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
    else { Write-Hint ("Noch kein frisches Log innerhalb des kurzen Installerfensters: {0}. Das ist bei langer/idle Cadence eine ausstehende Bestaetigung; Aufgabenplanung, Tageslog und Portal-Statusseite spaeter pruefen." -f $comp) }
}

# --- Abschluss-Marker -------------------------------------------------------
if ($script:VsInstallBlockers -eq 0) {
    New-ItemProperty -Path $registryPath -Name 'SetupCompleted' -Value (Get-Date -Format 'o') -PropertyType String -Force -ErrorAction Stop | Out-Null
} else {
    Remove-ItemProperty -Path $registryPath -Name 'SetupCompleted' -ErrorAction SilentlyContinue
}

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
Write-Host ('  4. Client-Bootstrap: install-VirtuSphere-Clients.ps1 mit -WebApi "{0}" und demselben -Scheme ausfuehren. Der Client-Installer erzeugt bootstrap.json; Clientquelltext wird nicht bearbeitet.' -f $WebApi) -ForegroundColor Gray
Write-Host '  5. Seite "Systemstatus" im Portal beobachten - die Ampeln werden gruen.' -ForegroundColor Gray

# Exit-Code als maschinenlesbare Fassung der Schlusszeile: Voraussetzung dafuer,
# dass ein Rollout-Skript den Installer je pruefen kann.
if ($script:VsInstallBlockers -gt 0) { $finalExitCode = 1 }

# Erst hier ist Registry, Dateisatz und Aufgabenregistrierung gemeinsam
# bestaetigt. Bis zu dieser Grenze bleibt der Alt-Dateisatz rollbackfaehig.
if ($installBackup -and (Test-Path -LiteralPath $installBackup)) {
    try { Remove-Item -LiteralPath $installBackup -Recurse -Force -ErrorAction Stop }
    catch { Write-Hint ("Altdatei-Backup konnte nach erfolgreicher Aktivierung nicht entfernt werden: {0}" -f $_.Exception.Message) }
}
if ($templateBackup -and (Test-Path -LiteralPath $templateBackup)) {
    try { Remove-Item -LiteralPath $templateBackup -Recurse -Force -ErrorAction Stop }
    catch { Write-Hint ("Paketvorlagen-Backup konnte nach erfolgreicher Aktivierung nicht entfernt werden: {0}" -f $_.Exception.Message) }
}
} catch {
    $installError = $_
    $rollbackErrors = @()
    if ($transactionStarted) {
        $tasksToRestore = if ($taskMutationStarted) { $taskRollbacks } else { @() }
        $rollbackErrors = @(Restore-VsInstallTransaction -RegistrySnapshot $registryRollback -TaskSnapshots $tasksToRestore -BackupPath $installBackup -ActivatedFiles $activatedNames `
            -TemplateBackupPath $templateBackup -TemplateDestination $templateDest -TemplateActivationStarted $templateActivationStarted)
    }
    if ($installStage -and (Test-Path -LiteralPath $installStage)) { Remove-Item -LiteralPath $installStage -Recurse -Force -ErrorAction SilentlyContinue }
    if ($templateStage -and (Test-Path -LiteralPath $templateStage)) { Remove-Item -LiteralPath $templateStage -Recurse -Force -ErrorAction SilentlyContinue }
    if ($installBackup -and (Test-Path -LiteralPath $installBackup) -and $rollbackErrors.Count -eq 0) { Remove-Item -LiteralPath $installBackup -Recurse -Force -ErrorAction SilentlyContinue }
    if ($templateBackup -and (Test-Path -LiteralPath $templateBackup) -and $rollbackErrors.Count -eq 0) { Remove-Item -LiteralPath $templateBackup -Recurse -Force -ErrorAction SilentlyContinue }
    if ($rollbackErrors.Count -gt 0) {
        throw ("Installation fehlgeschlagen: {0} Rollback unvollstaendig: {1}" -f $installError.Exception.Message, ($rollbackErrors -join ' | '))
    }
    throw ("Installation fehlgeschlagen; vorheriger Registry-, Datei- und Aufgabenstand wurde wiederhergestellt: {0}" -f $installError.Exception.Message)
} finally {
    if ($installMutexHeld -and $installMutex) {
        try { $installMutex.ReleaseMutex() } catch { Write-Debug $_ }
    }
    if ($installMutex) { $installMutex.Dispose() }
}

exit $finalExitCode
