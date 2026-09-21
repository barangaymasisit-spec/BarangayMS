$projectRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$ignoredDirectories = @('\uploads\', '\.git\', '\.railway\')
$watchedExtensions = @('.php', '.css', '.js', '.sql', '.html', '.toml', '.dockerfile')
$pendingDeploy = $false
$lastChange = [DateTime]::MinValue

$watcher = New-Object System.IO.FileSystemWatcher
$watcher.Path = $projectRoot
$watcher.Filter = '*.*'
$watcher.IncludeSubdirectories = $true
$watcher.NotifyFilter = [IO.NotifyFilters]::LastWrite, [IO.NotifyFilters]::FileName, [IO.NotifyFilters]::Size

$action = {
    $path = $Event.SourceEventArgs.FullPath
    $extension = [IO.Path]::GetExtension($path).ToLowerInvariant()
    $normalizedPath = $path.ToLowerInvariant()

    if ($watchedExtensions -contains $extension -and -not ($ignoredDirectories | Where-Object { $normalizedPath.Contains($_) })) {
        $script:pendingDeploy = $true
        $script:lastChange = [DateTime]::UtcNow
        Write-Host "Changed: $path"
    }
}

Register-ObjectEvent -InputObject $watcher -EventName Changed -Action $action | Out-Null
Register-ObjectEvent -InputObject $watcher -EventName Created -Action $action | Out-Null
Register-ObjectEvent -InputObject $watcher -EventName Renamed -Action $action | Out-Null
$watcher.EnableRaisingEvents = $true

Write-Host "Watching $projectRoot for changes. Press Ctrl+C to stop."

while ($true) {
    if ($pendingDeploy -and (([DateTime]::UtcNow - $lastChange).TotalSeconds -ge 3)) {
        $pendingDeploy = $false
        Write-Host 'Deploying to Railway BMS...'
        Push-Location $projectRoot
        try {
            & railway.cmd up --detach
            if ($LASTEXITCODE -eq 0) {
                Write-Host 'Railway deployment started successfully.'
            } else {
                Write-Warning "Railway deployment failed with exit code $LASTEXITCODE."
            }
        } finally {
            Pop-Location
        }
    }
    Start-Sleep -Milliseconds 500
}
