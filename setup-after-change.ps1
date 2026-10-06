<#
.SYNOPSIS
Builds, tests, backs up, and deploys Photobooth after source or UI changes.

.DESCRIPTION
The default workflow builds frontend assets and the Docker image, runs software
tests, backs up the current runtime volumes, recreates only the photobooth
service, and waits for a healthy HTTP response. Named volumes, the Windows
Camera Agent, and the Sony runtime are preserved.

.EXAMPLE
.\setup-after-change.ps1

.EXAMPLE
.\setup-after-change.ps1 -WhatIf

.EXAMPLE
.\setup-after-change.ps1 -SkipTests

.NOTES
Use -SkipBackup only when the existing runtime data is already protected.
This script never runs docker compose down -v.
#>

[CmdletBinding(SupportsShouldProcess = $true, ConfirmImpact = 'Medium')]
param(
    [switch]$SkipAssetBuild,
    [switch]$SkipTests,
    [switch]$SkipBackup,
    [int]$HealthWaitSeconds = 120,
    [string]$HealthUrl = 'http://127.0.0.1/'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Resolve-RequiredCommand {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Name,

        [string]$FallbackPath = ''
    )

    $command = Get-Command $Name -ErrorAction SilentlyContinue
    if ($command) {
        return $command.Source
    }

    if ($FallbackPath -and (Test-Path -LiteralPath $FallbackPath -PathType Leaf)) {
        return $FallbackPath
    }

    throw "Required command is not available: $Name"
}

function Invoke-NativeCommand {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Step,

        [Parameter(Mandatory = $true)]
        [string]$Executable,

        [Parameter(Mandatory = $true)]
        [string[]]$Arguments
    )

    Write-Host "`n[$Step]"
    & $Executable @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "$Step failed with exit code $LASTEXITCODE."
    }
}

function Wait-ForPhotoboothHealth {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Docker,

        [Parameter(Mandatory = $true)]
        [int]$TimeoutSeconds,

        [Parameter(Mandatory = $true)]
        [string]$Url
    )

    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    $lastState = 'missing'

    do {
        $containerId = & $Docker compose ps -q photobooth 2>$null | Select-Object -First 1
        if ($containerId) {
            $lastState = & $Docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' $containerId 2>$null
            if ($LASTEXITCODE -eq 0 -and $lastState -eq 'healthy') {
                try {
                    $response = Invoke-WebRequest -UseBasicParsing -Uri $Url -TimeoutSec 10
                    if ($response.StatusCode -eq 200) {
                        Write-Host "Photobooth is healthy and $Url returned HTTP 200."
                        return
                    }
                    $lastState = "healthy, HTTP $($response.StatusCode)"
                } catch {
                    $lastState = "healthy, HTTP check failed: $($_.Exception.Message)"
                }
            }
        }

        Start-Sleep -Seconds 2
    } while ((Get-Date) -lt $deadline)

    throw "Photobooth did not become ready before the deadline (last state: $lastState)."
}

function Backup-PhotoboothRuntime {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Docker,

        [Parameter(Mandatory = $true)]
        [string]$ContainerId,

        [Parameter(Mandatory = $true)]
        [string]$WorkspacePath
    )

    $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
    $backupRoot = [IO.Path]::GetFullPath((Join-Path $WorkspacePath 'photobooth-backups'))
    $backupDirectory = [IO.Path]::GetFullPath((Join-Path $backupRoot "predeploy-$stamp"))

    if (-not $backupDirectory.StartsWith($backupRoot + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) {
        throw 'Resolved backup path is outside the expected backup directory.'
    }

    New-Item -ItemType Directory -Path $backupDirectory -Force | Out-Null

    $currentUser = [Security.Principal.WindowsIdentity]::GetCurrent().Name
    & icacls.exe $backupDirectory '/inheritance:r' '/grant:r' "$currentUser`:(OI)(CI)F" '*S-1-5-18:(OI)(CI)F' *> $null
    if ($LASTEXITCODE -ne 0) {
        throw 'Could not restrict the runtime backup directory ACL.'
    }

    $archiveName = "photobooth-predeploy-$stamp.tar.gz"
    $containerArchive = "/tmp/$archiveName"
    $hostArchive = Join-Path $backupDirectory $archiveName

    try {
        Invoke-NativeCommand -Step 'Create runtime backup inside the current container' -Executable $Docker -Arguments @(
            'exec', '--user', 'root', $ContainerId,
            'tar', '-czf', $containerArchive, '-C', '/',
            'app/config', 'app/data', 'app/private', 'app/var', 'sessions'
        )
        Invoke-NativeCommand -Step 'Copy runtime backup to the host' -Executable $Docker -Arguments @(
            'cp', "${ContainerId}:$containerArchive", $hostArchive
        )
    } finally {
        & $Docker exec --user root $ContainerId rm -f $containerArchive *> $null
    }

    if (-not (Test-Path -LiteralPath $hostArchive -PathType Leaf)) {
        throw 'Runtime backup archive was not created.'
    }

    $tar = Resolve-RequiredCommand -Name 'tar.exe'
    Write-Host "`n[Verify runtime backup archive]"
    & $tar -tzf $hostArchive *> $null
    if ($LASTEXITCODE -ne 0) {
        throw 'Runtime backup archive verification failed.'
    }

    $hash = (Get-FileHash -LiteralPath $hostArchive -Algorithm SHA256).Hash
    Set-Content -LiteralPath "$hostArchive.sha256" -Value "$hash  $archiveName" -Encoding ascii
    Write-Host "Runtime backup: $hostArchive"

    return $hostArchive
}

$projectPath = [IO.Path]::GetFullPath($PSScriptRoot).TrimEnd([IO.Path]::DirectorySeparatorChar)
$workspacePath = [IO.Path]::GetFullPath((Split-Path -Parent $projectPath)).TrimEnd([IO.Path]::DirectorySeparatorChar)
$composePath = Join-Path $projectPath 'docker-compose.yml'
$packagePath = Join-Path $projectPath 'package.json'

if (-not (Test-Path -LiteralPath $composePath -PathType Leaf)) {
    throw 'docker-compose.yml was not found next to this script.'
}
if (-not (Test-Path -LiteralPath $packagePath -PathType Leaf)) {
    throw 'package.json was not found next to this script.'
}

$docker = Resolve-RequiredCommand -Name 'docker.exe' -FallbackPath 'C:\Program Files\Docker\Docker\resources\bin\docker.exe'
$npm = if (-not $SkipAssetBuild -or -not $SkipTests) { Resolve-RequiredCommand -Name 'npm.cmd' } else { $null }
$node = if (-not $SkipTests) { Resolve-RequiredCommand -Name 'node.exe' } else { $null }

Push-Location $projectPath
try {
    Invoke-NativeCommand -Step 'Check Docker engine' -Executable $docker -Arguments @('version', '--format', '{{.Server.Version}}')
    Invoke-NativeCommand -Step 'Validate Docker Compose configuration' -Executable $docker -Arguments @('compose', 'config', '--quiet')

    if (-not $SkipAssetBuild) {
        if (-not (Test-Path -LiteralPath (Join-Path $projectPath 'node_modules') -PathType Container)) {
            throw 'node_modules is missing. Run npm ci before this setup script.'
        }

        if ($PSCmdlet.ShouldProcess($projectPath, 'Build Sass, JavaScript, and frontend assets')) {
            Invoke-NativeCommand -Step 'Build frontend assets' -Executable $npm -Arguments @('run', 'build:gulp')
        }
    }

    if (-not $SkipTests -and $PSCmdlet.ShouldProcess($projectPath, 'Run frontend checks')) {
        Invoke-NativeCommand -Step 'Run ESLint' -Executable $npm -Arguments @('run', 'eslint')

        $javascriptTests = Get-ChildItem -LiteralPath (Join-Path $projectPath 'tests\js') -Filter '*.test.cjs' -File |
            Sort-Object FullName |
            Select-Object -ExpandProperty FullName
        if (-not $javascriptTests) {
            throw 'No JavaScript tests were found in tests/js.'
        }
        Invoke-NativeCommand -Step 'Run JavaScript tests' -Executable $node -Arguments (@('--test') + [string[]]$javascriptTests)
    }

    $currentContainerId = & $docker compose ps -q photobooth 2>$null | Select-Object -First 1
    $currentImageId = if ($currentContainerId) {
        & $docker inspect --format '{{.Image}}' $currentContainerId 2>$null
    } else {
        $null
    }

    $imageBuilt = $false
    $newImageId = $null
    if ($PSCmdlet.ShouldProcess('photobooth Docker image', 'Build')) {
        Invoke-NativeCommand -Step 'Build Photobooth Docker image' -Executable $docker -Arguments @('compose', 'build', 'photobooth')
        $imageBuilt = $true
        # Collect all output first: Select-Object -First 1 stops docker early and sets exit code -1.
        $newImageName = @(& $docker compose config --images 2>$null)[0]
        if (-not $newImageName -or $LASTEXITCODE -ne 0) {
            throw 'Could not resolve the newly built Photobooth image name.'
        }
        $newImageId = & $docker image inspect --format '{{.Id}}' $newImageName 2>$null
        if (-not $newImageId -or $LASTEXITCODE -ne 0) {
            throw 'Could not resolve the newly built Photobooth image.'
        }
    }

    if (-not $SkipTests -and $imageBuilt -and $PSCmdlet.ShouldProcess('new Photobooth image', 'Run PHPUnit')) {
        Invoke-NativeCommand -Step 'Run PHPUnit in the new image' -Executable $docker -Arguments @(
            'run', '--rm', '--entrypoint', 'php',
            $newImageId, 'bin/composer', 'phpunit'
        )
    }

    $backupArchive = $null
    if (-not $SkipBackup -and $currentContainerId -and $PSCmdlet.ShouldProcess('Photobooth runtime volumes', 'Create pre-deploy backup')) {
        $backupArchive = Backup-PhotoboothRuntime -Docker $docker -ContainerId $currentContainerId -WorkspacePath $workspacePath
    } elseif (-not $SkipBackup -and -not $currentContainerId) {
        Write-Warning 'No existing Photobooth container was found; no runtime backup was created.'
    }

    if ($currentImageId -and $imageBuilt -and $PSCmdlet.ShouldProcess($currentImageId, 'Tag current image for rollback')) {
        $rollbackTag = 'photobooth-photobooth:predeploy-' + (Get-Date -Format 'yyyyMMdd-HHmmss')
        Invoke-NativeCommand -Step 'Tag current image for rollback' -Executable $docker -Arguments @('tag', $currentImageId, $rollbackTag)
        Write-Host "Rollback image: $rollbackTag"
    }

    $deployed = $false
    if ($imageBuilt -and $PSCmdlet.ShouldProcess('photobooth service', 'Recreate with the new image while preserving named volumes')) {
        Invoke-NativeCommand -Step 'Deploy Photobooth service' -Executable $docker -Arguments @(
            'compose', 'up', '-d', '--no-build', '--force-recreate', 'photobooth'
        )
        $deployed = $true
    }

    if ($deployed) {
        Wait-ForPhotoboothHealth -Docker $docker -TimeoutSeconds $HealthWaitSeconds -Url $HealthUrl
        Write-Host "`nSETUP COMPLETED"
        Write-Host 'Named volumes were preserved. Camera Agent and Sony runtime were not modified.'
        if ($backupArchive) {
            Write-Host "Backup retained at: $backupArchive"
        }
    } elseif ($WhatIfPreference) {
        Write-Host "`nWHATIF COMPLETED - no build, backup, or deployment changes were made."
    } else {
        Write-Warning 'No deployment was performed.'
    }
} finally {
    Pop-Location
}
