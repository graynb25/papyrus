# Check where the portable build put its data, from the desktop shortcut.
#
# ## Why this is separate from the screenshot loop
#
# A screenshot shows the reader pages. It does not show whether %LOCALAPPDATA%\Papyrus appeared, or
# whether an uninstall entry was written. This writes those facts to portable-layout.txt so the host
# can read them after the guest is closed.
#
# ## When to run it
#
# After the first launch has finished and the reader page is visible. The data directory is created
# during that launch. Running it before then reports a failure because there is nothing to inspect.

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

$appRoot = if ($session.ContainsKey('app_root')) { [string]$session['app_root'] } else { 'C:\Papyrus Portable\Papyrus' }
$dataRoot = if ($session.ContainsKey('data_root')) { [string]$session['data_root'] } else { 'C:\Papyrus Portable\papyrus-data' }
$driveLetter = if ($session.ContainsKey('drive')) { [string]$session['drive'] } else { ([System.IO.Path]::GetPathRoot($appRoot).TrimEnd('\')).TrimEnd(':') }
$driveKind = if ($session.ContainsKey('drive_kind')) { [string]$session['drive_kind'] } else { if ($driveLetter -eq 'C') { 'local' } else { 'vhd' } }
$systemDrive = ([string]$env:SystemDrive).TrimEnd(':')
$appDrive = ([System.IO.Path]::GetPathRoot($appRoot).TrimEnd('\')).TrimEnd(':')
$dataDrive = ([System.IO.Path]::GetPathRoot($dataRoot).TrimEnd('\')).TrimEnd(':')
$expectedDataRoot = Join-Path (Split-Path -Parent $appRoot) 'papyrus-data'
$dataBesideApp = $dataRoot.TrimEnd('\') -eq $expectedDataRoot.TrimEnd('\')
$separateVolume = $appDrive -ne $systemDrive
$driveMatches = $appDrive -eq $driveLetter -and $dataDrive -eq $driveLetter
$kindMatches = if ($driveKind -eq 'local') { $appDrive -eq $systemDrive } else { $separateVolume }
$localPrograms = Join-Path $env:LOCALAPPDATA 'Programs\Papyrus'
$localData = Join-Path $env:LOCALAPPDATA 'Papyrus'
$startMenu = Join-Path $env:APPDATA 'Microsoft\Windows\Start Menu\Programs'

New-Item -ItemType Directory -Force -Path $results | Out-Null

$lines = [System.Collections.Generic.List[string]]::new()

function Add-Line([string]$line) {
    Write-Host $line
    $script:lines.Add($line) | Out-Null
}

$appExists = Test-Path -LiteralPath $appRoot
$dataExists = Test-Path -LiteralPath $dataRoot
$envExists = Test-Path -LiteralPath (Join-Path $dataRoot '.env')
$databaseExists = Test-Path -LiteralPath (Join-Path $dataRoot 'database.sqlite')
$localProgramsExists = Test-Path -LiteralPath $localPrograms
$localDataExists = Test-Path -LiteralPath $localData
$dataInsideApp = Test-Path -LiteralPath (Join-Path $appRoot 'papyrus-data')

$shortcuts = @(Get-ChildItem -LiteralPath $startMenu -Filter '*.lnk' -Recurse -ErrorAction SilentlyContinue |
    Where-Object { $_.Name -like 'Papyrus*' })

$uninstallEntries = @()

foreach ($key in @(
    'HKCU:\Software\Microsoft\Windows\CurrentVersion\Uninstall',
    'HKLM:\Software\Microsoft\Windows\CurrentVersion\Uninstall',
    'HKLM:\Software\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall'
)) {
    if (-not (Test-Path -LiteralPath $key)) {
        continue
    }

    $uninstallEntries += @(Get-ChildItem -LiteralPath $key -ErrorAction SilentlyContinue |
        ForEach-Object { Get-ItemProperty -LiteralPath $_.PSPath -ErrorAction SilentlyContinue } |
        Where-Object { $null -ne $_ -and $_.DisplayName -like 'Papyrus*' })
}

$dataEntries = if ($dataExists) {
    @(Get-ChildItem -LiteralPath $dataRoot -Force -ErrorAction SilentlyContinue |
        Select-Object -ExpandProperty Name)
} else {
    @()
}

$volume = Get-Volume -DriveLetter $appDrive -ErrorAction SilentlyContinue
$disk = $null

if ($null -ne $volume) {
    $partition = Get-Partition -DriveLetter $appDrive -ErrorAction SilentlyContinue

    if ($null -ne $partition) {
        $disk = Get-Disk -Number $partition.DiskNumber -ErrorAction SilentlyContinue
    }
}

$ok = $appExists -and $dataExists -and $envExists -and $databaseExists `
    -and (-not $localProgramsExists) -and (-not $localDataExists) -and (-not $dataInsideApp) `
    -and ($shortcuts.Count -eq 0) -and ($uninstallEntries.Count -eq 0) `
    -and $dataBesideApp -and $driveMatches -and $kindMatches

Add-Line "checked=$((Get-Date).ToString('o'))"
Add-Line "app_root=$appRoot"
Add-Line "data_root=$dataRoot"
Add-Line "drive=$driveLetter"
Add-Line "drive_kind=$driveKind"
Add-Line "system_drive=$systemDrive"
Add-Line "app_drive=$appDrive"
Add-Line "data_drive=$dataDrive"
Add-Line "separate_volume=$separateVolume"
Add-Line "data_beside_app=$dataBesideApp"
Add-Line "drive_matches_session=$driveMatches"
Add-Line "drive_kind_matches=$kindMatches"
Add-Line "volume_file_system=$(if ($null -ne $volume) { $volume.FileSystem } else { '' })"
Add-Line "volume_size_bytes=$(if ($null -ne $volume) { $volume.Size } else { 0 })"
Add-Line "disk_bus_type=$(if ($null -ne $disk) { $disk.BusType } else { '' })"
Add-Line "disk_model=$(if ($null -ne $disk) { $disk.Model } else { '' })"
Add-Line "app_exists=$appExists"
Add-Line "data_exists=$dataExists"
Add-Line "env_exists=$envExists"
Add-Line "database_exists=$databaseExists"
Add-Line "local_programs_exists=$localProgramsExists"
Add-Line "local_data_exists=$localDataExists"
Add-Line "data_inside_app=$dataInsideApp"
Add-Line "start_menu_entries=$($shortcuts.Count)"
Add-Line "uninstall_entries=$($uninstallEntries.Count)"
Add-Line "data_entries=$($dataEntries -join ', ')"
Add-Line "verdict=$(if ($ok) { 'PASS' } else { 'FAIL' })"

$path = Join-Path $results 'portable-layout.txt'
Set-Content -Path $path -Value ($lines -join "`n") -Encoding UTF8

$summary = if ($ok) {
    "The portable layout held.`n`nData is beside the program folder on ${driveLetter}:, and no install or registry entry appeared."
} else {
    "The portable layout did not hold.`n`nRead portable-layout.txt in the results folder for each check."
}

Add-Type -AssemblyName System.Windows.Forms
[System.Windows.Forms.MessageBox]::Show(
    "$summary`n`n$path",
    'Papyrus portable run',
    [System.Windows.Forms.MessageBoxButtons]::OK,
    $(if ($ok) { [System.Windows.Forms.MessageBoxIcon]::Information } else { [System.Windows.Forms.MessageBoxIcon]::Warning })
) | Out-Null

if (-not $ok) { exit 1 }
exit 0
