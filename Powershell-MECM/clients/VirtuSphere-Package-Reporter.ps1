#Requires -Version 5.1
# Paket-Reporteradapter (ADR-0044). Diese Datei ist eine Bibliothek: sie startet
# keinen Prozess, oeffnet keine Logsenke und sendet beim Dot-Sourcing nichts.
# Der Wrapper und der beaufsichtigte Host aus T4 bleiben die einzigen Aufrufer.
Set-StrictMode -Version 1.0

$script:VsPackageReporterContractVersion = 1
$script:VsPackageReportSchemaVersion = 1
$script:VsPackageReporterExpectedCommonContractVersion = 1
$script:VsPackageReporterExpectedLoggingContractVersion = 1

function Get-VsPackageReporterContractVersion {
    return $script:VsPackageReporterContractVersion
}

function Get-VsPackageReportSchemaVersion {
    return $script:VsPackageReportSchemaVersion
}

function Test-VsPackageReporterDependencies {
    param(
        [Parameter(Mandatory)][int]$CommonContractVersion,
        [Parameter(Mandatory)][int]$LoggingContractVersion
    )
    return ($CommonContractVersion -eq $script:VsPackageReporterExpectedCommonContractVersion -and
        $LoggingContractVersion -eq $script:VsPackageReporterExpectedLoggingContractVersion)
}
