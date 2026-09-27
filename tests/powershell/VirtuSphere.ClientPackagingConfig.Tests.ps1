BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $script:ClientSource = Join-Path (Join-Path $script:RepoRoot 'Powershell-MECM') 'clients'
    . (Join-Path (Join-Path (Join-Path $script:RepoRoot 'Powershell-MECM') 'mecm') 'VirtuSphere-ClientPackaging.ps1')

    function New-TestSiteConfig {
        @{
            SchemaVersion = 1
            PackagesBase = 'D:\VirtuSphere\Base\Packages'
            ContentShare = '\\MECM-01\VirtuSphere\Base\Packages'
            WebApi = 'virtusphere.lan:8021'
            Scheme = 'https'
            CertThumbprint = 'A' * 40
            DpGroupName = 'DP Group - VirtuSphere-Applications'
            CoreLimitingCollectionId = 'PS100012'
            BundleArchivePath = 'D:\VirtuSphere\Base\ClientBundles'
            SourceIdentity = '0123456789abcdef' * 2
        }
    }

    function Invoke-TestAssert {
        param([hashtable]$Config, [string]$SourceDir = $script:ClientSource)
        Assert-VsClientPackagingConfigValues -Config $Config -ClientSourceDir $SourceDir
    }
}

Describe 'MC01 ClientPackaging.psd1 field validation' {
    It 'accepts the documented canonical site values' {
        { Invoke-TestAssert -Config (New-TestSiteConfig) } | Should -Not -Throw
    }

    It 'accepts an empty DP group, an unpinned HTTP API and a sibling archive with a shared prefix' {
        $config = New-TestSiteConfig
        $config.DpGroupName = ''
        $config.Scheme = 'http'
        $config.CertThumbprint = ''
        $config.BundleArchivePath = 'D:\VirtuSphere\Base\Packages2'
        { Invoke-TestAssert -Config $config } | Should -Not -Throw
    }

    It 'rejects <Field> = <Value>' -ForEach @(
        @{ Field = 'WebApi'; Value = 'https://virtusphere.lan:8021'; Reason = 'WebApi/Scheme/CertThumbprint' }
        @{ Field = 'WebApi'; Value = 'virtusphere.lan:65536'; Reason = 'WebApi/Scheme/CertThumbprint' }
        @{ Field = 'Scheme'; Value = 'HTTPS'; Reason = 'WebApi/Scheme/CertThumbprint' }
        @{ Field = 'Scheme'; Value = 'http'; Reason = 'WebApi/Scheme/CertThumbprint' }
        @{ Field = 'CertThumbprint'; Value = 'a' * 40; Reason = 'WebApi/Scheme/CertThumbprint' }
        @{ Field = 'CertThumbprint'; Value = 'AA BB'; Reason = 'WebApi/Scheme/CertThumbprint' }
        @{ Field = 'PackagesBase'; Value = 'Packages'; Reason = 'PackagesBase' }
        @{ Field = 'PackagesBase'; Value = 'D:\VirtuSphere\..\Packages'; Reason = 'PackagesBase' }
        @{ Field = 'PackagesBase'; Value = 'D:\VirtuSphere\Base\Packages\'; Reason = 'PackagesBase' }
        @{ Field = 'PackagesBase'; Value = '\\MECM-01\Packages'; Reason = 'PackagesBase' }
        @{ Field = 'PackagesBase'; Value = 'D:\VirtuSphere.\Base\Packages'; Reason = 'PackagesBase' }
        @{ Field = 'PackagesBase'; Value = 'D:\VirtuSphere \Base\Packages'; Reason = 'PackagesBase' }
        @{ Field = 'BundleArchivePath'; Value = 'D:\VirtuSphere\Base\Packages.\Bundles'; Reason = 'BundleArchivePath' }
        @{ Field = 'ContentShare'; Value = '\\MECM-01\VirtuSphere.\Base'; Reason = 'ContentShare' }
        @{ Field = 'BundleArchivePath'; Value = 'D:\VirtuSphere\Base\Packages\Bundles'; Reason = 'ausserhalb' }
        @{ Field = 'BundleArchivePath'; Value = 'd:\virtusphere\base\packages'; Reason = 'ausserhalb' }
        @{ Field = 'BundleArchivePath'; Value = 'D:\VirtuSphere'; Reason = 'ausserhalb' }
        @{ Field = 'BundleArchivePath'; Value = 'D:\Bundles*'; Reason = 'BundleArchivePath' }
        @{ Field = 'ContentShare'; Value = 'D:\VirtuSphere\Base\Packages'; Reason = 'ContentShare' }
        @{ Field = 'ContentShare'; Value = '\\MECM-01'; Reason = 'ContentShare' }
        @{ Field = 'ContentShare'; Value = '\\MECM-01\VirtuSphere\'; Reason = 'ContentShare' }
        @{ Field = 'ContentShare'; Value = '\\MECM-01\VirtuSphere\..\Other'; Reason = 'ContentShare' }
        @{ Field = 'DpGroupName'; Value = ' DP Group'; Reason = 'DpGroupName' }
        @{ Field = 'DpGroupName'; Value = "DP`tGroup"; Reason = 'DpGroupName' }
        @{ Field = 'CoreLimitingCollectionId'; Value = 'Alle Systeme'; Reason = 'CoreLimitingCollectionId' }
        @{ Field = 'CoreLimitingCollectionId'; Value = 'ps100012'; Reason = 'CoreLimitingCollectionId' }
        @{ Field = 'CoreLimitingCollectionId'; Value = 'PS10001G'; Reason = 'CoreLimitingCollectionId' }
        @{ Field = 'SourceIdentity'; Value = [guid]::NewGuid().ToString(); Reason = 'SourceIdentity' }
        @{ Field = 'SourceIdentity'; Value = ('0123456789ABCDEF' * 2); Reason = 'SourceIdentity' }
    ) {
        $config = New-TestSiteConfig
        $config[$Field] = $Value
        { Invoke-TestAssert -Config $config } | Should -Throw ('*' + $Reason + '*')
    }

    It 'rejects non-string values before evaluating them' {
        $config = New-TestSiteConfig
        $config.CoreLimitingCollectionId = 12345
        { Invoke-TestAssert -Config $config } | Should -Throw '*CoreLimitingCollectionId: muss eine Zeichenkette sein*'
    }

    It 'reports every invalid field in one message' {
        $config = New-TestSiteConfig
        $config.PackagesBase = 'Packages'
        $config.SourceIdentity = 'x'
        $message = $null
        try { Invoke-TestAssert -Config $config } catch { $message = $_.Exception.Message }
        $message | Should -Match 'PackagesBase:'
        $message | Should -Match 'SourceIdentity:'
    }
}

Describe 'MC01 API rule is derived from the shipped client Common' {
    BeforeEach {
        $script:FakeSource = Join-Path ([IO.Path]::GetTempPath()) ('vs-packaging-rule-' + [guid]::NewGuid().ToString('N'))
        New-Item -ItemType Directory -Path $script:FakeSource -Force | Out-Null
    }

    AfterEach {
        Remove-Item -LiteralPath $script:FakeSource -Recurse -Force -ErrorAction SilentlyContinue
    }

    It 'follows the client function instead of a server-side copy' {
        $permissive = 'function ConvertTo-VsClientApiConfiguration { param([string]$Api, [string]$Scheme, [string]$CertThumbprint) [pscustomobject]@{ Api = $Api } }'
        [IO.File]::WriteAllText((Join-Path $script:FakeSource 'VirtuSphere-Client-Common.ps1'), $permissive, (New-Object Text.UTF8Encoding($false)))
        $config = New-TestSiteConfig
        $config.Scheme = 'ftp'
        { Invoke-TestAssert -Config $config -SourceDir $script:FakeSource } | Should -Not -Throw
        { Invoke-TestAssert -Config $config } | Should -Throw '*WebApi/Scheme/CertThumbprint*'
    }

    It 'blocks when the client Common is missing, broken or defines the rule twice' {
        { Get-VsClientApiConfigurationRule -ClientSourceDir $script:FakeSource } | Should -Throw '*Client-Common fehlt*'
        $path = Join-Path $script:FakeSource 'VirtuSphere-Client-Common.ps1'
        [IO.File]::WriteAllText($path, 'function ConvertTo-VsClientApiConfiguration {', (New-Object Text.UTF8Encoding($false)))
        { Get-VsClientApiConfigurationRule -ClientSourceDir $script:FakeSource } | Should -Throw '*syntaktisch ungueltig*'
        $twice = "function ConvertTo-VsClientApiConfiguration { 1 }`nfunction ConvertTo-VsClientApiConfiguration { 2 }"
        [IO.File]::WriteAllText($path, $twice, (New-Object Text.UTF8Encoding($false)))
        { Get-VsClientApiConfigurationRule -ClientSourceDir $script:FakeSource } | Should -Throw '*genau einmal*'
    }
}

Describe 'MC01 Import-VsClientPackagingConfig validates values after ACL and keys' {
    BeforeEach {
        $script:SiteFile = Join-Path ([IO.Path]::GetTempPath()) ('vs-site-' + [guid]::NewGuid().ToString('N') + '.psd1')
        Mock Get-Acl { [pscustomobject]@{ Access = @() } }
    }

    AfterEach {
        Remove-Item -LiteralPath $script:SiteFile -Force -ErrorAction SilentlyContinue
    }

    It 'returns a valid file and refuses the same file with one invalid value' {
        $lines = foreach ($entry in (New-TestSiteConfig).GetEnumerator()) {
            if ($entry.Value -is [int]) { '    {0} = {1}' -f $entry.Key, $entry.Value }
            else { "    {0} = '{1}'" -f $entry.Key, ([string]$entry.Value).Replace("'", "''") }
        }
        $content = "@{`r`n" + ($lines -join "`r`n") + "`r`n}"
        [IO.File]::WriteAllText($script:SiteFile, $content, (New-Object Text.UTF8Encoding($true)))
        (Import-VsClientPackagingConfig -ClientSourceDir $script:ClientSource -LiteralPath $script:SiteFile).CoreLimitingCollectionId | Should -Be 'PS100012'

        [IO.File]::WriteAllText($script:SiteFile, $content.Replace("'PS100012'", "'Alle Systeme'"), (New-Object Text.UTF8Encoding($true)))
        { Import-VsClientPackagingConfig -ClientSourceDir $script:ClientSource -LiteralPath $script:SiteFile } | Should -Throw '*CoreLimitingCollectionId*'
    }
}
