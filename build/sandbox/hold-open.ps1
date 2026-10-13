# Start Papyrus inside the Windows Sandbox and leave it running, so a person can use it.
#
# ## Why this is a separate script from run-in-sandbox.ps1
#
# Because that one is a gate. It starts the install, tests it, stops it and reports. The whole point of
# a gate is that nothing is left behind, which is exactly wrong for looking at the thing.
#
# This does the same setup and then holds the terminal open, so the app is up and reachable for as long
# as the sandbox window is.
#
# ## Which ports, and why the defaults
#
# 8000 and 4567, the same as a real install, rather than the 8402 and 4571 the gate forces. Somebody
# exploring this should be looking at the addresses a reader would actually see, and the gate's ports
# exist only so two things on one machine cannot collide.
#
# ## Where it is installed
#
# C:\Program Files\Papyrus, because that is where a Windows installer puts one and because it is the
# awkward path. The install root containing a space is half of what this gate is for; installing to
# somewhere comfortable would make the test easier than the real thing.
#
# What it will NOT do is prompt. If Papyrus fails to start, the reason is written to
# C:\results\hold-open.txt on the host, and the terminal stays open so it can also be read on screen.

# Carry on past a failed probe rather than aborting, because the whole job of this script is to reach
# the end and write down what happened. Strict mode here turns a routine "not up yet" into a script
# that dies before it can say so.
$ErrorActionPreference = 'Continue'

# The install path, and the two ports, gathered together because they travel together. The port
# matters more than it looks: it is the answer to "where do I type this", and getting it wrong is
# what makes a healthy install look broken.
$root = 'C:\Program Files\Papyrus'
$data = 'C:\Papyrus Data'
$appPort = 8000
$suwayomiPort = 4567

# Every line said out loud is also kept, because the terminal inside a sandbox is a window nobody is
# watching. The report on the host is the copy that gets read.
$report = [System.Collections.Generic.List[string]]::new()

function Say([string]$line) {
    Write-Host $line
    $script:report.Add($line) | Out-Null
}

function Section([string]$title) {
    Say ''
    Say "=== $title ==="
}

function Save-Report {
    $path = 'C:\results\hold-open.txt'
    try {
        Set-Content -Path $path -Value ($report -join "`n") -Encoding UTF8
        Write-Host "  wrote $path"
    } catch {
        Write-Host "  could not write $path : $($_.Exception.Message)"
    }
}

Section 'the premise'
Say "  this sandbox has nothing installed in it, which is the point"
Say ("  java on PATH : {0}" -f @(Get-Command java -ErrorAction SilentlyContinue).Count)
Say ("  php on PATH  : {0}" -f @(Get-Command php -ErrorAction SilentlyContinue).Count)

Section 'copying the install in'
Say "  from C:\build\install to $root, about 620MB, this takes a minute"

if (Test-Path $root) {
    Say '  removing the previous copy so this is a genuine first run'
    Remove-Item -Recurse -Force $root -ErrorAction SilentlyContinue
}

Copy-Item -Path 'C:\build\install' -Destination $root -Recurse -Force -ErrorAction Continue

if (-not (Test-Path (Join-Path $root 'Papyrus.cmd'))) {
    Say '  THE COPY FAILED. There is no Papyrus.cmd at the destination.'
    Save-Report
    while ($true) { Start-Sleep -Seconds 60 }
}

Say ("  copied, {0} files" -f @(Get-ChildItem $root -Recurse -File -ErrorAction SilentlyContinue).Count)

Section 'starting Papyrus'
Say "  data directory : $data"
Say "  app port       : $appPort"
Say "  suwayomi port  : $suwayomiPort"

$env:PAPYRUS_ROOT = $root
$env:PAPYRUS_DATA = $data
$env:PAPYRUS_APP_PORT = "$appPort"
$env:PAPYRUS_SUWAYOMI_PORT = "$suwayomiPort"

$startLog = 'C:\results\hold-open-launcher.log'
$startErr = 'C:\results\hold-open-launcher.err'

Remove-Item $startLog, $startErr -ErrorAction SilentlyContinue

$launcher = Join-Path $root 'packaging\launcher\papyrus.php'
$php = Join-Path $root 'runtime\bin\php.exe'

Say "  launching, output to $startLog"

# `--open`, because this is the one context where it can genuinely work: the guest has a desktop and
# ships Edge. Every other context it is deliberately off, because there is no desktop to open on and
# the shell reports success having done nothing.
$process = Start-Process -FilePath $php `
    -ArgumentList "`"$launcher`" start --open" `
    -PassThru `
    -RedirectStandardOutput $startLog `
    -RedirectStandardError $startErr

Say ("  pid {0}, waiting for it to be usable" -f $process.Id)

$ready = $false
$reported = $null

for ($i = 1; $i -le 60; $i++) {
    Start-Sleep -Seconds 5

    $text = if (Test-Path $startLog) { Get-Content $startLog -Raw } else { '' }

    if ($null -eq $reported -and $text -match 'http://127\.0\.0\.1:(\d+)') {
        $reported = $Matches[1]
        Say "  the launcher reported it is on port $reported"
    }

    try {
        $probe = Invoke-WebRequest -Uri "http://127.0.0.1:$appPort/ready" -UseBasicParsing -TimeoutSec 5
        if ($probe.StatusCode -eq 200) {
            $ready = $true
            Say "  /ready answered 200 after $([int]($i * 5))s"
            break
        }
    } catch {
        # Expected while it is still starting. The launcher says what it is doing in the log.
    }
}

Section 'result'

if ($ready) {
    Say '  Papyrus is UP.'
    Say ''
    Say '  Type this into a browser INSIDE this sandbox window, not into one on the host.'
    Say ''
    Say "      >>>  http://127.0.0.1:$appPort  <<<"
    Say ''
    Say '  Papyrus opened one for you if it could. If no browser appeared, open Edge from the'
    Say "  taskbar inside this window and type the address above."
    Say ''
    Say '  ## Why the browser has to be in here, which has now cost two wrong turns'
    Say ''
    Say '  A sandbox guest is its own machine with its own loopback. 127.0.0.1 inside this window'
    Say '  is not 127.0.0.1 on the host, so a host browser asking for the same address reaches the'
    Say '  host, where Papyrus is not installed, and answers ERR_CONNECTION_REFUSED.'
    Say ''
    Say '  That looks exactly like a broken install. It is not one: nothing is wrong here, and the'
    Say '  only thing on screen is an error that does not distinguish the two.'
    Say ''
    Say "  Papyrus also binds loopback only, deliberately, so it is not reachable from the host at"
    Say "  any address. That is the security posture a reader ships with and it is not relaxed for"
    Say "  convenience here."
    Say ''
    Say '  Register a reader account on the login page if you have not. Empty library is expected:'
    Say '  a fresh sandbox has no manga, which is correct and not a fault.'
    Say ''
    Say '  Suwayomi own interface, if you want to look at it:'
    Say "      >>>  http://127.0.0.1:$suwayomiPort  <<<"
    Say ''
    Say '  Closing this terminal stops Papyrus. Closing the sandbox window does the same.'
} else {
    Say '  Papyrus did NOT come up. Its own words, in order:'
    Say ''
    foreach ($file in @($startLog, $startErr)) {
        if (Test-Path $file) {
            Say "  --- $file ---"
            Get-Content $file | Select-Object -Last 40 | ForEach-Object { Say "    $_" }
        }
    }
}

Save-Report

Say ''
Say 'holding the terminal open so the app keeps running.'
Say 'press Ctrl+C in here, or just close the sandbox window, to stop.'

while ($true) {
    Start-Sleep -Seconds 30
}