BeforeAll {
    $script:ClientCommon = Join-Path (Split-Path (Split-Path $PSScriptRoot -Parent) -Parent) 'Powershell-MECM/clients/VirtuSphere-Client-Common.ps1'
    . $script:ClientCommon
    function Read-ReporterApi {
        Get-VsPackageReportApiConfiguration
    }
}

Describe 'T4 reporter uses only the committed client configuration' {
    BeforeEach {
        $global:VsReporterApiFixture = [pscustomobject]@{ Api = 'portal.test:8021'; Scheme = 'https'; CertThumbprint = 'A' * 40 }
        Mock Get-VsCommittedClientApiConfiguration { return $global:VsReporterApiFixture }
        Mock Invoke-RestMethod { throw 'reporter configuration must not probe health' }
        Mock New-ItemProperty { throw 'reporter configuration must not write the registry' }
    }

    AfterEach {
        Remove-Variable -Name VsReporterApiFixture -Scope Global -ErrorAction SilentlyContinue
    }

    It 'builds one bounded URL without health traffic or registry writes' {
        $result = Read-ReporterApi
        $result.Api | Should -Be 'portal.test:8021'
        $result.Scheme | Should -Be 'https'
        $result.CertThumbprint | Should -Be ('A' * 40)
        $result.ReportUrl | Should -Be 'https://portal.test:8021/mecm_report.php?action=reportPackageRun'
        Should -Invoke Get-VsCommittedClientApiConfiguration -Exactly 1
        Should -Invoke Invoke-RestMethod -Times 0
        Should -Invoke New-ItemProperty -Times 0
    }

    It 'returns no reporter target when the shared reader rejects the native commit' {
        $global:VsReporterApiFixture = $null
        Read-ReporterApi | Should -BeNullOrEmpty
        Should -Invoke Invoke-RestMethod -Times 0
        Should -Invoke New-ItemProperty -Times 0
    }
}
