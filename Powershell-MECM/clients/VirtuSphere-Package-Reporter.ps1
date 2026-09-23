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

# A 200 status alone is not proof that this exact event reached the writer.
# The supervised host supplies only its bounded response, never an arbitrary
# page body or a response from a different run. This library performs no I/O.
function Resolve-VsPackageReportAcknowledgement {
    param(
        [Parameter(Mandatory)][int]$StatusCode,
        [Parameter(Mandatory)][string]$ContentType,
        [AllowNull()][string]$ResponseJson,
        [Parameter(Mandatory)][string]$RunId,
        [Parameter(Mandatory)][ValidateSet('started', 'step_result', 'completed')][string]$ReportEvent,
        [Parameter(Mandatory)][long]$EventSeq
    )

    $unconfirmed = [pscustomobject]@{ Confirmed = $false; Deduplicated = $false; Reason = 'invalid_ack' }
    if ($StatusCode -ne 200) { $unconfirmed.Reason = 'http_status'; return $unconfirmed }
    if ($ContentType -notmatch '\Aapplication/json(?:\s*;\s*charset\s*=\s*(?:utf-8|"utf-8"))?\s*\z') {
        $unconfirmed.Reason = 'content_type'
        return $unconfirmed
    }
    if ([string]::IsNullOrWhiteSpace($ResponseJson) -or
        [Text.Encoding]::UTF8.GetByteCount($ResponseJson) -gt 4096 -or
        $ResponseJson.Contains('\')) {
        $unconfirmed.Reason = 'body_shape'
        return $unconfirmed
    }

    $keys = @('schema_version', 'run_id', 'event', 'event_seq', 'accepted', 'deduplicated')
    if ([regex]::Matches($ResponseJson, '"[^"\\]+"\s*:').Count -ne $keys.Count) {
        $unconfirmed.Reason = 'body_shape'
        return $unconfirmed
    }
    foreach ($key in $keys) {
        if ([regex]::Matches($ResponseJson, ('"{0}"\s*:' -f $key)).Count -ne 1) {
            $unconfirmed.Reason = 'body_shape'
            return $unconfirmed
        }
    }
    try {
        $body = ConvertFrom-Json -InputObject $ResponseJson -ErrorAction Stop
        if ($body -isnot [pscustomobject]) { return $unconfirmed }
        $properties = @($body.PSObject.Properties)
        if ($properties.Count -ne $keys.Count) { return $unconfirmed }
        foreach ($property in $properties) {
            if ($keys -cnotcontains $property.Name) { return $unconfirmed }
        }
        if ($body.schema_version -isnot [int] -or $body.schema_version -ne $script:VsPackageReportSchemaVersion) { return $unconfirmed }
        if ($body.run_id -isnot [string] -or $body.run_id -cne $RunId) { return $unconfirmed }
        if ($body.event -isnot [string] -or $body.event -cne $ReportEvent) { return $unconfirmed }
        if (($body.event_seq -isnot [long] -and $body.event_seq -isnot [int]) -or [long]$body.event_seq -ne $EventSeq) { return $unconfirmed }
        if ($body.accepted -isnot [bool] -or $body.deduplicated -isnot [bool]) { return $unconfirmed }
        if ([bool]$body.accepted -eq [bool]$body.deduplicated) { return $unconfirmed }
        return [pscustomobject]@{
            Confirmed = $true
            Deduplicated = [bool]$body.deduplicated
            Reason = if ($body.deduplicated) { 'deduplicated' } else { 'accepted' }
        }
    } catch {
        return $unconfirmed
    }
}
