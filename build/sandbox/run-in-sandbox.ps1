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

    $out = if (Test-Path $outFile) { [string](Get-Content $outFile -Raw) } else { '' }
    $err = if (Test-Path $errFile) { [string](Get-Content $errFile -Raw) } else { '' }

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

# Started detached so this script keeps control and can keep reporting. The launcher outlives this
# script by design, since it leaves four processes running that the probe section then exercises.
#
# Quoted because the install root now has a space in it, and Start-Process joins an argument list
# with spaces without quoting. Unquoted, PHP receives "C:\Program" and "Files\Papyrus\...\papyrus.php"
# and reports "Could not open input file: Files\...", which names a file that does not exist and
# reads as a broken install. The same applies to the stop call below.
$startProcess = Start-Process -FilePath $launcherPhp -ArgumentList @('"' + $launcher + '"', 'start') -NoNewWindow -PassThru `
    -RedirectStandardOutput $startLog -RedirectStandardError 'C:\results\launcher-start.err'

Say "  launcher started, pid $($startProcess.Id), watching its output"

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
} else {
    Say "  launcher exited with code $($startProcess.ExitCode)"

    foreach ($file in @($startLog, 'C:\results\launcher-start.err')) {
        if (Test-Path $file) {
            $lines = @(Get-Content $file)

            if ($lines.Count -gt $seen) {
                $lines[$seen..($lines.Count - 1)] | ForEach-Object { Say "    $_" }
            }
        }
    }

    Check 'the launcher exited cleanly' ($startProcess.ExitCode -eq 0) "exit $($startProcess.ExitCode)"
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
    # The `-c` path is quoted separately, because the data directory now contains a space and
    # Start-Process joins -ArgumentList with spaces without quoting. Unquoted, PHP receives
    # "C:\papyrus" and "data\php\php.ini" as two arguments and reports "Could not open input
    # file: data\php\php.ini", which names a file that does not exist and reads as a broken
    # install. This gate ran clean for its whole life only because C:\papyrus-data had no space
    # in it.
    $version = Invoke-Captured $launcherPhp @('-c', '"' + $resolvedIni + '"', '-r', '"echo PHP_VERSION;"') 'php-resolved-ini'
    Say "  bundled PHP with the launcher's resolved ini: $($version.Out.Trim())"
    Check 'the launchers resolved ini loads cleanly' ($version.Code -eq 0) "exit $($version.Code)"
} else {
    Check 'the launcher resolved an ini' $false "no file at $resolvedIni"
}

Section 'probing'

function Get-Body([string]$url) {
    try {
        $response = Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 30
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

    # A rendered page, not merely a 200. An app that returns its own error page is answering.
    Check '/up returns html' ($up.Body -match '<!DOCTYPE html>|<html')
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
Check 'the bundle has collected logs' ($collected.Count -ge 5) "found $($collected.Count)"

$suwayomiCollected = Join-Path $bundleDir 'collected\suwayomi.txt'
if (Test-Path $suwayomiCollected) {
    $suwayomiLines = @(Get-Content $suwayomiCollected | Where-Object { $_.Trim() -ne '' })
    $suwayomiSource = @(Get-Content (Join-Path $data 'logs\suwayomi.log') -ErrorAction SilentlyContinue |
        Where-Object { $_.Trim() -ne '' })
    Check 'no line was lost collecting suwayomi output' ($suwayomiLines.Count -eq $suwayomiSource.Count) "$($suwayomiLines.Count) collected, $($suwayomiSource.Count) in the source"
}

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
