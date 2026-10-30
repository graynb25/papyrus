# Prepare the manual acceptance sandbox for the portable archive.
#
# ## Why this is not the installer run
#
# The portable shape is a zip a reader unpacks and runs. There is no setup executable, no registry,
# and no Start Menu entry. This script verifies the copied archive, extracts it outside
# %LOCALAPPDATA%, and puts the launcher and the capture tools on the desktop.
#
# ## Why the archive runs from a second volume
#
# A portable build that only works on C: is not portable. The guest starts with one system volume,
# so this script creates a VHD, attaches it as D:, and copies the archive there. The program is
# extracted to D:\Papyrus and its data belongs at D:\papyrus-data, beside it. The host names the
# expected drive in expected-portable.txt, and a run on C: is refused rather than recorded as
# portable evidence.
#
# ## What the person sees
#
# The archive is copied from C:\installer to D:\, verified against expected-portable.txt, and
# extracted to D:\Papyrus. The data directory is expected at D:\papyrus-data, beside the program
# folder.
#
# ## What runs in the background
#
# capture-screen.ps1 takes a PNG every ten seconds until STOP.txt appears. check-portable.ps1
# writes portable-layout.txt when the person runs the desktop shortcut after the first launch.

$ErrorActionPreference = 'Continue'

$results = 'C:\results'
$desktop = [Environment]::GetFolderPath('Desktop')
$powershell = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
$driveLetter = 'D'
$appRoot = 'D:\Papyrus'
$dataRoot = 'D:\papyrus-data'
$vhdPath = 'C:\papyrus-test.vhd'

New-Item -ItemType Directory -Force -Path $results | Out-Null

$log = [System.Collections.Generic.List[string]]::new()

function Say([string]$line) {
    Write-Host $line
    $script:log.Add($line) | Out-Null
}

function Save-Log {
    Set-Content -Path (Join-Path $results 'portable.log') -Value ($log -join "`n") -Encoding UTF8
}

function Stop-WithError([string]$message) {
    Say "REFUSING TO CONTINUE: $message"
    Save-Log

    Add-Type -AssemblyName System.Windows.Forms
    [System.Windows.Forms.MessageBox]::Show(
        "$message`n`nNothing was launched. Check portable.log in the results folder.",
        'Papyrus portable run',
        [System.Windows.Forms.MessageBoxButtons]::OK,
        [System.Windows.Forms.MessageBoxIcon]::Error
    ) | Out-Null

    exit 1
}

function New-Shortcut {
    param(
        [string]$Path,
        [string]$Target,
        [string]$Arguments = '',
        [string]$WorkingDirectory = '',
        [string]$Description = ''
    )

    $shell = New-Object -ComObject WScript.Shell
    $shortcut = $shell.CreateShortcut($Path)
    $shortcut.TargetPath = $Target

    if ($Arguments -ne '') { $shortcut.Arguments = $Arguments }
    if ($WorkingDirectory -ne '') { $shortcut.WorkingDirectory = $WorkingDirectory }
    if ($Description -ne '') { $shortcut.Description = $Description }

    $shortcut.Save()
}

# The facts about the volume a run used, read back from Windows rather than inferred.
#
# The C: run and the D: run must be distinguishable in the evidence. A path that starts with D: is
# not enough on its own, because a mapped folder or a substituted drive also has a letter. The disk
# bus type and the separate-volume check in check-portable.ps1 are what show the difference.
function Get-DriveFacts {
    param(
        [string]$Letter,
        [string]$Kind,
        [string]$Vhd
    )

    $volume = Get-Volume -DriveLetter $Letter -ErrorAction SilentlyContinue
    $disk = $null

    if ($null -ne $volume) {
        $partition = Get-Partition -DriveLetter $Letter -ErrorAction SilentlyContinue

        if ($null -ne $partition) {
            $disk = Get-Disk -Number $partition.DiskNumber -ErrorAction SilentlyContinue
        }
    }

    return @(
        "drive_letter=$Letter"
        "drive_kind=$Kind"
        "vhd_path=$Vhd"
        "volume_label=$(if ($null -ne $volume) { $volume.FileSystemLabel } else { '' })"
        "volume_file_system=$(if ($null -ne $volume) { $volume.FileSystem } else { '' })"
        "volume_size_bytes=$(if ($null -ne $volume) { $volume.Size } else { 0 })"
        "volume_free_bytes=$(if ($null -ne $volume) { $volume.SizeRemaining } else { 0 })"
        "disk_bus_type=$(if ($null -ne $disk) { $disk.BusType } else { '' })"
        "disk_model=$(if ($null -ne $disk) { $disk.Model } else { '' })"
    )
}

# Create and attach the second volume the D: run proves.
#
# Windows Sandbox starts with one system volume, so a run on C: would not answer the question the
# reader actually has: does this folder work on a USB stick or a second disk? A VHD is a real
# volume with its own filesystem, and it disappears with the guest. The run is refused if the host
# named C:, or if the expected letter is already in use, rather than silently recording a run that
# proved nothing.
function Mount-DataDrive {
    if ($driveLetter -eq ([string]$env:SystemDrive).TrimEnd(':')) {
        Stop-WithError "The host named $driveLetter as the portable drive, and that is this guest's system drive."
    }

    if (Test-Path -LiteralPath $driveRoot) {
        Stop-WithError "$driveRoot already exists, so this guest cannot prove a fresh second volume."
    }

    if (Test-Path -LiteralPath $vhdPath) {
        Stop-WithError "$vhdPath already exists, so this guest is not a fresh run."
    }

    $scriptPath = Join-Path $env:TEMP 'papyrus-test-volume.txt'

    @(
        "create vdisk file=`"$vhdPath`" maximum=4096 type=expandable"
        "select vdisk file=`"$vhdPath`""
        'attach vdisk'
        'create partition primary'
        'format fs=ntfs quick label=PapyrusTest'
        "assign letter=$driveLetter"
    ) | Set-Content -Path $scriptPath -Encoding ASCII

    Say "creating the $driveLetter volume at $vhdPath"

    $output = & (Join-Path $env:SystemRoot 'System32\diskpart.exe') /s $scriptPath 2>&1
    $code = $LASTEXITCODE

    foreach ($line in @($output)) {
        Say "diskpart: $line"
    }

    if ($code -ne 0) {
        Stop-WithError "diskpart could not create and attach the test volume (exit $code)."
    }

    $deadline = (Get-Date).AddSeconds(60)

    while (-not (Test-Path -LiteralPath $driveRoot) -and (Get-Date) -lt $deadline) {
        Start-Sleep -Seconds 1
    }

    if (-not (Test-Path -LiteralPath $driveRoot)) {
        Stop-WithError "$driveRoot did not appear after diskpart attached the test volume."
    }

    $script:volumeFacts = Get-DriveFacts -Letter $driveLetter -Kind 'vhd' -Vhd $vhdPath

    foreach ($line in $volumeFacts) {
        Say $line
    }
}

Say "portable sandbox started $((Get-Date).ToString('o'))"
Say "user $env:USERNAME, computer $env:COMPUTERNAME"
Say "results $results"

# The host writes this before launching, from VERSION and the sidecar. The guest never guesses which
# archive to test: build\dist can hold several versions.
$expected = @{}
$expectedPath = Join-Path $results 'expected-portable.txt'

if (Test-Path -LiteralPath $expectedPath) {
    foreach ($line in Get-Content -LiteralPath $expectedPath) {
        if ($line -match '^([^=]+)=(.*)$') {
            $expected[$matches[1]] = $matches[2]
        }
    }
}

$expectedFile = if ($expected.ContainsKey('file')) { [string]$expected['file'] } else { '' }
$runVersion = if ($expected.ContainsKey('version')) { [string]$expected['version'] } else { 'unknown' }

if ($expectedFile -eq '') {
    Stop-WithError 'expected-portable.txt has no file entry, so this session cannot test anything.'
}

$zipSource = Join-Path 'C:\installer' $expectedFile

if (-not (Test-Path -LiteralPath $zipSource)) {
    Stop-WithError "The expected archive $expectedFile was not found in C:\installer."
}

$expectedDrive = if ($expected.ContainsKey('drive')) { ([string]$expected['drive']).Trim().TrimEnd(':') } else { 'C' }

if ($expectedDrive -notmatch '^[C-Za-z]$') {
    Stop-WithError "expected-portable.txt names '$expectedDrive' as the drive, and this run accepts one letter from C to Z."
}

$driveLetter = $expectedDrive.ToUpperInvariant()
$driveRoot = $driveLetter + ':\'
$driveKind = if ($expected.ContainsKey('drive_kind')) {
    ([string]$expected['drive_kind']).Trim().ToLowerInvariant()
} else {
    if ($driveLetter -eq 'C') { 'local' } else { 'vhd' }
}

if ($driveKind -notin @('local', 'vhd')) {
    Stop-WithError "expected-portable.txt names '$driveKind' as the drive kind, and this run accepts local or vhd."
}

if (($driveLetter -eq 'C') -ne ($driveKind -eq 'local')) {
    Stop-WithError "The C: run is the local system drive and every other letter is a VHD, and the host named $driveLetter with $driveKind."
}

$appRoot = if ($expected.ContainsKey('app_root')) { ([string]$expected['app_root']).Trim() } else { '' }
$dataRoot = if ($expected.ContainsKey('data_root')) { ([string]$expected['data_root']).Trim() } else { '' }

if ($appRoot -eq '' -or $dataRoot -eq '') {
    Stop-WithError 'expected-portable.txt must name both app_root and data_root.'
}

$appDrive = [System.IO.Path]::GetPathRoot($appRoot).TrimEnd('\')
$dataDrive = [System.IO.Path]::GetPathRoot($dataRoot).TrimEnd('\')
$expectedDataRoot = Join-Path (Split-Path -Parent $appRoot) 'papyrus-data'

if ($appDrive -ne ($driveLetter + ':') -or $dataDrive -ne ($driveLetter + ':')) {
    Stop-WithError "The host named $appRoot and $dataRoot, and this run is on ${driveLetter}:."
}

if ($dataRoot.TrimEnd('\') -ne $expectedDataRoot.TrimEnd('\')) {
    Stop-WithError "The host named $dataRoot as the data directory, and the portable layout puts it at $expectedDataRoot."
}

if ($driveKind -eq 'vhd') {
    $vhdPath = "C:\papyrus-test-$driveLetter.vhd"
    Mount-DataDrive
} else {
    $volumeFacts = Get-DriveFacts -Letter $driveLetter -Kind 'local' -Vhd ''

    foreach ($line in $volumeFacts) {
        Say $line
    }
}

$zipDirectory = Split-Path -Parent $appRoot
$zip = Join-Path $zipDirectory $expectedFile

Say "drive $driveLetter ($driveKind), app $appRoot, data $dataRoot"
Say "copying $zipSource to $zip"

try {
    Copy-Item -LiteralPath $zipSource -Destination $zip -Force -ErrorAction Stop
} catch {
    Stop-WithError "Could not copy the archive: $($_.Exception.Message)"
}

$size = (Get-Item -LiteralPath $zip).Length
$hash = (Get-FileHash -LiteralPath $zip -Algorithm SHA256).Hash.ToLowerInvariant()
$expectedHash = if ($expected.ContainsKey('sha256')) { [string]$expected['sha256'] } else { '' }
$match = if ($expectedHash -ne '') { $hash -eq $expectedHash.ToLowerInvariant() } else { $null }

Say "archive size $size"
Say "archive sha256 $hash"

@(
    "file=$expectedFile"
    "size=$size"
    "sha256=$hash"
    "expected_sha256=$expectedHash"
    "match=$match"
) | Set-Content -Path (Join-Path $results 'portable-verified.txt') -Encoding UTF8

if ($match -ne $true) {
    Stop-WithError 'The copied archive does not match the hash the host recorded.'
}

# A mapped folder is a network share, and Windows can mark files copied from one as blocked. The
# archive is unblocked before extraction, and the extracted tree is unblocked again because the
# mark can travel with each entry.
Unblock-File -LiteralPath $zip -ErrorAction SilentlyContinue

if (Test-Path -LiteralPath $appRoot) {
    Remove-Item -LiteralPath $appRoot -Recurse -Force -ErrorAction SilentlyContinue
}

if (Test-Path -LiteralPath $dataRoot) {
    Remove-Item -LiteralPath $dataRoot -Recurse -Force -ErrorAction SilentlyContinue
}

Say "extracting to $appRoot"

try {
    Expand-Archive -LiteralPath $zip -DestinationPath $appRoot -Force -ErrorAction Stop
} catch {
    Stop-WithError "Could not extract the archive: $($_.Exception.Message)"
}

Say 'unblocking the extracted files'
Get-ChildItem -LiteralPath $appRoot -Recurse -File -ErrorAction SilentlyContinue |
    Unblock-File -ErrorAction SilentlyContinue

$stampPath = Join-Path $appRoot 'version.txt'
$stamp = if (Test-Path -LiteralPath $stampPath) { Get-Content -LiteralPath $stampPath -Raw } else { '' }
$stampVersion = if ($stamp -match 'version\s+(\S+)') { $matches[1] } else { '' }
$stampCommit = if ($stamp -match 'commit\s+(\S+)') { $matches[1] } else { '' }

if ($stampVersion -ne $runVersion) {
    Stop-WithError "The extracted version.txt says '$stampVersion', and the host expected '$runVersion'."
}

$launcher = Join-Path $appRoot 'Papyrus.cmd'
$php = Join-Path $appRoot 'runtime\bin\php.exe'
$launcherPhp = Join-Path $appRoot 'packaging\launcher\papyrus.php'
$portableReadme = Join-Path $appRoot 'PAPYRUS-PORTABLE.txt'

foreach ($needed in @($launcher, $php, $launcherPhp)) {
    if (-not (Test-Path -LiteralPath $needed)) {
        Stop-WithError "The extracted archive is missing $needed."
    }
}

$readmeText = if (Test-Path -LiteralPath $portableReadme) {
    Get-Content -LiteralPath $portableReadme -Raw
} else {
    ''
}

if ($readmeText -notmatch 'papyrus-data') {
    Stop-WithError 'The extracted readme does not name papyrus-data, so the artifact is not the rebuilt one.'
}

$envInArchive = Test-Path -LiteralPath (Join-Path $appRoot '.env')
$databaseInArchive = Test-Path -LiteralPath (Join-Path $appRoot 'database.sqlite')
$dataInArchive = Test-Path -LiteralPath (Join-Path $appRoot 'papyrus-data')

if ($envInArchive -or $databaseInArchive -or $dataInArchive) {
    Stop-WithError 'The archive already contains runtime state. A portable archive must start clean.'
}

$os = Get-CimInstance Win32_OperatingSystem -ErrorAction SilentlyContinue
$systemDrive = ([string]$env:SystemDrive).TrimEnd(':')

$sessionLines = @(
    "started=$((Get-Date).ToString('o'))"
    "user=$env:USERNAME"
    "computer=$env:COMPUTERNAME"
    "os=$($os.Caption) $($os.Version)"
    "version=$runVersion"
    "commit=$stampCommit"
    "archive=$expectedFile"
    "archive_size=$size"
    "archive_sha256=$hash"
    "expected_sha256=$expectedHash"
    "sha256_match=$match"
    "drive=$driveLetter"
    "drive_kind=$driveKind"
    "system_drive=$systemDrive"
    "separate_volume=$($driveLetter -ne $systemDrive)"
    "app_root=$appRoot"
    "data_root=$dataRoot"
)
$sessionLines += $volumeFacts
$sessionLines | Set-Content -Path (Join-Path $results 'session.txt') -Encoding UTF8

$layoutLines = @(
    "app_root=$appRoot"
    "data_root=$dataRoot"
    "drive=$driveLetter"
    "drive_kind=$driveKind"
    "system_drive=$systemDrive"
    "separate_volume=$($driveLetter -ne $systemDrive)"
    "version=$stampVersion"
    "commit=$stampCommit"
    "env_in_archive=$envInArchive"
    "database_in_archive=$databaseInArchive"
    "data_dir_in_archive=$dataInArchive"
    "readme_names_data_dir=$($readmeText -match 'papyrus-data')"
)
$layoutLines += $volumeFacts
$layoutLines | Set-Content -Path (Join-Path $results 'portable-layout-before.txt') -Encoding UTF8

$driveDescription = if ($driveKind -eq 'vhd') {
    "a second volume, ${driveLetter}:, created for this guest"
} else {
    "the system drive, ${driveLetter}:, in a folder with a space in its path"
}

$readme = @"
Papyrus $runVersion portable acceptance run

This guest has the portable archive, not the installer. Nothing was installed. The archive was
verified against the host hash, extracted to $appRoot on $driveDescription, and left with no
runtime state.

The data directory is expected at $dataRoot, beside the program folder.

BEFORE YOU START

  The desktop has these shortcuts:

    Start Papyrus          runs Papyrus.cmd from the extracted folder
    Check portable layout  checks where the data went and whether an install appeared
    Capture a step         opens a small form, then takes one named screenshot
    Stop screenshots       writes the stop marker and ends the ten second loop
    Open results           opens the folder the host reads

  The screenshot loop is already running. It writes a PNG every ten seconds to C:\results.

THE RUN

  1. Double-click Start Papyrus. The first start takes longer because it generates its own keys and
     database. A console window stays open and the browser should open.

     If no browser appears, open Edge inside this sandbox and go to:

         http://127.0.0.1:8000

     A browser on the host cannot reach this address. The sandbox has its own loopback.

  2. Register a reader account on the login page. Use any address and password you will remember for
     this disposable guest.

  3. Open Sources. Add the Keiyoushi store:

         https://raw.githubusercontent.com/keiyoushi/extensions/repo/index.pb

     Refresh the store, install the MangaTaro extension, then sync sources if the page asks.

  4. Search for One Piece, open the series, and read a chapter. Let the reader show at least one
     page.

  5. After the reader page is visible, double-click Check portable layout. It writes
     portable-layout.txt and tells you whether the data is beside the program folder on
     ${driveLetter}:, and whether %LOCALAPPDATA%\Programs\Papyrus or %LOCALAPPDATA%\Papyrus appeared.

  6. Use Capture a step at the moments that matter: after the first launch, after registration,
     after the store is added, after the extension is installed, when the series is open, and when
     a page is visible. Name each one. The ten second loop is the record; the named shots are the
     index.

  7. When you are done, double-click Stop screenshots and wait ten seconds for the loop to write
     its last frame, then close the sandbox window.

WHAT IS BEING TESTED

  The portable archive hash, extraction into $appRoot on ${driveLetter}:, the first launch, the
  data directory beside the program folder, the absence of an install or registry entry, the
  account flow, the source and extension flow through the UI, the search and reader pages, and what
  the pages look like at this window size. This is manual acceptance evidence. It is not the
  automated gate, and it does not become a gate pass.

  The archive is unsigned. Its SHA-256 is the check. Do not work around a warning silently.
"@

$readmePath = Join-Path $results 'MANUAL-PORTABLE-README.txt'
$readmeDesktop = Join-Path $desktop 'START-HERE.txt'
Set-Content -Path $readmePath -Value $readme -Encoding UTF8
Set-Content -Path $readmeDesktop -Value $readme -Encoding UTF8

New-Shortcut -Path (Join-Path $desktop 'Start Papyrus.lnk') -Target $launcher `
    -WorkingDirectory $appRoot -Description "Start Papyrus $runVersion"

New-Shortcut -Path (Join-Path $desktop 'Check portable layout.lnk') -Target $powershell `
    -Arguments '-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "C:\sandbox\check-portable.ps1"' `
    -WorkingDirectory 'C:\sandbox' -Description 'Check where the portable data went'

New-Shortcut -Path (Join-Path $desktop 'Capture a step.lnk') -Target $powershell `
    -Arguments '-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "C:\sandbox\capture-step.ps1"' `
    -WorkingDirectory 'C:\sandbox' -Description 'Take one named screenshot'

New-Shortcut -Path (Join-Path $desktop 'Stop screenshots.lnk') -Target $powershell `
    -Arguments '-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "C:\sandbox\stop-capture.ps1"' `
    -WorkingDirectory 'C:\sandbox' -Description 'Stop the ten second screenshot loop'

New-Shortcut -Path (Join-Path $desktop 'Open results.lnk') -Target $results `
    -WorkingDirectory $results -Description 'Open the folder the host reads'

# A new session folder is created by the host each time, so a stop marker from an earlier run cannot
# be inherited. Removing it here is only a second line of defence.
Remove-Item -LiteralPath (Join-Path $results 'STOP.txt') -Force -ErrorAction SilentlyContinue

Say 'starting the screenshot watcher'
$watcher = Start-Process -FilePath $powershell `
    -ArgumentList @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-WindowStyle', 'Hidden', '-File', 'C:\sandbox\capture-screen.ps1') `
    -WindowStyle Hidden `
    -PassThru

Start-Sleep -Seconds 2

$watcherAlive = $null -ne (Get-Process -Id $watcher.Id -ErrorAction SilentlyContinue)
Say "watcher pid $($watcher.Id), alive $watcherAlive"

@(
    "ready=$((Get-Date).ToString('o'))"
    "watcher_pid=$($watcher.Id)"
    "watcher_alive=$watcherAlive"
    "version=$runVersion"
    "archive=$zip"
    "drive=$driveLetter"
    "drive_kind=$driveKind"
    "app_root=$appRoot"
    "data_root=$dataRoot"
) | Set-Content -Path (Join-Path $results 'READY.txt') -Encoding UTF8

Save-Log

Start-Process -FilePath (Join-Path $env:SystemRoot 'notepad.exe') -ArgumentList "`"$readmePath`""
