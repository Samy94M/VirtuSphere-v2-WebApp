$script:VsPowerShellArgs = '-NoProfile -ExecutionPolicy Bypass -NonInteractive'

& 'C:\Windows\System32\WindowsPowerShell\v1.0\powershell.exe' -NoProfile -File '.\native.ps1'
Start-Process -FilePath 'pwsh.exe' -ArgumentList '-NoProfile -File ".\child.ps1"'
New-ScheduledTaskAction -Execute 'powershell.exe' -Argument ('{0} -File "{1}"' -f $script:VsPowerShellArgs, '.\task.ps1')
Get-VsPowerShellCommandLine -ScriptPath '.\mecm.ps1'
