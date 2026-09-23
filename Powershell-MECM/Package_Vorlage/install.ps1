# Kein param-Block: der frueher hier reservierte Schalter -repair wurde von
# niemandem gesetzt und von diesem Skript nie gelesen. Ein Parameter, der nichts
# tut, ist eine Zusage an den Paketautor, die das Skript nicht einhaelt.
# Aufgerufen wird immer parameterlos:
#   powershell.exe -NoProfile -ExecutionPolicy Bypass -NonInteractive -File install.ps1

# Set-StrictMode: ein vertippter Variablenname ist sonst ein stilles $null.
# Dieses Skript laeuft als SYSTEM und startet fremde Teilskripte; ein stilles
# $null entscheidet hier ueber Registry-Zweig und Detection-Wert. Version 1.0
# aus demselben Grund wie in VirtuSphere-Common.ps1: ab 2.0 wuerde auch der
# Zugriff auf ein legitim fehlendes JSON-Feld werfen.
#
# Fuer die Teilskripte aendert sich nichts: sie laufen ueber
# & PowerShell.exe -File in einem eigenen Prozess, StrictMode wirkt nicht hinein.
# $LASTEXITCODE wird erst NACH dem ersten &-Aufruf gelesen, ist dort also
# gesetzt; eine spaetere Umstellung auf Version 2.0 muesste das pruefen.
Set-StrictMode -Version 1.0

# Set-Location: Wechselt das Arbeitsverzeichnis auf den Ordner, in dem install.ps1 liegt.
# $PSScriptRoot: Automatische Variable - enthaelt immer den vollstaendigen Pfad des aktuellen Skripts.
# Wichtig damit alle relativen Pfade (.\powershell\, .\config.json) korrekt aufgeloest werden.
Set-Location $PSScriptRoot

$script:VsPackageLogSchemaVersion = 1
$script:VsPackageLogKeepCount = 5
$script:VsPackageLogSinks = @{}
$script:VsPackageLogPaths = @{}
$script:VsPackageLogWarnings = @{}
$script:VsPackageRunResults = New-Object System.Collections.Generic.List[object]
$script:VsPackageRunStopwatch = [System.Diagnostics.Stopwatch]::StartNew()
$script:VsPackageRunCompleted = $false
$script:VsPackageRunId = ([guid]::NewGuid()).ToString('D').ToLowerInvariant()
$script:VsPackageRunStartedUtc = [DateTime]::UtcNow
$script:VsPackageRunStamp = $script:VsPackageRunStartedUtc.ToString('yyyyMMddTHHmmssfffZ', [Globalization.CultureInfo]::InvariantCulture)

# The package is executable content. Resolve only the installer-owned closed
# generation, never a path supplied by config.json or by a manifest entry.
# This is a read-only precondition for the later supervised reporter start.
function Get-VsPackageVerifiedReporterBundle {
    param([Parameter(Mandatory)][string]$PackageRoot)

    try {
        $expectedNames = @(
            'VirtuSphere-Client-Common.ps1',
            'VirtuSphere-Client-Logging.ps1',
            'VirtuSphere-Package-Reporter.ps1',
            'VirtuSphere-Package-ReporterHost.ps1'
        ) | Sort-Object
        $reporting = Join-Path $PackageRoot 'reporting'
        $descriptorPath = Join-Path $reporting 'current.json'
        $wrapperPath = Join-Path $PackageRoot 'install.ps1'
        foreach ($directory in @($PackageRoot, $reporting)) {
            $item = Get-Item -LiteralPath $directory -Force -ErrorAction Stop
            if (-not $item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) { return $null }
        }
        foreach ($path in @($wrapperPath, $descriptorPath)) {
            $item = Get-Item -LiteralPath $path -Force -ErrorAction Stop
            if ($item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) { return $null }
        }
        if ((Get-Item -LiteralPath $descriptorPath -Force).Length -gt 8192) { return $null }
        $descriptor = Get-Content -LiteralPath $descriptorPath -Raw -ErrorAction Stop | ConvertFrom-Json -ErrorAction Stop
        if ((@($descriptor.PSObject.Properties.Name | Sort-Object) -join '|') -cne
            'bundle_id|contracts|schema_version|wrapper_sha256' -or
            ($descriptor.schema_version -isnot [int] -and $descriptor.schema_version -isnot [long]) -or $descriptor.schema_version -ne 1 -or
            $descriptor.bundle_id -isnot [string] -or $descriptor.bundle_id -cnotmatch '\A[0-9a-f]{64}\z' -or
            $descriptor.wrapper_sha256 -isnot [string] -or $descriptor.wrapper_sha256 -cnotmatch '\A[0-9a-f]{64}\z') { return $null }
        if ((Get-FileHash -LiteralPath $wrapperPath -Algorithm SHA256 -ErrorAction Stop).Hash.ToLowerInvariant() -cne
            $descriptor.wrapper_sha256) { return $null }

        $bundleRoot = Join-Path $reporting $descriptor.bundle_id
        $bundleItem = Get-Item -LiteralPath $bundleRoot -Force -ErrorAction Stop
        if (-not $bundleItem.PSIsContainer -or ($bundleItem.Attributes -band [IO.FileAttributes]::ReparsePoint)) { return $null }
        $manifestPath = Join-Path $bundleRoot 'manifest.json'
        $manifestItem = Get-Item -LiteralPath $manifestPath -Force -ErrorAction Stop
        if ($manifestItem.PSIsContainer -or ($manifestItem.Attributes -band [IO.FileAttributes]::ReparsePoint) -or
            $manifestItem.Length -gt 16384) { return $null }
        $manifest = Get-Content -LiteralPath $manifestPath -Raw -ErrorAction Stop | ConvertFrom-Json -ErrorAction Stop
        if ((@($manifest.PSObject.Properties.Name | Sort-Object) -join '|') -cne
            'bundle_id|contracts|files|schema_version' -or
            ($manifest.schema_version -isnot [int] -and $manifest.schema_version -isnot [long]) -or $manifest.schema_version -ne 1 -or
            $manifest.bundle_id -cne $descriptor.bundle_id) { return $null }
        foreach ($contractSet in @($descriptor.contracts, $manifest.contracts)) {
            if ($null -eq $contractSet -or
                (@($contractSet.PSObject.Properties.Name | Sort-Object) -join '|') -cne 'adapter|common|host|logging') { return $null }
            foreach ($name in @('common', 'logging', 'adapter', 'host')) {
                $value = $contractSet.PSObject.Properties[$name].Value
                if (($value -isnot [int] -and $value -isnot [long]) -or $value -ne 1) { return $null }
            }
        }

        $entries = @($manifest.files)
        if ($entries.Count -ne $expectedNames.Count) { return $null }
        $basis = New-Object 'System.Collections.Generic.List[string]'
        $verifiedPaths = New-Object 'System.Collections.Generic.List[string]'
        for ($index = 0; $index -lt $expectedNames.Count; $index++) {
            $entry = $entries[$index]
            if ($null -eq $entry -or
                (@($entry.PSObject.Properties.Name | Sort-Object) -join '|') -cne 'length|path|sha256' -or
                $entry.path -isnot [string] -or $entry.path -cne $expectedNames[$index] -or
                ($entry.length -isnot [int] -and $entry.length -isnot [long]) -or $entry.length -lt 0 -or
                $entry.sha256 -isnot [string] -or $entry.sha256 -cnotmatch '\A[0-9a-f]{64}\z') { return $null }
            $sourcePath = Join-Path $bundleRoot $entry.path
            $sourceItem = Get-Item -LiteralPath $sourcePath -Force -ErrorAction Stop
            if ($sourceItem.PSIsContainer -or ($sourceItem.Attributes -band [IO.FileAttributes]::ReparsePoint) -or
                $sourceItem.Length -ne [long]$entry.length -or
                (Get-FileHash -LiteralPath $sourcePath -Algorithm SHA256 -ErrorAction Stop).Hash.ToLowerInvariant() -cne $entry.sha256) { return $null }
            [void]$basis.Add(('{0}|{1}|{2}' -f $entry.path, [long]$entry.length, $entry.sha256))
            [void]$verifiedPaths.Add($sourcePath)
        }
        $actualNames = @(Get-ChildItem -LiteralPath $bundleRoot -Force -ErrorAction Stop | Sort-Object Name | ForEach-Object Name)
        $allowedNames = @($expectedNames + 'manifest.json' | Sort-Object)
        if (($actualNames -join '|') -cne ($allowedNames -join '|')) { return $null }
        $sha = [Security.Cryptography.SHA256]::Create()
        try {
            $calculatedId = ([BitConverter]::ToString($sha.ComputeHash(
                [Text.Encoding]::UTF8.GetBytes(($basis.ToArray() -join "`n")))).Replace('-', '')).ToLowerInvariant()
        } finally { $sha.Dispose() }
        if ($calculatedId -cne $descriptor.bundle_id) { return $null }
        return [pscustomobject]@{ BundleId = $descriptor.bundle_id; Root = $bundleRoot; Files = $verifiedPaths.ToArray() }
    } catch {
        Write-Debug $_
        return $null
    }
}

# Create the worker suspended, bind only that new process to a private job,
# then let its first instruction run. A failed assignment terminates the
# suspended child. The wrapper alone retains the non-inherited job handle.
function Start-VsPackageReporterJobProcess {
    param(
        [Parameter(Mandatory)][string]$ScriptPath,
        [Parameter(Mandatory)][string]$PipeName
    )

    if ($PipeName -cnotmatch '\Avirtusphere-report-[0-9a-f]{32}\z' -or
        -not (Test-Path -LiteralPath $ScriptPath -PathType Leaf)) { return $null }
    $powerShellPath = Join-Path ([Environment]::GetFolderPath('System')) 'WindowsPowerShell\v1.0\powershell.exe'
    if (-not (Test-Path -LiteralPath $powerShellPath -PathType Leaf)) { return $null }
    try {
        if (-not ('VirtuSphere.PackageReporterJobProcess' -as [type])) {
            Add-Type -Language CSharp -ErrorAction Stop -TypeDefinition @'
using System;
using System.ComponentModel;
using System.Runtime.InteropServices;
using System.Text;

namespace VirtuSphere {
    public sealed class PackageReporterJobProcess : IDisposable {
        [StructLayout(LayoutKind.Sequential)]
        private struct STARTUPINFO {
            public uint cb;
            public IntPtr lpReserved, lpDesktop, lpTitle;
            public uint dwX, dwY, dwXSize, dwYSize, dwXCountChars, dwYCountChars;
            public uint dwFillAttribute, dwFlags;
            public ushort wShowWindow, cbReserved2;
            public IntPtr lpReserved2, hStdInput, hStdOutput, hStdError;
        }
        [StructLayout(LayoutKind.Sequential)]
        private struct PROCESS_INFORMATION {
            public IntPtr hProcess, hThread;
            public uint dwProcessId, dwThreadId;
        }
        [StructLayout(LayoutKind.Sequential)]
        private struct BASIC_LIMIT_INFORMATION {
            public long PerProcessUserTimeLimit, PerJobUserTimeLimit;
            public uint LimitFlags;
            public UIntPtr MinimumWorkingSetSize, MaximumWorkingSetSize;
            public uint ActiveProcessLimit;
            public IntPtr Affinity;
            public uint PriorityClass, SchedulingClass;
        }
        [StructLayout(LayoutKind.Sequential)]
        private struct IO_COUNTERS {
            public ulong ReadOperationCount, WriteOperationCount, OtherOperationCount;
            public ulong ReadTransferCount, WriteTransferCount, OtherTransferCount;
        }
        [StructLayout(LayoutKind.Sequential)]
        private struct EXTENDED_LIMIT_INFORMATION {
            public BASIC_LIMIT_INFORMATION BasicLimitInformation;
            public IO_COUNTERS IoInfo;
            public UIntPtr ProcessMemoryLimit, JobMemoryLimit;
            public UIntPtr PeakProcessMemoryUsed, PeakJobMemoryUsed;
        }
        [DllImport("kernel32.dll", SetLastError=true, CharSet=CharSet.Unicode)]
        private static extern bool CreateProcessW(string application, StringBuilder commandLine,
            IntPtr processAttributes, IntPtr threadAttributes, bool inheritHandles,
            uint creationFlags, IntPtr environment, string currentDirectory,
            ref STARTUPINFO startup, out PROCESS_INFORMATION process);
        [DllImport("kernel32.dll", SetLastError=true)]
        private static extern IntPtr CreateJobObject(IntPtr security, string name);
        [DllImport("kernel32.dll", SetLastError=true)]
        private static extern bool SetInformationJobObject(IntPtr job, int infoClass,
            ref EXTENDED_LIMIT_INFORMATION limits, uint size);
        [DllImport("kernel32.dll", SetLastError=true)]
        private static extern bool AssignProcessToJobObject(IntPtr job, IntPtr process);
        [DllImport("kernel32.dll", SetLastError=true)]
        private static extern uint ResumeThread(IntPtr thread);
        [DllImport("kernel32.dll", SetLastError=true)]
        private static extern bool TerminateProcess(IntPtr process, uint exitCode);
        [DllImport("kernel32.dll", SetLastError=true)]
        private static extern uint WaitForSingleObject(IntPtr handle, uint milliseconds);
        [DllImport("kernel32.dll", SetLastError=true)]
        private static extern bool CloseHandle(IntPtr handle);

        private IntPtr job, process;
        public uint ProcessId { get; private set; }
        private PackageReporterJobProcess(IntPtr jobHandle, IntPtr processHandle, uint pid) {
            job = jobHandle; process = processHandle; ProcessId = pid;
        }
        public bool IsRunning { get { return process != IntPtr.Zero && WaitForSingleObject(process, 0) == 0x102; } }
        public bool WaitForExit(uint milliseconds) {
            return process != IntPtr.Zero && WaitForSingleObject(process, milliseconds) == 0;
        }
        public static PackageReporterJobProcess Start(string exe, string script, string pipeName) {
            IntPtr jobHandle = IntPtr.Zero;
            PROCESS_INFORMATION child = new PROCESS_INFORMATION();
            bool created = false;
            try {
                jobHandle = CreateJobObject(IntPtr.Zero, null);
                if (jobHandle == IntPtr.Zero) throw new Win32Exception(Marshal.GetLastWin32Error());
                EXTENDED_LIMIT_INFORMATION limits = new EXTENDED_LIMIT_INFORMATION();
                limits.BasicLimitInformation.LimitFlags = 0x2000; // JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE
                if (!SetInformationJobObject(jobHandle, 9, ref limits,
                    (uint)Marshal.SizeOf(typeof(EXTENDED_LIMIT_INFORMATION))))
                    throw new Win32Exception(Marshal.GetLastWin32Error());
                STARTUPINFO startup = new STARTUPINFO();
                startup.cb = (uint)Marshal.SizeOf(typeof(STARTUPINFO));
                string args = "\"" + exe + "\" -NoProfile -ExecutionPolicy Bypass -NonInteractive -File \"" +
                    script + "\" -VsReporterPipeName " + pipeName;
                if (!CreateProcessW(exe, new StringBuilder(args), IntPtr.Zero, IntPtr.Zero,
                    false, 0x08000004, IntPtr.Zero, null, ref startup, out child))
                    throw new Win32Exception(Marshal.GetLastWin32Error());
                created = true;
                if (!AssignProcessToJobObject(jobHandle, child.hProcess))
                    throw new Win32Exception(Marshal.GetLastWin32Error());
                if (ResumeThread(child.hThread) == 0xffffffff)
                    throw new Win32Exception(Marshal.GetLastWin32Error());
                PackageReporterJobProcess result = new PackageReporterJobProcess(jobHandle, child.hProcess, child.dwProcessId);
                jobHandle = IntPtr.Zero;
                child.hProcess = IntPtr.Zero;
                return result;
            } catch {
                if (created) {
                    TerminateProcess(child.hProcess, 1);
                    WaitForSingleObject(child.hProcess, 500);
                }
                throw;
            } finally {
                if (child.hThread != IntPtr.Zero) CloseHandle(child.hThread);
                if (child.hProcess != IntPtr.Zero) CloseHandle(child.hProcess);
                if (jobHandle != IntPtr.Zero) CloseHandle(jobHandle);
            }
        }
        public void Dispose() {
            if (job != IntPtr.Zero) { CloseHandle(job); job = IntPtr.Zero; }
            if (process != IntPtr.Zero) { CloseHandle(process); process = IntPtr.Zero; }
            GC.SuppressFinalize(this);
        }
        ~PackageReporterJobProcess() { Dispose(); }
    }
}
'@
        }
        return [VirtuSphere.PackageReporterJobProcess]::Start($powerShellPath, $ScriptPath, $PipeName)
    } catch {
        Write-Debug $_
        return $null
    }
}

function ConvertTo-VsPackageLogText {
    param(
        [AllowNull()]
        [object]$Value,

        [int]$MaximumLength = 512
    )

    if ($null -eq $Value) { return $null }
    $text = [string]$Value
    $text = [regex]::Replace($text, '[\x00-\x1f\x7f]', ' ')
    if ($MaximumLength -gt 0 -and $text.Length -gt $MaximumLength) {
        return $text.Substring(0, $MaximumLength)
    }
    return $text
}

function Get-VsPackageIdentityHash {
    param(
        [Parameter(Mandatory = $true)][string]$ProjectName,
        [Parameter(Mandatory = $true)][string]$Version
    )

    $utf8 = New-Object System.Text.UTF8Encoding($false)
    $ascii = [System.Text.Encoding]::ASCII
    $buffer = New-Object System.IO.MemoryStream
    try {
        foreach ($value in @($ProjectName, $Version)) {
            $valueBytes = $utf8.GetBytes($value)
            $lengthBytes = $ascii.GetBytes(($valueBytes.Length.ToString([Globalization.CultureInfo]::InvariantCulture) + ':'))
            $buffer.Write($lengthBytes, 0, $lengthBytes.Length)
            $buffer.Write($valueBytes, 0, $valueBytes.Length)
        }
        $sha256 = [System.Security.Cryptography.SHA256]::Create()
        try {
            return ([BitConverter]::ToString($sha256.ComputeHash($buffer.ToArray()))).Replace('-', '').ToLowerInvariant()
        } finally {
            $sha256.Dispose()
        }
    } finally {
        $buffer.Dispose()
    }
}

function Test-VsPackageLogAccess {
    param([Parameter(Mandatory = $true)][string]$Path)

    $broadWriteSids = @('S-1-1-0', 'S-1-5-11', 'S-1-5-32-545', 'S-1-5-32-546')
    $unsafeRights = [Security.AccessControl.FileSystemRights]::WriteData -bor
        [Security.AccessControl.FileSystemRights]::AppendData -bor
        [Security.AccessControl.FileSystemRights]::WriteExtendedAttributes -bor
        [Security.AccessControl.FileSystemRights]::WriteAttributes -bor
        [Security.AccessControl.FileSystemRights]::Delete -bor
        [Security.AccessControl.FileSystemRights]::DeleteSubdirectoriesAndFiles -bor
        [Security.AccessControl.FileSystemRights]::ChangePermissions -bor
        [Security.AccessControl.FileSystemRights]::TakeOwnership
    try {
        $acl = Get-Acl -LiteralPath $Path -ErrorAction Stop
        foreach ($rule in $acl.Access) {
            if ($rule.AccessControlType -ne [Security.AccessControl.AccessControlType]::Allow) { continue }
            try {
                $sid = $rule.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value
            } catch {
                return $false
            }
            if ($broadWriteSids -contains $sid -and (($rule.FileSystemRights -band $unsafeRights) -ne 0)) {
                return $false
            }
        }
        return $true
    } catch {
        return $false
    }
}

function Write-VsPackageLog {
    param(
        [Parameter(Mandatory = $true)][ValidateSet('wrapper', 'reporting')][string]$Sink,
        [Parameter(Mandatory = $true)][string]$Event,
        [hashtable]$Fields = @{}
    )

    $writer = $script:VsPackageLogSinks[$Sink]
    if ($null -eq $writer) { return }

    try {
        $record = [ordered]@{
            schema_version = $script:VsPackageLogSchemaVersion
            timestamp_utc = [DateTime]::UtcNow.ToString('o', [Globalization.CultureInfo]::InvariantCulture)
            elapsed_ms = [Math]::Floor($script:VsPackageRunStopwatch.Elapsed.TotalMilliseconds)
            run_id = $script:VsPackageRunId
            event = ConvertTo-VsPackageLogText -Value $Event -MaximumLength 64
        }
        foreach ($key in @($Fields.Keys | Sort-Object)) {
            $value = $Fields[$key]
            if ($value -is [string]) {
                $limit = if ($key -in @('project_name', 'package_version', 'detail_path')) { 0 } else { 512 }
                $value = ConvertTo-VsPackageLogText -Value $value -MaximumLength $limit
            }
            $record[[string]$key] = $value
        }
        $writer.WriteLine(($record | ConvertTo-Json -Compress -Depth 4))
    } catch {
        $script:VsPackageLogSinks[$Sink] = $null
        if (-not $script:VsPackageLogWarnings.ContainsKey($Sink)) {
            $script:VsPackageLogWarnings[$Sink] = $true
            try { Write-Warning "VirtuSphere $Sink-Log ist ausgefallen; Paketablauf wird fortgesetzt." } catch { $null = $_ }
        }
        try { $writer.Dispose() } catch { $null = $_ }
    }
}

function Open-VsPackageLogSink {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][ValidateSet('wrapper', 'reporting')][string]$Kind,
        [Parameter(Mandatory = $true)][string]$PartnerName,
        [Parameter(Mandatory = $true)][string]$ProjectName,
        [Parameter(Mandatory = $true)][string]$Version
    )

    try {
        $stream = New-Object System.IO.FileStream(
            $Path,
            [System.IO.FileMode]::CreateNew,
            [System.IO.FileAccess]::Write,
            [System.IO.FileShare]::Read,
            4096,
            [System.IO.FileOptions]::WriteThrough
        )
        try {
            $writer = New-Object System.IO.StreamWriter($stream, (New-Object System.Text.UTF8Encoding($true)))
            $writer.AutoFlush = $true
            $script:VsPackageLogSinks[$Kind] = $writer
            $script:VsPackageLogPaths[$Kind] = $Path
            Write-VsPackageLog -Sink $Kind -Event 'header' -Fields @{
                log_kind = $Kind
                partner_file = $PartnerName
                project_name = $ProjectName
                package_version = $Version
                started_utc = $script:VsPackageRunStartedUtc.ToString('o', [Globalization.CultureInfo]::InvariantCulture)
                process_id = $PID
                process_identity = [Security.Principal.WindowsIdentity]::GetCurrent().Name
                powershell_version = [string]$PSVersionTable.PSVersion
            }
            return ($null -ne $script:VsPackageLogSinks[$Kind])
        } catch {
            try { $stream.Dispose() } catch { $null = $_ }
            throw
        }
    } catch {
        $script:VsPackageLogSinks[$Kind] = $null
        if (-not $script:VsPackageLogWarnings.ContainsKey($Kind)) {
            $script:VsPackageLogWarnings[$Kind] = $true
            try { Write-Warning "VirtuSphere $Kind-Log konnte nicht angelegt werden; Paketablauf wird fortgesetzt." } catch { $null = $_ }
        }
        return $false
    }
}

function Invoke-VsPackageLogRetention {
    param(
        [Parameter(Mandatory = $true)][string]$Directory,
        [Parameter(Mandatory = $true)][string]$CurrentStem
    )

    $directoryInfo = Get-Item -LiteralPath $Directory -Force -ErrorAction Stop
    if (($directoryInfo.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
        throw 'PackageWrapper-Logordner ist ein Reparse-Point.'
    }
    $directoryFull = [IO.Path]::GetFullPath($directoryInfo.FullName).TrimEnd([IO.Path]::DirectorySeparatorChar)
    $pattern = '^(wrapper|reporting)_(?<stamp>\d{8}T\d{9}Z)_(?<run>[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\.log$'
    $groups = @{}
    foreach ($file in @(Get-ChildItem -LiteralPath $directoryFull -File -Force -ErrorAction Stop)) {
        $match = [regex]::Match($file.Name, $pattern, [Text.RegularExpressions.RegexOptions]::CultureInvariant)
        if (-not $match.Success) { continue }
        $parsedStamp = [DateTime]::MinValue
        if (-not [DateTime]::TryParseExact(
            $match.Groups['stamp'].Value,
            "yyyyMMdd'T'HHmmssfff'Z'",
            [Globalization.CultureInfo]::InvariantCulture,
            [Globalization.DateTimeStyles]::AssumeUniversal,
            [ref]$parsedStamp
        )) { continue }
        if (($file.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { continue }
        if ([string]::Compare($file.DirectoryName, $directoryFull, [StringComparison]::OrdinalIgnoreCase) -ne 0) { continue }
        $stem = '{0}_{1}' -f $match.Groups['stamp'].Value, $match.Groups['run'].Value
        if (-not $groups.ContainsKey($stem)) {
            $groups[$stem] = [pscustomobject]@{ Stem = $stem; Stamp = $match.Groups['stamp'].Value; Run = $match.Groups['run'].Value; Files = New-Object System.Collections.Generic.List[object] }
        }
        $groups[$stem].Files.Add($file)
    }

    $ordered = @($groups.Values | Sort-Object Stamp, Run -Descending)
    $protected = @($ordered | Select-Object -First $script:VsPackageLogKeepCount | ForEach-Object { $_.Stem })
    if ($protected -notcontains $CurrentStem) { $protected += $CurrentStem }
    $warned = $false
    foreach ($group in @($ordered | Where-Object { $protected -notcontains $_.Stem })) {
        $locks = New-Object System.Collections.Generic.List[object]
        try {
            $lockFailed = $false
            foreach ($file in $group.Files.ToArray()) {
                try {
                    $fresh = Get-Item -LiteralPath $file.FullName -Force -ErrorAction Stop
                    if (($fresh.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0 -or
                        [string]::Compare($fresh.DirectoryName, $directoryFull, [StringComparison]::OrdinalIgnoreCase) -ne 0) {
                        $lockFailed = $true
                        break
                    }
                    $locks.Add((New-Object System.IO.FileStream(
                        $fresh.FullName,
                        [IO.FileMode]::Open,
                        [IO.FileAccess]::ReadWrite,
                        ([IO.FileShare]::ReadWrite -bor [IO.FileShare]::Delete)
                    )))
                } catch {
                    $lockFailed = $true
                    break
                }
            }
            if ($lockFailed) { continue }
            foreach ($file in $group.Files.ToArray()) {
                try {
                    $fresh = Get-Item -LiteralPath $file.FullName -Force -ErrorAction Stop
                    if (($fresh.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0 -or
                        [string]::Compare($fresh.DirectoryName, $directoryFull, [StringComparison]::OrdinalIgnoreCase) -ne 0) {
                        throw 'Logdatei hat die Zielgrenze verlassen.'
                    }
                    [IO.File]::Delete($fresh.FullName)
                } catch [System.Management.Automation.ItemNotFoundException] {
                    # Ein paralleler Bereiniger war schneller.
                    $null = $_
                } catch {
                    if (-not $warned) {
                        $warned = $true
                        Write-Warning 'Alte VirtuSphere-PackageWrapper-Logs konnten nicht vollstaendig bereinigt werden.'
                    }
                }
            }
        } finally {
            foreach ($handle in $locks) { try { $handle.Dispose() } catch { $null = $_ } }
        }
    }
}

function Add-VsPackageStepResult {
    param(
        [int]$Index,
        [string]$ScriptName,
        [ValidateSet('OK', 'SKIP', 'FAIL')][string]$Outcome,
        [string]$Category,
        [AllowNull()][object]$ChildExitCode,
        [AllowNull()][string]$DetailPath,
        [long]$DurationMs
    )

    $result = [pscustomobject]@{
        index = $Index
        script_name = ConvertTo-VsPackageLogText -Value $ScriptName -MaximumLength 260
        outcome = $Outcome
        category = $Category
        child_exit_code = $ChildExitCode
        detail_path = $DetailPath
        duration_ms = $DurationMs
    }
    $script:VsPackageRunResults.Add($result)
    $displayTotal = if ($null -ne $knownStepTotal) { $knownStepTotal } else { '?' }
    Write-Host "[$Index/$displayTotal] $Outcome $ScriptName" -ForegroundColor Gray
    Write-VsPackageLog -Sink wrapper -Event 'step_result' -Fields @{
        index = $Index
        script_name = $result.script_name
        outcome = $Outcome
        category = $Category
        child_exit_code = $ChildExitCode
        detail_path = $DetailPath
        duration_ms = $DurationMs
    }
}

function Complete-VsPackageRun {
    param(
        [AllowNull()][object]$ExitCode,
        [Parameter(Mandatory = $true)][string]$Result,
        [Parameter(Mandatory = $true)][ValidateSet('written', 'failed', 'not_attempted')][string]$DetectionStatus,
        [AllowNull()][object]$Total
    )

    if ($script:VsPackageRunCompleted) { return }
    $script:VsPackageRunCompleted = $true
    $ok = @($script:VsPackageRunResults | Where-Object outcome -eq 'OK').Count
    $skip = @($script:VsPackageRunResults | Where-Object outcome -eq 'SKIP').Count
    $fail = @($script:VsPackageRunResults | Where-Object outcome -eq 'FAIL').Count
    $processed = $ok + $skip + $fail
    $notProcessed = if ($null -ne $Total) { [Math]::Max(0, ([int]$Total - $processed)) } else { $null }
    Write-VsPackageLog -Sink wrapper -Event 'completed' -Fields @{
        result = $Result
        exit_code = $ExitCode
        detection_status = $DetectionStatus
        total = $Total
        processed = $processed
        ok = $ok
        skip = $skip
        fail = $fail
        not_processed = $notProcessed
        duration_ms = [Math]::Floor($script:VsPackageRunStopwatch.Elapsed.TotalMilliseconds)
    }
    Write-VsPackageLog -Sink reporting -Event 'reporting_disabled' -Fields @{ reason = 'transport_not_integrated' }
    $wrapperPath = $script:VsPackageLogPaths['wrapper']
    $reportingPath = $script:VsPackageLogPaths['reporting']
    Write-Host "PackageWrapper Run-ID: $($script:VsPackageRunId)" -ForegroundColor Cyan
    if ($wrapperPath) { Write-Host "Wrapper-Log: $wrapperPath" -ForegroundColor Cyan }
    if ($reportingPath) { Write-Host "Reporting-Log: $reportingPath" -ForegroundColor Cyan }
}

function Close-VsPackageLogSink {
    foreach ($kind in @('wrapper', 'reporting')) {
        $writer = $script:VsPackageLogSinks[$kind]
        if ($null -ne $writer) {
            try { $writer.Dispose() } catch { $null = $_ }
            $script:VsPackageLogSinks[$kind] = $null
        }
    }
}

# Konfigurationsdatei einlesen und PRUEFEN, bevor irgendein Teilskript laeuft.
#
# Ohne diese Pruefung lief das Skript mit $config = $null weiter: der
# Registry-Pfad wurde zu "...\Packages\-", alle Teilskripte liefen trotzdem als
# SYSTEM, und der Wrapper konnte mit 0 enden. MECM faengt das am Ende ueber die
# nicht erfuellte Detection ab - da sind die Skripte aber schon gelaufen.
# Dasselbe Skript hat weiter unten einen ausfuehrlich begruendeten Guard dafuer,
# dass ein leerer powershell-Ordner ein Fehlschlag ist; fuer seine eigene
# Konfigurationsdatei hatte es keinen.
#
# Bewusst ohne Helfer aus VirtuSphere-Common.ps1: diese Datei wird einzeln in
# den Paketordner kopiert und laeuft dort ohne die Bibliothek (ADR-0029). Die
# Pflichtfelder sind dieselben, die Read-VsPackageConfig serverseitig prueft;
# ein Test haelt beide Listen gegeneinander.
$configPath = ".\config.json"
if (-not (Test-Path $configPath)) {
    Write-Host "config.json fehlt im Paketordner ($PSScriptRoot) - ohne sie ist weder der Registry-Pfad noch die Version bestimmbar. Installation abgebrochen." -ForegroundColor Red
    exit 1
}
try {
    $config = Get-Content $configPath -Raw | ConvertFrom-Json
} catch {
    Write-Host "config.json ist kein gueltiges JSON ($configPath): $($_.Exception.Message). Installation abgebrochen." -ForegroundColor Red
    exit 1
}
if (-not $config -or -not $config.ProjectName -or -not $config.version) {
    Write-Host "config.json ohne ProjectName/version ($configPath) - beide bilden den Registry-Pfad und den Detection-Wert. Installation abgebrochen." -ForegroundColor Red
    exit 1
}

# Skriptverzeichnis und Konfigurationswerte aus der config.json uebernehmen
$scriptDirectory = ".\powershell\"
$projectName     = $config.ProjectName

# $ErrorAction: Steuert das Verhalten bei Skriptfehlern.
# Moegliche Werte aus config.json:
#   "Stop"     - Bricht die gesamte Installation ab sobald ein Skript fehlschlaegt
#   "Continue" - Faehrt mit dem naechsten Skript fort auch wenn eines fehlschlaegt
$ErrorAction = $config.ErrorAction
if ([string]$ErrorAction -notin @('Stop', 'Continue')) {
    Write-Host 'config.json: ErrorAction muss Stop oder Continue sein. Installation abgebrochen.' -ForegroundColor Red
    exit 1
}

# Registrierungspfad je nach Installationstyp setzen
# Erlaubt sind genau zwei Werte (SSoT: $script:VsInstallationBehaviorTypes in
# VirtuSphere-Common.ps1, das diese Datei nie sieht - sie wird allein in den
# Paketordner kopiert):
# InstallForSystem: Installation fuer alle Benutzer - Registry unter HKLM (HKEY_LOCAL_MACHINE)
# InstallForUser:   Installation nur fuer den aktuellen Benutzer - Registry unter HKCU (HKEY_CURRENT_USER)
# Der Pfad enthaelt ProjectName und Version damit verschiedene Versionen nebeneinander
# in der Registry erfasst werden koennen ohne sich zu ueberschreiben.
#
# Auf InstallForUser geprueft, nicht auf InstallForSystem: der Autoimporter legt
# die Detection-Klausel genau so herum an. Andersherum fielen beide Seiten bei
# einem fehlenden Feld in verschiedene Zweige - MECM suchte in HKLM, dieses
# Skript schrieb nach HKCU, und die App galt nie als installiert.
if($config.InstallationBehaviorType -eq "InstallForUser"){
    $registryPath = "HKCU:\Software\VirtuSphere\Packages\$($projectName)-$($config.version)"
} else {
    $registryPath = "HKLM:\Software\VirtuSphere\Packages\$($projectName)-$($config.version)"
}

# Benutzerinstallationen schreiben in den eigenen Profilbereich; SYSTEM-
# Installationen in den geschuetzten Maschinenbereich.
if ($config.InstallationBehaviorType -eq 'InstallForUser') {
    $logDirectory = Join-Path $env:LOCALAPPDATA 'VirtuSphere\Logs'
} else {
    $logDirectory = Join-Path $env:ProgramData 'VirtuSphere\Logs'
}

# Die vorhandenen Teilskript-Logs und die neuen Gesamtlogs teilen nur die
# Logwurzel. PackageWrapper verwaltet ausschliesslich seinen eigenen Unterbaum.
if (!(Test-Path $logDirectory)) {
    New-Item -Path $logDirectory -ItemType Directory -Force -ErrorAction Stop | Out-Null
}
$packageLogReady = $true
try {
    $packageIdentity = Get-VsPackageIdentityHash -ProjectName ([string]$config.ProjectName) -Version ([string]$config.version)
    $packageWrapperRoot = Join-Path $logDirectory 'PackageWrapper'
    $packageLogDirectory = Join-Path $packageWrapperRoot $packageIdentity
    $logRootInfo = Get-Item -LiteralPath $logDirectory -Force -ErrorAction Stop
    if (($logRootInfo.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0 -or
        -not (Test-VsPackageLogAccess -Path $logRootInfo.FullName)) {
        throw 'Gemeinsame Logwurzel ist fuer PackageWrapper nicht sicher.'
    }
    foreach ($directory in @($packageWrapperRoot, $packageLogDirectory)) {
        if (!(Test-Path -LiteralPath $directory)) {
            New-Item -Path $directory -ItemType Directory -Force -ErrorAction Stop | Out-Null
        }
        $directoryInfo = Get-Item -LiteralPath $directory -Force -ErrorAction Stop
        if (($directoryInfo.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
            throw "Verwalteter PackageWrapper-Pfad ist ein Reparse-Point: $directory"
        }
        if (-not (Test-VsPackageLogAccess -Path $directoryInfo.FullName)) {
            throw "Verwalteter PackageWrapper-Pfad besitzt keine sichere ACL: $directory"
        }
    }
} catch {
    $packageLogReady = $false
    try { Write-Warning 'VirtuSphere-PackageWrapper-Logs sind fuer diesen Lauf deaktiviert; der Paketablauf wird fortgesetzt.' } catch { $null = $_ }
}

$currentLogStem = '{0}_{1}' -f $script:VsPackageRunStamp, $script:VsPackageRunId
$wrapperLogName = "wrapper_$currentLogStem.log"
$reportingLogName = "reporting_$currentLogStem.log"
$wrapperLogInitialized = $false
$reportingLogInitialized = $false
if ($packageLogReady) {
    $wrapperLogInitialized = Open-VsPackageLogSink -Path (Join-Path $packageLogDirectory $wrapperLogName) -Kind wrapper -PartnerName $reportingLogName -ProjectName ([string]$config.ProjectName) -Version ([string]$config.version)
    $reportingLogInitialized = Open-VsPackageLogSink -Path (Join-Path $packageLogDirectory $reportingLogName) -Kind reporting -PartnerName $wrapperLogName -ProjectName ([string]$config.ProjectName) -Version ([string]$config.version)
    if (-not $reportingLogInitialized) {
        Write-VsPackageLog -Sink wrapper -Event 'partner_unavailable' -Fields @{ partner_file = $reportingLogName }
    }
    if (-not $wrapperLogInitialized) {
        Write-VsPackageLog -Sink reporting -Event 'partner_unavailable' -Fields @{ partner_file = $wrapperLogName }
    }
    if ($wrapperLogInitialized -and $reportingLogInitialized) {
        try {
            Invoke-VsPackageLogRetention -Directory $packageLogDirectory -CurrentStem $currentLogStem
        } catch {
            Write-Warning 'Alte VirtuSphere-PackageWrapper-Logs konnten in diesem Lauf nicht sicher bereinigt werden.'
        }
    }
}

Write-Host "PackageWrapper Run-ID: $($script:VsPackageRunId)" -ForegroundColor Cyan
if ($script:VsPackageLogPaths['wrapper']) { Write-Host "Wrapper-Log: $($script:VsPackageLogPaths['wrapper'])" -ForegroundColor Cyan }
if ($script:VsPackageLogPaths['reporting']) { Write-Host "Reporting-Log: $($script:VsPackageLogPaths['reporting'])" -ForegroundColor Cyan }

############ Ab hier nichts aendern

# Exit-Codes eines Teilskripts, die als ERFOLG gelten. Genau einmal im Quelltext,
# damit Kommentar und Bedingung nicht auseinanderlaufen koennen:
#   0    - erfolgreich, kein Neustart notwendig
#   1707 - MSI-Installationspaket erfolgreich installiert
#   3010 - erfolgreich, ein Neustart wird empfohlen
#   1641 - erfolgreich, der Neustart wurde bereits eingeleitet
# Alles andere ist ein Fehler; 1 ist der generische Windows-Fehlercode.
$successExitCodes = @(0, 1707, 3010, 1641)

# Neustartcodes in der Reihenfolge, in der sie gewinnen. 1641 schlaegt 3010,
# weil 1641 heisst, dass ein Teilskript den Neustart bereits eingeleitet hat:
# MECM muss das wissen, um nicht selbst einen zweiten zu planen. Ein Fehlschlag
# schlaegt beide, sonst meldet ein Paket "bitte neu starten" statt "hat nicht
# funktioniert".
$rebootExitCodes = @(1641, 3010)

# Hoechster gesehener Neustartcode ueber alle Teilskripte dieses Laufs.
# Vorher wurden 3010 und 1641 auf "Erfolg" eingeebnet und der Wrapper endete mit
# 0, obwohl der Deployment-Type auf RebootBehavior = 'BasedOnExitCode' steht: das
# Neustartverhalten war konfiguriert und vom Wrapper unerreichbar gemacht.
$rebootCode = 0

# $Fullsuccess: Gesamtstatus der Installation.
# Startet als $true. Wird auf $false gesetzt sobald ein Teilskript fehlschlaegt.
# Entscheidet am Ende ob der MECM-Detection-Key (Version) in die Registry geschrieben wird.
$Fullsuccess = $true
$restartInitiated = $false
$completedSteps = 0
$detectionStatus = 'not_attempted'
$knownStepTotal = $null

try {

Write-Host @"



    A      P P P    L       W   W   W
   A A     P     P  L       W   W   W
  AAAAA    P P P    L       W W W W W
 A     A   P        L       W W W W W
A       A  P        L L L   W   W   W


VirtuSphere
install.ps1
"@

write-host "ErrorAction: $ErrorAction`n`n"
write-host "install.ps1 beginn" -ForegroundColor Magenta
write-host "----------------------------" -ForegroundColor Magenta

# Registry-Schluessel erstellen falls noch nicht vorhanden
# Test-Path: Prueft ob ein Pfad (Datei, Ordner oder Registry-Key) existiert. Gibt $true oder $false zurueck.
# New-Item: Erstellt einen neuen Eintrag - hier einen Registry-Schluessel.
# -Force: Erstellt auch fehlende uebergeordnete Schluessel automatisch mit.
if (!(Test-Path $registryPath)) {
    New-Item -Path $registryPath -Force -ErrorAction Stop
}

# Alle PowerShell-Skripte im powershell-Unterordner alphabetisch abarbeiten
# Get-ChildItem: Listet Dateien und Ordner auf - entspricht dem dir-Befehl in CMD.
# -Filter *.ps1: Nur Dateien mit der Endung .ps1 werden zurueckgegeben.
# Sort-Object Name: Sortiert die Ergebnisse alphabetisch nach Dateiname.
#   Wichtig: 01.check-dcready.ps1 muss vor 02.dc-dns-konfig.ps1 laufen.
#   Die Nummerierung im Dateinamen steuert die Reihenfolge.
$dir_script = @(Get-ChildItem $scriptDirectory -Filter *.ps1 -ErrorAction Stop | Sort-Object Name)
$knownStepTotal = $dir_script.Count
Write-VsPackageLog -Sink wrapper -Event 'inventory' -Fields @{ total = $knownStepTotal }
Write-Host "[0/$knownStepTotal] Paket-Skripte inventarisiert." -ForegroundColor Gray
if ($dir_script.Count -eq 0) {
    Write-Host "Keine Skripte im Ordner $scriptDirectory gefunden - Installation gilt als fehlgeschlagen." -ForegroundColor Red
    Complete-VsPackageRun -ExitCode 1 -Result 'failed' -DetectionStatus $detectionStatus -Total $knownStepTotal
    exit 1
}

# foreach-Statement statt "| ForEach-Object": beide teilen sich zwar denselben
# Scope (das Setzen von $Fullsuccess wirkt in beiden Varianten nach aussen), aber
# nur beim foreach-Statement ist das auch ohne PowerShell-Detailwissen ablesbar.
# Genau diese Frage - "wirkt das $false ueberhaupt nach draussen?" - entscheidet
# hier darueber, ob MECM eine fehlgeschlagene Installation als Erfolg meldet.
$stepIndex = 0
foreach ($scriptFile in $dir_script) {
    $stepIndex++
    $stepStopwatch = [Diagnostics.Stopwatch]::StartNew()
    # Zeitstempel fuer den Log-Dateinamen
    # Get-Date -Format: Formatiert das aktuelle Datum und die Uhrzeit als Text.
    # Das Format yyyy-MM-dd_HH-mm-ss erzeugt z.B. "2025-04-08_14-30-00"
    # Bindestriche statt Doppelpunkte weil Doppelpunkte in Dateinamen unter Windows nicht erlaubt sind.
    $currentDateTime = Get-Date -Format "yyyy-MM-dd_HH-mm-ss"

    $scriptName      = $scriptFile.Name        # z.B. "01.check-dcready.ps1"
    $scriptFullPath  = $scriptFile.FullName    # Vollstaendiger Pfad inkl. Laufwerk und Ordner
    $logPath         = Join-Path $logDirectory "$($scriptName)_$currentDateTime.log"
    Write-Host "[$stepIndex/$knownStepTotal] RUN $scriptName" -ForegroundColor Gray
    Write-VsPackageLog -Sink wrapper -Event 'step_run' -Fields @{ index = $stepIndex; total = $knownStepTotal; script_name = $scriptName }
    # Join-Path: Verbindet Pfadteile korrekt mit dem richtigen Trennzeichen (\).
    # Ergebnis z.B.: C:\Program Files\VirtuSphere\Logs\01.check-dcready.ps1_2025-04-08_14-30-00.log

    # Registry-Wertname pro Skript - enthaelt Projektname und Skriptname
    # Jedes Teilskript bekommt seinen eigenen Eintrag unter dem Registry-Schluessel.
    # Beispiel: "DC-Setup-01.check-dcready.ps1"
    $registryValueName = "$projectName-$scriptName"

    # Ein Erfolg gilt nur fuer exakt diesen Skriptinhalt. Der Registry-Pfad ist
    # bereits an die Paketversion gebunden; der Hash verhindert zusaetzlich,
    # dass ein nachtraeglich unter derselben Version geaendertes Teilskript nach
    # einem Reboot oder Reparaturlauf blind uebersprungen wird.
    try {
        $scriptHash = (Get-FileHash -LiteralPath $scriptFullPath -Algorithm SHA256 -ErrorAction Stop).Hash.ToLowerInvariant()
    } catch {
        $stepStopwatch.Stop()
        Add-VsPackageStepResult -Index $stepIndex -ScriptName $scriptName -Outcome FAIL -Category 'hash_failed' -ChildExitCode $null -DetailPath $null -DurationMs $stepStopwatch.ElapsedMilliseconds
        Complete-VsPackageRun -ExitCode $null -Result 'failed' -DetectionStatus $detectionStatus -Total $knownStepTotal
        throw
    }
    $successMarkerPrefix = "Erfolg:$scriptHash - "

    # Pruefen ob das Skript bereits erfolgreich ausgefuehrt wurde
    # Get-ItemPropertyValue: Liest den Wert eines Registry-Eintrags.
    # -ea 0: Kurzform fuer -ErrorAction SilentlyContinue - Fehler werden still ignoriert.
    # Wenn der Wert nicht existiert wirft Get-ItemPropertyValue eine Exception,
    # die der catch-Block abfaengt und $status auf "not installed" setzt.
    $fullregistrypath = "$registryPath"
    if(Test-Path -Path "$fullregistrypath"){
        try{
            $status = Get-ItemPropertyValue -Path $registryPath -Name $registryValueName -ea 0
        } catch {
            $status = "not installed"
        }
    } else {
        $status = "not installed"
    }

    write-host "Status $scriptName : $status" -ForegroundColor Gray

    # Skript ausfuehren wenn es noch nicht erfolgreich war
    # Nur ein Erfolg fuer denselben SHA-256-Inhalt darf uebersprungen werden.
    # Alte Marker ohne Hash werden einmalig erneut ausgefuehrt.
    if ($status -eq "not installed" -or -not ([string]$status).StartsWith($successMarkerPrefix, [System.StringComparison]::Ordinal)) {

        # Erst der Hash-Miss macht diesen Aufruf zu einer Reparatur. Vor dem
        # Kindprozess darf die alte Gesamt-Detection nicht mehr gelten, auch
        # wenn das Kind fehlschlaegt oder mit 1641 einen Neustart einleitet.
        # Fehlende Version ist normal; ein Lese-/Loeschfehler dagegen sperrt
        # die Nutzlast. Erfolgreiche Hash-Skips beruehren den Marker nicht.
        try {
            $packageState = Get-ItemProperty -LiteralPath $registryPath -ErrorAction Stop
            if ($null -ne $packageState.PSObject.Properties['Version']) {
                Remove-ItemProperty -LiteralPath $registryPath -Name 'Version' -ErrorAction Stop
            }
        } catch {
            Write-Host "Detection-Marker konnte vor der Reparatur nicht invalidiert werden: $($_.Exception.Message)" -ForegroundColor Red
            $stepStopwatch.Stop()
            Add-VsPackageStepResult -Index $stepIndex -ScriptName $scriptName -Outcome FAIL -Category 'detection_invalidation_failed' -ChildExitCode $null -DetailPath $null -DurationMs $stepStopwatch.ElapsedMilliseconds
            Complete-VsPackageRun -ExitCode 1 -Result 'failed' -DetectionStatus $detectionStatus -Total $knownStepTotal
            exit 1
        }

        $success = 'Fehler'
        $stepFailed = $false
        $stepCategory = 'child_exit'
        $childExitCode = $null
        try {
            Write-Host "Fuehre Skript aus." -ForegroundColor Green

            # Skript in einem neuen PowerShell-Prozess ausfuehren
            # & (Aufrufoperator): Fuehrt einen Befehl oder eine Datei aus.
            # -NoProfile: alles hier laeuft als SYSTEM, und ein maschinenweites
            #   Profil (AllUsersAllHosts) ist Fremdcode im Installationsprozess -
            #   es kann Kodierung, PSModulePath oder $ErrorActionPreference
            #   setzen, die das Teilskript nicht erwartet.
            # -ExecutionPolicy Bypass: umgeht die Ausfuehrungsrichtlinie fuer
            #   diesen Prozess.
            # -NonInteractive: ohne den Schalter kann eine Rueckfrage (Read-Host,
            #   eine Bestaetigung) die Bereitstellung haengen lassen, bis MECM
            #   sie abbricht. Ein Paketautor, der interaktiv liest, bekommt jetzt
            #   einen Fehler statt eines Haengers.
            # Dieselben Schalter wie $script:VsPowerShellArgs in
            #   VirtuSphere-Common.ps1, hier als Literal: diese Datei wird
            #   einzeln in den Paketordner kopiert und sieht Common nie.
            # -File: Gibt an dass eine Skriptdatei ausgefuehrt werden soll.
            # *> $logPath: Leitet alle Ausgaben (stdout und stderr) in die Log-Datei um.
            & PowerShell.exe -NoProfile -ExecutionPolicy Bypass -NonInteractive -File $scriptFullPath *> $logPath

            $childExitCode = $LASTEXITCODE

            # $LASTEXITCODE: Automatische Variable - enthaelt den Exit-Code des zuletzt
            # ausgefuehrten externen Prozesses (hier: PowerShell.exe).
            # Die Erfolgscodes stehen als $successExitCodes am Kopf der Datei,
            # samt ihrer Bedeutung - genau einmal.
            if ($successExitCodes -contains $childExitCode) {
                $success = "Erfolg"
                Write-Host "Skript $scriptName wurde erfolgreich durchgelaufen. Exit-Code: $childExitCode" -ForegroundColor Green
                # Neustartwunsch merken. Nur der HOECHSTRANGIGE gewinnt, und nur
                # aus einem tatsaechlich gelaufenen Teilskript: ein Paket, das
                # beim ersten Lauf 3010 lieferte und beim zweiten uebersprungen
                # wird (Skip-Zweig unten), darf nicht ewig Neustarts anfordern.
                foreach ($code in $rebootExitCodes) {
                    if ($childExitCode -eq $code) {
                        if ($rebootCode -eq 0 -or ($rebootExitCodes.IndexOf($code) -lt $rebootExitCodes.IndexOf($rebootCode))) {
                            $rebootCode = $code
                        }
                        break
                    }
                }
            } else {
                $stepFailed = $true
                $Fullsuccess = $false
                $stepCategory = 'child_exit'
                Write-Host "Fehler beim Ausfuehren von Skript $scriptName. Exit-Code: $childExitCode" -ForegroundColor Red
            }

            write-host "Log: $logPath" -ForegroundColor Cyan

        } catch {
            # catch: Faengt Fehler ab die PowerShell selbst ausloest (z.B. Datei nicht gefunden).
            # Wird nicht ausgeloest durch exit-Codes der Teilskripte - nur durch echte PS-Exceptions.
            $success = "Fehler"
            $stepFailed = $true
            $Fullsuccess = $false
            $stepCategory = 'child_start_failed'
            Write-Host "Ausnahme beim Ausfuehren von Skript $scriptName`: $($_.Exception.Message)" -ForegroundColor Red
        }

        # Ergebnis des Skripts in der Registry speichern
        # Set-ItemProperty: Schreibt einen Wert in einen Registry-Schluessel.
        # Format: "Erfolg:<sha256> - 2025-04-08 14:30:00" oder
        # "Fehler:<sha256> - 2025-04-08 14:30:00"
        # Beim naechsten Aufruf von install.ps1 wird dieser Wert gelesen.
        # Nur ein Erfolg fuer denselben Inhalt wird uebersprungen (Skip-Logik oben).
        $currentDateTime = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
        try {
            Set-ItemProperty -Path $registryPath -Name $registryValueName -Value "${success}:$scriptHash - $currentDateTime" -ErrorAction Stop
        } catch {
            Write-Host "Schrittstatus fuer $scriptName konnte nicht geschrieben werden: $($_.Exception.Message)" -ForegroundColor Red
            $stepStopwatch.Stop()
            Add-VsPackageStepResult -Index $stepIndex -ScriptName $scriptName -Outcome FAIL -Category 'status_write_failed' -ChildExitCode $childExitCode -DetailPath $logPath -DurationMs $stepStopwatch.ElapsedMilliseconds
            Complete-VsPackageRun -ExitCode 1 -Result 'failed' -DetectionStatus $detectionStatus -Total $knownStepTotal
            exit 1
        }

        if ($stepFailed) {
            $stepStopwatch.Stop()
            Add-VsPackageStepResult -Index $stepIndex -ScriptName $scriptName -Outcome FAIL -Category $stepCategory -ChildExitCode $childExitCode -DetailPath $logPath -DurationMs $stepStopwatch.ElapsedMilliseconds
            if ($ErrorAction -eq 'Stop') {
                Write-Host "ErrorAction ist auf Stop. Exit" -ForegroundColor DarkYellow
                Complete-VsPackageRun -ExitCode 1 -Result 'failed' -DetectionStatus $detectionStatus -Total $knownStepTotal
                exit 1
            }
            Write-Host "ErrorAction ist auf Continue. Fahre mit naechstem Skript fort..." -ForegroundColor DarkYellow
            continue
        }

        $stepStopwatch.Stop()
        Add-VsPackageStepResult -Index $stepIndex -ScriptName $scriptName -Outcome OK -Category 'child_success' -ChildExitCode $childExitCode -DetailPath $logPath -DurationMs $stepStopwatch.ElapsedMilliseconds
        $completedSteps++
        if ($childExitCode -eq 1641) {
            # Der Neustart laeuft bereits. Keine weitere Nutzlast starten und
            # den finalen Detection-Marker erst im Folgelauf setzen.
            $restartInitiated = $true
            Write-Host 'Exit-Code 1641: Neustart wurde eingeleitet. Weitere Schritte warten bis zum Folgelauf.' -ForegroundColor Yellow
            break
        }

    } else {
        write-host "Skript $scriptFullPath wurde bereits erfolgreich ausgefuehrt. Skip" -ForegroundColor Gray
        $stepStopwatch.Stop()
        Add-VsPackageStepResult -Index $stepIndex -ScriptName $scriptName -Outcome SKIP -Category 'hash_match' -ChildExitCode $null -DetailPath $null -DurationMs $stepStopwatch.ElapsedMilliseconds
        $completedSteps++
    }
}

# MECM Detection Clause schreiben
# Nur wenn ALLE Skripte erfolgreich waren wird der Version-Wert in die Registry geschrieben.
# MECM prueft nach der Installation genau diesen Registry-Wert als Detection Clause:
#   Pfad:  HKLM\Software\VirtuSphere\Packages\{ProjectName}-{Version}
#   Wert:  Version = "{version}"
# Existiert dieser Wert, markiert MECM die Application als "Installiert".
# Fehlt er (weil $Fullsuccess = $false), zeigt MECM "Fehler" an.
if ($Fullsuccess -and -not $restartInitiated -and $completedSteps -eq $dir_script.Count) {
    try {
        Set-ItemProperty -Path $registryPath -Name "Version" -Value $config.version -ErrorAction Stop
        $detectionStatus = 'written'
    } catch {
        $detectionStatus = 'failed'
        Write-Host "Detection-Marker konnte nicht geschrieben werden: $($_.Exception.Message)" -ForegroundColor Red
        Complete-VsPackageRun -ExitCode 1 -Result 'failed' -DetectionStatus $detectionStatus -Total $knownStepTotal
        exit 1
    }
}

write-host "----------------------------" -ForegroundColor Magenta
write-host "install.ps1 finish" -ForegroundColor Magenta

# Gesamtergebnis als Exit-Code zurueckgeben. Rangfolge:
#
#   Fehler (1)  >  1641 (Neustart eingeleitet)  >  3010 (Neustart noetig)  >  0
#
# Ein Fehlschlag gewinnt, sonst meldete ein Paket "bitte neu starten" statt "hat
# nicht funktioniert". 1641 gewinnt gegen 3010, weil der Neustart dort schon
# eingeleitet ist und MECM sonst einen zweiten planen wuerde.
#
# 3010 und 1641 stehen in der MECM-Standard-Rueckgabecodetabelle als
# Neustart-Erfolg; es ist also kein Eingriff in der Konsole noetig, damit der
# Deployment-Type sie versteht (er steht auf RebootBehavior = 'BasedOnExitCode').
if (-not $Fullsuccess) {
    write-host "Mindestens ein Teilskript ist fehlgeschlagen - Exit-Code 1." -ForegroundColor Red
    Complete-VsPackageRun -ExitCode 1 -Result 'failed' -DetectionStatus $detectionStatus -Total $knownStepTotal
    exit 1
}
if ($restartInitiated) {
    Write-Host 'Neustart wurde bereits eingeleitet - Exit-Code 1641 wird an MECM weitergereicht.' -ForegroundColor Yellow
    Complete-VsPackageRun -ExitCode 1641 -Result 'reboot_initiated' -DetectionStatus $detectionStatus -Total $knownStepTotal
    exit 1641
}
if ($rebootCode -ne 0) {
    write-host "Alle Teilskripte erfolgreich, ein Neustart ist noetig - Exit-Code $rebootCode wird an MECM weitergereicht." -ForegroundColor Yellow
    Complete-VsPackageRun -ExitCode $rebootCode -Result 'reboot_required' -DetectionStatus $detectionStatus -Total $knownStepTotal
    exit $rebootCode
}
Complete-VsPackageRun -ExitCode 0 -Result 'success' -DetectionStatus $detectionStatus -Total $knownStepTotal
exit 0
} catch {
    if (-not $script:VsPackageRunCompleted) {
        Write-VsPackageLog -Sink wrapper -Event 'wrapper_exception' -Fields @{ category = 'unhandled_exception' }
        Complete-VsPackageRun -ExitCode $null -Result 'failed' -DetectionStatus $detectionStatus -Total $knownStepTotal
    }
    throw
} finally {
    Close-VsPackageLogSink
}
