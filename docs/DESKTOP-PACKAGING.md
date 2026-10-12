# Packaging Papyrus for Windows

Status: **phase 0 complete and verified.** A 622.8MB install builds from one command and was run
end to end in a Windows Sandbox on a machine with no PHP, no Java and no C++ runtime. Thirteen
defects were found and fixed getting there; the last three existed only off the development
machine. Item 0.9, the unified logging that makes any of this diagnosable, is designed in
[`LOGGING.md`](LOGGING.md). Distribution, signing, platforms, backup and publishing are in
[`DISTRIBUTION.md`](DISTRIBUTION.md). Written after investigating this repository, this machine,
and the upstream projects it would ship.

The shipped configs live in `packaging/`, and the two build steps are:

```
php packaging/runtime/stage-runtime.php      the pinned JRE and the CA bundle
php packaging/runtime/build-install.php --frankenphp=<zip> --jar=<jar> --into=<dir>
```

`build-install.php` refuses to finish on a broken install. Beyond checking that files exist, it
lays the install out using the launcher's own code, boots the application with the launcher's own
resolved `php.ini`, asks PHP which ini it *actually loaded* rather than which one it was handed,
confirms `papyrus:configure` produced an `.env` with a key, and runs the staged `php.exe` from a
directory outside the install to prove it is self sufficient. Each of those is behavioural, and a
file listing cannot make any of them.

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

### Done: the sandbox gate, and the three defects only it could find

Phase 0 is finished. The install builds from one command and runs on a machine with nothing
installed. Getting there took thirteen defects, and the last three are the reason this section
exists, because **every check on the development machine passed while all three were present**:

| | Defect | Why nothing local caught it |
| --- | --- | --- |
| 11 | The Visual C++ runtime was never staged. `php.exe` is a 200KB shim that links `vcruntime140.dll`; the FrankenPHP archive ships 24 DLLs, all PHP and OpenSSL, and no `vcruntime*`. | Windows keeps them in System32 on every development machine |
| 12 | `papyrus.php` required five of the seven classes `Supervisor` constructs. `InstallLayout` and `LayoutRefused` were missing, so the first line of any start was a fatal. | Both the test suite and the clean-room probe `require` all seven explicitly, so neither can see the entry point forgot |
| 13 | `InstallLayout::comparable()` used `mb_strtolower`. The launcher runs under a bare `php.exe` with no ini, so mbstring is not loaded and the function does not exist. | Herd's `php.ini` loads mbstring |

The symptoms in a clean guest were, in order: `exit code 0xC0000135` (`STATUS_DLL_NOT_FOUND`) with
no output at all, then `Class "Papyrus\Launcher\InstallLayout" not found`, then `Call to undefined
function mb_strtolower()`. Each one is a fatal in a different component, and each one is invisible
from a machine that supplies the missing piece.

Two of them are the launcher's own design constraint being violated. The launcher must keep
working when the application does not, so it can assume **no extension at all**, including the ones
the application itself relies on. `mb_strtolower` was the only such use; the app's own uses are
fine, because the app always runs with the resolved ini.

Changed to `strtolower`, which is the better call independently: the comparison is between two
filesystem paths on one machine, so only ASCII case can differ, and a false refusal here is worse
than a missed one.

### Done: four more defects, found the same way

All four were present while the gate, the suite and the clean-room probe were green. They share one
cause: each is invisible from the machine that was missing the condition, and none of the three
local checks supplied it.

| | Defect | Why nothing local caught it |
| --- | --- | --- |
| 14 | `stop` could not stop anything. It needs a `proc_open` handle it created itself, so a `stop` from another process found an empty list, printed "Nothing was started by this session, so nothing was stopped" and exited **0** | every local test called `stopAll()` inside the process that had started them, which is the only case that worked |
| 15 | `ProcessFinder` missing from the build's require list, so a build would have fataled in `Supervisor`'s constructor | the tests reach Supervisor through Composer's autoloader and never through those hand-written requires |
| 16 | A data directory whose path contains a space produced an unbootable install: the `.env` `DB_DATABASE` was written unquoted and dotenv refused to load it | nothing in this project has a space in its paths, including this repository's own root |
| 17 | The stop matcher keyed on the install root, so two installs of one install would have killed each other's workers | two launchers sharing a root is a supported configuration and nothing tested it |
| 18 | `Papyrus.cmd` shipped with bare LF line endings, and `cmd.exe` ran it and printed nothing | the file was written and the process it starts ran, so every check that asked "did it start" said yes; nothing asked whether a human could read the output |
| 19 | The generated `.env` carries `SUWAYOMI_USERNAME=''`, so an install could not read anything from a Suwayomi that requires a login, and nothing said so | every check that exists asks Suwayomi `aboutServer`, which is not behind a login, so `health` printed six green lines over an install that refused every query the app made |

**18 and 19 are both "it worked, just not for the reader".** Neither crashes and neither stops
anything from starting, which is exactly why both survived.

18 is the sharper one. `cmd.exe` parses batch files by CRLF; with bare LF it still launches the
command and loses the output, so the one file a reader ever double-clicks opened a console that
filled with progress they could not read. It was found by redirecting that file's output to a file
and measuring it: 0 bytes, while running the same launcher directly produced 610 bytes in ten
seconds. `build-install.php` now normalises the endings and fails the build if any bare LF survives,
because the source can come back with LF from an editor, a checkout setting, or another platform.

19 is the one that reached a reader. The launcher knows Suwayomi's URL and port and has no way to
know a password, so it writes the URL and leaves the credentials as the empty strings
`.env.example` ships. Against `AuthMode.UI_LOGIN` every query the application makes is refused, so
every series reads "Not on the server" and no cover or chapter count can be fetched. The library is
intact and fully visible throughout, which is what made it look like data loss rather than a
configuration gap.

It stayed invisible because the one function that talks to Suwayomi at startup asks `aboutServer`,
and `aboutServer` carries no `@RequireAuth`. `suwayomiAnswers()` returned true, `/ready` answered
200, and `papyrus health` was green. A check that only exercises the one endpoint which never needed
credentials cannot see this, and there were three of them.

The launcher now probes `{ sources { totalCount } }`, which is behind the login, and refuses to
start when it is refused and no credentials are configured, naming the file to edit. It never
prompts: PHP on Windows cannot mask console input without a dependency, so a prompt would put the
password on screen and in scrollback. It also refuses **only** when all three of those hold, so an
inconclusive probe starts the install rather than stopping a working one.

**14 is the one worth dwelling on**, because it is the most consequential kind of wrong: not a
crash but a confident success. `stop` is documented as the command a reader runs when the
application is too broken to explain itself, and as one of the three a phase 2 tray icon calls. A
reader who ran it was told everything was fine while all four processes kept running. It exits 0 in
both cases, because "there was nothing to stop" and "I could not reach the processes" are the same
answer to a program that has no way to tell them apart.

The gate found it by starting an install, running `stop` from a second process, and finding both
ports still held and the JVM still alive. It now also asserts that the stop did **not** print the
sentence that reports having found nothing, because checking only the exit code proves nothing: that
exit code was correct for both outcomes.

**15 is defect 12 again, one layer out.** `Supervisor`'s constructor builds a `ProcessFinder`, so
it is a dependency of merely constructing one rather than only of calling `stopAll`. `papyrus.php`
was updated when the class was added and `packaging/runtime/build-install.php` was not, because it
names its dependencies by hand. A hand-maintained list is a second dependency graph, and it had no
check on it at all; there is one now.

**16 is the one this repository was uniquely unable to see**, and it is worth stating plainly: this
project's own working directory is `C:\Users\<you>\Php Projects`. Every test used a system temp
directory, the clean-room probe used a path with no space, and the gate used `C:\papyrus-data`. The
unit tests are not wrong and the design is not wrong; the paths simply were never the paths anyone
is on.

**17 follows from where the lock lives.** Two launchers sharing an install root with different data
directories do not exclude each other, because the lock is in the data directory. The matcher now
keys on the data directory, which cannot collide that way, and which all four processes already
carry on their own command line: the workers through `-c <data>/php/php.ini`, the web server through
`--config <data>/frankenphp/Caddyfile`, and Suwayomi through its `rootDir` property. That was
measured on four real processes rather than argued.

### Running the gate

Windows Sandbox will not start a second guest while one is open, and the message goes to a window
nobody is watching. Close the window and check for `vmmemWindowsSandbox` before launching again
rather than trusting the clock.

Three things about it fail silently, and each is recorded in `build/sandbox/papyrus.wsb` so it does
not have to be rediscovered:

- `build/sandbox-results` must exist on the host before launching. A missing mapped folder is never
  created, the guest closes with no error, and it looks exactly like a slow boot.
- The runner must not be edited while a guest is booting. It is read from the mapped folder at
  logon, so editing or committing it in the first minute of a run means the guest executes a
  half-written file.
- Every path passed to `Start-Process` has to be quoted. This is defect 16's mechanism one level
  down, and it appeared four times in a single afternoon, each time as a confident wrong answer.

**What the gate actually asserts**, from `build/sandbox/run-in-sandbox.ps1`:

```
no java on PATH, no php on PATH, no Java installed
all required files present, exactly one suwayomi jar
no database, no .env and no logs shipped
bundled php.exe runs standalone                      PHP 8.5.11 ZTS Visual C++ 2022 x64
13 directories created, Suwayomi answering
database migrated, no database written into the install
the app reports ready
queue worker and scheduler started
Papyrus is running at http://127.0.0.1:<port>
suwayomi answers graphql                             v2.4.2366
https works with the bundled trust store            200
papyrus diagnostics writes its five files and collects its logs
no line lost collecting suwayomi output              209 collected, 209 in the source
all four processes stopped, both ports released
the stop did not claim there was nothing to stop
```

An empty library in the sandbox is the **correct** result, not a symptom. No database and no
extensions ship, so a clean machine shows an empty library by design.

**The gate runs at the paths a reader gets.** It installs to `C:\Program Files\Papyrus` with its data
at `C:\papyrus data`, and asks for Suwayomi on 4571 rather than 4567. That is not decoration, and it
is worth saying why, because the gate was green on every one of these for its whole existence:

| What the gate used | What a reader has | What that hid |
| --- | --- | --- |
| `C:\papyrus` | `C:\Program Files\Papyrus` | a space in the Caddyfile document root, `extension_dir`, the artisan path and the jar path |
| `C:\papyrus-data` | `...\Programs\Papyrus\data` | an `.env` whose `DB_DATABASE` could not be quoted, so dotenv refused to load it and the whole application was dead |
| port 4567 | whatever is free | an install that could not start on any port but the default, passing the check written to catch exactly that |

Each row is a condition asserted rather than established. The rule that follows is now checked by
the gate about itself, because "intended to test the hard case" and "did" are different claims and
only the second one is falsifiable:

```
the data directory path really does contain a space     C:\papyrus data
the install root path really does contain a space        C:\Program Files\Papyrus
the .env quotes a database path containing a space       DB_DATABASE='C:\papyrus data\database.sqlite'
```

Moving the gate onto those paths immediately broke **two of its own checks**, which is the argument
for doing it. The ini probe passed its path to `Start-Process` unquoted, and PowerShell joins an
argument list with spaces without quoting, so PHP received `C:\papyrus` and `data\php\php.ini` as
two arguments and reported `Could not open input file: data\php\php.ini`, naming a file that does not
exist. And the `.env` check split on a newline, leaving a carriage return on each line, so `$` never
matched and it rejected `DB_DATABASE='C:\papyrus data\database.sqlite'`, which is exactly right. A
check that fails on correct output is worse than no check, because it is believed.

**What this gate cost to get right, and why it is worth the trouble.** Four separate probes wrote
a confident and wrong answer during phase 0, each reading something other than what it measured: a
clean-room run that substituted the `php.ini` it was meant to be testing, a driver probe that
measured a file it had already deleted, a second driver probe that mis-parsed its own output, and
an HTTPS probe that was handed no CA bundle at all and reported the resulting success. In every
case the test suite was correct and the probe was not, which is the failure mode a green suite
structurally cannot catch.

The sandbox run had the same problem twice more, both in the runner rather than the install: it
hard-coded app port 8402 while the launcher chooses its own free port and announces it, and it
treated a `start` command still serving after four minutes as a hang when a foreground process
holding and serving *is* the success case. The second one made a healthy install look broken for
ten minutes. The runner now reads the port from the launcher's own output and treats a running
process as success.

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

**The clean-room run, with no system Java reachable.** After the scrub, `where java` returns
nothing at all. Everything below then happened with that in force, so any process that had found
its JVM by name rather than by absolute path would have failed there and nowhere else:

```
java:  ...\cleanroom\suwayomi\runtime\jre\bin\java.exe   the staged one, inside the install
where java now finds: nothing, as intended

port 4567 is free, so this is a real start
starting Suwayomi, which takes the longest of the four
Suwayomi is answering                            on attempt 3
database is current
the app reports ready                            on attempt 1
started: suwayomi, web, queue, scheduler

/ready  HTTP/1.1 200 OK  {"status":"ready","checks":{"key":true,"database":true,"pending_migrations":0}}
/up     HTTP/1.1 200 OK
aboutServer.version: v2.4.2366                  from the jar, started rather than adopted

scheduler: terminated   queue: terminated   web: terminated   suwayomi: terminated
8402 free   4567 free
```

Two runs were needed to get there, and the failure in between is worth keeping. The probe staged
the JRE with `rename()`, so the first run consumed its own input, and deleting the clean-room
directory afterwards destroyed it. The second run reported `NOT STAGED`. That was caught rather
than papered over only because the probe refuses to continue when it cannot find a staged JRE,
instead of quietly falling back to a system one. It copies now.

### Staging it reproducibly

`.tools` is scratch and lives outside the repository, so the manual staging above is not a build
step anyone else can run. `packaging/runtime/stage-runtime.php` is: it downloads the pinned
archive, verifies the digest before extracting anything, strips the archive's single wrapper
directory, and then checks that `bin/java.exe` exists and `bin/javac.exe` does not. A mismatch
deletes the download rather than leaving it for the next person to trust by accident. `--force`
replaces an existing staged JRE, and without it the script reports what is already there and
exits.

It then stages the CA bundle, which the shipped ini names as `{{RUNTIME}}/cacert.pem` and which
nothing else provides. This is not optional. Measured against the real runtime with the shipped
ini and no bundle:

```
error adding trust anchors from file: .../runtime/cacert.pem
```

Every HTTPS request fails, and the error names a trust-anchor problem rather than the missing
file that caused it. With the bundle in place the same request returns `HTTP 200, 188900 bytes`,
and a self-signed endpoint is still correctly rejected, so verification is fixed rather than
switched off.

The bundle's digest is pinned too, and that pin is more of a liability than the JRE's, because
it changes whenever a certificate authority is added or removed. It will go stale. That is the
correct trade: a bundle that updates itself during a build means two builds of one commit ship
different trust decisions.

### Two defects the sandbox gate found before it ran

Neither of these needed the sandbox. Both came out of checking that the config the installer
ships is the config the installer actually uses, which is a smaller job than a sandbox and worth
doing anyway.

**The shipped php.ini was never substituted.** It is a template, `extension_dir =
"{{RUNTIME}}/ext"` and it means it. `resolveWebConfig()` filled in the Caddyfile's tokens and
there was no equivalent for the ini, so the template reached PHP verbatim. PHP does not reject
that path. It treats the braces as directory name characters, fails to find all twelve
extensions, and serves an app that cannot open its database. Measured, same runtime both ways:

| | shipped template | resolved |
| --- | --- | --- |
| pdo_sqlite | MISSING | loaded |
| sqlite3 | MISSING | loaded |
| mbstring, zip, gd, curl, fileinfo, exif | MISSING | loaded |
| upload_tmp_dir | `{{TEMP}}` | a real path |

The clean-room run had passed while this was true, which is the part worth keeping. The probe
was substituting the ini itself and pulling it from a scratch cache rather than from
`packaging/`, so it verified the harness instead of the product. A probe that quietly replaces
the thing under test is worse than no probe, because it reports success.

**The test suite runs on a different PHP than the product ships.** FrankenPHP bundles PHP
**8.5.11**. Every one of the 1,509 tests runs on Herd's **8.4**. That is the whole suite
validated against a runtime the reader will not have, and it is why `curl_close()` reports as
deprecated in the packaged runtime. Pinning FrankenPHP to an 8.4 build and adding an 8.5 lane are
both still open.

This is a build script rather than application code, so it is verified by running it end to end
rather than by the test suite. That is a real gap and it is worth closing with tests for the
argument parsing and the mismatch path once there is a suite that can stand a fake archive.

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
| 2.1 | Inno Setup shell | M | **Done.** One exe, per user, no elevation, silent flags as documented. See below. |
| 2.2 | Shortcuts, Start Menu, file associations | M | Associating `.tachibk` and `.tmb` so a reader can double-click a backup is a small thing that makes the product feel finished. |
| 2.3 | Atomic upgrade | **S** | Run from `app/1.4.0/` and `app/1.5.0/` with a `current` pointer. Extract, run migrations, flip. Otherwise a running app can never be updated. See bite 4. |
| 2.4 | Single instance guard | H | Lockfile owned by the launcher. Recover stale locks by checking whether the pid is alive. Never by process name. |
| 2.5 | Firewall prompt | L | Binding to `127.0.0.1` only should avoid it. Worth verifying, because a prompt on first run is a bad first impression. |
| 2.6 | Auto-update | M | One artifact containing all three runtimes, not three update channels. See bite 6. |

### 2.1 done: the installer

`packaging/installer/papyrus.iss`, compiled by `build-installer.php`, which reads `VERSION` and
passes the number in rather than carrying a copy of it.

**Per user, not per machine.** `PrivilegesRequired=lowest`, installing to
`%LOCALAPPDATA%\Programs\Papyrus`. A reader app whose first act is "allow this app to make changes
to your computer" has made a bad first impression to buy the ability for somebody else to upgrade
it later. The install is self contained and, measured across a full run, writes nothing at runtime:
0 of 12,635 files changed.

**The library is never inside the install.** Data lives at `%LOCALAPPDATA%\Papyrus`, which is a
different folder from `%LOCALAPPDATA%\Programs\Papyrus`, so uninstalling removes code and leaves
the reader's library. That is what makes item 0.12 true by construction rather than by remembering
to skip a directory.

This needed one change outside the installer. `DataLocation` chose a sibling `papyrus-data` folder
whenever the install was writable, and a per-user install **is** writable, so it would have taken
the portable branch and put the library in `papyrus-data` beside the program. A reader looking for
their library looks in their own AppData. So the rule now reads:

| Shape | Data goes to |
| --- | --- |
| Install is under `%LOCALAPPDATA%` | `%LOCALAPPDATA%\Papyrus`, it was installed deliberately |
| Otherwise, and a sibling is writable | beside the install, so the folder stays movable |
| Otherwise | `%LOCALAPPDATA%\Papyrus`, the install cannot be written to |

The "inside AppData" test is checked before the writability test. That ordering is the whole
decision: without it a per-user install always takes the portable branch. It compares with a
trailing separator, because a prefix match on `C:\Users\<you>\AppData` and `C:\Users\<you>\AppDataLocal`
sends one reader's data somewhere they will never look, and that check passes while being wrong.

**Two things the plan did not ask for and the build needed anyway.**

| Gap | What it was |
| --- | --- |
| No way to start Papyrus | There was no `.cmd`, no shortcut target and no launcher binary. Item 2.1 cannot produce a Start Menu entry pointing at nothing, so `packaging/launcher/Papyrus.cmd` is the one thing a reader ever runs, and the build checks it landed on the install root. |
| No version number | `build-install.php` stamped only a commit hash, and wrote in a comment that a version number is worse because it drifts. It is now read from `VERSION` in the repository root, the single place it is written by hand. The hash is kept alongside it, because they answer different questions. |

**The entry point holds a console window open, and that is not the finished behaviour.** `start`
is the supervisor: it brings the four processes up, waits until they are usable, then holds the
terminal and watches them. Closing the window stops the app, which is deliberate, because the
children are not daemons. So the shortcut opens a window and keeps it. That is what is true today.
The tray icon (0.8) is what replaces it, and it calls the same three commands.

**Compression is not solid.** 623MB of already-compressed payload: the jar, the JRE and the PHP
runtime are zipped already, so a solid LZMA2 block spends minutes to save very little.

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