# Packaging Papyrus for Windows

Status: phase 0 started. Items 0.2 and 0.4 are done and verified on a real Windows machine
against the real app, and the shipped configs live in `packaging/`. Distribution, signing,
platforms, backup and publishing are in [`DISTRIBUTION.md`](DISTRIBUTION.md). Written after
investigating this repository, this machine, and the upstream projects it would ship.

The question is whether a reader can install Papyrus on a Windows machine and have a working
manga reader without installing PHP, Java, or knowing what Suwayomi is.

## The finding that shapes everything else

**Papyrus is not the application. Suwayomi is.**

The library lives in `database.mv.db`, 25MB, under `%LOCALAPPDATA%\Tachidesk`. That file holds
867 series on this machine. Chapters, read state, extensions and downloads all live there.
Papyrus holds users, shelves, migrations, jobs and its own preferences, and contributes
almost no content of its own.

So "bundle everything" does not mean "wrap Papyrus in Electron". It means shipping **a JVM, a
174MB jar, and PHP**, and making three or four processes behave like one application. Electron
would add roughly 200MB of Chromium to render a page that the bundled web server is already
serving over localhost.

The honest framing: this is a **process supervision problem wearing an installer's clothes**.
The installer is the easy 10%. If phase 0 is skipped, every later phase inherits a mess.

## What was investigated, and what it found

| Question | Answer | Why it matters |
| --- | --- | --- |
| Does Papyrus ever write Suwayomi's SQLite? | **No.** Grep for `database.mv`, `Tachidesk`, `*.mv.db` finds only comments. Every access is GraphQL. | The two-database upgrade problem is far smaller than assumed. See bite 1. |
| How many runtime processes? | **Four.** Web server, `queue:work`, `schedule:work`, Suwayomi. `routes/console.php` registers a scheduled job and `.env.example` sets the database queue driver. | Not two. A launcher that forgets the scheduler quietly stops the library check. |
| Is there a readiness endpoint? | **No.** `/health` is a Livewire dashboard, not a probe. Suwayomi's readiness is a GraphQL `aboutServer` query. | Startup ordering needs something to poll. This has to be built. |
| Does the app assume a dev machine? | **No.** No absolute paths, no Herd dependency in code, only in comments. `storage_path()` and `database_path()` throughout. | The app is already portable. This is the good news. |
| What must an install generate? | `.env` is gitignored and holds `APP_KEY`. `vendor`, `public/build`, `public/storage` and `storage/*` are gitignored. | An installer is mostly *materialising gitignored things and generating one file*. |
| What PHP extensions are actually needed? | `pdo_sqlite`, `zip` (15 call sites), `mbstring` (24), `gd` or `imagick` (4), `bcmath` (1). No `curl`, `intl` or `openssl` call sites. | A modest set. A static build with these enabled is sufficient. |
| What does Suwayomi ship? | `windows-x64.msi` 340MB, `windows-x64.zip` 328MB, bare `jar` 174MB, plus a SHA256 file per release. MPL-2.0. | All three make this possible. See phase 1. |
| Is there one binary for PHP plus a web server? | **Yes.** FrankenPHP shipped native Windows binaries in March 2026, linking the official PHP builds, with worker mode and full extension support. Ships PHP 8.5. | Removes the nginx plus php-fpm plus config problem. |
| Are there really two Suwayomi processes on this machine? | **No.** Oracle's `javapath` shim (6MB, parent) plus the real JVM (722MB, owns port 4567). One instance. | A correction to an earlier claim, and it still teaches the right lesson. See bite 3. |

### A correction

An earlier note in this project described two `java.exe` processes running the same jar as a
duplicate instance problem. It is not. `C:\Program Files\Common Files\Oracle\Java\javapath\java.exe`
is a small launcher that spawns the real JVM and stays resident as its parent. The real JVM
is the 722MB one and it alone owns port 4567.

The lesson survives and sharpens: **the launcher shim is not the server, and it is not a
duplicate either.** Any readiness check that looks for a process name is wrong on this machine
today. Check the port, or a lockfile you own.

## Severity scale

Used throughout this document.

| | Meaning |
| --- | --- |
| **S** | Corrupts a reader's data, or blocks distribution to anyone but the author. |
| **H** | A reader hits it and sees a failure, or opens a support ticket about it. |
| **M** | Friction or risk that a deliberate design choice contains. |
| **L** | Polish. Real, deferrable. |

## Phase 0 progress

### Done and verified: 0.2, the FrankenPHP gate

This was the item the whole plan hinged on, because it decides whether the runtime is one
binary or three. It is resolved, in the app's favour, with evidence.

| Check | Result |
| --- | --- |
| FrankenPHP Windows binary | 1.13.0, ships PHP **8.5.11** ZTS |
| App requirement | `php ^8.3`, so 8.5.11 is in range |
| Full suite on 8.5.11 | **1,453 tests, 3,372 assertions, all passing** |
| Extensions needed by the app | all 12 load, zero warnings |
| App boots on the packaged ini | yes, `artisan about` reports PHP 8.5.11 |
| Serves the real app | `/login` returns a rendered 60KB Livewire page |
| Warm request | **55ms** |

The gap between what FrankenPHP's announcement claimed and what the Windows build does is
worth writing down, because it changed the design.

**Worker mode is not free, and it is not available for a plain Laravel app.** Getting there
took four wrong turns, each of which fails quietly enough to be worth recording:

1. `worker` is **not** a top level directive. It lives inside `php_server` and takes a
   filename. Written as a top level block it answers `unrecognized directive: worker`.
2. Inside `php_server`, `worker file <path>` fails with `strconv.ParseUint` on the path,
   because the token after `worker` is being read as a thread count.
3. With that filename correct, it resolves against the **process working directory**, not
   against the `root` set one line above. Given both in the same block it is natural to read
   the worker path as relative to the root, and the error is `GetFileAttributesEx index.php`,
   which names neither the real directory nor the real expectation.
4. With the path right, it still fails: `worker public/index.php has not reached
   frankenphp_handle_request()`. A Laravel worker script must call that function in a loop and
   does not.

The supported route is `laravel/octane` with `octane:install --server=frankenphp`. That adds a
dependency, a cache of application state held between requests, and a `--max-requests` policy
so a long lived process does not leak. **Classic mode was chosen instead**, and the measured
55ms warm request is what makes that defensible rather than merely convenient. One reader, one
machine, one page render. If a packaged install is ever measured too slow, Octane is the lever,
not a different web server.

Two smaller traps in the same config, both of which cost time and neither of which logged why:

- `auto_https off` **does not turn TLS off**. It stops the redirect and the listener still
  expects TLS, so every request answers `400 Client sent an HTTP request to an HTTPS server`
  with a clean config and an empty error log. The address needs an explicit `http://` prefix.
  That also keeps QUIC out, since h3 is always over TLS.
- `root * <path>` is Caddy module syntax. Using the wildcard inside a block whose directive is
  nginx-style changes the matcher and every request answers 400, again with nothing logged.

### Done and verified: 0.4, the readiness probe

`/ready` in `app/Http/Controllers/ReadinessController.php`, registered through
`withRouting(then:)` so it carries no middleware group.

It is not simply Laravel's `/up`, because `/up` answers one question, "is PHP serving", and a
packaged install needs three. A launcher has to know when to start the queue worker, when to
run migrations, and whether the install it just wrote is the one the reader is about to use.
`/up` returns 200 with no app key and no database, and both of those make every page after it
fail.

| Route | Answers |
| --- | --- |
| `/up` | is PHP serving at all |
| `/ready` | can this installation actually be used |

Two decisions in it that a reader would otherwise have to reverse-engineer.

**It runs outside the web middleware group, deliberately.** `SESSION_DRIVER=database`, which is
what `.env.example` ships, so every request through the web stack writes a session row. A
launcher polls readiness at least once a second, and a timer-driven write to SQLite from a
process whose job is to be idle is not a cost worth paying. There is a test asserting the
route gathers no middleware, and another asserting that polling it twice writes nothing, so
the comment cannot rot into a lie.

**It does not check Suwayomi.** Suwayomi being down is not this process being unready. The
launcher owns the Suwayomi lifecycle and polls its GraphQL `aboutServer` for that half. If
this route waited on Suwayomi too, a Suwayomi outage would make Papyrus look broken to
whatever was watching it, and the failure would be attributed to the wrong process.

It also answers only on loopback, reading `REMOTE_ADDR` rather than `$request->ip()`, because
`ip()` honours `X-Forwarded-For` for trusted proxies and a remote client behind a
misconfigured proxy could otherwise present itself as local and learn whether the app key is
set. There is a test for exactly that, sending the header and asserting 404.

### Done: the shipped configs

`packaging/php/php.ini` and `packaging/frankenphp/Caddyfile`, both carrying `{{TOKEN}}`
placeholders for the installer to substitute. Verified by substituting and serving: all 12
extensions load, `/ready`, `/up` and `/login` all answer 200, and the JSON access log at
`ERROR` level stays empty on a healthy run.

The php.ini exists because FrankenPHP's Windows archive ships `php.ini-development` and
`php.ini-production` and **no working ini at all**, on purpose. The extension list was derived
by grepping `app/` and `config/` for call sites rather than copied from a distribution default,
and each entry says which call site justified it.

Writing it also produced a bug worth recording, because the failure was nowhere near the
cause. The ini pointed `sys_temp_dir` at a directory the installer had not created, and the
first thing to use it was `artisan about`, which shells out. The error was a Symfony Process
complaint about not being able to open a temporary file, which reads as a bug in
`artisan about` and is not one. **The ini names three directories and the installer has to
create all three.**

### Done and verified: 0.3, the supervisor

`packaging/launcher/`, plain PHP with no dependency on the application. That is a requirement,
not a preference: a reader calls `stop` precisely when the application is broken, so anything
the launcher needs from the app it reads from files.

Five classes and three commands (`start`, `stop`, `status`). Proved end to end on this machine
against the real app: it adopted the Suwayomi already running on 4567 rather than starting a
second one, migrated, started the web server, the queue worker and the scheduler, got
`/ready` answering `{"status":"ready","checks":{"key":true,"database":true,
"pending_migrations":0}}`, then stopped all three cleanly and released the port.

Six real bugs were found by running it, and every one of them fails silently:

| Bug | What it looks like from outside |
| --- | --- |
| `proc_open` given the log file for **stdin** in mode `r` | Nothing starts, no log, no error. `r` needs the file to exist and on a first run it does not. |
| `artisan` omitted from the command | "Could not open input file: migrate", because PHP reads the first argument as a script path. |
| `phpExecutable` overridable but the ini path not | "could not find driver", four layers from the cause. |
| The Caddyfile path likewise not overridable | The server logs two lines about Go runtime tuning and exits, never reading a config. |
| Path separators not normalised when substituting the Caddyfile | Server starts and reports itself running, and every request 404s. The template joins with `/` for Linux and macOS, and a Windows `APP_ROOT` arrives with `\`. |
| `proc_open`'s environment argument **replaces** rather than merges | Passing only `PHPRC` hands a Windows process no `SystemRoot` and no `PATH`, and it dies before it can log. |

The last two are the ones worth keeping. Both were "add one thing" changes that were supposed
to be the most conservative available, and both produced a total failure in a direction that
pointed away from the change.

**And the one that is really the interesting finding: FrankenPHP does not read the ini that
`php.exe` was given.** It embeds its own PHP and searches beside its own executable, so the
server came up with no ini, loaded none of the twelve extensions the packaged one enables, and
answered every request with "could not find driver" while its log said it was running and
serving normally. The two halves of the install disagreed and nothing reported it:
`artisan migrate` through `php.exe` was perfectly happy. The fix is `PHPRC`, and the reason it
is worth writing down is that every symptom pointed at the database.

### Also learned, and required by the build

**A runtime needs the native libraries its extensions link against, beside the executable.**
`php_pdo_sqlite` needs libsqlite3, `php_curl` needs libcurl, `php_openssl` needs libssl and
libcrypto, `php_zip` needs libzip, `php_sodium` needs libsodium, and `frankenphp.exe` needs the
glib and ICU set. Copy only `ext/` and the runtime boots cleanly, reports its version, and
every one of those extensions fails to load with "the specified module could not be found",
which reads as a broken ini rather than a missing library. 24 shared libraries and 36
extension DLLs, in the smoke layout.

### Still to do in phase 0

0.5 `.env` generation, 0.6 Windows path and junction reality, 0.7 data location under
`%LOCALAPPDATA%`, 0.8 the launcher shell, 0.9 one log for four processes, 0.10 the clean
sandbox, 0.11 the Suwayomi runtime shape, 0.12 uninstall leaving data alone, and the
`VACUUM INTO` snapshot job that `DISTRIBUTION.md` puts at severe.

The goal is a folder that runs on a machine with nothing installed, launched by a script.
No installer, no shell, no registry. Everything after this phase is packaging; this phase is
the work.

**Exit gate: it runs in a clean Windows sandbox with nothing preinstalled.** Not "works on my
machine". A sandbox.

| # | Item | Sev | Notes |
| --- | --- | --- | --- |
| 0.1 | Artifact layout and build pipeline | M | One folder: `runtime/`, `php/`, `suwayomi/`, `app/`, `data/`, `logs/`. Everything the build must materialise is enumerated so a build script can materialise it: `composer install --no-dev`, `npm ci && npm run build`, `.env` generated, `public/storage` created. |
| 0.2 | Verify FrankenPHP on Windows with this app | H | It ships **PHP 8.5**; the app requires `^8.3`, so it is in range, but this app is developed on 8.4.25 and has never run on 8.5. Run the full suite against it before committing to it. Fallback is `php-cgi` plus a small Caddy or nginx config. |
| 0.3 | Process supervisor for four processes | **S** | The core of phase 0. Correct start order (Suwayomi first, it is slowest and everything depends on it), health gating, restart with backoff, and a single stop that leaves no orphans. Must launch by **absolute path**, never by `PATH` or process name. |
| 0.4 | Readiness probe | H | Nothing exists today. Add a cheap unauthenticated route that answers 200 when the app can serve, and poll Suwayomi's GraphQL `aboutServer` for the other half. |
| 0.5 | First-run `.env` generation | H | `APP_KEY` is per install and per machine. Also set `DB_DATABASE` to the absolute path in `data/`, and `SUWAYOMI_URL` to whatever the launcher resolved. Never ship a committed `.env`. |
| 0.6 | Windows path and permission reality | **H** | `public/storage` is a symlink in development. Windows needs a junction or a real directory. `MAX_PATH` will bite on deep `storage/app/private/livewire-tmp` paths, so put `data/` somewhere short, ideally `%LOCALAPPDATA%\Papyrus`. |
| 0.7 | Data location policy | **H** | **Never under `Program Files`.** It is read-only for a normal user, and every SQLite write fails. Data, logs and runtime state go in `%LOCALAPPDATA%`. |
| 0.8 | Launcher: tray, start, stop, open browser | M | 5 to 10MB Go or C# binary, or Tauri if a real window is wanted. Not Electron. A tray icon plus a browser is the honest shape for a localhost web app. |
| 0.9 | One place for four processes' logs | M | Today there is `storage/logs/laravel.log` plus Suwayomi's own. A reader who reports a bug cannot send four files from four locations. |
| 0.10 | Clean-sandbox verification | **S** | A fresh Windows VM, snapshot, install the folder, import a backup, read a chapter. This is the gate. |
| 0.11 | Decide the Suwayomi runtime shape | M | Their zip is 328MB and bundles a JRE, which may be a full JDK. The bare jar is 174MB. If `jlink` can produce a working minimal runtime, the download drops by roughly 100MB and the Java version is ours to pin. See bite 5. |
| 0.12 | Uninstall must not delete the library | **H** | An installer that removes a reader's 867 series on uninstall is the worst thing this project could ship. Data lives outside the install directory precisely so uninstall can delete only code. |

## Phase 0: the portable runtime

The goal is a folder that runs on a machine with nothing installed, launched by a script.
No installer, no shell, no registry. Everything after this phase is packaging; this phase is
the work.

**Exit gate: it runs in a clean Windows sandbox with nothing preinstalled.** Not "works on my
machine". A sandbox.

| # | Item | Sev | Notes |
| --- | --- | --- | --- |
| 0.1 | Artifact layout and build pipeline | M | One folder: `runtime/`, `data/`, `logs/`. `composer install --no-dev`, `npm ci && npm run build`, `.env` generated, templates substituted. **60 files minimum**: the executable, its extensions, and the 24 shared libraries they link against. |
| 0.2 | Verify FrankenPHP on Windows | H | **Done.** PHP 8.5.11, full suite green, serves the app. |
| 0.3 | Process supervisor | **S** | **Done.** Four processes, verified end to end. |
| 0.4 | Readiness probe | H | **Done.** `/ready`, outside the web group. |
| 0.5 | First-run `.env` generation | H | `APP_KEY` per install, absolute `DB_DATABASE`, resolved `SUWAYOMI_URL`. |
| 0.6 | Windows path reality | H | `public/storage` is a symlink in dev. `MAX_PATH` on deep `storage/app/private/livewire-tmp` paths. |
| 0.7 | Data location | **H** | Never under `Program Files`. `%LOCALAPPDATA%`. |
| 0.8 | Launcher shell | M | Go or C#, 5 to 10MB, or Tauri. Not Electron. |
| 0.9 | One log for four processes | M | Today four locations. A reader cannot send that. |
| 0.10 | Clean-sandbox verification | **S** | The gate. |
| 0.11 | Suwayomi runtime shape | M | **Answered by measurement, see below. Do not use their zip. |
| 0.12 | Uninstall leaves data alone | **H** | Data lives outside the install directory precisely so this is possible. |
| 0.13 | `VACUUM INTO` snapshot job | **S** | Added from `DISTRIBUTION.md`. The library is not in `data/`, but this side is, and a file copy of a live SQLite is a corrupt copy. |


### 0.11 answered: what is actually in Suwayomi's Windows zip

Measured with two HTTP range requests reading the zip's central directory, so nothing was
downloaded. The numbers are exact rather than inferred from the total size.

```
327.9 MB  Suwayomi-Server-v2.4.2366-windows-x64.zip, of which
   85.3 MB   electron/electron.exe and its assets, 196MB raw
  159.3 MB   bin/Suwayomi-Server.jar
   36.2 MB   jre/, 292 entries, java.exe present and javac.exe absent
   14.7 MB   Suwayomi-Launcher.jar
```

Two things follow, and one of them contradicts what this document assumed.

**Their bundled runtime is already a JRE, not a JDK.** `javac.exe` is absent, which is what
distinguishes them. So `jlink` is not the lever this plan assumed it was, and an earlier
version of the probe printed the opposite conclusion with the logic inverted. There is very
little to strip, because there was never a compiler in there.

**Do not use their zip.** 85.3 MB of it is an Electron build for Suwayomi's optional Electron
WebUI, which matches the `server.webUIInterface = BROWSER | ELECTRON` setting and which this
project would never launch. Shipping it would be shipping 85MB of Chromium we would throw away,
in a project whose entire reason for not using Electron is that Chromium is a liability.

So the runtime is the jar plus a JRE:

| | Size |
| --- | --- |
| `Suwayomi-Server.jar` | 159.3 MB compressed |
| a JRE, theirs or one we pick | 36.2 MB compressed |
| **total** | **~196 MB** |
| their whole zip | 327.9 MB |

**A 132MB saving, and it is also the cleaner arrangement**, because the launcher then owns
the Suwayomi lifecycle outright rather than sharing it with a launcher jar they wrote.

The one thing left to verify, and it is the gate: a JRE we ship has to be one Suwayomi
actually runs on. That is what the clean sandbox is for.

### The JRE, staged rather than assumed

Suwayomi's zip bundles a runtime, and reading its `jre/release` out of the zip with a single
HTTP range request says exactly what it is:

```
JAVA_VERSION="25.0.4"
```

So the version is not a guess and not a "recent LTS". It is 25.

**Picked: Eclipse Temurin JRE 25.0.4.1+1, Windows x64, 55.8MB.**

| | |
| --- | --- |
| Why Temurin | The distribution Suwayomi's own build is based on, and the one with the clearest redistribution terms |
| Why 25.0.4.1 and not 25.0.4 | It is the current patch of the version Suwayomi ships |
| Why not their bundled copy | It is inside an artifact that also carries 85MB of Electron, and pulling a JRE out of someone else's zip makes the licence question theirs rather than ours |
| Why not `jlink` | Their runtime is already a JRE with no `javac` in it, so there is nothing to strip |
| Verified | SHA256 matched the published checksum before staging; `javac.exe` absent, confirming a JRE and not a JDK |

Staged at `suwayomi/runtime/jre/`, which is the path the launcher already looks for, and
launched by absolute path. The clean-room run removes every Java directory from `PATH` before
starting anything, so a process that found its JVM by name rather than by absolute path would
fail there and nowhere else.

**A trap reproduced accidentally, which is the best kind.** The first scrub removed `PATH`
entries containing `jdk` or `jre`, and `where java` still answered
`C:\Program Files\Common Files\Oracle\Java\javapath\java.exe`, because that directory contains
neither string. It is the Oracle launcher shim from trap 217, and a filter written from memory
of what a Java path looks like is exactly the kind that misses it. The scrub now names it.

## Phase 1: acquiring Suwayomi

This is the direct answer to "can the app ask the user whether to download it and then install
it silently?" **Yes, and it is standard.** But the best answer is not a silent install.

### The two options, and which one to ship

**Option A, a genuine silent MSI install.** Suwayomi publishes `windows-x64.msi`. MSI silent
install is `msiexec /i <file> /qn /norestart`, and an administrative extract is
`msiexec /a <file> /qn TARGETDIR=<dir>`. This works today, no research needed.

**Option B, extract and launch, which is what should actually ship.** Take their zip, unpack
it into our own runtime folder, and start `java.exe -jar` ourselves.

| | A: silent MSI | B: extract and launch |
| --- | --- | --- |
| We control start and stop | No, it registers a service or a scheduled task | Yes, it is our child process |
| Uninstall | Removes a separately installed product | `rm -rf` the folder |
| Upgrading | MSI upgrade path, repair prompts, per-machine state | Swap a folder |
| Admin rights | Usually yes | No |
| Registry | Written | Untouched |
| Cost of failure | Leaves a half-registered install | Leaves a folder |

Option B wins on every row that matters for something we then have to support, and it is why
phase 0 has to work before phase 1 does. Ship B. Offer A only if a reader asks for Suwayomi to
appear in Apps and Features.

### The flow the user asked for

```
First run, no Suwayomi detected on 4567
    |
    +-- "Set up Suwayomi for me (recommended)"
    |     Fetch Checksums.sha256 from the release
    |     Verify the SHA256 of what arrived, and refuse a mismatch
    |     Download with progress, resume, and a cancel
    |     Extract into runtime/suwayomi/
    |     Write server.conf into the data dir
    |     Launch by absolute path, poll GraphQL until ready
    |     Report the chosen port in the UI
    |
    +-- "I already have Suwayomi"
          Probe 4567, then ask for a URL if it is not there
          Write SUWAYOMI_URL
          Credentials are optional and already supported
```

| # | Item | Sev | Notes |
| --- | --- | --- | --- |
| 1.1 | First-run choice with two clear options | H | The recommended path must be one click. The other must be reachable without reading a manual. |
| 1.2 | Download with progress, resume, cancel | H | 328MB is a long wait with no feedback and it is the first thing a reader sees. |
| 1.3 | Checksum verification, and refusing on mismatch | H | SHA256 is published per release. An unverified 328MB executable fetched over the network and run with local privileges is exactly the thing antivirus and users should refuse. **Refuse, do not warn.** |
| 1.4 | Extract and launch, per option B | H | Never by `PATH`. The `javapath` finding is the proof. |
| 1.5 | Attach to an existing Suwayomi | M | Detect on the port first, then ask. Credentials already exist in config. |
| 1.6 | Port conflict resolution | H | 4567 and an app port. Both must fail loudly, never silently pick the next free port, because `SUWAYOMI_URL` is already written into `.env` and a silent change orphans it. |
| 1.7 | Licence attribution | L | MPL-2.0 is fine to bundle. Ship the licence text and state that the bundle is unmodified. |
| 1.8 | Optional true MSI install | L | Only on request. See option A. |

## Phase 2: the Windows installer

| # | Item | Sev | Notes |
| --- | --- | --- | --- |
| 2.1 | Inno Setup shell | M | Around 1MB of tool, produces one exe. Silent flags `/VERYSILENT /SUPPRESSMSGBOXES /NORESTART` are well documented. |
| 2.2 | Shortcuts, Start Menu, file associations | M | Associating `.tachibk` and `.tmb` so a reader can double-click a backup is a small thing that makes the product feel finished. |
| 2.3 | Atomic upgrade | **S** | Run from `app/1.4.0/` and `app/1.5.0/` with a `current` pointer. Extract, run migrations, flip. Otherwise a running app can never be updated. See bite 4. |
| 2.4 | Single instance guard | H | Lockfile owned by the launcher. Recover stale locks by checking whether the pid is alive. Never by process name. |
| 2.5 | Firewall prompt | L | Binding to `127.0.0.1` only should avoid it. Worth verifying, because a prompt on first run is a bad first impression. |
| 2.6 | Auto-update | M | One artifact containing all three runtimes, not three update channels. See bite 6. |

## The five bites, investigated

### 1. Two databases and two migration systems

Originally assessed **S**. Investigation says **M**, and that is the most valuable finding in
this document.

Papyrus never writes Suwayomi's SQLite. It reaches Suwayomi only through GraphQL. Suwayomi
migrates its own schema on startup, and Papyrus migrates its own 34 migrations. **There is no
shared transaction and therefore no torn write.** The two can be upgraded in either order and
the only thing that can break is API compatibility.

That compatibility is already guarded: `suwayomi:schema:dump` writes a committed snapshot,
`suwayomi:check` compares it and reads `aboutServer { version buildTime }`, and the version is
read at runtime.

What is left is real but smaller:

- **M**: an upgrade must run Papyrus's migrations and Suwayomi's own, in that order, and the
  sequence must be recorded so a failure is diagnosable.
- **M**: `suwayomi:check` must run on every upgrade and refuse to continue on drift, rather
  than warning and letting a reader discover it as a broken query.
- **L**: the schema snapshot is generated per Suwayomi version, so packaging must decide
  whether to regenerate per release. That is a maintainer decision, not a user problem.

### 2. Defender and code signing

**H**, and the only item here that costs money to resolve.

A self-contained PHP binary, a 174MB jar and a launcher executable in a folder someone just
extracted is the exact shape SmartScreen flags. Two distinct problems:

- **Unsigned downloads.** SmartScreen shows a full-screen warning with no bypass for
  unreputable publishers. For a non-technical reader this reads as malware.
- **Runtime false positives.** SQLite, `java.exe` and a PHP binary all write patterns that
  heuristic scanners flag, especially in `%LOCALAPPDATA%`.

Mitigations in order of cost:

| | Action | Cost | Effect |
| --- | --- | --- | --- |
| 1 | Sign with an EV or standard code signing certificate | ~$100 to $400/year | Removes the SmartScreen warning after reputation accrues. **This is the gate for public distribution.** |
| 2 | Sign the jar as well, and check SHA256 before running | Free | Cuts the "downloaded and ran an unsigned exe" class entirely. |
| 3 | Install into `%LOCALAPPDATA%`, not `Program Files` | Free | Fewer heuristic hits than a program directory. |
| 4 | Publish checksums and a signed release manifest | Free | Lets a cautious reader verify without trusting us. |

For the author's own machine, none of this applies. It is the gate between "works for me" and
"distributable", so it belongs in the plan and not in phase 0.

### 3. Duplicate instances

**H**, but not for the reason first assumed.

The real Suwayomi instance on this machine shows the failure mode precisely: a 6MB launcher
shim and a 722MB JVM, both named `java.exe`, both with the same command line, one actual
server. A naive check for "is Suwayomi running" gives a false positive or a false negative
depending on which process it looks at.

Three rules follow:

1. **Never detect by process name.** Check the port, or a lockfile the launcher owns.
2. **Never launch via `PATH`.** Resolve `java.exe` inside our own runtime folder by absolute
   path. The `javapath` shim demonstrates that whatever is on `PATH` may not be what we think,
   and on a clean machine it may be nothing at all.
3. **A stale lock is normal after a crash.** Store pid plus start time, and treat a lock whose
   pid is dead or whose start time no longer matches as stale. A lock that only stores a pid
   eventually belongs to someone else.

### 4. Upgrading a running application

**S**, because it is where installers actually break, and the failure is silent.

An installed, running app holds an open SQLite database, a running JVM and a bound port. You
cannot overwrite any of them. The options are "close it first", which is hostile, or make the
upgrade not require touching the running version.

The versioned-directory approach:

```
data/                      outside the install, survives upgrades
app/
  1.4.0/                   current version, running
  1.5.0/                   staged by the updater
  current -> 1.5.0         a pointer, flipped after migrations succeed
```

Order matters and is the whole point:

1. Launcher starts, reads `current`.
2. Updater stages the new version beside the old one. Nothing running is touched.
3. Ask the app to quiesce: stop the queue worker and scheduler, drain, close SQLite cleanly.
4. Run migrations against `data/`. **Migrations can be destructive, so back up first** and
   that backup is a reader's library.
5. Flip `current`.
6. Start the new version. If it fails to come up, flip back and restore.

Step 4 is the one that gets skipped and it is the one that matters. **Back up `data/` before
every migration**, and keep the previous version directory until the new one has been healthy
long enough to trust.

### 5. jlink and the Java runtime

**M**. Not urgent, but it is 100MB and it is the kind of saving that justifies doing it early.

The measured options, from the actual v2.4.2366 release:

| Artifact | Size | Contains |
| --- | --- | --- |
| `Suwayomi-Server-v2.4.2366.jar` | 174.5MB | The server. Needs a JRE. |
| `Suwayomi-Server-v2.4.2366-windows-x64.zip` | 327.9MB | Server plus a bundled runtime. Self-contained. |
| `Suwayomi-Server-v2.4.2366-windows-x64.msi` | 340.3MB | An installer of the above. |

The zip is 154MB larger than the jar, which is more than a minimal JRE should cost and
suggests a full JDK is bundled. **That is a hypothesis to verify in phase 0**, by extracting
the zip and looking at the `runtime` directory, not an established fact.

If it is a full JDK, `jlink` can build a minimal runtime containing only the modules Suwayomi
needs, which typically lands near 50MB. That would make the download roughly 174 + 50 = 224MB
against 328MB, and pin the Java version ourselves.

The risk is asymmetric and worth stating: a missing module fails at startup with a
`NoClassDefFoundError`, which is a bad first-run experience. So the rule is that the linked
runtime must be verified by **running the full phase 0 sandbox test against it** before it
becomes the shipped path. Keep the zip as the fallback.

`jlink` needs a JDK at build time. This machine has 26, so it is available, though the runtime
should be linked from the same major version Suwayomi targets.

## What this phase list does not cover

Distribution, signing, platforms, backup and publishing are in
[`DISTRIBUTION.md`](DISTRIBUTION.md). Three findings from that document change this plan:

- **The backup plan is wrong as first written.** "Back up `data/`" does not contain the
  reader's library, which is H2 inside a running JVM that holds an exclusive lock on the file.
  Suwayomi already takes a daily `.tachibk` snapshot with 14 day retention, and this project can
  now read those with the reader built for the Mihon import. See that document.
- **Multi-user Windows accounts collide on ports**, because data is per account and both
  instances want 4567. Phase 1.6 goes from medium to high.
- **macOS notarisation is not optional** and costs $99 a year, which is a decision rather than a
  task.