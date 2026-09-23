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

# Build one closed V1 started event from the run's frozen identity. Invalid
# metadata disables reporting for this event; it never changes installation.
function New-VsPackageReportStartedRequest {
    param(
        [Parameter(Mandatory)][string]$RunId,
        [Parameter(Mandatory)][object]$Snapshot,
        [Parameter(Mandatory)][string]$ProjectName,
        [Parameter(Mandatory)][string]$PackageVersion,
        [Parameter(Mandatory)][string]$ClientStartedAt,
        [Parameter(Mandatory)][string]$EventAt,
        [Parameter(Mandatory)][string]$Context,
        [AllowNull()][object]$Total = $null
    )

    $uuidPattern = '\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z'
    $timestampPattern = '\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,7})?Z\z'
    try {
        if ($RunId -cnotmatch $uuidPattern -or
            [string]$Snapshot.DeviceGeneration -cnotmatch $uuidPattern -or
            [string]$Snapshot.AcceptanceGeneration -cnotmatch $uuidPattern -or
            $Snapshot.RolloutRevision -isnot [int] -or $Snapshot.RolloutRevision -le 0) { return $null }

        $macs = @($Snapshot.MacCandidates)
        if ($macs.Count -lt 1 -or $macs.Count -gt 16) { return $null }
        $seen = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::Ordinal)
        foreach ($mac in $macs) {
            if ($mac -isnot [string] -or $mac -cnotmatch '\A[0-9A-F]{2}(?::[0-9A-F]{2}){5}\z' -or
                -not $seen.Add($mac)) { return $null }
        }
        [Array]::Sort($macs, [StringComparer]::Ordinal)

        foreach ($value in @($ProjectName, $PackageVersion)) {
            if ($value.Length -eq 0 -or $value.Length -gt 255 -or $value -cne $value.Trim() -or
                $value -match '[\x00-\x1F\x7F]') { return $null }
        }
        foreach ($timestamp in @($ClientStartedAt, $EventAt)) {
            if ($timestamp -cnotmatch $timestampPattern) { return $null }
            $parsed = [DateTimeOffset]::MinValue
            $format = if ($timestamp.Contains('.')) { 'yyyy-MM-ddTHH:mm:ss.FFFFFFFZ' } else { 'yyyy-MM-ddTHH:mm:ssZ' }
            if (-not [DateTimeOffset]::TryParseExact($timestamp, $format,
                    [Globalization.CultureInfo]::InvariantCulture,
                    [Globalization.DateTimeStyles]::AssumeUniversal, [ref]$parsed)) { return $null }
        }
        if ($Context -cne 'system' -and $Context -cne 'user') { return $null }
        if ($null -ne $Total -and (($Total -isnot [int] -and $Total -isnot [long]) -or $Total -lt 0)) { return $null }

        $body = [ordered]@{
            schema_version = $script:VsPackageReportSchemaVersion
            run_id = $RunId
            event = 'started'
            event_seq = 1
            mac_candidates = $macs
            rollout_revision = $Snapshot.RolloutRevision
            device_generation = $Snapshot.DeviceGeneration
            acceptance_generation = $Snapshot.AcceptanceGeneration
            project_name = $ProjectName
            package_version = $PackageVersion
            client_started_at = $ClientStartedAt
            event_at = $EventAt
            context = $Context
            total = $Total
        }
        $bytes = [Text.Encoding]::UTF8.GetBytes((ConvertTo-Json -InputObject $body -Compress -Depth 4))
        if ($bytes.Length -gt 65536) { return $null }
        return [pscustomobject]@{ BodyBytes = $bytes; RunId = $RunId; ReportEvent = 'started'; EventSeq = 1 }
    } catch {
        Write-Debug $_
        return $null
    }
}

# One result per processed wrapper step. The caller owns the stable sequence
# number and first-failure decision; a server detail-limit refusal is not an
# installation failure and does not suppress a later completion report.
function New-VsPackageReportStepRequest {
    param(
        [Parameter(Mandatory)][string]$RunId,
        [Parameter(Mandatory)][object]$Snapshot,
        [Parameter(Mandatory)][string]$ProjectName,
        [Parameter(Mandatory)][string]$PackageVersion,
        [Parameter(Mandatory)][string]$ClientStartedAt,
        [Parameter(Mandatory)][string]$EventAt,
        [Parameter(Mandatory)][string]$Context,
        [AllowNull()][object]$Total = $null,
        [Parameter(Mandatory)][object]$EventSeq,
        [Parameter(Mandatory)][object]$StepIndex,
        [Parameter(Mandatory)][string]$ScriptName,
        [Parameter(Mandatory)][string]$Result,
        [Parameter(Mandatory)][object]$IsFirstFailure,
        [AllowNull()][object]$ErrorCategory = $null,
        [AllowNull()][object]$ChildExitCode = $null,
        [AllowNull()][object]$DurationMs = $null,
        [AllowNull()][object]$DetailPath = $null
    )

    try {
        if (($EventSeq -isnot [int] -and $EventSeq -isnot [long]) -or $EventSeq -le 0 -or
            ($StepIndex -isnot [int] -and $StepIndex -isnot [long]) -or $StepIndex -le 0 -or
            ($null -ne $Total -and $StepIndex -gt $Total) -or
            $ScriptName.Length -eq 0 -or $ScriptName.Length -gt 255 -or
            $ScriptName -cne $ScriptName.Trim() -or $ScriptName -match '[\x00-\x1F\x7F]' -or
            ($Result -cne 'ok' -and $Result -cne 'skip' -and $Result -cne 'fail') -or
            $IsFirstFailure -isnot [bool] -or ($IsFirstFailure -and $Result -cne 'fail')) { return $null }

        foreach ($detail in @(@($ErrorCategory, 255), @($DetailPath, 1024))) {
            $value = $detail[0]
            if ($null -ne $value -and ($value -isnot [string] -or $value.Length -eq 0 -or
                    $value.Length -gt $detail[1] -or $value -cne $value.Trim() -or
                    $value -match '[\x00-\x1F\x7F]')) { return $null }
        }
        if ($null -ne $ChildExitCode -and
            (($ChildExitCode -isnot [int] -and $ChildExitCode -isnot [long]) -or
                $ChildExitCode -lt [int]::MinValue -or $ChildExitCode -gt [int]::MaxValue)) { return $null }
        if ($null -ne $DurationMs -and
            (($DurationMs -isnot [int] -and $DurationMs -isnot [long]) -or $DurationMs -lt 0)) { return $null }

        $started = New-VsPackageReportStartedRequest -RunId $RunId -Snapshot $Snapshot `
            -ProjectName $ProjectName -PackageVersion $PackageVersion -ClientStartedAt $ClientStartedAt `
            -EventAt $EventAt -Context $Context -Total $Total
        if ($null -eq $started) { return $null }
        $body = ConvertFrom-Json -InputObject ([Text.Encoding]::UTF8.GetString($started.BodyBytes)) -ErrorAction Stop
        $body.event = 'step_result'
        $body.event_seq = $EventSeq
        $stepFields = [ordered]@{
                step_index = $StepIndex
                script_name = $ScriptName
                result = $Result
                is_first_failure = $IsFirstFailure
                error_category = $ErrorCategory
                child_exit_code = $ChildExitCode
                duration_ms = $DurationMs
                detail_path = $DetailPath
            }
        foreach ($field in $stepFields.GetEnumerator()) {
            $body | Add-Member -MemberType NoteProperty -Name $field.Key -Value $field.Value -ErrorAction Stop
        }
        $bytes = [Text.Encoding]::UTF8.GetBytes((ConvertTo-Json -InputObject $body -Compress -Depth 4))
        if ($bytes.Length -gt 65536) { return $null }
        return [pscustomobject]@{ BodyBytes = $bytes; RunId = $RunId; ReportEvent = 'step_result'; EventSeq = $EventSeq }
    } catch {
        Write-Debug $_
        return $null
    }
}

# Completion carries the wrapper's decided outcome, not a second calculation
# from whichever step messages happened to reach the server. The first failure
# is the reserved compact projection, including when its step report was lost.
function New-VsPackageReportCompletedRequest {
    param(
        [Parameter(Mandatory)][string]$RunId,
        [Parameter(Mandatory)][object]$Snapshot,
        [Parameter(Mandatory)][string]$ProjectName,
        [Parameter(Mandatory)][string]$PackageVersion,
        [Parameter(Mandatory)][string]$ClientStartedAt,
        [Parameter(Mandatory)][string]$EventAt,
        [Parameter(Mandatory)][string]$Context,
        [AllowNull()][object]$Total = $null,
        [Parameter(Mandatory)][object]$EventSeq,
        [Parameter(Mandatory)][string]$WrapperResult,
        [AllowNull()][object]$WrapperExitCode = $null,
        [Parameter(Mandatory)][string]$DetectionResult,
        [Parameter(Mandatory)][object]$ProcessedCount,
        [Parameter(Mandatory)][object]$OkCount,
        [Parameter(Mandatory)][object]$SkipCount,
        [Parameter(Mandatory)][object]$FailCount,
        [AllowNull()][object]$LastProcessedIndex = $null,
        [AllowNull()][object]$FirstFailure = $null,
        [Parameter(Mandatory)][object]$PayloadOmittedCount,
        [AllowNull()][object]$WrapperLogPath = $null,
        [AllowNull()][object]$ReportingLogPath = $null
    )

    try {
        if (($EventSeq -isnot [int] -and $EventSeq -isnot [long]) -or $EventSeq -le 0 -or
            @('ok', 'failed', 'reboot_required', 'reboot_initiated') -cnotcontains $WrapperResult -or
            @('written', 'failed', 'not_attempted') -cnotcontains $DetectionResult) { return $null }
        foreach ($count in @($ProcessedCount, $OkCount, $SkipCount, $FailCount, $PayloadOmittedCount)) {
            if (($count -isnot [int] -and $count -isnot [long]) -or $count -lt 0) { return $null }
        }
        if ([decimal]$ProcessedCount -ne ([decimal]$OkCount + [decimal]$SkipCount + [decimal]$FailCount) -or
            ($null -ne $Total -and $ProcessedCount -gt $Total) -or
            (($ProcessedCount -eq 0) -ne ($null -eq $LastProcessedIndex)) -or
            ($null -ne $LastProcessedIndex -and
                (($LastProcessedIndex -isnot [int] -and $LastProcessedIndex -isnot [long]) -or
                    $LastProcessedIndex -le 0 -or ($null -ne $Total -and $LastProcessedIndex -gt $Total))) -or
            (($FailCount -eq 0) -ne ($null -eq $FirstFailure))) { return $null }
        if ($null -ne $WrapperExitCode -and
            (($WrapperExitCode -isnot [int] -and $WrapperExitCode -isnot [long]) -or
                $WrapperExitCode -lt [int]::MinValue -or $WrapperExitCode -gt [int]::MaxValue)) { return $null }
        foreach ($path in @($WrapperLogPath, $ReportingLogPath)) {
            if ($null -ne $path -and ($path -isnot [string] -or $path.Length -eq 0 -or
                    $path.Length -gt 1024 -or $path -cne $path.Trim() -or
                    $path -match '[\x00-\x1F\x7F]')) { return $null }
        }

        $failureBody = $null
        if ($null -ne $FirstFailure) {
            if ($FirstFailure -isnot [pscustomobject]) { return $null }
            $allowed = @('step_index', 'script_name', 'error_category', 'child_exit_code', 'detail_path')
            foreach ($property in $FirstFailure.PSObject.Properties) {
                if ($allowed -cnotcontains $property.Name) { return $null }
            }
            if (@($FirstFailure.PSObject.Properties.Name) -cnotcontains 'step_index' -or
                @($FirstFailure.PSObject.Properties.Name) -cnotcontains 'script_name') { return $null }
            $index = $FirstFailure.step_index
            $name = $FirstFailure.script_name
            if (($index -isnot [int] -and $index -isnot [long]) -or $index -le 0 -or
                ($null -ne $Total -and $index -gt $Total) -or
                $name -isnot [string] -or $name.Length -eq 0 -or $name.Length -gt 255 -or
                $name -cne $name.Trim() -or $name -match '[\x00-\x1F\x7F]') { return $null }
            $category = if (@($FirstFailure.PSObject.Properties.Name) -ccontains 'error_category') { $FirstFailure.error_category } else { $null }
            $exit = if (@($FirstFailure.PSObject.Properties.Name) -ccontains 'child_exit_code') { $FirstFailure.child_exit_code } else { $null }
            $path = if (@($FirstFailure.PSObject.Properties.Name) -ccontains 'detail_path') { $FirstFailure.detail_path } else { $null }
            foreach ($textAndMax in @(@($category, 255), @($path, 1024))) {
                $value = $textAndMax[0]
                if ($null -ne $value -and ($value -isnot [string] -or $value.Length -eq 0 -or
                        $value.Length -gt $textAndMax[1] -or $value -cne $value.Trim() -or
                        $value -match '[\x00-\x1F\x7F]')) { return $null }
            }
            if ($null -ne $exit -and (($exit -isnot [int] -and $exit -isnot [long]) -or
                    $exit -lt [int]::MinValue -or $exit -gt [int]::MaxValue)) { return $null }
            $failureBody = [ordered]@{
                step_index = $index
                script_name = $name
                error_category = $category
                child_exit_code = $exit
                detail_path = $path
            }
        }

        $started = New-VsPackageReportStartedRequest -RunId $RunId -Snapshot $Snapshot `
            -ProjectName $ProjectName -PackageVersion $PackageVersion -ClientStartedAt $ClientStartedAt `
            -EventAt $EventAt -Context $Context -Total $Total
        if ($null -eq $started) { return $null }
        $body = ConvertFrom-Json -InputObject ([Text.Encoding]::UTF8.GetString($started.BodyBytes)) -ErrorAction Stop
        $body.event = 'completed'
        $body.event_seq = $EventSeq
        $completionFields = [ordered]@{
            wrapper_result = $WrapperResult
            wrapper_exit_code = $WrapperExitCode
            detection_result = $DetectionResult
            processed_count = $ProcessedCount
            ok_count = $OkCount
            skip_count = $SkipCount
            fail_count = $FailCount
            last_processed_index = $LastProcessedIndex
            first_failure = $failureBody
            payload_omitted_count = $PayloadOmittedCount
            wrapper_log_path = $WrapperLogPath
            reporting_log_path = $ReportingLogPath
        }
        foreach ($field in $completionFields.GetEnumerator()) {
            $body | Add-Member -MemberType NoteProperty -Name $field.Key -Value $field.Value -ErrorAction Stop
        }
        $bytes = [Text.Encoding]::UTF8.GetBytes((ConvertTo-Json -InputObject $body -Compress -Depth 5))
        if ($bytes.Length -gt 65536) { return $null }
        return [pscustomobject]@{ BodyBytes = $bytes; RunId = $RunId; ReportEvent = 'completed'; EventSeq = $EventSeq }
    } catch {
        Write-Debug $_
        return $null
    }
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
