# Start the two processes a development checkout needs, or refuse to start a second copy.
#
# ## Why this exists
#
# Because Herd and nginx serve the repository at papyrus.test, and nothing runs the checkout's
# queue. The library page dispatches a bulk check, the job sits in `database/database.sqlite` with
# nobody to take it, and the progress bar reads "Checked 0 of 670 series" for ever.
#
# The trap is that a `queue:work` process *was* running. It belonged to the packaged install at
# build/install, against `Documents\Papyrus\data`, draining a different database entirely. So
# "is a worker running" answers yes while the queue this checkout owns is untouched, and
# `papyrus health` reported every line green over it.
#
# ## Both, or neither
#
# `queue:work` does the library checks and the downloads. `schedule:work` does the daily
# `VACUUM INTO` snapshot at 03:17 and the sweep that dispatches bulk checks. Starting one alone is
# the half-working state that is hardest to notice, because half of the product is fine.
#
# ## What this deliberately does not do
#
# It does not change `QUEUE_CONNECTION`. `sync` is the one-line fix and it is a trap: `CheckLibrary`
# guards its self-re-booking chain with a static depth, so under `sync` the chain stops after about
# two slices. A full library check would check roughly a hundred series, stop, and report that
# nothing more is due without ever having looked. That is worse than a stalled bar, because it looks
# like success.
#
# ## Usage:
#
#   powershell -File packaging/dev-workers.ps1          # start them, and return
#   powershell -File packaging/dev-workers.ps1 -Status # say what is up, change nothing

param(
    [switch] $Status,
    [switch] $Stop
)

$ErrorActionPreference = 'Stop'

# $PSScriptRoot is <repo>\packaging, so one parent is the repository. Two was wrong and looked
# for a database one level above the project, which is how the first run of this reported
# "no database" against a checkout that has a 5MB one.
$repo = Split-Path -Parent $PSScriptRoot

# Herd's PHP, because this is a Herd site and its extensions are what the app needs. Falling back to
# whatever `php` resolves to keeps it working on a machine without Herd.
$php = (Get-Command php -ErrorAction SilentlyContinue).Source
if (-not $php) {
    Write-Output '  php is not on PATH. This script needs the PHP that serves this checkout.'
    exit 1
}

# Does this checkout's database have a jobs table at all? A checkout that has never run `migrate`
# has nothing to drain and the worker would sit there failing every job.
$database = Join-Path $repo 'database\database.sqlite'
if (-not (Test-Path $database)) {
    Write-Output "  no database at $database"
    Write-Output '  Run: php artisan migrate'
    exit 1
}

$wanted = [ordered]@{
    'queue'     = 'queue:work'
    'scheduler' = 'schedule:work'
}

<#
 # Which workers belong to this checkout.
 #
 # Matched on the artisan command and on the packaged install being excluded, and deliberately NOT on
 # the repository path appearing in the command line. A worker started as `artisan queue:work` has no
 # path in its arguments at all, because artisan resolves the working directory itself. The real
 # command line is `php84\php.exe artisan queue:work --tries=3 --sleep=1`, and not one character of it
 # names the project, so requiring the path reported "not running" over a worker that was running.
 #
 # That is the same shape of confident wrong answer as the missing queue check this script exists
 # beside, which is why it is called out here rather than left to be discovered again.
 #
 # The packaged install is excluded because it is the trap this whole script is about: it runs a
 # worker, it drains a different database, and from outside it is indistinguishable from one that
 # would have drained this checkout's.
#>
function Get-Workers {
    Get-CimInstance Win32_Process -Filter "Name='php.exe'" -ErrorAction SilentlyContinue |
        Where-Object {
            $cmd = $_.CommandLine

            $cmd -and
            $cmd -like '*artisan*' -and
            $cmd -notlike '*build\install*' -and
            $cmd -notlike '*packaging\launcher*' -and
            ($cmd -like '*queue:work*' -or $cmd -like '*schedule:work*')
        } |
        ForEach-Object {
            $kind = if ($_.CommandLine -like '*queue:work*') { 'queue' } else { 'scheduler' }
            [pscustomobject]@{ Kind = $kind; Pid = $_.ProcessId }
        }
}

$running = Get-Workers

if ($Stop) {
    if ($running.Count -eq 0) {
        Write-Output ''
        Write-Output '  nothing of this checkout is running'
        exit 0
    }

    foreach ($worker in $running) {
        Write-Output ("  stop  {0,-10} pid {1}" -f $worker.Kind, $worker.Pid)
        Stop-Process -Id $worker.Pid -ErrorAction SilentlyContinue
    }

    Start-Sleep -Seconds 2
    Write-Output ''
    Write-Output ("  left running: " + (Get-Workers).Count)
    exit 0
}

# The queue itself, read by a child PHP. Written to a file rather than passed through `php -r`,
# because a command line carrying this repository's path is where an afternoon has already been lost
# to a space splitting an argument in half.
function Get-QueueState {
    $probe = Join-Path $env:TEMP ('papyrus-queue-' + $PID + '.php')

    @'
<?php
$p = new PDO('sqlite:' . $argv[1]);
$now = time();
$q = (int) $p->query('SELECT count(*) FROM jobs')->fetchColumn();
$s = $p->prepare('SELECT count(*), min(available_at) FROM jobs WHERE reserved_at IS NULL AND available_at <= :n');
$s->execute([':n' => $now]);
[$u, $o] = $s->fetch(PDO::FETCH_NUM);
echo $q . '|' . $u . '|' . ($o === null ? 0 : $now - (int) $o);
'@ | Set-Content $probe -Encoding UTF8

    try {
        $raw = & $php $probe $database 2>$null
    } finally {
        Remove-Item $probe -ErrorAction SilentlyContinue
    }

    if ("$raw" -match '^(\d+)\|(\d+)\|(\d+)$') {
        return @{ Queued = [int] $Matches[1]; Undrained = [int] $Matches[2]; Age = [int] $Matches[3] }
    }

    return $null
}

if ($Status) {
    Write-Output ''
    Write-Output '  papyrus workers for this checkout'
    Write-Output ''

    $allUp = $true

    foreach ($name in $wanted.Keys) {
        $hit = @($running | Where-Object { $_.Kind -eq $name })

        if ($hit.Count -gt 0) {
            Write-Output ("  ok    {0,-10} pid {1}" -f $name, (($hit | ForEach-Object { $_.Pid }) -join ', '))
        } else {
            Write-Output ("  FAIL  {0,-10} not running" -f $name)
            $allUp = $false
        }
    }

    # The queue itself, because a worker being present has already fooled us once, and a worker for
    # another install looks exactly the same from here.
    $state = Get-QueueState

    if ($null -eq $state) {
        Write-Output '  ?     queue       could not be read'
    } elseif ($state.Queued -eq 0) {
        Write-Output '  ok    queue       idle, nothing queued'
    } elseif ($state.Undrained -eq 0) {
        Write-Output ("  ok    queue       {0} queued, all being taken" -f $state.Queued)
    } elseif ($state.Age -lt 60) {
        Write-Output ("  ok    queue       {0} queued, oldest due {1}s ago" -f $state.Queued, $state.Age)
    } else {
        Write-Output ("  FAIL  queue       {0} queued and the oldest has been due for {1} minutes" -f $state.Undrained, [math]::Round($state.Age / 60))
        $allUp = $false
    }

    exit $(if ($allUp) { 0 } else { 1 })
}

Write-Output ''
Write-Output '  papyrus workers for this checkout'
Write-Output ''

$missing = @()

foreach ($name in $wanted.Keys) {
    if ($running | Where-Object { $_.Kind -eq $name }) {
        Write-Output ("  ok    {0,-10} already running" -f $name)
    } else {
        Write-Output ("  start {0,-10} {1}" -f $name, $wanted[$name])
        $missing += $name
    }
}

<#
 * Options per command, not shared.
 *
 * `--tries` and `--sleep` are `queue:work` options. `schedule:work` does not have them and refuses
 * to start with "The --tries option does not exist", which is how the scheduler went missing for a
 * second time while this script reported that it had started it.
 *
 * Sharing one argument list across both is the obvious thing to write and it is the reason a half
 * started pair of workers is a state worth designing against at all.
#>
$options = @{
    'queue'     = @('--tries=3', '--sleep=1')
    'scheduler' = @()
}

foreach ($name in $missing) {
    $log = Join-Path $repo ('build\dev-' + $name + '.log')
    New-Item -ItemType Directory -Force -Path (Split-Path -Parent $log) | Out-Null

    Start-Process -FilePath $php `
        -ArgumentList (@('artisan', $wanted[$name]) + $options[$name]) `
        -WorkingDirectory $repo `
        -WindowStyle Hidden `
        -RedirectStandardOutput $log `
        -RedirectStandardError ($log + '.err') | Out-Null
}

if ($missing.Count -eq 0) {
    Write-Output ''
    Write-Output '  nothing to do; both were already up'
    exit 0
}

Start-Sleep -Seconds 5

Write-Output ''
$after = Get-Workers
$stillMissing = @()

foreach ($name in $wanted.Keys) {
    if ($after | Where-Object { $_.Kind -eq $name }) {
        Write-Output ("  ok    {0,-10} pid {1}" -f $name, ($after | Where-Object { $_.Kind -eq $name } | ForEach-Object { $_.Pid }))
    } else {
        Write-Output ("  FAIL  {0,-10} did not start" -f $name)
        $stillMissing += $name
    }
}

# The log, when one is missing. "Did not start" on its own sends the reader to a terminal to
# discover why, and the reason is usually sitting in the file this script just wrote.
foreach ($name in $stillMissing) {
    $log = Join-Path $repo ('build\dev-' + $name + '.log')

    if (Test-Path $log) {
        $said = @(Get-Content $log -ErrorAction SilentlyContinue | Where-Object { $_.Trim() -ne '' })

        if ($said.Count -gt 0) {
            Write-Output ''
            Write-Output ("  " + $name + ' said:')

            foreach ($line in ($said | Select-Object -Last 6)) {
                Write-Output ('      ' + $line.Trim())
            }
        }
    }
}

if ($stillMissing.Count -gt 0) {
    Write-Output ''
    Write-Output '  A worker that does not start is worse than one that is absent, because it looks'
    Write-Output '  present. Run it in a terminal to see why:'
    Write-Output ''
    Write-Output ("      cd `"$repo`"")
    foreach ($name in $stillMissing) {
        Write-Output ("      php artisan " + $wanted[$name])
    }
    exit 1
}

Write-Output ''
Write-Output '  Both up. Stop them with:'
Write-Output ''
Write-Output '      powershell -File packaging/dev-workers.ps1 -Stop'
Write-Output ''
Write-Output ("  Or leave them; they do nothing until something is queued. Logs in build\dev-*.log")
