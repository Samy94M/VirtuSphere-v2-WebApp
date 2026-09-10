$processName = 'powershell.exe'
$example = 'powershell.exe -NoProfile -File example.ps1'
Get-CimInstance -ClassName Win32_Process -Filter "Name='powershell.exe' OR Name='pwsh.exe'"
