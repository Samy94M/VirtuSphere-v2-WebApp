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
            DetectionKey    = 'SOFTWARE\VirtuSphere\HostnameUpdate'
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
            DetectionKey    = 'SOFTWARE\VirtuSphere\staticip'
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
        [Parameter(Mandatory)][string]$PackagesBase
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
