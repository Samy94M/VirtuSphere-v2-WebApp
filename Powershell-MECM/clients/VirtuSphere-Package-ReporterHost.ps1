#Requires -Version 5.1
# Prozesshost-Vertrag des Paket-Reporters (ADR-0044). T3 verteilt diesen Host
# als Teil eines unveraenderlichen Bundles. Prozesskapselung, IPC und Transport
# werden in T4 implementiert und vor Aktivierung im Wrapper gesondert gemessen.
Set-StrictMode -Version 1.0

$script:VsPackageReporterHostContractVersion = 1
$script:VsPackageReporterHostExpectedAdapterContractVersion = 1

function Get-VsPackageReporterHostContractVersion {
    return $script:VsPackageReporterHostContractVersion
}
