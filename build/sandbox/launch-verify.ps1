# Launch the automated install harnesses in a fresh Windows Sandbox guest.
#
# ## Why this is not the host run
#
# `verify-installer.ps1` and `verify-install-immutable.ps1` install into %LOCALAPPDATA% and create a
# real library. On this host a live 1.4.0 install and 1.5 GB of reader data are already there, so both
# harnesses abort at pre-flight. A guest has neither, so the 1.5.1 setup can be measured without
# moving or deleting the reader's data.
#
# ## What this does not do
#
# It does not close the guest. The wrapper writes DONE.txt, shows a message box, and leaves the window
# open so the output can be inspected. Close the sandbox after the dialog appears.
#
# ## Usage
#
#     powershell -File build\sandbox\launch-verify.ps1
#     powershell -File build\sandbox\launch-verify.ps1 -NoLaunch

param(
    [switch]$NoLaunch
)

$ErrorActionPreference = 'Stop'

$repo = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$version = (Get-Content (Join-Path $repo 'VERSION') -Raw -ErrorAction Stop).Trim()
$setup = Join-Path $repo "build\dist\Papyrus-$version-setup.exe"
$manifestPath = Join-Path $repo 'build\dist\manifest.json'
$template = Join-Path $repo 'build\sandbox\papyrus-verify.wsb.template'
$wsb = Join-Path $repo 'build\sandbox\papyrus-verify.wsb'
$resultsRoot = Join-Path $repo 'build\verify-sandbox-results'
$tools = Join-Path (Split-Path -Parent $repo) '.tools'

function Say([string]$line) {
    Write-Host $line
}

foreach ($needed in @($template, $setup, $manifestPath)) {
    if (-not (Test-Path -LiteralPath $needed)) {
        Say "REFUSING TO LAUNCH: $needed is missing."
        exit 1
    }
}

foreach ($needed in @('verify-in-sandbox.ps1')) {
    $path = Join-Path $repo "build\sandbox\$needed"

    if (-not (Test-Path -LiteralPath $path)) {
        Say "REFUSING TO LAUNCH: $path is missing."
        exit 1
    }
}

foreach ($needed in @('verify-installer.ps1', 'verify-install-immutable.ps1')) {
    $path = Join-Path $tools $needed

    if (-not (Test-Path -LiteralPath $path)) {
        Say "REFUSING TO LAUNCH: $path is missing."
        exit 1
    }
}

$manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
$artifact = @($manifest.artifacts | Where-Object { $_.file -eq (Split-Path -Leaf $setup) }) | Select-Object -First 1

if ($null -eq $artifact) {
    Say "REFUSING TO LAUNCH: $manifestPath does not list $(Split-Path -Leaf $setup)."
    exit 1
}

$size = (Get-Item -LiteralPath $setup).Length
$hash = (Get-FileHash -LiteralPath $setup -Algorithm SHA256).Hash.ToLowerInvariant()

if ($size -ne [long]$artifact.size) {
    Say "REFUSING TO LAUNCH: $setup is $size bytes, and the manifest says $($artifact.size)."
    exit 1
}

if ($hash -ne ([string]$artifact.sha256).ToLowerInvariant()) {
    Say "REFUSING TO LAUNCH: $setup hashes to $hash, and the manifest says $($artifact.sha256)."
    exit 1
}

Say "ok   installer is $version, $size bytes, sha256 $hash"

$base = 'session-' + (Get-Date -Format 'yyyyMMdd-HHmmss')
$session = Join-Path $resultsRoot $base
$suffix = 1

while (Test-Path -LiteralPath $session) {
    $session = Join-Path $resultsRoot ("$base-$suffix")
    $suffix++
}

New-Item -ItemType Directory -Force -Path $session | Out-Null

@(
    "started=$((Get-Date).ToString('o'))"
    "version=$version"
    "installer=$setup"
    "installer_size=$size"
    "installer_sha256=$hash"
    "results=$session"
    "wsb=$wsb"
    "tools=$tools"
) | Set-Content -Path (Join-Path $session 'session.txt') -Encoding UTF8

$text = Get-Content -LiteralPath $template -Raw
$text = $text.Replace('%PAPYRUS_REPO_ROOT%', [System.Security.SecurityElement]::Escape($repo))
$text = $text.Replace('%PAPYRUS_TOOLS%', [System.Security.SecurityElement]::Escape($tools))
$text = $text.Replace('%PAPYRUS_VERIFY_RESULTS%', [System.Security.SecurityElement]::Escape($session))
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
Say 'launching Windows Sandbox...'
Start-Process -FilePath (Join-Path $env:SystemRoot 'System32\WindowsSandbox.exe') -ArgumentList "`"$wsb`""

$done = Join-Path $session 'DONE.txt'
$deadline = (Get-Date).AddMinutes(30)

Say 'waiting for the guest to run the install and zero-write harnesses...'

while ((Get-Date) -lt $deadline) {
    Start-Sleep -Seconds 10

    if (Test-Path -LiteralPath $done) {
        break
    }
}

Say ''

if (Test-Path -LiteralPath $done) {
    Say 'ok   guest finished'
    Get-Content -LiteralPath $done | ForEach-Object { Say "  $_" }

    $summary = Join-Path $session 'verify-summary.txt'

    if (Test-Path -LiteralPath $summary) {
        Say ''
        Say 'summary:'
        Get-Content -LiteralPath $summary | ForEach-Object { Say "  $_" }
    }
} else {
    Say 'WARNING: no DONE.txt after thirty minutes.'
    Say 'The guest may still be running a harness, or the wrapper failed.'
    Say "Look in $session for verify-summary.txt and the harness output."
}

Say ''
Say "Results are in $session"
Say 'Close the sandbox after the completion dialog appears, then tell the assistant.'
