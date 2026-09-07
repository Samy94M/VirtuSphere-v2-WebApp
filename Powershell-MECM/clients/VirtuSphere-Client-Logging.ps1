#Requires -Version 5.1
# Lokale Logging-Domaene der Windows-Clientphasen (Etappe 10D).
# Sie bleibt vom MECM-Serverpaket getrennt, implementiert aber denselben
# versionierten Schema-, Bounds- und Retentionvertrag (ADR-0029).
Set-StrictMode -Version 1.0

$script:VsClientLoggingContractVersion = 1
$script:VsClientLogLevels = @('DEBUG', 'INFO', 'WARN', 'ERROR')
$script:VsClientLogRetentionDays = 30
$script:VsClientLogRetentionCheckHours = 24
$script:VsClientLogComponentMaxChars = 64
$script:VsClientLogContextMaxBytes = 256
$script:VsClientLogMessageMaxBytes = 3072
$script:VsClientLogLineMaxBytes = 4096
$script:VsClientLogSecretKeys = 'access_token|refresh_token|auth_token|api_key|apikey|client_secret|private_key|password|passwd|pwd|secret|token'
$script:VsClientLogSecretHeaders = 'Authorization|Proxy-Authorization|X-VirtuSphere-Token|Auth-Token|Set-Cookie|Cookie|HTTP_AUTHORIZATION|HTTP_PROXY_AUTHORIZATION|HTTP_X_VIRTUSPHERE_TOKEN|HTTP_AUTH_TOKEN|HTTP_COOKIE'

$script:VsClientLogComponent = 'client'
$script:VsClientLogRoot = if ($env:ProgramFiles) {
    Join-Path $env:ProgramFiles 'VirtuSphere\Logs'
} else {
    Join-Path ([System.IO.Path]::GetTempPath()) 'VirtuSphere-Logs'
}
$script:VsClientCorrelationId = ([guid]::NewGuid().ToString('N')).Substring(0, 16).ToLowerInvariant()
$script:VsClientLogRetentionNextCheck = $null
$script:VsClientLogSinkFailed = $false

function Get-VsClientLoggingContractVersion {
    return $script:VsClientLoggingContractVersion
}

function Get-VsClientLogContract {
    [pscustomobject]@{
        Version             = $script:VsClientLoggingContractVersion
        Levels              = @($script:VsClientLogLevels)
        RetentionDays       = $script:VsClientLogRetentionDays
        RetentionCheckHours = $script:VsClientLogRetentionCheckHours
        ComponentMaxChars   = $script:VsClientLogComponentMaxChars
        ContextMaxBytes     = $script:VsClientLogContextMaxBytes
        MessageMaxBytes     = $script:VsClientLogMessageMaxBytes
        LineMaxBytes        = $script:VsClientLogLineMaxBytes
        FileNamePattern     = 'yyyy-MM-dd_<component>.log'
        FieldCount          = 6
    }
}

function Get-VsClientLogUtf8Truncated {
    param(
        [AllowNull()][string]$Text,
        [Parameter(Mandatory)][ValidateRange(1, 1048576)][int]$MaxBytes
    )
    if ($null -eq $Text) { return '' }
    $encoding = [System.Text.Encoding]::UTF8
    if ($encoding.GetByteCount($Text) -le $MaxBytes) { return $Text }

    $suffix = '...'
    if ($MaxBytes -le $suffix.Length) { return $suffix.Substring(0, $MaxBytes) }
    $budget = $MaxBytes - $encoding.GetByteCount($suffix)
    $builder = New-Object System.Text.StringBuilder
    $elements = [System.Globalization.StringInfo]::GetTextElementEnumerator($Text)
    while ($elements.MoveNext()) {
        $element = [string]$elements.GetTextElement()
        if (($encoding.GetByteCount($builder.ToString()) + $encoding.GetByteCount($element)) -gt $budget) { break }
        [void]$builder.Append($element)
    }
    return ($builder.ToString() + $suffix)
}

function Protect-VsClientLogText {
    param([AllowNull()][string]$Text)
    if ($null -eq $Text) { return '' }
    $value = [string]$Text
    $headerPattern = '(?i)\b(' + $script:VsClientLogSecretHeaders + ')("?\s*[:=]\s*"?)([^\r\n,;"'']*)'
    $value = [regex]::Replace($value, $headerPattern, {
        param($match)
        $scheme = ''
        if ($match.Groups[3].Value -match '^([A-Za-z][A-Za-z0-9._-]*)\s+\S') {
            $scheme = $Matches[1] + ' '
        }
        return ($match.Groups[1].Value + $match.Groups[2].Value + $scheme + '[redacted]')
    })

    $keys = $script:VsClientLogSecretKeys
    $patterns = @(
        ('([?&](?:' + $keys + ')=)(?!\[redacted\])[^&#\s"'']*')
        ('((?:' + $keys + ')%3[Dd])(?!\[redacted\])(?:(?!%26|&|\s|["'']).)*')
        ('(["'']?(?:' + $keys + ')["'']?\s*(?:=>|:|=(?!>))\s*)(?!\[redacted\])(?:"[^"]*"|''[^'']*''|[^,;)}\]\s]+)')
    )
    foreach ($pattern in $patterns) {
        $value = [regex]::Replace($value, ('(?i)' + $pattern), '$1[redacted]')
    }
    return $value
}

function Remove-VsClientLogTerminalSequences {
    param([AllowNull()][string]$Text)
    if ($null -eq $Text) { return '' }
    $value = [string]$Text
    $esc = [regex]::Escape([string][char]27)
    $value = [regex]::Replace($value, ($esc + '\][^\x07]*(?:\x07|' + $esc + '\\)'), '')
    $value = [regex]::Replace($value, ($esc + '\][^\x07]*$'), '')
    $value = [regex]::Replace($value, ($esc + '\[[0-?]*[ -/]*[@-~]'), '')
    $value = [regex]::Replace($value, ($esc + '\[[0-?]*[ -/]*$'), '')
    return $value.Replace([string][char]27, '')
}

function ConvertTo-VsClientLogField {
    param(
        [AllowNull()][string]$Text,
        [Parameter(Mandatory)][int]$MaxBytes
    )
    $value = Remove-VsClientLogTerminalSequences -Text $Text
    $value = Protect-VsClientLogText -Text $value
    $value = $value -replace '\r\n?|\n', ' '
    $value = $value -replace '[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]', ' '
    $value = $value.Replace('|', ';').Trim()
    return (Get-VsClientLogUtf8Truncated -Text $value -MaxBytes $MaxBytes)
}

function Get-VsClientLogComponentName {
    param([AllowNull()][string]$Component)
    $value = if ($null -eq $Component) { '' } else { $Component.Trim() }
    $value = $value -replace '[^A-Za-z0-9._-]', '_'
    if ([string]::IsNullOrWhiteSpace($value)) { $value = 'unknown' }
    if ($value.Length -gt $script:VsClientLogComponentMaxChars) {
        $value = $value.Substring(0, $script:VsClientLogComponentMaxChars)
    }
    return $value
}

function Get-VsClientCorrelationId {
    if (-not $script:VsClientCorrelationId) {
        $script:VsClientCorrelationId = ([guid]::NewGuid().ToString('N')).Substring(0, 16).ToLowerInvariant()
    }
    return $script:VsClientCorrelationId
}

function Format-VsClientLogLine {
    param(
        [Parameter(Mandatory)][string]$Message,
        [Parameter(Mandatory)][ValidateSet('DEBUG', 'INFO', 'WARN', 'ERROR')][string]$Level,
        [string]$Context = '-',
        [datetime]$Timestamp = (Get-Date)
    )
    $component = Get-VsClientLogComponentName -Component $script:VsClientLogComponent
    $contextValue = ConvertTo-VsClientLogField -Text $Context -MaxBytes $script:VsClientLogContextMaxBytes
    if ([string]::IsNullOrWhiteSpace($contextValue)) { $contextValue = '-' }
    $messageValue = ConvertTo-VsClientLogField -Text $Message -MaxBytes $script:VsClientLogMessageMaxBytes
    if ([string]::IsNullOrWhiteSpace($messageValue)) { $messageValue = '-' }

    $prefix = '{0} | {1,-5} | {2} | {3} | ' -f $Timestamp.ToString('o'), $Level, $component, $contextValue
    $suffix = ' | {0}' -f (Get-VsClientCorrelationId)
    $remaining = $script:VsClientLogLineMaxBytes - [System.Text.Encoding]::UTF8.GetByteCount($prefix + $suffix)
    if ($remaining -lt 1) { $remaining = 1 }
    $messageValue = Get-VsClientLogUtf8Truncated -Text $messageValue -MaxBytes $remaining
    return ($prefix + $messageValue + $suffix)
}

function Write-VsClientLogSinkFailure {
    param([string]$Detail)
    if (-not $script:VsClientLogSinkFailed) {
        $script:VsClientLogSinkFailed = $true
        Write-Warning ('Log-Sink gest{0}rt.' -f [char]0x00F6)
    }
    if ($Detail) { Write-Debug ('Log-Sink-Detail: {0}' -f (ConvertTo-VsClientLogField -Text $Detail -MaxBytes $script:VsClientLogMessageMaxBytes)) }
}

function Write-VsClientLogSinkRecovery {
    if ($script:VsClientLogSinkFailed) {
        $script:VsClientLogSinkFailed = $false
        Write-Warning ('Log-Sink wieder verf{0}gbar.' -f [char]0x00FC)
    }
}

function Initialize-VsClientLog {
    param(
        [Parameter(Mandatory)][string]$Component,
        [string]$LogRoot
    )
    $script:VsClientLogComponent = Get-VsClientLogComponentName -Component $Component
    if ($LogRoot) { $script:VsClientLogRoot = $LogRoot }
    try {
        if (-not (Test-Path $script:VsClientLogRoot)) {
            New-Item -ItemType Directory -Path $script:VsClientLogRoot -Force -ErrorAction Stop | Out-Null
        }
        Write-VsClientLogSinkRecovery
    } catch {
        Write-VsClientLogSinkFailure -Detail $_.Exception.Message
    }
}

function Write-VsClientLog {
    param(
        [Parameter(Mandatory)][string]$Message,
        [ValidateSet('DEBUG', 'INFO', 'WARN', 'ERROR')][string]$Level = 'INFO',
        [string]$Context = '-'
    )
    $line = Format-VsClientLogLine -Message $Message -Level $Level -Context $Context
    # A logger is diagnostic output. Keeping Stream 1 empty is load-bearing for
    # helpers such as Resolve-VsApi, whose sole return value is an address.
    Write-Host $line
    try {
        $file = Join-Path $script:VsClientLogRoot ('{0}_{1}.log' -f (Get-Date -Format 'yyyy-MM-dd'), (Get-VsClientLogComponentName -Component $script:VsClientLogComponent))
        Add-Content -Path $file -Value $line -Encoding UTF8 -ErrorAction Stop
        $retentionHealthy = $true
        if ($null -eq $script:VsClientLogRetentionNextCheck -or (Get-Date) -ge $script:VsClientLogRetentionNextCheck) {
            $retentionHealthy = Invoke-VsClientLogRetention
        }
        if ($retentionHealthy) { Write-VsClientLogSinkRecovery }
    } catch {
        Write-VsClientLogSinkFailure -Detail $_.Exception.Message
    }
}

function Invoke-VsClientLogRetention {
    param([datetime]$Now = (Get-Date))
    $marker = Join-Path $script:VsClientLogRoot 'last_cleanup.txt'
    $lockPath = Join-Path $script:VsClientLogRoot 'last_cleanup.lock'
    $lock = $null
    try {
        $lock = [System.IO.File]::Open($lockPath, [System.IO.FileMode]::OpenOrCreate, [System.IO.FileAccess]::ReadWrite, [System.IO.FileShare]::None)
    } catch [System.IO.IOException] {
        return $true
    } catch {
        $script:VsClientLogRetentionNextCheck = $null
        Write-VsClientLogSinkFailure -Detail $_.Exception.Message
        return $false
    }

    try {
        $due = $true
        if (Test-Path $marker) {
            try {
                $raw = Get-Content $marker -ErrorAction Stop | Select-Object -First 1
                $last = [datetime]::Parse([string]$raw, [System.Globalization.CultureInfo]::InvariantCulture, [System.Globalization.DateTimeStyles]::RoundtripKind)
                if (($Now - $last).TotalHours -lt $script:VsClientLogRetentionCheckHours) {
                    $due = $false
                    $script:VsClientLogRetentionNextCheck = $last.AddHours($script:VsClientLogRetentionCheckHours)
                }
            } catch {
                Write-Debug ('Log-Retention-Marker ungueltig, wird ersetzt: {0}' -f (ConvertTo-VsClientLogField -Text $_.Exception.Message -MaxBytes $script:VsClientLogMessageMaxBytes))
            }
        }
        if (-not $due) {
            return $true
        }

        $script:VsClientLogRetentionNextCheck = $Now.AddHours($script:VsClientLogRetentionCheckHours)
        $cutoff = $Now.AddDays(-$script:VsClientLogRetentionDays)
        Get-ChildItem -Path $script:VsClientLogRoot -Filter '*.log' -File -ErrorAction Stop |
            Where-Object { $_.LastWriteTime -lt $cutoff } |
            Remove-Item -Force -ErrorAction Stop
        $Now.ToString('o') | Set-Content -Path $marker -Encoding UTF8 -ErrorAction Stop
        return $true
    } catch {
        $script:VsClientLogRetentionNextCheck = $null
        Write-VsClientLogSinkFailure -Detail $_.Exception.Message
        return $false
    } finally {
        if ($null -ne $lock) { $lock.Dispose() }
    }
}
