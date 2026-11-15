# Dot-sourced check module. Importing defines functions only.

function Add-Gate {
    param(
        [string]$Name,
        [string[]]$Lanes,
        [ValidateSet('native', 'container', 'windows-only')] [string]$Kind,
        [bool]$Network = $false,
        # Advisory: the result can change without a commit, because it reads
        # external advisory or CVE data. check.ps1 -Advisory selects exactly
        # these gates of a lane (nightly CI, QL3 A5), so no caller keeps a list.
        [bool]$Advisory = $false,
        [scriptblock]$Body
    )
    [void]$gates.Add(@{ Name = $Name; Lanes = $Lanes; Kind = $Kind; Network = $Network; Advisory = $Advisory; Body = $Body })
}
