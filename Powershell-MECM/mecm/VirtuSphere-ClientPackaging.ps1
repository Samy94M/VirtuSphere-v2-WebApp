# Reine, testbare Bausteine fuer install-VirtuSphere-Clients.ps1.
#
# Bewusst OHNE #Requires und OHNE CM-Cmdlets: keine Seiteneffekte beim Laden,
# damit Pester die Funktionen dot-sourcen und pruefen kann. Die CM-Orchestrierung
# (Applikationen anlegen, Content verteilen) lebt im Hauptskript, das dieses hier
# dot-sourct und das ConfigurationManager-Modul braucht.
Set-StrictMode -Version 1.0

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
            Folder          = 'client_getInfos'
            Script          = 'client_getinfo.ps1'
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
            Folder          = 'client_hostname'
            Script          = 'client_hostname.ps1'
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
            AppName         = 'client_staticip'
            Folder          = 'client_staticip'
            Script          = 'client_staticip.ps1'
            DetectionKey    = 'SOFTWARE\VirtuSphere\staticip'
            DetectionName   = 'installed'
            # Als DWORD 1 geschrieben ([int]$Success). PropertyType MUSS 'Int64'
            # sein - New-CMDetectionClauseRegistryKeyValue kennt kein 'Integer'
            # (gueltig: String/Boolean/DateTime/Double/Int64/Version), sonst wirft
            # die Detection-Erstellung am Server.
            DetectionValues = @('1')
            DetectionType   = 'Int64'
            DependsOn       = 'client_hostname'
            ManagedMarker   = 'VirtuSphere managed client application contract v1'
            ReturnCodes     = @(
                [pscustomobject]@{ Value = 0; Type = 'Success' }
                [pscustomobject]@{ Value = 1641; Type = 'HardReboot' }
                [pscustomobject]@{ Value = 3010; Type = 'SoftReboot' }
            )
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
    foreach ($spec in $Specs) {
        $name = [string]$spec.AppName
        if ([string]::IsNullOrWhiteSpace($name) -or $byName.ContainsKey($name)) {
            throw ("Client-App-Graph enthaelt einen leeren oder doppelten Namen: '{0}'." -f $name)
        }
        $byName[$name] = $spec
    }
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
    if ($description -eq [string]$Spec.ManagedMarker) { return $true }
    # Legacy adoption is deliberately narrow: older installers moved their app
    # into the dedicated folder before creating the DT. Name alone is never
    # ownership evidence; a half-created app outside that folder stays foreign.
    $objectPath = if ($Application.PSObject.Properties['ObjectPath']) { [string]$Application.ObjectPath } else { '' }
    if ([string]::IsNullOrWhiteSpace($objectPath)) { return $false }
    return (($objectPath.TrimEnd('\') -split '\\')[-1] -eq $AppFolder)
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
    $scriptSource = Join-Path $SourceDir $Spec.Script
    $commonSource = Join-Path $SourceDir 'VirtuSphere-Client-Common.ps1'
    $loggingSource = Join-Path $SourceDir 'VirtuSphere-Client-Logging.ps1'
    foreach ($src in @($scriptSource, $commonSource, $loggingSource)) {
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
        foreach ($src in @($scriptSource, $commonSource, $loggingSource)) {
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
