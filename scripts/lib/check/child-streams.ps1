# Dot-sourced check module. Importing defines functions only.
#
# Parallel child processes whose output is passed on line by line while they
# run. Each child writes stdout and stderr to its own file; the parent reads the
# growing stdout file and hands every complete line on at once. Files instead of
# redirected pipes because Windows PowerShell 5.1 has no safe way to read several
# pipes at the same time without event handlers. Used by scripts/test-guards.ps1
# (-Parallel) and scripts/run-pester.ps1 (partitions). ASCII only (PS 5.1 reads
# UTF-8 without BOM as ANSI).

function Start-StreamedChild {
    param(
        [Parameter(Mandatory = $true)][string]$Exe,
        [Parameter(Mandatory = $true)][string]$ArgumentLine,
        [Parameter(Mandatory = $true)][string]$OutPath,
        [Parameter(Mandatory = $true)][string]$ErrPath
    )
    $process = Start-Process -FilePath $Exe -ArgumentList $ArgumentLine -NoNewWindow -PassThru `
        -RedirectStandardOutput $OutPath -RedirectStandardError $ErrPath
    # Without an early handle access Windows PowerShell 5.1 reports no ExitCode
    # for a Start-Process object after WaitForExit.
    $null = $process.Handle
    return @{
        Process = $process; Out = $OutPath; Err = $ErrPath
        Stream = $null; Decoder = [Console]::OutputEncoding.GetDecoder(); Pending = ''
        Finished = $false; ExitCode = $null; Watch = [System.Diagnostics.Stopwatch]::StartNew()
    }
}

# Complete lines the child wrote since the last call. -Final also returns the
# unterminated rest; call it once after the child has exited.
function Read-StreamedChildLines {
    param([Parameter(Mandatory = $true)]$Child, [switch]$Final)
    if ($null -eq $Child.Stream) {
        if (-not (Test-Path -LiteralPath $Child.Out)) { return @() }
        $Child.Stream = [System.IO.File]::Open($Child.Out, [System.IO.FileMode]::Open, [System.IO.FileAccess]::Read, [System.IO.FileShare]::ReadWrite)
    }
    $buffer = New-Object byte[] 65536
    while (($read = $Child.Stream.Read($buffer, 0, $buffer.Length)) -gt 0) {
        $chars = New-Object char[] ($Child.Decoder.GetCharCount($buffer, 0, $read))
        $count = $Child.Decoder.GetChars($buffer, 0, $read, $chars, 0)
        $Child.Pending += New-Object string ($chars, 0, $count)
    }
    $parts = $Child.Pending -split "`r?`n"
    $Child.Pending = $parts[-1]
    $lines = @($parts | Select-Object -First ($parts.Count - 1))
    if ($Final -and $Child.Pending -ne '') {
        $lines += $Child.Pending
        $Child.Pending = ''
    }
    return $lines
}

# True once the child has exited; then records ExitCode and the duration. The
# caller still drains the rest with Read-StreamedChildLines -Final and closes.
function Test-StreamedChildExited {
    param([Parameter(Mandatory = $true)]$Child)
    if (-not $Child.Process.HasExited) { return $false }
    $Child.Process.WaitForExit()
    $Child.Watch.Stop()
    $Child.ExitCode = $Child.Process.ExitCode
    return $true
}

# Releases the stdout reader and returns the non-empty stderr lines.
function Close-StreamedChild {
    param([Parameter(Mandatory = $true)]$Child)
    if ($null -ne $Child.Stream) {
        $Child.Stream.Dispose()
        $Child.Stream = $null
    }
    $Child.Finished = $true
    if (-not (Test-Path -LiteralPath $Child.Err)) { return @() }
    return @(Get-Content -LiteralPath $Child.Err | Where-Object { $_ -ne '' })
}

# Only for an aborted parent: ends the still running children with their own
# process trees (by their own PID, never by a name pattern).
function Stop-StreamedChildren {
    param([object[]]$Children = @())
    foreach ($child in $Children) {
        if ($null -eq $child) { continue }
        if ($null -ne $child.Stream) {
            $child.Stream.Dispose()
            $child.Stream = $null
        }
        if (-not $child.Process.HasExited) {
            if ([System.Environment]::OSVersion.Platform -eq [System.PlatformID]::Win32NT) {
                & taskkill.exe /T /F /PID $child.Process.Id 2>&1 | Out-Null
            } else {
                # pwsh on Linux (CI): no taskkill; the child itself by its PID.
                Stop-Process -Id $child.Process.Id -Force -ErrorAction SilentlyContinue
            }
        }
    }
}
