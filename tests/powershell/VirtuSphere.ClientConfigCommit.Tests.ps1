BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    . (Join-Path $script:RepoRoot 'Powershell-MECM/clients/VirtuSphere-Client-Common.ps1')
}

# Jeder Fall schreibt den nativen Satz unter HKCU:. Nur auf Windows definiert
# (Registry-Provider), wie in VirtuSphere.ErrorPaths.Tests.ps1; der
# PS-5.1-CI-Job beweist den Block, auf dem Linux-Runner existiert er nicht.
$HasRegistry = Test-Path 'HKCU:\'
if ($HasRegistry) {
    Describe 'MC01 closed client configuration commit' {
        BeforeEach {
            $script:ConfigProbe = 'HKCU:\Software\_vs_config_commit_' + [guid]::NewGuid().ToString('N')
            $script:VsRegistryBase = $script:ConfigProbe
            $script:VsBootstrapApiConfiguration = $null
            $script:VsResolvedApi = $null
            $script:ManifestFile = Join-Path ([IO.Path]::GetTempPath()) ('vs-bootstrap-' + [guid]::NewGuid().ToString('N') + '.json')
            [IO.File]::WriteAllText($script:ManifestFile, '{"Schema":1,"WebAPI":"portal.test:8021","Scheme":"https","CertThumbprint":""}', (New-Object Text.UTF8Encoding($false)))
        }

        AfterEach {
            if (Test-Path -LiteralPath $script:ConfigProbe) { Remove-Item -LiteralPath $script:ConfigProbe -Recurse -Force -ErrorAction SilentlyContinue }
            if (Test-Path -LiteralPath $script:ManifestFile) { Remove-Item -LiteralPath $script:ManifestFile -Force -ErrorAction SilentlyContinue }
        }

        It 'prepares one validated source without claiming SetupState or reporter readiness' {
            Initialize-VsClientBootstrap -ManifestPath $script:ManifestFile
            $raw = Get-ItemProperty -Path $script:ConfigProbe
            $raw.WebAPI | Should -Be 'portal.test:8021'
            $raw.Scheme | Should -Be 'https'
            $raw.ConfigSchemaVersion | Should -Be 1
            $raw.ConfigHash | Should -Match '^[0-9a-f]{64}$'
            $raw.PSObject.Properties['SetupState'] | Should -BeNullOrEmpty
            (Get-VsPreparedClientApiConfiguration).Api | Should -Be 'portal.test:8021'
            @(Get-VsApiCandidates) | Should -Be @('portal.test:8021')
            Get-VsPackageReportApiConfiguration | Should -BeNullOrEmpty
        }

        It 'reuses a prepared but unpublished set when getInfos is retried' {
            # First run committed the config, then failed at WebAPI, MAC or ACK.
            Initialize-VsClientBootstrap -ManifestPath $script:ManifestFile
            $committedAt = (Get-ItemProperty -Path $script:ConfigProbe).ConfigCommittedAtUtc
            $script:VsBootstrapApiConfiguration = $null

            { Initialize-VsClientBootstrap -ManifestPath $script:ManifestFile } | Should -Not -Throw
            $script:VsBootstrapApiConfiguration.Api | Should -Be 'portal.test:8021'
            (Get-ItemProperty -Path $script:ConfigProbe).ConfigCommittedAtUtc | Should -Be $committedAt
            Get-VsPackageReportApiConfiguration | Should -BeNullOrEmpty
        }

        It 'reports drift instead of overwriting a prepared set from another bootstrap' {
            Initialize-VsClientBootstrap -ManifestPath $script:ManifestFile
            $script:VsBootstrapApiConfiguration = $null
            [IO.File]::WriteAllText($script:ManifestFile, '{"Schema":1,"WebAPI":"other.test:8021","Scheme":"https","CertThumbprint":""}', (New-Object Text.UTF8Encoding($false)))

            { Initialize-VsClientBootstrap -ManifestPath $script:ManifestFile } | Should -Throw '*configuration_drift*'
            (Get-ItemProperty -Path $script:ConfigProbe).WebAPI | Should -Be 'portal.test:8021'
            $script:VsBootstrapApiConfiguration | Should -BeNullOrEmpty
        }

        It 'refuses partial native values without merging bootstrap or trying a fallback' {
            New-Item -Path $script:ConfigProbe -Force | Out-Null
            New-ItemProperty -Path $script:ConfigProbe -Name WebAPI -Value 'old.test:8021' -PropertyType String -Force | Out-Null
            { Initialize-VsClientBootstrap -ManifestPath $script:ManifestFile } | Should -Throw '*configuration_invalid*'
            (Get-ItemProperty -Path $script:ConfigProbe).WebAPI | Should -Be 'old.test:8021'
            (Get-ItemProperty -Path $script:ConfigProbe).PSObject.Properties['Scheme'] | Should -BeNullOrEmpty
            @(Get-VsApiCandidates).Count | Should -Be 0
        }

        It 'publishes one hash-bound registry and snapshot pair to both API readers' {
            Initialize-VsClientBootstrap -ManifestPath $script:ManifestFile
            $id = [guid]::NewGuid().ToString('N')
            $root = Join-Path (Join-Path $script:ConfigProbe 'Snapshots') $id
            New-Item -Path $root -Force | Out-Null
            New-ItemProperty -Path $root -Name SnapshotSchema -Value 1 -PropertyType DWord -Force | Out-Null
            New-ItemProperty -Path $root -Name SnapshotState -Value 'published' -PropertyType String -Force | Out-Null
            New-ItemProperty -Path $root -Name InterfaceCount -Value 1 -PropertyType DWord -Force | Out-Null
            New-ItemProperty -Path $script:ConfigProbe -Name ActiveSnapshot -Value $id -PropertyType String -Force | Out-Null
            New-ItemProperty -Path $script:ConfigProbe -Name SetupState -Value 'complete' -PropertyType String -Force | Out-Null
            $script:VsBootstrapApiConfiguration = $null
            (Get-VsCommittedClientApiConfiguration).Api | Should -Be 'portal.test:8021'
            (Get-VsPackageReportApiConfiguration).ReportUrl | Should -Be 'https://portal.test:8021/mecm_report.php?action=reportPackageRun'
            @(Get-VsApiCandidates) | Should -Be @('portal.test:8021')

            Set-ItemProperty -Path $script:ConfigProbe -Name Scheme -Value 'http'
            Get-VsCommittedClientApiConfiguration | Should -BeNullOrEmpty
            Get-VsPackageReportApiConfiguration | Should -BeNullOrEmpty
            @(Get-VsApiCandidates).Count | Should -Be 0
        }

        It 'rejects invalid bootstrap even if native values were already present' {
            Initialize-VsClientBootstrap -ManifestPath $script:ManifestFile
            [IO.File]::WriteAllText($script:ManifestFile, '{"Schema":1,"WebAPI":"portal.test:8021","Scheme":"ftp","CertThumbprint":""}', (New-Object Text.UTF8Encoding($false)))
            { Initialize-VsClientBootstrap -ManifestPath $script:ManifestFile } | Should -Throw '*ungueltige*'
            (Get-ItemProperty -Path $script:ConfigProbe).Scheme | Should -Be 'https'
        }

        It 'rejects malformed host, pin and HTTP-with-pin before any registry write' {
            foreach ($candidate in @(
                @{ Api = 'https://portal.test'; Scheme = 'https'; Pin = '' },
                @{ Api = 'portal.test:8021/path'; Scheme = 'https'; Pin = '' },
                @{ Api = "portal.test:8021`n"; Scheme = 'https'; Pin = '' },
                @{ Api = 'portal.test:0'; Scheme = 'http'; Pin = '' },
                @{ Api = 'portal.test:65536'; Scheme = 'http'; Pin = '' },
                @{ Api = 'portal.test:99999999999999999999'; Scheme = 'http'; Pin = '' },
                @{ Api = 'portal.test:8021'; Scheme = 'ftp'; Pin = '' },
                @{ Api = 'portal.test:8021'; Scheme = 'http'; Pin = 'A' * 40 },
                @{ Api = 'portal.test:8021'; Scheme = 'https'; Pin = 'bad' }
            )) {
                ConvertTo-VsClientApiConfiguration -Api $candidate.Api -Scheme $candidate.Scheme -CertThumbprint $candidate.Pin | Should -BeNullOrEmpty
            }
            Test-Path -LiteralPath $script:ConfigProbe | Should -BeFalse
        }

        It 'rejects a numeric bootstrap host before creating native values' {
            [IO.File]::WriteAllText($script:ManifestFile, '{"Schema":1,"WebAPI":123,"Scheme":"http","CertThumbprint":""}', (New-Object Text.UTF8Encoding($false)))
            { Initialize-VsClientBootstrap -ManifestPath $script:ManifestFile } | Should -Throw '*Feldtypen*'
            Test-Path -LiteralPath $script:ConfigProbe | Should -BeFalse
        }

        It 'accepts bootstrap schema 2 with its BundleId as provenance without changing the config hash' {
            Initialize-VsClientBootstrap -ManifestPath $script:ManifestFile
            $schemaOneHash = (Get-ItemProperty -Path $script:ConfigProbe).ConfigHash
            $script:VsBootstrapBundleId | Should -BeNullOrEmpty
            $script:VsBootstrapApiConfiguration = $null
            $bundleId = 'a' * 64
            [IO.File]::WriteAllText($script:ManifestFile, ('{{"Schema":2,"WebAPI":"portal.test:8021","Scheme":"https","CertThumbprint":"","BundleId":"{0}"}}' -f $bundleId), (New-Object Text.UTF8Encoding($false)))

            { Initialize-VsClientBootstrap -ManifestPath $script:ManifestFile } | Should -Not -Throw
            $script:VsBootstrapBundleId | Should -Be $bundleId
            (Get-ItemProperty -Path $script:ConfigProbe).ConfigHash | Should -Be $schemaOneHash
            (Get-ItemProperty -Path $script:ConfigProbe).ConfigSchemaVersion | Should -Be 1
        }

        It 'rejects a schema 1 BundleId, a schema 2 without one and a malformed BundleId before any write' {
            foreach ($document in @(
                ('{{"Schema":1,"WebAPI":"portal.test:8021","Scheme":"https","CertThumbprint":"","BundleId":"{0}"}}' -f ('a' * 64)),
                '{"Schema":2,"WebAPI":"portal.test:8021","Scheme":"https","CertThumbprint":""}',
                ('{{"Schema":2,"WebAPI":"portal.test:8021","Scheme":"https","CertThumbprint":"","BundleId":"{0}"}}' -f ('A' * 64)),
                ('{{"Schema":2,"WebAPI":"portal.test:8021","Scheme":"https","CertThumbprint":"","BundleId":"{0}"}}' -f ('a' * 63)),
                ('{{"Schema":2,"WebAPI":"portal.test:8021","Scheme":"https","CertThumbprint":"","BundleId":"{0}","Extra":""}}' -f ('a' * 64)),
                '{"Schema":3,"WebAPI":"portal.test:8021","Scheme":"https","CertThumbprint":""}'
            )) {
                $script:VsBootstrapBundleId = $null
                [IO.File]::WriteAllText($script:ManifestFile, $document, (New-Object Text.UTF8Encoding($false)))
                { Initialize-VsClientBootstrap -ManifestPath $script:ManifestFile } | Should -Throw '*Bootstrap-Schema*' -Because $document
                Test-Path -LiteralPath $script:ConfigProbe | Should -BeFalse
                $script:VsBootstrapBundleId | Should -BeNullOrEmpty
            }
        }

        It 'rejects a non-string registry host even with a recomputed matching hash' {
            Initialize-VsClientBootstrap -ManifestPath $script:ManifestFile
            New-ItemProperty -Path $script:ConfigProbe -Name WebAPI -Value 123 -PropertyType DWord -Force | Out-Null
            $forged = [pscustomobject]@{ Api = '123'; Scheme = 'https'; CertThumbprint = '' }
            Set-ItemProperty -Path $script:ConfigProbe -Name ConfigHash -Value (Get-VsClientConfigHash -Configuration $forged)
            Get-VsPreparedClientApiConfiguration | Should -BeNullOrEmpty
        }
    }
}
