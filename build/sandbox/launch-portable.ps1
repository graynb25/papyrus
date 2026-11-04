# Launch the portable acceptance sandbox, which extracts the real archive and records a person using it.
#
# ## What this is for
#
# The automated gate copies `build/install` and tests that tree. The manual installer run proves the
# setup executable. Neither proves the portable shape: a reader unpacking the zip, running
# Papyrus.cmd from that folder, and getting a data directory beside it. This launcher prepares a
# fresh guest with the real archive, a desktop shortcut for it, a ten second screenshot loop, and a
# named step capture.
#
# ## Why a fresh session folder each time
#
# A manual run leaves hundreds of frames and a log. Reusing one results folder makes a later run look
# like part of an earlier one. Each launch gets
# `build/portable-sandbox-results/session-<timestamp>`, which is also where the expected archive hash
# is written before the guest starts.
#
# ## What this does not do
#
# It does not close the guest, stop the app, or decide whether the run passed. The person closes the
# sandbox after stopping the screenshots. The evidence is manual acceptance, not a gate report.
#
# ## Usage
#
#     powershell -File build\sandbox\launch-portable.ps1 -Drive C
#     powershell -File build\sandbox\launch-portable.ps1 -Drive D
#     powershell -File build\sandbox\launch-portable.ps1 -Drive D -NoLaunch
#
# -Drive C runs the archive from C:\Papyrus Portable\Papyrus, the ordinary case with a space in the
# path. -Drive D creates a VHD in the guest, attaches it as D:, and runs the archive from D:\Papyrus,
# the second-volume case. Both runs are needed: a build that only works on C: is not portable, and a
# build that only works on a blank second volume is not the ordinary case either.
#
# -NoLaunch writes the `.wsb` and the session files, prints where they are, and exits.

param(
    [switch]$NoLaunch,
    [ValidateSet('C', 'D')][string]$Drive = 'D',
    [string]$StoreUrl = $env:PAPYRUS_TEST_STORE_URL
)

$ErrorActionPreference = 'Stop'

$repo = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$version = (Get-Content (Join-Path $repo 'VERSION') -Raw -ErrorAction Stop).Trim()
$template = Join-Path $repo 'build\sandbox\papyrus-portable.wsb.template'
$driveLetter = $Drive.ToUpperInvariant()

if ($driveLetter -eq 'C') {
    $driveKind = 'local'
    $appRoot = 'C:\Papyrus Portable\Papyrus'
    $dataRoot = 'C:\Papyrus Portable\papyrus-data'
} else {
    $driveKind = 'vhd'
    $appRoot = "${driveLetter}:\Papyrus"
    $dataRoot = "${driveLetter}:\papyrus-data"
}

$wsb = Join-Path $repo "build\sandbox\papyrus-portable-$($driveLetter.ToLowerInvariant()).wsb"
$archive = Join-Path $repo "build\dist\Papyrus-$version-portable.zip"
$sidecar = "$archive.sha256"
$resultsRoot = Join-Path $repo 'build\portable-sandbox-results'

function Say([string]$line) {
    Write-Host $line
}

foreach ($needed in @($template, $archive, $sidecar)) {
    if (-not (Test-Path -LiteralPath $needed)) {
        Say "REFUSING TO LAUNCH: $needed is missing."
        exit 1
    }
}

foreach ($needed in @('manual-portable.ps1', 'check-portable.ps1', 'capture-screen.ps1', 'capture-step.ps1', 'stop-capture.ps1', 'screenshot-lib.ps1')) {
    $path = Join-Path $repo "build\sandbox\$needed"

    if (-not (Test-Path -LiteralPath $path)) {
        Say "REFUSING TO LAUNCH: $path is missing."
        exit 1
    }
}

$sidecarText = (Get-Content -LiteralPath $sidecar -Raw).Trim()
$sidecarParts = @($sidecarText -split '\s+' | Where-Object { $_ -ne '' })

if ($sidecarParts.Count -lt 2) {
    Say "REFUSING TO LAUNCH: $sidecar does not hold a hash and a file name."
    exit 1
}

$sidecarHash = $sidecarParts[0].ToLowerInvariant()
$sidecarName = $sidecarParts[1]

if ($sidecarName -ne (Split-Path -Leaf $archive)) {
    Say "REFUSING TO LAUNCH: the sidecar names $sidecarName, not $(Split-Path -Leaf $archive)."
    exit 1
}

$size = (Get-Item -LiteralPath $archive).Length
$hash = (Get-FileHash -LiteralPath $archive -Algorithm SHA256).Hash.ToLowerInvariant()

if ($hash -ne $sidecarHash) {
    Say "REFUSING TO LAUNCH: $archive hashes to $hash, and the sidecar says $sidecarHash."
    exit 1
}

Say "ok   archive is $version, $size bytes, sha256 $hash"
Say "ok   layout is $driveLetter ($driveKind), app $appRoot, data $dataRoot"

# The portable run has to prove the source flow, and the tree must not carry a store URL. The
# host supplies one and the guest reads it from the results mount.
$StoreUrl = if ($null -eq $StoreUrl) { '' } else { $StoreUrl.Trim() }

if ($StoreUrl -eq '') {
    Say 'REFUSING TO LAUNCH: no store index URL was supplied.'
    Say 'Pass -StoreUrl, or set PAPYRUS_TEST_STORE_URL, to the index URL of a repository you trust.'
    exit 1
}

if ($StoreUrl -notmatch '^https?://') {
    Say 'REFUSING TO LAUNCH: the store index URL must start with http:// or https://.'
    exit 1
}

Say 'ok   store index URL supplied by the caller'

# A new folder every time. The suffix is only for two launches in the same second.
$base = 'session-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '-' + $driveLetter.ToLowerInvariant()
$session = Join-Path $resultsRoot $base
$suffix = 1

while (Test-Path -LiteralPath $session) {
    $session = Join-Path $resultsRoot ("$base-$suffix")
    $suffix++
}

New-Item -ItemType Directory -Force -Path $session | Out-Null

@(
    "file=$(Split-Path -Leaf $archive)"
    "size=$size"
    "sha256=$hash"
    "version=$version"
    "drive=$driveLetter"
    "drive_kind=$driveKind"
    "app_root=$appRoot"
    "data_root=$dataRoot"
) | Set-Content -Path (Join-Path $session 'expected-portable.txt') -Encoding UTF8

Set-Content -Path (Join-Path $session 'store-url.txt') -Value $StoreUrl -Encoding UTF8

@(
    "started=$((Get-Date).ToString('o'))"
    "version=$version"
    "archive=$archive"
    "archive_size=$size"
    "archive_sha256=$hash"
    "drive=$driveLetter"
    "drive_kind=$driveKind"
    "app_root=$appRoot"
    "data_root=$dataRoot"
    "results=$session"
    "wsb=$wsb"
) | Set-Content -Path (Join-Path $session 'session.txt') -Encoding UTF8

$text = Get-Content -LiteralPath $template -Raw
$text = $text.Replace('%PAPYRUS_REPO_ROOT%', [System.Security.SecurityElement]::Escape($repo))
$text = $text.Replace('%PAPYRUS_PORTABLE_RESULTS%', [System.Security.SecurityElement]::Escape($session))
Set-Content -Path $wsb -Value $text -Encoding UTF8

try {
    [xml](Get-Content -LiteralPath $wsb -Raw) | Out-Null
} catch {
    Say "REFUSING TO LAUNCH: the generated $wsb is not valid XML: $($_.Exception.Message)"
    exit 1
}

Say "ok   wrote $wsb"
Say "ok   results folder $session"

if ($NoLaunch) {
    Say ''
    Say '  -NoLaunch was given, so no guest was started.'
    Say '  inspect the generated .wsb and the session folder above.'
    exit 0
}

# Only one guest at a time. The client is checked as well as the VM, because the VM can still be
# releasing after the window has gone.
foreach ($name in @('vmmemWindowsSandbox', 'WindowsSandboxServer', 'WindowsSandbox')) {
    $running = @(Get-Process -Name $name -ErrorAction SilentlyContinue)

    if ($running.Count -gt 0) {
        $pids = @($running | ForEach-Object { [string]$_.Id })
        Say "REFUSING TO LAUNCH: $name is already running (pids: $($pids -join ', '))."
        Say 'Close the guest and wait for the virtual machine to go before trying again.'
        exit 1
    }
}

$freeMb = [math]::Round((Get-CimInstance Win32_OperatingSystem).FreePhysicalMemory / 1KB)
Say "free memory $freeMb MB"

if ($freeMb -lt 3000) {
    Say 'REFUSING TO LAUNCH: less than 3 GB of free memory, and a guest needs several GB.'
    exit 1
}

Say ''
Say "launching Windows Sandbox for the ${driveLetter}: run..."
Start-Process -FilePath (Join-Path $env:SystemRoot 'System32\WindowsSandbox.exe') -ArgumentList "`"$wsb`""

$ready = Join-Path $session 'READY.txt'
$deadline = (Get-Date).AddMinutes(20)

Say 'waiting for the guest to verify and extract the archive, then start the screenshot loop...'

while ((Get-Date) -lt $deadline) {
    Start-Sleep -Seconds 5

    if (Test-Path -LiteralPath $ready) {
        break
    }
}

Say ''

if (Test-Path -LiteralPath $ready) {
    Say 'ok   guest ready'
    Get-Content -LiteralPath $ready | ForEach-Object { Say "  $_" }
} else {
    Say 'WARNING: no READY.txt after twenty minutes.'
    Say 'The guest may still be extracting, or the mapping or the setup script failed.'
    Say "Look in $session for portable.log and portable-verified.txt, and at the sandbox window."
}

Say ''
Say "Inside the guest (${driveLetter}: run, app $appRoot):"
Say '  1. Double-click Start Papyrus.'
Say '  2. Register, add the store URL supplied on the host, install MangaTaro, search One Piece, read a chapter.'
Say '  3. After the reader page is visible, double-click Check portable layout.'
Say '  4. Use Capture a step at the moments that matter.'
Say '  5. Double-click Stop screenshots, wait ten seconds, then close the sandbox.'
Say ''
Say "Results are in $session"
Say 'Tell the assistant when the guest is closed, so the screenshots can be read.'
