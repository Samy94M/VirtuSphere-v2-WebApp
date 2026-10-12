# Dot-sourced check module. Importing defines functions only.

function New-CheckArtifactDirectory {
    # Every run gets its own directory. Runs started in the same second used to
    # share one directory, and the run without -KeepArtifacts deleted it under
    # the other run. The 'virtusphere-qa-' prefix stays because backup watchers
    # look for it.
    param(
        [Parameter(Mandatory = $true)][string]$Root,
        [Parameter(Mandatory = $true)][string]$Stamp
    )
    $maxAttempts = 5
    for ($attempt = 1; $attempt -le $maxAttempts; $attempt++) {
        $id = [guid]::NewGuid().ToString('N').Substring(0, 8)
        $path = Join-Path $Root ('virtusphere-qa-' + $Stamp + '-' + $PID + '-' + $id)
        try {
            New-Item -ItemType Directory -Path $path -ErrorAction Stop | Out-Null
            return $path
        } catch {
            # Only a name collision is retried; any other failure is rethrown.
            if (-not (Test-Path -LiteralPath $path)) { throw }
        }
    }
    throw ("Kein neues Gate-Log-Verzeichnis unter '{0}' anlegbar ({1} Versuche, alle belegt)." -f $Root, $maxAttempts)
}
