# Isolierter Kontrollflusstest fuer den Clientinstaller. Die Fixture startet den
# echten Installerrumpf in einem Kindprozess; die dot-gesourcten
# Module werden durch Stubs ersetzt. Dadurch kann der Test jeden Manifestfall
# bis zur ersten CM-Schreibgrenze ausfuehren, ohne MECM, Registry oder Share zu
# veraendern. Der Administrator-#Requires wird nur in der Fixturekopie entfernt,
# weil alle privilegierten Befehle dort protokollierende Stubs sind.

BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $script:Installer = Join-Path (Join-Path $script:RepoRoot 'Powershell-MECM') 'install-VirtuSphere-Clients.ps1'
    $script:PowerShellHost = (Get-Process -Id $PID).Path

    function Invoke-VsClientInstallerFixture {
        param(
            [Parameter(Mandatory)][ValidateSet('match', 'drift', 'inconclusive')][string]$ManifestMode,
            [string]$FailureFolder = '',
            [string]$SourceDirOverride = $null
        )

        $tempRoot = [IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd([IO.Path]::DirectorySeparatorChar, [IO.Path]::AltDirectorySeparatorChar)
        $fixtureRoot = Join-Path $tempRoot ('vs-client-installer-' + [guid]::NewGuid().ToString('N'))
        $mecmRoot = Join-Path $fixtureRoot 'mecm'
        $packagesRoot = Join-Path $fixtureRoot 'packages'
        $contentRoot = Join-Path $fixtureRoot 'published'
        $callLog = Join-Path $fixtureRoot 'calls.log'
        New-Item -ItemType Directory -Path $mecmRoot, $packagesRoot, $contentRoot -Force | Out-Null

        try {
            $installerText = Get-Content -LiteralPath $script:Installer -Raw
            $installerText = $installerText -replace '(?m)^#Requires -RunAsAdministrator\r?\n', ''
            Set-Content -LiteralPath (Join-Path $fixtureRoot 'install-VirtuSphere-Clients.ps1') -Value $installerText -Encoding UTF8

            @'
function Add-VsFixtureCall {
    param([string]$Value)
    Add-Content -LiteralPath $env:VS_CLIENT_INSTALLER_CALL_LOG -Value $Value -Encoding UTF8
}
function Get-VsConfig { [pscustomobject]@{ LogRoot = $null } }
function Initialize-VsLog { param($Component, $LogRoot) }
function Write-VsLog { param($Level, $Context, $Message, $Color) Write-Host $Message }
function Convert-VsWebApi { param($WebApi) $WebApi }
function Get-VsLoggingContractVersion { 1 }
function Get-VsDangerousFileSystemAclEntries { param($Acl) @() }
# pwsh on Linux has no Get-Acl; the ACL verdict above is stubbed either way.
if (-not (Get-Command Get-Acl -ErrorAction SilentlyContinue)) { function Get-Acl { param($Path, $LiteralPath, $ErrorAction) } }
function Get-VsErrorDetail { param($ErrorRecord) [string]$ErrorRecord.Exception.Message }
function Initialize-VsCmSite {
    param($Config)
    Add-VsFixtureCall 'CM_READ Initialize-VsCmSite'
    'TST'
}
function Get-CMFolder {
    param($FolderPath, $ErrorAction)
    Add-VsFixtureCall 'CM_READ Get-CMFolder'
    $null
}
function New-CMFolder {
    param($Name, $ParentFolderPath, $ErrorAction)
    Add-VsFixtureCall 'CM_WRITE New-CMFolder'
    throw 'fixture-stop-after-first-cm-write'
}
'@ | Set-Content -LiteralPath (Join-Path $mecmRoot 'VirtuSphere-Common.ps1') -Encoding UTF8

            @'
function Get-VsClientAppSpecs {
    @(
        [pscustomobject]@{ AppName = 'client_getInfos'; Folder = 'client_getInfos'; Script = 'client_getInfos.ps1'; DependsOn = $null }
        [pscustomobject]@{ AppName = 'client_hostname'; Folder = 'client_hostname'; Script = 'client_hostname.ps1'; DependsOn = 'client_getInfos' }
        [pscustomobject]@{ AppName = 'client_VMDisksOnline'; Folder = 'client_VMDisksOnline'; Script = 'client_VMDisksOnline.ps1'; DependsOn = 'client_hostname' }
        [pscustomobject]@{ AppName = 'client_staticip'; Folder = 'client_staticip'; Script = 'client_staticip.ps1'; DependsOn = 'client_VMDisksOnline' }
    )
}
function Import-VsClientPackagingConfig {
    [pscustomobject]@{
        ContentShare = $env:VS_CLIENT_INSTALLER_CONTENT_ROOT
        PackagesBase = $env:VS_CLIENT_INSTALLER_PACKAGES_ROOT
        DpGroupName = ''
        WebApi = 'fixture.test:8021'
        Scheme = 'http'
        CertThumbprint = ''
    }
}
function Assert-VsClientAppSpecGraph { param($Specs) }
function Get-VsClientLoggingPackageVersion { param($SourceDir) 1 }
function Copy-VsClientContent {
    param($Spec, $SourceDir, $PackagesBase, $Bootstrap)
    Add-VsFixtureCall ('SOURCE ' + [string]$SourceDir)
    $destination = Join-Path $PackagesBase $Spec.Folder
    New-Item -ItemType Directory -Path $destination -Force | Out-Null
    $destination
}
function Compare-VsClientContentManifest {
    param($StagedPath, $PublishedPath)
    $folder = Split-Path $PublishedPath -Leaf
    Add-VsFixtureCall ('MANIFEST ' + $folder)
    if ($env:VS_CLIENT_INSTALLER_MANIFEST_MODE -eq 'drift' -and $folder -eq $env:VS_CLIENT_INSTALLER_FAILURE_FOLDER) {
        return @('content:' + $folder + '.ps1')
    }
    if ($env:VS_CLIENT_INSTALLER_MANIFEST_MODE -eq 'inconclusive' -and $folder -eq $env:VS_CLIENT_INSTALLER_FAILURE_FOLDER) {
        throw 'fixture-share-unreadable'
    }
    return @()
}
'@ | Set-Content -LiteralPath (Join-Path $mecmRoot 'VirtuSphere-ClientPackaging.ps1') -Encoding UTF8

            @'
function Get-VsClientPreflightReport {
    param($Config, $Specs, $ClientSourceDir, $SiteCode)
    Add-VsFixtureCall 'PREFLIGHT'
    [pscustomobject]@{ CanApply = $true; Findings = @(); PlanId = 'fixture' }
}
'@ | Set-Content -LiteralPath (Join-Path $mecmRoot 'VirtuSphere-ClientPreflight.ps1') -Encoding UTF8

            $previousLog = $env:VS_CLIENT_INSTALLER_CALL_LOG
            $previousMode = $env:VS_CLIENT_INSTALLER_MANIFEST_MODE
            $previousFailureFolder = $env:VS_CLIENT_INSTALLER_FAILURE_FOLDER
            $previousContentRoot = $env:VS_CLIENT_INSTALLER_CONTENT_ROOT
            $previousPackagesRoot = $env:VS_CLIENT_INSTALLER_PACKAGES_ROOT
            $env:VS_CLIENT_INSTALLER_CALL_LOG = $callLog
            $env:VS_CLIENT_INSTALLER_MANIFEST_MODE = $ManifestMode
            $env:VS_CLIENT_INSTALLER_FAILURE_FOLDER = $FailureFolder
            $env:VS_CLIENT_INSTALLER_CONTENT_ROOT = $contentRoot
            $env:VS_CLIENT_INSTALLER_PACKAGES_ROOT = $packagesRoot
            $previousErrorAction = $ErrorActionPreference
            try {
                # Windows PowerShell 5.1 exposes native stderr as ErrorRecord.
                # Capture the expected nonzero child result before asserting it.
                $ErrorActionPreference = 'Continue'
                $childArguments = @(
                    '-NoProfile', '-NonInteractive',
                    '-File', (Join-Path $fixtureRoot 'install-VirtuSphere-Clients.ps1')
                )
                if ($PSBoundParameters.ContainsKey('SourceDirOverride')) {
                    $childArguments += @('-SourceDir', $SourceDirOverride)
                }
                $output = @(& $script:PowerShellHost @childArguments 2>&1 | ForEach-Object { [string]$_ })
                $exitCode = $LASTEXITCODE
            } finally {
                $ErrorActionPreference = $previousErrorAction
                $env:VS_CLIENT_INSTALLER_CALL_LOG = $previousLog
                $env:VS_CLIENT_INSTALLER_MANIFEST_MODE = $previousMode
                $env:VS_CLIENT_INSTALLER_FAILURE_FOLDER = $previousFailureFolder
                $env:VS_CLIENT_INSTALLER_CONTENT_ROOT = $previousContentRoot
                $env:VS_CLIENT_INSTALLER_PACKAGES_ROOT = $previousPackagesRoot
            }

            $calls = if (Test-Path -LiteralPath $callLog) { @(Get-Content -LiteralPath $callLog) } else { @() }
            [pscustomobject]@{
                ExitCode = $exitCode
                Calls = $calls
                Output = $output
                DefaultSourceDir = (Join-Path $fixtureRoot 'clients')
            }
        } finally {
            $resolvedFixtureRoot = [IO.Path]::GetFullPath($fixtureRoot)
            $expectedPrefix = $tempRoot + [IO.Path]::DirectorySeparatorChar
            $isExpectedFixture = $resolvedFixtureRoot.StartsWith($expectedPrefix, [StringComparison]::OrdinalIgnoreCase) -and
                (Split-Path $resolvedFixtureRoot -Leaf).StartsWith('vs-client-installer-', [StringComparison]::Ordinal)
            if ($isExpectedFixture -and (Test-Path -LiteralPath $resolvedFixtureRoot)) {
                Remove-Item -LiteralPath $resolvedFixtureRoot -Recurse -Force -ErrorAction SilentlyContinue
            }
        }
    }
}

Describe 'Clientinstaller: Manifestabnahme vor MECM' {
    It 'beendet Manifestdrift in <folder> nach allen vier Vergleichen ohne CM-Write' -ForEach @(
        @{ folder = 'client_getInfos' }
        @{ folder = 'client_hostname' }
        @{ folder = 'client_staticip' }
        @{ folder = 'client_VMDisksOnline' }
    ) {
        $result = Invoke-VsClientInstallerFixture -ManifestMode drift -FailureFolder $folder

        $result.ExitCode | Should -Not -Be 0
        @($result.Calls | Where-Object { $_ -like 'MANIFEST *' }).Count | Should -Be 4 -Because ($result.Output -join "`n")
        @($result.Calls | Where-Object { $_ -like 'CM_WRITE *' }).Count | Should -Be 0
        ($result.Output -join "`n") | Should -Match 'ContentShare-Manifestpruefung fehlgeschlagen'
    }

    It 'beendet eine nicht entscheidbare Pruefung in <folder> nach allen vier Vergleichen ohne CM-Write' -ForEach @(
        @{ folder = 'client_getInfos' }
        @{ folder = 'client_hostname' }
        @{ folder = 'client_staticip' }
        @{ folder = 'client_VMDisksOnline' }
    ) {
        $result = Invoke-VsClientInstallerFixture -ManifestMode inconclusive -FailureFolder $folder

        $result.ExitCode | Should -Not -Be 0
        @($result.Calls | Where-Object { $_ -like 'MANIFEST *' }).Count | Should -Be 4 -Because ($result.Output -join "`n")
        @($result.Calls | Where-Object { $_ -like 'CM_WRITE *' }).Count | Should -Be 0
        ($result.Output -join "`n") | Should -Match 'ContentShare-Manifestpruefung fehlgeschlagen'
    }

    It 'laesst vier passende Manifeste bis zur ersten CM-Mutation passieren' {
        $result = Invoke-VsClientInstallerFixture -ManifestMode match

        @($result.Calls | Where-Object { $_ -like 'MANIFEST *' }).Count | Should -Be 4 -Because ($result.Output -join "`n")
        @($result.Calls | Where-Object { $_ -eq ('SOURCE ' + $result.DefaultSourceDir) }).Count | Should -Be 4
        $result.Calls | Should -Contain 'CM_READ Initialize-VsCmSite'
        $result.Calls | Should -Contain 'CM_WRITE New-CMFolder'
        ($result.Output -join "`n") | Should -Match 'fixture-stop-after-first-cm-write'
    }

    It 'weist einen SourceDir-Einzeloverride vor jedem Content-Write ab' {
        $explicitSourceDir = Join-Path ([IO.Path]::GetTempPath()) 'vs-explicit-client-source'
        $result = Invoke-VsClientInstallerFixture -ManifestMode match -SourceDirOverride $explicitSourceDir

        $result.ExitCode | Should -Not -Be 0
        @($result.Calls).Count | Should -Be 0
        ($result.Output -join "`n") | Should -Match 'SourceDir'
    }
}
