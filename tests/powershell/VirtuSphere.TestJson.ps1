# Shared by the reporter suites: reads JSON the way Windows PowerShell 5.1 does,
# the engine the client scripts run on. pwsh 7 turns ISO timestamps into
# DateTime, and binding one to a [string] parameter reformats it in the current
# culture, so a fixture timestamp would stop matching the V1 wire format.
function ConvertFrom-VsTestJson {
    param([Parameter(Mandatory, ValueFromPipeline)][string]$Json)
    process {
        if ((Get-Command ConvertFrom-Json).Parameters.ContainsKey('DateKind')) {
            return (ConvertFrom-Json -InputObject $Json -DateKind String)
        }
        return (ConvertFrom-Json -InputObject $Json)
    }
}
