# Runs the packaged install inside the Windows Sandbox and writes what happened to C:\results.
#
# ## If you are reading this because you opened the sandbox and Papyrus is not at papyrus.test
#
# It is not, and it cannot be. `papyrus.test` is a *host* name: it is a line in this machine's hosts
# file pointing at 127.0.0.1, and it is served by Herd's nginx on port 80 of this machine. A Windows
# Sandbox guest is a separate machine with its own hosts file and its own network stack, and it has no
# route to this machine's port 80. So a browser inside the guest asking for papyrus.test gets
# ERR_CONNECTION_REFUSED, which is the correct answer and not a fault.
#
# Inside the guest, from this gate's configuration:
#
#     Papyrus          http://127.0.0.1:8402
#     Suwayomi's WebUI http://127.0.0.1:4571
#
# Both of those are loopback inside the guest and work. If you are looking at Suwayomi's own WebUI and
# it is fine, that is the manga server working and says nothing about whether Papyrus is up; open the
# app's port to find out.
#
# Deliberately plain PowerShell with no dependency on anything outside the sandbox. Every earlier
# check used PHP probes written for the development machine, and the point of this gate is to test
# the install rather than the harness that tested it. A probe that has been through ten revisions
# this week is not something to point at a clean machine and call a result.
#
# Everything it reports is something it observed. It never infers a pass from the absence of an
# error, because that is the mistake four separate probes made during the phase 0 work.

$ErrorActionPreference = 'Continue'
$report = [System.Collections.Generic.List[string]]::new()
$failures = [System.Collections.Generic.List[string]]::new()

function Say([string]$line) {
    Write-Host $line
    $report.Add($line) | Out-Null
}

function Section([string]$title) {
    Say ''
    Say "=== $title ==="
}

# Recorded rather than inferred, so a failing assertion names the thing that was expected.
function Check([string]$what, [bool]$ok, [string]$detail = '') {
    $mark = if ($ok) { 'ok  ' } else { 'FAIL' }
    $line = "  [$mark] $what"

    if ($detail -ne '') {
        $line = "$line  $detail"
    }

    Say $line

    if (-not $ok) {
        $failures.Add($what) | Out-Null
    }
}

function Save-Report {
    $path = 'C:\results\sandbox-report.txt'
    Set-Content -Path $path -Value ($report -join [Environment]::NewLine) -Encoding UTF8
    Write-Host ''
    Write-Host "  report written to $path"
}

# Written after every line, so a guest that dies mid run still leaves everything up to that point.
#
# The first three runs each produced either nothing at all or a report that stopped at the first
# failure, which made them hard to tell apart from a run that had not started. A progress file
# that grows answers "did it start, and how far did it get" without needing the sandbox to
# finish cleanly, which is the only way to tell a hung guest from a guest that never booted.
$progress = 'C:\results\progress.txt'

function Say([string]$line) {
    Write-Host $line
    $report.Add($line) | Out-Null
    Set-Content -Path 'C:\results\progress.txt' -Value ($report -join [Environment]::NewLine) -Encoding UTF8
}

$source = 'C:\build\install'

# With a space, deliberately, for the same reason the data directory has one.
#
# `C:\Program Files\Papyrus` is where a Windows installer puts this by default. This gate copied
# to `C:\papyrus`, so the install root was the last path in the whole system that no reader is
# on, and it is the one that reaches the most: the Caddyfile document root, the extension
# directory in php.ini, the artisan path on both workers' command lines, and the jar path the
# JVM is started with.
#
# The data directory got a space first and the gate immediately broke two of its own checks on
# it, which is the argument for doing this too. A harness whose paths all avoid the awkward case
# is not a weaker harness, it is a different one.
$root = 'C:\Program Files\Papyrus'

# With a space, deliberately.
#
# This was `C:\papyrus-data`, which is the path that made this gate unable to see the worst bug
# it existed to find. A data directory whose path contains a space produced an install whose
# .env dotenv refused to load, so the whole application was dead with an error naming a parser
# rather than a path, and the launcher reported that the database could not be migrated.
#
# It survived because nothing here had a space in it. The unit tests use a temp directory, whose
# path has none either. `C:\Users\you\AppData\Local\Programs\Papyrus\data` is where a real
# install puts this, and every path this gate chose was a path no reader is on.
#
# One space is the whole point: an install that works at `C:\papyrus-data` says nothing about one
# that works where Windows puts it by default.
$data = 'C:\papyrus data'
$appPort = 8402

# Not 4567. Deliberately not the default, and this is the reason.
#
# Every probe in this project passed 4567, including this gate, so the install worked for as long
# as it was only ever asked about the port Suwayomi happens to choose for itself. The launcher
# waited three minutes on a port it had never told Suwayomi about, and nothing here could see it:
# a test that always passes the default cannot fail.
#
# Running the gate on a port nobody uses is what turns that from a blind spot into a check.
$suwayomiPort = 4571

# Written before anything else, so a guest that cannot find the install still says so. The first
# three runs of this gate produced no results file at all when the guest died early, which left
# nothing to tell a failed check apart from a guest that never booted.
New-Item -ItemType Directory -Force -Path 'C:\results' | Out-Null
Set-Content -Path 'C:\results\progress.txt' -Value 'sandbox runner started' -Encoding UTF8

Section 'the premise'

# The whole reason for this gate. If a JVM is reachable by name, the run below proves nothing,
# because the install might quietly be using it.
$javaOnPath = @(Get-Command java -ErrorAction SilentlyContinue)
Check 'no java on PATH' ($javaOnPath.Count -eq 0) "found $($javaOnPath.Count)"

$phpOnPath = @(Get-Command php -ErrorAction SilentlyContinue)
Check 'no php on PATH' ($phpOnPath.Count -eq 0) "found $($phpOnPath.Count)"

$installedJava = Test-Path 'C:\Program Files\Java'
Check 'no Java installed' (-not $installedJava)

Section 'copying the install out of the read only mount'

if (-not (Test-Path $source)) {
    Say "  no install at $source, the build has not run"
    Save-Report
    exit 1
}

Say "  copying $source to $root, about 620MB, this takes a minute"
Copy-Item -Path $source -Destination $root -Recurse -Force
Say '  copied'

foreach ($required in @(
    'runtime\bin\php.exe',
    'runtime\bin\frankenphp.exe',
    'runtime\cacert.pem',
    'runtime\php\php.ini',
    'suwayomi\runtime\bin\java.exe',
    'artisan',
    'composer.json',
    '.env.example'
)) {
    Check "$required present" (Test-Path (Join-Path $root $required))
}

$jarCount = @(Get-ChildItem (Join-Path $root 'suwayomi') -Filter '*.jar' -ErrorAction SilentlyContinue).Count
Check 'exactly one suwayomi jar' ($jarCount -eq 1) "found $jarCount"

Section 'what must not have shipped'

# A build that leaked the developer's library is a privacy failure, and it is the one problem
# here that no amount of a successful start would reveal.
$leakedDb = @(Get-ChildItem (Join-Path $root 'database') -Filter '*.sqlite' -ErrorAction SilentlyContinue)
Check 'no database in the install' ($leakedDb.Count -eq 0) "found $($leakedDb.Count)"

$realEnv = Test-Path (Join-Path $root '.env')
Check 'no .env shipped' (-not $realEnv)

$logs = @(Get-ChildItem (Join-Path $root 'storage\logs') -File -ErrorAction SilentlyContinue)
Check 'no logs shipped' ($logs.Count -eq 0) "found $($logs.Count)"

Section 'starting'

$env:PAPYRUS_ROOT = $root
$env:PAPYRUS_DATA = $data
$env:PAPYRUS_SUWAYOMI_PORT = $suwayomiPort
$env:PAPYRUS_APP_PORT = $appPort

$launcherPhp = Join-Path $root 'runtime\bin\php.exe'
$launcher = Join-Path $root 'packaging\launcher\papyrus.php'
$ini = Join-Path $root 'runtime\php\php.ini'

# The bundled php.exe, by absolute path, which is the arrangement the launcher itself uses. The
# alternative, relying on `php` being on PATH, is the thing being ruled out.
#
# Every piece of output captured separately, and the exit code read from the process rather than
# from $LASTEXITCODE. The first run of this gate reported an empty version string and a bare
# failure, because stderr was folded into stdout by `2>&1` and the exit code came from a variable
# a native command failure had left unset. An error you cannot read is not a diagnosis.
function Invoke-Captured([string]$exe, [string[]]$arguments, [string]$label) {
    $outFile = "C:\results\$label.out"
    $errFile = "C:\results\$label.err"

    $process = Start-Process -FilePath $exe -ArgumentList $arguments -NoNewWindow -Wait -PassThru `
        -RedirectStandardOutput $outFile -RedirectStandardError $errFile

    # ReadAllText rather than `[string](Get-Content -Raw)`, measured 2026-10-16: on an empty file
    # Get-Content -Raw answers $null, and [string]$null is $null too, so that cast does not save it
    # and the .Trim() below throws "You cannot call a method on a null-valued expression."
    # ReadAllText answers '' for an empty file, so Out and Err are strings for every caller.
    $out = if (Test-Path $outFile) { [System.IO.File]::ReadAllText($outFile) } else { '' }
    $err = if (Test-Path $errFile) { [System.IO.File]::ReadAllText($errFile) } else { '' }

    Say "  $label exit code: $($process.ExitCode)"

    if ($out.Trim() -ne '') { Say "  $label stdout: $($out.Trim())" }
    if ($err.Trim() -ne '') { Say "  $label stderr: $($err.Trim())" }

    return @{ Code = $process.ExitCode; Out = $out; Err = $err }
}

# The most basic invocation first. If this cannot run, nothing after it is worth attempting and
# the run should stop rather than produce seven failures that all say the same thing.
$bare = Invoke-Captured $launcherPhp @('--version') 'php-version'

Check 'the bundled php runs' ($bare.Code -eq 0) "exit $($bare.Code)"

if ($bare.Code -ne 0) {
    Say ''
    Say '  the bundled php cannot run, so nothing else can be tested. Diagnostics:'

    $bin = Join-Path $root 'runtime\bin'
    Say "    files beside php.exe: $(@(Get-ChildItem $bin -File).Count)"
    Say "    dll files beside it:  $(@(Get-ChildItem $bin -Filter '*.dll').Count)"

    # A missing side by side DLL is the usual reason a portable php.exe will not start, and it
    # reports nothing at all on the console. Named explicitly so the next run knows to look.
    foreach ($dll in @('php8ts.dll', 'vcruntime140.dll', 'vcruntime140_1.dll', 'ucrtbase.dll')) {
        Say "    $dll present: $(Test-Path (Join-Path $bin $dll))"
    }

    Save-Report
    exit 1
}

# The ini the launcher *writes*, not the shipped template.
#
# The shipped template is `packaging/php/php.ini` and it contains `{{RUNTIME}}/ext` literally,
# because the launcher resolves it into the data directory at start. Handing that template to
# `php -c` produces eleven extension warnings and an invented failure, which is what the first two
# runs of this gate did: the report blamed the install for a file the install never uses.
$resolvedIni = Join-Path $data 'php\php.ini'

if (-not (Test-Path $resolvedIni)) {
    Say "  no resolved ini at $resolvedIni yet, so it cannot be tested before a start"
    Say '  the launcher writes it, so this is checked after starting rather than before'
} else {
    $version = Invoke-Captured $launcherPhp @('-c', $resolvedIni, '-r', 'echo PHP_VERSION;') 'php-with-ini'
    Say "  bundled PHP with the resolved ini reports: $($version.Out.Trim())"
    Check 'the bundled php reads the resolved ini' ($version.Code -eq 0) "exit $($version.Code)"
}

# Streamed to a file and tailed, rather than captured into a variable.
#
# The first version did `$startOutput = & $launcherPhp $launcher start 2>&1` and printed it
# afterwards, which buffers the launcher's entire output until it exits. That froze progress.txt
# for ten minutes at exactly the step that matters, and swallowed the launcher's stderr
# diagnostics, which are the only thing that would have explained the hang. A harness that hides
# the failure it is looking for is worse than one that crashes.
$startLog = 'C:\results\launcher-start.log'

# Papyrus.cmd, the file the desktop shortcut runs, rather than `php papyrus.php start`.
#
# A reader never runs `php papyrus.php start`, and for the whole life of this gate it was the only
# thing the gate started, so 55 green checks said nothing about the shortcut anybody double clicks
# and nothing about whether a browser opens, which is the one thing a reader actually sees. It is
# also the only entry point that passes `--open`, and without that argument the launcher starts the
# app and opens nothing at all. The two checks at the end of the start section exist because of it.
#
# Through cmd.exe, because a .cmd file needs a command interpreter, and the installer is not run in
# the guest, so the .lnk a reader clicks does not exist here; this is the file it points at. And
# through a runner batch, written below, rather than by handing the shortcut's path to cmd.exe
# directly. That is not indirection for its own sake. Two failures, both reproduced on the host
# before either was believed, put the runner there.
#
# The first is quoting. `@('/c', '"' + $shortcut + '"')` is not the two arguments it reads as: the
# comma binds tighter than +, so PowerShell built four elements, `'/c', '"', $shortcut, '"'`, and
# Start-Process joins an argument list with spaces. cmd.exe received a quote standing apart from
# the path at each end, `/c " C:\Program Files\Papyrus\Papyrus.cmd "`, applied its own /c rules to
# that, was left with an unquoted path, and tried to run `C:\Program`. What it writes is
# `'C:\Program' is not recognized as an internal or external command`, and that is exactly what the
# guest reported in the failed sandbox run. A single correctly built argument runs the same file,
# so the invocation was the suspect rather than the shortcut.
#
# The second is the exit code. -PassThru with redirected streams returns a process object whose
# `ExitCode` reads empty on this machine, before and after `WaitForExit()`, and the launcher cannot
# be started with -Wait because it is meant to outlive this script's watch; the -Wait form in
# `Invoke-Captured` does populate the code, which is why the php commands above it read fine. So
# the runner writes `%errorlevel%` to a file the moment the shortcut returns, and the failure path
# below reads that file instead of the process object.
#
# Started detached so this script keeps control and can keep reporting. The launcher outlives this
# script by design, since it leaves four processes running that the probe section then exercises.
#
# The stdin redirect is a real empty file, written here, immediately above the call that opens it.
# Two measured failures taught that shape: `-RedirectStandardInput 'NUL'` makes Start-Process
# return nothing, with no error, and the run dies with an empty pid and no launcher log at all,
# because PowerShell will not open the null device as an input file; and an empty file created
# three hundred lines further down does not exist yet when this call runs, which fails in exactly
# the same way. With the file here, the `pause` in Papyrus.cmd's failure path reads end of file
# and returns, so a stopped install cannot sit at a keypress.
$shortcut = Join-Path $root 'Papyrus.cmd'
$emptyStdin = 'C:\results\launcher-stdin.txt'
[System.IO.File]::WriteAllText($emptyStdin, '')

# Written immediately above the call that opens it, for the same reason the stdin file is, and with
# every concatenated element parenthesized: the comma precedence that mangled the direct form
# splits `'call "' + $shortcut + '"'` into three separate lines without them, measured on the host,
# and the runner then runs a line reading `call "`, reports 9009, and never touches the shortcut.
$shortcutRunner = 'C:\results\start-papyrus.cmd'
$shortcutExit = 'C:\results\launcher-exit.txt'
[System.IO.File]::WriteAllLines($shortcutRunner, @(
    '@echo off',
    ('call "' + $shortcut + '"'),
    'set "PAPYRUS_SHORTCUT_EXIT=%errorlevel%"',
    'echo launcher exit code: %PAPYRUS_SHORTCUT_EXIT%',
    ('echo %PAPYRUS_SHORTCUT_EXIT% > "' + $shortcutExit + '"'),
    'exit /b %PAPYRUS_SHORTCUT_EXIT%'
))

$startProcess = Start-Process -FilePath 'cmd.exe' -ArgumentList @('/c', $shortcutRunner) -NoNewWindow -PassThru `
    -RedirectStandardInput $emptyStdin -RedirectStandardOutput $startLog -RedirectStandardError 'C:\results\launcher-start.err'

if ($null -eq $startProcess) {
    # Start-Process returns nothing when it cannot open a redirect target, and everything below
    # would read an empty pid as a broken install. Named here rather than at the first use.
    Check 'the shortcut started' $false 'Start-Process returned nothing, so nothing was started'
    Save-Report
    exit 1
}

Say "  Papyrus.cmd started, pid $($startProcess.Id), watching its output"

# Reported every few seconds so progress is visible while the launcher works. The readiness poll
# is one attempt a second for up to sixty, so a minute of silence is normal and more than that is
# not, and only a running log tells the two apart.
# Discovered, not assumed.
#
# `papyrus.php start` picks a free port when it is not told one, and says which it chose on its
# own output: "Papyrus is running at http://127.0.0.1:8000". This runner originally hard coded 8402,
# so it probed a port nothing was listening on and reported six failures against a perfectly healthy
# install. The port is read from the launcher's own report rather than guessed, because the launcher
# is the only thing that knows.
$appPort = $null

# `start` is a foreground command that holds and serves, so "still running" is success. The first
# version of this runner treated a four minute run as a hang and reported it as a failure, which is
# the same misreading twice: a healthy install that stays up is the expected result, and the check
# that matters is whether it answered, not whether it returned.
$seen = 0
$deadline = (Get-Date).AddMinutes(4)
$reportedPort = $null

# Watched while the start runs, because the browser can open and then close, and "the shortcut
# opened a browser" is a claim about what happened during the start rather than about what is
# still true four minutes later. `msedge` is the browser a clean Windows guest has; the rest cost
# nothing to look for.
$browserNames = @('msedge', 'chrome', 'firefox', 'opera', 'brave', 'vivaldi')
$browserSeen = $false

while (-not $startProcess.HasExited -and (Get-Date) -lt $deadline) {
    Start-Sleep -Seconds 10

    foreach ($file in @($startLog, 'C:\results\launcher-start.err')) {
        if (-not (Test-Path $file)) {
            continue
        }

        $lines = @(Get-Content $file -ErrorAction SilentlyContinue)

        if ($lines.Count -gt $seen) {
            $lines[$seen..($lines.Count - 1)] | ForEach-Object { Say "    $_" }
            $seen = $lines.Count
        }
    }

    # Read the port the launcher reported, once, from its own output.
    #
    # The host is matched as either spelling on purpose. `Supervisor::appUrl()` prints `localhost`,
    # deliberately, because that is the address the app itself advertises in APP_URL and it is what
    # a WebAuthn relying-party id is derived from. This runner still looked for `127.0.0.1` only, so
    # after that deliberate change the pattern matched nothing, `$reportedPort` stayed null, and the
    # gate reported five failures against an install that was serving correctly the whole time:
    #
    #     [FAIL] the launcher reached running   never reported a port
    #     [ok  ] port  released                 <- the port was empty, which is the symptom
    #     [FAIL] /ready is 200  got 0           <- it probed http://127.0.0.1:/ready
    #
    # So the pattern accepts both, and the comment above it stays, because a pattern that names one
    # spelling is a pattern that will break the next time the launcher deliberately changes it.
    if ($null -eq $reportedPort -and (Test-Path $startLog)) {
        $match = Select-String -Path $startLog -Pattern 'running at http://(?:127\.0\.0\.1|localhost):(\d+)' -ErrorAction SilentlyContinue |
            Select-Object -First 1

        if ($match) {
            $reportedPort = [int]$match.Matches[0].Groups[1].Value
            Say "  launcher reported app port $reportedPort, using that rather than a guessed one"
        }
    }

    if (-not $browserSeen) {
        $appeared = @(Get-Process -Name $browserNames -ErrorAction SilentlyContinue)

        if ($appeared.Count -gt 0) {
            $browserSeen = $true
            Say "  a browser appeared while starting: $(@($appeared | ForEach-Object { $_.ProcessName } | Select-Object -Unique) -join ', ')"
        }
    }
}

if (-not $startProcess.HasExited) {
    # Not a failure. This is what a working install looks like from outside.
    Say '  the launcher is still holding and serving after 4 minutes, which is what start does'

    # The detail says what actually happened rather than what used to happen.
    #
    # This was a literal 'never reported a port', which is the text of the failure. Once the pattern
    # matched, the run printed a green line reading:
    #
    #     [ok  ] the launcher reached running  never reported a port
    #
    # A check that passes while its own detail describes the failure it once reported is a check
    # nobody can read quickly, and a reader scanning for red will miss that the wording is wrong. The
    # same fault as `port  released` with an empty port name: a string that survived the fix it was
    # written for.
    Check 'the launcher reached running' ($null -ne $reportedPort) `
        $(if ($null -eq $reportedPort) { 'never reported a port' } else { "reported port $reportedPort" })

    if ($null -ne $reportedPort) {
        $appPort = $reportedPort
    }

    # ---------------------------------------------------------------------------------------
    # Is the scheduler actually dispatching?
    #
    # Everything above this point can pass with a scheduler that has never once run, and did. The
    # launcher prints "queue worker and scheduler started" whether or not either of them can reach a
    # database, which is exactly what happened: `schedule:work` shells out to `schedule:run` without
    # passing it the ini, the grandchild loads no extensions, and it dies every ten seconds with
    # "could not find driver". 182 times, with `health` reporting all four processes healthy, in an
    # install whose downloads silently never happened.
    #
    # So this gate, the only check in the project that runs on a machine with nothing installed, would
    # have passed over the worst defect in its history. It checked that the process existed and never
    # asked whether it did anything. Liveness is not participation.
    #
    # Measured by the log's modification time rather than by parsing a timestamp out of it, for the
    # same reason the health check does: the scheduler renders timestamps at UTC+5 while this guest
    # runs at UTC, so an age computed from a parsed line would be wrong by five hours. A file's mtime is
    # a unix timestamp and does not care.
    #
    # Two samples with a gap between them, because one sample cannot tell "not dispatching" from "has
    # not started yet". The gap is longer than the ten second schedule interval, so a working scheduler
    # must have written something during it.
    Section 'is the scheduler dispatching'

    $schedulerLog = Join-Path $data 'logs\scheduler.log'
    $firstStamp = $null
    $firstSize = -1

    if (Test-Path $schedulerLog) {
        $firstStamp = (Get-Item $schedulerLog).LastWriteTimeUtc
        $firstSize = (Get-Item $schedulerLog).Length
        Say "  scheduler.log is $firstSize bytes, last written $($firstStamp.ToString('HH:mm:ss')) UTC"
    } else {
        Say "  no scheduler.log at $schedulerLog yet"
    }

    Start-Sleep -Seconds 35

    if (-not (Test-Path $schedulerLog)) {
        Check 'the scheduler is dispatching' $false 'no scheduler.log after 35 seconds of running'
    } else {
        $secondStamp = (Get-Item $schedulerLog).LastWriteTimeUtc
        $secondSize = (Get-Item $schedulerLog).Length
        $grew = $secondSize -gt $firstSize
        $moved = $secondStamp -gt $firstStamp

        Say "  after 35s it is $secondSize bytes, last written $($secondStamp.ToString('HH:mm:ss')) UTC"

        Check 'the scheduler is dispatching' ($grew -and $moved) `
            "grew=$grew mtimeAdvanced=$moved, a working scheduler writes every 10s"

        if (-not $grew) {
            # The failure a reader would report is "my downloads never happen", so name it here
            # rather than leaving a bare boolean in a log file.
            Say '  the scheduler process is up but is writing nothing. Downloads and the library check'
            Say '  will silently never run, and nothing else in this gate would have noticed.'

            if (Test-Path $schedulerLog) {
                Say '  the tail of scheduler.log, which is where the reason usually is:'
                Get-Content $schedulerLog -Tail 12 | ForEach-Object { Say "    $_" }
            }
        }
    }

    # And the launcher's own verdict, so a wedge is caught even if the log happens to be growing.
    $healthOut = Join-Path $data 'logs\health.txt'
    $healthText = & (Join-Path $root 'runtime\bin\php.exe') (Join-Path $root 'packaging\launcher\papyrus.php') health 2>&1 | Out-String
    foreach ($line in ($healthText -split "`n")) {
        if ($line -match '\S') { Say "  $_" }
    }

    Check 'health reports the scheduler dispatching' ($healthText -match 'schedule\s+dispatching') `
        'the health line is what a reader is shown, so it has to be right and not merely the process'

    # ---------------------------------------------------------------------------------------
    # What the shortcut did that nothing else here does.
    #
    # Papyrus.cmd is the only entry point in this repository that passes `--open`, so a browser
    # that opened during this start is the shortcut's doing and cannot be anything else's. Both
    # halves are required: the launcher's own line says the shortcut asked, and a browser process
    # says the ask did something. A browser without the line would be something else opening one,
    # and a line without a browser is the silent failure this check exists to catch.
    Section 'the shortcut opened a browser'

    $alive = @(Get-Process -Name $browserNames -ErrorAction SilentlyContinue)
    $asked = Select-String -Path $startLog -Pattern 'Asked your browser to open it' -ErrorAction SilentlyContinue |
        Select-Object -First 1

    Check 'the shortcut opened a browser' ($browserSeen -and $null -ne $asked) `
        "browser seen while starting: $browserSeen; alive now: $($alive.Count); the launcher said it asked: $($null -ne $asked)"

    $browserWindow = @(Get-Process -Name $browserNames -ErrorAction SilentlyContinue |
        Where-Object { $_.MainWindowTitle -ne '' } | Select-Object -First 1)
    $browserTitle = if ($browserWindow.Count -gt 0) { $browserWindow[0].MainWindowTitle } else { '' }

    Check 'the browser window shows Papyrus' ($browserTitle -match 'Papyrus' -and $browserTitle -notmatch "can.t reach|refused|^localhost") `
        $browserTitle

    # ---------------------------------------------------------------------------------------
    # What the reader would have seen.
    #
    # A process existing is not a page having rendered, and the check above cannot tell a loaded
    # page from an error tab. So the screen is captured, and the file is left where a person can
    # look at it. The guest is gone when the run ends, which is why the capture is written to the
    # results folder rather than kept inside the guest.
    Section 'a screenshot of the guest'

    $screenPath = 'C:\results\screen-after-start.png'
    $screenProblem = ''

    try {
        Add-Type -AssemblyName System.Windows.Forms
        Add-Type -AssemblyName System.Drawing

        $bounds = [System.Windows.Forms.Screen]::PrimaryScreen.Bounds
        $bitmap = New-Object System.Drawing.Bitmap $bounds.Width, $bounds.Height
        $canvas = [System.Drawing.Graphics]::FromImage($bitmap)
        $canvas.CopyFromScreen($bounds.Location, [System.Drawing.Point]::Empty, $bounds.Size)
        $bitmap.Save($screenPath, [System.Drawing.Imaging.ImageFormat]::Png)
        $canvas.Dispose()
        $bitmap.Dispose()
    } catch {
        $screenProblem = $_.Exception.Message
    }

    $screenBytes = if (Test-Path $screenPath) { (Get-Item $screenPath).Length } else { 0 }

    Check 'a screenshot of the guest was written' ($screenBytes -gt 0) `
        $(if ($screenBytes -gt 0) { "$screenPath, $screenBytes bytes" } elseif ($screenProblem -ne '') { "the capture failed: $screenProblem" } else { 'nothing was written' })
} else {
    # From the runner's file, not the process object, for the reason in the comment where the
    # runner is written: ExitCode reads empty on the object a redirected no-wait Start-Process
    # returns, and the runner recorded the code when the shortcut returned.
    $exitText = if (Test-Path $shortcutExit) {
        ([string](Get-Content $shortcutExit -Raw -ErrorAction SilentlyContinue)).Trim()
    } else {
        ''
    }
    $exitCode = $null

    if ($exitText -match '^-?\d+$') {
        $exitCode = [int]$exitText
    }

    Say "  Papyrus.cmd exited with code $(if ($null -eq $exitCode) { 'not recorded' } else { $exitCode })"

    foreach ($file in @($startLog, 'C:\results\launcher-start.err')) {
        if (Test-Path $file) {
            $lines = @(Get-Content $file)

            if ($lines.Count -gt $seen) {
                $lines[$seen..($lines.Count - 1)] | ForEach-Object { Say "    $_" }
            }
        }
    }

    Check 'the launcher exited cleanly' ($exitCode -eq 0) `
        $(if ($null -eq $exitCode) { "no code was recorded at $shortcutExit" } else { "exit $exitCode" })
}

if ($null -eq $appPort) {
    # Recorded as a failure rather than merely announced, and the web checks below are skipped rather
    # than run against an empty port.
    #
    # Announcing it and carrying on is what produced five failures and one false pass in a single run:
    # `http://127.0.0.1:/ready` with nothing after the colon, reported as "/ready is 200, got 0", and
    # a stop check that printed "port  released" with the port name literally empty and counted it as
    # a pass. Every one of those was the missing port wearing a different hat, and none of them named
    # the missing port. A condition that cannot proceed has to say so where the result is read.
    Say '  no app port was ever reported, so the web checks below cannot run'
    Check 'the launcher reported a port to probe' $false 'it never printed "running at http://<host>:<port>"'
}

# Checked here rather than before the start, because the launcher writes this file as part of
# starting. Checking it before the start is checking a file that does not exist yet, which is how
# the first two runs of this gate reported a failure against the wrong ini.
if (Test-Path $resolvedIni) {
    # Quoted, and the reason is two separate bugs rather than one.
    #
    # The `-r` argument has to be quoted because it contains a space, and unquoted PHP receives
    # `-r echo` and treats PHP_VERSION; as a stray argument. Measured, with Start-Process and
    # both streams redirected:
    #
    #     unquoted   exit 255   stdout 82 bytes   stderr 0 bytes
    #                 "Parse error: syntax error, unexpected end of file"
    #     quoted     exit 0     stdout  6 bytes   stderr  0 bytes   "8.5.11"
    #
    # Note stderr is empty in the failing case too. PHP writes that parse error to STDOUT, so an
    # empty stderr here was never evidence of anything. An earlier note in this file treated the
    # silence as unexplained, on the assumption that a parse error would go to stderr. That
    # assumption was wrong, and it sent the investigation looking for a second fault that was
    # never there.
    #
    # The `-c` path needs quoting, and one argument string rather than pieces. Unquoted, PHP
    # receives "C:\papyrus" and "data\php\php.ini" as two arguments and reports "Could not open
    # input file: data\php\php.ini", which names a file that does not exist and reads as a broken
    # install. Quoted but in list form it fails quietly instead: the comma binds tighter than `+`,
    # the quote characters become elements of their own, and Start-Process joins the pieces with
    # spaces, so php is handed ` C:\papyrus data\php\php.ini ` and silently ignores it, exiting 0
    # and printing the version anyway. The 2026-10-15 run passed this check on 6 bytes of 8.5.11,
    # which says nothing about the ini.
    # One string reaches php verbatim, and the probe asks for memory_limit, which only the ini
    # sets: 512M against php's 128M default. Measured 2026-10-16 with the bundled php and a path
    # containing a space.
    $version = Invoke-Captured $launcherPhp @('-c "' + $resolvedIni + '" -r "echo PHP_VERSION, '' '', ini_get(''memory_limit'');"') 'php-resolved-ini'
    Say "  bundled PHP with the launcher's resolved ini: $($version.Out.Trim())"
    Check 'the launchers resolved ini loads cleanly' ($version.Code -eq 0 -and $version.Out -match '512M') "exit $($version.Code): $($version.Out.Trim())"
} else {
    Check 'the launcher resolved an ini' $false "no file at $resolvedIni"
}

Section 'probing'

# `method`, `form` and `session` exist for the reader path below: registering and opening the
# sources page is the one walk in this script that needs cookies, and a POST. They are optional so
# every earlier probe is unchanged, and the catch stays the single place that turns a failed
# request into a readable status and body rather than a thrown exception.
function Get-Body([string]$url, [string]$method = 'Get', $form = $null, $session = $null, [int]$timeout = 30) {
    try {
        $request = @{ Uri = $url; UseBasicParsing = $true; TimeoutSec = $timeout; Method = $method }

        if ($null -ne $form) { $request.Body = $form }
        if ($null -ne $session) { $request.WebSession = $session }

        $response = Invoke-WebRequest @request
        return @{ Status = [int]$response.StatusCode; Body = [string]$response.Content }
    } catch {
        # A 503 is an answer, not a failure to connect, and it is the one that matters here.
        $status = 0
        $body = $_.Exception.Message

        if ($_.Exception.Response) {
            $status = [int]$_.Exception.Response.StatusCode
            try {
                $body = (New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())).ReadToEnd()
            } catch {
                $body = '(unreadable body)'
            }
        }

        return @{ Status = $status; Body = $body }
    }
}

# Guarded, because with no port these were probing `http://127.0.0.1:/ready` and reporting the
# result as a failing endpoint rather than as the missing port it actually was. The failure that
# names the missing port has already been recorded above; this block would only add noise about a
# URL that was never valid.
if ($null -ne $appPort) {
    $ready = Get-Body "http://127.0.0.1:$appPort/ready"
    Say "  /ready  $($ready.Status)  $($ready.Body)"

    $readyDecoded = $null

    try {
        $readyDecoded = $ready.Body | ConvertFrom-Json
    } catch {
        Say '  /ready did not return json'
    }

    Check '/ready is 200' ($ready.Status -eq 200) "got $($ready.Status)"
    Check '/ready says ready' ($null -ne $readyDecoded -and $readyDecoded.status -eq 'ready')

    if ($null -ne $readyDecoded -and $null -ne $readyDecoded.checks) {
        Check 'app key is set' ($readyDecoded.checks.key -eq $true) "key=$($readyDecoded.checks.key)"
        Check 'database opens' ($readyDecoded.checks.database -eq $true) "database=$($readyDecoded.checks.database)"
        Check 'no pending migrations' ($readyDecoded.checks.pending_migrations -eq 0) "pending=$($readyDecoded.checks.pending_migrations)"
    }

    $up = Get-Body "http://127.0.0.1:$appPort/up"
    Check '/up is 200' ($up.Status -eq 200) "got $($up.Status)"

    # The app's own page, not merely a page.
    #
    # This matched `<!DOCTYPE html>|<html` for the life of the gate. Its own comment named the
    # failure it existed to catch: an app returning its own error page while still answering. A
    # Laravel 500 is a doctype page, so the check accepted exactly what the comment said it would
    # not, and the comment was the half that was right.
    #
    # Three conditions, because the two obvious ones are each satisfied by the wrong page: a blank
    # shell carries the app's title, and a heading alone says nothing about which app rendered it.
    # So the body must carry the title AND the rendered heading, and must not carry the markers a
    # 500 or a debug error page leaves. Proven against six bodies, of which two are the app's page.
    $hasTitle = $up.Body -match '<title>\s*Papyrus\s*</title>'
    $hasHeading = $up.Body -match 'Application up'
    $noErrorMarkers = $up.Body -notmatch 'Server Error|Whoops|Stack trace'

    Check '/up rendered its own page' ($hasTitle -and $hasHeading) "title=$hasTitle heading=$hasHeading"
    Check '/up carries no error page' $noErrorMarkers

    $login = Get-Body "http://127.0.0.1:$appPort/login"
    $hasLoginTitle = $login.Body -match '<title>\s*Log in - Papyrus\s*</title>'
    $loginNoError = $login.Body -notmatch 'Server Error|Whoops|Stack trace'

    Check 'the login page is served on the bound port' ($login.Status -eq 200 -and $hasLoginTitle -and $loginNoError) `
        "status $($login.Status), title=$hasLoginTitle, error page=$(-not $loginNoError)"
}

Section 'suwayomi'

$graphql = Get-Body "http://127.0.0.1:$suwayomiPort/api/graphql"
Say "  graphql  $($graphql.Status)"

$version = 'unknown'

try {
    $query = '{"query":"{ aboutServer { version } }"}'
    $result = Invoke-RestMethod -Uri "http://127.0.0.1:$suwayomiPort/api/graphql" -Method Post `
        -Body $query -ContentType 'application/json' -TimeoutSec 30
    $version = $result.data.aboutServer.version
} catch {
    $version = "query failed: $($_.Exception.Message)"
}

Say "  aboutServer.version: $version"
Check 'suwayomi answers graphql' ($version -match '^v?2\.4') "got $version"

# What the library is reachable from.
#
# Suwayomi's own default is `server.ip = "0.0.0.0"`, which it writes into server.conf itself, so a
# launcher that sets only the port is choosing the wildcard rather than declining to choose. Measured
# on a host install in that state: the listener was `:::4567`, and from another address on the
# network a login as the configured reader succeeded and `{ sources { totalCount } }` returned 223.
# The reader's whole library, over the local network.
#
# Checked here on the socket rather than on the configuration file, because a correct `server.ip` line
# and a server actually listening on it are different claims, and only one of them is the defect.
#
# `::1` and `127.0.0.1` are both loopback. `0.0.0.0`, `::` and any real interface address are not.
$suwayomiListeners = @(Get-NetTCPConnection -LocalPort $suwayomiPort -State Listen -ErrorAction SilentlyContinue)

if ($suwayomiListeners.Count -eq 0) {
    Check 'suwayomi is bound to loopback' $false "nothing is listening on $suwayomiPort"
} else {
    $addresses = @($suwayomiListeners | ForEach-Object { $_.LocalAddress } | Select-Object -Unique)
    Say "  suwayomi is listening on: $($addresses -join ', ')"
    $allLoopback = @($addresses | Where-Object { $_ -notin @('127.0.0.1', '::1') }).Count -eq 0
    Check 'suwayomi is bound to loopback' $allLoopback "addresses: $($addresses -join ', ')"
}

$appListeners = @(Get-NetTCPConnection -LocalPort $appPort -State Listen -ErrorAction SilentlyContinue)

if ($appPort -and $appListeners.Count -gt 0) {
    $appAddresses = @($appListeners | ForEach-Object { $_.LocalAddress } | Select-Object -Unique)
    $appLoopback = @($appAddresses | Where-Object { $_ -notin @('127.0.0.1', '::1') }).Count -eq 0
    Check 'papyrus is bound to loopback' $appLoopback "addresses: $($appAddresses -join ', ')"
}

# The install's own answer to the question this gate never asked it.
#
# The run of 2026-10-15 passed 58 of 58 checks over an install that could not read a single source,
# because no check ever asked the application to talk to its own manga server. This asks that
# question with the application's own command, through the application's own php and the ini the
# launcher resolved: it exits nonzero and names the reason when the app cannot reach Suwayomi, so a
# green line here is the app having read the server back. Its output is kept in
# C:\results\suwayomi-check.out beside the report.
#
# PAPYRUS_ENV is how the launcher points its children at the data directory's `.env`. Without it
# artisan finds no `.env` at all and queries the default port 4567 rather than the port this gate
# chose, which would fail a healthy install for the wrong reason.
#
# The whole command is one argument string. Written as separate pieces, @('-c', '"' + $ini + '"',
# '"' + $artisan + '"', 'suwayomi:check') binds as eight arguments with the quote characters
# standing alone between the paths, and Start-Process joins those with spaces, so php is handed
# ` C:\papyrus data\php\php.ini ` and reports "Could not open input file" (measured 2026-10-16,
# bundled php, paths containing spaces). The single string below reaches php verbatim, argv correct
# and the ini loaded.
$env:PAPYRUS_ENV = Join-Path $data '.env'

$check = Invoke-Captured $launcherPhp @('-c "' + $resolvedIni + '" "' + (Join-Path $root 'artisan') + '" suwayomi:check') 'suwayomi-check'

Check 'the install answers its own suwayomi:check' ($check.Code -eq 0) "exit $($check.Code)"
Check 'suwayomi:check read the server version' ($check.Out -match 'Server version.*2\.4') 'its output names no 2.4 server'

Section 'the reader path'

# The walk this gate never made, and the difference between an install that serves pages and one a
# reader can use.
#
# The same run that passed 58 checks showed the app's 500 page at /sources behind the login: every
# probe was green and no check had ever signed in and opened a page. This section makes the first
# account the way the browser makes it, from the register form, and then opens the page a reader
# opens, requiring it to render.
#
# The account is throwaway because the guest is: it never outlives the run. Creating it closes
# registration behind it on a single-reader install, which is that posture working rather than a
# problem. The password satisfies `Password::defaults()` in production (12 characters, mixed case,
# letters, numbers, symbols) because a packaged install carries no APP_ENV and so reads as
# production.
$password = 'Papyrus-gate-7!Kx'
$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$readerCreated = $false

$registerPage = Get-Body "http://127.0.0.1:$appPort/register" -session $session
Check 'the register page renders' ($registerPage.Status -eq 200) "got $($registerPage.Status)"

$registerToken = [regex]::Match($registerPage.Body, 'name="_token"\s+value="([^"]+)"').Groups[1].Value
Check 'the register form offered a csrf token' ($registerToken -ne '') 'no _token field was in the page'

if ($registerToken -ne '') {
    $created = Get-Body "http://127.0.0.1:$appPort/register" -method 'Post' -session $session -timeout 180 -form @{
        _token = $registerToken
        name = 'Sandbox Gate'
        email = 'sandbox-gate@example.test'
        password = $password
        password_confirmation = $password
    }

    $readerCreated = $created.Status -eq 200
    Check 'the register form creates the account' $readerCreated `
        "final status $($created.Status) after its redirects"

    $sources = Get-Body "http://127.0.0.1:$appPort/sources" -session $session

    # Three checks, split the way the /up checks are: the status, then the body being this app's
    # page, then the thing this run is here to prove. The eyebrow is the sources page's own
    # sentence and appears on no other page; the callout is the app's own words for "the catalogue
    # could not be read", so its absence is the app having read Suwayomi. A 500 page carries
    # neither.
    $hasEyebrow = $sources.Body -match 'Sources, on your terms'
    $hasErrorMarkers = $sources.Body -match 'Server Error|Whoops|Stack trace'
    $hasCallout = $sources.Body -match 'The extension list did not load'

    Check 'the sources page renders for the reader' ($sources.Status -eq 200) "got $($sources.Status)"
    Check 'the sources page is the app page, not an error page' ($hasEyebrow -and -not $hasErrorMarkers) `
        "eyebrow=$hasEyebrow error markers=$hasErrorMarkers"
    Check 'the sources page read its catalogue' ($hasEyebrow -and -not $hasCallout) `
        "eyebrow=$hasEyebrow failure callout=$hasCallout"
} else {
    Say '  no csrf token, so no account and no sources page: the reader path stops here'
}

# The catalogue page rendering is not a source read.
#
# Run 15 passed every check with an empty catalogue: `/sources` rendered its eyebrow because the
# page can render without a store, an extension or a chapter. WSL run 3 proved the reader path on
# Linux, which is a different PHP, JVM and trust store. This section adds a public community
# store's protobuf index, installs one English extension, mirrors the catalogue, fetches a manga
# and a chapter, and reads a page through the app's authenticated proxy. A page with an image
# content type and real bytes is the evidence; a 200 on `/sources` alone is not.
#
# The store URL is supplied by the host and is not part of the tree. The extension is public. The
# guest is disposable.
# The checks are ordered so a failure names the step that stopped the path.
$graphqlUrl = "http://127.0.0.1:$suwayomiPort/api/graphql"

function Get-Suwayomi([string]$query, [string]$label) {
    $body = @{ query = $query } | ConvertTo-Json -Compress
    $file = "C:\results\$label.json"

    try {
        $response = Invoke-WebRequest -Uri $graphqlUrl -Method Post -Body $body `
            -ContentType 'application/json' -UseBasicParsing -TimeoutSec 180
        [System.IO.File]::WriteAllText($file, [string]$response.Content)

        $decoded = $response.Content | ConvertFrom-Json
        $errors = ''

        if ($null -ne $decoded.errors) {
            $errors = (@($decoded.errors | ForEach-Object { $_.message }) -join '; ')
        }

        return @{
            Ok = ($errors -eq '')
            Errors = $errors
            Data = $decoded.data
        }
    } catch {
        $status = 0
        $text = $_.Exception.Message

        if ($_.Exception.Response) {
            $status = [int]$_.Exception.Response.StatusCode

            try {
                $text = (New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())).ReadToEnd()
            } catch {
                $text = '(unreadable body)'
            }
        }

        [System.IO.File]::WriteAllText($file, [string]$text)
        return @{ Ok = $false; Errors = "HTTP $status $text"; Data = $null }
    }
}

function Get-ProxyPage([string]$url, [string]$path) {
    if (Test-Path $path) { Remove-Item -Path $path -Force }

    try {
        $response = Invoke-WebRequest `
            -Uri $url `
            -WebSession $session -OutFile $path -UseBasicParsing -PassThru -TimeoutSec 120

        $type = [string]$response.Headers['Content-Type']
        $bytes = if (Test-Path $path) { (Get-Item $path).Length } else { 0 }

        return @{
            Status = [int]$response.StatusCode
            Type = $type
            Bytes = $bytes
            Ok = ([int]$response.StatusCode -eq 200 -and $type -match '^image/' -and $bytes -gt 1000)
        }
    } catch {
        $status = 0
        $type = ''

        if ($_.Exception.Response) {
            $status = [int]$_.Exception.Response.StatusCode
            $type = [string]$_.Exception.Response.ContentType
        }

        return @{ Status = $status; Type = $type; Bytes = 0; Ok = $false }
    }
}

# Mirror the reader's own URL construction.
#
# `fetchChapterPages` returns a root-relative /api/v1/... path, and
# `Gateway::resolvePageUrls()` moves it under the app's /suwayomi prefix. The path is not
# rebuilt from the manga and chapter ids, because Suwayomi addresses a page by the
# chapter's source order rather than by its id. Measured live: chapter id 3178 came back
# as /api/v1/manga/116/chapter/2/page/0, and the id-shaped URL 404s.
function Resolve-ProxyPageUrl([string]$serverPath) {
    $path = $serverPath -replace '^https?://[^/]+', ''
    $path = $path.TrimStart('/')
    $path = $path -replace '^api/v1/', ''

    return "http://127.0.0.1:$appPort/suwayomi/api/v1/$path"
}

function Invoke-SourceProbe {
    if (-not $readerCreated) {
        Say '  the reader account was not created, so the source path cannot be tested'
        Check 'a source page was read through the app proxy' $false 'no authenticated session'
        return
    }

    # The host writes this into the results mount before launching. The tree carries no store
    # URL, because the app must not name or ship one, and the gate still needs a real catalogue.
    $storeUrl = ''
    $storeUrlPath = 'C:\results\store-url.txt'

    if (Test-Path -LiteralPath $storeUrlPath) {
        $storeUrl = (Get-Content -LiteralPath $storeUrlPath -Raw -ErrorAction SilentlyContinue)
        if ($null -ne $storeUrl) { $storeUrl = $storeUrl.Trim() }
    }

    if ($storeUrl -eq '') {
        Check 'the install added the extension store' $false 'no store URL was supplied to the gate'
        Check 'a source page was read through the app proxy' $false 'no store URL was supplied'
        return
    }

    $artisan = Join-Path $root 'artisan'

    $store = Invoke-Captured $launcherPhp @(
        '-c "' + $resolvedIni + '" "' + $artisan + '" sources:sync --add-store=' + $storeUrl + ' --refresh'
    ) 'source-store'

    Check 'the install added the extension store' ($store.Code -eq 0) "exit $($store.Code)"

    if ($store.Code -ne 0) {
        Check 'a source page was read through the app proxy' $false 'the store did not load'
        return
    }

    Check 'the store refresh listed extensions' ($store.Out -match 'Store refresh.*extensions available') `
        'its output names no extension count'

    $extensions = Get-Suwayomi '{ extensions(first: 2000) { nodes { pkgName name lang isInstalled } } }' 'source-extensions'
    $extensionNodes = @()

    if ($extensions.Ok -and $null -ne $extensions.Data -and $null -ne $extensions.Data.extensions -and $null -ne $extensions.Data.extensions.nodes) {
        $extensionNodes = @($extensions.Data.extensions.nodes)
    }

    Say "  extensions listed: $($extensionNodes.Count)"
    Check 'the store listed an extension' ($extensionNodes.Count -gt 0) $extensions.Errors

    if ($extensionNodes.Count -eq 0) {
        Check 'a source page was read through the app proxy' $false 'the store listed no extensions'
        return
    }

    # MangaTaro is the extension the scratch WSL probe read through. The fallbacks keep the gate
    # useful when that package moves, but the check still names the package it chose.
    $wanted = 'eu.kanade.tachiyomi.extension.all.mangataro'
    $chosen = @($extensionNodes | Where-Object { $_.pkgName -eq $wanted }) | Select-Object -First 1

    if ($null -eq $chosen) {
        $chosen = @($extensionNodes | Where-Object { $_.lang -eq 'en' -and $_.pkgName -match 'mangataro|mangadex' }) | Select-Object -First 1
    }

    if ($null -eq $chosen) {
        $chosen = @($extensionNodes | Where-Object { $_.lang -eq 'en' }) | Select-Object -First 1
    }

    Check 'the store listed an English extension' ($null -ne $chosen) "wanted $wanted"

    if ($null -eq $chosen) {
        Check 'a source page was read through the app proxy' $false 'no English extension was listed'
        return
    }

    Say "  extension: $($chosen.pkgName) $($chosen.name)"
    $install = Get-Suwayomi ('mutation { updateExtension(input: {id: "' + $chosen.pkgName + '", patch: {install: true}}) { clientMutationId } }') 'source-install'
    Check 'the extension install request was accepted' $install.Ok $install.Errors

    $installed = $false
    $polls = 0

    for ($attempt = 0; $attempt -lt 60; $attempt++) {
        Start-Sleep -Seconds 2
        $polls = $attempt + 1
        $after = Get-Suwayomi '{ extensions(first: 2000) { nodes { pkgName isInstalled } } }' 'source-extensions-after'
        $node = @()

        if ($after.Ok -and $null -ne $after.Data -and $null -ne $after.Data.extensions -and $null -ne $after.Data.extensions.nodes) {
            $node = @($after.Data.extensions.nodes | Where-Object { $_.pkgName -eq $chosen.pkgName })
        }

        if ($node.Count -gt 0 -and $node[0].isInstalled -eq $true) {
            $installed = $true
            break
        }
    }

    Check 'the extension reports installed' $installed "package $($chosen.pkgName) after $polls polls"

    if (-not $installed) {
        Check 'a source page was read through the app proxy' $false 'the extension did not install'
        return
    }

    $sync = Invoke-Captured $launcherPhp @(
        '-c "' + $resolvedIni + '" "' + $artisan + '" sources:sync'
    ) 'source-sync'

    $mirrored = [regex]::Match($sync.Out, 'Sources mirrored\D*(\d+)')
    $mirroredCount = if ($mirrored.Success) { [int]$mirrored.Groups[1].Value } else { 0 }

    Check 'the app mirrored the installed source' ($sync.Code -eq 0 -and $mirroredCount -gt 0) `
        "exit $($sync.Code), mirrored $mirroredCount"

    if ($sync.Code -ne 0 -or $mirroredCount -eq 0) {
        Check 'a source page was read through the app proxy' $false 'no source was mirrored'
        return
    }

    $sources = Get-Suwayomi '{ sources { nodes { id name lang } } }' 'source-sources'
    $sourceNodes = @()

    if ($sources.Ok -and $null -ne $sources.Data -and $null -ne $sources.Data.sources -and $null -ne $sources.Data.sources.nodes) {
        $sourceNodes = @($sources.Data.sources.nodes)
    }

    $source = @($sourceNodes | Where-Object { $_.name -eq $chosen.name }) | Select-Object -First 1

    if ($null -eq $source) {
        $source = @($sourceNodes | Where-Object { $_.name -like '*MangaTaro*' }) | Select-Object -First 1
    }

    if ($null -eq $source) {
        $source = @($sourceNodes | Where-Object { $_.lang -eq 'en' }) | Select-Object -First 1
    }

    Check 'suwayomi listed a source for the installed extension' ($null -ne $source) "extension $($chosen.name)"

    if ($null -eq $source) {
        Check 'a source page was read through the app proxy' $false 'suwayomi listed no source'
        return
    }

    Say "  source: $($source.id) $($source.name)"

    $popular = Get-Suwayomi ('mutation { fetchSourceManga(input: {source: "' + $source.id + '", type: POPULAR, page: 1}) { mangas { id title } hasNextPage } }') 'source-popular'
    $mangas = @()

    if ($popular.Ok -and $null -ne $popular.Data -and $null -ne $popular.Data.fetchSourceManga -and $null -ne $popular.Data.fetchSourceManga.mangas) {
        $mangas = @($popular.Data.fetchSourceManga.mangas)
    }

    Say "  mangas listed: $($mangas.Count)"
    Check 'the source returned a manga' ($mangas.Count -gt 0) $popular.Errors

    if ($mangas.Count -eq 0) {
        Check 'a source page was read through the app proxy' $false 'the source returned no manga'
        return
    }

    $manga = @($mangas | Where-Object { $_.title -eq 'One Piece' }) | Select-Object -First 1

    if ($null -eq $manga) {
        $manga = $mangas[0]
    }

    $mangaId = [int]$manga.id
    Say "  manga: $mangaId $($manga.title)"

    $chapters = Get-Suwayomi ('mutation { fetchMangaAndChapters(input: {id: ' + $mangaId + ', fetchManga: true, fetchChapters: true}) { manga { id title } chapters { id name pageCount } } }') 'source-chapters'
    $chapterList = @()

    if ($chapters.Ok -and $null -ne $chapters.Data -and $null -ne $chapters.Data.fetchMangaAndChapters -and $null -ne $chapters.Data.fetchMangaAndChapters.chapters) {
        $chapterList = @($chapters.Data.fetchMangaAndChapters.chapters)
    }

    Check 'the manga listed a chapter' ($chapterList.Count -gt 0) `
        "chapters $($chapterList.Count), error $($chapters.Errors)"

    if ($chapterList.Count -eq 0) {
        Check 'a source page was read through the app proxy' $false 'the chapter list was empty'
        return
    }

    # `fetchChapterPages` is the only call that produces a real page count, and the only
    # one that downloads the images. The proxy does not fetch on its own: asking it for a
    # page from a chapter Suwayomi has never opened returns 404. Run 18 guessed chapter
    # indexes 1, 0, 2 and 3 and got four 404s for exactly that reason, while WSL's read
    # worked because its chapter had already been fetched.
    #
    # So pick a chapter, let Suwayomi fetch its pages, and then request the path the
    # mutation returned. That is the reader's own path, not a guess.
    $candidates = @()

    for ($index = 0; $index -lt [Math]::Min(3, $chapterList.Count); $index++) {
        $candidates += [pscustomobject]@{
            Id = [int]$chapterList[$index].id
            Name = [string]$chapterList[$index].name
            Pages = [int]$chapterList[$index].pageCount
        }
    }

    $chapter = $null
    $pagePaths = @()

    foreach ($candidate in $candidates) {
        $fetched = Get-Suwayomi ('mutation { fetchChapterPages(input: {chapterId: ' + $candidate.Id + '}) { chapter { id name pageCount } pages } }') 'source-pages'

        if (-not $fetched.Ok -or $null -eq $fetched.Data -or $null -eq $fetched.Data.fetchChapterPages) {
            continue
        }

        $paths = @($fetched.Data.fetchChapterPages.pages)

        if ($paths.Count -eq 0) {
            continue
        }

        $candidate.Pages = [int]($fetched.Data.fetchChapterPages.chapter.pageCount)
        $chapter = $candidate
        $pagePaths = $paths
        break
    }

    Check 'fetchChapterPages returned pages for a chapter' ($null -ne $chapter) `
        "tried chapter ids $((@($candidates | ForEach-Object { $_.Id })) -join ', ')"

    if ($null -eq $chapter) {
        Check 'a source page was read through the app proxy' $false 'no chapter pages were fetched'
        return
    }

    Say "  chapter: id $($chapter.Id) $($chapter.Name), $($chapter.Pages) pages"

    $attempts = [System.Collections.Generic.List[string]]::new()
    $attempts.Add('chapter_id,page_count,page_index,status,content_type,bytes,path')
    $page = $null
    $pagePath = ''
    $pageNumber = -1

    for ($index = 0; $index -lt [Math]::Min(3, $pagePaths.Count); $index++) {
        $serverPath = [string]$pagePaths[$index]
        $url = Resolve-ProxyPageUrl $serverPath
        $path = "C:\results\source-page-$index.bin"
        $result = Get-ProxyPage $url $path
        $attempts.Add("$($chapter.Id),$($chapter.Pages),$index,$($result.Status),$($result.Type),$($result.Bytes),$serverPath")

        if ($result.Ok) {
            $page = $result
            $pagePath = $path
            $pageNumber = $index
            break
        }
    }

    Set-Content -Path 'C:\results\source-pages.csv' -Value ($attempts -join [Environment]::NewLine) -Encoding UTF8
    Check 'a source page was read through the app proxy' ($null -ne $page) `
        'tried page paths returned by fetchChapterPages'

    if ($null -eq $page) {
        return
    }

    Check 'the source page answered 200' ($page.Status -eq 200) "got $($page.Status)"
    Check 'the source page is an image' ($page.Type -match '^image/') "got $($page.Type)"
    Check 'the source page has real bytes' ($page.Bytes -gt 1000) "got $($page.Bytes) bytes"

    $hash = (Get-FileHash -Path $pagePath -Algorithm SHA256).Hash
    Say "  page: chapter id $($chapter.Id) page $pageNumber, $($page.Status) $($page.Type) $($page.Bytes) bytes"
    Say "  page sha256: $hash"

    $sourcesAfter = Get-Body "http://127.0.0.1:$appPort/sources" -session $session
    Check 'the sources page renders after the catalogue is populated' ($sourcesAfter.Status -eq 200) `
        "got $($sourcesAfter.Status)"
    Check 'the sources page shows no failure callout' ($sourcesAfter.Body -notmatch 'The extension list did not load')

    $seriesPage = Get-Body "http://127.0.0.1:$appPort/series/$mangaId" -session $session
    Check 'the series page renders the fetched manga' ($seriesPage.Status -eq 200) "got $($seriesPage.Status)"
    Check 'the series page is the app page, not an error page' `
        ($seriesPage.Body -notmatch 'Server Error|Whoops|Stack trace')

    $summary = @(
        "store $storeUrl"
        "extension $($chosen.pkgName) $($chosen.name)"
        "source $($source.id) $($source.name)"
        "manga $mangaId $($manga.title)"
        "chapter list $($chapterList.Count), read chapter id $($chapter.Id) $($chapter.Name), $($chapter.Pages) pages"
        "page $pageNumber, $($page.Status) $($page.Type) $($page.Bytes) bytes"
        "sha256 $hash"
    ) -join [Environment]::NewLine

    Set-Content -Path 'C:\results\source-summary.txt' -Value $summary -Encoding UTF8
}

Section 'source access'

# An unexpected throw must become a failure rather than leaving the gate green by skipping the
# checks below. The functions above catch the requests they expect to fail; this catches the rest.
try {
    Invoke-SourceProbe
} catch {
    Say "  the source probe threw: $($_.Exception.Message)"
    Check 'the source probe ran to completion' $false $_.Exception.Message
}

Section 'the database is in the data directory, not the install'

$dataDb = Test-Path (Join-Path $data 'database.sqlite')
Check 'database created in the data directory' $dataDb

# The check above can be satisfied by a database at a path with no space in it, so it says
# nothing about the path a reader is on. This one cannot be satisfied by accident, and it is the
# difference between a gate that checks a spaced path and a gate that merely intends to.
Check 'the data directory path really does contain a space' ($data -like '* *') $data

# And the install root, which is the path Windows chooses by default and the last one in this
# system that had no space in it. Checked for the same reason: "intended to use a spaced path"
# and "used one" are different claims, and only the second is falsifiable.
Check 'the install root path really does contain a space' ($root -like '* *') $root

# And the .env the launcher wrote must be one dotenv can read back. Read with the same parser the
# application uses, because the failure this covers is "Encountered unexpected whitespace", which
# is reported by the parser and not by anything else.
$envText = Get-Content (Join-Path $data '.env') -Raw -ErrorAction SilentlyContinue

# Trimmed, because splitting on "`n" leaves the carriage return on the end of every line and `$`
# does not match before one. The first version of this check rejected
# DB_DATABASE='C:\papyrus data\database.sqlite', which is exactly right, and reported it as a
# defect: a check that fails on correct output is worse than no check, because it is believed.
$dbLine = ($envText -split "`n" | Where-Object { $_ -like 'DB_DATABASE=*' } | Select-Object -First 1)
$dbLine = if ($null -eq $dbLine) { $null } else { $dbLine.Trim() }

Check 'the .env quotes a database path containing a space' `
    ($null -ne $dbLine -and $dbLine -match "^DB_DATABASE='.+'$") $(if ($dbLine) { $dbLine } else { 'no DB_DATABASE line' })

$appUrlLine = ($envText -split "`n" | Where-Object { $_ -like 'APP_URL=*' } | Select-Object -First 1)
$appUrlLine = if ($null -eq $appUrlLine) { $null } else { $appUrlLine.Trim() }

Check 'the app URL matches the bound port' `
    ($null -ne $appUrlLine -and $appUrlLine.Contains("http://localhost:$appPort")) $(if ($appUrlLine) { $appUrlLine } else { 'no APP_URL line' })

$installDb = @(Get-ChildItem (Join-Path $root 'database') -Filter '*.sqlite' -ErrorAction SilentlyContinue)
Check 'no database written into the install' ($installDb.Count -eq 0) "found $($installDb.Count)"

# Suwayomi's own database, which is the one this gate never checked.
#
# For most of this project's life the launcher passed TACHIDESK_DIR, which Suwayomi does not read,
# so its data went to the Windows profile and the data directory held empty directories named after
# it. Every check above would have passed anyway: they are all about Laravel's SQLite file, and an
# empty `database/` directory is not a failure of anything they look at.
#
# A sandbox is the only environment that can prove the negative, because its profile starts empty.
# On a developer machine the profile already holds 52 MB from earlier runs, so "nothing appeared
# there" is only meaningful where there was nothing to begin with.
$suwayomiDb = Join-Path $data 'suwayomi\database.mv.db'
Check 'suwayomi database is in the data directory' (Test-Path $suwayomiDb) $suwayomiDb

$profileDb = Join-Path $env:LOCALAPPDATA 'Tachidesk\database.mv.db'
Check 'nothing was written to the windows profile' (-not (Test-Path $profileDb)) $profileDb

$suwayomiConf = Join-Path $data 'suwayomi\server.conf'
if (Test-Path $suwayomiConf) {
    $confPort = (Select-String -Path $suwayomiConf -Pattern '^server\.port\s*=\s*(\d+)' |
        Select-Object -First 1).Matches.Groups[1].Value
    Check 'suwayomi is serving on the port papyrus chose' ($confPort -eq "$suwayomiPort") "conf says $confPort, this gate asked for $suwayomiPort"
}

# Nothing may claim to hold data and hold nothing.
Check 'no empty directory named database' (-not (Test-Path (Join-Path $data 'database')))
Check 'no empty directory named exports' (-not (Test-Path (Join-Path $data 'exports')))

Section 'diagnostics'

# The command a reader is asked to run. Checked here because a reader who cannot produce a bundle
# cannot file a report at all, which makes it the failure that costs us the most information.
$diagnostics = & (Join-Path $root 'runtime\bin\php.exe') (Join-Path $root 'packaging\launcher\papyrus.php') diagnostics 2>&1
$diagnosticsExit = $LASTEXITCODE

Check 'papyrus diagnostics succeeds' ($diagnosticsExit -eq 0) "exit $diagnosticsExit"

$bundleDir = Join-Path $data 'diagnostics'
Check 'the bundle directory exists' (Test-Path $bundleDir) $bundleDir

foreach ($wanted in @('README.txt', 'summary.txt', 'health.txt', 'processes.txt', 'environment.txt')) {
    Check "the bundle has $wanted" (Test-Path (Join-Path $bundleDir $wanted))
}

$collected = @(Get-ChildItem (Join-Path $bundleDir 'collected') -Filter '*.txt' -ErrorAction SilentlyContinue)
$emptyCollected = @($collected | Where-Object { $_.Length -eq 0 })
$emptyNames = @($emptyCollected | ForEach-Object { $_.Name })
$withContent = @($collected | Where-Object { $_.Length -gt 0 })

# Counted and weighed. The count alone passed a bundle of nine files that were every one 0 KB, and
# an empty bundle is exactly what a reader sends when the app is broken, which is the case this
# bundle exists for. Existence is not content.
#
# Two of the nine are empty whenever the install is healthy, and it is the install being healthy
# that makes them empty. frankenphp.log is Caddy's log at level ERROR, so it says nothing when
# nothing errored, and php-error.log is PHP's error log, so it says nothing when nothing failed.
# Measured on 2026-10-15: the first run with every other check passing failed this one for those
# two files. Adding a name here is a claim that the file is silent in the healthy state, and the
# claim needs a reason like the two above.
$silentByDesign = @('frankenphp.txt', 'php.txt')
$unexpectedlyEmpty = @($emptyCollected | Where-Object { $_.Name -notin $silentByDesign } |
    ForEach-Object { $_.Name })
$emptyText = if ($emptyNames.Count -gt 0) { $emptyNames -join ', ' } else { 'none' }

$collectedDetail = if ($unexpectedlyEmpty.Count -gt 0) {
    "found $($collected.Count), $($withContent.Count) with content, unexpectedly empty: $($unexpectedlyEmpty -join ', ')"
} else {
    "found $($collected.Count), $($withContent.Count) with content, empty by design: $emptyText"
}

Check 'the bundle has collected logs' ($collected.Count -ge 5 -and $unexpectedlyEmpty.Count -eq 0) $collectedDetail

$suwayomiCollected = Join-Path $bundleDir 'collected\suwayomi.txt'
if (Test-Path $suwayomiCollected) {
    $suwayomiLines = @(Get-Content $suwayomiCollected | Where-Object { $_.Trim() -ne '' })
    $suwayomiSource = @(Get-Content (Join-Path $data 'logs\suwayomi.log') -ErrorAction SilentlyContinue |
        Where-Object { $_.Trim() -ne '' })

    # Both counts have to be real before the equality means anything: zero collected and zero in the
    # source is equal, so this passed on an empty bundle in the same words that mean a clean
    # collection of everything. The source is the thing being collected from, and it is never empty
    # in a running install.
    Check 'no line was lost collecting suwayomi output' `
        ($suwayomiSource.Count -gt 0 -and $suwayomiLines.Count -eq $suwayomiSource.Count) `
        "$($suwayomiLines.Count) collected, $($suwayomiSource.Count) in the source"
}

# Copied out of the guest, in the same run that made it.
#
# The bundle is what a reader sends when the app is broken, and it exists only inside a machine
# that is disposed of with the window. Copying it into the results folder, on the host side of the
# mapping, is what makes the gate's own bundle something anyone can look at afterwards. Cleared
# first so the counts below are this run's and not a previous run's, and copied rather than moved
# so the artifact stays where the checks above found it.
$resultsBundle = 'C:\results\diagnostics'

if (Test-Path $resultsBundle) { Remove-Item -Path $resultsBundle -Recurse -Force }

New-Item -ItemType Directory -Force -Path $resultsBundle | Out-Null
Copy-Item -Path (Join-Path $bundleDir '*') -Destination $resultsBundle -Recurse -Force

$inBundle = @(Get-ChildItem $bundleDir -Recurse -File -ErrorAction SilentlyContinue)
$outOfGuest = @(Get-ChildItem $resultsBundle -Recurse -File -ErrorAction SilentlyContinue)

Check 'the bundle was copied out of the guest' `
    ($inBundle.Count -ge 5 -and $outOfGuest.Count -eq $inBundle.Count) `
    "copied $($outOfGuest.Count) of $($inBundle.Count) files to C:\results\diagnostics"

Section 'https'

# Windows PHP has no default trust store, so this is the difference between an install that can
# reach a source and one that cannot.
$bundle = Join-Path $root 'runtime\cacert.pem'
Check 'ca bundle shipped' (Test-Path $bundle)

if (Test-Path $bundle) {
    try {
        $https = Invoke-WebRequest -Uri 'https://curl.se/ca/cacert.pem' -UseBasicParsing -TimeoutSec 30
        Check 'https works with the bundled trust store' ($https.StatusCode -eq 200) "got $($https.StatusCode)"
    } catch {
        # A failure here may be the sandbox's own lack of network, so it is reported as such
        # rather than counted as a product defect.
        Say "  https failed: $($_.Exception.Message)"
        Say '  this may be the sandbox having no network rather than the install being wrong'
    }
}

Section 'stopping'

$stopProcess = Start-Process -FilePath $launcherPhp -ArgumentList @('"' + $launcher + '"', 'stop') -NoNewWindow -Wait -PassThru `
    -RedirectStandardOutput 'C:\results\launcher-stop.log' -RedirectStandardError 'C:\results\launcher-stop.err'

Get-Content 'C:\results\launcher-stop.log' -ErrorAction SilentlyContinue | ForEach-Object { Say "    $_" }
Get-Content 'C:\results\launcher-stop.err' -ErrorAction SilentlyContinue | ForEach-Object { Say "    $_" }

Start-Sleep -Seconds 3

# Nulls are skipped rather than passed to the cmdlet, because `Get-NetTCPConnection -LocalPort $null`
# returns nothing and so looks exactly like a released port. That is how a run printed
# `[ok  ] port  released`, with the port name literally empty, and counted a check it never made.
foreach ($port in @($appPort, $suwayomiPort)) {
    if ($null -eq $port) {
        Say "  port $('<unknown>') was never reported, so there is nothing to check for"
        continue
    }

    $still = Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue
    Check "port $port released" ($null -eq $still)
}

# Scoped to this install's root, by process name alone.
#
# Checking for "java" or "frankenphp" anywhere on the machine reports a failure for a JVM the
# reader happened to have open. That is the same mistake this gate made once in the other
# direction: it checked a machine-wide thing and was satisfied by the wrong evidence.
# A clean sandbox has no other JVM, so this cannot hide a leak here, and it cannot cry wolf on a
# machine that happens to have one.
$survivors = @(Get-CimInstance Win32_Process -ErrorAction SilentlyContinue |
    Where-Object { $_.CommandLine -and $_.CommandLine.Contains($root) } |
    Where-Object {
        $_.CommandLine.Contains('queue:work') -or
        $_.CommandLine.Contains('schedule:work') -or
        $_.CommandLine.Contains('frankenphp') -or
        $_.CommandLine.Contains('.jar')
    })

Check 'no papyrus process survived the stop' ($survivors.Count -eq 0) "found $($survivors.Count)"

foreach ($survivor in $survivors) {
    Say "    left running: $($survivor.ProcessId) $($survivor.Name)"
}

# The stop command has to say something true.
#
# It used to print "Nothing was started by this session, so nothing was stopped" and exit 0 while
# all four processes kept running. That is a confident wrong answer rather than a failure, and
# checking only the exit code would have passed it: the command succeeded, and did nothing.
$stopOutput = Get-Content 'C:\results\launcher-stop.log' -Raw -ErrorAction SilentlyContinue
Check 'the stop did not claim there was nothing to stop' `
    ($stopOutput -notmatch 'Nothing was started by this session')

Section 'result'

if ($failures.Count -eq 0) {
    Say '  PASS, every check held'
    Save-Report
    exit 0
}

Say "  $($failures.Count) FAILED:"
$failures | ForEach-Object { Say "    - $_" }
Save-Report
exit 1
