param([string]$TaskName = 'Photobooth Docker Compose', [switch]$Force)
$ErrorActionPreference = 'Stop'

$launcher = Join-Path $PSScriptRoot 'start-photobooth.ps1'
if (-not (Test-Path -LiteralPath $launcher -PathType Leaf)) { throw 'start-photobooth.ps1 is missing.' }
$existingTask = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
if ($existingTask -and -not $Force) {
    throw 'A task with this name already exists. Choose a different TaskName.'
}

$arguments = '-NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + $launcher + '"'
$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $arguments -WorkingDirectory $PSScriptRoot
$trigger = New-ScheduledTaskTrigger -AtLogOn -User ([System.Security.Principal.WindowsIdentity]::GetCurrent().Name)
$trigger.Delay = 'PT45S'
$watchdog = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(5) `
    -RepetitionInterval (New-TimeSpan -Minutes 5) -RepetitionDuration (New-TimeSpan -Days 3650)
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -RestartCount 5 -RestartInterval (New-TimeSpan -Minutes 1) `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 10) -MultipleInstances IgnoreNew
Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger @($trigger, $watchdog) -Settings $settings `
    -Description 'Wait for Docker Desktop, start Photobooth Compose, and verify container health' -Force:$Force | Out-Null
Write-Output 'Photobooth Docker Compose will start after sign-in and self-check every five minutes.'
