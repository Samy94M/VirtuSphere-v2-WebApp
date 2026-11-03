#Requires -Version 5.1
#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Lesende Standort-Erhebung einer MECM-Site fuer den VirtuSphere-Client-
    Lieferweg (MC02). Aendert nichts.

.DESCRIPTION
    Liest einmal die Feldformen und Bestaende, die die MC02-Restpruefungen
    (RBAC/Scope, Content-Quelle, Referenzen, Deployment-Policy) und MC03
    brauchen: Umgebung und Provider, Klassenschemata, die Client-Anwendungen
    samt Deployment Types, ihre Deployments, Abhaengigkeiten, Sicherheits-
    bereiche, Sammlungen, Content-Verteilung, Konsolenordner, Content-Orte und
    aehnlich benannte Altanwendungen.

    Das Skript schreibt nichts in MECM, WMI, Registry oder an eine Freigabe.
    Einzige Schreibstelle ist das Tageslog. Der Bericht ist ein JSON-Objekt auf
    stdout; der Fortschritt steht als [n/11] in der Konsole.

    Laeuft auf jeder MECM-Site, auch ohne VirtuSphere-Installation: Dann ist der
    Bericht die Ausgangslage (leere Listen, Registryteil 'absent'). Noetig ist
    ein Rechner mit MECM-Konsole und erreichbarem SMS-Provider.

    Exit 0: Bericht vollstaendig ausgegeben (einzelne Abschnitte koennen
    'partial' oder 'failed' sein, siehe status/error je Abschnitt).
    Exit 2: kein Site-Code ermittelbar, nichts abgefragt.
    Exit 1: unerwarteter Fehler ausserhalb der Abschnitte.

.EXAMPLE
    Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass -Force
    .\survey-VirtuSphere-MecmSite.ps1 | Out-File -Encoding utf8 C:\Temp\vs-site-survey.json
    In derselben Sitzung ausfuehren, nicht ueber ein zweites powershell.exe -File:
    ein eigener Prozess schreibt Fortschritt und Warnungen auf seine
    Standardausgabe, und sie landen vor und nach dem JSON in der Datei.
#>
[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
# Version 1.0, nicht Latest: siehe die Begruendung in mecm\VirtuSphere-Common.ps1.
Set-StrictMode -Version 1.0

. (Join-Path $PSScriptRoot 'mecm\VirtuSphere-Common.ps1')
. (Join-Path $PSScriptRoot 'mecm\VirtuSphere-ClientPackaging.ps1')
. (Join-Path $PSScriptRoot 'mecm\VirtuSphere-ClientPreflight.ps1')
. (Join-Path $PSScriptRoot 'mecm\VirtuSphere-LegacyRetirement.ps1')
. (Join-Path $PSScriptRoot 'mecm\VirtuSphere-SiteSurvey.ps1')

$exitCode = 1
try {
    $config = Get-VsConfig
    $logRoot = if ($config) { $config.LogRoot } else { $null }
    Initialize-VsLog -Component 'site-survey' -LogRoot $logRoot
    Write-VsLog -Level INFO -Context 'site-survey' -Message ('Erhebung gestartet (VirtuSphere-Registry: {0})' -f ($null -ne $config))

    $result = Get-VsSiteSurveyResult -Config $config -Specs @(Get-VsClientAppSpecs) `
        -LegacyNames @((Get-VsClientPackagingPolicy).LegacyApplicationNames) `
        -CurrentSids @(Get-VsCurrentSids) -ComputerNames @(Get-VsLocalComputerNames)
    if ($null -ne $result.Report) {
        ConvertTo-Json -InputObject $result.Report -Depth 16 | Write-Output
        $states = @(foreach ($name in @($result.Report.sections.Keys)) { '{0}={1}' -f $name, $result.Report.sections[$name].status })
        Write-VsLog -Level INFO -Context 'site-survey' -Message ('Erhebung beendet, Site {0}: {1}' -f $result.Report.site.siteCode, ($states -join ', '))
    } else {
        Write-VsLog -Level ERROR -Context 'site-survey' -Message 'Kein Site-Code ermittelbar; nichts abgefragt.'
    }
    $exitCode = [int]$result.ExitCode
} catch {
    $message = [string]$_.Exception.Message
    Write-Host ('Erhebung abgebrochen: {0}' -f $message)
    try { Write-VsLog -Level ERROR -Context 'site-survey' -Message ('Erhebung abgebrochen: {0}' -f $message) } catch { Write-Debug $_ }
    $exitCode = 1
}
exit $exitCode
