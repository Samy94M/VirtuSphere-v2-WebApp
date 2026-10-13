# Dot-sourced check module. Importing defines functions only.

function New-CheckArtifactDirectory {
    # One naming rule for every run directory: <Prefix><Stamp>-<PID>-<8 hex>.
    # Gate logs keep the default 'virtusphere-qa-' prefix because backup watchers
    # look for it. The guard harness ('virtusphere-guards-') and the visual
    # artifacts ('visual-baselines-', 'visual-baseline-review-') pass their own.
    # Every run gets its own directory. Runs started in the same second used to
    # share one directory, and the run without -KeepArtifacts deleted it under
    # the other run. An existing directory is never reused; a name collision
    # draws a new id.
    param(
        [Parameter(Mandatory = $true)][string]$Root,
        [Parameter(Mandatory = $true)][string]$Stamp,
        [ValidatePattern('^[A-Za-z0-9-]+-$')][string]$Prefix = 'virtusphere-qa-'
    )
    $maxAttempts = 5
    for ($attempt = 1; $attempt -le $maxAttempts; $attempt++) {
        $id = [guid]::NewGuid().ToString('N').Substring(0, 8)
        $path = Join-Path $Root ($Prefix + $Stamp + '-' + $PID + '-' + $id)
        try {
            New-Item -ItemType Directory -Path $path -ErrorAction Stop | Out-Null
            return $path
        } catch {
            # Only a name collision is retried; any other failure is rethrown.
            if (-not (Test-Path -LiteralPath $path)) { throw }
        }
    }
    throw ("Kein neues Laufverzeichnis unter '{0}' anlegbar ({1} Versuche, alle belegt)." -f $Root, $maxAttempts)
}
