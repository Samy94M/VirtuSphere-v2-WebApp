# Pester-Suite fuer die reinen Funktionen der PowerShell-Integrationsclients.
#
# Dev-Host- und CI-Tooling, wie die Playwright- und Infection-Schichten (ADR-0028):
# nichts davon wird ausgeliefert, nichts liegt in einem Container.
#
# Warum es diese Suite gibt: die PowerShell-Skripte laufen als SYSTEM in
# Endlosschleifen auf dem MECM-Server und auf frisch ausgerollten Clients, und sie
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
    $script:ClientStaticIp = Join-Path (Join-Path $psRoot 'clients') 'client_staticip.ps1'
    $script:VectorFile = Join-Path (Join-Path (Join-Path (Join-Path (Join-Path $script:RepoRoot 'Docker') 'WebAPI') 'tests') 'fixtures') 'mac-vectors.json'

    # Beide Module definieren ConvertTo-VsNormalizedMac. Sie laufen nie in
    # derselben Sitzung (MECM-Server vs. Client), also wird jede Implementierung
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

Describe 'New-VsClientNetworkPlan (vollstaendige Vorabvalidierung)' {
    BeforeAll {
        $script:NetworkAdapter1 = [pscustomobject]@{ MacAddress = '00-11-22-33-44-55'; Status = 'Up'; PhysicalMediaType = '802.3'; ifIndex = 7; Name = 'Ethernet' }
        $script:NetworkAdapter2 = [pscustomobject]@{ MacAddress = '00-11-22-33-44-66'; Status = 'Up'; PhysicalMediaType = '802.3'; ifIndex = 8; Name = 'Ethernet 2' }
        $script:StaticTarget = [pscustomobject]@{ Mac = '00:11:22:33:44:55'; Name = 'Server'; Mode = 'static'; Ip = '10.0.0.10'; Subnet = '24'; Gateway = '10.0.0.1'; Dns1 = '10.0.0.2'; Dns2 = '' }
        $script:DhcpTarget = [pscustomobject]@{ Mac = '00:11:22:33:44:66'; Name = 'Backup'; Mode = 'dhcp'; Ip = ''; Subnet = ''; Gateway = ''; Dns1 = ''; Dns2 = '' }

        function script:Get-NetworkPlanForTest {
            param([array]$Targets, [array]$Adapters)
            $bundle = @{ Targets = @($Targets); Adapters = @($Adapters) }
            Invoke-InFileScope -Path $script:ClientCommon -Arguments @($bundle) -Body {
                param($b) New-VsClientNetworkPlan -Targets $b.Targets -Adapters $b.Adapters
            }
        }
    }

    It 'ordnet jede Soll-MAC genau einem nutzbaren Adapter zu' {
        $plan = Get-NetworkPlanForTest -Targets @($script:DhcpTarget, $script:StaticTarget) -Adapters @($script:NetworkAdapter1, $script:NetworkAdapter2)
        $plan.Valid | Should -BeTrue
        @($plan.Items).Count | Should -Be 2
        @($plan.Items | ForEach-Object Mac) | Should -Be @('00:11:22:33:44:55', '00:11:22:33:44:66')
        $plan.Items[0].Prefix | Should -Be 24
    }

    It 'verwendet nach der Planung den normalisierten Registry-Modus' {
        $rawTarget = [pscustomobject]@{
            Mac = '00:11:22:33:44:55'; Name = 'Server'; Mode = ' DHCP '
            Ip = ''; Subnet = ''; Gateway = ''; Dns1 = ''; Dns2 = ''
        }
        $plan = Get-NetworkPlanForTest -Targets @($rawTarget) -Adapters @($script:NetworkAdapter1)

        $plan.Valid | Should -BeTrue
        $rawTarget.Mode | Should -Be ' DHCP ' -Because 'der publizierte Rohwert bleibt unveraendert'
        $plan.Items[0].Mode | Should -Be 'dhcp'

        $tokens = $null
        $parseErrors = $null
        $ast = [System.Management.Automation.Language.Parser]::ParseFile(
            $script:ClientStaticIp, [ref]$tokens, [ref]$parseErrors)
        @($parseErrors).Count | Should -Be 0
        $modeReads = @($ast.FindAll({
            param($node)
            $node -is [System.Management.Automation.Language.MemberExpressionAst] -and
                $node.Member.Value -eq 'Mode'
        }, $true))
        @($modeReads | Where-Object { $_.Expression.Extent.Text -eq '$cfg' }).Count |
            Should -Be 0 -Because 'der Mutationspfad darf den unnormalisierten Target-Rohwert nicht erneut lesen'
        @($modeReads | Where-Object { $_.Expression.Extent.Text -eq '$item' }).Count |
            Should -BeGreaterThan 0 -Because 'der Mutationspfad muss den validierten Planmodus verwenden'
    }

    It 'blockiert vor Writes wenn eine zweite Soll-NIC fehlt' {
        $plan = Get-NetworkPlanForTest -Targets @($script:StaticTarget, $script:DhcpTarget) -Adapters @($script:NetworkAdapter1)
        $plan.Valid | Should -BeFalse
        $plan.Errors -join ' ' | Should -Match '00:11:22:33:44:66 fehlt'
    }

    It 'blockiert doppelte MACs, Down-Adapter und mehrere Gateways' {
        $secondStatic = [pscustomobject]@{ Mac = '00:11:22:33:44:66'; Name = 'Backup'; Mode = 'static'; Ip = '10.0.1.10'; Subnet = '24'; Gateway = '10.0.1.1'; Dns1 = ''; Dns2 = '' }
        $down = [pscustomobject]@{ MacAddress = '00-11-22-33-44-66'; Status = 'Down'; PhysicalMediaType = '802.3'; ifIndex = 8; Name = 'Ethernet 2' }
        $plan = Get-NetworkPlanForTest -Targets @($script:StaticTarget, $secondStatic) -Adapters @($script:NetworkAdapter1, $down, $down)
        $plan.Valid | Should -BeFalse
        $text = $plan.Errors -join ' '
        $text | Should -Match 'mehrdeutig'
        $text | Should -Match 'Mehrere Sollschnittstellen definieren ein Default-Gateway'
    }

    It 'behandelt eine leere Sollmenge als Fehler' {
        (Get-NetworkPlanForTest -Targets @() -Adapters @($script:NetworkAdapter1)).Valid | Should -BeFalse
    }
}

Describe 'Get-VsDiskStableIdentity (A09 Wiederanlauf)' {
    It 'bevorzugt die stabile UniqueId und ignoriert die veraenderliche Disknummer' {
        $first = [pscustomobject]@{ Number = 2; UniqueId = ' 6000C29A-ABC '; SerialNumber = 'old'; LocationPath = 'slot-1'; Size = 10GB }
        $renumbered = [pscustomobject]@{ Number = 7; UniqueId = '6000C29A-ABC'; SerialNumber = 'different'; LocationPath = 'slot-9'; Size = 10GB }
        $bundle = @($first, $renumbered)
        $ids = Invoke-InFileScope -Path $script:ClientCommon -Arguments @(,$bundle) -Body {
            param($items) @($items | ForEach-Object { Get-VsDiskStableIdentity -Disk $_ })
        }
        $ids[0].Valid | Should -BeTrue
        $ids[0].Identity | Should -Be 'unique:6000C29A-ABC'
        $ids[1].Identity | Should -Be $ids[0].Identity
    }

    It 'verwendet nur den vollstaendigen Seriennummer-Location-Groesse-Fallback' {
        $disk = [pscustomobject]@{ Number = 3; UniqueId = ''; SerialNumber = 'SER-1'; LocationPath = 'PCIROOT(0)#SLOT(4)'; Size = 20GB }
        $id = Invoke-InFileScope -Path $script:ClientCommon -Arguments @($disk) -Body {
            param($item) Get-VsDiskStableIdentity -Disk $item
        }
        $id.Valid | Should -BeTrue
        $id.Identity | Should -Be "serial-location-size:SER-1|PCIROOT(0)#SLOT(4)|$([long](20GB))"
    }

    It 'verweigert Disknummer, FriendlyName oder einen unvollstaendigen Fallback als Eigentumsbeweis' {
        $disk = [pscustomobject]@{ Number = 4; FriendlyName = 'VMware Virtual disk'; UniqueId = ''; SerialNumber = 'SER-1'; LocationPath = ''; Size = 20GB }
        $id = Invoke-InFileScope -Path $script:ClientCommon -Arguments @($disk) -Body {
            param($item) Get-VsDiskStableIdentity -Disk $item
        }
        $id.Valid | Should -BeFalse
        $id.Identity | Should -BeNullOrEmpty
        $id.Reason | Should -Match 'stabile Datentraegeridentitaet'
    }

    It 'bildet fuer dieselbe Identitaet einen deterministischen Journalschluessel' {
        $hashes = Invoke-InFileScope -Path $script:ClientCommon -Arguments @('unique:disk-1') -Body {
            param($value) @((Get-VsSha256Hex -Value $value), (Get-VsSha256Hex -Value $value))
        }
        $hashes[0] | Should -Be $hashes[1]
        $hashes[0] | Should -Match '^[0-9a-f]{64}$'
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

Describe 'A14b sichere Paketversions- und Bereinigungsplanung' {
    BeforeAll { . $script:MecmCommon }

    It 'blocks numeric-equivalent source versions independently of input order' {
        foreach ($versions in @(@('1', '1.0'), @('1.0', '1'))) {
            $selection = @(Get-VsPackageSourceSelections -Packages @($versions | ForEach-Object { [pscustomobject]@{ ProjectName = 'Agent'; version = $_ } }))
            $selection[0].State | Should -Be 'blocked'
            $selection[0].Blockers | Should -Contain 'duplicate_version:1'
        }
    }

    It 'does not flag supplied or newer versions as cleanup failures' {
        $selection = @(Get-VsPackageSourceSelections -Packages @(
            [pscustomobject]@{ ProjectName = 'Agent'; version = '1' },
            [pscustomobject]@{ ProjectName = 'Agent'; version = '2' }
        ))
        $names = @(Get-VsPackageRetainedNames -ProductName Agent -SourceVersions @('2') -Selections $selection -Names @('Agent-1', 'Agent-2', 'Agent-3', 'Agent-0.9', 'Other-0.8'))
        $names.Count | Should -Be 1
        $names[0] | Should -Be 'Agent-0.9'
    }

    It 'keeps an unsupported old version explicit without guessing its order' {
        $selection = @(Get-VsPackageSourceSelections -Packages @([pscustomobject]@{ ProjectName = 'Agent'; version = '2' }))
        @(Get-VsPackageRetainedNames -ProductName Agent -SourceVersions @('2') -Selections $selection -Names @('Agent-0.1b')) | Should -Contain 'Agent-0.1b'
    }

    It 'ordnet numerische Segmente statt lexikalisch oder ueber begrenzte Integer' {
        (Compare-VsPackageVersion -Left '1.9' -Right '1.10') | Should -Be -1
        (Compare-VsPackageVersion -Left '2' -Right '10') | Should -Be -1
        (Compare-VsPackageVersion -Left '999999999999999999999' -Right '10') | Should -Be 1
        (Compare-VsPackageVersion -Left '1.0' -Right '1') | Should -Be 0
        { Compare-VsPackageVersion -Left 'release-x' -Right '1' } | Should -Throw '*Nicht interpretierbare*'
    }

    It 'blockiert freie oder doppelte Quellversionen und waehlt sonst genau den hoechsten Zielstand' {
        $selected = @(Get-VsPackageSourceSelections -Packages @(
            [pscustomobject]@{ ProjectName = 'Agent'; version = '1.9' },
            [pscustomobject]@{ ProjectName = 'Agent'; version = '1.10' },
            [pscustomobject]@{ ProjectName = 'Free'; version = 'release-x' },
            [pscustomobject]@{ ProjectName = 'Dup'; version = '2' },
            [pscustomobject]@{ ProjectName = 'Dup'; version = '2' }
        ))
        ($selected | Where-Object ProductName -eq 'Agent').TargetName | Should -Be 'Agent-1.10'
        ($selected | Where-Object ProductName -eq 'Free').State | Should -Be 'blocked'
        ($selected | Where-Object ProductName -eq 'Dup').State | Should -Be 'blocked'
    }

    It 'plant nur nicht mehr gelieferte, markierte und unreferenzierte IDs bei belegtem Ersatz' {
        $selection = (Get-VsPackageSourceSelections -Packages @(
            [pscustomobject]@{ ProjectName = 'Agent'; version = '1.10' },
            [pscustomobject]@{ ProjectName = 'Agent'; version = '1.9' }
        ))[0]
        $replacement = [pscustomobject]@{ Name = 'Agent-1.10'; Owned = $true; DeploymentTypeCount = 1; ContentState = 'complete'; DistributionState = 'succeeded'; DeploymentReady = $true }
        $plan = Get-VsPackageRetirementPlan -Selection $selection -Applications @(
            [pscustomobject]@{ LocalizedDisplayName = 'Agent-1.8'; CI_ID = '101'; LocalizedDescription = $script:VsManagedPackageApplicationMarker },
            [pscustomobject]@{ LocalizedDisplayName = 'Agent-1.7'; CI_ID = '102'; LocalizedDescription = 'foreign' },
            [pscustomobject]@{ LocalizedDisplayName = 'Agent-1.9'; CI_ID = '103'; LocalizedDescription = $script:VsManagedPackageApplicationMarker }
        ) -Collections @(
            [pscustomobject]@{ Name = 'Agent-1.8'; CollectionID = 'C01'; Comment = $script:VsManagedPackageCollectionMarker }
        ) -Replacement $replacement -References @(
            [pscustomobject]@{ TargetType = 'collection'; TargetId = 'C99' }
        ) -ReferenceScanComplete $true
        $plan.State | Should -Be 'blocked'
        $plan.Blockers | Should -Contain 'application_not_owned:Agent-1.7'
        @($plan.Items | Where-Object Name -eq 'Agent-1.8').Count | Should -Be 2
        @($plan.Items | Where-Object Name -eq 'Agent-1.9').Count | Should -Be 0 -Because 'eine weiterhin gelieferte Quellversion wird nie bereinigt'
        $plan.PlanHash | Should -Match '^[0-9a-f]{64}$'
    }

    It 'blockiert einen unvollstaendigen Referenzscan auch bei leerer Fundliste' {
        $selection = (Get-VsPackageSourceSelections -Packages @([pscustomobject]@{ ProjectName = 'Agent'; version = '2' }))[0]
        $replacement = [pscustomobject]@{ Name = 'Agent-2'; Owned = $true; DeploymentTypeCount = 1; ContentState = 'complete'; DistributionState = 'succeeded'; DeploymentReady = $true }
        $plan = Get-VsPackageRetirementPlan -Selection $selection -Applications @() -Collections @() -Replacement $replacement -References @() -ReferenceScanComplete $false
        $plan.State | Should -Be 'blocked'
        $plan.Blockers | Should -Contain 'reference_scan_incomplete'
    }

    It 'verweigert einen veralteten Plan vor dem ersten Remove' {
        $calls = New-Object System.Collections.Generic.List[string]
        $approved = [pscustomobject]@{ State = 'ready'; PlanHash = 'a'; Items = @([pscustomobject]@{ Kind = 'application'; Id = '1'; Name = 'A-1' }) }
        $current = [pscustomobject]@{ State = 'ready'; PlanHash = 'b'; Items = $approved.Items }
        { Invoke-VsPackageRetirementPlan -ApprovedPlan $approved -CurrentPlan $current -RemoveApplication { param($i) $calls.Add($i.Id) } -RemoveCollection { param($i) $calls.Add($i.Id) } } | Should -Throw '*veraltet*'
        $calls.Count | Should -Be 0
    }

    It 'bricht nach einem Teilfehler ab und meldet jede ausgefuehrte Einheit' {
        $items = @(
            [pscustomobject]@{ Kind = 'application'; Id = '1'; Name = 'A-1' },
            [pscustomobject]@{ Kind = 'collection'; Id = '2'; Name = 'A-1' },
            [pscustomobject]@{ Kind = 'collection'; Id = '3'; Name = 'A-0' }
        )
        $plan = [pscustomobject]@{ State = 'ready'; PlanHash = 'same'; Items = $items }
        $result = @(Invoke-VsPackageRetirementPlan -ApprovedPlan $plan -CurrentPlan $plan -RemoveApplication { param($i) } -RemoveCollection { param($i) if ($i.Id -eq '2') { throw 'provider' } })
        $result.Count | Should -Be 2
        $result[0].State | Should -Be 'removed'
        $result[1].State | Should -Be 'failed'
    }
}

Describe 'A16 sprachunabhaengige ACL-Pruefung' {
    BeforeAll { . $script:MecmCommon }

    It 'meldet breite Allow-Schreibrechte per SID und ignoriert Namen, Read, Deny und Administratoren' {
        $allow = [Security.AccessControl.AccessControlType]::Allow
        $deny = [Security.AccessControl.AccessControlType]::Deny
        $acl = [pscustomobject]@{ Access = @(
            [pscustomobject]@{ IdentityReference = 'S-1-5-32-545'; AccessControlType = $allow; FileSystemRights = [Security.AccessControl.FileSystemRights]::Modify },
            [pscustomobject]@{ IdentityReference = 'S-1-1-0'; AccessControlType = $deny; FileSystemRights = [Security.AccessControl.FileSystemRights]::FullControl },
            [pscustomobject]@{ IdentityReference = 'S-1-5-11'; AccessControlType = $allow; FileSystemRights = [Security.AccessControl.FileSystemRights]::ReadAndExecute },
            [pscustomobject]@{ IdentityReference = 'S-1-5-32-544'; AccessControlType = $allow; FileSystemRights = [Security.AccessControl.FileSystemRights]::FullControl }
        ) }
        $issues = @(Get-VsDangerousFileSystemAclEntries -Acl $acl)
        $issues.Count | Should -Be 1
        [string]$issues[0].IdentityReference | Should -Be 'S-1-5-32-545'
    }

    It 'haelt beide Installer auf dem SID-Adapter und leert explizite Regeln des eigenen Secret-Keys' {
        $server = Get-Content -LiteralPath (Join-Path (Split-Path $script:MecmCommon -Parent | Split-Path -Parent) 'install-VirtuSphere-MECM.ps1') -Raw
        $client = Get-Content -LiteralPath (Join-Path (Split-Path $script:MecmCommon -Parent | Split-Path -Parent) 'install-VirtuSphere-Clients.ps1') -Raw
        $server | Should -Match 'Get-VsDangerousFileSystemAclEntries'
        $client | Should -Match 'Get-VsDangerousFileSystemAclEntries'
        $server | Should -Match 'RemoveAccessRuleSpecific\(\$existingRule\)'
        $server | Should -Not -Match "IdentityReference\s+-match\s+'Users\|Everyone\|Authenticated Users'"
        $client | Should -Not -Match "IdentityReference\s+-match\s+'Users\|Everyone\|Authenticated Users'"
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

    It 'rejects invalid JSON types or ambiguous names: <why>' -ForEach @(
        @{ why = 'numeric version'; json = '{"ProjectName":"Agent","version":0.2}' },
        @{ why = 'array version'; json = '{"ProjectName":"Agent","version":["0.2"]}' },
        @{ why = 'object name'; json = '{"ProjectName":{},"version":"0.2"}' },
        @{ why = 'wildcard name'; json = '{"ProjectName":"Agent*","version":"0.2"}' },
        @{ why = 'version whitespace'; json = '{"ProjectName":"Agent","version":" 0.2"}' }
    ) {
        $dir = New-PackageFolder -Name ([guid]::NewGuid().ToString('N')) -Json $json
        Read-Config -Folder $dir | Should -BeNullOrEmpty
    }

    It 'preserves supported legacy letter versions without inventing a cleanup order' {
        $dir = New-PackageFolder -Name 'legacy-letter' -Json '{"ProjectName":"Agent-FINAL","version":"0.1b"}'
        (Read-Config -Folder $dir).version | Should -Be '0.1b'
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

    # InstallationBehaviorType entscheidet auf ZWEI Maschinen dieselbe Frage:
    # der Autoimporter legt die Detection-Klausel auf HKLM oder HKCU, und
    # Package_Vorlage\install.ps1 schreibt den Schluessel dorthin. Ein fehlendes
    # Feld schickte sie frueher in verschiedene Zweige, ein Tippfehler ebenso:
    # die App galt nie als installiert und wurde endlos erneut versucht.
    It 'normalisiert <case>' -ForEach @(
        @{ case = 'ein fehlendes InstallationBehaviorType auf InstallForSystem'; json = '{"ProjectName":"F","version":"1"}'; expected = 'InstallForSystem' }
        @{ case = 'ein leeres Feld auf InstallForSystem'; json = '{"ProjectName":"F","version":"1","InstallationBehaviorType":""}'; expected = 'InstallForSystem' }
        @{ case = 'ein gesetztes InstallForUser unveraendert'; json = '{"ProjectName":"F","version":"1","InstallationBehaviorType":"InstallForUser"}'; expected = 'InstallForUser' }
        @{ case = 'Kleinschreibung auf die kanonische Form'; json = '{"ProjectName":"F","version":"1","InstallationBehaviorType":"installforsystem"}'; expected = 'InstallForSystem' }
    ) {
        $dir = New-PackageFolder -Name ('beh-' + [guid]::NewGuid().ToString('N')) -Json $json
        (Read-Config -Folder $dir).InstallationBehaviorType | Should -Be $expected
    }

    It 'weist ein unbekanntes InstallationBehaviorType ab' {
        # config.json wird von Hand gepflegt, ein Tippfehler ist der Normalfall
        # und keine Meinungsaeusserung. Das Paket verschwindet dabei nicht (der
        # Autoimporter loescht nichts), es bekommt nur keine Aktualisierung, und
        # der offene Punkt nennt Ordner und Wert.
        $dir = New-PackageFolder -Name 'typo' -Json '{"ProjectName":"F","version":"1","InstallationBehaviorType":"InstalForSystem"}'
        Read-Config -Folder $dir | Should -BeNullOrEmpty
    }
}

Describe 'InstallationBehaviorType: beide Seiten defaulten in dieselbe Richtung' {

    # Die Vorlage sieht Common nie (sie wird allein in den Paketordner kopiert),
    # fuehrt das Literal also gespiegelt. Genau das ist die Drift-Gefahr, gegen
    # die dieser Test steht.
    BeforeAll {
        $psRoot = Join-Path $script:RepoRoot 'Powershell-MECM'
        $script:TemplateText = Get-Content -Raw -Path (Join-Path (Join-Path $psRoot 'Package_Vorlage') 'install.ps1')
        $script:AutoimporterText = Get-Content -Raw -Path (Join-Path (Join-Path $psRoot 'mecm') 'mecm_autoimporter.ps1')
        $script:BehaviorTypes = @(Invoke-InFileScope -Path $script:MecmCommon -Body { $script:VsInstallationBehaviorTypes })
    }

    It 'Common fuehrt genau die beiden erlaubten Werte, InstallForSystem zuerst' {
        $script:BehaviorTypes.Count | Should -Be 2
        $script:BehaviorTypes[0] | Should -Be 'InstallForSystem' -Because 'der erste Eintrag ist der Standard bei fehlendem Feld'
        $script:BehaviorTypes | Should -Contain 'InstallForUser'
    }

    It '<file> nennt keinen Wert, den Common nicht kennt' -ForEach @(
        @{ file = 'Package_Vorlage/install.ps1' }
        @{ file = 'mecm_autoimporter.ps1' }
    ) {
        $text = if ($file -match 'Vorlage') { $script:TemplateText } else { $script:AutoimporterText }
        $found = @([regex]::Matches($text, 'Install[A-Za-z]*For[A-Za-z]+') | ForEach-Object { $_.Value } | Select-Object -Unique)
        $found.Count | Should -BeGreaterThan 0 -Because 'ohne Fundstelle prueft dieser Test nichts'
        foreach ($value in $found) { $script:BehaviorTypes | Should -Contain $value }
    }

    It 'beide fragen auf InstallForUser und fallen auf InstallForSystem zurueck' {
        # Die Richtung ist der Befund: der Autoimporter prueft auf
        # InstallForUser, die Vorlage prueft frueher auf InstallForSystem. Bei
        # einem Bestandspaket ohne das Feld legte MECM die Detection auf HKLM,
        # waehrend die Vorlage nach HKCU schrieb.
        $script:TemplateText | Should -Match 'InstallationBehaviorType\s+-eq\s+"InstallForUser"'
        $script:AutoimporterText | Should -Match "InstallationBehaviorType\s+-eq\s+'InstallForUser'"
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

    It 'bevorzugt ErrorDetails.Message auch wenn der Response-Stream bereits leer ist' {
        $detail = Invoke-InFileScope -Path $script:MecmCommon -Body {
            $record = New-Object System.Management.Automation.ErrorRecord(
                (New-Object System.Exception('HTTP 409')), 'id',
                [System.Management.Automation.ErrorCategory]::InvalidOperation, $null)
            $record.ErrorDetails = New-Object System.Management.Automation.ErrorDetails('{"error":"veralteter Plan"}')
            Get-VsErrorDetail -ErrorRecord $record
        }
        $detail | Should -Match 'WebApp: veralteter Plan'
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

    It 'validiert den deklarierten Graphen und blockiert unbekannte Kanten oder Zyklen' {
        { Invoke-InFileScope -Path $script:Packaging -Body { Assert-VsClientAppSpecGraph -Specs (Get-VsClientAppSpecs) } } | Should -Not -Throw
        { Invoke-InFileScope -Path $script:Packaging -Body {
            Assert-VsClientAppSpecGraph -Specs @(
                [pscustomobject]@{ AppName = 'a'; DependsOn = 'missing' }
            )
        } } | Should -Throw '*unbekannten Vorgaenger*'
        { Invoke-InFileScope -Path $script:Packaging -Body {
            Assert-VsClientAppSpecGraph -Specs @(
                [pscustomobject]@{ AppName = 'a'; DependsOn = 'b' }
                [pscustomobject]@{ AppName = 'b'; DependsOn = 'a' }
            )
        } } | Should -Throw '*Zyklus*'
    }

    It 'verlangt fuer jede Phase den Standard-Returncodevertrag' {
        foreach ($spec in (Get-Specs)) {
            @($spec.ReturnCodes.Value) | Should -Be @(0, 1641, 3010)
            ($spec.ReturnCodes | Where-Object Value -eq 1641).Type | Should -Be 'HardReboot'
            ($spec.ReturnCodes | Where-Object Value -eq 3010).Type | Should -Be 'SoftReboot'
        }
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

        It 'legt Skript, Common und Loggingmodul in den App-Ordner (ersetzt)' {
            $result = Invoke-InFileScope -Path $script:Packaging -Arguments @($script:ClientsDir, $script:StageRoot) -Body {
                param($srcDir, $base)
                $spec = (Get-VsClientAppSpecs | Where-Object AppName -eq 'client_getInfos')
                Copy-VsClientContent -Spec $spec -SourceDir $srcDir -PackagesBase $base -Bootstrap @{ Schema = 1; WebAPI = 'virtusphere.test:8021'; Scheme = 'http'; CertThumbprint = '' }
            }
            Test-Path (Join-Path $result 'client_getinfo.ps1')               | Should -BeTrue
            Test-Path (Join-Path $result 'VirtuSphere-Client-Common.ps1')    | Should -BeTrue
            Test-Path (Join-Path $result 'VirtuSphere-Client-Logging.ps1')   | Should -BeTrue
            Test-Path (Join-Path $result 'bootstrap.json')                    | Should -BeTrue
            $bootstrap = Get-Content -LiteralPath (Join-Path $result 'bootstrap.json') -Raw | ConvertFrom-Json
            $bootstrap.WebAPI | Should -Be 'virtusphere.test:8021'
            $bootstrap.Scheme | Should -Be 'http'
            (Split-Path $result -Leaf) | Should -Be 'client_getInfos'
        }

        It 'wirft, wenn eine Quelldatei fehlt' {
            { Invoke-InFileScope -Path $script:Packaging -Arguments @($script:StageRoot, $script:StageRoot) -Body {
                param($srcDir, $base)
                $spec = (Get-VsClientAppSpecs | Where-Object AppName -eq 'client_getInfos')
                Copy-VsClientContent -Spec $spec -SourceDir $srcDir -PackagesBase $base -Bootstrap @{ Schema = 1; WebAPI = 'virtusphere.test:8021'; Scheme = 'http'; CertThumbprint = '' }
            } } | Should -Throw
        }

        It 'vergleicht am tatsaechlichen Share das vollstaendige Pfad-Laenge-Hash-Manifest' {
            $left = Join-Path $script:StageRoot 'manifest-left'
            $right = Join-Path $script:StageRoot 'manifest-right'
            New-Item -Path $left -ItemType Directory -Force | Out-Null
            New-Item -Path $right -ItemType Directory -Force | Out-Null
            Set-Content -LiteralPath (Join-Path $left 'a.txt') -Value 'gleich' -Encoding UTF8
            Copy-Item -LiteralPath (Join-Path $left 'a.txt') -Destination $right
            @(Invoke-InFileScope -Path $script:Packaging -Arguments @($left, $right) -Body {
                param($a, $b) Compare-VsClientContentManifest -StagedPath $a -PublishedPath $b
            }).Count | Should -Be 0

            Set-Content -LiteralPath (Join-Path $right 'a.txt') -Value 'anders' -Encoding UTF8
            Set-Content -LiteralPath (Join-Path $right 'alt.txt') -Value 'alt' -Encoding UTF8
            $issues = @(Invoke-InFileScope -Path $script:Packaging -Arguments @($left, $right) -Body {
                param($a, $b) Compare-VsClientContentManifest -StagedPath $a -PublishedPath $b
            })
            $issues | Should -Contain 'content:a.txt'
            $issues | Should -Contain 'extra:alt.txt'
        }
    }

    Context 'bestehende MECM-Definitionen' {
        It 'erkennt Eigentum nur am Marker oder am exakten Legacy-Ordner' {
            $spec = (Get-Specs)[0]
            $marked = [pscustomobject]@{ LocalizedDescription = $spec.ManagedMarker; ObjectPath = '' }
            $legacy = [pscustomobject]@{ LocalizedDescription = ''; ObjectPath = 'Application\VirtuSphere_Core' }
            $foreign = [pscustomobject]@{ LocalizedDescription = ''; ObjectPath = 'Application\Other' }
            Invoke-InFileScope -Path $script:Packaging -Arguments @($marked, $spec) -Body {
                param($app, $s) Test-VsClientApplicationOwnership -Application $app -Spec $s -AppFolder 'VirtuSphere_Core'
            } | Should -BeTrue
            Invoke-InFileScope -Path $script:Packaging -Arguments @($legacy, $spec) -Body {
                param($app, $s) Test-VsClientApplicationOwnership -Application $app -Spec $s -AppFolder 'VirtuSphere_Core'
            } | Should -BeTrue
            Invoke-InFileScope -Path $script:Packaging -Arguments @($foreign, $spec) -Body {
                param($app, $s) Test-VsClientApplicationOwnership -Application $app -Spec $s -AppFolder 'VirtuSphere_Core'
            } | Should -BeFalse
        }

        It 'prueft DT, Detection, Content, Kontext und Returncodes ohne Drift zu reparieren' {
            $spec = (Get-Specs)[0]
            $command = 'powershell.exe -NoProfile -File client_getinfo.ps1'
            $content = '\\server\share\client_getInfos'
            $xml = '<DeploymentType><Name>client_getInfos Deployment</Name><ContentLocation>{0}</ContentLocation><InstallCommand>{1}</InstallCommand><Context>System</Context><Reboot>BasedOnExitCode</Reboot><Detection><Key>{2}</Key><Name>{3}</Name><Type>{4}</Type><Value>{5}</Value></Detection></DeploymentType>' -f $content, $command, $spec.DetectionKey, $spec.DetectionName, $spec.DetectionType, $spec.DetectionValues[0]
            $dt = [pscustomobject]@{ LocalizedDisplayName = 'client_getInfos Deployment'; SDMPackageXML = $xml }
            $codes = @(
                [pscustomobject]@{ Value = 0; CodeType = 'Success' }
                [pscustomobject]@{ Value = 1641; CodeType = 'HardReboot' }
                [pscustomobject]@{ Value = 3010; CodeType = 'SoftReboot' }
            )
            $issues = @(Invoke-InFileScope -Path $script:Packaging -Arguments @($dt, $spec, $content, $command, $codes) -Body {
                param($d, $s, $c, $i, $r) Get-VsClientDeploymentTypeContractIssues -DeploymentType $d -Spec $s -ContentLocation $c -InstallCommand $i -ReturnCodes $r
            })
            $issues.Count | Should -Be 0

            $codes[1].CodeType = 'Success'
            $issues = @(Invoke-InFileScope -Path $script:Packaging -Arguments @($dt, $spec, $content, $command, $codes) -Body {
                param($d, $s, $c, $i, $r) Get-VsClientDeploymentTypeContractIssues -DeploymentType $d -Spec $s -ContentLocation $c -InstallCommand $i -ReturnCodes $r
            })
            $issues | Should -Contain 'return-code-type:1641'
        }
    }
}
