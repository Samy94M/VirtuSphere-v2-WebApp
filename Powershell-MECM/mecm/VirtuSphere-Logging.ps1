#Requires -Version 5.1
# Lokale Logging-Domaene der MECM-Server-Skripte (Etappe 10D).
# Das Clientpaket besitzt eine getrennte Implementation mit demselben Vertrag;
# beide Laufzeitpakete teilen absichtlich keine Datei (ADR-0029).
Set-StrictMode -Version 1.0

$script:VsLoggingContractVersion = 1
$script:VsLogLevels = @('DEBUG', 'INFO', 'WARN', 'ERROR')
$script:VsLogRetentionDays = 30
$script:VsLogRetentionCheckHours = 24
$script:VsLogComponentMaxChars = 64
$script:VsLogContextMaxBytes = 256
$script:VsLogMessageMaxBytes = 3072
$script:VsLogLineMaxBytes = 4096
$script:VsLogSecretKeys = 'access_token|refresh_token|auth_token|api_key|apikey|client_secret|private_key|password|passwd|pwd|secret|token'
$script:VsLogSecretHeaders = 'Authorization|Proxy-Authorization|X-VirtuSphere-Token|Auth-Token|Set-Cookie|Cookie|HTTP_AUTHORIZATION|HTTP_PROXY_AUTHORIZATION|HTTP_X_VIRTUSPHERE_TOKEN|HTTP_AUTH_TOKEN|HTTP_COOKIE'

$script:VsLogComponent = 'virtusphere'
$script:VsLogRoot = if ($env:ProgramFiles) {
    Join-Path $env:ProgramFiles 'VirtuSphere\Logs'
} else {
    Join-Path ([System.IO.Path]::GetTempPath()) 'VirtuSphere-Logs'
}
$script:VsCorrelationId = ([guid]::NewGuid().ToString('N')).Substring(0, 16).ToLowerInvariant()
$script:VsLogRetentionNextCheck = $null
$script:VsLogSinkFailed = $false

function Get-VsLoggingContractVersion {
    return $script:VsLoggingContractVersion
}

function Get-VsLogContract {
    [pscustomobject]@{
        Version             = $script:VsLoggingContractVersion
        Levels              = @($script:VsLogLevels)
        RetentionDays       = $script:VsLogRetentionDays
        RetentionCheckHours = $script:VsLogRetentionCheckHours
        ComponentMaxChars   = $script:VsLogComponentMaxChars
        ContextMaxBytes     = $script:VsLogContextMaxBytes
        MessageMaxBytes     = $script:VsLogMessageMaxBytes
        LineMaxBytes        = $script:VsLogLineMaxBytes
        FileNamePattern     = 'yyyy-MM-dd_<component>.log'
        FieldCount          = 6
    }
}

function Get-VsLogUtf8Truncated {
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

function Protect-VsLogText {
    param([AllowNull()][string]$Text)
    if ($null -eq $Text) { return '' }
    $value = [string]$Text

    # Dieselben benannten Header-/Parameterformen wie der PHP-Redactor. Ein
    # zitierter Wert darf Leerzeichen enthalten; der gesamte Wert verschwindet.
    # Beim Authorization-Header bleibt nur das Schema als Diagnose erhalten.
    $headerPattern = '(?i)\b(' + $script:VsLogSecretHeaders + ')("?\s*[:=]\s*"?)([^\r\n,;"'']*)'
    $value = [regex]::Replace($value, $headerPattern, {
        param($match)
        $scheme = ''
        if ($match.Groups[3].Value -match '^([A-Za-z][A-Za-z0-9._-]*)\s+\S') {
            $scheme = $Matches[1] + ' '
        }
        return ($match.Groups[1].Value + $match.Groups[2].Value + $scheme + '[redacted]')
    })

    $keys = $script:VsLogSecretKeys
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

function Remove-VsLogTerminalSequences {
    param([AllowNull()][string]$Text)
    if ($null -eq $Text) { return '' }
    $value = [string]$Text
    $esc = [regex]::Escape([string][char]27)

    # OSC may end in BEL or ST (ESC + backslash). Remove complete sequences
    # before CSI. An unterminated OSC is discarded from its introducer to the
    # field end: retaining its printable payload could reassemble a secret only
    # after the redaction pass.
    $value = [regex]::Replace($value, ($esc + '\][^\x07]*(?:\x07|' + $esc + '\\)'), '')
    $value = [regex]::Replace($value, ($esc + '\][^\x07]*$'), '')
    $value = [regex]::Replace($value, ($esc + '\[[0-?]*[ -/]*[@-~]'), '')
    $value = [regex]::Replace($value, ($esc + '\[[0-?]*[ -/]*$'), '')
    return $value.Replace([string][char]27, '')
}

function ConvertTo-VsLogField {
    param(
        [AllowNull()][string]$Text,
        [Parameter(Mandatory)][int]$MaxBytes
    )
    # Terminal markup is attacker-controlled text too. Normalize it first so it
    # cannot split a known key (for example pass<CSI>word) until after redaction.
    $value = Remove-VsLogTerminalSequences -Text $Text
    $value = Protect-VsLogText -Text $value
    $value = $value -replace '\r\n?|\n', ' '
    $value = $value -replace '[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]', ' '
    # Ein Feld darf den sechs Spalten des Wire-aehnlichen Dateiformats keine
    # siebte Spalte unterschieben.
    $value = $value.Replace('|', ';').Trim()
    return (Get-VsLogUtf8Truncated -Text $value -MaxBytes $MaxBytes)
}

function Get-VsLogComponentName {
    param([AllowNull()][string]$Component)
    $value = if ($null -eq $Component) { '' } else { $Component.Trim() }
    $value = $value -replace '[^A-Za-z0-9._-]', '_'
    if ([string]::IsNullOrWhiteSpace($value)) { $value = 'unknown' }
    if ($value.Length -gt $script:VsLogComponentMaxChars) {
        $value = $value.Substring(0, $script:VsLogComponentMaxChars)
    }
    return $value
}

function Get-VsCorrelationId {
    if (-not $script:VsCorrelationId) {
        $script:VsCorrelationId = ([guid]::NewGuid().ToString('N')).Substring(0, 16).ToLowerInvariant()
    }
    return $script:VsCorrelationId
}

function Format-VsLogLine {
    param(
        [Parameter(Mandatory)][string]$Message,
        [Parameter(Mandatory)][ValidateSet('DEBUG', 'INFO', 'WARN', 'ERROR')][string]$Level,
        [string]$Context = '-',
        [datetime]$Timestamp = (Get-Date)
    )
    $component = Get-VsLogComponentName -Component $script:VsLogComponent
    $contextValue = ConvertTo-VsLogField -Text $Context -MaxBytes $script:VsLogContextMaxBytes
    if ([string]::IsNullOrWhiteSpace($contextValue)) { $contextValue = '-' }
    $messageValue = ConvertTo-VsLogField -Text $Message -MaxBytes $script:VsLogMessageMaxBytes
    if ([string]::IsNullOrWhiteSpace($messageValue)) { $messageValue = '-' }

    $prefix = '{0} | {1,-5} | {2} | {3} | ' -f $Timestamp.ToString('o'), $Level, $component, $contextValue
    $suffix = ' | {0}' -f (Get-VsCorrelationId)
    $remaining = $script:VsLogLineMaxBytes - [System.Text.Encoding]::UTF8.GetByteCount($prefix + $suffix)
    if ($remaining -lt 1) { $remaining = 1 }
    $messageValue = Get-VsLogUtf8Truncated -Text $messageValue -MaxBytes $remaining
    return ($prefix + $messageValue + $suffix)
}

function Write-VsLogSinkFailure {
    param([string]$Detail)
    if (-not $script:VsLogSinkFailed) {
        $script:VsLogSinkFailed = $true
        Write-Warning ('Log-Sink gest{0}rt.' -f [char]0x00F6)
    }
    if ($Detail) { Write-Debug ('Log-Sink-Detail: {0}' -f (ConvertTo-VsLogField -Text $Detail -MaxBytes $script:VsLogMessageMaxBytes)) }
}

function Write-VsLogSinkRecovery {
    if ($script:VsLogSinkFailed) {
        $script:VsLogSinkFailed = $false
        Write-Warning ('Log-Sink wieder verf{0}gbar.' -f [char]0x00FC)
    }
}

function Initialize-VsLog {
    param(
        [Parameter(Mandatory)][string]$Component,
        [string]$LogRoot
    )
    $script:VsLogComponent = Get-VsLogComponentName -Component $Component
    if ($LogRoot) { $script:VsLogRoot = $LogRoot }
    try {
        if (-not (Test-Path $script:VsLogRoot)) {
            New-Item -ItemType Directory -Path $script:VsLogRoot -Force -ErrorAction Stop | Out-Null
        }
        Write-VsLogSinkRecovery
    } catch {
        Write-VsLogSinkFailure -Detail $_.Exception.Message
    }
}

function Write-VsLog {
    param(
        [Parameter(Mandatory)][string]$Message,
        [ValidateSet('DEBUG', 'INFO', 'WARN', 'ERROR')][string]$Level = 'INFO',
        [string]$Context = '-',
        [string]$Color
    )
    $line = Format-VsLogLine -Message $Message -Level $Level -Context $Context
    $consoleMessage = ConvertTo-VsLogField -Text $Message -MaxBytes $script:VsLogMessageMaxBytes
    if (-not $Color) {
        $Color = switch ($Level) { 'ERROR' { 'Red' } 'WARN' { 'Yellow' } 'DEBUG' { 'DarkGray' } default { 'Gray' } }
    }
    Write-Host $consoleMessage -ForegroundColor $Color

    try {
        $file = Join-Path $script:VsLogRoot ('{0}_{1}.log' -f (Get-Date -Format 'yyyy-MM-dd'), (Get-VsLogComponentName -Component $script:VsLogComponent))
        Add-Content -Path $file -Value $line -Encoding UTF8 -ErrorAction Stop
        $retentionHealthy = $true
        if ($null -eq $script:VsLogRetentionNextCheck -or (Get-Date) -ge $script:VsLogRetentionNextCheck) {
            $retentionHealthy = Invoke-VsLogRetention
        }
        if ($retentionHealthy) { Write-VsLogSinkRecovery }
    } catch {
        Write-VsLogSinkFailure -Detail $_.Exception.Message
    }
}

function Invoke-VsLogRetention {
    param([datetime]$Now = (Get-Date))
    $marker = Join-Path $script:VsLogRoot 'last_cleanup.txt'
    $lockPath = Join-Path $script:VsLogRoot 'last_cleanup.lock'
    $lock = $null
    try {
        # Ein zweiter Prozess darf die gerade laufende taegliche Bereinigung
        # einfach ueberspringen. Der erste schreibt danach den gemeinsamen Marker.
        $lock = [System.IO.File]::Open($lockPath, [System.IO.FileMode]::OpenOrCreate, [System.IO.FileAccess]::ReadWrite, [System.IO.FileShare]::None)
    } catch [System.IO.IOException] {
        # Ein anderer Prozess bereinigt gerade. Das ist ein gesunder Skip und
        # darf keinen Sinkausfall vortaeuschen.
        return $true
    } catch {
        $script:VsLogRetentionNextCheck = $null
        Write-VsLogSinkFailure -Detail $_.Exception.Message
        return $false
    }

    try {
        $due = $true
        if (Test-Path $marker) {
            try {
                $raw = Get-Content $marker -ErrorAction Stop | Select-Object -First 1
                $last = [datetime]::Parse([string]$raw, [System.Globalization.CultureInfo]::InvariantCulture, [System.Globalization.DateTimeStyles]::RoundtripKind)
                if (($Now - $last).TotalHours -lt $script:VsLogRetentionCheckHours) {
                    $due = $false
                    $script:VsLogRetentionNextCheck = $last.AddHours($script:VsLogRetentionCheckHours)
                }
            } catch {
                # Teilwrite/Stromausfall: ein kaputter Marker ist kein ewiger
                # Retention-Blocker. Jetzt bereinigen und ihn unten ersetzen.
                Write-Debug ('Log-Retention-Marker ungueltig, wird ersetzt: {0}' -f (ConvertTo-VsLogField -Text $_.Exception.Message -MaxBytes $script:VsLogMessageMaxBytes))
            }
        }
        if (-not $due) {
            return $true
        }

        $script:VsLogRetentionNextCheck = $Now.AddHours($script:VsLogRetentionCheckHours)
        $cutoff = $Now.AddDays(-$script:VsLogRetentionDays)
        Get-ChildItem -Path $script:VsLogRoot -Filter '*.log' -File -ErrorAction Stop |
            Where-Object { $_.LastWriteTime -lt $cutoff } |
            Remove-Item -Force -ErrorAction Stop
        $Now.ToString('o') | Set-Content -Path $marker -Encoding UTF8 -ErrorAction Stop
        return $true
    } catch {
        # Auf der naechsten Logzeile erneut versuchen. Die gemeinsame
        # Sinkdrosselung verhindert dabei Warnspam und meldet Erholung erst,
        # nachdem auch die Bereinigung wieder erfolgreich war.
        $script:VsLogRetentionNextCheck = $null
        Write-VsLogSinkFailure -Detail $_.Exception.Message
        return $false
    } finally {
        if ($null -ne $lock) { $lock.Dispose() }
    }
}
