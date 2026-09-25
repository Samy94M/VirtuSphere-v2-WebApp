BeforeAll {
    $script:ClientCommon = Join-Path (Split-Path (Split-Path $PSScriptRoot -Parent) -Parent) 'Powershell-MECM/clients/VirtuSphere-Client-Common.ps1'
    function Read-ReporterApi {
        . $script:ClientCommon
        Get-VsPackageReportApiConfiguration
    }
}

Describe 'T4 reporter reads only the configured API address' {
    BeforeEach {
        $global:VsReporterApiFixture = @{
            Values = [pscustomobject]@{ WebAPI = 'virtusphere.lan:8021'; Scheme = 'https'; CertThumbprint = 'A' * 40 }
            Reads = 0
        }
        Mock Get-ItemProperty {
            if ($Path -ne 'HKLM:\SOFTWARE\VirtuSphere') { throw "unexpected registry path: $Path" }
            $global:VsReporterApiFixture.Reads++
            return $global:VsReporterApiFixture.Values
        }
        Mock Invoke-RestMethod { throw 'reporter configuration must not probe health' }
        Mock New-ItemProperty { throw 'reporter configuration must not write the registry' }
    }

    AfterEach {
        Remove-Variable -Name VsReporterApiFixture -Scope Global -ErrorAction SilentlyContinue
    }

    It 'returns one bounded configuration without health traffic or registry writes' {
        $result = Read-ReporterApi
        $result.Api | Should -Be 'virtusphere.lan:8021'
        $result.Scheme | Should -Be 'https'
        $result.CertThumbprint | Should -Be ('A' * 40)
        $result.ReportUrl | Should -Be 'https://virtusphere.lan:8021/mecm_report.php?action=reportPackageRun'
        $global:VsReporterApiFixture.Reads | Should -Be 1
        Should -Invoke Invoke-RestMethod -Times 0
        Should -Invoke New-ItemProperty -Times 0
    }

    It 'uses the shared HTTP default only when Scheme is absent' {
        $global:VsReporterApiFixture.Values = [pscustomobject]@{ WebAPI = '10.0.0.5:8021' }
        (Read-ReporterApi).ReportUrl | Should -Be 'http://10.0.0.5:8021/mecm_report.php?action=reportPackageRun'
    }

    It 'refuses missing address instead of using DNS or IP fallbacks' {
        $global:VsReporterApiFixture.Values = [pscustomobject]@{ Scheme = 'http' }
        Read-ReporterApi | Should -BeNullOrEmpty
        $global:VsReporterApiFixture.Reads | Should -Be 1
    }

    It 'refuses a URL, path, invalid port or invalid Scheme' {
        foreach ($api in @('https://foreign.example:8021', 'host:8021/path', "host:8021`n", 'host:0', 'host:65536', 'host:99999999999999999999')) {
            $global:VsReporterApiFixture.Values = [pscustomobject]@{ WebAPI = $api; Scheme = 'http' }
            Read-ReporterApi | Should -BeNullOrEmpty
        }
        $global:VsReporterApiFixture.Values = [pscustomobject]@{ WebAPI = 'virtusphere.lan:8021'; Scheme = 'ftp' }
        Read-ReporterApi | Should -BeNullOrEmpty
    }

    It 'refuses malformed certificate pins but accepts an unpinned HTTPS address' {
        $global:VsReporterApiFixture.Values = [pscustomobject]@{ WebAPI = 'virtusphere.lan:8021'; Scheme = 'https'; CertThumbprint = 'bad-pin' }
        Read-ReporterApi | Should -BeNullOrEmpty
        $global:VsReporterApiFixture.Values = [pscustomobject]@{ WebAPI = 'virtusphere.lan:8021'; Scheme = 'https' }
        (Read-ReporterApi).CertThumbprint | Should -Be ''
    }
}
