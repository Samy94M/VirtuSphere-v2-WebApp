# Reine, testbare Bausteine fuer install-VirtuSphere-Clients.ps1.
#
# Bewusst OHNE #Requires und OHNE CM-Cmdlets: keine Seiteneffekte beim Laden,
# damit Pester die Funktionen dot-sourcen und pruefen kann. Die CM-Orchestrierung
# (Applikationen anlegen, Content verteilen) lebt im Hauptskript, das dieses hier
# dot-sourct und das ConfigurationManager-Modul braucht.
Set-StrictMode -Version 1.0

# Statischer, standortunabhaengiger Packaging-Vertrag. Die Standortdatei darf
# weder die Aufbewahrung lockern noch den MECM-Graphen umdefinieren.
function Get-VsClientPackagingPolicy {
    [pscustomobject]@{
        SchemaVersion = 1
        ConfigPath = 'C:\ProgramData\VirtuSphere\MECM\ClientPackaging.psd1'
        RequiredConfigKeys = @('SchemaVersion', 'PackagesBase', 'ContentShare', 'WebApi', 'Scheme', 'CertThumbprint', 'DpGroupName', 'CoreLimitingCollectionId', 'BundleArchivePath', 'SourceIdentity')
        AllowedWriterSids = @('S-1-5-18', 'S-1-5-32-544') # SYSTEM, lokale Administratoren
        MinimumSuccessfulBundles = 5
        MinimumAgeDays = 180
        UpgradeMode = 'future_provisioning_no_replay'
        # Manuell angelegte Altobjekte der getinfo-Phase (Entscheidung 11):
        # nie adoptieren, nur inventarisieren und hoechstens retire.
        LegacyApplicationNames = @('client_getinfo', 'client_getinfo_2.1')
    }
}

# Nur konstante PSD1-Daten laden. ACL- und Feldvalidierung sind Pflicht vor
# Apply; ein unbekannter Writer ist kein akzeptierter Packaging-Auftrag.
function Import-VsClientPackagingConfig {
    param(
        [Parameter(Mandatory)][string]$ClientSourceDir,
        [string]$LiteralPath = (Get-VsClientPackagingPolicy).ConfigPath
    )
    if (-not (Test-Path -LiteralPath $LiteralPath -PathType Leaf)) {
        throw ("Client-Packaging-Konfiguration fehlt: {0}" -f $LiteralPath)
    }
    $policy = Get-VsClientPackagingPolicy
    $acl = Get-Acl -LiteralPath $LiteralPath -ErrorAction Stop
    foreach ($entry in @($acl.Access)) {
        if ($entry.AccessControlType -ne [Security.AccessControl.AccessControlType]::Allow) { continue }
        $rights = [int64]$entry.FileSystemRights
        $writeRights = [int64]([Security.AccessControl.FileSystemRights]::Write -bor [Security.AccessControl.FileSystemRights]::Delete -bor [Security.AccessControl.FileSystemRights]::ChangePermissions -bor [Security.AccessControl.FileSystemRights]::TakeOwnership)
        if (($rights -band $writeRights) -eq 0) { continue }
        try { $sid = $entry.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value }
        catch { throw ("Client-Packaging-ACL enthaelt nicht aufloesbaren Writer: {0}" -f $entry.IdentityReference) }
        if ($policy.AllowedWriterSids -cnotcontains $sid) {
            throw ("Client-Packaging-ACL enthaelt unbekannten Writer: {0}" -f $sid)
        }
    }
    $value = Import-PowerShellDataFile -LiteralPath $LiteralPath -ErrorAction Stop
    if ($value -isnot [hashtable]) { throw 'Client-Packaging-Konfiguration muss eine Hashtable sein.' }
    if (@($value.Keys | Where-Object { $_ -cnotin $policy.RequiredConfigKeys }).Count -gt 0 -or
        @($policy.RequiredConfigKeys | Where-Object { -not $value.ContainsKey($_) }).Count -gt 0) {
        throw 'Client-Packaging-Konfiguration hat fehlende oder unbekannte Schluessel.'
    }
    if ($value.SchemaVersion -isnot [int] -or $value.SchemaVersion -ne $policy.SchemaVersion) {
        throw 'Client-Packaging-Konfiguration hat eine unbekannte Schemaversion.'
    }
    Assert-VsClientPackagingConfigValues -Config $value -ClientSourceDir $ClientSourceDir
    return $value
}

# Die WebAPI-/Scheme-/Pin-Regel besitzt allein der ausgelieferte Client-Common.
# Der Server wendet exakt diese Funktion auf den kuenftigen Bootstrap an, statt
# eine zweite Regel zu pflegen, die still auseinanderlaufen koennte. Nur die
# reine Funktion wird aus dem AST gelesen; der zustandsbehaftete Common wird
# nicht ausgefuehrt.
function Get-VsClientApiConfigurationRule {
    param([Parameter(Mandatory)][string]$ClientSourceDir)
    $commonSource = Join-Path $ClientSourceDir 'VirtuSphere-Client-Common.ps1'
    if (-not (Test-Path -LiteralPath $commonSource -PathType Leaf)) { throw "Client-Common fehlt: $commonSource" }
    $tokens = $null
    $parseErrors = $null
    $ast = [System.Management.Automation.Language.Parser]::ParseFile((Resolve-Path -LiteralPath $commonSource).Path, [ref]$tokens, [ref]$parseErrors)
    if (@($parseErrors).Count -gt 0) { throw "Client-Common ist syntaktisch ungueltig: $commonSource" }
    $rule = @($ast.FindAll({
        param($node)
        $node -is [System.Management.Automation.Language.FunctionDefinitionAst] -and
            $node.Name -ceq 'ConvertTo-VsClientApiConfiguration'
    }, $true))
    if ($rule.Count -ne 1) { throw 'Client-Common muss ConvertTo-VsClientApiConfiguration genau einmal definieren.' }
    return $rule[0].Body.GetScriptBlock()
}

# Lokaler absoluter Pfad ohne Relativsegmente, Platzhalter oder Schlussstrich.
# Windows entfernt Punkt und Leerzeichen am Ende JEDES Segments; `a.\b` waere
# derselbe Ordner wie `a\b` und unterliefe den Enthaltenseinvergleich unten.
function Test-VsClientPackagingLocalPath {
    param([string]$Path)
    return ($Path -cmatch '\A[A-Za-z]:(\\[^\\/:*?"<>|\x00-\x1F]+)+\z' -and
        $Path -notmatch '[ .](\\|\z)')
}

# Typ- und Wertpruefung jedes Standortfelds. Werte muessen bereits kanonisch
# sein: Der Lauf nennt und verwendet genau die Werte der Datei, er normalisiert
# nichts still. Alle Befunde werden gesammelt und gemeinsam gemeldet.
function Assert-VsClientPackagingConfigValues {
    param(
        [Parameter(Mandatory)][hashtable]$Config,
        [Parameter(Mandatory)][string]$ClientSourceDir
    )
    $issues = New-Object System.Collections.Generic.List[string]
    foreach ($key in @($Config.Keys | Where-Object { $_ -cne 'SchemaVersion' })) {
        if ($Config[$key] -isnot [string]) { $issues.Add(('{0}: muss eine Zeichenkette sein' -f $key)) }
    }
    if ($issues.Count -gt 0) { throw ('Client-Packaging-Konfiguration ungueltig: {0}.' -f ($issues -join '; ')) }

    $apiRule = Get-VsClientApiConfigurationRule -ClientSourceDir $ClientSourceDir
    $api = & $apiRule -Api $Config.WebApi -Scheme $Config.Scheme -CertThumbprint $Config.CertThumbprint
    if (-not $api) {
        $issues.Add('WebApi/Scheme/CertThumbprint: kein gueltiger Client-Bootstrap (host[:port], http|https klein, Pin genau 40 Hexzeichen gross und nur mit https)')
    }

    $packagesBase = [string]$Config.PackagesBase
    $archive = [string]$Config.BundleArchivePath
    if (-not (Test-VsClientPackagingLocalPath $packagesBase)) { $issues.Add('PackagesBase: lokaler absoluter Pfad erwartet (z. B. D:\VirtuSphere\Base\Packages)') }
    if (-not (Test-VsClientPackagingLocalPath $archive)) {
        $issues.Add('BundleArchivePath: lokaler absoluter Pfad erwartet (z. B. D:\VirtuSphere\Base\ClientBundles)')
    } elseif (($archive + '\').StartsWith($packagesBase + '\', [StringComparison]::OrdinalIgnoreCase) -or
        ($packagesBase + '\').StartsWith($archive + '\', [StringComparison]::OrdinalIgnoreCase)) {
        $issues.Add('BundleArchivePath: muss ausserhalb von PackagesBase liegen und darf es nicht enthalten')
    }
    $share = [string]$Config.ContentShare
    if ($share -cnotmatch '\A\\\\[A-Za-z0-9]([A-Za-z0-9.\-]*[A-Za-z0-9])?(\\[^\\/:*?"<>|\x00-\x1F]+)+\z' -or
        $share -match '[ .](\\|\z)') {
        $issues.Add('ContentShare: UNC-Pfad \\server\freigabe[\ordner] ohne Schlussstrich erwartet')
    }
    $dpGroup = [string]$Config.DpGroupName
    if ($dpGroup -and ($dpGroup -cne $dpGroup.Trim() -or $dpGroup.Length -gt 256 -or $dpGroup -match '[\x00-\x1F]')) {
        $issues.Add('DpGroupName: leer oder ein Name ohne Randleerzeichen und Steuerzeichen, hoechstens 256 Zeichen')
    }
    # MECM-CollectionID: dreistelliger Sitecode plus fuenf Hexziffern. Nie ein
    # lokalisierter Anzeigename.
    if ([string]$Config.CoreLimitingCollectionId -cnotmatch '\A[A-Z0-9]{3}[0-9A-F]{5}\z') {
        $issues.Add('CoreLimitingCollectionId: MECM-CollectionID erwartet (Sitecode plus fuenf Hexziffern, z. B. PS100012)')
    }
    if ([string]$Config.SourceIdentity -cnotmatch '\A[0-9a-f]{32}\z') {
        $issues.Add('SourceIdentity: 32 Kleinbuchstaben-Hexzeichen erwartet (zufaelliger Source-Identitaetsmarker)')
    }
    if ($issues.Count -gt 0) { throw ('Client-Packaging-Konfiguration ungueltig: {0}.' -f ($issues -join '; ')) }
}

function Get-VsDangerousFileSystemAclEntries {
    param([Parameter(Mandatory)]$Acl)
    $broadSids = @('S-1-1-0', 'S-1-5-11', 'S-1-5-32-545')
    $writeMask = [Security.AccessControl.FileSystemRights]::WriteData -bor
        [Security.AccessControl.FileSystemRights]::AppendData -bor
        [Security.AccessControl.FileSystemRights]::WriteAttributes -bor
        [Security.AccessControl.FileSystemRights]::WriteExtendedAttributes -bor
        [Security.AccessControl.FileSystemRights]::Delete -bor
        [Security.AccessControl.FileSystemRights]::ChangePermissions -bor
        [Security.AccessControl.FileSystemRights]::TakeOwnership
    return @($Acl.Access | Where-Object {
        $entry = $_
        if ($entry.AccessControlType -ne [Security.AccessControl.AccessControlType]::Allow) { return $false }
        try { $sid = $entry.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value } catch { $sid = [string]$entry.IdentityReference }
        return $broadSids -contains $sid -and (([int64]$entry.FileSystemRights -band [int64]$writeMask) -ne 0)
    })
}

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
            DisplayName     = 'client_getInfos'
            CmIdentityFields = @('CI_ID', 'ModelName')
            Folder          = 'client_getInfos'
            Script          = 'client_getInfos.ps1'
            RequiredFiles   = @('client_getInfos.ps1', 'VirtuSphere-Client-Common.ps1', 'VirtuSphere-Client-Logging.ps1', 'bootstrap.json')
            Role            = 'internal_dependency'
            RunAs32Bit      = $false
            DetectionIs32Bit = $false
            MaximumRuntimeMins = 15
            DetectionKey    = 'SOFTWARE\VirtuSphere'
            DetectionName   = 'SetupState'
            DetectionValues = @('complete')
            DetectionType   = 'String'
            DependsOn       = $null
            ManagedMarker   = 'VirtuSphere managed client application contract v1'
            ReturnCodes     = @(
                [pscustomobject]@{ Value = 0; Type = 'Success' }
                [pscustomobject]@{ Value = 1641; Type = 'HardReboot' }
                [pscustomobject]@{ Value = 3010; Type = 'SoftReboot' }
            )
        }
        [pscustomobject]@{
            AppName         = 'client_hostname'
            DisplayName     = 'client_hostname'
            CmIdentityFields = @('CI_ID', 'ModelName')
            Folder          = 'client_hostname'
            Script          = 'client_hostname.ps1'
            RequiredFiles   = @('client_hostname.ps1', 'VirtuSphere-Client-Common.ps1', 'VirtuSphere-Client-Logging.ps1', 'bootstrap.json')
            Role            = 'internal_dependency'
            RunAs32Bit      = $false
            DetectionIs32Bit = $false
            MaximumRuntimeMins = 15
            DetectionKey    = 'SOFTWARE\VirtuSphere\HostnameUpdate'
            DetectionName   = 'Status'
            # Zwei Erfolgswerte: 'Erfolgreich' (umbenannt/bereits korrekt) und
            # 'Uebersprungen' (Domaenen-Computer). Ohne den zweiten Wert wuerde ein
            # domaenengebundener Client nie als installiert erkannt und liefe endlos.
            DetectionValues = @('Erfolgreich', 'Uebersprungen')
            DetectionType   = 'String'
            DependsOn       = 'client_getInfos'
            ManagedMarker   = 'VirtuSphere managed client application contract v1'
            ReturnCodes     = @(
                [pscustomobject]@{ Value = 0; Type = 'Success' }
                [pscustomobject]@{ Value = 1641; Type = 'HardReboot' }
                [pscustomobject]@{ Value = 3010; Type = 'SoftReboot' }
            )
        }
        [pscustomobject]@{
            AppName         = 'client_VMDisksOnline'
            DisplayName     = 'client_VMDisksOnline'
            CmIdentityFields = @('CI_ID', 'ModelName')
            Folder          = 'client_VMDisksOnline'
            Script          = 'client_VMDisksOnline.ps1'
            RequiredFiles   = @('client_VMDisksOnline.ps1', 'VirtuSphere-Client-Common.ps1', 'VirtuSphere-Client-Logging.ps1', 'bootstrap.json')
            Role            = 'internal_dependency'
            RunAs32Bit      = $false
            DetectionIs32Bit = $false
            MaximumRuntimeMins = 15
            DetectionKey    = 'SOFTWARE\VirtuSphere\VMDiskManagement'
            DetectionName   = 'VMDisksOnlineStatus'
            DetectionValues = @('Success')
            DetectionType   = 'String'
            DependsOn       = 'client_hostname'
            ManagedMarker   = 'VirtuSphere managed client application contract v1'
            ReturnCodes     = @(
                [pscustomobject]@{ Value = 0; Type = 'Success' }
                [pscustomobject]@{ Value = 1641; Type = 'HardReboot' }
                [pscustomobject]@{ Value = 3010; Type = 'SoftReboot' }
            )
        }
        [pscustomobject]@{
            AppName         = 'client_staticip'
            DisplayName     = 'client_staticip'
            CmIdentityFields = @('CI_ID', 'ModelName')
            Folder          = 'client_staticip'
            Script          = 'client_staticip.ps1'
            RequiredFiles   = @('client_staticip.ps1', 'VirtuSphere-Client-Common.ps1', 'VirtuSphere-Client-Logging.ps1', 'bootstrap.json')
            Role            = 'deployable_entry'
            RunAs32Bit      = $false
            DetectionIs32Bit = $false
            MaximumRuntimeMins = 15
            DetectionKey    = 'SOFTWARE\VirtuSphere\staticip'
            DetectionName   = 'installed'
            DetectionValues = @('1')
            DetectionType   = 'Int64'
            DependsOn       = 'client_VMDisksOnline'
            ManagedMarker   = 'VirtuSphere managed client application contract v1'
            ReturnCodes     = @(
                [pscustomobject]@{ Value = 0; Type = 'Success' }
                [pscustomobject]@{ Value = 1641; Type = 'HardReboot' }
                [pscustomobject]@{ Value = 3010; Type = 'SoftReboot' }
            )
        }
    )
}

function Assert-VsClientAppSpecGraph {
    param([Parameter(Mandatory)][object[]]$Specs)
    $byName = @{}
    $entryCount = 0
    foreach ($spec in $Specs) {
        $name = [string]$spec.AppName
        if ([string]::IsNullOrWhiteSpace($name) -or $byName.ContainsKey($name)) {
            throw ("Client-App-Graph enthaelt einen leeren oder doppelten Namen: '{0}'." -f $name)
        }
        $byName[$name] = $spec
        if ($spec.PSObject.Properties['Role']) {
            if ($spec.Role -eq 'deployable_entry') { $entryCount++ }
            elseif ($spec.Role -ne 'internal_dependency') { throw ("Client-App '{0}' hat eine unbekannte Rolle." -f $name) }
            if ($spec.RunAs32Bit -ne $false -or $spec.DetectionIs32Bit -ne $false) {
                throw ("Client-App '{0}' verletzt den 64-Bit-Vertrag." -f $name)
            }
            # MECM akzeptiert fuer Applications keine Maximum Runtime unter 15 Minuten.
            if (-not $spec.PSObject.Properties['MaximumRuntimeMins'] -or $spec.MaximumRuntimeMins -isnot [int] -or $spec.MaximumRuntimeMins -lt 15) {
                throw ("Client-App '{0}' braucht eine ganzzahlige Maximum Runtime von mindestens 15 Minuten." -f $name)
            }
            $expectedFiles = @($spec.Script, 'VirtuSphere-Client-Common.ps1', 'VirtuSphere-Client-Logging.ps1', 'bootstrap.json')
            if (@(Compare-Object -ReferenceObject $expectedFiles -DifferenceObject @($spec.RequiredFiles)).Count -gt 0) {
                throw ("Client-App '{0}' hat einen abweichenden RequiredFiles-Vertrag." -f $name)
            }
        }
    }
    if ($entryCount -gt 0 -and $entryCount -ne 1) { throw 'Client-App-Graph muss genau einen deploybaren Einstieg enthalten.' }
    foreach ($spec in $Specs) {
        if ($spec.DependsOn -and -not $byName.ContainsKey([string]$spec.DependsOn)) {
            throw ("Client-App-Graph verweist von '{0}' auf den unbekannten Vorgaenger '{1}'." -f $spec.AppName, $spec.DependsOn)
        }
        $seen = @{}
        $cursor = $spec
        while ($cursor -and $cursor.DependsOn) {
            if ($seen.ContainsKey([string]$cursor.AppName)) {
                throw ("Client-App-Graph enthaelt einen Zyklus bei '{0}'." -f $cursor.AppName)
            }
            $seen[[string]$cursor.AppName] = $true
            $cursor = $byName[[string]$cursor.DependsOn]
        }
    }
}

function Get-VsClientContentManifest {
    param([Parameter(Mandatory)][string]$Path)
    if (-not (Test-Path -LiteralPath $Path -PathType Container)) {
        throw ("Client-Contentpfad fehlt oder ist kein Verzeichnis: {0}" -f $Path)
    }
    $root = (Get-Item -LiteralPath $Path -ErrorAction Stop).FullName.TrimEnd('\', '/')
    $result = @()
    foreach ($file in @(Get-ChildItem -LiteralPath $root -Recurse -File -Force -ErrorAction Stop | Sort-Object FullName)) {
        $relative = $file.FullName.Substring($root.Length).TrimStart('\', '/').Replace('\', '/')
        $result += [pscustomobject]@{
            Path   = $relative
            Length = [long]$file.Length
            Sha256 = (Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256 -ErrorAction Stop).Hash.ToUpperInvariant()
        }
    }
    return @($result)
}

function Compare-VsClientContentManifest {
    param(
        [Parameter(Mandatory)][string]$StagedPath,
        [Parameter(Mandatory)][string]$PublishedPath
    )
    $staged = @(Get-VsClientContentManifest -Path $StagedPath)
    $published = @(Get-VsClientContentManifest -Path $PublishedPath)
    if ($staged.Count -eq 0) { return @('staged:empty') }
    $issues = @()
    if ($staged.Count -ne $published.Count) {
        $issues += ('file-count:{0}!={1}' -f $staged.Count, $published.Count)
    }
    $publishedByPath = @{}
    foreach ($entry in $published) { $publishedByPath[[string]$entry.Path] = $entry }
    foreach ($entry in $staged) {
        if (-not $publishedByPath.ContainsKey([string]$entry.Path)) {
            $issues += ('missing:{0}' -f $entry.Path)
            continue
        }
        $other = $publishedByPath[[string]$entry.Path]
        if ($entry.Length -ne $other.Length -or $entry.Sha256 -ne $other.Sha256) {
            $issues += ('content:{0}' -f $entry.Path)
        }
        $publishedByPath.Remove([string]$entry.Path)
    }
    foreach ($extra in @($publishedByPath.Keys | Sort-Object)) { $issues += ('extra:{0}' -f $extra) }
    return @($issues)
}

function Test-VsClientApplicationOwnership {
    param(
        [Parameter(Mandatory)]$Application,
        [Parameter(Mandatory)]$Spec,
        [Parameter(Mandatory)][string]$AppFolder
    )
    $description = if ($Application.PSObject.Properties['LocalizedDescription']) { [string]$Application.LocalizedDescription } elseif ($Application.PSObject.Properties['Description']) { [string]$Application.Description } else { '' }
    $null = $AppFolder # Ordner allein ist ausdruecklich kein Eigentumsbeleg.
    return ($description -ceq [string]$Spec.ManagedMarker)
}

function Get-VsObjectPropertyText {
    param([Parameter(Mandatory)]$Object, [Parameter(Mandatory)][string[]]$Names)
    foreach ($name in $Names) {
        if ($Object.PSObject.Properties[$name] -and $null -ne $Object.$name) { return [string]$Object.$name }
    }
    return ''
}

function Get-VsClientDeploymentTypeContractIssues {
    param(
        [Parameter(Mandatory)]$DeploymentType,
        [Parameter(Mandatory)]$Spec,
        [Parameter(Mandatory)][string]$ContentLocation,
        [Parameter(Mandatory)][string]$InstallCommand,
        [Parameter(Mandatory)][object[]]$ReturnCodes
    )
    $issues = @()
    $expectedName = '{0} Deployment' -f $Spec.AppName
    $actualName = Get-VsObjectPropertyText -Object $DeploymentType -Names @('LocalizedDisplayName', 'DeploymentTypeName')
    if ($actualName -ne $expectedName) { $issues += 'deployment-type-name' }
    $rawXml = Get-VsObjectPropertyText -Object $DeploymentType -Names @('SDMPackageXML')
    if ([string]::IsNullOrWhiteSpace($rawXml)) { return @($issues + 'definition-unreadable') }
    try { [xml]$xml = $rawXml } catch { return @($issues + 'definition-invalid-xml') }
    $values = New-Object System.Collections.Generic.List[string]
    foreach ($node in @($xml.SelectNodes('//*'))) {
        if ($node.ChildNodes.Count -eq 1 -and $node.FirstChild.NodeType -in @([Xml.XmlNodeType]::Text, [Xml.XmlNodeType]::CDATA)) {
            [void]$values.Add([string]$node.InnerText)
        }
        foreach ($attribute in @($node.Attributes)) { [void]$values.Add([string]$attribute.Value) }
    }
    $expectedValues = @($ContentLocation.TrimEnd('\'), $InstallCommand, [string]$Spec.DetectionKey, [string]$Spec.DetectionName, [string]$Spec.DetectionType, 'BasedOnExitCode')
    $expectedValues += @($Spec.DetectionValues | ForEach-Object { [string]$_ })
    foreach ($expected in $expectedValues) {
        $matched = @($values | Where-Object { ([string]$_).TrimEnd('\') -eq $expected }).Count -gt 0
        if (-not $matched) { $issues += ('definition:{0}' -f $expected) }
    }
    if (@($values | Where-Object { $_ -in @('InstallForSystem', 'System') }).Count -eq 0) { $issues += 'installation-context-system' }
    # Installer-Argumente tragen die SDK-Eigenschaftsnamen des ScriptInstallers
    # (MaxExecuteTime, RunAs32Bit). Fehlt die Laufzeit oder ist sie mehrdeutig,
    # ist sie unbekannt und blockiert; eine fehlende 32-Bit-Angabe ist der
    # native Standard, jeder andere Wert als "false" blockiert.
    $runtimeArgs = @($xml.SelectNodes("//*[local-name()='Arg'][@Name='MaxExecuteTime']"))
    if ($runtimeArgs.Count -ne 1 -or ([string]$runtimeArgs[0].InnerText).Trim() -cne [string]$Spec.MaximumRuntimeMins) { $issues += 'maximum-runtime' }
    foreach ($bitnessArg in @($xml.SelectNodes("//*[local-name()='Arg'][@Name='RunAs32Bit']"))) {
        if (([string]$bitnessArg.InnerText).Trim() -ne 'false') { $issues += 'run-as-32bit'; break }
    }
    if (@($Spec.DetectionValues).Count -gt 1 -and $rawXml -notmatch '(?i)\bOR\b') { $issues += 'detection-connector-or' }

    foreach ($expectedCode in @($Spec.ReturnCodes)) {
        $codeMatches = @($ReturnCodes | Where-Object {
            (Get-VsObjectPropertyText -Object $_ -Names @('Value', 'ReturnCode', 'ExitCode', 'Code')) -eq [string]$expectedCode.Value
        })
        if ($codeMatches.Count -ne 1) {
            $issues += ('return-code:{0}' -f $expectedCode.Value)
            continue
        }
        $actualType = Get-VsObjectPropertyText -Object $codeMatches[0] -Names @('CodeType', 'Type')
        if ($actualType -ne [string]$expectedCode.Type) { $issues += ('return-code-type:{0}' -f $expectedCode.Value) }
    }
    return @($issues)
}

# Liest eine einzelne ganzzahlige Vertragskonstante ueber den PowerShell-AST.
# Die Paketpruefung darf die fremde Common-Fassade nicht dot-sourcen: deren
# $script:-Zustand wuerde sonst Komponente, Korrelation und Sinkstatus des
# laufenden Installers ueberschreiben.
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

# Vergleicht die deklarierten Client-Vertragsversionen, ohne eine der beiden
# zustandsbehafteten Laufzeitdateien auszufuehren.
function Get-VsClientLoggingPackageVersion {
    param([Parameter(Mandatory)][string]$SourceDir)
    $commonSource = Join-Path $SourceDir 'VirtuSphere-Client-Common.ps1'
    $loggingSource = Join-Path $SourceDir 'VirtuSphere-Client-Logging.ps1'
    foreach ($src in @($commonSource, $loggingSource)) {
        if (-not (Test-Path $src)) { throw "Client-Loggingpaket unvollstaendig: $src fehlt." }
    }
    $expected = Get-VsDeclaredScriptInteger -Path $commonSource -VariableName 'VsExpectedClientLoggingContractVersion'
    $declared = Get-VsDeclaredScriptInteger -Path $loggingSource -VariableName 'VsClientLoggingContractVersion'
    if ($declared -ne $expected) {
        throw ('Client-Logging-Modul hat Version {0}; erwartet wird {1}. Vollstaendiges Paket verwenden.' -f $declared, $expected)
    }
    return $declared
}

# Stellt den Content EINES App-Ordners bereit: Phasenskript, Common-Fassade und
# lokales Loggingmodul. Alle drei werden zuerst in einen privaten Stagingordner
# kopiert und geprueft; MECM verteilt den Ordner erst nach erfolgreicher Rueckkehr.
function Copy-VsClientContent {
    param(
        [Parameter(Mandatory)][pscustomobject]$Spec,
        [Parameter(Mandatory)][string]$SourceDir,
        [Parameter(Mandatory)][string]$PackagesBase,
        [Parameter(Mandatory)][hashtable]$Bootstrap
    )
    $sourceFiles = @($Spec.RequiredFiles | Where-Object { $_ -ne 'bootstrap.json' })
    if ($sourceFiles.Count -ne 3 -or @($sourceFiles | Where-Object { $_ -notmatch '^[A-Za-z0-9_-]+\.ps1$' }).Count -gt 0) {
        throw 'Client-RequiredFiles-Vertrag enthaelt ungueltige Quelldateien.'
    }
    $sources = @($sourceFiles | ForEach-Object { Join-Path $SourceDir $_ })
    foreach ($src in $sources) {
        if (-not (Test-Path $src)) { throw "Quelldatei fehlt: $src (SourceDir stimmt?)" }
    }
    [void](Get-VsClientLoggingPackageVersion -SourceDir $SourceDir)

    if (-not (Test-Path $PackagesBase)) {
        New-Item -ItemType Directory -Path $PackagesBase -Force -ErrorAction Stop | Out-Null
    }
    $dest = Join-Path $PackagesBase $Spec.Folder
    $suffix = [guid]::NewGuid().ToString('N')
    # Stage und Backup liegen als Geschwister auf demselben Volume. Der
    # Verzeichnistausch ist damit atomar; ein Fehler stellt den vollstaendigen
    # alten Dreiersatz wieder her statt einzelne neue Dateien liegenzulassen.
    $stage = Join-Path $PackagesBase ('.virtusphere-stage-' + $suffix)
    $backup = Join-Path $PackagesBase ('.virtusphere-backup-' + $suffix)
    $hadDestination = Test-Path $dest
    $destinationBackedUp = $false
    $activated = $false
    New-Item -ItemType Directory -Path $stage -Force -ErrorAction Stop | Out-Null
    try {
        foreach ($src in $sources) {
            Copy-Item -Path $src -Destination $stage -Force -ErrorAction Stop
            $staged = Join-Path $stage (Split-Path $src -Leaf)
            if ((Get-FileHash -Algorithm SHA256 -Path $src).Hash -ne (Get-FileHash -Algorithm SHA256 -Path $staged).Hash) {
                throw ('Client-Content-Pruefsumme weicht ab: {0}' -f (Split-Path $src -Leaf))
            }
        }
        $bootstrapJson = $Bootstrap | ConvertTo-Json -Depth 3
        [IO.File]::WriteAllText((Join-Path $stage 'bootstrap.json'), $bootstrapJson, (New-Object Text.UTF8Encoding($false)))
        if ($hadDestination) {
            Move-Item -Path $dest -Destination $backup -ErrorAction Stop
            $destinationBackedUp = $true
        }
        Move-Item -Path $stage -Destination $dest -ErrorAction Stop
        $activated = $true
        if ($destinationBackedUp -and (Test-Path $backup)) {
            Remove-Item -Path $backup -Recurse -Force -ErrorAction SilentlyContinue
        }
    } catch {
        $activationError = $_
        if ($destinationBackedUp -and (Test-Path $backup)) {
            if (Test-Path $dest) {
                Remove-Item -Path $dest -Recurse -Force -ErrorAction Stop
            }
            Move-Item -Path $backup -Destination $dest -ErrorAction Stop
            $destinationBackedUp = $false
        }
        throw $activationError
    } finally {
        if (Test-Path $stage) { Remove-Item -Path $stage -Recurse -Force -ErrorAction SilentlyContinue }
        if ($activated -and (Test-Path $backup)) { Remove-Item -Path $backup -Recurse -Force -ErrorAction SilentlyContinue }
    }
    return $dest
}

# Die Programm-Befehlszeile eines Deployment-Types (eine Stelle, damit Skript und
# Test dieselbe Zeichenkette nutzen). Die Schalter selbst kommen aus
# $script:VsPowerShellArgs in VirtuSphere-Common.ps1; install-VirtuSphere-Clients.ps1
# sourct beide Dateien, Common zuerst.
function Get-VsClientInstallCommand {
    param([Parameter(Mandatory)][pscustomobject]$Spec)
    return (Get-VsPowerShellCommandLine -ScriptPath $Spec.Script)
}
