# Pester-Suite fuer den Prozesshost des Paket-Reporters (ADR-0044): TLS-Vorbereitung
# des Berichtswegs (PS-12) und die protokollierte Empfaengergrenze der IPC (SD-11).
#
# Die Protokollfunktion wird nur auf Windows geprueft (ServicePointManager). Die
# AST-Pruefungen laufen ueberall. Ausfuehren:
#   Invoke-Pester tests/powershell/VirtuSphere.PackageReporterHostProcess.Tests.ps1

# Auf Dateiebene, weil -Skip schon in der Discovery-Phase gebraucht wird.
$HostIsWindows = [Environment]::OSVersion.Platform -eq [PlatformID]::Win32NT

BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $script:ClientsDir = Join-Path (Join-Path $script:RepoRoot 'Powershell-MECM') 'clients'
    $script:HostFile = Join-Path $script:ClientsDir 'VirtuSphere-Package-ReporterHost.ps1'
    $script:ClientCommonFile = Join-Path $script:ClientsDir 'VirtuSphere-Client-Common.ps1'

    function Get-HostAst {
        param([string]$Path)
        $tokens = $null
        $errors = $null
        [System.Management.Automation.Language.Parser]::ParseFile($Path, [ref]$tokens, [ref]$errors)
    }

    function Get-NamedFunctionBody {
        param([string]$Path, [string]$Name)
        $ast = Get-HostAst -Path $Path
        $found = @($ast.FindAll({ param($n)
            $n -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $n.Name -eq $Name
        }, $true))
        if ($found.Count -ne 1) { throw "$Name in $Path nicht eindeutig ($($found.Count))." }
        return $found[0]
    }

    # Dot-Source wie die anderen Reporter-Suiten: der Host definiert nur Funktionen,
    # sein Hauptpfad laeuft nur mit einem Pipe-Namen.
    . $script:HostFile
}

Describe 'Reporter-Host setzt TLS 1.2 einmal beim Start und nur bei https (PS-12)' -Skip:(-not $HostIsWindows) {
    BeforeEach {
        $script:PreviousSecurityProtocol = [Net.ServicePointManager]::SecurityProtocol
        [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls
    }

    AfterEach {
        [Net.ServicePointManager]::SecurityProtocol = $script:PreviousSecurityProtocol
    }

    It 'setzt TLS 1.2 fuer eine https-Konfiguration' {
        $api = [pscustomobject]@{ Api = 'portal.test:8021'; Scheme = 'https'; CertThumbprint = ''; ReportUrl = 'https://portal.test:8021/mecm_report.php?action=reportPackageRun' }
        Set-VsPackageReportTlsProtocol -ApiConfiguration $api | Should -BeTrue
        [Net.ServicePointManager]::SecurityProtocol | Should -Be ([Net.SecurityProtocolType]::Tls12)
    }

    It 'laesst das Protokoll bei http und bei fehlender Konfiguration unberuehrt' {
        $api = [pscustomobject]@{ Api = 'portal.test:8021'; Scheme = 'http'; CertThumbprint = ''; ReportUrl = 'http://portal.test:8021/mecm_report.php?action=reportPackageRun' }
        Set-VsPackageReportTlsProtocol -ApiConfiguration $api | Should -BeFalse
        Set-VsPackageReportTlsProtocol -ApiConfiguration $null | Should -BeFalse
        [Net.ServicePointManager]::SecurityProtocol | Should -Be ([Net.SecurityProtocolType]::Tls)
    }
}

Describe 'Reporter-Host: TLS-Aufruf und Lesegrenze' {
    It 'Get-VsPackageReportApiConfiguration bleibt read-only (kein TLS, kein Trust-Rueckruf, keine Registry)' {
        $fn = Get-NamedFunctionBody -Path $script:ClientCommonFile -Name 'Get-VsPackageReportApiConfiguration'
        $body = $fn.Body.Extent.Text
        $body | Should -Not -Match 'SecurityProtocol|ServicePointManager|Initialize-VsTls|New-ItemProperty|Set-ItemProperty'
    }

    It 'der Hoststart ruft die TLS-Vorbereitung nach dem Vertragscheck und vor dem Ready-Handshake' {
        $fn = Get-NamedFunctionBody -Path $script:HostFile -Name 'Start-VsPackageReportPipeWorker'
        $tlsCall = @($fn.Body.FindAll({ param($n)
            $n -is [System.Management.Automation.Language.CommandAst] -and $n.GetCommandName() -eq 'Set-VsPackageReportTlsProtocol'
        }, $true))
        $contractCheck = @($fn.Body.FindAll({ param($n)
            $n -is [System.Management.Automation.Language.CommandAst] -and $n.GetCommandName() -eq 'Test-VsPackageReporterDependencies'
        }, $true))
        $ready = @($fn.Body.FindAll({ param($n)
            $n -is [System.Management.Automation.Language.StringConstantExpressionAst] -and $n.Value -match '"state":"ready"'
        }, $true))
        $tlsCall.Count | Should -Be 1
        $contractCheck.Count | Should -Be 1
        $ready.Count | Should -Be 1
        $tlsCall[0].Extent.StartOffset | Should -BeGreaterThan $contractCheck[0].Extent.StartOffset
        $tlsCall[0].Extent.StartOffset | Should -BeLessThan $ready[0].Extent.StartOffset
    }
}

Describe 'Reporter-IPC-Empfaenger: Ueberlaenge wird protokolliert (SD-11)' {
    It 'nutzt eine benannte Konstante mit der Grenze 16384 statt eines Literals' {
        $fn = Get-NamedFunctionBody -Path $script:HostFile -Name 'Start-VsPackageReportPipeWorker'
        $assigned = @($fn.Body.FindAll({ param($n)
            $n -is [System.Management.Automation.Language.AssignmentStatementAst] -and
            $n.Left.Extent.Text -eq '$ipcMaxBytes' -and $n.Right.Extent.Text -eq '16384'
        }, $true))
        $assigned.Count | Should -Be 1 -Because 'die Grenze steht genau einmal als benannte Variable der Funktion'
        $guard = @($fn.Body.FindAll({ param($n)
            $n -is [System.Management.Automation.Language.IfStatementAst] -and $n.Clauses[0].Item1.Extent.Text -match '\$ipcMaxBytes'
        }, $true))
        $guard.Count | Should -Be 1
        $literals = @($guard[0].Clauses[0].Item1.FindAll({ param($n)
            $n -is [System.Management.Automation.Language.ConstantExpressionAst] -and
            ($n.Value -is [int] -or $n.Value -is [long] -or $n.Value -is [double])
        }, $true))
        $literals.Count | Should -Be 0 -Because 'die Bedingung darf nur die benannte Konstante nennen'
    }

    It 'schreibt vor dem Abbruch genau eine WARN-Zeile, ohne die Zeile selbst zu protokollieren' {
        $fn = Get-NamedFunctionBody -Path $script:HostFile -Name 'Start-VsPackageReportPipeWorker'
        $guard = @($fn.Body.FindAll({ param($n)
            $n -is [System.Management.Automation.Language.IfStatementAst] -and $n.Clauses[0].Item1.Extent.Text -match '\$ipcMaxBytes'
        }, $true))[0]
        $logCalls = @($guard.FindAll({ param($n)
            $n -is [System.Management.Automation.Language.CommandAst] -and $n.GetCommandName() -eq 'Write-VsClientLog'
        }, $true))
        $logCalls.Count | Should -Be 1
        $logCalls[0].Extent.Text | Should -Match '-Level WARN'
        $logCalls[0].Extent.Text | Should -Not -Match '\$line'
        $breaks = @($guard.FindAll({ param($n) $n -is [System.Management.Automation.Language.BreakStatementAst] }, $true))
        $breaks.Count | Should -Be 1
        $breaks[0].Extent.StartOffset | Should -BeGreaterThan $logCalls[0].Extent.StartOffset
    }
}
