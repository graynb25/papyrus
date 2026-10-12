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
    [switch] $Stop,
    # Prints the verdict for one command line, read from stdin, and exits. Exists so the rule can be
    # tested against fixed inputs instead of against whatever workers happen to be running on the
    # test machine, which is the mistake HealthTest documents having already made once.
    #
    # stdin and not an argument, because a Windows process command line is mostly spaces and quotes
    # and passing one as an argv value splits it at the first space. That happened while writing
    # this: the rule was asked about `C:\...\php84\php.exe` on its own, found no `artisan` in it, and
    # answered not-ours about a worker that is unambiguously ours. A test that fed the rule a
    # truncated input would have passed against a rule that is simply wrong.
    [switch] $Explain,

    # Prints the interpreter this script would start a worker with, and exits.
    #
    # Its own switch because it is the thing worth asserting. A worker started through Herd's
    # `php.bat` comes up, appears in the process list, and drains nothing, so "is a worker running"
    # is true and "is the queue being served" is false. That is the whole subject of this script, and
    # it is worth a test that can say which interpreter gets used without starting anything.
    [switch] $WhichPhp
)

$ErrorActionPreference = 'Stop'

# $PSScriptRoot is <repo>\packaging, so one parent is the repository. Two was wrong and looked
# for a database one level above the project, which is how the first run of this reported
# "no database" against a checkout that has a 5MB one.
$repo = Split-Path -Parent $PSScriptRoot

<#
 # A real `php.exe`, and never a batch wrapper.
 #
 # The first version took `(Get-Command php).Source`, which on this machine is Herd's `php.bat`. The
 # worker it started came up, showed up in the process list, and drained nothing: six jobs sat
 # unreserved for twenty-five minutes with a healthy-looking `queue:work` running, and the database
 # had no WAL file beside it, which is what proved nothing had it open at all.
 #
 # Replacing that with the interpreter itself drained all six in about a second and brought the WAL
 # back. So the wrapper is not a slower way to run the same thing, it is a different thing that
 # leaves a worker running and idle, which is the precise failure this script exists to prevent.
 #
 # Herd's layout is `bin/php.bat` wrapping `bin/php84/php.exe`, so the real interpreter is found
 # beside it.
#>
function Resolve-PHPExecutable {
    $configured = $env:PAPYRUS_PHP

    if ($configured -and (Test-Path $configured)) {
        return $configured
    }

    $onPath = (Get-Command php -ErrorAction SilentlyContinue).Source

    # Already a real interpreter, which is the ordinary case on a machine without a wrapper.
    if ($onPath -and $onPath -match '\.exe$') {
        return $onPath
    }

    $candidates = @()

    if ($onPath) {
        # Beside the wrapper: Herd puts php.bat in bin and the interpreter in bin/php84.
        $bin = Split-Path -Parent $onPath
        $candidates += @(Get-ChildItem (Join-Path $bin 'php*') -Filter 'php.exe' -Recurse -Depth 2 -ErrorAction SilentlyContinue |
            ForEach-Object { $_.FullName })
    }

    $candidates += @(
        'C:\php\php.exe',
        'C:\xampp\php\php.exe',
        'C:\laragon\bin\php\php.exe',
        'C:\Program Files\PHP\php.exe'
    )

    foreach ($candidate in $candidates) {
        if (Test-Path $candidate) { return $candidate }
    }

    return $null
}

$php = Resolve-PHPExecutable

if (-not $php) {
    Write-Output '  No PHP interpreter found.'
    Write-Output ''
    Write-Output '  This script needs php.exe itself, not a wrapper around it, because a worker'
    Write-Output '  started through a batch wrapper can run without draining anything.'
    Write-Output ''
    Write-Output '  Set PAPYRUS_PHP to the interpreter and try again, for example:'
    Write-Output ''
    Write-Output '      $env:PAPYRUS_PHP = "C:\Users\you\.config\herd\bin\php84\php.exe"'
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
 # Matched on the artisan command, and deliberately NOT on the repository path appearing in the
 # command line. A worker started as `artisan queue:work` has no path in its arguments at all,
 # because artisan resolves the working directory itself. The real command line is
 # `php84\php.exe artisan queue:work --tries=3 --sleep=1`, and not one character of it names the
 # project, so requiring the path reported "not running" over a worker that was running.
 #
 # ## What this gets wrong, which it got wrong the first time as well
 #
 # A worker belonging to a *different install* looks exactly like one that would have drained this
 # checkout's, and it is the trap this whole script exists to describe. The first version excluded
 # two known install paths by name, `build\install` and `packaging\launcher`, which covered every
 # install that existed when it was written.
 #
 # Then Papyrus was actually installed, and its worker appeared:
 #
 #     "...AppData\Local\Papyrus\php\php.ini" "...\Programs\Papyrus\artisan" "queue:work"
 #
 # Not one of the two exclusions matched it. This script reported `ok queue pid 6448` over a
 # checkout that had no worker at all, with six jobs sitting unreserved for seven minutes, which is
 # the exact failure it was written to surface. Naming the paths you happen to know about is not a
 # rule; it is a list that goes stale the moment anything new exists.
 #
 # ## The rule that does not go stale
 #
 # This checkout's own workers are started with a bare relative `artisan`, so they carry **no
 # directory in front of it**. Any worker whose command line has a path-qualified artisan is by
 # construction running some other install's code against some other install's database, whatever
 # that path turns out to be. That holds for `%LOCALAPPDATA%\Programs\Papyrus`, for `build\install`,
 # and for an install that does not exist yet.
 #>
# The rule, as a function, so it can be asked about a command line rather than only about whatever
# happens to be running. `-Explain` calls it directly, which is how the test exercises it without
# depending on what this machine is doing at the time.
function Test-CheckoutWorkerCommandLine {
    param([string] $CommandLine)

    if (-not $CommandLine) { return $false }
    if ($CommandLine -notlike '*artisan*') { return $false }
    if ($CommandLine -notlike '*queue:work*' -and $CommandLine -notlike '*schedule:work*') { return $false }

    # A path-qualified artisan is another install's. See above.
    if ($CommandLine -match '[\\/]artisan') { return $false }

    return $true
}

function Get-Workers {
    Get-CimInstance Win32_Process -Filter "Name='php.exe'" -ErrorAction SilentlyContinue |
        Where-Object { Test-CheckoutWorkerCommandLine $_.CommandLine } |
        ForEach-Object {
            $kind = if ($_.CommandLine -like '*queue:work*') { 'queue' } else { 'scheduler' }
            [pscustomobject]@{ Kind = $kind; Pid = $_.ProcessId }
        }
}

if ($WhichPhp) {
    Write-Output $php
    exit 0
}

if ($Explain) {
    $line = [Console]::In.ReadToEnd().Trim()
    $verdict = Test-CheckoutWorkerCommandLine $line
    Write-Output ("{0}|{1}" -f $(if ($verdict) { 'checkout' } else { 'not-ours' }), $line)
    exit 0
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
