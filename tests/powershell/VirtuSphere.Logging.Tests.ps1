# Etappe 10D: gespiegelt versionierter Server-/Client-Logvertrag, lokale Module,
# Retention, Sinkstoerung und Packaging. Alle Dateipfade bleiben Join-Path-basiert,
# damit dieselbe Suite unter Windows PowerShell 5.1 und Linux-pwsh laeuft.

BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $script:PsRoot = Join-Path $script:RepoRoot 'Powershell-MECM'
    $script:MecmDir = Join-Path $script:PsRoot 'mecm'
    $script:ClientDir = Join-Path $script:PsRoot 'clients'
    $script:ServerCommon = Join-Path $script:MecmDir 'VirtuSphere-Common.ps1'
    $script:ServerLogging = Join-Path $script:MecmDir 'VirtuSphere-Logging.ps1'
    $script:ClientCommon = Join-Path $script:ClientDir 'VirtuSphere-Client-Common.ps1'
    $script:ClientLogging = Join-Path $script:ClientDir 'VirtuSphere-Client-Logging.ps1'
    $script:Packaging = Join-Path $script:MecmDir 'VirtuSphere-ClientPackaging.ps1'

    function Invoke-InLoggingScope {
        param([string]$Path, [scriptblock]$Body, [object[]]$Arguments = @())
        & {
            param($p, $b, $a)
            . $p
            & $b @a
        } $Path $Body $Arguments
    }

    function New-LoggingTempRoot {
        $root = Join-Path ([System.IO.Path]::GetTempPath()) ('vs-logging-' + [guid]::NewGuid().ToString('N'))
        New-Item -ItemType Directory -Path $root -Force | Out-Null
        return $root
    }

    function Get-ServerContract {
        Invoke-InLoggingScope -Path $script:ServerLogging -Body { Get-VsLogContract }
    }

    function Get-ClientContract {
        Invoke-InLoggingScope -Path $script:ClientLogging -Body { Get-VsClientLogContract }
    }
}

Describe 'PowerShell-Loggingvertrag: Paritaet und Dot-Source-Exports' {
    It 'Server und Client spiegeln Version, Levels, Schema, Dateiname, Bounds und Retention' {
        $server = Get-ServerContract
        $client = Get-ClientContract
        $server.Version | Should -Be 1
        ($server.Levels -join ',') | Should -Be 'DEBUG,INFO,WARN,ERROR'
        ($client | ConvertTo-Json -Compress) | Should -Be ($server | ConvertTo-Json -Compress)
    }

    It 'die PHP-Hilfekonstanten spiegeln die ausgelieferten PowerShell-Bounds' {
        $constants = Get-Content -Raw -Path (Join-Path (Join-Path (Join-Path $script:RepoRoot 'Docker') 'WebAPI') 'lib/constants.php')
        $server = Get-ServerContract
        $constants | Should -Match ('VIRTUSPHERE_POWERSHELL_LOG_RETENTION_DAYS\s*=\s*{0}' -f $server.RetentionDays)
        $constants | Should -Match ('VIRTUSPHERE_POWERSHELL_LOG_MESSAGE_MAX_BYTES\s*=\s*{0}' -f $server.MessageMaxBytes)
        $constants | Should -Match ('VIRTUSPHERE_POWERSHELL_LOG_LINE_MAX_BYTES\s*=\s*{0}' -f $server.LineMaxBytes)
    }

    It 'beide Common-Fassaden laden unter dem aktuellen Host und behalten die bisherigen Loggingaufrufe' {
        $server = Invoke-InLoggingScope -Path $script:ServerCommon -Body {
            $init = Get-Command Initialize-VsLog
            $write = Get-Command Write-VsLog
            [pscustomobject]@{
                Version = Get-VsLoggingContractVersion
                Init = @($init.Parameters.Keys)
                Write = @($write.Parameters.Keys)
                Retention = [bool](Get-Command Invoke-VsLogRetention)
                Correlation = [bool](Get-Command Get-VsCorrelationId)
            }
        }
        $client = Invoke-InLoggingScope -Path $script:ClientCommon -Body {
            $init = Get-Command Initialize-VsClientLog
            $write = Get-Command Write-VsClientLog
            [pscustomobject]@{
                Version = Get-VsClientLoggingContractVersion
                Init = @($init.Parameters.Keys)
                Write = @($write.Parameters.Keys)
                Retention = [bool](Get-Command Invoke-VsClientLogRetention)
                Correlation = [bool](Get-Command Get-VsClientCorrelationId)
            }
        }
        $server.Version | Should -Be 1
        $server.Init | Should -Contain 'Component'
        $server.Init | Should -Contain 'LogRoot'
        $server.Write | Should -Contain 'Message'
        $server.Write | Should -Contain 'Level'
        $server.Write | Should -Contain 'Context'
        $server.Write | Should -Contain 'Color'
        $server.Retention | Should -BeTrue
        $server.Correlation | Should -BeTrue
        $client.Version | Should -Be 1
        $client.Init | Should -Contain 'Component'
        $client.Write | Should -Contain 'Message'
        $client.Write | Should -Contain 'Level'
        $client.Write | Should -Contain 'Context'
        $client.Retention | Should -BeTrue
        $client.Correlation | Should -BeTrue
    }

    It 'jede Laufzeitschleife sourct ihre Common-Fassade vor der Loginitialisierung' {
        foreach ($name in @('mecm_new-device-sync.ps1', 'mecm_Packages-TaskSeq-sync.ps1', 'mecm_autoimporter.ps1', 'mecm_site-health.ps1')) {
            $text = Get-Content -Raw -Path (Join-Path $script:MecmDir $name)
            $text | Should -Match '(?s)VirtuSphere-Common\.ps1.*Initialize-VsLog'
        }
        foreach ($name in @('client_getinfo.ps1', 'client_hostname.ps1', 'client_staticip.ps1', 'Set-VMDisksOnline.ps1')) {
            $text = Get-Content -Raw -Path (Join-Path $script:ClientDir $name)
            $text | Should -Match '(?s)VirtuSphere-Client-Common\.ps1.*Initialize-VsClientLog'
        }
    }
}

Describe 'PowerShell-Loggingvertrag: Zeile, Unicode, Bounds und Redigierung' {
    It 'Server und Client erzeugen dieselben sechs Felder und denselben Tagesdateinamen' {
        $root = New-LoggingTempRoot
        try {
            $serverLine = Invoke-InLoggingScope -Path $script:ServerLogging -Arguments @($root) -Body {
                param($r)
                Initialize-VsLog -Component 'schema' -LogRoot $r
                Write-VsLog -Level WARN -Context 'VM01/getinfo' -Message 'Gruesse aus dem Server' | Out-Null
                Get-Content -Path (Join-Path $r ((Get-Date -Format 'yyyy-MM-dd') + '_schema.log')) | Select-Object -First 1
            }
            Remove-Item -Path (Join-Path $root '*') -Recurse -Force -ErrorAction SilentlyContinue
            $clientLine = Invoke-InLoggingScope -Path $script:ClientLogging -Arguments @($root) -Body {
                param($r)
                Initialize-VsClientLog -Component 'schema' -LogRoot $r
                Write-VsClientLog -Level WARN -Context 'VM01/getinfo' -Message 'Gruesse aus dem Client' | Out-Null
                Get-Content -Path (Join-Path $r ((Get-Date -Format 'yyyy-MM-dd') + '_schema.log')) | Select-Object -First 1
            }
            foreach ($line in @($serverLine, $clientLine)) {
                $parts = @($line -split '\s+\|\s+')
                $parts.Count | Should -Be 6
                $parts[0] | Should -Match '^\d{4}-\d{2}-\d{2}T'
                $parts[1] | Should -Be 'WARN'
                $parts[2] | Should -Be 'schema'
                $parts[3] | Should -Be 'VM01/getinfo'
                $parts[5] | Should -Match '^[0-9a-f]{16}$'
            }
        } finally {
            Remove-Item -Path $root -Recurse -Force -ErrorAction SilentlyContinue
        }
    }

    It 'bewahrt Unicode, normalisiert Steuerzeichen und verhindert zusaetzliche Trennspalten' {
        $line = Invoke-InLoggingScope -Path $script:ClientLogging -Body {
            $script:VsClientLogComponent = 'unicode'
            Format-VsClientLogLine -Level INFO -Context "phase|eins`n" -Message "Grüße 世界`r`nzweite|Spalte"
        }
        $line | Should -Match 'Grüße 世界'
        @($line -split '\s+\|\s+').Count | Should -Be 6
        $line | Should -Not -Match "`r|`n"
        $line | Should -Match 'phase;eins'
        $line | Should -Match 'zweite;Spalte'
    }

    It 'kappt Nachricht und Gesamtzeile UTF-8-sicher an den gespiegelten Bounds' {
        $result = Invoke-InLoggingScope -Path $script:ServerLogging -Body {
            $script:VsLogComponent = 'bound'
            $line = Format-VsLogLine -Level INFO -Context ('ctx' * 500) -Message ('ü' * 5000)
            [pscustomobject]@{
                Line = $line
                LineBytes = [Text.Encoding]::UTF8.GetByteCount($line)
                MessageBytes = [Text.Encoding]::UTF8.GetByteCount((@($line -split '\s+\|\s+'))[4])
                Contract = Get-VsLogContract
            }
        }
        $result.LineBytes | Should -BeLessOrEqual $result.Contract.LineMaxBytes
        $result.MessageBytes | Should -BeLessOrEqual $result.Contract.MessageMaxBytes
        $result.Line | Should -Not -Match ([char]0xFFFD)
    }

    It 'redigiert den vollstaendigen Secret-Wortschatz samt zitierten Werten, aber nicht die Korrelations-ID' {
        $sentinels = @(
            'alpha beta', 'PWD-LEAK', 'PASS LEAK', 'PRIVATE-LEAK',
            'COOKIE-LEAK', 'SETCOOKIE-LEAK', 'ACCESS-LEAK',
            'REFRESH-LEAK', 'AUTH-LEAK'
        )
        $messages = @(
            'password="alpha beta" suffix=kept',
            'pwd=PWD-LEAK',
            "passwd='PASS LEAK' suffix=kept",
            'private_key=PRIVATE-LEAK',
            'Cookie: session=COOKIE-LEAK',
            'Set-Cookie: auth=SETCOOKIE-LEAK',
            '?access_token=ACCESS-LEAK&next=x',
            'refresh_token%3DREFRESH-LEAK%26next%3Dx',
            'Authorization: Bearer AUTH-LEAK'
        )
        $lines = @()
        $lines += Invoke-InLoggingScope -Path $script:ServerLogging -Arguments @(, $messages) -Body {
            param($inputs)
            $script:VsLogComponent = 'redaction'
            foreach ($message in $inputs) {
                Format-VsLogLine -Level ERROR -Context 'redaction' -Message $message
            }
        }
        $lines += Invoke-InLoggingScope -Path $script:ClientLogging -Arguments @(, $messages) -Body {
            param($inputs)
            $script:VsClientLogComponent = 'redaction'
            foreach ($message in $inputs) {
                Format-VsClientLogLine -Level ERROR -Context 'redaction' -Message $message
            }
        }
        $lines.Count | Should -Be 18
        foreach ($line in $lines) {
            foreach ($sentinel in $sentinels) { $line | Should -Not -Match $sentinel }
            $line | Should -Match '\[redacted\]'
            (@($line -split '\s+\|\s+'))[5] | Should -Match '^[0-9a-f]{16}$'
        }
    }
}

Describe 'PowerShell-Loggingvertrag: Retention und parallele Prozesse' {
    It 'Server behaelt 29 und exakt 30 Tage, loescht 31 Tage' {
        $root = New-LoggingTempRoot
        try {
            $remaining = Invoke-InLoggingScope -Path $script:ServerLogging -Arguments @($root) -Body {
                param($r)
                Initialize-VsLog -Component 'retention' -LogRoot $r
                $now = [datetime]'2026-08-27T12:00:00'
                foreach ($days in @(29, 30, 31)) {
                    $file = Join-Path $r ("old-{0}.log" -f $days)
                    Set-Content -Path $file -Value 'x'
                    (Get-Item $file).LastWriteTime = $now.AddDays(-$days)
                }
                [void](Invoke-VsLogRetention -Now $now)
                , @(Get-ChildItem -Path $r -Filter 'old-*.log' | ForEach-Object Name | Sort-Object)
            }
            $remaining | Should -Be @('old-29.log', 'old-30.log')
        } finally {
            Remove-Item -Path $root -Recurse -Force -ErrorAction SilentlyContinue
        }
    }

    It 'Client behaelt 29 und exakt 30 Tage, loescht 31 Tage' {
        $root = New-LoggingTempRoot
        try {
            $remaining = Invoke-InLoggingScope -Path $script:ClientLogging -Arguments @($root) -Body {
                param($r)
                Initialize-VsClientLog -Component 'retention' -LogRoot $r
                $now = [datetime]'2026-08-27T12:00:00'
                foreach ($days in @(29, 30, 31)) {
                    $file = Join-Path $r ("old-{0}.log" -f $days)
                    Set-Content -Path $file -Value 'x'
                    (Get-Item $file).LastWriteTime = $now.AddDays(-$days)
                }
                [void](Invoke-VsClientLogRetention -Now $now)
                , @(Get-ChildItem -Path $r -Filter 'old-*.log' | ForEach-Object Name | Sort-Object)
            }
            $remaining | Should -Be @('old-29.log', 'old-30.log')
        } finally {
            Remove-Item -Path $root -Recurse -Force -ErrorAction SilentlyContinue
        }
    }

    It 'bereinigt pro gemeinsamem Marker hoechstens einmal in 24 Stunden' {
        $root = New-LoggingTempRoot
        try {
            $states = Invoke-InLoggingScope -Path $script:ServerLogging -Arguments @($root) -Body {
                param($r)
                Initialize-VsLog -Component 'daily' -LogRoot $r
                $now = [datetime]'2026-08-27T12:00:00'
                [void](Invoke-VsLogRetention -Now $now)
                $lateOld = Join-Path $r 'late-old.log'
                Set-Content -Path $lateOld -Value 'x'
                (Get-Item $lateOld).LastWriteTime = $now.AddDays(-31)
                [void](Invoke-VsLogRetention -Now $now.AddHours(23))
                $beforeDue = Test-Path $lateOld
                [void](Invoke-VsLogRetention -Now $now.AddHours(24))
                , @($beforeDue, (Test-Path $lateOld))
            }
            $states | Should -Be @($true, $false)
        } finally {
            Remove-Item -Path $root -Recurse -Force -ErrorAction SilentlyContinue
        }
    }

    It 'ein paralleler Prozess mit Cleanup-Lock wird uebersprungen und der naechste Lauf bereinigt' {
        $root = New-LoggingTempRoot
        try {
            $states = Invoke-InLoggingScope -Path $script:ServerLogging -Arguments @($root) -Body {
                param($r)
                Initialize-VsLog -Component 'parallel' -LogRoot $r
                $now = [datetime]'2026-08-27T12:00:00'
                $old = Join-Path $r 'old-31.log'
                Set-Content -Path $old -Value 'x'
                (Get-Item $old).LastWriteTime = $now.AddDays(-31)
                $lockPath = Join-Path $r 'last_cleanup.lock'
                $other = [IO.File]::Open($lockPath, [IO.FileMode]::OpenOrCreate, [IO.FileAccess]::ReadWrite, [IO.FileShare]::None)
                try {
                    [void](Invoke-VsLogRetention -Now $now)
                    $during = Test-Path $old
                } finally {
                    $other.Dispose()
                }
                [void](Invoke-VsLogRetention -Now $now)
                , @($during, (Test-Path $old))
            }
            $states | Should -Be @($true, $false)
        } finally {
            Remove-Item -Path $root -Recurse -Force -ErrorAction SilentlyContinue
        }
    }

    It 'Server ersetzt einen korrupten Cleanup-Marker und fuehrt die faellige Retention aus' {
        $root = New-LoggingTempRoot
        try {
            $state = Invoke-InLoggingScope -Path $script:ServerLogging -Arguments @($root) -Body {
                param($r)
                Initialize-VsLog -Component 'corrupt-marker' -LogRoot $r
                $now = [datetime]'2026-08-27T12:00:00'
                $marker = Join-Path $r 'last_cleanup.txt'
                Set-Content -Path $marker -Value 'half-written-marker'
                $old = Join-Path $r 'old-31.log'
                Set-Content -Path $old -Value 'x'
                (Get-Item $old).LastWriteTime = $now.AddDays(-31)
                $script:VsLogRetentionNextCheck = $null
                [void](Invoke-VsLogRetention -Now $now)
                [pscustomobject]@{
                    OldExists = Test-Path $old
                    Marker = Get-Content -Path $marker | Select-Object -First 1
                }
            }
            $state.OldExists | Should -BeFalse
            { [datetime]::Parse($state.Marker, [Globalization.CultureInfo]::InvariantCulture, [Globalization.DateTimeStyles]::RoundtripKind) } |
                Should -Not -Throw
        } finally {
            Remove-Item -Path $root -Recurse -Force -ErrorAction SilentlyContinue
        }
    }

    It 'Client ersetzt einen korrupten Cleanup-Marker und fuehrt die faellige Retention aus' {
        $root = New-LoggingTempRoot
        try {
            $state = Invoke-InLoggingScope -Path $script:ClientLogging -Arguments @($root) -Body {
                param($r)
                Initialize-VsClientLog -Component 'corrupt-marker' -LogRoot $r
                $now = [datetime]'2026-08-27T12:00:00'
                $marker = Join-Path $r 'last_cleanup.txt'
                Set-Content -Path $marker -Value 'half-written-marker'
                $old = Join-Path $r 'old-31.log'
                Set-Content -Path $old -Value 'x'
                (Get-Item $old).LastWriteTime = $now.AddDays(-31)
                $script:VsClientLogRetentionNextCheck = $null
                [void](Invoke-VsClientLogRetention -Now $now)
                [pscustomobject]@{
                    OldExists = Test-Path $old
                    Marker = Get-Content -Path $marker | Select-Object -First 1
                }
            }
            $state.OldExists | Should -BeFalse
            { [datetime]::Parse($state.Marker, [Globalization.CultureInfo]::InvariantCulture, [Globalization.DateTimeStyles]::RoundtripKind) } |
                Should -Not -Throw
        } finally {
            Remove-Item -Path $root -Recurse -Force -ErrorAction SilentlyContinue
        }
    }
}

Describe 'PowerShell-Loggingvertrag: defekter Sink, Drosselung und Recovery' {
    It 'fasst Retention- und Dateifehler zu genau einer Sinkstoerung zusammen' {
        $result = Invoke-InLoggingScope -Path $script:ServerLogging -Body {
            $script:warnings = @()
            $root = Join-Path ([IO.Path]::GetTempPath()) ('vs-shared-sink-' + [guid]::NewGuid().ToString('N'))
            New-Item -ItemType Directory -Path $root -Force | Out-Null
            # Ein Verzeichnis an der Lockdatei laesst das Tageslog weiter
            # schreiben, aber die Retention sichtbar scheitern.
            $badLock = Join-Path $root 'last_cleanup.lock'
            New-Item -ItemType Directory -Path $badLock -Force | Out-Null
            Initialize-VsLog -Component 'shared-sink' -LogRoot $root -WarningVariable +script:warnings
            Write-VsLog -Message 'retention fails' -WarningVariable +script:warnings
            Write-VsLog -Message 'same outage retries' -WarningVariable +script:warnings
            Remove-Item -Path $badLock -Recurse -Force
            Write-VsLog -Message 'retention recovers' -WarningVariable +script:warnings
            Write-VsLog -Message 'steady state' -WarningVariable +script:warnings
            Remove-Item -Path $root -Recurse -Force -ErrorAction SilentlyContinue
            , @($script:warnings | ForEach-Object { $_.ToString() })
        }
        $failedText = 'Log-Sink gest{0}rt.' -f [char]0x00F6
        $recoveryText = 'Log-Sink wieder verf{0}gbar.' -f [char]0x00FC
        @($result | Where-Object { $_ -eq $failedText }).Count | Should -Be 1
        @($result | Where-Object { $_ -eq $recoveryText }).Count | Should -Be 1
    }

    It 'Server stoppt nicht, warnt einmal je Stoerung und einmal bei Erholung' {
        $result = Invoke-InLoggingScope -Path $script:ServerLogging -Body {
            $script:failWrites = $true
            $script:warnings = @()
            function Add-Content {
                param($Path, $Value, $Encoding, $ErrorAction)
                if ($script:failWrites) { throw 'ReportToken=NEVER-LEAK' }
                Microsoft.PowerShell.Management\Add-Content -Path $Path -Value $Value -Encoding $Encoding -ErrorAction $ErrorAction
            }
            $root = Join-Path ([IO.Path]::GetTempPath()) ('vs-sink-' + [guid]::NewGuid().ToString('N'))
            Initialize-VsLog -Component 'sink' -LogRoot $root -WarningVariable +script:warnings
            Write-VsLog -Message 'eins' -WarningVariable +script:warnings
            Write-VsLog -Message 'zwei' -WarningVariable +script:warnings
            $script:failWrites = $false
            Write-VsLog -Message 'drei' -WarningVariable +script:warnings
            Write-VsLog -Message 'vier' -WarningVariable +script:warnings
            Remove-Item -Path $root -Recurse -Force -ErrorAction SilentlyContinue
            , @($script:warnings | ForEach-Object { $_.ToString() })
        }
        $failedText = 'Log-Sink gest{0}rt.' -f [char]0x00F6
        $recoveryText = 'Log-Sink wieder verf{0}gbar.' -f [char]0x00FC
        @($result | Where-Object { $_ -eq $failedText }).Count | Should -Be 1
        @($result | Where-Object { $_ -eq $recoveryText }).Count | Should -Be 1
        ($result -join ' ') | Should -Not -Match 'NEVER-LEAK'
    }

    It 'Client stoppt nicht, warnt einmal je Stoerung und einmal bei Erholung' {
        $result = Invoke-InLoggingScope -Path $script:ClientLogging -Body {
            $script:failWrites = $true
            $script:warnings = @()
            function Add-Content {
                param($Path, $Value, $Encoding, $ErrorAction)
                if ($script:failWrites) { throw 'token=NEVER-LEAK' }
                Microsoft.PowerShell.Management\Add-Content -Path $Path -Value $Value -Encoding $Encoding -ErrorAction $ErrorAction
            }
            $root = Join-Path ([IO.Path]::GetTempPath()) ('vs-client-sink-' + [guid]::NewGuid().ToString('N'))
            Initialize-VsClientLog -Component 'sink' -LogRoot $root -WarningVariable +script:warnings
            Write-VsClientLog -Message 'eins' -WarningVariable +script:warnings | Out-Null
            Write-VsClientLog -Message 'zwei' -WarningVariable +script:warnings | Out-Null
            $script:failWrites = $false
            Write-VsClientLog -Message 'drei' -WarningVariable +script:warnings | Out-Null
            Write-VsClientLog -Message 'vier' -WarningVariable +script:warnings | Out-Null
            Remove-Item -Path $root -Recurse -Force -ErrorAction SilentlyContinue
            , @($script:warnings | ForEach-Object { $_.ToString() })
        }
        $failedText = 'Log-Sink gest{0}rt.' -f [char]0x00F6
        $recoveryText = 'Log-Sink wieder verf{0}gbar.' -f [char]0x00FC
        @($result | Where-Object { $_ -eq $failedText }).Count | Should -Be 1
        @($result | Where-Object { $_ -eq $recoveryText }).Count | Should -Be 1
        ($result -join ' ') | Should -Not -Match 'NEVER-LEAK'
    }
}

Describe 'PowerShell-Loggingvertrag: Packaging, fehlende Datei und Version' {
    It 'ClientPackaging kopiert Phase, Common und Loggingmodul mit identischen Hashes' {
        $root = New-LoggingTempRoot
        try {
            $dest = Invoke-InLoggingScope -Path $script:Packaging -Arguments @($script:ClientDir, $root) -Body {
                param($source, $target)
                $spec = Get-VsClientAppSpecs | Select-Object -First 1
                Copy-VsClientContent -Spec $spec -SourceDir $source -PackagesBase $target
            }
            foreach ($name in @('client_getinfo.ps1', 'VirtuSphere-Client-Common.ps1', 'VirtuSphere-Client-Logging.ps1')) {
                Test-Path (Join-Path $dest $name) | Should -BeTrue
                (Get-FileHash -Algorithm SHA256 -Path (Join-Path $dest $name)).Hash |
                    Should -Be (Get-FileHash -Algorithm SHA256 -Path (Join-Path $script:ClientDir $name)).Hash
            }
            @(Get-ChildItem -Path $root -Directory | Where-Object { $_.Name -like '.virtusphere-*' }).Count | Should -Be 0
        } finally {
            Remove-Item -Path $root -Recurse -Force -ErrorAction SilentlyContinue
        }
    }

    It 'ClientPackaging ersetzt bei einem Upgrade alle drei Dateien zusammen' {
        $root = New-LoggingTempRoot
        try {
            $dest = Invoke-InLoggingScope -Path $script:Packaging -Arguments @($script:ClientDir, $root) -Body {
                param($source, $target)
                $spec = Get-VsClientAppSpecs | Select-Object -First 1
                Copy-VsClientContent -Spec $spec -SourceDir $source -PackagesBase $target
            }
            foreach ($name in @('client_getinfo.ps1', 'VirtuSphere-Client-Common.ps1', 'VirtuSphere-Client-Logging.ps1')) {
                Set-Content -Path (Join-Path $dest $name) -Value 'stale upgrade content'
            }
            $upgradeDest = Invoke-InLoggingScope -Path $script:Packaging -Arguments @($script:ClientDir, $root) -Body {
                param($source, $target)
                $spec = Get-VsClientAppSpecs | Select-Object -First 1
                Copy-VsClientContent -Spec $spec -SourceDir $source -PackagesBase $target
            }
            $upgradeDest | Should -Be $dest
            foreach ($name in @('client_getinfo.ps1', 'VirtuSphere-Client-Common.ps1', 'VirtuSphere-Client-Logging.ps1')) {
                (Get-FileHash -Algorithm SHA256 -Path (Join-Path $dest $name)).Hash |
                    Should -Be (Get-FileHash -Algorithm SHA256 -Path (Join-Path $script:ClientDir $name)).Hash
            }
        } finally {
            Remove-Item -Path $root -Recurse -Force -ErrorAction SilentlyContinue
        }
    }

    It 'ClientPackaging stellt nach fehlgeschlagener Aktivierung den vollstaendigen Altstand wieder her' {
        $root = New-LoggingTempRoot
        try {
            $result = Invoke-InLoggingScope -Path $script:Packaging -Arguments @($script:ClientDir, $root) -Body {
                param($source, $target)
                $spec = Get-VsClientAppSpecs | Select-Object -First 1
                $dest = Join-Path $target $spec.Folder
                New-Item -ItemType Directory -Path $dest -Force | Out-Null
                $old = @{
                    'client_getinfo.ps1' = 'old phase'
                    'VirtuSphere-Client-Common.ps1' = 'old common'
                    'VirtuSphere-Client-Logging.ps1' = 'old logging'
                }
                foreach ($entry in $old.GetEnumerator()) {
                    Set-Content -Path (Join-Path $dest $entry.Key) -Value $entry.Value
                }
                $script:failActivation = $true
                function Move-Item {
                    [CmdletBinding()]
                    param([string]$Path, [string]$Destination, [switch]$Force)
                    if ($script:failActivation -and (Split-Path $Path -Leaf) -like '.virtusphere-stage-*') {
                        $script:failActivation = $false
                        throw 'simulierter Aktivierungsfehler'
                    }
                    Microsoft.PowerShell.Management\Move-Item -Path $Path -Destination $Destination -Force:$Force -ErrorAction Stop
                }
                $errorText = ''
                try {
                    Copy-VsClientContent -Spec $spec -SourceDir $source -PackagesBase $target | Out-Null
                } catch {
                    $errorText = $_.Exception.Message
                }
                [pscustomobject]@{
                    Error = $errorText
                    Phase = (Get-Content -Raw -Path (Join-Path $dest 'client_getinfo.ps1')).Trim()
                    Common = (Get-Content -Raw -Path (Join-Path $dest 'VirtuSphere-Client-Common.ps1')).Trim()
                    Logging = (Get-Content -Raw -Path (Join-Path $dest 'VirtuSphere-Client-Logging.ps1')).Trim()
                    SwapDirectories = @(Get-ChildItem -Path $target -Directory | Where-Object { $_.Name -like '.virtusphere-*' }).Count
                }
            }
            $result.Error | Should -Match 'simulierter Aktivierungsfehler'
            $result.Phase | Should -Be 'old phase'
            $result.Common | Should -Be 'old common'
            $result.Logging | Should -Be 'old logging'
            $result.SwapDirectories | Should -Be 0
        } finally {
            Remove-Item -Path $root -Recurse -Force -ErrorAction SilentlyContinue
        }
    }

    It 'AST-Versionspruefung veraendert keinen Client-Loggingzustand' {
        $root = New-LoggingTempRoot
        try {
            $state = Invoke-InLoggingScope -Path $script:ClientCommon -Arguments @($script:Packaging, $script:ClientDir, $root) -Body {
                param($packaging, $source, $logRoot)
                Initialize-VsClientLog -Component 'stateful-probe' -LogRoot $logRoot
                $correlationBefore = Get-VsClientCorrelationId
                $script:VsClientLogSinkFailed = $true
                . $packaging
                $version = Get-VsClientLoggingPackageVersion -SourceDir $source
                [pscustomobject]@{
                    Version = $version
                    Component = $script:VsClientLogComponent
                    CorrelationBefore = $correlationBefore
                    CorrelationAfter = Get-VsClientCorrelationId
                    SinkFailed = $script:VsClientLogSinkFailed
                }
            }
            $state.Version | Should -Be 1
            $state.Component | Should -Be 'stateful-probe'
            $state.CorrelationAfter | Should -Be $state.CorrelationBefore
            $state.SinkFailed | Should -BeTrue
        } finally {
            Remove-Item -Path $root -Recurse -Force -ErrorAction SilentlyContinue
        }
    }

    It 'eine Client-Fassade ohne Loggingmodul scheitert mit verstaendlichem Fehler' {
        $root = New-LoggingTempRoot
        try {
            Copy-Item -Path $script:ClientCommon -Destination $root
            { . (Join-Path $root 'VirtuSphere-Client-Common.ps1') } | Should -Throw '*Logging-Modul fehlt*'
        } finally {
            Remove-Item -Path $root -Recurse -Force -ErrorAction SilentlyContinue
        }
    }

    It 'eine Server-Fassade ohne Loggingmodul scheitert mit verstaendlichem Fehler' {
        $root = New-LoggingTempRoot
        try {
            Copy-Item -Path $script:ServerCommon -Destination $root
            { . (Join-Path $root 'VirtuSphere-Common.ps1') } | Should -Throw '*Logging-Modul fehlt*'
        } finally {
            Remove-Item -Path $root -Recurse -Force -ErrorAction SilentlyContinue
        }
    }

    It 'ein versionsfalsches Clientmodul scheitert vor der Phasenaktion' {
        $root = New-LoggingTempRoot
        try {
            Copy-Item -Path $script:ClientCommon -Destination $root
            $target = Join-Path $root 'VirtuSphere-Client-Logging.ps1'
            (Get-Content -Raw -Path $script:ClientLogging) -replace 'VsClientLoggingContractVersion = 1', 'VsClientLoggingContractVersion = 2' |
                Set-Content -Path $target -Encoding UTF8
            { . (Join-Path $root 'VirtuSphere-Client-Common.ps1') } | Should -Throw '*Version 2*erwartet wird 1*'
        } finally {
            Remove-Item -Path $root -Recurse -Force -ErrorAction SilentlyContinue
        }
    }

    It 'ein versionsfalsches Servermodul scheitert vor dem Sync-Lauf' {
        $root = New-LoggingTempRoot
        try {
            Copy-Item -Path $script:ServerCommon -Destination $root
            $target = Join-Path $root 'VirtuSphere-Logging.ps1'
            (Get-Content -Raw -Path $script:ServerLogging) -replace 'VsLoggingContractVersion = 1', 'VsLoggingContractVersion = 2' |
                Set-Content -Path $target -Encoding UTF8
            { . (Join-Path $root 'VirtuSphere-Common.ps1') } | Should -Throw '*Version 2*erwartet wird 1*'
        } finally {
            Remove-Item -Path $root -Recurse -Force -ErrorAction SilentlyContinue
        }
    }

    It 'Installer und Packaging halten Staging, Hash, Version und vollstaendigen Modulsatz fest' {
        $serverInstaller = Get-Content -Raw -Path (Join-Path $script:PsRoot 'install-VirtuSphere-MECM.ps1')
        $clientInstaller = Get-Content -Raw -Path (Join-Path $script:PsRoot 'install-VirtuSphere-Clients.ps1')
        $packaging = Get-Content -Raw -Path $script:Packaging
        $serverInstaller | Should -Match 'VirtuSphere-Logging\.ps1'
        $serverInstaller | Should -Match 'Get-FileHash -Algorithm SHA256'
        $serverInstaller | Should -Match 'Get-VsLoggingContractVersion'
        $serverInstaller | Should -Match 'Get-VsDeclaredScriptInteger'
        $serverInstaller | Should -Not -Match '(?m)^\s*\. \$common\s*$'
        $serverInstaller.IndexOf('Disable-ScheduledTask') | Should -BeLessThan $serverInstaller.IndexOf('Stop-ScheduledTask')
        $clientInstaller | Should -Match 'Get-VsClientLoggingPackageVersion'
        $packaging | Should -Match 'VirtuSphere-Client-Logging\.ps1'
        $packaging | Should -Match 'Get-FileHash -Algorithm SHA256'
        $packaging | Should -Match 'virtusphere-backup-'
    }
}

Describe 'Client-Reportkorrelation bleibt additiv zum unveraenderten JSON' {
    It 'ReportPhase sendet dieselbe JSON-Struktur plus stabile Korrelations-ID im Header' {
        $captured = Invoke-InLoggingScope -Path $script:ClientCommon -Body {
            function Resolve-VsApi { return 'virtusphere.lan:8021' }
            $script:capture = $null
            function Invoke-RestMethod {
                param($Uri, $Method, $ContentType, $Body, $Headers, $TimeoutSec)
                $script:capture = [pscustomobject]@{ Body = $Body; Headers = $Headers; Uri = $Uri }
            }
            Send-VsPhase -Mac '00:50:56:AA:BB:CC' -Phase getinfo -PhaseEvent started -Detail 'one'
            $first = $script:capture
            Send-VsPhase -Mac '00:50:56:AA:BB:CC' -Phase getinfo -PhaseEvent finished -Detail 'two'
            [pscustomobject]@{ First = $first; Second = $script:capture }
        }
        $body = $captured.First.Body | ConvertFrom-Json
        ($body.PSObject.Properties.Name | Sort-Object) | Should -Be @('detail', 'event', 'mac', 'phase')
        $body.mac | Should -Be '00:50:56:AA:BB:CC'
        $captured.First.Headers['X-VirtuSphere-Correlation'] | Should -Match '^[0-9a-f]{16}$'
        $captured.Second.Headers['X-VirtuSphere-Correlation'] | Should -Be $captured.First.Headers['X-VirtuSphere-Correlation']
    }

    It 'Client-Ready-ACK behaelt ausschliesslich mac im JSON und nutzt dieselbe Headerfunktion' {
        $captured = Invoke-InLoggingScope -Path $script:ClientCommon -Body {
            $script:capture = $null
            function Invoke-RestMethod {
                param($Uri, $Method, $ContentType, $Body, $Headers, $TimeoutSec)
                $script:capture = [pscustomobject]@{ Body = $Body; Headers = $Headers }
                return [pscustomobject]@{ success = $true }
            }
            Confirm-VsClientReady -Api 'virtusphere.lan:8021' -Mac '00:50:56:AA:BB:CC'
            $script:capture
        }
        $body = $captured.Body | ConvertFrom-Json
        @($body.PSObject.Properties.Name) | Should -Be @('mac')
        $captured.Headers['X-VirtuSphere-Correlation'] | Should -Match '^[0-9a-f]{16}$'
    }
}

Describe 'Server-Heartbeat bleibt unveraendert und erzeugt keinen Zusatzverkehr' {
    It 'sendet genau einen Legacy-Heartbeat mit unveraendertem Body und Korrelationsheader' {
        $captured = Invoke-InLoggingScope -Path $script:ServerCommon -Body {
            $script:calls = @()
            function Invoke-RestMethod {
                param($Uri, $Method, $ContentType, $Body, $Headers, $TimeoutSec)
                $script:calls += [pscustomobject]@{
                    Uri = $Uri; Method = $Method; Body = $Body; Headers = $Headers; TimeoutSec = $TimeoutSec
                }
            }
            $config = [pscustomobject]@{ Scheme = 'http'; WebApi = 'virtusphere.lan:8021'; ReportToken = '' }
            Send-VsHeartbeat -Config $config -Source 'device-sync' -IntervalSeconds 60 -Detail 'still-compatible'
            , @($script:calls)
        }
        $captured.Count | Should -Be 1
        $captured[0].Uri | Should -Be 'http://virtusphere.lan:8021/mecm_report.php?action=heartbeat'
        $captured[0].Method | Should -Be 'POST'
        $captured[0].TimeoutSec | Should -Be 5
        $body = $captured[0].Body | ConvertFrom-Json
        ($body.PSObject.Properties.Name | Sort-Object) | Should -Be @('detail', 'interval_seconds', 'source')
        $body.source | Should -Be 'device-sync'
        $body.interval_seconds | Should -Be 60
        $body.detail | Should -Be 'still-compatible'
        $captured[0].Headers['X-VirtuSphere-Correlation'] | Should -Match '^[0-9a-f]{16}$'
    }
}
