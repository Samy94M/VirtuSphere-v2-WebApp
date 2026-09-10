# Regression fuer E1: JSON muss Invoke-RestMethod als echtes byte[] erreichen.
# Die Suite ist engine-neutral und wird fuer die Laufzeitabnahme einmal unter
# Windows PowerShell 5.1 und einmal unter PowerShell 7 ausgefuehrt. Der
# Loopback-Teil liest die Bytes hinter dem HTTP-Header direkt vom TCP-Stream.

BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $script:MecmCommon = Join-Path (Join-Path (Join-Path $script:RepoRoot 'Powershell-MECM') 'mecm') 'VirtuSphere-Common.ps1'
    $script:ClientCommon = Join-Path (Join-Path (Join-Path $script:RepoRoot 'Powershell-MECM') 'clients') 'VirtuSphere-Client-Common.ps1'

    function Invoke-InFileScope {
        param([string]$Path, [scriptblock]$Body, [object[]]$Arguments = @())
        & {
            param($p, $b, $a)
            . $p
            & $b @a
        } $Path $Body $Arguments
    }

    function Get-VsExpectedJsonBytes {
        param([Parameter(Mandatory)]$Value)
        $json = ConvertTo-Json -InputObject $Value -Depth 6
        return ,([Text.Encoding]::UTF8.GetBytes($json))
    }

    function New-VsJsonTransportValue {
        param([ValidateSet('object', 'one', 'many')][string]$Shape)
        $muenchen = 'M' + [char]0x00FC + 'nchen'
        $size = 'Gr' + [char]0x00F6 + [char]0x00DF + 'e'
        $aUmlaut = [string][char]0x00C4
        $tokyo = ([string][char]0x6771) + [char]0x4EAC
        switch ($Shape) {
            'object' { return [ordered]@{ name = $muenchen; enabled = $true } }
            'one' { return ,@([ordered]@{ name = $size }) }
            'many' { return ,@([ordered]@{ name = $aUmlaut }, [ordered]@{ name = $tokyo }) }
        }
    }

    function New-VsClientReadyValue {
        $value = @{ mac = '00:11:22:33:44:55' }
        $value['rollout_revision'] = 7
        return $value
    }

    function New-VsClientPhaseValue {
        $detail = 'M' + [char]0x00FC + 'nchen ' + [char]0x6771 + [char]0x4EAC
        $value = @{ mac = '00:11:22:33:44:55'; phase = 'staticip'; event = 'started' }
        $value['detail'] = $detail
        return $value
    }

    function Get-VsByteSignature {
        param([Parameter(Mandatory)]$Value)
        [pscustomobject]@{
            Type = $Value.GetType().FullName
            Hex = ((@($Value) | ForEach-Object { ([byte]$_).ToString('x2') }) -join '')
            Text = [Text.Encoding]::UTF8.GetString($Value)
        }
    }

    function Start-VsRawLoopbackCapture {
        $reservation = New-Object System.Net.Sockets.TcpListener ([Net.IPAddress]::Loopback, 0)
        $reservation.Start()
        try { $port = ([Net.IPEndPoint]$reservation.LocalEndpoint).Port } finally { $reservation.Stop() }

        $root = Join-Path ([IO.Path]::GetTempPath()) ('vs-json-loopback-' + [guid]::NewGuid().ToString('N'))
        $null = New-Item -ItemType Directory -Path $root -Force
        $readyPath = Join-Path $root 'ready'
        $bodyPath = Join-Path $root 'body.bin'
        $job = Start-Job -ArgumentList @($port, $readyPath, $bodyPath) -ScriptBlock {
            param($Port, $ReadyPath, $BodyPath)
            $listener = New-Object System.Net.Sockets.TcpListener ([Net.IPAddress]::Loopback, [int]$Port)
            $listener.Start()
            try {
                [IO.File]::WriteAllText($ReadyPath, 'ready')
                $client = $listener.AcceptTcpClient()
                try {
                    $stream = $client.GetStream()
                    $headerBytes = New-Object 'System.Collections.Generic.List[byte]'
                    while ($headerBytes.Count -lt 65536) {
                        $next = $stream.ReadByte()
                        if ($next -lt 0) { throw 'HTTP-Anfrage endete vor dem Headerabschluss.' }
                        $headerBytes.Add([byte]$next)
                        $count = $headerBytes.Count
                        if ($count -ge 4 -and
                            $headerBytes[$count - 4] -eq 13 -and $headerBytes[$count - 3] -eq 10 -and
                            $headerBytes[$count - 2] -eq 13 -and $headerBytes[$count - 1] -eq 10) { break }
                    }
                    $headers = [Text.Encoding]::ASCII.GetString($headerBytes.ToArray())
                    $lengthMatch = [regex]::Match($headers, '(?im)^Content-Length:\s*(\d+)\s*$')
                    if (-not $lengthMatch.Success) { throw 'HTTP-Anfrage ohne Content-Length.' }
                    if ($headers -match '(?im)^Expect:\s*100-continue\s*$') {
                        $continue = [Text.Encoding]::ASCII.GetBytes("HTTP/1.1 100 Continue`r`n`r`n")
                        $stream.Write($continue, 0, $continue.Length)
                        $stream.Flush()
                    }
                    $length = [int]$lengthMatch.Groups[1].Value
                    $body = New-Object byte[] $length
                    $offset = 0
                    while ($offset -lt $length) {
                        $read = $stream.Read($body, $offset, $length - $offset)
                        if ($read -le 0) { throw 'HTTP-Anfrage endete vor dem Bodyabschluss.' }
                        $offset += $read
                    }
                    [IO.File]::WriteAllBytes($BodyPath, $body)

                    $responseBody = [Text.Encoding]::UTF8.GetBytes('{"success":true}')
                    $responseHead = [Text.Encoding]::ASCII.GetBytes(
                        "HTTP/1.1 200 OK`r`nContent-Type: application/json; charset=utf-8`r`nContent-Length: $($responseBody.Length)`r`nConnection: close`r`n`r`n")
                    $stream.Write($responseHead, 0, $responseHead.Length)
                    $stream.Write($responseBody, 0, $responseBody.Length)
                    $stream.Flush()
                } finally {
                    if ($client) { $client.Dispose() }
                }
            } finally {
                $listener.Stop()
            }
        }

        for ($attempt = 0; $attempt -lt 200 -and -not (Test-Path -LiteralPath $readyPath) -and
            $job.State -notin @('Completed', 'Failed', 'Stopped'); $attempt++) {
            Start-Sleep -Milliseconds 25
        }
        if (-not (Test-Path -LiteralPath $readyPath)) {
            Stop-Job -Job $job -ErrorAction SilentlyContinue
            $failure = Receive-Job -Job $job 2>&1 | Out-String
            Remove-Job -Job $job -Force -ErrorAction SilentlyContinue
            if (Test-Path -LiteralPath $root) { Remove-Item -LiteralPath $root -Recurse -Force }
            throw ('Loopback-Listener wurde nicht bereit: ' + $failure.Trim())
        }
        [pscustomobject]@{ Port = $port; Job = $job; Root = $root; BodyPath = $bodyPath }
    }

    function Complete-VsRawLoopbackCapture {
        param([Parameter(Mandatory)]$Capture)
        $completed = Wait-Job -Job $Capture.Job -Timeout 10
        if (-not $completed) { throw 'Loopback-Listener beendete die Anfrage nicht.' }
        $jobErrors = Receive-Job -Job $Capture.Job 2>&1 | Out-String
        if ($Capture.Job.State -ne 'Completed') { throw ('Loopback-Listener fehlgeschlagen: ' + $jobErrors.Trim()) }
        return ,([IO.File]::ReadAllBytes($Capture.BodyPath))
    }

    function Remove-VsRawLoopbackCapture {
        param($Capture)
        if (-not $Capture) { return }
        if ($Capture.Job) {
            Stop-Job -Job $Capture.Job -ErrorAction SilentlyContinue
            Remove-Job -Job $Capture.Job -Force -ErrorAction SilentlyContinue
        }
        if ($Capture.Root -and (Test-Path -LiteralPath $Capture.Root)) {
            Remove-Item -LiteralPath $Capture.Root -Recurse -Force -ErrorAction SilentlyContinue
        }
    }
}

Describe 'E1 ConvertTo-VsUtf8JsonBytes CLR- und Bytevertrag' {
    It '<owner> liefert fuer Objekt, Ein-/Mehrarray und Unicode jeweils byte[] ohne Enumeration' -ForEach @(
        @{ owner = 'MECM-Server'; ownerKey = 'server' }
        @{ owner = 'Windows-Client'; ownerKey = 'client' }
    ) {
        $path = if ($ownerKey -eq 'server') { $script:MecmCommon } else { $script:ClientCommon }
        $result = Invoke-InFileScope -Path $path -Body {
            $muenchen = 'M' + [char]0x00FC + 'nchen'
            $size = 'Gr' + [char]0x00F6 + [char]0x00DF + 'e'
            $aUmlaut = [string][char]0x00C4
            $tokyo = ([string][char]0x6771) + [char]0x4EAC
            $values = @(
                @{ kind = 'object'; value = [ordered]@{ name = $muenchen; enabled = $true } }
                @{ kind = 'one'; value = @([ordered]@{ name = $size }) }
                @{ kind = 'many'; value = @([ordered]@{ name = $aUmlaut }, [ordered]@{ name = $tokyo }) }
            )
            foreach ($case in $values) {
                $actual = ConvertTo-VsUtf8JsonBytes -Value $case.value -Depth 6
                $expectedJson = ConvertTo-Json -InputObject $case.value -Depth 6
                $expectedBytes = [Text.Encoding]::UTF8.GetBytes($expectedJson)
                [pscustomobject]@{
                    Kind = $case.kind
                    Type = $actual.GetType().FullName
                    Text = [Text.Encoding]::UTF8.GetString($actual)
                    Markers = @($case.value | ForEach-Object { [string]$_.name })
                    ActualHex = ((@($actual) | ForEach-Object { ([byte]$_).ToString('x2') }) -join '')
                    ExpectedHex = ((@($expectedBytes) | ForEach-Object { ([byte]$_).ToString('x2') }) -join '')
                }
            }
        }

        @($result).Count | Should -Be 3 -Because 'ohne alle drei Formen waere die Regression unvollstaendig'
        foreach ($case in @($result)) {
            $case.Type | Should -Be 'System.Byte[]' -Because ("$owner/$($case.Kind) darf am Funktionsrand nicht zu object[] werden")
            $case.ActualHex | Should -Be $case.ExpectedHex
            foreach ($marker in @($case.Markers)) {
                $case.Text.Contains($marker) | Should -BeTrue -Because 'der aus ASCII-Codepunkten konstruierte Unicodewert muss erhalten bleiben'
            }
        }
        (@($result | Where-Object Kind -eq 'object')[0].Text).TrimStart() | Should -Match '^\{'
        (@($result | Where-Object Kind -eq 'one')[0].Text).TrimStart() | Should -Match '^\['
        (@($result | Where-Object Kind -eq 'many')[0].Text).TrimStart() | Should -Match '^\['
    }
}

Describe 'E1 tatsächliche HTTP-Parameterbindung ohne verdeckenden Stubcast' {
    It 'Invoke-VsApi reicht ein Einarray als untypisiertes byte[] mit exakten Unicode-Bytes weiter' {
        $value = New-VsJsonTransportValue -Shape one
        $bundle = @{ Value = $value }
        $captured = Invoke-InFileScope -Path $script:MecmCommon -Arguments @(,$bundle) -Body {
            param($case)
            function Invoke-RestMethod {
                param($Uri, $Method, $TimeoutSec, $Headers, $Body, $ContentType)
                # $Body bleibt absichtlich untypisiert. Erst der CLR-Typ, dann
                # die Dekodierung: ein [byte[]]-Cast hier wuerde E1 verdecken.
                [pscustomobject]@{
                    Type = $Body.GetType().FullName
                    Text = [Text.Encoding]::UTF8.GetString($Body)
                    Hex = ((@($Body) | ForEach-Object { ([byte]$_).ToString('x2') }) -join '')
                    ContentType = $ContentType
                }
            }
            $config = [pscustomobject]@{ WebApi = 'host:1'; Scheme = 'http'; ReportToken = '' }
            Invoke-VsApi -Config $config -Path '/capture' -Method POST -Body $case.Value
        }
        $expected = Get-VsExpectedJsonBytes -Value $value
        $captured.Type | Should -Be 'System.Byte[]'
        $captured.Hex | Should -Be (Get-VsByteSignature -Value $expected).Hex
        $captured.Text.TrimStart() | Should -Match '^\['
        $captured.ContentType | Should -Be 'application/json; charset=utf-8'
    }

    It 'Confirm-VsClientReady reicht seinen Objektbody als untypisiertes byte[] weiter' {
        $captured = Invoke-InFileScope -Path $script:ClientCommon -Body {
            function Invoke-RestMethod {
                param($Uri, $Method, $TimeoutSec, $Headers, $Body, $ContentType)
                $script:capturedBody = [pscustomobject]@{
                    Type = $Body.GetType().FullName
                    Text = [Text.Encoding]::UTF8.GetString($Body)
                    Hex = ((@($Body) | ForEach-Object { ([byte]$_).ToString('x2') }) -join '')
                    ContentType = $ContentType
                }
                return [pscustomobject]@{ success = $true }
            }
            Confirm-VsClientReady -Api 'host:1' -Mac '00:11:22:33:44:55' -RolloutRevision 7
            $script:capturedBody
        }
        $expected = Get-VsExpectedJsonBytes -Value (New-VsClientReadyValue)
        $captured.Type | Should -Be 'System.Byte[]'
        $captured.Hex | Should -Be (Get-VsByteSignature -Value $expected).Hex
        $captured.Text.TrimStart() | Should -Match '^\{'
        $captured.ContentType | Should -Be 'application/json; charset=utf-8'
    }

    It 'Send-VsPhase reicht Unicode im Objektbody als untypisiertes byte[] weiter' {
        $captured = Invoke-InFileScope -Path $script:ClientCommon -Body {
            function Resolve-VsApi { return 'host:1' }
            function Write-VsClientLog { param($Message, $Level, $Context) }
            function Invoke-RestMethod {
                param($Uri, $Method, $TimeoutSec, $Headers, $Body, $ContentType)
                $script:capturedBody = [pscustomobject]@{
                    Type = $Body.GetType().FullName
                    Text = [Text.Encoding]::UTF8.GetString($Body)
                    Hex = ((@($Body) | ForEach-Object { ([byte]$_).ToString('x2') }) -join '')
                    ContentType = $ContentType
                }
                return [pscustomobject]@{ success = $true }
            }
            $detail = 'M' + [char]0x00FC + 'nchen ' + [char]0x6771 + [char]0x4EAC
            Send-VsPhase -Mac '00:11:22:33:44:55' -Phase 'staticip' -PhaseEvent 'started' -Detail $detail
            $script:capturedBody
        }
        $expected = Get-VsExpectedJsonBytes -Value (New-VsClientPhaseValue)
        $captured.Type | Should -Be 'System.Byte[]'
        $captured.Hex | Should -Be (Get-VsByteSignature -Value $expected).Hex
        $captured.ContentType | Should -Be 'application/json; charset=utf-8'
    }
}

Describe 'E1 vorbereitete Loopback-Abnahme der wirklich übertragenen Bytes' {
    It 'erfasst Invoke-VsApi <shape> unter der aktuellen Engine bytegenau hinter dem HTTP-Header' -ForEach @(
        @{ shape = 'object' }
        @{ shape = 'one' }
        @{ shape = 'many' }
    ) {
        $capture = $null
        $value = New-VsJsonTransportValue -Shape $shape
        try {
            $capture = Start-VsRawLoopbackCapture
            $bundle = @{ Port = $capture.Port; Value = $value }
            Invoke-InFileScope -Path $script:MecmCommon -Arguments @(,$bundle) -Body {
                param($case)
                $config = [pscustomobject]@{ WebApi = "127.0.0.1:$($case.Port)"; Scheme = 'http'; ReportToken = '' }
                Invoke-VsApi -Config $config -Path '/capture' -Method POST -Body $case.Value | Out-Null
            }
            $actual = Complete-VsRawLoopbackCapture -Capture $capture
            (Get-VsByteSignature -Value $actual).Hex | Should -Be (Get-VsByteSignature -Value (Get-VsExpectedJsonBytes -Value $value)).Hex
        } finally { Remove-VsRawLoopbackCapture -Capture $capture }
    }

    It 'erfasst Confirm-VsClientReady unter der aktuellen Engine bytegenau hinter dem HTTP-Header' {
        $capture = $null
        try {
            $capture = Start-VsRawLoopbackCapture
            Invoke-InFileScope -Path $script:ClientCommon -Arguments @($capture.Port) -Body {
                param($port)
                Confirm-VsClientReady -Api "127.0.0.1:$port" -Mac '00:11:22:33:44:55' -RolloutRevision 7
            }
            $actual = Complete-VsRawLoopbackCapture -Capture $capture
            $expected = Get-VsExpectedJsonBytes -Value (New-VsClientReadyValue)
            (Get-VsByteSignature -Value $actual).Hex | Should -Be (Get-VsByteSignature -Value $expected).Hex
        } finally { Remove-VsRawLoopbackCapture -Capture $capture }
    }

    It 'erfasst Send-VsPhase mit Unicode unter der aktuellen Engine bytegenau hinter dem HTTP-Header' {
        $capture = $null
        try {
            $capture = Start-VsRawLoopbackCapture
            Invoke-InFileScope -Path $script:ClientCommon -Arguments @($capture.Port) -Body {
                param($port)
                function Resolve-VsApi { return "127.0.0.1:$port" }
                function Write-VsClientLog { param($Message, $Level, $Context) }
                $detail = 'M' + [char]0x00FC + 'nchen ' + [char]0x6771 + [char]0x4EAC
                Send-VsPhase -Mac '00:11:22:33:44:55' -Phase 'staticip' -PhaseEvent 'started' -Detail $detail
            }
            $actual = Complete-VsRawLoopbackCapture -Capture $capture
            $expected = Get-VsExpectedJsonBytes -Value (New-VsClientPhaseValue)
            (Get-VsByteSignature -Value $actual).Hex | Should -Be (Get-VsByteSignature -Value $expected).Hex
        } finally { Remove-VsRawLoopbackCapture -Capture $capture }
    }
}
