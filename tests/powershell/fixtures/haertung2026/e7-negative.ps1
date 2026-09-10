& powershell.exe -File '.\native.ps1' -NoProfile
Start-Process -FilePath 'pwsh.exe' -ArgumentList '-File ".\child.ps1" -NoProfile' -WorkingDirectory '-NoProfile'
New-ScheduledTaskAction -Execute 'powershell.exe' -Argument '$script:VsPowerShellArgs -File ".\task.ps1"'
New-ScheduledTaskAction -Execute 'powershell.exe' -Argument ('-File "{1}" {0} -Command ignored' -f $script:VsPowerShellArgs, '.\task.ps1')
