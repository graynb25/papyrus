# Packaging Papyrus for Windows

> **Release status (2026-11-07).** 1.5.7 is published from `633eb85`, tagged
> `v1.5.7`. The 1.5.8 build from `f7a61cf` is proven locally with gate 89 of 89 and a
> full manual tour, but not published and not tagged. See [RELEASE-STATUS.md](RELEASE-STATUS.md).

Status: **the 1.5.7 Windows installer builds.** Its build, hashes, automated gate and manual
tour are recorded below. The paragraphs that follow also record the 1.5.0 and 1.5.1 work that
got the packaging layer here.

The 1.5.0 install tree passed the Windows Sandbox gate on run 19 with a real Windows source read,
and the rebuilt 1.5.0 installer passed the manual sandbox on 2026-10-27, including the shortcut
double-click that hung on the 2026-10-26 run. The 1.5.1 setup was built from `9dd0918` and passed
the manual sandbox on 2026-10-29: per-user install, registration, a community store, MangaTaro, an
One Piece search and a chapter read. The 1.5.1 portable zip was rebuilt from `8ca2028` after the
first-launch storage fix and passed clean-guest runs on C: and D: on 2026-10-29, including the same
reader flow and a layout check with no install or registry entry. Run 19 copied `build/install` to
a fresh guest and started it through
the reader shortcut. All 86 checks held: the bundled PHP 8.5.12 ran, Suwayomi answered, the app
reported ready on 8402, Edge opened, the first account was created, `/sources` rendered, the
community store and MangaTaro were installed, One Piece was fetched, and page 0 of chapter 1 was
read through the authenticated proxy as a 175,432-byte WebP. The gate does not run the setup
executable; `launch-manual.ps1` is the separate manual run: its first run installed the 1.5.0
payload and found the shortcut defect, and its second run proved the rebuilt 1.5.0 installer end
to end. Earlier completed runs found thirteen defects, including three visible only off the
development machine. Item 0.9, the unified logging that makes any of this diagnosable, is
designed in [`LOGGING.md`](LOGGING.md). Distribution, signing, platforms, backup and publishing
are in [`DISTRIBUTION.md`](DISTRIBUTION.md). Written after investigating this repository, this
machine, and the upstream projects it would ship.

The shipped configs live in `packaging/`, and the build steps are:

```
php packaging/runtime/stage-frankenphp.php the pinned web server
php packaging/runtime/stage-runtime.php      the pinned JRE and the CA bundle
php packaging/runtime/build-install.php --frankenphp=<zip> --jar=<jar> --into=<absolute path>
php packaging/installer/build-installer.php --unsigned  the local unsigned .exe
php packaging/installer/build-portable.php   the zip, hashed
```

The 1.5.0 build on 2026-10-26 ran in the checkout, and the artifacts were rebuilt on 2026-10-27
from commit `86e033a` after the shortcut fix. The install payload passed its built-in checks at
12,962 files. The unsigned installer compiled at 275,084,375 bytes (262.3MB), and the portable
archive compiled at 321,055,119 bytes (306.2MB) with 12,963 entries and a matching sidecar hash.
The staged runtime reports PHP 8.5.12, FrankenPHP 1.13.2 and Temurin 25.0.5+8. The install tree
passed the Windows Sandbox gate on run 19 with 86 checks, including a source page read through
the app proxy. The gate copies `build/install` rather than running the setup executable. The
separate manual sandbox ran the first `Papyrus-1.5.0-setup.exe` (275,084,054 bytes): it installed
1.5.0 per-user and the reader flow worked, but `Papyrus.cmd` hung at its `php -n` preflight. The
check is now `php -n -v`, with a regression test. The fresh manual run on the rebuilt installer
(275,084,375 bytes) passed on 2026-10-27: post-install launch, shortcut double-click,
registration, store, MangaTaro, search and a chapter read.

The 1.5.1 build on 2026-10-29 ran from `9dd0918` for the setup. The install payload passed its
built-in checks at 12,963 files with 138 licence texts. The unsigned installer compiled at
275,072,638 bytes (262.3MB) with SHA-256
`056cc3e55b7cba84bcbcb052652ceec839a7795a1567a05f532f537214993aae`. The 1.5.1 setup passed the
manual sandbox on 2026-10-29: the guest verified the copied installer at 275,072,638 bytes with
the hash above, installed it per-user, registered, trusted a community store, installed
MangaTaro, searched One Piece, and read chapter 1 of 1,202.

The portable zip was rebuilt from `8ca2028` after the first-launch storage fix in `2e4a73d`. It
compiled at 321,034,901 bytes (306.2MB) with 12,964 entries and SHA-256
`0a430b7a9ad293ba33343cb55950c667a1c82642f5f044ea913ee25d2191efab`; the sidecar matches. The
staged runtime reports PHP 8.5.12, FrankenPHP 1.13.2 and Temurin 25.0.5+8. The rebuilt portable
passed clean-guest runs on C: and D: on 2026-10-29: extraction, first launch through
`Papyrus.cmd`, data beside the program folder, registration, a community store, MangaTaro, One Piece,
chapter 1 of 1,203, and a layout check with no `%LOCALAPPDATA%` install, no Start Menu entry and
no uninstall entry. The C: run used a path with a space; the D: run used a 4 GB VHD-backed
second volume.

The 1.5.4 final build on 2026-11-04 ran from `730225f` in a clean worktree. The install
payload passed its built-in checks at 10,237 files. The unsigned installer compiled at
257,582,579 bytes (245.6MB) with SHA-256
`e5bf56bbf6a6e67bc2626d6175c866206f0fab08d31678b4dc6eae8901386f27`; the portable
compiled at 299,480,441 bytes (285.6MB) with 10,238 entries and SHA-256
`e53b5c859f649db1aca8c4057a5fa12cf7f4c48e10c7836c4b751f5fd2877748`. The manual
fresh-install sandbox passed on 2026-11-04 in session `session-20261104-133727`:
per-user install, registration, a store, MangaTaro, One Piece, 14 results from 2 of 2
runnable sources, a real reader page, and 1 of 1,203 chapters read. The automated
Windows Sandbox gate has not run on this build.

The 1.5.6 build on 2026-11-05 ran from `8a398ab`. The install payload passed its built-in
checks at 10,244 files. The unsigned installer compiled at 257,605,124 bytes (245.7MB) with
SHA-256 `138465bd176d2fb4c56737b40cb65b70c70f27f4a6b79d34658981671ba686d3`; the portable
compiled at 299,506,107 bytes (285.6MB) with 10,245 entries and SHA-256
`bc8cd08c383bc8c2c47f6d51fae31c1c4411a99cbaa9b5449e17b28aeb685409`. The automated
Windows Sandbox gate passed all 89 checks with 0 failures on 2026-11-05. The manual
installer tour passed all nine steps in session `session-20261105-112810`. The portable
zip passed clean-machine C: and D: runs on the same day, in sessions
`session-20261105-145005-c` and `session-20261105-145450-d`. The D: run used a 4 GB
VHD-backed second volume and reported `separate_volume=True`.

`--into` must be absolute. A relative root reaches `Supervisor::path()`, which joins it onto whatever it
resolves, and the build then fails twenty lines later at `the install cannot boot with its own resolved
php.ini` with nothing pointing at the cause. It is refused at the top of the script for that reason.

### Signing the installer

Authenticode, driven entirely by the environment so that no secret lives in the repository:

| Variable | What it does |
| --- | --- |
| `PAPYRUS_SIGN=1` | Asks for signing. Without it the build **refuses** rather than producing an unsigned installer quietly |
| `PAPYRUS_CERT_THUMBPRINT` | A code-signing certificate already in the Windows certificate store |
| `PAPYRUS_CERT_PFX` | Path to a `.pfx` holding one, instead of a thumbprint |
| `PAPYRUS_CERT_PASSWORD` | Password for that `.pfx` |
| `PAPYRUS_SIGNTOOL` | Path to `signtool.exe`, if it is not where the SDK puts it |
| `PAPYRUS_TIMESTAMP_URL` | Defaults to DigiCert. A release without a timestamp is refused |
| `PAPYRUS_RELEASE=1` | Marks this as a release, which makes a missing timestamp fatal rather than survivable |

`--unsigned` is the only way to build without signing, and it is a flag rather than a default because an
unsigned installer is indistinguishable from a signed one on disk. The build manifest records
`signed: true|false` and a description of the signature, so the build log is not the only record.

### Self-signing, and what it actually does

`packaging/signing/make-certificate.php` makes a self-signed code-signing certificate in `.signing/`:

```powershell
php packaging/signing/make-certificate.php
```

Then to sign with it:

```powershell
$env:PAPYRUS_SIGN = "1"
$env:PAPYRUS_CERT_PFX = "<the .pfx path>"
php packaging/installer/build-installer.php
```

**It does not remove the Windows warning on a reader's machine.** Measured both ways, with the count of
trusted roots read back afterwards rather than assumed:

| Certificate root | `Get-AuthenticodeSignature` | `signtool verify /pa` |
| --- | --- | --- |
| absent from every store | `UnknownError` | exit 1, "terminated in a root certificate which is not trusted by the trust provider" |
| imported to `Cert:\CurrentUser\Root` | `Valid` | exit 0 |

So the warning goes away only on a machine where somebody installed the certificate:

```powershell
Import-Certificate -FilePath .signing\papyrus.crt -CertStoreLocation Cert:\CurrentUser\Root
```

What it does buy, which is worth having:

- **Integrity.** The signature proves the file has not changed since it was signed, and it names a
  signer whose thumbprint can be published alongside the download so a reader can check it.
- **A stable identity.** Every release signed by the same certificate names the same signer, so a
  reader can notice a change. That is worth something even untrusted, because the absence of a change is
  information.
- **A tested upgrade path.** When a CA-issued certificate is bought, only the environment variables
  change. The signing code has already run against a real signature rather than a mock.

Two details that are not optional:

- The certificate **must carry the code signing EKU**. openssl's bare `req -x509` produces one with no
  Extended Key Usage at all, which `signtool sign` accepts and Windows then refuses. The script uses a
  config file and reads the EKU back off the finished certificate before reporting success.
- The material lives in `.signing/`, which is gitignored **and** skipped by the machine-detail gate, and
  not under `build/dist/`. A clean of the output directory must not take the certificate with it: losing
  it means the next release is signed by a new identity, and a reader who trusted the old one is handed
  a warning they had stopped seeing, with nothing on their side to explain it.

The script refuses to overwrite an existing certificate for the same reason.

### The portable zip, and why it cannot be signed

`build-portable.php` packages the install for a reader who wants no installer at all: unpack it anywhere,
run `Papyrus.cmd`, and the library lives in a `Papyrus` directory beside it rather than inside it. It
refuses an install containing a real `.env`, a live `database.sqlite`, either sidecar, or a build probe,
and it refuses an output directory inside the install, because a build then packages the previous build's
archive.

**A zip cannot carry an Authenticode signature.** Measured with a real certificate and `signtool.exe`:

```
SignTool Error: This file format cannot be signed because it is not recognized.
```

So it ships a `.sha256` sidecar in `sha256sum` format instead, which both `sha256sum -c` and Windows'
`certutil` accept. That is a weaker guarantee than a signature and it is the honest one available for a
container format.

It also makes reproducibility load-bearing. Entries are sorted before writing, and two builds of
identical content produce byte-identical archives, so a published hash stays meaningful. A zip whose bytes
change every build has a hash that changes every build, and "is this the file you published" becomes
unanswerable.

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
| 20 | `health` asked whether a `queue:work` process existed, so a bulk library check sat unreserved for 70 minutes behind a worker that was draining a different database | a worker for the packaged install is running, and from outside it is indistinguishable from one that would have drained the checkout's jobs table |
| 21 | A fresh install prints "Papyrus did not start. The lines above say why." over no lines at all, because `cmd.exe` refused to execute the runtime and said only "Access is denied." | antivirus scans a freshly written 623MB install before letting any of it execute, and for that window `cmd.exe` refuses `php.exe`. The application is not failing, so nothing in the application can report it, and the one message that does appear points at the wrong layer |
| 22 | `stop` reported all four processes dead and left Suwayomi's embedded browser running, holding `jcef_helper.exe` open inside the data directory | the browser is launched by the JVM rather than by the launcher, so it is never in the list of processes the launcher started, and nothing ever looked for it |
| 23 | The packaged install's scheduler dispatched nothing. `artisan schedule:run`, the process that actually runs the schedule, died every ten seconds with `PDOException: could not find driver`, and 182 attempts had failed without a word reaching the reader or the log's own summary | `schedule:work` does not run the schedule. It shells out to `schedule:run`, building that command from `PHP_BINARY` with no `-c` of its own, because Laravel assumes it inherited a configured interpreter. The launcher passed `-c` to `schedule:work` and nothing to the child, and this launcher's own PHP loads no ini at all, so the grandchild found none, loaded no extensions, and died on its first query |
| 24 | `health` reported `scheduler running schedule:work` for six minutes over a scheduler that had stopped dispatching entirely. Downloads kept draining through the queue, so the install looked busy and nothing was wrong with any line | the scheduler's health line was process presence, which is the same mistake the queue line had already been corrected for. Both lines were written together, three lines apart, and correcting one left the other saying the thing the file's own docblock exists to warn against |

**24 is the correction that was half made, and it is worth being precise about what is and is not
known.** The check now measures whether the scheduler is still writing rather than whether a process
exists, with a threshold of four missed intervals. What is **not** known is why that particular
scheduler stopped looping. It was alive, idle rather than spinning at 0.016 seconds of CPU across
ten seconds, with its log frozen and not one `schedule:run` child spawned in a sampled 32 seconds.
Fifty-one held `framework/schedule-*` cache mutexes were found and cleared on the way to diagnosing
it, and clearing them did not restore dispatching, so they are not the cause either. The sequence
that preceded it involved several launcher starts and stops against one install, which leaves a
scheduler that a later launcher finds rather than starts, so the most likely explanation is an
artefact of that rather than something a reader would meet. It is recorded here as a check that could
not see it, not as a cause found.

**23 is the fourth "it worked, just not for the reader", and the quietest one yet.** Nothing crashed,
all four processes reported healthy, and the schedule simply never ran. Three separate silences
stacked: the supervisor saw a live parent and had no reason to look at its children; the child wrote
its stack trace to a temp file under `tmp/` rather than to `scheduler.log`, where nobody was reading;
and it recurred every ten seconds, which looks like a log line rather than an outage. The one
process that was obviously wrong, `schedule:run`, was never in the list of processes anything checked.

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

20 cost the longest of the three, and it is the same shape as 19 a second time: a check that asks
the wrong question and gets a confident yes.

`health` asked whether a `queue:work` process existed. It did, and it belonged to the packaged
install, draining `Documents\Papyrus\data` while the site in front of the reader was the repository
served by Herd on port 80. A bulk library check sat in `database/database.sqlite` with
`reserved_at` null and `available_at` 70 minutes in the past, and the library page read `Checked 0 of
670 series` the entire time. Six green lines, including the one about the queue.

So process presence is the wrong question and the queue table is the right one. `health` now asks
whether a job that is **due** has been taken, which a worker that exists cannot fake: it is one
query, and a due unreserved job older than a minute is a failure that names its own cause. The
count of rows is deliberately not the test, because `CheckLibrary` re-books itself on a three second
delay and a working queue is routinely non-empty.

Two details cost time and are worth keeping.

The probe has to be a child process. The launcher's own interpreter loads no `php.ini`, so it has no
`pdo_sqlite` at all, and `php_ini_loaded_file()` is empty while `extension_dir` is the compiled-in
`C:\php\ext`. Measured, not assumed.

And it has to read the **resolved** ini in the data directory, not the template in the install. The
first version read the template, started PHP against it, and got eleven warnings about `{{RUNTIME}}`
followed by `could not find driver`. It reported "not checked", which is honest, and the eleven
warnings in front of it are what made the log unreadable. A probe that reports on a file it did not
read is worse than no probe, because it produces a specific and wrong answer.

**And the test written to protect the default could not fail.** The verdict was a list of healthy
prefixes, so an unrecognised sentence fell through to "not healthy", which is the safe answer. A
test asserting "an unknown sentence is not a verdict" passed anyway, because every assertion it
made happened to match a named prefix. The verdict is now a table of pairs, and a test reads the
probe's own source for the sentences it can print and requires a verdict for each, so a vocabulary
change cannot reach the default without failing something.

**21 and 22 were both found by the one thing that had never been done: running the cycle.**

Every check until now started the application from a working tree with a working data directory. The
install, the uninstall and the empty first run had never been exercised as one sequence, and each of
these is invisible from inside an install that already works.

**21 is the worst message in the project**, and it is three lines long. A fresh install prints:

```
Papyrus did not start. The lines above say why.

Keeping this window open so they can be read, or copied into a bug report.
```

There are no lines above. What is above, written by `cmd.exe` to stderr, is:

```
Access is denied.
```

That is Windows declining to execute `runtime\bin\php.exe`, which is what antivirus software does
while it scans a freshly written 623MB install, and it does it for long enough that a reader
double-clicking the shortcut straight after installing hits it. The application never ran, so
nothing in the application could report anything, and the one message that does appear sends the
reader, and anyone reading over their shoulder, to the wrong layer entirely. The cause is not
Papyrus failing. It is the operating system refusing to run the interpreter Papyrus is written in.

The two cases are told apart now. `Papyrus.cmd` runs the runtime with `-n` first, which costs about
a fortieth of a second and answers whether the file can execute at all rather than whether Papyrus is
configured. When it cannot, it names the file and says what to do about it. The old message is kept
for the case it is actually correct for, which is the launcher refusing and printing its own reason.

What a test could and could not have caught here was worth recording. Asserting on the text of a
batch file would have passed against the version that produced this, so
`tests/Feature/PapyrusCmdTest.php` builds a scratch install and runs the real file in it. An
unrunnable runtime is a **directory** named `php.exe`, which satisfies the existing `if exist` check
and is then refused by `cmd.exe`, which is the antivirus case exactly. Five mutations against that
file are killed, and the assertion that matters most is a negative one: "the lines above say why" is
the message being removed, and a test that only checked for the new wording would have passed against
a script printing both.

**22 is smaller and was hiding behind it.** Once the install ran, `stop` reported all four processes
dead and an orphaned Suwayomi browser helper was still running, holding `jcef_helper.exe` open inside
the reader's data directory. Deleting that directory afterwards failed with "Access is denied", for a
file nothing was using any more.

The reason is structural rather than accidental. The browser is launched by the JVM, so it is never
in the list of processes the launcher started, and the only way to find it is the matcher the other
four already use. Nothing listed it.

That list and the list of matcher tokens are two separate lists, and a name in the first with no
entry in the second is searched for with an empty token list, which matches nothing and prints
nothing. So the failure mode is a process silently never stopped, and a stop line that looks
complete, which is defect 14's shape a second time. The test asserts the property across both lists
rather than naming the entry, because "nothing can be added to one of these without the other" is
the thing worth protecting, and a test that named the fifth entry would have to be edited for the
sixth.

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
project's own working directory is `C:\My Projects`. Every test used a system temp
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
rather than trusting the clock. On this host the memory process can outlive both the client and
`WindowsSandboxServer`; the release that worked was an elevated `hcsdiag list` followed by
`hcsdiag kill <the Running WindowsSandbox id>`, never the SavedAsTemplate entry.

Three things about it fail silently, and each is recorded in `build/sandbox/papyrus.wsb` so it does
not have to be rediscovered:

- `build/sandbox-results` must exist on the host before launching. A missing mapped folder is never
  created, the guest closes with no error, and it looks exactly like a slow boot.
- The runner must not be edited while a guest is booting. It is read from the mapped folder at
  logon, so editing or committing it in the first minute of a run means the guest executes a
  half-written file.
- Every path passed to `Start-Process` has to be quoted. This is defect 16's mechanism one level
  down, and it appeared four times in a single afternoon, each time as a confident wrong answer.

Run it with `powershell -File build\sandbox\launch-gate.ps1`, not by opening the `.wsb` by hand.
The launcher refuses a running guest, clears `build\sandbox-results` recursively, and refuses to
launch if anything remains, so a report from an earlier run cannot be mistaken for this one. It
waits for `sandbox-report.txt` and prints the report as it grows.

**What the gate actually asserts**, from `build/sandbox/run-in-sandbox.ps1`:

```
no java on PATH, no php on PATH, no Java installed
all required files present, exactly one suwayomi jar
no database, no .env and no logs shipped
bundled php.exe runs standalone                      PHP 8.5.12 ZTS Visual C++ 2022 x64
13 directories created, Suwayomi answering
database migrated, no database written into the install
the app reports ready
queue worker and scheduler started
Papyrus is running at http://127.0.0.1:<port>
suwayomi answers graphql                             v2.4.2366
a community store adds 1,431 extensions
MangaTaro installs and Suwayomi lists it as a source
the source returns One Piece with 1,202 chapters
fetchChapterPages returns 53 page paths for chapter 1
page 0 through /suwayomi/api/v1/... is 200 image/webp, 175,432 bytes
/sources and /series/1 render after the catalogue is populated
https works with the bundled trust store            200
papyrus diagnostics writes its five files and collects its logs
no line lost collecting suwayomi output              209 collected, 209 in the source
all four processes stopped, both ports released
the stop did not claim there was nothing to stop
```

Run 19 was the first run to reach the source read. The report is 10,056 bytes, SHA-256
`02413c0cdfb47475ffc681033d2d3eb02362208a7934008d7eb335267b94d650`; the screenshot is
1,556,164 bytes, SHA-256
`c0926a1af1d5fd3adf31c6f421eff1ab0645e6c818b0125c359b4cbc19cd42df`. The page was 175,432
bytes, SHA-256 `9996ffbb9d1fadac23357e56c611f93b1c07e980737592baa0658bf567574e6e`, the same
first page WSL run 3 read. The artifacts are preserved under
`%TEMP%\opencode\run19-20261026\`.

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

### The manual sandbox: the installer, not the install tree

The gate copies `build/install` and never runs `Papyrus-1.5.0-setup.exe`. That is the right shape for
a gate, and it leaves the setup executable, its shortcuts, its first run and the pages a reader
actually clicks unproven. Those are manual checks, so they have their own sandbox rather than a
weakened gate.

`build\sandbox\launch-manual.ps1` builds `build\sandbox\papyrus-manual.wsb` from
`papyrus-manual.wsb.template`, creates a fresh `build\manual-sandbox-results\session-<timestamp>`
folder, writes the expected installer size and SHA-256 into it, and maps three folders:

| Host | Guest | Mode |
| --- | --- | --- |
| `build\dist` | `C:\installer` | read only |
| `build\sandbox` | `C:\sandbox` | read only |
| the session folder | `C:\results` | writable |

The guest copies the installer to its desktop, checks the copy against the host-written hash, and
leaves it there to be double-clicked. It also starts `capture-screen.ps1` hidden: a PNG every ten
seconds, a CSV index, and a stop marker. Four desktop shortcuts open the installer, take a named
step capture, stop the loop, and open the results folder. The loop is the record; the named frames
are the index a person can read.

    powershell -File build\sandbox\launch-manual.ps1
    powershell -File build\sandbox\launch-manual.ps1 -NoLaunch

`-NoLaunch` writes the `.wsb` and the session files without starting a guest. The launcher refuses a
running guest and refuses an installer whose size or hash does not match `build\dist\manifest.json`.
Inside the guest, the desktop shortcuts and `START-HERE.txt` carry the order: install, register, add
a community store, install MangaTaro, search One Piece, read a chapter, name the moments, stop the
screenshots, close the sandbox.

The session folder ends up with `READY.txt`, `installer-verified.txt`, `setup.log`, `session.txt`,
`MANUAL-README.txt`, `shot-*.png`, `screenshots.log`, `step-*.png`, `steps.log` and
`capture-stopped.txt`. The named steps are `step-<label>-<timestamp>.png`; a label is sanitised to
letters, digits, dots, underscores and hyphens before it becomes a file name.

The run is manual acceptance evidence and never a gate pass. It proves what a person saw, not a
reproducible assertion. The installer is unsigned, so any warning is recorded rather than worked
around. The app binds loopback only, so the guest's own browser is the only one that can reach it.
The host scripts were exercised on the development machine: the watcher took two frames and stopped
on its shot limit, the named capture wrote its frame and log, and `-NoLaunch` generated a valid
`.wsb` with the expected installer hash.

The first guest run on 2026-10-26 used the pre-fix installer and proved the setup executable, the
registration page, a community store, MangaTaro, a One Piece search, the series page and a
chapter read. It also found the preflight defect: `Papyrus.cmd` ran `php -n` with no script, and a
console leaves stdin open, so the runtime check waited forever and the window stayed blank. The
gate and `PapyrusCmdTest` had redirected stdin from an empty file and missed it. The check is now
`php -n -v`, with a regression test that keeps stdin open.

The second guest run on 2026-10-27 used the rebuilt installer and repeated the flow through the
shortcut: the post-install console and the shortcut console each printed the complete preflight
and asked the browser to open, so the hang did not reproduce, and registration, the store,
MangaTaro, the search, the chapter read and the marked-read state all passed. The run is manual
acceptance evidence, never a gate pass.

### Done and verified: 0.2, the FrankenPHP gate

This was the item the whole plan hinged on, because it decides whether the runtime is one
binary or three. It is resolved, in the app's favour, with evidence.

| Check | Result |
| --- | --- |
| FrankenPHP Windows binary | 1.13.0, ships PHP **8.5.11** ZTS |
| App requirement | `php ^8.4`, so 8.5.11 is in range |
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

**Nothing except 0.8's tray icon, which is deferred rather than forgotten.** This list said all of
these were outstanding as of 2026-10-11, which stopped being true within the day, and a plan that
lists completed work as pending is worse than no plan. Verified against the tree rather than from
memory:

| # | Item | State | Evidence |
| --- | --- | --- | --- |
| 0.5 | `.env` generation | done | `papyrus:configure`, and `.env.example` ships. The build runs it and fails if it does not write a key |
| 0.6 | Windows path and junction reality | done, **by a different route than planned** | The plan said junction `public/storage`. What shipped is that `storage/` moved to the install root and `NEVER_COPY_STORAGE` keeps it out of the artifact, so there is no junction to make. A real directory at the root, no symlink, no long path |
| 0.7 | Data location under `%LOCALAPPDATA%` | done | `DataLocation.php`, with a portable branch beside the program. Live install at `%LOCALAPPDATA%\Papyrus` |
| 0.8 | Launcher: tray, start, stop, open browser | **partly done** | `Papyrus.cmd` starts, stops, reports, and **opens the browser at the address it actually bound** (`--open`). **No tray icon and no native binary.** The plan called for a 5 to 10MB Go or C# binary; what exists is a batch file. Deliberate, since the batch file is what the clean sandbox could prove, and a native binary would need its own gate |
| 0.9 | One place for four processes' logs | done | `SummaryLog` writes the one-line-per-process summary; nine parsers normalise the nine native formats; `papyrus diagnostics` bundles them |
| 0.10 | Clean-sandbox verification | **partly done, re-run on 1.5.0** | `build/sandbox/run-in-sandbox.ps1` and `papyrus.wsb` are tracked. **Run 19, 2026-10-26: PASS, 86 checks, 0 failures**, on a fresh guest with no Java, no PHP on PATH and no Java installed. It started the install through the reader shortcut with `--open`, created the first account, rendered `/sources`, added a community store, installed MangaTaro, fetched One Piece, read page 0 of chapter 1 through the proxy (200 `image/webp`, 175,432 bytes, SHA-256 `9996ffbb...`), and rendered `/sources` and `/series/1` after the catalogue was populated. The original bar also names importing a backup; run 19 does not, so the backup path in a clean guest remains unproven. The gate copies `build/install`; the separate manual sandbox ran the setup executable on 2026-10-26 (it installed 1.5.0 but its shortcut preflight hung, fixed in `86e033a`) and again on the rebuilt installer on 2026-10-27, when the shortcut double-click started the reader and the full flow passed. That run is manual acceptance evidence, not a gate pass |
| 0.12 | Look at a real install in a sandbox | done | `build/sandbox/hold-open.ps1` and `papyrus-hold.wsb`. A gate stops everything it starts, so it cannot be used to look at the thing. This is the same setup and then it holds. **Verified live: 12,644 files copied, `/ready` 200 in 15s.** Nine tests in `SandboxRunnerTest`, both mutations killed |
| 0.11 | Decide the Suwayomi runtime shape | done | The `jlink` question was answered by staging rather than building: a pinned JRE is staged beside the jar at build time and digest verified. 353.6 MB of the 623 MB install is Suwayomi and its JVM, none of it ours |
| 0.12 | Uninstall must not delete the library | done | `papyrus.iss` contains **zero** `[UninstallDelete]` directives. The full install, run, uninstall cycle was run with the library hashed either side and it came back byte identical |
| | `VACUUM INTO` snapshot job | done | `SnapshotDatabase` behind `papyrus:snapshot`, scheduled `dailyAt('03:17')` |
| 0.1 | Artifact layout and build pipeline | done | `build-install.php` verifies 20 required paths, boots Laravel from the built tree, and runs the launcher out of it |
| 0.2 | Verify FrankenPHP on Windows | done | PHP 8.5.11 ZTS, full suite green against it |
| 0.3 | Process supervisor for four processes | done | Verified across install, run and upgrade on a real install |
| 0.4 | Readiness probe | done | `/ready`, and the health check reports its status, app key and database separately |

**The gate, and what it had never seen.** "Runs in a clean Windows sandbox with nothing preinstalled"
was demonstrated on 2026-10-11, and not again until 2026-10-13, by which time three launcher changes
had landed. Launch it with

    WindowsSandbox.exe build\sandbox\papyrus.wsb

which mounts `build\` into the guest, copies the install to `C:\Program Files\Papyrus` there, and
writes `build\sandbox-results\sandbox-report.txt` back. It takes about seven minutes.

**It had a hole exactly where the worst defect was.** Thirty-eight assertions and not one of them
asked whether the scheduler was doing anything. It checked that `queue worker and scheduler started`
appeared in the launcher's output, which the launcher prints whether or not either process can reach a
database, and then moved on. The `PHPRC` defect therefore passed straight through the one check in
this project that runs on a machine with nothing installed, 182 failures and all.

The gate now samples `scheduler.log`'s modification time twice, 35 seconds apart, and fails if it did
not both grow and advance, because the schedule fires every ten seconds and a working scheduler cannot
go that long without writing. It also runs `health` and asserts the line a reader is actually shown
says dispatching, so a wedge is caught even if the log happens to be growing for some other reason.
Measured on the re-run: 1450 bytes to 1694 bytes, mtime advanced, and `health` agreed.

**Mtime, not a parsed timestamp, for the same reason the health check uses one.** The scheduler renders
timestamps at UTC+5 while the guest runs at UTC, so an age computed from a line inside the log would be
wrong by five hours and would report a working scheduler as silent since yesterday.

| # | Item | Sev | Notes |
| --- | --- | --- | --- |
| 0.1 | Artifact layout and build pipeline | M | One folder: `runtime/`, `php/`, `suwayomi/`, `app/`, `data/`, `logs/`. Everything the build must materialise is enumerated so a build script can materialise it: `composer install --no-dev`, `npm ci && npm run build`, `.env` generated, `public/storage` created. |
| 0.2 | Verify FrankenPHP on Windows with this app | H | It ships **PHP 8.5**; the app requires `^8.4`, so it is in range, but this app is developed on 8.4.27 and has never run on 8.5. Run the full suite against it before committing to it. Fallback is `php-cgi` plus a small Caddy or nginx config. |
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
replaces an existing staged JRE, and without it the script reports what is already there, checks
the trust store and the CA bundle, and exits.

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

The pin is a dated curl.se extract, `cacert-2026-10-20.pem`, not the moving `cacert.pem`, and
the cached file is verified on every run. A warm build that finds a corrupt or stale bundle
replaces it instead of reporting it as already staged.

The Suwayomi jar is pinned the same way. `RuntimePins::SUWAYOMI_SHA256` is the digest GitHub
publishes for the 2.4.2366 release asset, and `build-install.php` refuses a jar that does not
match before it clears the layout. The jar name must carry the pinned version, and
`packaging/suwayomi.json` must name the same tested release; the suite compares the two.

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

### The application icon

The mark is the `.seal` tile from `resources/css/app.css` with the Heroicons `book-open` outline
inside it. `packaging/make-icon.php` puts those two existing pieces on a 512 canvas and writes the
five files the app and Windows read:

| File | Used by |
| --- | --- |
| `public/favicon.svg` | The browser tab, tuned for 16 pixels first |
| `public/favicon.ico` | The tab fallback, shortcuts, the uninstaller and the setup executable |
| `public/apple-touch-icon.png` | Opaque 180 pixel icon, because iOS composites transparency onto black |
| `public/icon-256.png`, `public/icon-512.png` | The larger mark, for anything that asks for one |

The setup executable, the Start Menu entry, the desktop shortcut and the uninstaller all read
`public/favicon.ico`, and `SetupIconFile={#SourceRoot}\public\favicon.ico` makes the compiler take
the setup icon from the built install rather than a second copy kept beside the `.iss`. A rebuild
is `php packaging/make-icon.php`, which needs GD and Chrome or Edge; the rasteriser is the browser
so every size comes from the same vector.

The ICO carries 16, 24, 32, 48, 64, 128 and 256 pixel frames in that order. At 48 and above the
book keeps the seal's exact 42.86 percent proportion and 1.5 unit stroke; below that the line is
heavier and the book slightly larger. The delivered 16 pixel frame measured 82.4 percent ink and
7.0 percent white book, so the shape survives the size rather than being assumed to.

The portable shape has no shell icon to set: a `.cmd` cannot carry one. Its mark is the browser tab
and the favicon the server answers with. The CSS grain and outer cast shadow are deliberately not
baked into the icon, because a shadow on a transparent canvas is a dark halo at small sizes and
the grain is noise at 16 pixels.

### 2.1 done: the installer

`packaging/installer/papyrus.iss`, compiled by `build-installer.php`, which reads `VERSION` and
passes the number in rather than carrying a copy of it.

**Per user, not per machine.** `PrivilegesRequired=lowest`, installing to
`%LOCALAPPDATA%\Programs\Papyrus`. A reader app whose first act is "allow this app to make changes
to your computer" has made a bad first impression to buy the ability for somebody else to upgrade
it later. The install is self contained and, measured in a clean Windows Sandbox guest on
2026-10-29 across a full install, run and uninstall, writes nothing at runtime: 12,965 installed
files, 0 added, 0 removed, 0 changed. The same guest ran `verify-installer.ps1`: silent install in
35s, data at `%LOCALAPPDATA%\Papyrus`, `/ready` 200, uninstall in 13s, and the library byte
identical afterwards.

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

### 2.1 verified: the whole cycle, at last

Marked done for a long time before it had ever been run end to end. Every check started the
application from a working tree, so the install, the first run and the uninstall had never happened
as one sequence, and the harness that was supposed to prove them aborted every time at the same
place and the abort was blamed on the harness.

It was not the harness. It was demanding a Suwayomi library, which needs Suwayomi to have accepted a
login, which a fresh install by definition cannot, because a fresh install has no credentials for it.
The test required the thing it was testing to depend on a precondition the thing it was testing
cannot satisfy. Requiring Papyrus's own `database.sqlite` instead, which Laravel's migrations create
during boot with no dependency on Suwayomi at all, fixed a test bug and exposed two product bugs
underneath it, which are 21 and 22 above.

What the cycle measures now, on the real installer, every claim checked against the filesystem:

| Claim | Result |
| --- | --- |
| Installs per user with no elevation | exit 0 in 74s, 12,641 files under `%LOCALAPPDATA%\Programs\Papyrus` |
| The shortcut points at something that is there | `Papyrus.lnk`, target exists |
| Data goes to `%LOCALAPPDATA%\Papyrus` and not beside the program | no `papyrus-data` beside the program, ever |
| A reader can read what the entry point printed | 1,023 bytes through `cmd.exe`, which is the CRLF claim measured rather than assumed |
| It actually serves | `/ready` 200, `{"key":true,"database":true,"pending_migrations":0}` |
| `stop` leaves nothing holding a port | both ports free, no JVM |
| **Uninstalling leaves the library** | `database.sqlite` byte identical, `31BCF847...` before and after |
| Nothing left behind | 0 stray directories, 0 shortcuts |

The library line is the one that matters and it is a hash, not an existence check. A test that only
checked the file still existed would pass while the uninstaller quietly emptied it.

Two things it does not yet cover, so they are written down rather than assumed away. It does not
test an **upgrade through a versioned folder**, because the versioned pointer path (item 2.3) is
not built yet. The full-replacement upgrade path exists since 1.5.3, and the 1.5.6 drill upgraded
1.5.4 to 1.5.6, rolled back, and removed the install successfully. And it measures the install as a
*fresh* one: the case where a reader already has a library at a different path is not exercised, and
`DataLocation` choosing `%LOCALAPPDATA%\Papyrus` for a reader whose library is in `Documents` is an
empty library with no explanation attached, which is its own problem.

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

### 4a. What ships now: manual upgrade

The versioned-directory updater above is not built. The supported path today is the full
installer over the existing install, with the reader confirming every step:

1. Download the new `Papyrus-<version>-setup.exe` from Releases and compare its SHA-256 with
   `releases/<version>.json`.
2. Stop Papyrus, or let the installer stop it. The installer aborts if the launcher cannot
   stop every process.
3. Run the installer. It refuses to replace a newer install unless it is run with
   `/FORCEDOWNGRADE`, which is the deliberate rollback path.
4. The installer replaces program files only. It never writes, moves or deletes anything under
   `%LOCALAPPDATA%\Papyrus`.
5. The first start records the pre-migration snapshot path, digest, version and commit, then
   migrates. If the snapshot cannot be recorded, it does not migrate.
6. If the migration fails, `papyrus rollback` restores the recorded snapshot after saving the
   current database. Reinstall the previous version afterwards; the current version must not
   start against the restored database.

Papyrus never checks, downloads or runs an update silently, and it never suppresses SmartScreen.
The installer is unsigned, so Windows will warn about it; the hash and the committed release
record are the checks that mean something.

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