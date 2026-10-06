param([int]$DockerWaitSeconds = 180, [int]$HealthWaitSeconds = 90)
$ErrorActionPreference = 'Stop'

$projectPath = [IO.Path]::GetFullPath($PSScriptRoot)
$composePath = Join-Path $projectPath 'docker-compose.yml'
if (-not (Test-Path -LiteralPath $composePath -PathType Leaf)) {
    throw 'docker-compose.yml was not found next to start-photobooth.ps1.'
}

$dockerCommand = Get-Command docker.exe -ErrorAction SilentlyContinue
$docker = if ($dockerCommand) { $dockerCommand.Source } else { 'C:\Program Files\Docker\Docker\resources\bin\docker.exe' }
if (-not (Test-Path -LiteralPath $docker -PathType Leaf)) {
    throw 'Docker CLI is not installed.'
}

$dockerDeadline = (Get-Date).AddSeconds($DockerWaitSeconds)
do {
    & $docker version --format '{{.Server.Version}}' *> $null
    if ($LASTEXITCODE -eq 0) { break }
    Start-Sleep -Seconds 3
} while ((Get-Date) -lt $dockerDeadline)
if ($LASTEXITCODE -ne 0) { throw 'Docker engine did not become ready before the deadline.' }

Push-Location $projectPath
try {
    & $docker compose up -d --remove-orphans
    if ($LASTEXITCODE -ne 0) { throw 'docker compose up failed.' }

    $healthDeadline = (Get-Date).AddSeconds($HealthWaitSeconds)
    do {
        $containerId = & $docker compose ps -q photobooth 2>$null | Select-Object -First 1
        $health = if ($containerId) {
            & $docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' $containerId 2>$null
        } else {
            'missing'
        }
        if ($containerId -and $LASTEXITCODE -eq 0 -and $health -eq 'healthy') {
            Write-Output 'Photobooth Docker container is healthy.'
            exit 0
        }
        Start-Sleep -Seconds 2
    } while ((Get-Date) -lt $healthDeadline)
    throw "Photobooth container did not become healthy (last state: $health)."
} finally {
    Pop-Location
}
