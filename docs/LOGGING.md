# Unified logging: how a reader reports a broken install, and how we read it

Status: design, not implementation. Phase 0 shipped and verified. This is item 0.9.

The question this answers is narrow and practical. A reader's install breaks, they send us
something, and we have to reconstruct what happened from four processes that write to four places
in two formats with no shared clock and no shared timeline. Today that reconstruction is manual
and unreliable. This document says what we collect, how it is shaped, and what the reader sees
versus what they attach to a bug report.

## What exists today, measured

One full run on this machine, all four processes started and stopped:

| File | Bytes | Written by |
| --- | --- | --- |
| `suwayomi.log` | 15,512 | Suwayomi's stdout and stderr |
| `web.log` | 2,441 | FrankenPHP, JSON lines from Caddy |
| `migrate.log` | 2,240 | `artisan migrate`, captured |
| `configure.log` | 42 | `papyrus:configure`, captured |
| `php-error.log` | 198 | PHP's own `error_log` setting |
| `queue.log` | 0 | `artisan queue:work` |
| `scheduler.log` | 0 | `artisan schedule:work` |
| `frankenphp.log` | 0 | Caddy access log, `level ERROR` only |

Eight files, three formats, and no ordering between them. A reader who reports "the library
page is blank" gives us no way to know that the blank page happened at 20:14:38, between Suwayomi
starting a database pool and the web server binding its port.

Two of those files are **empty even on a healthy run**, which is worse than not having them:
`queue.log` and `scheduler.log` at 0 bytes tell us nothing about whether the worker started, and a
worker that fails silently looks exactly like a worker that is idle.

## Two defects found while investigating, before any design

Neither is a logging preference. Both change what we can collect at all.

### The application's own log goes inside the install, where it will be lost

`config/logging.php` writes to `storage_path('logs/laravel.log')`. Laravel resolves `storage_path`
from the application's base path, and **nothing in this codebase ever points it at the data
directory**. So the app writes to:

```
<install>/storage/logs/laravel.log
```

Three consequences, in increasing severity:

1. On a real install under `C:\Program Files`, that directory is **read only for a normal user**.
   The single log driver cannot create its file.
2. An uninstall deletes it. The evidence of what went wrong is removed by the act of removing the
   thing that went wrong.
3. The launcher's log directory is `<data>/logs`. The app's log is not in it, so anything that
   collects the data directory misses the one file a bug report most needs.

Meanwhile `InstallLayout` dutifully creates a complete storage tree **in the data directory**:

```
storage/app/private   storage/app/public   storage/framework/cache
storage/framework/sessions   storage/framework/views   storage/logs
```

Across a complete start, migrate, serve and stop, **0 files** were written to any of those six
directories. They are created on every install and used by nothing.

The fix is one line at boot, `useStoragePath()`, alongside the `useEnvironmentPath()` that
`bootstrap/app.php` already does for the `.env`. This is the same class of bug as that one, found
by the same method: assuming the framework resolves a path relative to where we want it.

### The build ships test artifacts

`storage/framework/testing/disks/local/covers/1/116.png` and a Livewire temporary upload, from the
test suite. The build's denylist names six specific paths under `storage/` and
`framework/testing` is not one of them, so a denylist that looks thorough has a hole the width of
"whatever else Laravel puts there".

The general shape is the problem: a list of paths someone remembered. Every other directory under
`storage/` and `database/` is one more entry to forget, and forgetting one puts developer state in
a reader's install.

## What each process gives us, and what we can ask of it

We control three of the four. That asymmetry decides the whole design, so it is worth stating
plainly rather than pretending we can normalise everything.

### Suwayomi: structured already, and noisy

Suwayomi is a Kotlin application using logback, writing to stdout, which we capture wholesale. It
emits lines of this shape:

```
20:14:37.587 [main] INFO suwayomi.tachidesk.server.util.AppMutex -- Mutex status is clear, Resuming startup.
20:14:38.025 [main] INFO com.zaxxer.hikari.HikariDataSource -- Suwayomi-DB-Pool - Starting...
```

Four useful properties: it has a real timestamp, a thread, an explicit level, and a logger name.
That is enough to interleave with everything else without inventing anything.

Interleaved with those are JVM warnings we do not control and cannot suppress:

```
WARNING: A restricted method in java.lang.System has been called
WARNING: java.lang.System::load has been called by com.sun.jna.Native in an unnamed module
```

Those are JNA noise about a restricted method, they appear on every single start, and they are not
errors. A reader who opens a log file and sees `WARNING` twice at the top will reasonably conclude
something is wrong. They must be classified and set aside rather than left to alarm anyone.

**We cannot change Suwayomi's logging.** No format flag, no level setting, no way to make it
quieter. Anything that depends on Suwayomi logging the way we want is depending on something we
do not have.

### FrankenPHP and Caddy: JSON, and deliberately almost empty

Caddy already emits JSON lines, which is the best of the four:

```json
{"level":"warn","ts":1791674331.52,"msg":"HTTP/2 skipped because it requires TLS","addr":":8402"}
```

The Caddyfile sets the access log to `level ERROR` only, deliberately: a reader clicking through a
library produces thousands of lines saying a page rendered, which buries the one line that
matters. That decision is right and this design keeps it. The consequence is that **`web.log` at
0 bytes is a good sign**, and that must be stated in the reader-facing summary, or it reads as a
failure.

### The queue worker and the scheduler: silent by design, which is the problem

Both write nothing on a healthy run. That is the correct behaviour for a worker with nothing to do
and the worst possible behaviour for diagnosis.

The failure mode is specific and nasty. A queue worker without `pdo_sqlite` does not crash. It
pops nothing and exits quietly. A scheduler without a database driver logs nothing at all. The
install looks perfectly healthy, `/ready` returns 200, and every download, search and library check
silently never happens.

This was not hypothetical. It is the exact shape of defect 12 and 13 from the sandbox gate, and a
reader who hit it would have had nothing at all to send us.

**The fix is not more logging, it is a liveness signal.** Something must say "I started, and I am
still alive" independently of whether there is work.

### `artisan` and PHP errors

`php-error.log` comes from the ini's `error_log` setting and captures things PHP writes outside
exceptions, including the "Unable to load dynamic library" warnings that were the visible symptom
of defect 1. It is small and it is one of the most valuable files in the set.

## The design

Three layers, because three different questions get asked.

### Layer 1: the reader-visible summary

One file, `logs/papyrus.log`, plain text, chronological, meant to be read by a person deciding
whether to file a bug. It contains **only** lines that mean something changed:

```
2026-10-10 20:14:35  papyrus   starting, version 0.1.0, install C:\Program Files\Papyrus
2026-10-10 20:14:35  papyrus   data directory C:\Users\<you>\AppData\Local\Papyrus
2026-10-10 20:14:36  suwayomi  starting on port 4567
2026-10-10 20:14:41  suwayomi  ready, v2.4.2366, database migrated
2026-10-10 20:14:42  papyrus   application key configured
2026-10-10 20:14:43  web       serving on http://127.0.0.1:8402, ready
2026-10-10 20:14:43  papyrus   ready at http://127.0.0.1:8402
2026-10-10 20:15:02  papyrus   stopped cleanly, all four processes
```

Rules, and each one is a decision:

- **Only state changes.** Startup milestones, errors, and stop. Not every request.
- **Timestamps to the second, no milliseconds.** A reader does not need more, and the detail lives
  in layer 2.
- **One line, one fact.** A line that says two things is a line we cannot grep.
- **The URLs and paths the reader can actually open.** A summary that names a path they do not have
  is worse than no summary.
- **Nothing is ever written here that is not explained.** No stack traces, no SQL, no tokens. This
  file is safe to screenshot.

The stop line matters more than it looks. "Stopped cleanly, all four processes" is the single most
useful line when a reader says it crashed, because it tells us whether the exit was orderly
without needing them to describe it.

### Layer 2: the collected diagnostics

Everything, in one place, machine-readable, safe to attach. One file per process under
`logs/collected/`, because a single interleaved file loses the ability to tell you which process
produced a line, and that attribution is most of what we use it for.

The unifying decision: **every line in every collected file gets the same five fields**, so the
files can be merged and sorted without a per-process parser.

```
2026-10-10T20:14:38.025+01:00  suwayomi  INFO   HikariDataSource  Suwayomi-DB-Pool - Starting...
2026-10-10T20:14:43.101+01:00  web       WARN   caddy.http         HTTP/2 skipped, requires TLS
2026-10-10T20:14:44.900+01:00  queue      INFO   worker             started, polling every 1s
```

`timestamp | process | level | source | message`

Five decisions worth defending:

- **ISO 8601 with an offset.** `20:14:38.025` cannot be correlated with a screenshot, and a
  timezone-free timestamp cannot be correlated with anything on a machine that has moved.
- **The process name is a field, not a filename.** It is why one file per process still merges.
- **The source is the logger name, or the Caddy logger, or the PHP file.** Within one process the
  source is what narrows a problem, and throwing it away to save a column is a bad trade.
- **Normalise Suwayomi's lines, do not rewrite them.** Its output is already good. We parse the
  prefix to fill the first four fields and keep its message verbatim. Rewriting someone else's log
  format is how you lose the one line they would have recognised.
- **Caddy's JSON is converted to the same shape** rather than left as JSON, so one `grep` finds
  everything.

### Layer 3: the health summary

The answer to "is it working", which is a different question from "what happened", and which is
what a reader asks first and what nothing currently answers.

```
$ papyrus health

  suwayomi    running    v2.4.2366   port 4567   library 0 series
  web         running    PHP 8.5.11   port 8402   /ready 200
  queue       running    idle        polling 1s  last job: never
  scheduler   running    idle                  last run: never
  database    ok         papyrus.sqlite 0.1 MB   migrations current
  ca bundle   ok         121 certificates
  disk        ok         4.2 GB free

  overall     ok
```

`papyrus health` is a command that changes nothing and answers in one screen. It exists because
"is it working" should never require reading a log file, and because a reader who can run one
command and paste the output has given us most of what we need before they write a word.

**The "last job" and "last run" fields are not decoration.** They are the liveness signal for the
two processes that are silent by design, and they are how we would have caught defect 12 and 13
from a bug report instead of from a sandbox.

## What a reader is asked to send

One file. Not "your logs", not a screenshot of a folder.

```
logs/diagnostics.zip
  summary.txt          the layer 1 file, human readable, safe to screenshot
  health.txt           the layer 3 output at the moment they ran it
  collected/*.txt      layer 2, every process, full detail
  environment.txt      Windows version, whether AV is on, whether the install path has spaces
```

Two things this excludes, both deliberate:

- **No reader data.** No library, no series names, no chapter list, no covers. Our own log says
  "library 0 series" and never what it contains. If the bug is about a specific series the reader
  tells us in the bug report, in their own words, and we ask for what we need.
- **No credentials.** The app key, session cookies, and any extension token are redacted at
  collection time rather than on request, because a redaction step that runs after collection is
  a redaction step someone will forget.

`environment.txt` is the one people forget and the one I would want most: whether Windows
Defender was running, and whether the install path contains a space. The second one has already
cost us a real defect, and the first is the answer to a whole class of "it just doesn't work"
reports that would otherwise be unauditable.

## Retention, and why it is bounded

Logs are the one thing in the install that grows without limit. A reader who reads for two years
should not fill their disk, and a support request should not require a 400MB download.

| File | Kept | Why |
| --- | --- | --- |
| `papyrus.log` | last 2 MB, rotated at 1 MB | A reader reads the last run, not the last year |
| `laravel-YYYY-MM-DD.log` | 14 daily files | The packaged install writes the app log through the daily stack |
| `web.log`, `queue.log`, `scheduler.log`, `suwayomi.log` | rotated above 5 MB, last 3 rotations | A process log is opened for append and would otherwise grow for the life of the install |
| `collected/*.txt` | last run only, replaced each start | A failed start is the interesting one; the run before it is not |
| `health.txt` | last run only | It is a snapshot, not a history |
| `diagnostics.zip` | built on demand, never automatic | It should cost nothing unless asked for |

The packaged install sets `LOG_STACK=daily`, `LOG_LEVEL=info` and `LOG_DAILY_DAYS=14` in
`papyrus:configure`, so a fresh install and an existing 1.5.3 `.env` both end up with the same
retention on their next launcher start. When debug logging is enabled, a Suwayomi GraphQL failure
is one curated record naming the operation, a whitespace-collapsed first message bounded to about
200 characters, and `elapsed_ms`. The raw upstream `errors` array is not written.

The launcher rotates a process log above 5 MB before it opens the file, so the child writes to the
new file, and it keeps the newest three rotations. A collected file above 10 MB is not loaded into
the diagnostics zip; the archive carries `<name>.truncated.txt` naming the excluded size so the
bundle never silently drops evidence. The zip writer still assembles the archive in memory, so the
cap bounds each member and a streaming rewrite remains a follow-up.

**Rotation is on size, not on date.** A reader who opens Papyrus once a month should not
accumulate a year of logs they will never read, and a reader in a debug loop should not be able to
fill a disk in an afternoon.

The tension with "collected logs survive a crash" is real: if the install does not start, there is
no opportunity to rotate. The answer is that the summary file is appended, never truncated, and
rotated at the size boundary only. A reader whose install will not start still has everything up
to the point it stopped.

## Sequencing, and what to verify

Ordered so each step is independently useful and independently checkable.

**Step 1, the defect fixes.** `useStoragePath()` for the application log, and `storage/framework/*`
added to the build's strip list. Neither is logging design; both are prerequisites, because
otherwise we are collecting logs from the wrong directory and shipping test artifacts in the same
breath. *Verified by:* the app's log appearing in the data directory, and a clean-room run with 0
files in the install's `storage/`.

**Step 1.** Both defects above, in `useStoragePath()` and the build's subtree rule. *Done.*

**Step 2, layer 1.** The summary file, written by the launcher as it goes. *Done.* Verified by a
startup and a clean stop producing the eight lines above, and a deliberate failure producing one
clear line rather than a stack trace.

**Step 3, layer 3.** `papyrus health`. *Done.* Verified against a live four-process install reading
`overall ok`, and by 10 of 11 mutations killed; the one survivor is inert, since removing the
`$started` fast path in `isRunning()` leaves the fallback answering the same question correctly.

**Step 4, layer 2.** The normalising collectors. *Done.* Nine sources, five parsers, one
five-field shape. Verified over logs captured from a real clean-room run rather than samples: four
defects were found only when real Suwayomi, Caddy and Laravel output met the parsers, including a
layout that separates logger from message with a space rather than `--`, and a physical line where
a JVM warning and a logback record are glued together. 17 mutations, 17 killed.

**Step 5, the bundle.** `papyrus diagnostics`, producing the directory and, with `--zip`, an
archive. *Done.* Verified by opening the archive in two independent implementations, one of them
.NET's, which is not ours and not the one our tests use. It contains no library, no database and no
environment variable, and it says so in its own README.

**Step 6, the clean machine.** The Windows Sandbox gate, which is the only check in this project
that runs where nothing is installed. *Done*, and it is where four further defects were found, all
of them invisible to steps 1 to 5 because all three had paths and ports in common that no reader
has. *Verified by* an install coming up at `C:\Program Files\Papyrus` with its data at
`C:\papyrus data`, Suwayomi answering on 4571, a diagnostics bundle with no line lost from 209
collected, and `stop` from a second process leaving both ports released and no process behind.
`docs/DESKTOP-PACKAGING.md` has the defect list and the gate's own operating instructions.

Each step is checkable before the next exists, which is the property that mattered during phase 0
and is worth preserving.

## What the sequence taught, which is worth more than the sequence

Every real defect in these five steps was found by **looking at what was produced** and comparing
it against the input, or by asking the machine a question whose answer was not assumed. None was
found by a test failing.

| what was claimed | what was true |
| --- | --- |
| the log directory is unwritable | `mkdir` succeeded, because the parent existed |
| the log directory is unwritable | Windows created `C:\no-such-drive-for-tests\` from nothing |
| rotation preserves `.1` | rotation overwrites `.1` with the current log |
| the database is under `<data>/database/` | it is directly under `<data>/` |
| both workers are dead | both were verifiably running |
| a healthy install has logs to collect | `queue.log` and `scheduler.log` are empty, and empty is healthy |
| a missing log is a quiet log | absence and silence are different facts, and only one of them is evidence |
| a reader's data can never be at `C:\papyrus-data` | `C:\Program Files\Papyrus` is where an installer puts it, and the space in it produced an install that would not boot |
| the gate runs on a realistic path | it ran at `C:\papyrus-data`, which has no space in it, for its whole existence |
| the gate proves the install starts anywhere | it asked for port 4567, so an install that worked nowhere else passed the check written to catch that |
| `stop` reports what it did | it printed "nothing to stop" and exited 0 with all four processes running |
| a `stop` that exits 0 worked | exit 0 is correct for "there was nothing" and for "I could not reach them", and the program cannot tell them apart |
| the build's require list is complete | the tests reach Supervisor through an autoloader and never through those requires |
| an empty stderr means something failed quietly | PHP writes a `-r` parse error to **stdout**, so the silence was never evidence of anything |

Each row is the same mistake in a different costume: a condition asserted rather than established.
That is the argument for keeping the mutation checks *and* for reading the output of every layer
before calling it done. Mutation checking proves the tests pin what was already suspected. Reading
the output finds what was not, and there were more of the second kind.

The last four rows have something in common that the earlier ones do not: **every local check was
green, and the green was the problem.** Not a flaky test or a wrong assertion, but three
independent checks sharing a blind spot, because they all ran on the same paths and the same ports.
A suite that agrees with itself is not evidence when the thing it is measuring is the environment.

The remedy is the same one that found the rest: make the harness state the condition it depends on,
and then check that it is true. The sandbox gate now asserts that its own install root and data
directory contain a space, because "intended to test the hard case" and "did" are different claims
and only the second is falsifiable.

### The failure mode a green suite structurally cannot catch

Worth separating out, because it is the one that cost the most here.

A probe that returns a confident wrong answer is more dangerous than a crash, because a crash stops
the work and a wrong answer does not. Four were written in the course of the packaging work and
three more during the logging work:

| the probe | what it actually measured |
| --- | --- |
| finding an install's processes | a PowerShell script that never ran, because PowerShell refuses a `-File` argument that does not end in `.ps1`, and whose stdout carried the resulting complaint |
| confirming a spaced path works | an extension directory that did not exist, so PHP correctly loaded nothing and the space was innocent |
| making an install read only | an `icacls` deny that removed execute as well as write, so `php.exe` could not start and a working install looked broken |
| measuring where PHP sends a parse error | a work directory whose own space made `-c` fail first, hiding what was being measured about `-r` |

The first is the instructive one. `tempnam()` returns a name with no extension, the script was
never executed, and the output that came back was PowerShell's startup banner beside its
complaint. That reads as a probe that ran and found nothing. It survived four wrong diagnoses,
because the output was never empty, and a plausible-looking result is much harder to distrust than
an obvious one.

The rule is short: **a matcher for a specific thing, and a matcher that matched nothing, produce
identical output when that thing is absent.** Every probe needs a positive control, and a control
that also came back empty tells you the probe is broken rather than the subject.

## Two defects this design depends on being fixed first

Both are in step 1 of the sequencing, and both were found while investigating, not before.

**The application log goes inside the install.** `storage_path()` is never pointed at the data
directory, so `laravel.log` lands in `<install>/storage/logs`. That directory is read only under
Program Files, it is destroyed by an uninstall, and it is not in the launcher's log directory, so
anything that collects logs misses the one file a bug report most needs. Meanwhile `InstallLayout`
creates six storage directories in the data directory that received **0 files** across a complete
run.

**The build ships test artifacts.** `storage/framework/testing/` holds a cover image and a
Livewire temporary upload from the test suite, because the strip list names six specific paths
under `storage/` and that is not one of them. The shape is the problem: a list of paths someone
remembered, where every other directory is one more entry to forget.

Neither is a preference. Collecting logs from the wrong directory, while shipping test artifacts
beside them, is not a design question.

## What this does not solve

- **Suwayomi's own logging stays out of our hands.** We can normalise it and classify its noise,
  and that is all. If Suwayomi needs a log level changed, we cannot change it.
- **A process that dies before its first line still leaves nothing.** This is why the summary file
  is written by the launcher before anything is spawned, and why "papyrus: starting" is its first
  line. It narrows the window; it does not close it.
- **Timing correlation is best effort.** Four processes on one machine share a clock, so ordering
  is reliable to about a second. Anything finer than that in layer 2 is millisecond precision from
  one process, not across them, and should not be read as if it were.
- **Nothing here detects problems we have not thought to log.** The health command answers the
  questions we wrote down. A failure mode nobody listed is still invisible, and the honest way to
  find those is a reader's report, which is why the bundle asks for their words as well as ours.
