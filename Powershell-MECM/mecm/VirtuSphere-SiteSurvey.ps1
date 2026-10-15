# Read-only Standortbefund fuer den MECM-Client-Lieferweg (MC02 Restpruefungen).
#
# Nur Funktionen: Dot-Sourcing hat keinerlei Seiteneffekte. Das Modul veraendert
# nichts in MECM, WMI, Registry oder am Contentshare. Die Ausgabe ist ein JSON-
# Objekt auf stdout; Fortschritt geht ueber Write-Host. Das Einstiegsskript
# survey-VirtuSphere-MecmSite.ps1 verdrahtet Konfiguration und Tageslog; die
# Bausteine hier sind einzeln testbar.
#
# Jede MECM-Site: ohne VirtuSphere-Registry und ohne VirtuSphere-Objekte ist der
# Bericht die Ausgangslage einer frischen Site (leere Listen, Status ok). Keine
# standortbezogenen Konstanten; Site-Code, Provider, Freigaben und Pfade kommen
# zur Laufzeit.
#
# Braucht VirtuSphere-Common.ps1 (Initialize-VsCmSite), VirtuSphere-ClientPackaging.ps1
# (Spec, Policy, Get-VsObjectPropertyText), VirtuSphere-ClientPreflight.ps1
# (Get-VsClientSupersedenceRows) und VirtuSphere-LegacyRetirement.ps1
# (Read-VsApplicationGroupMembership, Read-VsLegacySimilarNames, Get-VsLegacyLogicalName).
Set-StrictMode -Version 1.0

$script:VsSurveySchema = 'virtusphere.mecm-site-survey/1'
$script:VsSurveyMaxStringLength = 262144
$script:VsSurveyMaxNestingDepth = 4
$script:VsSurveyMaxLimitingChain = 64
$script:VsSurveySystemProperties = @('CimSystemProperties', 'CimClass', 'CimInstanceProperties', 'PSComputerName')
$script:VsSurveyRedactPattern = '(?i)password|secret|credential'
$script:VsSurveySectionNames = @(
    'environment', 'classSchemas', 'applications', 'deployments', 'references', 'securityScopes',
    'collections', 'content', 'folders', 'sourceMapping', 'legacy'
)
# Abschnitte, deren Zielmenge aus einem frueheren Abschnitt stammt. Ist der
# Vorgaenger gescheitert, ist eine leere Liste kein Befund, sondern unbekannt.
$script:VsSurveyPrerequisites = @{
    deployments = @('applications')
    references = @('applications')
    securityScopes = @('applications', 'deployments')
    collections = @('deployments')
    content = @('applications')
    folders = @('applications')
    sourceMapping = @('applications')
}
$script:VsSurveyClassNames = @(
    'SMS_Application', 'SMS_ApplicationLatest', 'SMS_DeploymentType', 'SMS_ApplicationAssignment',
    'SMS_AppDependenceRelation', 'SMS_CIRelation', 'SMS_ApplicationGroup', 'SMS_TaskSequencePackage',
    'SMS_TaskSequenceAppReferencesInfo', 'SMS_SecuredCategory', 'SMS_SecuredCategoryMembership', 'SMS_Admin',
    'SMS_Collection', 'SMS_CollectionSettings', 'SMS_CollectionDependencies', 'SMS_DistributionPointGroup',
    'SMS_PackageStatusDistPointsSummarizer', 'SMS_ObjectContentExtraInfo', 'SMS_ObjectContainerNode',
    'SMS_ObjectContainerItem', 'SMS_R_System'
)
# IDs und Schluessel, die in eine WQL-Bedingung gehen. Alles andere wird nicht
# abgefragt, sondern als Notiz gemeldet.
$script:VsSurveyIdPattern = '\A[A-Za-z0-9]{1,16}\z'
$script:VsSurveyKeyPattern = '\A[A-Za-z0-9_./-]{1,256}\z'

# Ein Wert wird zu einem reinen, JSON-faehigen Wert: DateTime als ISO-8601 UTC,
# Arrays elementweise, eingebettete Provider-Objekte (CIM-Instanzen wie
# SMS_ServiceWindow, CM-Ergebnisobjekte, Hashtables) als verschachtelter
# Datensatz bis zur Tiefe $script:VsSurveyMaxNestingDepth, darunter als Text.
function ConvertTo-VsSurveyValue {
    param($Value, [int]$Depth = 0)
    if ($null -eq $Value) { return $null }
    if ($Value -is [DateTime]) { return ([DateTime]$Value).ToUniversalTime().ToString('o') }
    if ($Value -is [string]) { return (Format-VsSurveyText -Text $Value) }
    if ($Value -is [bool] -or $Value -is [byte] -or $Value -is [int16] -or $Value -is [int32] -or $Value -is [int64] -or
        $Value -is [uint16] -or $Value -is [uint32] -or $Value -is [uint64] -or $Value -is [single] -or $Value -is [double] -or $Value -is [decimal]) {
        return $Value
    }
    if (Test-VsSurveyNestedObject -Value $Value) {
        if ($Depth -ge $script:VsSurveyMaxNestingDepth) { return (Format-VsSurveyText -Text ([string]$Value)) }
        return (ConvertTo-VsSurveyRecord -Object $Value -Depth ($Depth + 1))
    }
    if ($Value -is [Collections.IEnumerable]) {
        $items = New-Object System.Collections.Generic.List[object]
        foreach ($item in $Value) { $items.Add((ConvertTo-VsSurveyValue -Value $item -Depth $Depth)) }
        return , $items.ToArray()
    }
    return (Format-VsSurveyText -Text ([string]$Value))
}

# Typname statt Typliteral: Die CIM- und CM-Typen muessen auf dem Testrechner
# nicht geladen sein.
function Test-VsSurveyNestedObject {
    param($Value)
    if ($Value -is [Collections.IDictionary] -or $Value -is [Management.Automation.PSCustomObject]) { return $true }
    $typeName = $Value.GetType().FullName
    return ($typeName -ceq 'Microsoft.Management.Infrastructure.CimInstance' -or $typeName -clike 'Microsoft.ConfigurationManagement.*')
}

function Format-VsSurveyText {
    param([AllowNull()][string]$Text)
    if ($null -eq $Text) { return $null }
    if ($Text.Length -le $script:VsSurveyMaxStringLength) { return $Text }
    $cut = $script:VsSurveyMaxStringLength
    return ('{0}...[truncated {1} chars]' -f $Text.Substring(0, $cut), ($Text.Length - $cut))
}

# Wandelt ein Ergebnisobjekt (CIM-Instanz, CM-IResultObject-Wrapper, Hashtable)
# in eine geordnete Hashtable aller nicht-systemischen Eigenschaften.
# Lazy-Eigenschaften werden hier NICHT nachgeladen; das macht der Aufrufer gezielt.
function ConvertTo-VsSurveyRecord {
    param([AllowNull()]$Object, [string[]]$Exclude = @(), [int]$Depth = 0)
    $record = [ordered]@{}
    if ($null -eq $Object) { return $record }
    if ($Object -is [Collections.IDictionary]) {
        $pairs = @(foreach ($key in @($Object.Keys)) { [pscustomobject]@{ Name = [string]$key; Value = $Object[$key] } })
    } else {
        $pairs = @($Object.PSObject.Properties | ForEach-Object { [pscustomobject]@{ Name = [string]$_.Name; Value = $_.Value } })
    }
    foreach ($pair in $pairs) {
        $name = $pair.Name
        if ($name -cin $script:VsSurveySystemProperties) { continue }
        if ($name -cin $Exclude) { continue }
        if ($name -match $script:VsSurveyRedactPattern) {
            $record[$name] = '[redacted]'
            continue
        }
        $record[$name] = ConvertTo-VsSurveyValue -Value $pair.Value -Depth $Depth
    }
    return $record
}

# Konvertiert eine Trefferliste elementweise in Datensaetze. Gibt die Datensaetze
# einzeln aus; Aufrufer sammeln mit @(...), damit auch null oder ein Treffer
# eine Liste bleibt.
function ConvertTo-VsSurveyRecords {
    param([AllowNull()][object[]]$Objects, [string[]]$Exclude = @())
    foreach ($object in @($Objects)) {
        if ($null -eq $object) { continue }
        ConvertTo-VsSurveyRecord -Object $object -Exclude $Exclude
    }
}

# Liest eine UNC-Angabe wie \\SERVER\share\a\b in Host, Freigabe und Restpfad.
# Ein abschliessender Backslash (so stehen Content-Pfade im SDMPackageXML) wird
# entfernt. Liefert $null bei jeder Fehlform (kein UNC, fehlende Freigabe).
function ConvertFrom-VsSurveyUncPath {
    param([AllowNull()][string]$Path)
    if ([string]::IsNullOrWhiteSpace($Path)) { return $null }
    $text = $Path.Trim()
    if ($text.Length -gt 2) { $text = $text.TrimEnd('\') }
    if ($text -cnotmatch '\A\\\\(?<host>[A-Za-z0-9][A-Za-z0-9.\-]*)\\(?<share>[^\\/:*?"<>|\x00-\x1F]+)(?<rest>(\\[^\\/:*?"<>|\x00-\x1F]+)*)\z') { return $null }
    # Relativsegmente fuehren aus der Freigabe heraus; solche Angaben ordnet die
    # Erhebung keinem lokalen Pfad zu.
    foreach ($segment in @(([string]$Matches['share'] + [string]$Matches['rest']) -split '\\')) {
        if ($segment -ceq '.' -or $segment -ceq '..') { return $null }
    }
    return [pscustomobject]@{
        Host = [string]$Matches['host']
        Share = [string]$Matches['share']
        Rest = [string]$Matches['rest']
    }
}

# Ordnet eine UNC-Angabe nur dann einem lokalen Freigabepfad zu, wenn der Host
# dieser Rechner ist (NetBIOS- oder FQDN-Name, Gross-/Kleinschreibung egal).
function Get-VsSurveyLocalPathForUnc {
    param(
        [AllowNull()][string]$Path,
        [AllowEmptyCollection()][object[]]$LocalShares,
        [Parameter(Mandatory)][AllowEmptyCollection()][string[]]$LocalNames
    )
    $parsed = ConvertFrom-VsSurveyUncPath -Path $Path
    if ($null -eq $parsed) { return [pscustomobject]@{ Parsed = $false; Host = ''; Share = ''; IsLocal = $false; ShareMatch = ''; LocalPath = '' } }
    $isLocal = @($LocalNames | Where-Object { $_ -and [string]$_ -ieq $parsed.Host }).Count -gt 0
    $result = [pscustomobject]@{ Parsed = $true; Host = $parsed.Host; Share = $parsed.Share; IsLocal = $isLocal; ShareMatch = ''; LocalPath = '' }
    if (-not $isLocal) { return $result }
    $share = @($LocalShares | Where-Object { $_ -and [string]$_.Name -ieq $parsed.Share } | Select-Object -First 1)
    if ($share.Count -eq 0 -or -not $share[0].Path) { return $result }
    $result.ShareMatch = [string]$share[0].Name
    $result.LocalPath = ([string]$share[0].Path).TrimEnd('\') + $parsed.Rest
    return $result
}

# Alle Content-Orte eines Deployment-Type-Manifests (AppMgmtDigest), ohne
# Namespace-Annahme ueber local-name().
function Get-VsSurveyContentLocations {
    param([AllowNull()][string]$Xml)
    if ([string]::IsNullOrWhiteSpace($Xml)) { return }
    [xml]$document = $Xml
    @($document.SelectNodes("//*[local-name()='Location']") | ForEach-Object { ([string]$_.InnerText).Trim() } |
        Where-Object { $_ } | Sort-Object -Unique)
}

# Eine Fortschrittszeile im Repository-Format. Sie geht nur ueber Write-Host,
# niemals auf stdout, damit das JSON sauber bleibt.
function Format-VsSurveyProgress {
    param(
        [Parameter(Mandatory)][int]$Position,
        [Parameter(Mandatory)][int]$Total,
        [Parameter(Mandatory)][string]$Phase,
        [Parameter(Mandatory)][string]$Unit
    )
    return ('[{0}/{1}] {2} {3}' -f $Position, $Total, $Phase, $Unit)
}

function Write-VsSurveyNote {
    param([Parameter(Mandatory)][string]$Text)
    $script:VsSurveyNotes.Add((Format-VsSurveyText -Text $Text))
}

# Gemeinsamer Abschnittsrahmen: Fortschritt vor und nach jedem Abschnitt, ein
# Fehler beendet nur diesen Abschnitt. Teilfehler einzelner Lesevorgaenge
# sammeln sich ueber Write-VsSurveyNote und machen den Abschnitt 'partial'.
function Get-VsSurveySection {
    param(
        [Parameter(Mandatory)][string]$Name,
        [Parameter(Mandatory)][int]$Position,
        [Parameter(Mandatory)][int]$Total,
        [Parameter(Mandatory)][scriptblock]$Body,
        $Argument
    )
    Write-Host (Format-VsSurveyProgress -Position $Position -Total $Total -Phase 'RUN' -Unit $Name)
    $script:VsSurveyNotes = New-Object System.Collections.Generic.List[string]
    $script:VsSurveyAbsentFlag = $false
    $status = 'ok'
    $errorText = ''
    $data = $null
    try {
        $data = & $Body $Argument
        if ($script:VsSurveyAbsentFlag) { $status = 'absent' }
        elseif ($script:VsSurveyNotes.Count -gt 0) {
            $status = 'partial'
            $errorText = ($script:VsSurveyNotes -join '; ')
        }
    } catch {
        $status = 'failed'
        $errorText = [string]$_.Exception.Message
        $data = $null
    }
    Write-Host (Format-VsSurveyProgress -Position $Position -Total $Total -Phase $status -Unit $Name)
    return [ordered]@{ status = $status; error = (Format-VsSurveyText -Text $errorText); data = $data }
}

# Ein gescheiterter Vorgaenger macht eine leere Zielmenge unbekannt.
function Assert-VsSurveyPrerequisite {
    param([Parameter(Mandatory)][hashtable]$Context, [Parameter(Mandatory)][string]$Section)
    foreach ($name in @($script:VsSurveyPrerequisites[$Section])) {
        if ($name -and @($Context.FailedSections) -ccontains $name) {
            Write-VsSurveyNote -Text ('Zielmenge unvollstaendig: Abschnitt {0} ist gescheitert' -f $name)
        }
    }
}

# Einzige Stelle fuer CIM-Abfragen. Liegt der SMS-Provider nicht auf diesem
# Rechner, sondern hinter einem vorhandenen CMSite-Laufwerk, geht die Abfrage an
# dessen Rechner (offene Probe PS-02); es wird kein Laufwerk angelegt.
function Get-VsSurveyCim {
    param(
        [Parameter(Mandatory)][hashtable]$Context,
        [Parameter(Mandatory)][string]$ClassName,
        [string]$Filter,
        [string[]]$Property,
        [string]$Namespace
    )
    if (-not $Namespace) { $Namespace = $Context.Namespace }
    $arguments = @{ Namespace = $Namespace; ClassName = $ClassName; ErrorAction = 'Stop' }
    if ($Filter) { $arguments.Filter = $Filter }
    if ($Property) { $arguments.Property = $Property }
    if ($Context.CimComputer) { $arguments.ComputerName = $Context.CimComputer }
    Get-CimInstance @arguments
}

# Laedt die Lazy-Eigenschaften genau eines ausgewaehlten Objekts nach.
function Get-VsSurveyCimFull {
    param([Parameter(Mandatory)]$InputObject)
    Get-CimInstance -InputObject $InputObject -ErrorAction Stop
}

function ConvertTo-VsSurveyWqlString {
    param([AllowNull()][string]$Value)
    return ("'{0}'" -f ([string]$Value).Replace('\', '\\').Replace("'", "\'"))
}

# Zeilen, bei denen eine Eigenschaft, deren Name auf $NamePattern passt, einen
# der gesuchten Werte traegt. Die Feldnamen der Provider-Klassen sind genau das,
# was die Erhebung erst feststellt; deshalb kein fester Feldname.
function Select-VsSurveyRowsReferencing {
    param(
        [AllowNull()][object[]]$Rows,
        [AllowNull()][AllowEmptyCollection()][string[]]$Values,
        [Parameter(Mandatory)][string]$NamePattern
    )
    $wanted = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase)
    foreach ($value in @($Values)) { if ($value) { [void]$wanted.Add([string]$value) } }
    if ($wanted.Count -eq 0) { return }
    foreach ($row in @($Rows)) {
        if ($null -eq $row) { continue }
        foreach ($property in @($row.PSObject.Properties)) {
            if ([string]$property.Name -notmatch $NamePattern -or $null -eq $property.Value) { continue }
            if ($wanted.Contains([string]$property.Value)) { $row; break }
        }
    }
}

function Get-VsSurveyComputerNames {
    $names = New-Object System.Collections.Generic.List[string]
    $names.Add([string]$env:COMPUTERNAME)
    try {
        $fqdn = [string]([Net.Dns]::GetHostEntry([string]$env:COMPUTERNAME).HostName)
        if ($fqdn) { $names.Add($fqdn) }
    } catch {
        Write-Verbose ([string]$_)
    }
    if ($env:USERDNSDOMAIN -and $env:COMPUTERNAME) { $names.Add(('{0}.{1}' -f $env:COMPUTERNAME, $env:USERDNSDOMAIN)) }
    @($names | Where-Object { $_ } | Sort-Object -Unique)
}

# SID des Aufrufers und seiner Gruppen, fuer die eigene Zeile in SMS_Admin.
function Get-VsSurveyCurrentSids {
    try {
        $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
        $sids = New-Object System.Collections.Generic.List[string]
        $sids.Add([string]$identity.User.Value)
        foreach ($group in @($identity.Groups)) { $sids.Add([string]$group.Value) }
        @($sids | Where-Object { $_ } | Sort-Object -Unique)
    } catch {
        Write-Verbose ([string]$_)
    }
}

# Wohin die CIM-Abfragen gehen: zuerst der lokale Provider (root\SMS\site_<code>),
# sonst der Rechner hinter einem bereits vorhandenen CMSite-Laufwerk.
function Resolve-VsSurveyProvider {
    param([Parameter(Mandatory)][string]$SiteCode)
    try {
        $local = @(Get-CimInstance -Namespace 'root\SMS' -ClassName '__NAMESPACE' -ErrorAction Stop |
            Where-Object { [string]$_.Name -ieq ('site_{0}' -f $SiteCode) })
        if ($local.Count -gt 0) { return [pscustomobject]@{ Source = 'local'; Computer = '' } }
    } catch {
        Write-Verbose ([string]$_)
    }
    try {
        $drive = @(Get-PSDrive -PSProvider CMSite -ErrorAction Stop | Where-Object { [string]$_.Name -ieq $SiteCode } | Select-Object -First 1)
        if ($drive.Count -gt 0 -and $drive[0].Root) { return [pscustomobject]@{ Source = 'cmsite-drive'; Computer = [string]$drive[0].Root } }
    } catch {
        Write-Verbose ([string]$_)
    }
    return [pscustomobject]@{ Source = 'unknown'; Computer = '' }
}

function Get-VsSurveyEnvironment {
    param([Parameter(Mandatory)][hashtable]$Context)
    $cmModule = Get-Module -ListAvailable -Name ConfigurationManager | Sort-Object Version -Descending | Select-Object -First 1
    $site = @()
    try {
        $site = @(ConvertTo-VsSurveyRecords -Objects @(Get-VsSurveyCim -Context $Context -ClassName 'SMS_Site'))
    } catch {
        Write-VsSurveyNote -Text ('SMS_Site: {0}' -f [string]$_.Exception.Message)
    }
    # SMS_ProviderLocation liegt im Namespace root\SMS, nicht im Site-Namespace
    # (erster Standortlauf 15.10.2026: "Ungueltige Klasse").
    $locations = @()
    try {
        $locations = @(ConvertTo-VsSurveyRecords -Objects @(Get-VsSurveyCim -Context $Context -ClassName 'SMS_ProviderLocation' -Namespace 'root\SMS'))
    } catch {
        Write-VsSurveyNote -Text ('SMS_ProviderLocation: {0}' -f [string]$_.Exception.Message)
    }
    return [ordered]@{
        PowerShellVersion = [string]$PSVersionTable.PSVersion
        ConfigurationManagerVersion = $(if ($cmModule) { [string]$cmModule.Version } else { '' })
        SiteCode = $Context.SiteCode
        ProviderSource = $Context.ProviderSource
        ProviderComputer = $Context.CimComputer
        Site = $site
        ProviderLocations = $locations
    }
}

# Ein Klassenschema, das es auf diesem Provider nicht gibt, ist 'absent' und
# kein Fehler. Eigenschaften mit Lazy- oder Key-Qualifier werden markiert.
function Get-VsSurveyClassSchema {
    param([Parameter(Mandatory)][hashtable]$Context, [Parameter(Mandatory)][string]$ClassName)
    $arguments = @{ Namespace = $Context.Namespace; ClassName = $ClassName; ErrorAction = 'Stop' }
    if ($Context.CimComputer) { $arguments.ComputerName = $Context.CimComputer }
    try {
        $schema = Get-CimClass @arguments
    } catch {
        $text = [string]$_.Exception.Message
        $id = [string]$_.FullyQualifiedErrorId
        # Get-CimClass meldet eine fehlende Klasse als HRESULT 0x80041010 (Invalid class).
        if ($id -match 'InvalidClass|NotFound|0x80041010' -or $text -match 'Invalid class|not found') {
            return [ordered]@{ status = 'absent'; error = ''; properties = @() }
        }
        return [ordered]@{ status = 'failed'; error = (Format-VsSurveyText -Text $text); properties = @() }
    }
    $properties = @(foreach ($property in @($schema.CimClassProperties)) {
        $qualifierNames = @($property.Qualifiers | ForEach-Object { [string]$_.Name })
        [ordered]@{
            name = [string]$property.Name
            cimType = [string]$property.CimType
            lazy = (@($qualifierNames | Where-Object { $_ -ieq 'lazy' }).Count -gt 0)
            key = (@($qualifierNames | Where-Object { $_ -ieq 'key' }).Count -gt 0)
        }
    })
    return [ordered]@{ status = 'ok'; error = ''; properties = $properties }
}

function Get-VsSurveyClassSchemas {
    param([Parameter(Mandatory)][hashtable]$Context)
    $classes = [ordered]@{}
    $found = 0
    foreach ($className in $script:VsSurveyClassNames) {
        $schema = Get-VsSurveyClassSchema -Context $Context -ClassName $className
        if ($schema.status -ceq 'ok') { $found++ }
        if ($schema.status -ceq 'failed') { Write-VsSurveyNote -Text ('{0}: {1}' -f $className, $schema.error) }
        $classes[$className] = $schema
    }
    if ($found -eq 0) { $script:VsSurveyAbsentFlag = $true }
    return [ordered]@{ classes = $classes }
}

# Zielmenge: alle Anwendungen mit dem Praefix client_, die Anwendungen der
# Client-Specs und die Altnamen aus der Policy (nur Namen, ohne Lazy-Laden).
# MECM vergleicht Anwendungsnamen ohne Gross-/Kleinschreibung; bei gleichem
# Namen gewinnt die Schreibweise der Site (erster Standortlauf: client_Hostname
# auf der Site, client_hostname in der Spec).
function Get-VsSurveyTargetNames {
    param([Parameter(Mandatory)][object[]]$Specs, [Parameter(Mandatory)][AllowEmptyCollection()][string[]]$LegacyNames)
    $seen = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase)
    $names = New-Object System.Collections.Generic.List[string]
    foreach ($application in @(Get-CMApplication -Name 'client_*' -ShowHidden -Fast -ErrorAction Stop)) {
        $displayName = Get-VsObjectPropertyText -Object $application -Names @('LocalizedDisplayName', 'Name')
        if ($displayName -like 'client_*' -and $seen.Add($displayName)) { $names.Add($displayName) }
    }
    foreach ($name in @(@($Specs | ForEach-Object { [string]$_.AppName }) + @($LegacyNames))) {
        if ($name -and $seen.Add([string]$name)) { $names.Add([string]$name) }
    }
    @($names | Sort-Object)
}

# Eine Anwendung je Zielname mit ihren Deployment Types. SDMPackageXML ist eine
# Lazy-Eigenschaft: Get-CMApplication mit -Name (ohne -Fast) liefert sie mit.
# Deployment Types nur fuer gefundene Anwendungen: ein fehlender Name ist auf
# einer frischen Site der Normalfall, kein Lesefehler.
function Get-VsSurveyApplications {
    param([Parameter(Mandatory)][hashtable]$Context)
    $targets = @(Get-VsSurveyTargetNames -Specs $Context.Specs -LegacyNames $Context.LegacyNames)
    $Context.Targets = $targets
    $rows = New-Object System.Collections.Generic.List[object]
    $appObjects = New-Object System.Collections.Generic.List[object]
    $appEntries = New-Object System.Collections.Generic.List[object]
    $typeObjects = New-Object System.Collections.Generic.List[object]
    foreach ($name in $targets) {
        $row = [ordered]@{ name = $name; applications = @(); deploymentTypes = @(); error = '' }
        $applications = @()
        try {
            $applications = @(Get-CMApplication -Name $name -ShowHidden -DisableWildcardHandling -ErrorAction Stop |
                Where-Object { (Get-VsObjectPropertyText -Object $_ -Names @('LocalizedDisplayName', 'Name')) -ieq $name })
            $row.applications = @(ConvertTo-VsSurveyRecords -Objects $applications)
            foreach ($application in $applications) {
                $appObjects.Add($application)
                $appEntries.Add([pscustomobject]@{ Name = $name; Object = $application })
            }
        } catch {
            $row.error = [string]$_.Exception.Message
            Write-VsSurveyNote -Text ('{0}: {1}' -f $name, $row.error)
        }
        if ($applications.Count -gt 0) {
            try {
                $siteName = Get-VsObjectPropertyText -Object $applications[0] -Names @('LocalizedDisplayName', 'Name')
                $types = @(Get-CMDeploymentType -ApplicationName $siteName -ErrorAction Stop)
                $row.deploymentTypes = @(ConvertTo-VsSurveyRecords -Objects $types)
                foreach ($type in $types) { $typeObjects.Add([pscustomobject]@{ Name = $name; Object = $type }) }
            } catch {
                $message = [string]$_.Exception.Message
                $row.error = (@($row.error, $message) | Where-Object { $_ }) -join '; '
                Write-VsSurveyNote -Text ('{0} deployment types: {1}' -f $name, $message)
            }
        }
        $rows.Add($row)
    }
    $Context.ApplicationObjects = $appObjects.ToArray()
    $Context.ApplicationEntries = $appEntries.ToArray()
    $Context.DeploymentTypeObjects = $typeObjects.ToArray()
    $Context.TargetCiIds = @($appObjects | ForEach-Object { Get-VsObjectPropertyText -Object $_ -Names @('CI_ID') } | Where-Object { $_ } | Sort-Object -Unique)
    $Context.TargetModelNames = @($appObjects | ForEach-Object { Get-VsObjectPropertyText -Object $_ -Names @('ModelName') } | Where-Object { $_ } | Sort-Object -Unique)
    $Context.TargetApplicationCount = @($rows | Where-Object { @($_.applications).Count -gt 0 }).Count
    return [ordered]@{ targets = $targets; rows = $rows.ToArray() }
}

# Alle Zuweisungen, deren ApplicationName ein Zielname ist (alle Felder).
function Get-VsSurveyDeployments {
    param([Parameter(Mandatory)][hashtable]$Context)
    Assert-VsSurveyPrerequisite -Context $Context -Section 'deployments'
    $matching = @()
    if (@($Context.Targets).Count -gt 0) {
        $all = @(Get-VsSurveyCim -Context $Context -ClassName 'SMS_ApplicationAssignment')
        $matching = @($all | Where-Object { (Get-VsObjectPropertyText -Object $_ -Names @('ApplicationName')) -in $Context.Targets })
    }
    $Context.DeploymentCollectionIds = @($matching | ForEach-Object { Get-VsObjectPropertyText -Object $_ -Names @('TargetCollectionID') } | Where-Object { $_ } | Sort-Object -Unique)
    return [ordered]@{ rows = @(ConvertTo-VsSurveyRecords -Objects $matching) }
}

# Abhaengigkeiten in beide Richtungen: DT-Abhaengigkeitsgruppen, Provider-
# Relationen, Supersedence, Application Groups und Tasksequenzen.
function Get-VsSurveyReferences {
    param([Parameter(Mandatory)][hashtable]$Context)
    Assert-VsSurveyPrerequisite -Context $Context -Section 'references'
    $dependencies = New-Object System.Collections.Generic.List[object]
    $supersedence = New-Object System.Collections.Generic.List[object]
    foreach ($entry in @($Context.DeploymentTypeObjects)) {
        $typeId = Get-VsObjectPropertyText -Object $entry.Object -Names @('CI_ID')
        try {
            foreach ($group in @(Get-CMDeploymentTypeDependencyGroup -InputObject $entry.Object -ErrorAction Stop)) {
                $members = @(Get-CMDeploymentTypeDependency -InputObject $group -ErrorAction Stop)
                $dependencies.Add([ordered]@{
                    application = $entry.Name
                    deploymentTypeId = $typeId
                    group = (ConvertTo-VsSurveyRecord -Object $group -Exclude @('SDMPackageXML'))
                    dependencies = @(ConvertTo-VsSurveyRecords -Objects $members -Exclude @('SDMPackageXML'))
                })
            }
        } catch {
            Write-VsSurveyNote -Text ('{0} DT {1} dependencies: {2}' -f $entry.Name, $typeId, [string]$_.Exception.Message)
        }
        try {
            foreach ($row in @(Get-VsClientSupersedenceRows -ApplicationName $entry.Name -DeploymentType $entry.Object)) {
                $supersedence.Add((ConvertTo-VsSurveyRecord -Object $row))
            }
        } catch {
            Write-VsSurveyNote -Text ('{0} DT {1} supersedence: {2}' -f $entry.Name, $typeId, [string]$_.Exception.Message)
        }
    }

    $relations = @()
    $related = New-Object System.Collections.Generic.List[object]
    if (@($Context.TargetCiIds).Count -gt 0) {
        try {
            $all = @(Get-VsSurveyCim -Context $Context -ClassName 'SMS_AppDependenceRelation')
            $matchingRelations = @(Select-VsSurveyRowsReferencing -Rows $all -Values $Context.TargetCiIds -NamePattern '(?i)CI_?ID')
            $relations = @(ConvertTo-VsSurveyRecords -Objects $matchingRelations)
            # Die Gegenseite einer Relation ist oft keine Zielanwendung; ohne ihren
            # Namen bleibt die Kette eine Liste von CI_IDs (erster Standortlauf).
            $otherIds = @(@(foreach ($relation in $matchingRelations) {
                foreach ($property in @($relation.PSObject.Properties)) {
                    if ([string]$property.Name -match '(?i)ApplicationCI_?ID' -and $null -ne $property.Value) { [string]$property.Value }
                }
            }) | Where-Object { $_ -and $_ -notin $Context.TargetCiIds } | Sort-Object -Unique)
            foreach ($ciId in $otherIds) {
                if ($ciId -cnotmatch '\A[0-9]{1,10}\z') { Write-VsSurveyNote -Text ('related application {0}: unerwartete CI_ID' -f $ciId); continue }
                foreach ($application in @(Get-VsSurveyCim -Context $Context -ClassName 'SMS_Application' -Filter ('CI_ID = {0}' -f $ciId) `
                    -Property @('CI_ID', 'LocalizedDisplayName', 'ModelName', 'CIVersion', 'IsLatest', 'IsExpired', 'IsDeployed', 'NumberOfDeployments'))) {
                    $related.Add((ConvertTo-VsSurveyRecord -Object $application))
                }
            }
        } catch {
            Write-VsSurveyNote -Text ('SMS_AppDependenceRelation: {0}' -f [string]$_.Exception.Message)
        }
    }

    $groups = [ordered]@{ state = 'not-needed'; groupCount = 0; members = [ordered]@{}; detail = 'keine Zielanwendung vorhanden' }
    $logicalNames = @($Context.TargetModelNames | ForEach-Object { Get-VsLegacyLogicalName -ModelName $_ } | Where-Object { $_ })
    if ($logicalNames.Count -gt 0) {
        $membership = Read-VsApplicationGroupMembership -LogicalNames $logicalNames
        $members = [ordered]@{}
        foreach ($key in @($membership.Members.Keys | Sort-Object)) { $members[[string]$key] = @($membership.Members[$key]) }
        $groups = [ordered]@{ state = [string]$membership.State; groupCount = [int]$membership.GroupCount; members = $members; detail = [string]$membership.Detail }
        if ($membership.State -cne 'complete') { Write-VsSurveyNote -Text ('application groups: {0}' -f $membership.Detail) }
    }

    $tsReferences = @()
    $taskSequences = @()
    $keys = @(@($Context.TargetCiIds) + @($Context.TargetModelNames) | Where-Object { $_ })
    if ($keys.Count -gt 0) {
        try {
            $all = @(Get-VsSurveyCim -Context $Context -ClassName 'SMS_TaskSequenceAppReferencesInfo')
            $matching = @(Select-VsSurveyRowsReferencing -Rows $all -Values $keys -NamePattern '(?i)CI_?ID|ModelName')
            $tsReferences = @(ConvertTo-VsSurveyRecords -Objects $matching)
            $packageIds = @($matching | ForEach-Object { Get-VsObjectPropertyText -Object $_ -Names @('PackageID') } | Where-Object { $_ } | Sort-Object -Unique)
            $taskSequences = @(foreach ($packageId in $packageIds) {
                if ($packageId -cnotmatch $script:VsSurveyIdPattern) { Write-VsSurveyNote -Text ('task sequence {0}: unerwartete PackageID' -f $packageId); continue }
                ConvertTo-VsSurveyRecords -Objects @(Get-VsSurveyCim -Context $Context -ClassName 'SMS_TaskSequencePackage' `
                    -Filter ('PackageID = {0}' -f (ConvertTo-VsSurveyWqlString -Value $packageId)) -Property @('PackageID', 'Name'))
            })
        } catch {
            Write-VsSurveyNote -Text ('task sequence references: {0}' -f [string]$_.Exception.Message)
        }
    }

    return [ordered]@{
        deploymentTypeDependencies = $dependencies.ToArray()
        appDependenceRelations = $relations
        relatedApplications = $related.ToArray()
        supersedence = $supersedence.ToArray()
        applicationGroups = $groups
        taskSequenceReferences = $tsReferences
        taskSequences = $taskSequences
    }
}

# Sicherheitsbereiche und die eigene RBAC-Sicht: alle Bereiche, die
# Zugehoerigkeit der Zielobjekte und die SMS_Admin-Zeilen des Aufrufers.
function Get-VsSurveySecurityScopes {
    param([Parameter(Mandatory)][hashtable]$Context)
    Assert-VsSurveyPrerequisite -Context $Context -Section 'securityScopes'
    $categories = @(ConvertTo-VsSurveyRecords -Objects @(Get-VsSurveyCim -Context $Context -ClassName 'SMS_SecuredCategory'))

    $memberships = New-Object System.Collections.Generic.List[object]
    foreach ($key in @(@($Context.TargetModelNames) + @($Context.DeploymentCollectionIds) | Where-Object { $_ } | Sort-Object -Unique)) {
        if ($key -cnotmatch $script:VsSurveyKeyPattern) { Write-VsSurveyNote -Text ('scope membership {0}: unerwarteter Schluessel' -f $key); continue }
        try {
            # ObjectKey ist der dokumentierte Schluessel; das Klassenschema im Bericht belegt ihn.
            foreach ($row in @(Get-VsSurveyCim -Context $Context -ClassName 'SMS_SecuredCategoryMembership' -Filter ('ObjectKey = {0}' -f (ConvertTo-VsSurveyWqlString -Value $key)))) {
                $memberships.Add((ConvertTo-VsSurveyRecord -Object $row))
            }
        } catch {
            Write-VsSurveyNote -Text ('scope membership {0}: {1}' -f $key, [string]$_.Exception.Message)
        }
    }

    $admins = New-Object System.Collections.Generic.List[object]
    if (@($Context.CurrentSids).Count -eq 0) {
        Write-VsSurveyNote -Text 'eigene SIDs nicht lesbar; SMS_Admin-Zeilen fehlen'
    } else {
        $all = @(Get-VsSurveyCim -Context $Context -ClassName 'SMS_Admin')
        foreach ($row in @(Select-VsSurveyRowsReferencing -Rows $all -Values $Context.CurrentSids -NamePattern '\AAdminSid\z')) {
            try {
                $admins.Add((ConvertTo-VsSurveyRecord -Object (Get-VsSurveyCimFull -InputObject $row)))
            } catch {
                Write-VsSurveyNote -Text ('SMS_Admin {0}: Lazy-Felder nicht lesbar: {1}' -f (Get-VsObjectPropertyText -Object $row -Names @('LogonName')), [string]$_.Exception.Message)
                $admins.Add((ConvertTo-VsSurveyRecord -Object $row))
            }
        }
    }
    return [ordered]@{ categories = $categories; memberships = $memberships.ToArray(); currentAdmins = $admins.ToArray() }
}

# Sammlungen: Ziele der Deployments, ihre Limiting-Kette bis zur Wurzel und alle
# Sammlungen mit VirtuSphere im Namen. Ohne CollectionRules; die Einstellungen
# mit ServiceWindows (Lazy) je Sammlung.
function Get-VsSurveyCollections {
    param([Parameter(Mandatory)][hashtable]$Context)
    Assert-VsSurveyPrerequisite -Context $Context -Section 'collections'
    $found = [ordered]@{}
    $reasons = @{}
    $addCollection = {
        param($collection, [string]$reason)
        $id = Get-VsObjectPropertyText -Object $collection -Names @('CollectionID')
        if (-not $id) { return }
        if (-not $found.Contains($id)) { $found[$id] = $collection; $reasons[$id] = New-Object System.Collections.Generic.List[string] }
        if (-not $reasons[$id].Contains($reason)) { $reasons[$id].Add($reason) }
    }
    $readById = {
        param([string]$id, [string]$reason)
        if ($found.Contains($id)) { & $addCollection $found[$id] $reason; return }
        if ($id -cnotmatch $script:VsSurveyIdPattern) { Write-VsSurveyNote -Text ('collection {0}: unerwartete ID' -f $id); return }
        $rows = @(Get-VsSurveyCim -Context $Context -ClassName 'SMS_Collection' -Filter ('CollectionID = {0}' -f (ConvertTo-VsSurveyWqlString -Value $id)))
        if ($rows.Count -eq 0) { Write-VsSurveyNote -Text ('collection {0}: nicht sichtbar' -f $id); return }
        & $addCollection $rows[0] $reason
    }

    foreach ($id in @($Context.DeploymentCollectionIds)) { & $readById ([string]$id) 'deployment-target' }
    foreach ($row in @(Get-VsSurveyCim -Context $Context -ClassName 'SMS_Collection' -Filter "Name LIKE '%VirtuSphere%'")) { & $addCollection $row 'name-virtusphere' }
    # Kette bis zur Wurzel (leeres LimitToCollectionID); ein Zyklus oder eine
    # ueberlange Kette endet ohne Endlosschleife.
    foreach ($start in @($found.Keys)) {
        $visited = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase)
        [void]$visited.Add([string]$start)
        $current = $found[$start]
        for ($step = 0; $step -lt $script:VsSurveyMaxLimitingChain; $step++) {
            $limit = Get-VsObjectPropertyText -Object $current -Names @('LimitToCollectionID')
            if (-not $limit -or -not $visited.Add($limit)) { break }
            & $readById $limit 'limiting-chain'
            if (-not $found.Contains($limit)) { break }
            $current = $found[$limit]
        }
    }

    $rows = @(foreach ($id in @($found.Keys)) {
        $settings = @()
        try {
            $settings = @(foreach ($setting in @(Get-VsSurveyCim -Context $Context -ClassName 'SMS_CollectionSettings' -Filter ('CollectionID = {0}' -f (ConvertTo-VsSurveyWqlString -Value $id)))) {
                ConvertTo-VsSurveyRecord -Object (Get-VsSurveyCimFull -InputObject $setting)
            })
        } catch {
            Write-VsSurveyNote -Text ('collection settings {0}: {1}' -f $id, [string]$_.Exception.Message)
        }
        [ordered]@{
            collectionId = $id
            reasons = $reasons[$id].ToArray()
            collection = (ConvertTo-VsSurveyRecord -Object $found[$id] -Exclude @('CollectionRules'))
            settings = $settings
        }
    })
    return [ordered]@{ rows = $rows }
}

# Content: DP-Gruppen und der Verteilstand je Content-Paket der Zielanwendungen.
function Get-VsSurveyContent {
    param([Parameter(Mandatory)][hashtable]$Context)
    Assert-VsSurveyPrerequisite -Context $Context -Section 'content'
    $groups = @()
    try {
        $groups = @(ConvertTo-VsSurveyRecords -Objects @(Get-CMDistributionPointGroup -ErrorAction Stop))
    } catch {
        Write-VsSurveyNote -Text ('distribution point groups: {0}' -f [string]$_.Exception.Message)
    }

    $packageIds = New-Object System.Collections.Generic.List[string]
    foreach ($application in @($Context.ApplicationObjects)) {
        $packageId = Get-VsObjectPropertyText -Object $application -Names @('PackageID')
        if ($packageId) { $packageIds.Add($packageId) }
    }
    $contentInfo = @()
    $keys = @(@($Context.TargetCiIds) + @($Context.TargetModelNames) | Where-Object { $_ })
    if ($keys.Count -gt 0) {
        try {
            $all = @(Get-VsSurveyCim -Context $Context -ClassName 'SMS_ObjectContentExtraInfo')
            $matching = @(Select-VsSurveyRowsReferencing -Rows $all -Values $keys -NamePattern '(?i)CI_?ID|ObjectID|ModelName')
            $contentInfo = @(ConvertTo-VsSurveyRecords -Objects $matching)
            foreach ($row in $matching) {
                $packageId = Get-VsObjectPropertyText -Object $row -Names @('PackageID')
                if ($packageId) { $packageIds.Add($packageId) }
            }
        } catch {
            Write-VsSurveyNote -Text ('SMS_ObjectContentExtraInfo: {0}' -f [string]$_.Exception.Message)
        }
    }

    $distribution = @(foreach ($packageId in @($packageIds | Sort-Object -Unique)) {
        if ($packageId -cnotmatch $script:VsSurveyIdPattern) { Write-VsSurveyNote -Text ('content {0}: unerwartete PackageID' -f $packageId); continue }
        try {
            [ordered]@{
                packageId = $packageId
                distributionPoints = @(ConvertTo-VsSurveyRecords -Objects @(Get-VsSurveyCim -Context $Context -ClassName 'SMS_PackageStatusDistPointsSummarizer' `
                    -Filter ('PackageID = {0}' -f (ConvertTo-VsSurveyWqlString -Value $packageId))))
            }
        } catch {
            Write-VsSurveyNote -Text ('content {0}: {1}' -f $packageId, [string]$_.Exception.Message)
        }
    })
    return [ordered]@{ distributionPointGroups = $groups; contentInfo = $contentInfo; distribution = $distribution }
}

# Konsolenordner: Knoten mit VirtuSphere im Namen, die Ordnerzuordnung der
# Zielanwendungen und die Knoten, in denen diese liegen.
function Get-VsSurveyFolders {
    param([Parameter(Mandatory)][hashtable]$Context)
    Assert-VsSurveyPrerequisite -Context $Context -Section 'folders'
    $nodes = [ordered]@{}
    foreach ($node in @(Get-VsSurveyCim -Context $Context -ClassName 'SMS_ObjectContainerNode' -Filter "Name LIKE '%VirtuSphere%'")) {
        $nodeId = Get-VsObjectPropertyText -Object $node -Names @('ContainerNodeID')
        if ($nodeId) { $nodes[$nodeId] = $node }
    }
    $items = New-Object System.Collections.Generic.List[object]
    foreach ($modelName in @($Context.TargetModelNames)) {
        if ($modelName -cnotmatch $script:VsSurveyKeyPattern) { Write-VsSurveyNote -Text ('folder item {0}: unerwarteter Schluessel' -f $modelName); continue }
        try {
            foreach ($item in @(Get-VsSurveyCim -Context $Context -ClassName 'SMS_ObjectContainerItem' -Filter ('InstanceKey = {0}' -f (ConvertTo-VsSurveyWqlString -Value $modelName)))) {
                $items.Add($item)
            }
        } catch {
            Write-VsSurveyNote -Text ('folder item {0}: {1}' -f $modelName, [string]$_.Exception.Message)
        }
    }
    foreach ($item in $items) {
        $nodeId = Get-VsObjectPropertyText -Object $item -Names @('ContainerNodeID')
        if (-not $nodeId -or $nodes.Contains($nodeId)) { continue }
        if ($nodeId -cnotmatch '\A[0-9]{1,10}\z') { Write-VsSurveyNote -Text ('folder node {0}: unerwartete ID' -f $nodeId); continue }
        foreach ($node in @(Get-VsSurveyCim -Context $Context -ClassName 'SMS_ObjectContainerNode' -Filter ('ContainerNodeID = {0}' -f $nodeId))) { $nodes[$nodeId] = $node }
    }
    return [ordered]@{
        nodes = @(ConvertTo-VsSurveyRecords -Objects @($nodes.Values))
        items = @(ConvertTo-VsSurveyRecords -Objects $items.ToArray())
    }
}

# Wo der Content liegt: Registrywerte (falls vorhanden), lokale Freigaben und je
# Deployment Type die Content-Orte aus dem Manifest samt lokaler Zuordnung und
# Erreichbarkeit. Datei- und UNC-Pruefungen nur von einem FileSystem-Ort aus:
# Initialize-VsCmSite hat ins CM-Laufwerk gewechselt.
function Get-VsSurveySourceMapping {
    param([Parameter(Mandatory)][hashtable]$Context)
    Assert-VsSurveyPrerequisite -Context $Context -Section 'sourceMapping'
    $config = $Context.Config
    if ($null -eq $config) {
        $registry = [ordered]@{ status = 'absent'; reason = 'Keine VirtuSphere-Registry auf diesem Rechner (Get-VsConfig leer); der Registryteil entfaellt, alles andere ist erhoben.' }
    } else {
        $registry = [ordered]@{
            status = 'ok'
            PackagesShare = [string]$config.PackagesShare
            LogRoot = [string]$config.LogRoot
            VirtuSphere_WebAPI = [string]$config.WebApi
            Scheme = [string]$config.Scheme
        }
    }

    $shares = @()
    try {
        $shares = @(Get-SmbShare -ErrorAction Stop | Where-Object { -not $_.Special })
    } catch {
        Write-VsSurveyNote -Text ('Get-SmbShare: {0}' -f [string]$_.Exception.Message)
    }
    $shareRecords = @(foreach ($share in $shares) {
        [ordered]@{ Name = [string]$share.Name; Path = [string]$share.Path; Description = [string]$share.Description; ScopeName = [string]$share.ScopeName }
    })

    $locations = New-Object System.Collections.Generic.List[object]
    $covered = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase)
    $addLocations = {
        param([string]$application, [string]$typeId, [string]$source, [string]$xml)
        foreach ($path in @(Get-VsSurveyContentLocations -Xml $xml)) {
            [void]$covered.Add($application)
            $mapping = Get-VsSurveyLocalPathForUnc -Path $path -LocalShares $shares -LocalNames @($Context.ComputerNames)
            $locations.Add([ordered]@{
                application = $application
                deploymentTypeId = $typeId
                source = $source
                location = $path
                parsed = $mapping.Parsed
                host = $mapping.Host
                share = $mapping.Share
                isLocalHost = $mapping.IsLocal
                localShare = $mapping.ShareMatch
                localPath = $mapping.LocalPath
                uncReachable = $(if ($mapping.Parsed) { [bool](Test-Path -LiteralPath $path -PathType Container) } else { $false })
                localReachable = $(if ($mapping.LocalPath) { [bool](Test-Path -LiteralPath $mapping.LocalPath -PathType Container) } else { $false })
            })
        }
    }
    $fileSystemRoot = Get-VsSurveyFileSystemRoot
    Push-Location -LiteralPath $fileSystemRoot
    try {
        foreach ($entry in @($Context.DeploymentTypeObjects)) {
            $typeId = Get-VsObjectPropertyText -Object $entry.Object -Names @('CI_ID')
            try {
                & $addLocations $entry.Name $typeId 'deployment-type' (Get-VsObjectPropertyText -Object $entry.Object -Names @('SDMPackageXML'))
            } catch {
                Write-VsSurveyNote -Text ('{0} DT {1}: Manifest nicht lesbar: {2}' -f $entry.Name, $typeId, [string]$_.Exception.Message)
            }
        }
        # Get-CMDeploymentType liefert das Manifest nicht immer mit (erster
        # Standortlauf: zwei von vier Deployment Types ohne SDMPackageXML). Das
        # Anwendungsmanifest enthaelt die Content-Orte aller Deployment Types.
        foreach ($entry in @($Context.ApplicationEntries)) {
            if ($covered.Contains([string]$entry.Name)) { continue }
            try {
                & $addLocations $entry.Name '' 'application' (Get-VsObjectPropertyText -Object $entry.Object -Names @('SDMPackageXML'))
            } catch {
                Write-VsSurveyNote -Text ('{0}: Anwendungsmanifest nicht lesbar: {1}' -f $entry.Name, [string]$_.Exception.Message)
            }
        }
    } finally {
        Pop-Location
    }
    return [ordered]@{ registry = $registry; shares = $shareRecords; contentLocations = $locations.ToArray() }
}

# Systemlaufwerk unter Windows; pwsh unter Linux hat kein Systemverzeichnis, dort
# nennt der Rueckfall den FileSystem-Provider ausdruecklich (wie ClientPreflight).
function Get-VsSurveyFileSystemRoot {
    $root = [IO.Path]::GetPathRoot([Environment]::SystemDirectory)
    if ([string]::IsNullOrEmpty($root)) {
        $root = 'Microsoft.PowerShell.Core\FileSystem::' + [IO.Path]::GetPathRoot([IO.Path]::GetTempPath())
    }
    return $root
}

# Altanwendungen: Namen, die wie die getinfo-Altnamen beginnen, ohne diese und
# ohne die verwaltete erste Phase. Die Altnamen selbst stehen in applications.
function Get-VsSurveyLegacy {
    param([Parameter(Mandatory)][hashtable]$Context)
    $managed = [string](@($Context.Specs | Where-Object { $null -eq $_.DependsOn } | ForEach-Object { [string]$_.AppName }) | Select-Object -First 1)
    $similar = Read-VsLegacySimilarNames -LegacyNames $Context.LegacyNames -ManagedName $managed
    if ($similar.State -cne 'complete') { Write-VsSurveyNote -Text ('similar names: {0}' -f $similar.Detail) }
    return [ordered]@{
        legacyNames = @($Context.LegacyNames)
        managedName = $managed
        similarNames = [ordered]@{ state = [string]$similar.State; names = @($similar.Names); detail = [string]$similar.Detail }
    }
}

# Gesamtlauf. Ohne Site-Code Exit 2 ohne jede MECM-Abfrage; sonst laufen alle
# Abschnitte, jeder fuer sich, und der Lauf endet mit Exit 0 und dem Bericht.
function Get-VsSiteSurveyResult {
    param(
        $Config,
        [Parameter(Mandatory)][object[]]$Specs,
        [Parameter(Mandatory)][AllowEmptyCollection()][string[]]$LegacyNames,
        [scriptblock]$InitializeSite = { param($siteConfig) Initialize-VsCmSite -Config $siteConfig },
        [scriptblock]$ResolveProvider = { param($code) Resolve-VsSurveyProvider -SiteCode $code },
        [AllowNull()][AllowEmptyCollection()][string[]]$CurrentSids = @(),
        [AllowNull()][AllowEmptyCollection()][string[]]$ComputerNames = @()
    )
    $total = $script:VsSurveySectionNames.Count
    Write-Host (Format-VsSurveyProgress -Position 0 -Total $total -Phase 'RUN' -Unit 'site-survey')
    $siteCode = [string](& $InitializeSite $Config)
    if (-not $siteCode) {
        Write-Host 'Kein MECM-Site-Code ermittelbar: Die Erhebung braucht einen Rechner mit MECM-Konsole und erreichbarem SMS-Provider (lokaler Namespace root\SMS oder ein vorhandenes CMSite-Laufwerk). Es wurde nichts abgefragt.'
        return [pscustomobject]@{ ExitCode = 2; Report = $null }
    }
    $provider = & $ResolveProvider $siteCode
    $context = @{
        SiteCode = $siteCode
        Namespace = ('root\SMS\site_{0}' -f $siteCode)
        ProviderSource = [string]$provider.Source
        CimComputer = [string]$provider.Computer
        Config = $Config
        Specs = $Specs
        LegacyNames = @($LegacyNames)
        CurrentSids = @($CurrentSids | Where-Object { $_ })
        ComputerNames = @($ComputerNames | Where-Object { $_ })
        Targets = @()
        TargetCiIds = @()
        TargetModelNames = @()
        TargetApplicationCount = 0
        ApplicationObjects = @()
        ApplicationEntries = @()
        DeploymentTypeObjects = @()
        DeploymentCollectionIds = @()
        FailedSections = New-Object System.Collections.Generic.List[string]
    }
    $bodies = [ordered]@{
        environment = { param($c) Get-VsSurveyEnvironment -Context $c }
        classSchemas = { param($c) Get-VsSurveyClassSchemas -Context $c }
        applications = { param($c) Get-VsSurveyApplications -Context $c }
        deployments = { param($c) Get-VsSurveyDeployments -Context $c }
        references = { param($c) Get-VsSurveyReferences -Context $c }
        securityScopes = { param($c) Get-VsSurveySecurityScopes -Context $c }
        collections = { param($c) Get-VsSurveyCollections -Context $c }
        content = { param($c) Get-VsSurveyContent -Context $c }
        folders = { param($c) Get-VsSurveyFolders -Context $c }
        sourceMapping = { param($c) Get-VsSurveySourceMapping -Context $c }
        legacy = { param($c) Get-VsSurveyLegacy -Context $c }
    }
    $sections = [ordered]@{}
    $position = 0
    foreach ($name in $script:VsSurveySectionNames) {
        $position++
        $section = Get-VsSurveySection -Name $name -Position $position -Total $total -Body $bodies[$name] -Argument $context
        if ($section.status -ceq 'failed') { $context.FailedSections.Add($name) }
        $sections[$name] = $section
    }
    $report = [ordered]@{
        schema = $script:VsSurveySchema
        generatedUtc = [DateTime]::UtcNow.ToString('o')
        computer = [string]$env:COMPUTERNAME
        site = [ordered]@{
            siteCode = $siteCode
            hasVirtuSphereRegistry = ($null -ne $Config)
            targetApplicationCount = [int]$context.TargetApplicationCount
        }
        sections = $sections
    }
    return [pscustomobject]@{ ExitCode = 0; Report = $report }
}
