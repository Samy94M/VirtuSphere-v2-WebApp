# Dot-sourced check module. Importing defines functions only.

function Add-Gate {
    param(
        [string]$Name,
        [string[]]$Lanes,
        [ValidateSet('native', 'container', 'windows-only')] [string]$Kind,
        [bool]$Network = $false,
        [scriptblock]$Body
    )
    [void]$gates.Add(@{ Name = $Name; Lanes = $Lanes; Kind = $Kind; Network = $Network; Body = $Body })
}
