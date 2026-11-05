# U10: run the actual wrapper with isolated files and mocked registry/children.
# No registry provider or child payload is reached by these tests.
BeforeAll {
    $script:Template = Join-Path (Split-Path (Split-Path $PSScriptRoot -Parent) -Parent) 'Powershell-MECM/Package_Vorlage/install.ps1'
    $script:ReporterClients = Join-Path (Split-Path (Split-Path $PSScriptRoot -Parent) -Parent) 'Powershell-MECM/clients'
    function PowerShell.exe {
        param([switch]$NoProfile, [string]$ExecutionPolicy, [switch]$NonInteractive, [string]$File)
        throw 'A child process must be mocked.'
    }
    # Pester can only mock a command that exists; pwsh on Linux has no Get-Acl.
    if (-not (Get-Command Get-Acl -ErrorAction SilentlyContinue)) {
        function Get-Acl { param($LiteralPath, $Path, $ErrorAction) }
    }
    function Invoke-RepairWrapper {
        $previousLocation = Get-Location
        $wrapperLog = Join-Path $script:PackageRoot 'wrapper.log'
        try {
            & (Join-Path $script:PackageRoot 'install.ps1') *> $wrapperLog
            $script:WrapperExit = $LASTEXITCODE
        } finally {
            Set-Location $previousLocation
        }
        if ($script:WrapperExit -ne 0) {
            $diagnostic = Get-Content -LiteralPath $wrapperLog -Raw -ErrorAction SilentlyContinue
            Write-Host "PackageRepair wrapper diagnostic (exit $($script:WrapperExit)):`n$diagnostic"
        }
    }
    function Set-RepairStepMarker {
        param([string]$Name)
        $hash = (Get-FileHash -LiteralPath (Join-Path $script:StepsRoot $Name) -Algorithm SHA256).Hash.ToLowerInvariant()
        $global:VirtuSpherePackageRepairFixture.Registry["RepairFixture-$Name"] = "Erfolg:$hash - previous run"
    }
    function Add-RepairReporterBundle {
        param([AllowNull()][int]$LoopbackPort = 0)
        $reporting = Join-Path $script:PackageRoot 'reporting'
        $null = New-Item -ItemType Directory -Path $reporting -Force
        $names = @('VirtuSphere-Client-Common.ps1', 'VirtuSphere-Client-Logging.ps1',
            'VirtuSphere-Package-Reporter.ps1', 'VirtuSphere-Package-ReporterHost.ps1') | Sort-Object
        $staging = Join-Path $reporting 'staging'
        $null = New-Item -ItemType Directory -Path $staging
        foreach ($name in $names) {
            Copy-Item -LiteralPath (Join-Path $script:ReporterClients $name) -Destination (Join-Path $staging $name)
        }
        # The synthetic Common can address only this test's loopback listener;
        # neither case can send to a configured real server on the test host.
        if ($LoopbackPort -gt 0) {
            $override = @'
function Get-VsPackageReportSnapshot {
    return [pscustomobject]@{
        MacCandidates = @('00:50:56:AA:BB:CC')
        RolloutRevision = [int]3
        DeviceGeneration = '018f2f49-5e41-4d55-8f05-8f55a5334102'
        AcceptanceGeneration = '018f2f49-5e41-4d55-8f05-8f55a5334103'
    }
}
function Get-VsPackageReportApiConfiguration {
    return [pscustomobject]@{
        Api = '127.0.0.1:__PORT__'
        Scheme = 'http'
        CertThumbprint = ''
        ReportUrl = 'http://127.0.0.1:__PORT__/mecm_report.php?action=reportPackageRun'
    }
}
'@.Replace('__PORT__', [string]$LoopbackPort)
        } else {
            $override = 'function Get-VsPackageReportSnapshot { return $null }'
        }
        Add-Content -LiteralPath (Join-Path $staging 'VirtuSphere-Client-Common.ps1') -Value $override
        $entries = @($names | ForEach-Object {
            $item = Get-Item -LiteralPath (Join-Path $staging $_)
            [pscustomobject]@{
                path = $_
                length = [long]$item.Length
                sha256 = (Get-FileHash -LiteralPath $item.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
            }
        })
        $basis = @($entries | ForEach-Object { '{0}|{1}|{2}' -f $_.path, $_.length, $_.sha256 }) -join "`n"
        $sha = [Security.Cryptography.SHA256]::Create()
        try {
            $bundleId = ([BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($basis))).Replace('-', '')).ToLowerInvariant()
        } finally { $sha.Dispose() }
        $bundleRoot = Join-Path $reporting $bundleId
        $reportingPrefix = [IO.Path]::GetFullPath($reporting).TrimEnd('\') + '\'
        if (-not [IO.Path]::GetFullPath($staging).StartsWith($reportingPrefix, [StringComparison]::OrdinalIgnoreCase) -or
            -not [IO.Path]::GetFullPath($bundleRoot).StartsWith($reportingPrefix, [StringComparison]::OrdinalIgnoreCase)) {
            throw 'Synthetic reporter move left its TestDrive reporting root.'
        }
        Move-Item -LiteralPath $staging -Destination $bundleRoot
        $contracts = [ordered]@{ common = 1; logging = 1; adapter = 1; host = 1 }
        $manifest = [ordered]@{ schema_version = 1; bundle_id = $bundleId; contracts = $contracts; files = $entries }
        $descriptor = [ordered]@{
            schema_version = 1
            bundle_id = $bundleId
            wrapper_sha256 = (Get-FileHash -LiteralPath (Join-Path $script:PackageRoot 'install.ps1') -Algorithm SHA256).Hash.ToLowerInvariant()
            contracts = $contracts
        }
        [IO.File]::WriteAllText((Join-Path $bundleRoot 'manifest.json'), (($manifest | ConvertTo-Json -Depth 5) + "`n"), (New-Object Text.UTF8Encoding($false)))
        [IO.File]::WriteAllText((Join-Path $reporting 'current.json'), (($descriptor | ConvertTo-Json -Depth 4) + "`n"), (New-Object Text.UTF8Encoding($false)))
    }
    # The synthetic loopback endpoint of the reporter cases (test scaffolding, compiled
    # once per process in the type block below). KB3 (05.11.2026), measured: the old
    # receiver was a Start-Job powershell.exe that polled every 25 ms, read the request
    # byte by byte in script and answered through ConvertFrom-Json/ConvertTo-Json. Its
    # first answer took about 170 ms at idle, inside the product's 2000 ms per-exchange
    # deadline, which left about 300 ms of slack: a receiver delayed by 1.5 s already
    # ended reporting with ipc_unconfirmed, the DG1 red "event 1 did not arrive".
    # The stall case slept a fixed 8 s and the test then waited a fixed 10 s for the
    # job; 4.5 s of job overhead reproduced the DG1 red with the product's own
    # numbers fulfilled. This receiver records every request before it answers (so a
    # confirmation the product logged implies the record), answers from a warmed
    # thread, and in stall mode never answers while the run lasts. No case waits on a
    # clock for it any more. The product budgets and every assertion are unchanged.
    function Start-RepairReportServer {
        param([ValidateSet('ack', 'backpressure', 'stall')][string]$Mode = 'ack')
        $receiver = New-Object VirtuSphereTests.RepairReportReceiver -ArgumentList $Mode
        return [pscustomobject]@{ Port = $receiver.Port; Receiver = $receiver }
    }
    function Stop-RepairReportServer {
        param($Server)
        if ($null -ne $Server) { $Server.Receiver.Dispose() }
    }
    # What reached the receiver during the run. Called after the wrapper returned: its
    # reporter child is gone with the job object then, so nothing can arrive later.
    function Get-RepairReportEvents {
        param([Parameter(Mandatory)]$Server, [string]$Because = '')
        $Server.Receiver.Stop()
        $errors = @($Server.Receiver.Errors)
        if ($errors.Count -gt 0) { throw ('Synthetic report receiver failed: {0} ({1})' -f ($errors -join '; '), $Because) }
        return @($Server.Receiver.Bodies | ForEach-Object {
            $body = $_ | ConvertFrom-Json
            [pscustomobject]@{ event = $body.event; seq = $body.event_seq; body = $body }
        })
    }
    function Get-RepairReportingDiagnostic {
        param([long]$WrapperWallMs)
        # Reads only what the product already logs. It must never throw, because it runs
        # before the assertions it explains.
        try {
            $logRoot = Join-Path $script:PackageRoot 'VirtuSphere\Logs\PackageWrapper'
            $log = Get-ChildItem -LiteralPath $logRoot -Filter 'reporting_*.log' -File -Recurse -ErrorAction SilentlyContinue |
                Select-Object -First 1
            if ($null -eq $log) { return "no reporting log found; wrapper_wall_ms=$WrapperWallMs" }
            $records = @(Get-Content -LiteralPath $log.FullName -ErrorAction Stop | ForEach-Object { $_ | ConvertFrom-Json })
            $disabled = @($records | Where-Object event -eq 'reporting_disabled')
            $budget = @($records | Where-Object event -eq 'reporting_budget')
            $reasonPart = if ($disabled.Count -gt 0) { "reporting_disabled=$($disabled[0].reason)" } else { 'reporting_disabled=(no record)' }
            $timingPart = if ($budget.Count -gt 0) {
                $b = $budget[0]
                "reporting_budget: active_ms=$($b.active_ms), verifier_ms=$($b.verifier_ms), host_start_ms=$($b.host_start_ms), ipc_ms=$($b.ipc_ms), teardown_ms=$($b.teardown_ms)"
            } else { 'reporting_budget=(no record)' }
            # A start or budget reason means the host was too slow for a product
            # limit. One bare child start says how slow: idle it takes 1.3 to 1.5 s
            # (KB1, 31.10.2026). The full path bypasses the PowerShell.exe guard
            # function above, which exists only to catch an unmocked wrapper child.
            $hostPart = ''
            if ($disabled.Count -gt 0 -and $disabled[0].reason -in @('bundle_unavailable', 'supervisor_unavailable', 'budget_exhausted')) {
                $bare = [Diagnostics.Stopwatch]::StartNew()
                $null = & (Join-Path ([Environment]::GetFolderPath('System')) 'WindowsPowerShell\v1.0\powershell.exe') -NoProfile -NonInteractive -Command 'exit 0'
                $bare.Stop()
                $hostPart = "; bare_child_start_ms=$($bare.ElapsedMilliseconds)"
            }
            return "$reasonPart; $timingPart; wrapper_wall_ms=$WrapperWallMs$hostPart"
        } catch {
            return "reporting log unreadable ($($_.Exception.Message)); wrapper_wall_ms=$WrapperWallMs"
        }
    }

    # Identitaetshash des Wrappers (SHA-256 der laengenpraefixierten UTF-8-Bytes), damit
    # der Test den verwalteten Ordner genau dort vorbereiten kann, wo der Lauf schreibt.
    $script:TemplateTokensForIdentity = $null
    $script:TemplateErrorsForIdentity = $null
    $script:TemplateAstForIdentity = [System.Management.Automation.Language.Parser]::ParseFile($script:Template, [ref]$script:TemplateTokensForIdentity, [ref]$script:TemplateErrorsForIdentity)
    $identityDefinition = @($script:TemplateAstForIdentity.FindAll({ param($n)
        $n -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $n.Name -eq 'Get-VsPackageIdentityHash'
    }, $true))
    if ($identityDefinition.Count -ne 1) { throw "Get-VsPackageIdentityHash in der Vorlage nicht eindeutig ($($identityDefinition.Count))." }
    . ([scriptblock]::Create($identityDefinition[0].Extent.Text))

    # KB1 (31.10.2026): the wrapper compiles its job-object type once per process
    # (Add-Type in Start-VsPackageReporterJobProcess). All test files share one
    # Pester process and this file is the first to start a supervised child, so its
    # first reporter case paid the compile inside the product's verifier budget:
    # the first supervised verify of a process took 0.7 to 1.7 s longer than the
    # later ones (idle 3.2 to 3.7 s against 2.4 to 3.1 s). On 29.10. that case was
    # the only red reporter case. Compiling the wrapper's own type definition here,
    # outside every timed case, gives each case the same start. Budgets and
    # assertions are unchanged: a host too slow for them stays red, and the
    # diagnostic names the limit and the bare child start time.
    if ([Environment]::OSVersion.Platform -eq [PlatformID]::Win32NT -and
            -not ('VirtuSphere.PackageReporterJobProcess' -as [type])) {
        $addType = @($script:TemplateAstForIdentity.FindAll({ param($n)
            $n -is [System.Management.Automation.Language.CommandAst] -and $n.GetCommandName() -eq 'Add-Type'
        }, $true))
        if ($addType.Count -ne 1) { throw "Add-Type in der Vorlage nicht eindeutig ($($addType.Count))." }
        $elements = @($addType[0].CommandElements)
        $definitionIndex = -1
        for ($i = 0; $i -lt $elements.Count; $i++) {
            if ($elements[$i] -is [System.Management.Automation.Language.CommandParameterAst] -and
                    $elements[$i].ParameterName -eq 'TypeDefinition') { $definitionIndex = $i + 1 }
        }
        if ($definitionIndex -lt 1 -or $definitionIndex -ge $elements.Count -or
                $elements[$definitionIndex] -isnot [System.Management.Automation.Language.StringConstantExpressionAst]) {
            throw 'Add-Type -TypeDefinition der Vorlage ist kein konstanter Text.'
        }
        Add-Type -Language CSharp -ErrorAction Stop -TypeDefinition $elements[$definitionIndex].Value
    }

    # The receiver behind Start-RepairReportServer, compiled here like the job type
    # above: outside every timed case.
    if ([Environment]::OSVersion.Platform -eq [PlatformID]::Win32NT -and
            -not ('VirtuSphereTests.RepairReportReceiver' -as [type])) {
        Add-Type -Language CSharp -ErrorAction Stop -TypeDefinition @'
using System;
using System.Collections.Generic;
using System.IO;
using System.Net;
using System.Net.Sockets;
using System.Text;
using System.Text.RegularExpressions;
using System.Threading;

namespace VirtuSphereTests
{
    public sealed class RepairReportReceiver : IDisposable
    {
        private readonly TcpListener listener;
        private readonly string mode;
        private readonly Thread thread;
        private readonly List<string> bodies = new List<string>();
        private readonly List<string> errors = new List<string>();
        private readonly List<TcpClient> held = new List<TcpClient>();
        private int stopped;

        public RepairReportReceiver(string mode)
        {
            if (mode != "ack" && mode != "backpressure" && mode != "stall") { throw new ArgumentException("Unknown receiver mode " + mode); }
            this.mode = mode;
            // Pays JIT and regex setup once, before the product's deadlines start.
            string warm = "{\"run_id\":\"warmup\",\"event\":\"started\",\"event_seq\":1}";
            string request = "POST /warmup HTTP/1.1\r\nContent-Length: " + Encoding.UTF8.GetByteCount(warm) + "\r\n\r\n" + warm;
            Answer(new MemoryStream(Encoding.UTF8.GetBytes(request)), Stream.Null, false);
            listener = new TcpListener(IPAddress.Loopback, 0);
            listener.Start();
            Port = ((IPEndPoint)listener.LocalEndpoint).Port;
            thread = new Thread(Run);
            thread.IsBackground = true;
            thread.Priority = ThreadPriority.Highest;
            thread.Start();
        }

        public int Port { get; private set; }
        public string[] Bodies { get { lock (bodies) { return bodies.ToArray(); } } }
        public string[] Errors { get { lock (errors) { return errors.ToArray(); } } }

        private void Run()
        {
            while (true)
            {
                TcpClient client;
                try { client = listener.AcceptTcpClient(); }
                catch (SocketException) { return; }
                catch (ObjectDisposedException) { return; }
                catch (InvalidOperationException) { return; }
                bool hold = false;
                try
                {
                    // Safety bounds only; no case waits for them.
                    client.ReceiveTimeout = 30000;
                    client.SendTimeout = 30000;
                    NetworkStream stream = client.GetStream();
                    hold = Answer(stream, stream, true);
                }
                catch (Exception error)
                {
                    lock (errors) { errors.Add(error.GetType().Name + ": " + error.Message); }
                }
                if (hold)
                {
                    lock (held)
                    {
                        if (Thread.VolatileRead(ref stopped) == 0) { held.Add(client); client = null; }
                    }
                }
                if (client != null) { client.Close(); }
            }
        }

        // Returns true when the connection is held open without an answer (stall).
        private bool Answer(Stream input, Stream output, bool record)
        {
            List<byte> header = new List<byte>();
            while (true)
            {
                int next = input.ReadByte();
                if (next < 0) { throw new IOException("Request header ended early."); }
                header.Add((byte)next);
                int count = header.Count;
                if (count >= 4 && header[count - 4] == 13 && header[count - 3] == 10 &&
                    header[count - 2] == 13 && header[count - 1] == 10) { break; }
                if (count >= 8192) { throw new IOException("Request header too large."); }
            }
            Match length = Regex.Match(Encoding.ASCII.GetString(header.ToArray()), @"(?im)^Content-Length:\s*(\d+)\s*$");
            if (!length.Success) { throw new IOException("Missing Content-Length."); }
            int size = int.Parse(length.Groups[1].Value);
            if (size < 1 || size > 65536) { throw new IOException("Request size out of bounds."); }
            byte[] payload = new byte[size];
            int offset = 0;
            while (offset < size)
            {
                int read = input.Read(payload, offset, size - offset);
                if (read <= 0) { throw new IOException("Request body ended early."); }
                offset += read;
            }
            string body = Encoding.UTF8.GetString(payload);
            // Recorded before any answer: a confirmation the product logged implies this record.
            if (record) { lock (bodies) { bodies.Add(body); } }
            if (mode == "stall") { return true; }
            string status;
            string extra = "";
            string response;
            if (mode == "ack")
            {
                response = "{\"schema_version\":1,\"run_id\":\"" + Field(body, "run_id", true) +
                    "\",\"event\":\"" + Field(body, "event", true) +
                    "\",\"event_seq\":" + Field(body, "event_seq", false) +
                    ",\"accepted\":true,\"deduplicated\":false}";
                status = "200 OK";
            }
            else
            {
                response = "{}";
                status = "503 Service Unavailable";
                extra = "Retry-After: 30\r\n";
            }
            byte[] responseBytes = Encoding.UTF8.GetBytes(response);
            byte[] head = Encoding.ASCII.GetBytes("HTTP/1.1 " + status +
                "\r\nContent-Type: application/json; charset=utf-8\r\nContent-Length: " + responseBytes.Length +
                "\r\n" + extra + "Connection: close\r\n\r\n");
            output.Write(head, 0, head.Length);
            output.Write(responseBytes, 0, responseBytes.Length);
            output.Flush();
            return false;
        }

        // The ack echoes three fields of the product's JSON as sent. A wrong echo is
        // refused by the product, so it can only turn a case red. The cases read the
        // whole body with ConvertFrom-Json after the run.
        private static string Field(string body, string name, bool quoted)
        {
            string value = quoted ? "\"((?:[^\"\\\\]|\\\\.)*)\"" : "(-?\\d+)";
            Match match = Regex.Match(body, "\"" + Regex.Escape(name) + "\"\\s*:\\s*" + value);
            if (!match.Success) { throw new InvalidDataException("Report body has no " + name + "."); }
            return match.Groups[1].Value;
        }

        public void Stop()
        {
            if (Interlocked.Exchange(ref stopped, 1) != 0) { return; }
            listener.Stop();
            lock (held)
            {
                foreach (TcpClient client in held) { client.Close(); }
                held.Clear();
            }
            thread.Join(5000);
        }

        public void Dispose() { Stop(); }
    }
}
'@
    }
}

# Discovery-time capability flag, like $HasRegistry in VirtuSphere.ErrorPaths.Tests.ps1.
# The wrapper enables its managed logs only after a Windows ACL check and runs
# the reporter as a powershell.exe child inside a Win32 job object. pwsh on
# Linux has neither, so those cases are defined only on Windows, where the
# PS-5.1 CI job proves them.
$IsWindowsHost = [Environment]::OSVersion.Platform -eq [PlatformID]::Win32NT
# PS-13: der geschuetzte Unterbau gibt nur SYSTEM und Administratoren Zugriff. Ein
# Lauf, der die Logs wirklich schreiben laesst, braucht daher eine erhoehte Sitzung
# (Administratoren-SID aktiv im Token) oder SYSTEM, wie MECM sie hat.
$IsElevatedHost = $false
if ($IsWindowsHost) {
    $IsElevatedHost = (New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

Describe 'Package repair invalidates only an actual hash-miss before child execution' {
    BeforeEach {
        $script:PackageRoot = Join-Path $TestDrive ([guid]::NewGuid().ToString('N'))
        $script:StepsRoot = Join-Path $script:PackageRoot 'powershell'
        $null = New-Item -ItemType Directory -Path $script:StepsRoot -Force
        Copy-Item -LiteralPath $script:Template -Destination (Join-Path $script:PackageRoot 'install.ps1')
        Set-Content -LiteralPath (Join-Path $script:PackageRoot 'config.json') -Encoding UTF8 -Value '{"ProjectName":"RepairFixture","version":"1","InstallationBehaviorType":"InstallForSystem","ErrorAction":"Stop"}'
        Set-Content -LiteralPath (Join-Path $script:StepsRoot '01.ps1') -Encoding UTF8 -Value '# first payload'
        Set-Content -LiteralPath (Join-Path $script:StepsRoot '02.ps1') -Encoding UTF8 -Value '# second payload'
        $global:VirtuSpherePackageRepairFixture = @{
            Registry = @{ Version = '1' }
            Children = [System.Collections.Generic.List[string]]::new()
            ChildCodes = @{ '01.ps1' = 0; '02.ps1' = 0 }
            RemoveCount = 0
            RegistryReadFails = $false
            RegistryRemoveFails = $false
            ChildThrows = $false
            MarkerWriteFails = $false
        }
        $script:OldProgramData = $env:ProgramData
        $script:OldLastExitCode = Get-Variable -Name LASTEXITCODE -Scope Global -ErrorAction SilentlyContinue
        $script:HadLastExitCode = $null -ne $script:OldLastExitCode
        $script:OldLastExitValue = if ($script:HadLastExitCode) { $script:OldLastExitCode.Value } else { $null }
        $env:ProgramData = $script:PackageRoot
        # Der verwaltete Unterbau liegt vor dem Lauf bereit, mit der geerbten ACL dieses
        # Testordners (dem Testbenutzer gehoerend). Der geschuetzte Anlagepfad (SYSTEM und
        # Administratoren) wird nur im PS-13-Block geprueft: er braucht eine erhoehte
        # Sitzung, und ein geschuetzter Ordner unter TestDrive laesst sich ohne sie nicht
        # wieder entfernen. Die Logtests hier pruefen die Protokollierung selbst.
        $null = New-Item -ItemType Directory -Force -Path (Join-Path $script:PackageRoot ('VirtuSphere\Logs\PackageWrapper\' + (Get-VsPackageIdentityHash -ProjectName 'RepairFixture' -Version '1')))
        Set-RepairStepMarker '01.ps1'
        Set-RepairStepMarker '02.ps1'

        Mock Test-Path {
            $fileSystemPath = if ($LiteralPath) { [string]$LiteralPath } else { [string]$Path }
            if (-not [System.IO.Path]::IsPathRooted($fileSystemPath)) {
                $fileSystemPath = Join-Path (Get-Location).Path $fileSystemPath
            }
            [System.IO.File]::Exists($fileSystemPath) -or [System.IO.Directory]::Exists($fileSystemPath)
        }
        Mock Test-Path { $true } -ParameterFilter { $Path -like 'HKLM:*' }
        Mock Get-ItemPropertyValue { $global:VirtuSpherePackageRepairFixture.Registry[$Name] }
        Mock Get-ItemProperty {
            if ($global:VirtuSpherePackageRepairFixture.RegistryReadFails) { throw 'registry read denied' }
            [pscustomobject]$global:VirtuSpherePackageRepairFixture.Registry
        }
        Mock Remove-ItemProperty {
            if ($global:VirtuSpherePackageRepairFixture.RegistryRemoveFails) { throw 'registry delete denied' }
            $valueNames = @($Name)
            $valueNames.Count | Should -Be 1
            $valueNames[0] | Should -Be 'Version'
            $global:VirtuSpherePackageRepairFixture.RemoveCount++
            $global:VirtuSpherePackageRepairFixture.Registry.Remove([string]$valueNames[0])
        }
        Mock Set-ItemProperty {
            if ($global:VirtuSpherePackageRepairFixture.MarkerWriteFails) { throw 'registry write denied' }
            $global:VirtuSpherePackageRepairFixture.Registry[$Name] = $Value
        }
        Mock PowerShell.exe {
            # This assertion pins the mutation boundary, not just final state.
            $global:VirtuSpherePackageRepairFixture.Registry.ContainsKey('Version') | Should -BeFalse
            $name = Split-Path $File -Leaf
            $global:VirtuSpherePackageRepairFixture.Children.Add($name)
            if ($global:VirtuSpherePackageRepairFixture.ChildThrows) { throw 'child launch failed' }
            $global:LASTEXITCODE = $global:VirtuSpherePackageRepairFixture.ChildCodes[$name]
        }
    }

    AfterEach {
        $env:ProgramData = $script:OldProgramData
        Remove-Variable -Name VirtuSpherePackageRepairFixture -Scope Global -ErrorAction SilentlyContinue
        if ($script:HadLastExitCode) {
            Set-Variable -Name LASTEXITCODE -Scope Global -Value $script:OldLastExitValue
        } else {
            Remove-Variable -Name LASTEXITCODE -Scope Global -ErrorAction SilentlyContinue
        }
    }

    It 'preserves detection and does not launch children for exact successful hashes' {
        Invoke-RepairWrapper
        $script:WrapperExit | Should -Be 0
        $global:VirtuSpherePackageRepairFixture.Children.Count | Should -Be 0
        $global:VirtuSpherePackageRepairFixture.RemoveCount | Should -Be 0
        $global:VirtuSpherePackageRepairFixture.Registry.Version | Should -Be '1'
    }

    # Windows only: managed logs (ACL check) or the job-bound reporter child.
    if ($IsWindowsHost) {
        It 'writes a paired UTF-8 BOM run record with skips and one completed summary' {
            Invoke-RepairWrapper
            $script:WrapperExit | Should -Be 0

            $runLogs = @(Get-ChildItem -LiteralPath (Join-Path $script:PackageRoot 'VirtuSphere\Logs\PackageWrapper') -File -Recurse)
            @($runLogs | Where-Object Name -like 'wrapper_*.log').Count | Should -Be 1
            @($runLogs | Where-Object Name -like 'reporting_*.log').Count | Should -Be 1
            foreach ($file in $runLogs) {
                $bytes = [IO.File]::ReadAllBytes($file.FullName)
                @($bytes[0..2]) | Should -Be @(0xef, 0xbb, 0xbf)
            }

            $wrapper = $runLogs | Where-Object Name -like 'wrapper_*.log' | Select-Object -First 1
            $reporting = $runLogs | Where-Object Name -like 'reporting_*.log' | Select-Object -First 1
            $wrapperRecords = @(Get-Content -LiteralPath $wrapper.FullName | ForEach-Object { $_ | ConvertFrom-Json })
            $reportingRecords = @(Get-Content -LiteralPath $reporting.FullName | ForEach-Object { $_ | ConvertFrom-Json })
            @($wrapperRecords.event) | Should -Be @('header', 'inventory', 'step_run', 'step_result', 'step_run', 'step_result', 'completed')
            @($wrapperRecords | Where-Object event -eq 'step_result').outcome | Should -Be @('SKIP', 'SKIP')
            $completed = $wrapperRecords | Where-Object event -eq 'completed'
            $completed.exit_code | Should -Be 0
            $completed.detection_status | Should -Be 'written'
            $completed.total | Should -Be 2
            $completed.processed | Should -Be 2
            $completed.skip | Should -Be 2
            @($reportingRecords.event) | Should -Be @('header', 'reporting_disabled', 'reporting_budget')
            $reportingRecords[1].reason | Should -Be 'bundle_unavailable'
            $reportingRecords[2].active_ms | Should -BeGreaterOrEqual 0
            $wrapperRecords[0].run_id | Should -Be $reportingRecords[0].run_id
            $wrapperRecords[0].partner_file | Should -Be $reporting.Name
            $reportingRecords[0].partner_file | Should -Be $wrapper.Name
        }

        It 'preserves detection and exit when a verified worker lacks a published identity' {
            Add-RepairReporterBundle
            $watch = [Diagnostics.Stopwatch]::StartNew()
            Invoke-RepairWrapper
            $watch.Stop()
            $diag = Get-RepairReportingDiagnostic -WrapperWallMs $watch.ElapsedMilliseconds
            $script:WrapperExit | Should -Be 0
            $reporting = Get-ChildItem -LiteralPath (Join-Path $script:PackageRoot 'VirtuSphere\Logs\PackageWrapper') `
                -Filter 'reporting_*.log' -File -Recurse | Select-Object -First 1
            $records = @(Get-Content -LiteralPath $reporting.FullName | ForEach-Object { $_ | ConvertFrom-Json })
            @($records.event) | Should -Be @('header', 'report_attempt', 'reporting_disabled', 'reporting_budget') -Because $diag
            $records[1].report_event | Should -Be 'started'
            $records[1].attempted | Should -BeFalse
            $records[1].reason | Should -Be 'identity_unavailable'
            $records[2].reason | Should -Be 'identity_unavailable'
            $records[3].active_ms | Should -BeLessThan 10000
        }

        It 'sends started, both skips and completion once to a synthetic loopback receiver' {
            $server = Start-RepairReportServer
            try {
                Add-RepairReporterBundle -LoopbackPort $server.Port
                $watch = [Diagnostics.Stopwatch]::StartNew()
                Invoke-RepairWrapper
                $watch.Stop()
                $diag = Get-RepairReportingDiagnostic -WrapperWallMs $watch.ElapsedMilliseconds
                $script:WrapperExit | Should -Be 0
                $events = @(Get-RepairReportEvents -Server $server -Because $diag)
                @($events.event) | Should -Be @('started', 'step_result', 'step_result', 'completed') -Because $diag
                @($events.seq) | Should -Be @(1, 2, 3, 4)
                $events[1].body.result | Should -Be 'skip'
                $events[2].body.result | Should -Be 'skip'
                $events[3].body.wrapper_result | Should -Be 'ok'
                $events[3].body.processed_count | Should -Be 2
                $events[3].body.skip_count | Should -Be 2
                $events[3].body.first_failure | Should -BeNullOrEmpty
                $reporting = Get-ChildItem -LiteralPath (Join-Path $script:PackageRoot 'VirtuSphere\Logs\PackageWrapper') `
                    -Filter 'reporting_*.log' -File -Recurse | Select-Object -First 1
                $records = @(Get-Content -LiteralPath $reporting.FullName | ForEach-Object { $_ | ConvertFrom-Json })
                @($records | Where-Object event -eq 'report_attempt').Count | Should -Be 4
                @($records | Where-Object event -eq 'report_attempt' | ForEach-Object confirmed) | Should -Be @($true, $true, $true, $true)
                ($records | Where-Object event -eq 'reporting_budget').active_ms | Should -BeLessThan 10000
            } finally {
                Stop-RepairReportServer -Server $server
            }
        }

        It 'does not bypass server Retry-After for steps or completion' {
            $server = Start-RepairReportServer -Mode backpressure
            try {
                Add-RepairReporterBundle -LoopbackPort $server.Port
                $watch = [Diagnostics.Stopwatch]::StartNew()
                Invoke-RepairWrapper
                $watch.Stop()
                $diag = Get-RepairReportingDiagnostic -WrapperWallMs $watch.ElapsedMilliseconds
                $script:WrapperExit | Should -Be 0
                $received = @(Get-RepairReportEvents -Server $server -Because $diag)
                @($received.event) | Should -Be @('started') -Because $diag
                $reporting = Get-ChildItem -LiteralPath (Join-Path $script:PackageRoot 'VirtuSphere\Logs\PackageWrapper') `
                    -Filter 'reporting_*.log' -File -Recurse | Select-Object -First 1
                $records = @(Get-Content -LiteralPath $reporting.FullName | ForEach-Object { $_ | ConvertFrom-Json })
                $attempts = @($records | Where-Object event -eq 'report_attempt')
                @($attempts.report_event) | Should -Be @('started', 'step_result', 'step_result', 'completed')
                @($attempts.reason) | Should -Be @('http_status', 'backpressure', 'backpressure', 'backpressure')
                @($attempts.attempted) | Should -Be @($true, $false, $false, $false)
                ($records | Where-Object event -eq 'reporting_budget').active_ms | Should -BeLessThan 10000
            } finally {
                Stop-RepairReportServer -Server $server
            }
        }

        # The receiver holds the request open without any answer until the run is
        # over, so the response is late by construction, not by a sleep that has to
        # outlast the deadline on a busy host.
        It 'keeps package success when a report response stalls beyond the IPC deadline' {
            $server = Start-RepairReportServer -Mode stall
            try {
                Add-RepairReporterBundle -LoopbackPort $server.Port
                $watch = [Diagnostics.Stopwatch]::StartNew()
                Invoke-RepairWrapper
                $watch.Stop()
                # Read before the assertions below: a silent receiver is explained by the product's own disable reason and phase timings.
                $diag = Get-RepairReportingDiagnostic -WrapperWallMs $watch.ElapsedMilliseconds
                $script:WrapperExit | Should -Be 0
                $watch.ElapsedMilliseconds | Should -BeLessThan 10000 -Because $diag
                $received = @(Get-RepairReportEvents -Server $server -Because $diag)
                @($received.event) | Should -Be @('started') -Because $diag
                $reporting = Get-ChildItem -LiteralPath (Join-Path $script:PackageRoot 'VirtuSphere\Logs\PackageWrapper') `
                    -Filter 'reporting_*.log' -File -Recurse | Select-Object -First 1
                $records = @(Get-Content -LiteralPath $reporting.FullName | ForEach-Object { $_ | ConvertFrom-Json })
                ($records | Where-Object event -eq 'reporting_disabled').reason | Should -Be 'ipc_unconfirmed'
                ($records | Where-Object event -eq 'reporting_budget').active_ms | Should -BeLessThan 10000
            } finally {
                Stop-RepairReportServer -Server $server
            }
        }

        It 'retains five paired run groups and leaves a locked older group intact' {
            1..5 | ForEach-Object { Invoke-RepairWrapper; $script:WrapperExit | Should -Be 0 }
            $logRoot = Join-Path $script:PackageRoot 'VirtuSphere\Logs\PackageWrapper'
            $groupsBefore = @(Get-ChildItem -LiteralPath $logRoot -File -Recurse | Group-Object { $_.BaseName -replace '^(wrapper|reporting)_', '' })
            $groupsBefore.Count | Should -Be 5
            $oldestWrapper = Get-ChildItem -LiteralPath $logRoot -Filter 'wrapper_*.log' -File -Recurse | Sort-Object Name | Select-Object -First 1
            $lock = New-Object IO.FileStream($oldestWrapper.FullName, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]::Read)
            try {
                Invoke-RepairWrapper
                $script:WrapperExit | Should -Be 0
                $groupsLocked = @(Get-ChildItem -LiteralPath $logRoot -File -Recurse | Group-Object { $_.BaseName -replace '^(wrapper|reporting)_', '' })
                $groupsLocked.Count | Should -Be 6
            } finally {
                $lock.Dispose()
            }
            Invoke-RepairWrapper
            $script:WrapperExit | Should -Be 0
            $groupsAfter = @(Get-ChildItem -LiteralPath $logRoot -File -Recurse | Group-Object { $_.BaseName -replace '^(wrapper|reporting)_', '' })
            $groupsAfter.Count | Should -Be 5
            @($groupsAfter | Where-Object Count -ne 2).Count | Should -Be 0
        }
    }

    It 'continues the package decision when the managed log ACL cannot be checked' {
        Mock Get-Acl { throw 'acl unavailable' }
        Invoke-RepairWrapper
        $script:WrapperExit | Should -Be 0
        $global:VirtuSpherePackageRepairFixture.Registry.Version | Should -Be '1'
        $global:VirtuSpherePackageRepairFixture.Children.Count | Should -Be 0
        @(Get-ChildItem -LiteralPath (Join-Path $script:PackageRoot 'VirtuSphere\Logs\PackageWrapper') -Recurse -File).Count | Should -Be 0 -Because 'der vorbereitete Unterbau darf bei abgelehnter Logwurzel keine Logdatei bekommen'
    }

    # Windows only: managed logs (ACL check) or the job-bound reporter child.
    if ($IsWindowsHost) {
        It 'accepts read-only Users access without disabling package logs' {
            $global:VirtuSpherePackageRepairFixture.LogAcl = [Security.AccessControl.DirectorySecurity]::new()
            $usersSid = [Security.Principal.SecurityIdentifier]::new('S-1-5-32-545')
            $readRule = [Security.AccessControl.FileSystemAccessRule]::new(
                $usersSid,
                [Security.AccessControl.FileSystemRights]::ReadAndExecute,
                [Security.AccessControl.InheritanceFlags]::ContainerInherit,
                [Security.AccessControl.PropagationFlags]::None,
                [Security.AccessControl.AccessControlType]::Allow
            )
            $global:VirtuSpherePackageRepairFixture.LogAcl.AddAccessRule($readRule)
            $global:VirtuSpherePackageRepairFixture.LogAcl.Access.Count | Should -Be 1
            Mock Get-Acl { $global:VirtuSpherePackageRepairFixture.LogAcl }

            Invoke-RepairWrapper
            $script:WrapperExit | Should -Be 0
            $logRoot = Join-Path $script:PackageRoot 'VirtuSphere\Logs\PackageWrapper'
            Get-Content -LiteralPath (Join-Path $script:PackageRoot 'wrapper.log') -Raw | Should -Not -Match 'deaktiviert'
            @(Get-ChildItem -LiteralPath $logRoot -File -Recurse).Count | Should -Be 2
        }

        It 'disables package logs for writable Users access without changing detection' {
            $global:VirtuSpherePackageRepairFixture.LogAcl = [Security.AccessControl.DirectorySecurity]::new()
            $usersSid = [Security.Principal.SecurityIdentifier]::new('S-1-5-32-545')
            $writeRule = [Security.AccessControl.FileSystemAccessRule]::new(
                $usersSid,
                [Security.AccessControl.FileSystemRights]::Write,
                [Security.AccessControl.InheritanceFlags]::ContainerInherit,
                [Security.AccessControl.PropagationFlags]::None,
                [Security.AccessControl.AccessControlType]::Allow
            )
            $global:VirtuSpherePackageRepairFixture.LogAcl.AddAccessRule($writeRule)
            Mock Get-Acl { $global:VirtuSpherePackageRepairFixture.LogAcl }

            Invoke-RepairWrapper
            $script:WrapperExit | Should -Be 0
            $global:VirtuSpherePackageRepairFixture.Registry.Version | Should -Be '1'
            @(Get-ChildItem -LiteralPath (Join-Path $script:PackageRoot 'VirtuSphere\Logs\PackageWrapper') -Recurse -File).Count | Should -Be 0 -Because 'der vorbereitete Unterbau darf bei abgelehnter Logwurzel keine Logdatei bekommen'
        }
    }

    It 'removes old detection before changed content, including failure and reboot <Code>' -TestCases @(
        @{ Code = 1; Expected = 1; Detected = $false }
        @{ Code = 1641; Expected = 1641; Detected = $false }
        @{ Code = 0; Expected = 0; Detected = $true }
        @{ Code = 3010; Expected = 3010; Detected = $true }
        @{ Code = 1707; Expected = 0; Detected = $true }
    ) {
        param($Code, $Expected, $Detected)
        Add-Content -LiteralPath (Join-Path $script:StepsRoot '02.ps1') -Value '# changed under same version'
        $global:VirtuSpherePackageRepairFixture.ChildCodes['02.ps1'] = $Code
        Invoke-RepairWrapper
        $script:WrapperExit | Should -Be $Expected
        @($global:VirtuSpherePackageRepairFixture.Children) | Should -Be @('02.ps1')
        $global:VirtuSpherePackageRepairFixture.RemoveCount | Should -Be 1
        $global:VirtuSpherePackageRepairFixture.Registry.ContainsKey('Version') | Should -Be $Detected
    }

    It 'resumes after 1641, skipping the completed hash and running the remaining step' {
        $global:VirtuSpherePackageRepairFixture.Registry.Remove('RepairFixture-01.ps1')
        $global:VirtuSpherePackageRepairFixture.Registry.Remove('RepairFixture-02.ps1')
        $global:VirtuSpherePackageRepairFixture.ChildCodes['01.ps1'] = 1641
        Invoke-RepairWrapper
        $script:WrapperExit | Should -Be 1641
        @($global:VirtuSpherePackageRepairFixture.Children) | Should -Be @('01.ps1')
        $global:VirtuSpherePackageRepairFixture.Registry.ContainsKey('Version') | Should -BeFalse

        Invoke-RepairWrapper
        $script:WrapperExit | Should -Be 0
        @($global:VirtuSpherePackageRepairFixture.Children) | Should -Be @('01.ps1', '02.ps1')
        $global:VirtuSpherePackageRepairFixture.Registry.Version | Should -Be '1'
        $global:VirtuSpherePackageRepairFixture.RemoveCount | Should -Be 1
    }

    It 'keeps failed repair undetected with Continue even when the next child succeeds' {
        Set-Content -LiteralPath (Join-Path $script:PackageRoot 'config.json') -Encoding UTF8 -Value '{"ProjectName":"RepairFixture","version":"1","InstallationBehaviorType":"InstallForSystem","ErrorAction":"Continue"}'
        $global:VirtuSpherePackageRepairFixture.Registry.Remove('RepairFixture-01.ps1')
        $global:VirtuSpherePackageRepairFixture.Registry.Remove('RepairFixture-02.ps1')
        $global:VirtuSpherePackageRepairFixture.ChildCodes['01.ps1'] = 1
        Invoke-RepairWrapper
        $script:WrapperExit | Should -Be 1
        @($global:VirtuSpherePackageRepairFixture.Children) | Should -Be @('01.ps1', '02.ps1')
        $global:VirtuSpherePackageRepairFixture.Registry.ContainsKey('Version') | Should -BeFalse
    }

    It 'retries a failed hash while preserving the other completed step' {
        Add-Content -LiteralPath (Join-Path $script:StepsRoot '02.ps1') -Value '# changed content'
        $global:VirtuSpherePackageRepairFixture.ChildCodes['02.ps1'] = 1
        Invoke-RepairWrapper
        $script:WrapperExit | Should -Be 1
        $global:VirtuSpherePackageRepairFixture.Registry.ContainsKey('Version') | Should -BeFalse

        $global:VirtuSpherePackageRepairFixture.ChildCodes['02.ps1'] = 0
        Invoke-RepairWrapper
        $script:WrapperExit | Should -Be 0
        @($global:VirtuSpherePackageRepairFixture.Children) | Should -Be @('02.ps1', '02.ps1')
        $global:VirtuSpherePackageRepairFixture.Registry.Version | Should -Be '1'
        $global:VirtuSpherePackageRepairFixture.RemoveCount | Should -Be 1
    }

    It 'fails closed before payload if marker invalidation cannot be established (<Fault>)' -TestCases @(
        @{ Fault = 'read' }
        @{ Fault = 'remove' }
    ) {
        param($Fault)
        $global:VirtuSpherePackageRepairFixture.Registry.Remove('RepairFixture-02.ps1')
        $global:VirtuSpherePackageRepairFixture.RegistryReadFails = $Fault -eq 'read'
        $global:VirtuSpherePackageRepairFixture.RegistryRemoveFails = $Fault -eq 'remove'
        Invoke-RepairWrapper
        $script:WrapperExit | Should -Be 1
        $global:VirtuSpherePackageRepairFixture.Children.Count | Should -Be 0
        $global:VirtuSpherePackageRepairFixture.Registry.Version | Should -Be '1'
    }

    It 'leaves detection absent on child launch or step-marker write failure (<Fault>)' -TestCases @(
        @{ Fault = 'launch' }
        @{ Fault = 'write' }
    ) {
        param($Fault)
        $global:VirtuSpherePackageRepairFixture.Registry.Remove('RepairFixture-02.ps1')
        $global:VirtuSpherePackageRepairFixture.ChildThrows = $Fault -eq 'launch'
        $global:VirtuSpherePackageRepairFixture.MarkerWriteFails = $Fault -eq 'write'
        Invoke-RepairWrapper
        $script:WrapperExit | Should -Be 1
        $global:VirtuSpherePackageRepairFixture.Registry.ContainsKey('Version') | Should -BeFalse
    }

    # PS-13 (Windows only): echte, geerbte ACLs statt Get-Acl-Mocks. Der Elternordner
    # gibt BUILTIN\Users Schreibrecht mit ContainerInherit weiter, wie C:\ProgramData.
    # Die Faelle beweisen: Logs entstehen trotz geerbtem Benutzerrecht, der verwaltete
    # Unterbau ist geschuetzt, und ein explizit gesetztes Benutzerrecht auf der Wurzel
    # wird weiter abgelehnt.
    if ($IsWindowsHost) {
        Context 'PS-13: geschuetzter Unterbau trotz geerbter ProgramData-Rechte' {
            BeforeEach {
                $script:InheritedParent = Join-Path $TestDrive ('pd-' + [guid]::NewGuid().ToString('N'))
                $null = New-Item -ItemType Directory -Path $script:InheritedParent
                $parentAcl = Get-Acl -LiteralPath $script:InheritedParent
                $parentAcl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new(
                    [Security.Principal.SecurityIdentifier]::new('S-1-5-32-545'),
                    [Security.AccessControl.FileSystemRights]::Write,
                    [Security.AccessControl.InheritanceFlags]::ContainerInherit,
                    [Security.AccessControl.PropagationFlags]::None,
                    [Security.AccessControl.AccessControlType]::Allow
                ))
                Set-Acl -LiteralPath $script:InheritedParent -AclObject $parentAcl
                $env:ProgramData = $script:InheritedParent
                $script:SharedLogRoot = Join-Path $script:InheritedParent 'VirtuSphere\Logs'
                $script:ManagedRoot = Join-Path $script:SharedLogRoot 'PackageWrapper'
            }

            It 'vererbt das Benutzerschreibrecht tatsaechlich auf die Logwurzel' {
                $null = New-Item -ItemType Directory -Path $script:SharedLogRoot -Force
                $usersRules = @((Get-Acl -LiteralPath $script:SharedLogRoot).Access | Where-Object {
                    $_.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value -eq 'S-1-5-32-545' -and
                    $_.IsInherited -and (($_.FileSystemRights -band [Security.AccessControl.FileSystemRights]::WriteData) -ne 0)
                })
                $usersRules.Count | Should -BeGreaterThan 0 -Because 'ohne geerbtes Benutzerrecht prueft der Test den Standardfall nicht'
            }

            Context 'mit erhoehter Sitzung oder SYSTEM: die Logs laufen' -Skip:(-not $IsElevatedHost) {
            It 'schaltet die Logs trotz geerbtem Benutzerrecht ein und schuetzt den verwalteten Unterbau' {
                Invoke-RepairWrapper
                $script:WrapperExit | Should -Be 0
                Get-Content -LiteralPath (Join-Path $script:PackageRoot 'wrapper.log') -Raw | Should -Not -Match 'deaktiviert'

                $identityDirs = @(Get-ChildItem -LiteralPath $script:ManagedRoot -Directory)
                $identityDirs.Count | Should -Be 1
                foreach ($dir in @($script:ManagedRoot, $identityDirs[0].FullName)) {
                    $acl = Get-Acl -LiteralPath $dir
                    $acl.AreAccessRulesProtected | Should -BeTrue -Because 'der verwaltete Unterbau erbt nichts von ProgramData'
                    $usersRules = @($acl.Access | Where-Object {
                        $_.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value -eq 'S-1-5-32-545'
                    })
                    $usersRules.Count | Should -Be 0 -Because "Benutzer haben auf $dir weder Schreib- noch Leserecht"
                }
                @(Get-ChildItem -LiteralPath $identityDirs[0].FullName -Filter 'wrapper_*.log' -File).Count | Should -Be 1
                @(Get-ChildItem -LiteralPath $identityDirs[0].FullName -Filter 'reporting_*.log' -File).Count | Should -Be 1
            }

            It 'bleibt beim zweiten Lauf aktiv, obwohl die Logwurzel das geerbte Recht weiter traegt' {
                Invoke-RepairWrapper
                $script:WrapperExit | Should -Be 0
                Invoke-RepairWrapper
                $script:WrapperExit | Should -Be 0
                Get-Content -LiteralPath (Join-Path $script:PackageRoot 'wrapper.log') -Raw | Should -Not -Match 'deaktiviert'
                $identityDir = @(Get-ChildItem -LiteralPath $script:ManagedRoot -Directory)[0].FullName
                @(Get-ChildItem -LiteralPath $identityDir -Filter 'wrapper_*.log' -File).Count | Should -Be 2
            }
            }

            It 'verweigert die Logwurzel bei einem explizit gesetzten Benutzerschreibrecht und aendert kein Recht' {
                $null = New-Item -ItemType Directory -Path $script:SharedLogRoot -Force
                $rootAcl = Get-Acl -LiteralPath $script:SharedLogRoot
                $rootAcl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new(
                    [Security.Principal.SecurityIdentifier]::new('S-1-5-32-545'),
                    [Security.AccessControl.FileSystemRights]::Write,
                    [Security.AccessControl.InheritanceFlags]::ContainerInherit,
                    [Security.AccessControl.PropagationFlags]::None,
                    [Security.AccessControl.AccessControlType]::Allow
                ))
                Set-Acl -LiteralPath $script:SharedLogRoot -AclObject $rootAcl

                Invoke-RepairWrapper
                $script:WrapperExit | Should -Be 0
                $global:VirtuSpherePackageRepairFixture.Registry.Version | Should -Be '1'
                Get-Content -LiteralPath (Join-Path $script:PackageRoot 'wrapper.log') -Raw | Should -Match 'deaktiviert'
                Test-Path -LiteralPath $script:ManagedRoot | Should -BeFalse
                $explicitUsers = @((Get-Acl -LiteralPath $script:SharedLogRoot).Access | Where-Object {
                    -not $_.IsInherited -and $_.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value -eq 'S-1-5-32-545'
                })
                $explicitUsers.Count | Should -Be 1 -Because 'die Wurzel wird nicht repariert, nur abgelehnt'
            }
        }
    }
}

# PS-13 auf Funktionsebene: die beiden Regeln aus der Vorlage (Logwurzel ohne
# explizites Benutzerrecht, geschuetzter Unterbau) mit echten geerbten ACLs. Diese
# Pruefung braucht keine erhoehte Sitzung, weil sie keinen Lauf schreiben laesst.
Describe 'PS-13: Logwurzel und geschuetzter Unterbau (Funktionsebene)' -Skip:(-not $IsWindowsHost) {
    BeforeAll {
        $tokens = $null
        $errors = $null
        $templateAst = [System.Management.Automation.Language.Parser]::ParseFile($script:Template, [ref]$tokens, [ref]$errors)
        foreach ($name in @('Test-VsPackageLogAccess', 'New-VsPackageManagedDirectory')) {
            $definition = @($templateAst.FindAll({ param($n)
                $n -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $n.Name -eq $name
            }, $true))
            if ($definition.Count -ne 1) { throw "$name in der Vorlage nicht eindeutig ($($definition.Count))." }
            . ([scriptblock]::Create($definition[0].Extent.Text))
        }
        function Add-TestUsersWriteRule {
            param([string]$Path)
            $acl = Get-Acl -LiteralPath $Path
            $acl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new(
                [Security.Principal.SecurityIdentifier]::new('S-1-5-32-545'),
                [Security.AccessControl.FileSystemRights]::Write,
                [Security.AccessControl.InheritanceFlags]::ContainerInherit,
                [Security.AccessControl.PropagationFlags]::None,
                [Security.AccessControl.AccessControlType]::Allow
            ))
            Set-Acl -LiteralPath $Path -AclObject $acl
        }
    }

    BeforeEach {
        $script:FnParent = Join-Path $TestDrive ('fn-' + [guid]::NewGuid().ToString('N'))
        $null = New-Item -ItemType Directory -Path $script:FnParent
    }

    AfterEach {
        # Der geschuetzte Testordner gehoert dem Testbenutzer, der ohne SYSTEM- oder
        # Administratorrecht nicht hineinsieht. Der Eigentuemer gibt sich seine Rechte
        # zurueck, damit TestDrive sich wieder entfernen laesst.
        if (Test-Path -LiteralPath $script:FnParent) {
            $me = [Security.Principal.WindowsIdentity]::GetCurrent().User.Value
            icacls $script:FnParent /grant ("*{0}:(OI)(CI)F" -f $me) /T /C | Out-Null
        }
    }

    It 'die geerbte Benutzer-Schreibrecht-ACL der Wurzel ist zulaessig, ein explizites Benutzerrecht nicht' {
        Add-TestUsersWriteRule -Path $script:FnParent
        $root = Join-Path $script:FnParent 'Logs'
        $null = New-Item -ItemType Directory -Path $root
        $inherited = @((Get-Acl -LiteralPath $root).Access | Where-Object { $_.IsInherited })
        $inherited.Count | Should -BeGreaterThan 0 -Because 'die Wurzel muss das Recht wirklich erben'
        Test-VsPackageLogAccess -Path $root -IgnoreInherited | Should -BeTrue
        Test-VsPackageLogAccess -Path $root | Should -BeFalse -Because 'ohne Ausnahme zaehlt das geerbte Recht wie frueher'
        Add-TestUsersWriteRule -Path $root
        Test-VsPackageLogAccess -Path $root -IgnoreInherited | Should -BeFalse -Because 'ein explizites Benutzerrecht ist fremde ACL'
    }

    It 'der verwaltete Unterbau hat eine geschuetzte ACL nur fuer SYSTEM und Administratoren' {
        Add-TestUsersWriteRule -Path $script:FnParent
        $managed = Join-Path $script:FnParent 'PackageWrapper'
        New-VsPackageManagedDirectory -Path $managed
        $acl = Get-Acl -LiteralPath $managed
        $acl.AreAccessRulesProtected | Should -BeTrue -Because 'keine Vererbung von der Elternebene'
        $sids = @($acl.Access | ForEach-Object { $_.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value } | Sort-Object)
        ($sids -join ',') | Should -Be 'S-1-5-18,S-1-5-32-544'
        Test-VsPackageLogAccess -Path $managed | Should -BeTrue
    }
}

# PS-19: die Paketvorlage liest config.json als UTF-8 wie der Server. Ohne -Encoding
# liest Windows PowerShell 5.1 eine BOM-lose Datei als ANSI, und Umlaute werden zu Mojibake.
Describe 'PS-19: config.json wird in Vorlage und Server als UTF-8 gelesen' {
    BeforeAll {
        $script:PsRoot19 = Join-Path (Split-Path (Split-Path $PSScriptRoot -Parent) -Parent) 'Powershell-MECM'
        $script:TemplateInstall = Join-Path (Join-Path $script:PsRoot19 'Package_Vorlage') 'install.ps1'
        $script:ServerCommonFile = Join-Path (Join-Path $script:PsRoot19 'mecm') 'VirtuSphere-Common.ps1'
        function Get-ConfigReadCommandTexts {
            param([string]$Path)
            $tokens = $null
            $errors = $null
            $ast = [System.Management.Automation.Language.Parser]::ParseFile($Path, [ref]$tokens, [ref]$errors)
            @($ast.FindAll({ param($n)
                $n -is [System.Management.Automation.Language.CommandAst] -and
                $n.GetCommandName() -eq 'Get-Content' -and $n.Extent.Text -match '\$configPath'
            }, $true) | ForEach-Object { $_.Extent.Text })
        }
    }

    It 'die Paketvorlage liest config.json mit -Encoding UTF8' {
        $reads = @(Get-ConfigReadCommandTexts -Path $script:TemplateInstall)
        $reads.Count | Should -Be 1
        $reads[0] | Should -Match '-Encoding UTF8\b'
    }

    It 'der Server liest config.json ebenfalls mit -Encoding UTF8 (Read-VsPackageConfig)' {
        $reads = @(Get-ConfigReadCommandTexts -Path $script:ServerCommonFile)
        $reads.Count | Should -BeGreaterThan 0
        foreach ($read in $reads) { $read | Should -Match '-Encoding UTF8\b' }
    }

    It 'eine BOM-lose UTF-8-Datei mit Umlaut wird mit dieser Lesart unverfaelscht gelesen' {
        $dir = Join-Path $TestDrive ('cfg-' + [guid]::NewGuid().ToString('N'))
        $null = New-Item -ItemType Directory -Path $dir
        $path = Join-Path $dir 'config.json'
        # Umlaut per Codepoint, damit diese Testdatei ASCII bleibt.
        $umlaut = 'B' + [char]0x00FC + 'ro'
        [IO.File]::WriteAllText($path, ('{{"ProjectName":"{0}-App","version":"1"}}' -f $umlaut), (New-Object Text.UTF8Encoding($false)))
        $config = Get-Content -Path $path -Raw -Encoding UTF8 | ConvertFrom-Json
        $config.ProjectName | Should -Be ($umlaut + '-App')
    }
}
