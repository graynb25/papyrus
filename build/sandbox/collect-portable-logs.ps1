# Collect the portable guest's logs and runtime facts into C:\results.
#
# ## Why this exists
#
# The console says the migration failed and points at the data directory's `logs\migrate.log`.
# That file is inside the guest, and the host can only read the mapped results folder. This copies
# the logs and a short runtime report there, with secrets redacted, so the failure can be diagnosed
# from the host.
#
# ## Which layout it reads
#
# The session file names the app and data roots, because a run can be on C: or on a second volume.
# The collector never guesses the paths, and a session that names neither is reported rather than
# collected from a stale location.
#
# ## How to run it
#
# From the guest, open PowerShell and run:
#
#     powershell -NoProfile -ExecutionPolicy Bypass -File C:\sandbox\collect-portable-logs.ps1
#
# Or double-click collect-portable-logs.cmd in the Open results folder. The report lands in
# C:\results\portable-diagnostics.

$ErrorActionPreference = 'Continue'

$results = 'C:\results'
$sessionPath = Join-Path $results 'session.txt'
$session = @{}

if (Test-Path -LiteralPath $sessionPath) {
    foreach ($line in Get-Content -LiteralPath $sessionPath) {
        if ($line -match '^([^=]+)=(.*)$') {
            $session[$matches[1]] = $matches[2]
        }
    }
}

$appRoot = if ($session.ContainsKey('app_root')) { [string]$session['app_root'] } else { '' }
$dataRoot = if ($session.ContainsKey('data_root')) { [string]$session['data_root'] } else { '' }
$driveLetter = if ($session.ContainsKey('drive')) { [string]$session['drive'] } else { '' }
$driveKind = if ($session.ContainsKey('drive_kind')) { [string]$session['drive_kind'] } else { '' }
$systemDrive = ([string]$env:SystemDrive).TrimEnd(':')
$appDrive = if ($appRoot -ne '') { ([System.IO.Path]::GetPathRoot($appRoot).TrimEnd('\')).TrimEnd(':') } else { '' }
$dataDrive = if ($dataRoot -ne '') { ([System.IO.Path]::GetPathRoot($dataRoot).TrimEnd('\')).TrimEnd(':') } else { '' }
$out = Join-Path $results 'portable-diagnostics'
$php = if ($appRoot -ne '') { Join-Path $appRoot 'runtime\bin\php.exe' } else { '' }
$migrateLog = if ($dataRoot -ne '') { Join-Path $dataRoot 'logs\migrate.log' } else { '' }

New-Item -ItemType Directory -Force -Path $out | Out-Null

$lines = [System.Collections.Generic.List[string]]::new()

function Add-Line([string]$line) {
    $script:lines.Add($line) | Out-Null
}

Add-Line "collected=$((Get-Date).ToString('o'))"
Add-Line "user=$env:USERNAME"
Add-Line "computer=$env:COMPUTERNAME"
Add-Line "drive=$driveLetter"
Add-Line "drive_kind=$driveKind"
Add-Line "system_drive=$systemDrive"
Add-Line "app_drive=$appDrive"
Add-Line "data_drive=$dataDrive"
Add-Line "separate_volume=$($appDrive -ne '' -and $appDrive -ne $systemDrive)"
Add-Line "app_root=$appRoot"
Add-Line "data_root=$dataRoot"
Add-Line "app_exists=$(if ($appRoot -ne '') { Test-Path -LiteralPath $appRoot } else { $false })"
Add-Line "data_exists=$(if ($dataRoot -ne '') { Test-Path -LiteralPath $dataRoot } else { $false })"

$volume = if ($appDrive -ne '') { Get-Volume -DriveLetter $appDrive -ErrorAction SilentlyContinue } else { $null }
$disk = $null

if ($null -ne $volume) {
    $partition = Get-Partition -DriveLetter $appDrive -ErrorAction SilentlyContinue

    if ($null -ne $partition) {
        $disk = Get-Disk -Number $partition.DiskNumber -ErrorAction SilentlyContinue
    }
}

Add-Line "volume_file_system=$(if ($null -ne $volume) { $volume.FileSystem } else { '' })"
Add-Line "volume_size_bytes=$(if ($null -ne $volume) { $volume.Size } else { 0 })"
Add-Line "volume_free_bytes=$(if ($null -ne $volume) { $volume.SizeRemaining } else { 0 })"
Add-Line "disk_bus_type=$(if ($null -ne $disk) { $disk.BusType } else { '' })"
Add-Line "disk_model=$(if ($null -ne $disk) { $disk.Model } else { '' })"

$versionPath = if ($appRoot -ne '') { Join-Path $appRoot 'version.txt' } else { '' }

if ($versionPath -ne '' -and (Test-Path -LiteralPath $versionPath)) {
    Get-Content -LiteralPath $versionPath | ForEach-Object { Add-Line "version_file=$_" }
}

foreach ($letter in @($dataDrive, $systemDrive) | Select-Object -Unique) {
    if ($letter -eq '') {
        continue
    }

    try {
        $drive = New-Object -TypeName System.IO.DriveInfo -ArgumentList $letter
        Add-Line ("free_space_{0}_mb={1}" -f $letter.ToLowerInvariant(), [math]::Round($drive.AvailableFreeSpace / 1MB))
    } catch {
        Add-Line ("free_space_{0}_mb=unknown" -f $letter.ToLowerInvariant())
    }
}

Add-Line ''
Add-Line '--- processes ---'

foreach ($name in @('php', 'frankenphp', 'java', 'cmd', 'conhost')) {
    foreach ($process in @(Get-Process -Name $name -ErrorAction SilentlyContinue)) {
        Add-Line ("process={0} pid={1} memory_mb={2}" -f $process.ProcessName, $process.Id, [math]::Round($process.WorkingSet64 / 1MB))
    }
}

Add-Line ''
Add-Line '--- listening ports ---'

foreach ($port in @(8000, 4567)) {
    $listeners = @(Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue)

    if ($listeners.Count -eq 0) {
        Add-Line "port_$port=not_listening"
    } else {
        foreach ($listener in $listeners) {
            Add-Line ("port_{0}=listening {1}:{2} pid={3}" -f $port, $listener.LocalAddress, $listener.LocalPort, $listener.OwningProcess)
        }
    }
}

Add-Line ''
Add-Line '--- data tree, first 200 files ---'

if ($dataRoot -ne '' -and (Test-Path -LiteralPath $dataRoot)) {
    Get-ChildItem -LiteralPath $dataRoot -Recurse -File -Force -ErrorAction SilentlyContinue |
        Select-Object -First 200 |
        ForEach-Object { Add-Line ("file={0} bytes={1}" -f $_.FullName, $_.Length) }
}

Add-Line ''
Add-Line '--- migrate.log ---'

if ($migrateLog -ne '' -and (Test-Path -LiteralPath $migrateLog)) {
    Get-Content -LiteralPath $migrateLog | ForEach-Object { Add-Line $_ }
} else {
    Add-Line 'migrate.log is missing'
}

Add-Line ''
Add-Line '--- data logs ---'

$dataLogs = if ($dataRoot -ne '') { Join-Path $dataRoot 'logs' } else { '' }

if ($dataLogs -ne '' -and (Test-Path -LiteralPath $dataLogs)) {
    Get-ChildItem -LiteralPath $dataLogs -File -Recurse -ErrorAction SilentlyContinue | ForEach-Object {
        Add-Line ("log={0} bytes={1}" -f $_.FullName, $_.Length)
    }
}

Add-Line ''
Add-Line '--- php -v ---'

if ($php -ne '' -and (Test-Path -LiteralPath $php)) {
    try {
        & $php -v 2>&1 | ForEach-Object { Add-Line $_ }
    } catch {
        Add-Line ("php failed: " + $_.Exception.Message)
    }
} else {
    Add-Line 'bundled php is missing'
}

Set-Content -Path (Join-Path $out 'diagnostics.txt') -Value ($lines -join "`n") -Encoding UTF8

# Copy the logs, but not the live database. The logs are small and are the point.
$logPairs = @()

if ($dataRoot -ne '') {
    $logPairs += @{ Source = (Join-Path $dataRoot 'logs'); Destination = (Join-Path $out 'data-logs') }
}

if ($appRoot -ne '') {
    $logPairs += @{ Source = (Join-Path $appRoot 'storage\logs'); Destination = (Join-Path $out 'storage-logs') }
}

foreach ($pair in $logPairs) {
    if (-not (Test-Path -LiteralPath $pair.Source)) {
        continue
    }

    New-Item -ItemType Directory -Force -Path $pair.Destination | Out-Null
    Copy-Item -Path (Join-Path $pair.Source '*') -Destination $pair.Destination -Recurse -Force -ErrorAction SilentlyContinue
}

# The .env is needed to see the database path and the runtime settings, but its secrets must not
# travel to the host. Every key that looks secret is replaced, not just APP_KEY.
$envPath = if ($dataRoot -ne '') { Join-Path $dataRoot '.env' } else { '' }

if ($envPath -ne '' -and (Test-Path -LiteralPath $envPath)) {
    $envText = Get-Content -LiteralPath $envPath -Raw
    $envText = $envText -replace '(?m)^([A-Z0-9_]*(?:KEY|PASSWORD|SECRET|TOKEN)[A-Z0-9_]*=).*$', '$1<redacted>'
    Set-Content -Path (Join-Path $out '.env.redacted') -Value $envText -Encoding UTF8
}

Add-Type -AssemblyName System.Windows.Forms
[System.Windows.Forms.MessageBox]::Show(
    "Diagnostics collected in:`n`n$out`n`nThe host can read that folder through the results mapping.",
    'Papyrus portable run',
    [System.Windows.Forms.MessageBoxButtons]::OK,
    [System.Windows.Forms.MessageBoxIcon]::Information
) | Out-Null

exit 0
