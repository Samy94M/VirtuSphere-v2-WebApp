# Pester-Suite fuer die reinen Funktionen der PowerShell-Integrationsclients.
#
# Dev-Host- und CI-Tooling, wie die Playwright- und Infection-Schichten (ADR-0028):
# nichts davon wird ausgeliefert, nichts liegt in einem Container.
#
# Warum es diese Suite gibt: die PowerShell-Skripte laufen als SYSTEM in
# Endlosschleifen auf dem SCCM-Server und auf frisch ausgerollten Clients, und sie
# waren der einzige Code im Projekt, den nichts geprueft hat. Der teuerste Fehler
# der Kampagne (TESTPLAN 2.2: eine VM, die MECM nicht findet, ohne Fehlermeldung)
# sass genau an der Naht zwischen PHP und PowerShell.
#
# Ausfuehren:  Invoke-Pester tests/powershell

# Pfade werden aus Join-Path-Ketten gebaut, nicht aus Literalen mit Backslash:
# die Suite laeuft auch unter pwsh auf Linux (CI), wo '\' kein Pfadtrenner ist.
# Eine Hilfsfunktion geht hier NICHT: Pester wertet den Dateirumpf in der
# Discovery-Phase aus, BeforeAll laeuft in der Run-Phase, und die beiden teilen
# sich keinen Funktionsscope.

# Auf Dateiebene, weil -ForEach schon in der Discovery-Phase gebraucht wird.
$RepoRootDiscovery = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$VectorFileDiscovery = Join-Path (Join-Path (Join-Path (Join-Path (Join-Path $RepoRootDiscovery 'Docker') 'WebAPI') 'tests') 'fixtures') 'mac-vectors.json'
# Schluessel bewusst 'mac' und nicht 'input': $input ist eine automatische
# PowerShell-Variable (der Pipeline-Enumerator) und wuerde den Wert verschlucken.
$MacCases = (Get-Content -Path $VectorFileDiscovery -Raw | ConvertFrom-Json).vectors |
    ForEach-Object { @{ mac = $_.input; expected = $_.expected; why = $_.why } }

# Detection-Contract-Faelle aus dem echten Spec (nicht hardcodiert): so faellt der
# Test auf, wenn die Spec in VirtuSphere-ClientPackaging.ps1 von dem abweicht, was
# das Client-Skript in die Registry schreibt. Dot-Source im Child-Scope, damit die
# Discovery-Phase sauber bleibt.
$PackagingDiscovery = Join-Path (Join-Path (Join-Path $RepoRootDiscovery 'Powershell-MECM') 'mecm') 'VirtuSphere-ClientPackaging.ps1'
$ClientsDirDiscovery = Join-Path (Join-Path $RepoRootDiscovery 'Powershell-MECM') 'clients'
$SpecCases = & { . $PackagingDiscovery; Get-VsClientAppSpecs } | ForEach-Object {
    @{
        AppName    = $_.AppName
        Script     = $_.Script
        Name       = $_.DetectionName
        Values     = $_.DetectionValues
        Type       = $_.DetectionType
        KeyFrag    = ($_.DetectionKey -split '\\')[-1]
        ClientsDir = $ClientsDirDiscovery
    }
}

BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $psRoot = Join-Path $script:RepoRoot 'Powershell-MECM'
    $script:MecmCommon = Join-Path (Join-Path $psRoot 'mecm') 'VirtuSphere-Common.ps1'
    $script:ClientCommon = Join-Path (Join-Path $psRoot 'clients') 'VirtuSphere-Client-Common.ps1'
    $script:VectorFile = Join-Path (Join-Path (Join-Path (Join-Path (Join-Path $script:RepoRoot 'Docker') 'WebAPI') 'tests') 'fixtures') 'mac-vectors.json'

    # Beide Module definieren ConvertTo-VsNormalizedMac. Sie laufen nie in
    # derselben Sitzung (SCCM-Server vs. Client), also wird jede Implementierung
    # in einem eigenen Kindscope dot-gesourct und dort aufgerufen: so ueberschreibt
    # nicht die zuletzt geladene Datei die Antwort der anderen.
    function Invoke-InFileScope {
        param([string]$Path, [scriptblock]$Body, [object[]]$Arguments = @())
        & {
            param($p, $b, $a)
            . $p
            & $b @a
        } $Path $Body $Arguments
    }

    function Get-NormalizedMac {
        param([string]$File, [string]$Value)
        Invoke-InFileScope -Path $File -Arguments @($Value) -Body {
            param($v)
            ConvertTo-VsNormalizedMac $v
        }
    }

    # Extrahiert den Rumpf einer Funktion als normalisierten Text (fuer den
    # Zwillings-Vergleich): Kommentare und Leerraum raus, nur der Code zaehlt.
    function Get-FunctionBody {
        param([string]$Path, [string]$Name)
        $tokens = $null
        $errors = $null
        $ast = [System.Management.Automation.Language.Parser]::ParseFile($Path, [ref]$tokens, [ref]$errors)
        $fn = $ast.Find(
            { param($node) $node -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $node.Name -eq $Name },
            $true)
        if (-not $fn) { return $null }
        # Kommentarzeilen und Leerraum entfernen: der Vergleich gilt dem Verhalten.
        $lines = $fn.Body.Extent.Text -split "`r?`n" |
            ForEach-Object { $_.Trim() } |
            Where-Object { $_ -ne '' -and -not $_.StartsWith('#') }
        return ($lines -join "`n")
    }

    $script:MacVectors = (Get-Content -Path $VectorFile -Raw | ConvertFrom-Json).vectors
}

Describe 'ConvertTo-VsNormalizedMac' {

    Context 'Alle drei Implementierungen teilen eine Wahrheit' {
        # Die Vektor-Datei ist die SSoT: PHPUnit (MacNormalizeTest) prueft
        # virtusphere_normalize_mac() dagegen, diese Suite die beiden
        # PowerShell-Zwillinge. Wer eine Seite aendert, ohne die anderen
        # nachzuziehen, bricht einen Build - genau das ist der Zweck.

        It 'MECM-Server: <why>' -ForEach $MacCases {
            Get-NormalizedMac -File $script:MecmCommon -Value $mac | Should -Be $expected
        }

        It 'Client: <why>' -ForEach $MacCases {
            Get-NormalizedMac -File $script:ClientCommon -Value $mac | Should -Be $expected
        }
    }

    Context 'Die beiden PowerShell-Zwillinge bleiben identisch' {
        It 'hat in beiden Dateien denselben Rumpf' {
            # Die Funktion existiert zweimal, weil die zwei Dateien auf zwei
            # verschiedene Maschinen ausgerollt werden und sich keine Datei teilen
            # koennen. Sie duerfen aber nicht auseinanderlaufen: wer eine Kopie
            # repariert und die andere vergisst, faellt hier auf.
            $mecm = Get-FunctionBody -Path $script:MecmCommon -Name 'ConvertTo-VsNormalizedMac'
            $client = Get-FunctionBody -Path $script:ClientCommon -Name 'ConvertTo-VsNormalizedMac'

            $mecm | Should -Not -BeNullOrEmpty
            $client | Should -Not -BeNullOrEmpty
            $client | Should -Be $mecm
        }
    }

    Context 'Idempotenz' {
        It 'kanonisiert eine bereits kanonische Adresse unveraendert' {
            $once = Get-NormalizedMac -File $script:MecmCommon -Value 'aa-bb-cc-dd-ee-ff'
            $twice = Get-NormalizedMac -File $script:MecmCommon -Value $once
            $twice | Should -Be $once
        }
    }
}

Describe 'Convert-VsSubnetMaskToPrefix' {

    It 'akzeptiert die Punktnotation: <mask> -> /<expected>' -ForEach @(
        @{ mask = '255.255.255.0';   expected = 24 }
        @{ mask = '255.255.255.252'; expected = 30 }
        @{ mask = '255.255.0.0';     expected = 16 }
        @{ mask = '255.0.0.0';       expected = 8 }
        @{ mask = '0.0.0.0';         expected = 0 }
        @{ mask = '255.255.255.255'; expected = 32 }
        @{ mask = ' 255.255.255.0 '; expected = 24 }
    ) {
        Invoke-InFileScope -Path $script:ClientCommon -Arguments @($mask) -Body {
            param($m) Convert-VsSubnetMaskToPrefix $m
        } | Should -Be $expected
    }

    It 'nimmt eine fertige Praefixlaenge unveraendert: <mask>' -ForEach @(
        @{ mask = '24'; expected = 24 }
        @{ mask = '0';  expected = 0 }
        @{ mask = '32'; expected = 32 }
    ) {
        Invoke-InFileScope -Path $script:ClientCommon -Arguments @($mask) -Body {
            param($m) Convert-VsSubnetMaskToPrefix $m
        } | Should -Be $expected
    }

    It 'weist ungueltige Eingaben ab: <why>' -ForEach @(
        # Der wichtigste Fall ist die nicht zusammenhaengende Maske: 255.0.255.0
        # hat 16 gesetzte Bits. Die alte Implementierung zaehlte nur Bits und
        # haette daraus stillschweigend /16 gemacht - der Client haette das
        # falsche Netz bekommen, ohne Fehler.
        @{ mask = '255.0.255.0';     why = 'nicht zusammenhaengende Maske' }
        @{ mask = '255.255.0.255';   why = 'Loch in der Maske' }
        @{ mask = '0.255.255.255';   why = 'invertierte Maske' }
        @{ mask = '999';             why = 'Praefix ausserhalb 0..32' }
        @{ mask = '33';              why = 'Praefix eins zu gross' }
        @{ mask = '256.255.255.0';   why = 'Oktett ausserhalb 0..255' }
        @{ mask = '255.255.255';     why = 'nur drei Oktette' }
        @{ mask = 'foo';             why = 'kein Zahlenwert' }
        @{ mask = '';                why = 'leer' }
        @{ mask = '   ';             why = 'nur Leerraum' }
    ) {
        Invoke-InFileScope -Path $script:ClientCommon -Arguments @($mask) -Body {
            param($m) Convert-VsSubnetMaskToPrefix $m
        } | Should -BeNullOrEmpty
    }
}

Describe 'Get-VsSupersededNamePattern' {

    BeforeAll {
        $script:Pattern = Invoke-InFileScope -Path $script:MecmCommon -Arguments @('Firefox') -Body {
            param($n) Get-VsSupersededNamePattern -AppName $n
        }
    }

    It 'trifft eine Altversion desselben Pakets' {
        'Firefox-1.0' | Should -Match $script:Pattern
        'Firefox-115' | Should -Match $script:Pattern
    }

    It 'trifft NICHT ein Fremdpaket mit demselben Praefix' {
        # Der historische Bug: der Wildcard 'Firefox*' loeschte auch Firefox-ESR-*.
        # Genau dafuer steht das [^-]+ im Muster.
        'Firefox-ESR-115' | Should -Not -Match $script:Pattern
        'Firefox-ESR-1.0' | Should -Not -Match $script:Pattern
    }

    It 'trifft NICHT ein Paket, das nur so anfaengt' {
        'FirefoxPortable-1.0' | Should -Not -Match $script:Pattern
    }

    It 'maskiert Regex-Sonderzeichen im Paketnamen' {
        $dotted = Invoke-InFileScope -Path $script:MecmCommon -Arguments @('Node.js') -Body {
            param($n) Get-VsSupersededNamePattern -AppName $n
        }
        'Node.js-20' | Should -Match $dotted
        # Ohne Escape wuerde der Punkt jedes Zeichen treffen.
        'NodeXjs-20' | Should -Not -Match $dotted
    }
}

Describe 'Read-VsPackageConfig' {

    BeforeAll {
        $script:PkgRoot = Join-Path ([System.IO.Path]::GetTempPath()) ('vs-pester-' + [guid]::NewGuid().ToString('N'))
        New-Item -ItemType Directory -Path $script:PkgRoot -Force | Out-Null

        function New-PackageFolder {
            param([string]$Name, [string]$Json)
            $dir = Join-Path $script:PkgRoot $Name
            New-Item -ItemType Directory -Path $dir -Force | Out-Null
            if ($null -ne $Json) {
                Set-Content -Path (Join-Path $dir 'config.json') -Value $Json -Encoding UTF8
            }
            return $dir
        }

        function Read-Config {
            param([string]$Folder)
            Invoke-InFileScope -Path $script:MecmCommon -Arguments @($Folder) -Body {
                param($f)
                # Logs in ein Wegwerf-Verzeichnis, damit der Test nichts unter
                # %ProgramFiles% anlegt.
                Initialize-VsLog -Component 'pester' -LogRoot ([System.IO.Path]::GetTempPath())
                Read-VsPackageConfig -Folder $f
            }
        }
    }

    AfterAll {
        if (Test-Path $script:PkgRoot) { Remove-Item -Path $script:PkgRoot -Recurse -Force -ErrorAction SilentlyContinue }
    }

    It 'liest eine gueltige config.json und ergaenzt den Ordnernamen' {
        $dir = New-PackageFolder -Name 'firefox' -Json '{"ProjectName":"Firefox","version":"115"}'
        $cfg = Read-Config -Folder $dir
        $cfg | Should -Not -BeNullOrEmpty
        $cfg.ProjectName | Should -Be 'Firefox'
        $cfg.version | Should -Be '115'
        $cfg.FolderName | Should -Be 'firefox'
    }

    It 'erlaubt einen Bindestrich im ProjectName' {
        # Der Katalog trennt am LETZTEN Bindestrich: 'Firefox-ESR-115' hat den
        # Basisnamen 'Firefox-ESR'. Das ist gueltig und muss durchgehen.
        $dir = New-PackageFolder -Name 'firefox-esr' -Json '{"ProjectName":"Firefox-ESR","version":"115"}'
        (Read-Config -Folder $dir).ProjectName | Should -Be 'Firefox-ESR'
    }

    It 'weist eine version mit Bindestrich ab' {
        # Sonst verschiebt sich die Basisnamen-Gruppierung fuer Retire/Relink:
        # 'Firefox-1.0-beta' waere der Basisname 'Firefox-1.0'.
        $dir = New-PackageFolder -Name 'beta' -Json '{"ProjectName":"Firefox","version":"1.0-beta"}'
        Read-Config -Folder $dir | Should -BeNullOrEmpty
    }

    It 'weist eine config.json ohne <field> ab' -ForEach @(
        @{ field = 'ProjectName'; json = '{"version":"1.0"}' }
        @{ field = 'version';     json = '{"ProjectName":"Firefox"}' }
        @{ field = 'Werte (leer)'; json = '{"ProjectName":"","version":""}' }
        @{ field = 'Werte (Leerraum)'; json = '{"ProjectName":"  ","version":"1.0"}' }
    ) {
        $dir = New-PackageFolder -Name ('bad-' + [guid]::NewGuid().ToString('N')) -Json $json
        Read-Config -Folder $dir | Should -BeNullOrEmpty
    }

    It 'weist kaputtes JSON ab, ohne zu werfen' {
        $dir = New-PackageFolder -Name 'broken' -Json '{"ProjectName":"Firefox",'
        { Read-Config -Folder $dir } | Should -Not -Throw
        Read-Config -Folder $dir | Should -BeNullOrEmpty
    }

    It 'liefert $null, wenn gar keine config.json da ist' {
        $dir = New-PackageFolder -Name 'empty' -Json $null
        Read-Config -Folder $dir | Should -BeNullOrEmpty
    }
}

Describe 'Get-VsErrorDetail' {

    # Der Grund, warum diese Funktion existiert: Invoke-RestMethod wirft in
    # Windows PowerShell 5.1 bei 4xx/5xx eine Exception und verwirft dabei den
    # Antwort-Body. Die WebApp baut aber genau dort ihre JSON-Envelope
    # ({"error":"..."}), und die Skripte loggten bisher nur "(400) Bad Request".

    It 'haengt die JSON-Envelope der WebApp an die Statuszeile' {
        $result = Invoke-InFileScope -Path $script:MecmCommon -Body {
            $body = '{"error":"Invalid data format"}'
            $stream = New-Object System.IO.MemoryStream (, [Text.Encoding]::UTF8.GetBytes($body))
            $response = [pscustomobject]@{ StatusCode = 400 }
            $response | Add-Member -MemberType ScriptMethod -Name GetResponseStream -Value { $stream }.GetNewClosure()
            $record = [pscustomobject]@{
                Exception = [pscustomobject]@{
                    Message  = 'The remote server returned an error: (400) Bad Request.'
                    Response = $response
                }
            }
            Get-VsErrorDetail -ErrorRecord $record
        }

        $result | Should -BeLike '*400*Bad Request*'
        $result | Should -BeLike '*WebApp: Invalid data format*'
    }

    It 'kommt ohne Response-Objekt aus (Netzfehler, DNS, Timeout)' {
        $result = Invoke-InFileScope -Path $script:MecmCommon -Body {
            $record = [pscustomobject]@{
                Exception = [pscustomobject]@{ Message = 'Der Remoteserver ist nicht erreichbar.' }
            }
            Get-VsErrorDetail -ErrorRecord $record
        }
        $result | Should -Be 'Der Remoteserver ist nicht erreichbar.'
    }

    It 'kuerzt eine Nicht-JSON-Antwort, statt sie zu verschlucken' {
        # Eine nginx-Fehlerseite statt der Envelope heisst: die Antwort kam gar
        # nicht von der WebApp. Genau das muss der Operator sehen.
        $result = Invoke-InFileScope -Path $script:MecmCommon -Body {
            $body = '<html><head><title>502 Bad Gateway</title></head><body>nginx</body></html>'
            $stream = New-Object System.IO.MemoryStream (, [Text.Encoding]::UTF8.GetBytes($body))
            $response = [pscustomobject]@{ StatusCode = 502 }
            $response | Add-Member -MemberType ScriptMethod -Name GetResponseStream -Value { $stream }.GetNewClosure()
            $record = [pscustomobject]@{
                Exception = [pscustomobject]@{ Message = '(502) Bad Gateway.'; Response = $response }
            }
            Get-VsErrorDetail -ErrorRecord $record
        }
        $result | Should -BeLike '*502 Bad Gateway*'
        $result | Should -BeLike '*nginx*'
    }
}

Describe 'Get-VsApiBaseUrl' {

    It 'nutzt http, solange kein Schema konfiguriert ist' {
        Invoke-InFileScope -Path $script:MecmCommon -Body {
            Get-VsApiBaseUrl -Config ([pscustomobject]@{ WebApi = 'virtusphere.lan:8021' })
        } | Should -Be 'http://virtusphere.lan:8021'
    }

    It 'folgt dem Registry-Schema auf https' {
        # Ohne diesen Schalter waere ein HTTPS-Umstieg des Portals das stille Ende
        # der MECM-Integration.
        Invoke-InFileScope -Path $script:MecmCommon -Body {
            Get-VsApiBaseUrl -Config ([pscustomobject]@{ WebApi = 'virtusphere.lan:8443'; Scheme = 'https' })
        } | Should -Be 'https://virtusphere.lan:8443'
    }
}

Describe 'Convert-VsWebApi' {

    It 'laesst eine kanonische host:port-Adresse unveraendert' {
        Invoke-InFileScope -Path $script:MecmCommon -Body { Convert-VsWebApi 'virtusphere.lan:8021' } | Should -Be 'virtusphere.lan:8021'
    }

    It 'strippt Schema, Trailing-Slash und Leerraum: <raw>' -ForEach @(
        @{ raw = 'http://virtusphere.lan:8021';   expected = 'virtusphere.lan:8021' }
        @{ raw = 'https://virtusphere.lan:8443/';  expected = 'virtusphere.lan:8443' }
        @{ raw = '  HTTP://10.0.0.5:8021  ';        expected = '10.0.0.5:8021' }
        @{ raw = 'virtusphere.lan';                 expected = 'virtusphere.lan' }
    ) {
        Invoke-InFileScope -Path $script:MecmCommon -Arguments @($raw) -Body { param($r) Convert-VsWebApi $r } | Should -Be $expected
    }

    It 'wirft bei einem Pfad oder ungueltiger Eingabe: <raw>' -ForEach @(
        @{ raw = 'virtusphere.lan:8021/portal' }   # Pfad
        @{ raw = 'http://foo/bar' }                 # Pfad nach Schema-Strip
        @{ raw = 'has space' }                      # Leerzeichen
        @{ raw = ':8021' }                          # kein Host
    ) {
        { Invoke-InFileScope -Path $script:MecmCommon -Arguments @($raw) -Body { param($r) Convert-VsWebApi $r } } | Should -Throw
    }
}

# Die Registry-Idempotenz beweist den P1-Fix des Installers: New-Item -Force auf
# einen bestehenden Key wischt dessen Werte (der ReportToken war weg), das
# bedingte Anlegen behaelt sie. Nur auf Windows (Registry-Provider); auf dem
# Linux-CI-Runner wird der Block gar nicht erst definiert.
$HasRegistry = Test-Path 'HKCU:\'
if ($HasRegistry) {
    Describe 'Installer-Registry-Idempotenz' {
        BeforeEach {
            $script:probeKey = 'HKCU:\Software\_vs_installer_pester_' + [guid]::NewGuid().ToString('N')
            New-Item -Path $script:probeKey -Force | Out-Null
            New-ItemProperty -Path $script:probeKey -Name 'ReportToken' -Value 'secret-token' -PropertyType String -Force | Out-Null
        }
        AfterEach {
            Remove-Item -Path $script:probeKey -Recurse -Force -ErrorAction SilentlyContinue
        }

        It 'New-Item -Force wischt bestehende Werte (der Grund fuer den Fix)' {
            New-Item -Path $script:probeKey -Force | Out-Null
            (Get-ItemProperty -Path $script:probeKey -Name 'ReportToken' -ErrorAction SilentlyContinue).ReportToken | Should -BeNullOrEmpty
        }

        It 'bedingtes Anlegen (Test-Path-Guard) behaelt bestehende Werte (der Fix)' {
            if (-not (Test-Path $script:probeKey)) { New-Item -Path $script:probeKey -Force | Out-Null }
            (Get-ItemProperty -Path $script:probeKey -Name 'ReportToken').ReportToken | Should -Be 'secret-token'
        }
    }
}

Describe 'Client-Packaging (Get-VsClientAppSpecs / Copy-VsClientContent)' {

    BeforeAll {
        $script:Packaging = Join-Path (Join-Path (Join-Path $script:RepoRoot 'Powershell-MECM') 'mecm') 'VirtuSphere-ClientPackaging.ps1'
        $script:ClientsDir = Join-Path (Join-Path $script:RepoRoot 'Powershell-MECM') 'clients'

        function Get-Specs {
            Invoke-InFileScope -Path $script:Packaging -Body { Get-VsClientAppSpecs }
        }
    }

    It 'liefert genau die vier Phasen in Ausfuehrungsreihenfolge' {
        $names = (Get-Specs).AppName
        $names | Should -Be @('client_getInfos', 'client_hostname', 'client_staticip', 'client_VMDisksOnline')
    }

    It 'verdrahtet die Kette getinfo -> hostname -> staticip -> disks' {
        $specs = Get-Specs
        ($specs | Where-Object AppName -eq 'client_getInfos').DependsOn      | Should -BeNullOrEmpty
        ($specs | Where-Object AppName -eq 'client_hostname').DependsOn      | Should -Be 'client_getInfos'
        ($specs | Where-Object AppName -eq 'client_staticip').DependsOn      | Should -Be 'client_hostname'
        ($specs | Where-Object AppName -eq 'client_VMDisksOnline').DependsOn | Should -Be 'client_staticip'
    }

    It 'jedes Spec-Skript existiert im clients-Ordner: <Script>' -ForEach @(
        @{ Script = 'client_getinfo.ps1' }
        @{ Script = 'client_hostname.ps1' }
        @{ Script = 'client_staticip.ps1' }
        @{ Script = 'Set-VMDisksOnline.ps1' }
    ) {
        Test-Path (Join-Path $script:ClientsDir $Script) | Should -BeTrue
    }

    Context 'Detection-Contract: die Erkennungswerte stimmen mit dem, was das Skript schreibt' {
        # Die kritischste SSoT: schreibt das Client-Skript einen anderen Registry-
        # Namen/Wert als die Detection erwartet, gilt die App nie als installiert
        # und MECM fuehrt sie endlos aus. Geprueft gegen den Skript-Quelltext.
        It '<AppName>: Skript schreibt DetectionName/-Key/-Werte aus der Spec' -ForEach $SpecCases {
            $src = Get-Content -Path (Join-Path $ClientsDir $Script) -Raw
            $src | Should -Match ([regex]::Escape($Name))
            $src | Should -Match ([regex]::Escape($KeyFrag))
            if ($Type -eq 'String') {
                foreach ($v in $Values) { $src | Should -Match ([regex]::Escape($v)) }
            }
            # Integer-Wert (1) wird berechnet ([int]$Success), kein Literal - daher
            # nur Name/Key geprueft.
        }
    }

    Context 'Copy-VsClientContent' {
        BeforeAll {
            $script:StageRoot = Join-Path ([System.IO.Path]::GetTempPath()) ('vs-stage-' + [guid]::NewGuid().ToString('N'))
        }
        AfterAll {
            if (Test-Path $script:StageRoot) { Remove-Item -Path $script:StageRoot -Recurse -Force -ErrorAction SilentlyContinue }
        }

        It 'legt Skript UND Common.ps1 in den App-Ordner (ersetzt)' {
            $result = Invoke-InFileScope -Path $script:Packaging -Arguments @($script:ClientsDir, $script:StageRoot) -Body {
                param($srcDir, $base)
                $spec = (Get-VsClientAppSpecs | Where-Object AppName -eq 'client_getInfos')
                Copy-VsClientContent -Spec $spec -SourceDir $srcDir -PackagesBase $base
            }
            Test-Path (Join-Path $result 'client_getinfo.ps1')               | Should -BeTrue
            Test-Path (Join-Path $result 'VirtuSphere-Client-Common.ps1')    | Should -BeTrue
            (Split-Path $result -Leaf) | Should -Be 'client_getInfos'
        }

        It 'wirft, wenn eine Quelldatei fehlt' {
            { Invoke-InFileScope -Path $script:Packaging -Arguments @($script:StageRoot, $script:StageRoot) -Body {
                param($srcDir, $base)
                $spec = (Get-VsClientAppSpecs | Where-Object AppName -eq 'client_getInfos')
                Copy-VsClientContent -Spec $spec -SourceDir $srcDir -PackagesBase $base
            } } | Should -Throw
        }
    }
}
