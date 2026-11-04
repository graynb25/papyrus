# Launch the manual acceptance sandbox, which runs the real installer and records a person using it.
#
# ## What this is for
#
# The automated gate copies `build/install` and tests that tree. It never runs
# `Papyrus-1.5.0-setup.exe`. This launcher prepares a fresh guest with the real setup executable, a
# desktop shortcut for it, a ten second screenshot loop, and a named step capture. The person
# installs, registers, adds a source, searches, and reads a chapter. The host reads the screenshots
# and logs afterwards.
#
# ## Why a fresh session folder each time
#
# A manual run leaves hundreds of frames and a log. Reusing one results folder makes a later run look
# like part of an earlier one. Each launch gets
# `build/manual-sandbox-results/session-<timestamp>`, which is also where the expected installer hash
# is written before the guest starts.
#
# ## What this does not do
#
# It does not close the guest, stop the app, or decide whether the run passed. The person closes the
# sandbox after stopping the screenshots. The evidence is manual acceptance, not a gate report.
#
# ## Usage
#
#     powershell -File build\sandbox\launch-manual.ps1
#     powershell -File build\sandbox\launch-manual.ps1 -NoLaunch
#
# -NoLaunch writes the `.wsb` and the session files, prints where they are, and exits. It is for
# checking the configuration without starting a guest.

param(
    [switch]$NoLaunch,
    [string]$StoreUrl = $env:PAPYRUS_TEST_STORE_URL
)

$ErrorActionPreference = 'Stop'

$repo = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$version = (Get-Content (Join-Path $repo 'VERSION') -Raw -ErrorAction Stop).Trim()
$manifestPath = Join-Path $repo 'build\dist\manifest.json'
$template = Join-Path $repo 'build\sandbox\papyrus-manual.wsb.template'
$wsb = Join-Path $repo 'build\sandbox\papyrus-manual.wsb'
$installer = Join-Path $repo "build\dist\Papyrus-$version-setup.exe"
$resultsRoot = Join-Path $repo 'build\manual-sandbox-results'

function Say([string]$line) {
    Write-Host $line
}

foreach ($needed in @($template, $installer, $manifestPath)) {
    if (-not (Test-Path -LiteralPath $needed)) {
        Say "REFUSING TO LAUNCH: $needed is missing."
        exit 1
    }
}

foreach ($needed in @('manual-sandbox.ps1', 'capture-screen.ps1', 'capture-step.ps1', 'stop-capture.ps1', 'screenshot-lib.ps1')) {
    $path = Join-Path $repo "build\sandbox\$needed"

    if (-not (Test-Path -LiteralPath $path)) {
        Say "REFUSING TO LAUNCH: $path is missing."
        exit 1
    }
}

$manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
$artifact = @($manifest.artifacts | Where-Object { $_.file -eq (Split-Path -Leaf $installer) }) | Select-Object -First 1

if ($null -eq $artifact) {
    Say "REFUSING TO LAUNCH: $manifestPath does not list $(Split-Path -Leaf $installer)."
    exit 1
}

$size = (Get-Item -LiteralPath $installer).Length
$hash = (Get-FileHash -LiteralPath $installer -Algorithm SHA256).Hash.ToLowerInvariant()

if ($size -ne [long]$artifact.size) {
    Say "REFUSING TO LAUNCH: $installer is $size bytes, and the manifest says $($artifact.size)."
    exit 1
}

if ($hash -ne ([string]$artifact.sha256).ToLowerInvariant()) {
    Say "REFUSING TO LAUNCH: $installer hashes to $hash, and the manifest says $($artifact.sha256)."
    exit 1
}

Say "ok   installer is $version, $size bytes, sha256 $hash"

# The manual run has to prove the source flow, and the tree must not carry a store URL. The host
# supplies one and the guest reads it from the results mount, which is what the README tells the
# person to paste. A missing URL is refused before the guest starts.
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

# A new folder every time. The suffix is only for two launches in the same second, which is rare but
# cheaper to handle than a mount that silently points at the previous run.
$base = 'session-' + (Get-Date -Format 'yyyyMMdd-HHmmss')
$session = Join-Path $resultsRoot $base
$suffix = 1

while (Test-Path -LiteralPath $session) {
    $session = Join-Path $resultsRoot ("$base-$suffix")
    $suffix++
}

New-Item -ItemType Directory -Force -Path $session | Out-Null

@(
    "file=$(Split-Path -Leaf $installer)"
    "size=$size"
    "sha256=$hash"
    "version=$version"
) | Set-Content -Path (Join-Path $session 'expected-installer.txt') -Encoding UTF8

Set-Content -Path (Join-Path $session 'store-url.txt') -Value $StoreUrl -Encoding UTF8

@(
    "started=$((Get-Date).ToString('o'))"
    "version=$version"
    "installer=$installer"
    "installer_size=$size"
    "installer_sha256=$hash"
    "results=$session"
    "wsb=$wsb"
) | Set-Content -Path (Join-Path $session 'session.txt') -Encoding UTF8

$text = Get-Content -LiteralPath $template -Raw
$text = $text.Replace('%PAPYRUS_REPO_ROOT%', [System.Security.SecurityElement]::Escape($repo))
$text = $text.Replace('%PAPYRUS_MANUAL_RESULTS%', [System.Security.SecurityElement]::Escape($session))
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

Say ''
Say 'launching Windows Sandbox...'
Start-Process -FilePath (Join-Path $env:SystemRoot 'System32\WindowsSandbox.exe') -ArgumentList "`"$wsb`""

$ready = Join-Path $session 'READY.txt'
$deadline = (Get-Date).AddMinutes(5)

Say 'waiting for the guest to copy the installer and start the screenshot loop...'

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
    Say 'WARNING: no READY.txt after five minutes.'
    Say 'The guest may still be booting, or the mapping or the setup script failed.'
    Say "Look in $session for setup.log and installer-verified.txt, and at the sandbox window."
}

Say ''
Say 'Inside the guest:'
Say '  1. Double-click Install Papyrus on the desktop.'
Say '  2. Accept the defaults and let it launch.'
Say '  3. Register, add the store URL supplied on the host, install MangaTaro, search One Piece, read a chapter.'
Say '  4. Use Capture a step at the moments that matter.'
Say '  5. Double-click Stop screenshots, wait ten seconds, then close the sandbox.'
Say ''
Say "Results are in $session"
Say 'Tell the assistant when the guest is closed, so the screenshots can be read.'
