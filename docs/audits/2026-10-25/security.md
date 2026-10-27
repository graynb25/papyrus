# Security and privacy assessment, phase 3, 2026-10-25

Status: complete. Read-only assessment of HEAD `6572ba0` on 2026-10-25. One Blocker,
three High, eight Medium, six Low and four Info findings. No code, test, configuration,
install or tracked file was changed by this audit.

Remediation status, 2026-10-27: B1 and M8 are addressed in the local 1.5.0 build (PHP
8.5.12, FrankenPHP 1.13.2, Temurin 25.0.5+8 with the four distrust-listed roots removed).
The install tree passed the Windows Sandbox gate on run 19 (86 checks, 0 failures) and read a
source page through the app proxy on Windows, which is the clean-machine acceptance those
findings needed. The separate manual installer sandbox ran the real setup executable on
2026-10-26: it installed 1.5.0 per-user and the reader flow passed, but the shortcut preflight
hung; the fix is committed in `86e033a`, the artifacts were rebuilt from it, and a fresh manual
shortcut run is pending. It is tracked in [`hardening-plan.md`](hardening-plan.md) and is not part
of B1 or M8.

## Status, method, limitations

Read-only audit of the working tree plus online advisory checks. Every claim in the findings
carries the file and line that answers for it. No live requests were made with a session
cookie, no reader-bearing endpoint was opened, no row of reader data was read, and the
packaged install itself was not run. Nothing was changed, built, installed, started or
stopped, and nothing was committed.

What was read: `routes/web.php`, `routes/settings.php`, `bootstrap/app.php`, the middleware
stack, every HTTP controller the route files name, the cover store, the Suwayomi
integration surface (`AuthManager`, `Transport`, `Jwt`, `Gateway`, `BackupGateway`,
`CbzExporter`), the AniList flow (`OAuth`, `Tracking`, the callback controller, the
connections page), the backup and export pipeline (run models, jobs, libraries, the
snapshot schedule), the import pipeline (`BackupImporter`, `BackupReaders`, the import
page, the extension installer, the Tachibk and Tachimanga readers), the Fortify set-up,
the registration gate, `config/session.php`, `config/filesystems.php`, `config/cache.php`
and `config/papyrus.php`, plus the blade tree scanned for raw output and query-string
binding. `docs/SECURITY.md` was checked claim by claim against all of the above.

What was run: `php -v`, `composer --version`, `node --version`, `npm --version`,
`composer audit` in both summary and JSON form, `npm audit --omit=dev` in JSON form, and
curl HEAD and cookie-free GET probes against the checkout's public, guest-reachable pages
only. Each command's output and exit code is recorded where it is cited. The exact audit
JSON and the probe headers and bodies are in `%TEMP%\opencode\composer-audit-2026-10-25.json`,
`%TEMP%\opencode\npm-audit-2026-10-25.json`, `%TEMP%\opencode\advisories-api-2026-10-25.json`
and `%TEMP%\opencode\guest-probes-2026-10-25\`, not in the tree. No request carried a
session cookie, and no reader-bearing endpoint was opened.

Limitations and honesty notes:

- The browser half is reasoned from code and headers, not from a logged-in browser
  session. Nothing in that half was clicked with a session.
- The packaged runtime was sized by its pinned manifests (`packaging/runtime/*.php`,
  `packaging/suwayomi.json`) and by the audit README's environment table, not by running
  an install.
- Write-side probes (posting to signup, uploading a backup, driving an import, requesting
  an export) are described as designed, not run.

## Dependency audit

Measured from this tree on 2026-10-25, at HEAD `6572ba0`. A version comes from the binary
on PATH or from the pinned manifest named in the row, never from an installed application.
The packaged runtime was not built or launched.

| Component | Version measured | Evidence | Advisory status |
| --- | --- | --- | --- |
| PHP, checkout CLI | 8.4.27 NTS, built 2026-10-20 | `php -v`, exit 0 | 8.4.27 is the 21 October 2026 security release. It fixes CVE-2026-109618 and CVE-2026-109619. CVE-2026-115560 is conditional and does not apply to this tree: see the PHP notes below. No other PHP advisory is in range. |
| PHP, packaged runtime | 8.5.11 ZTS | `packaging/php/php.ini:14-15`, `packaging/frankenphp/Caddyfile:21-22`, audit README environment table | Vulnerable to CVE-2026-109618 and CVE-2026-109619. Fixed in 8.5.12 on 21 October 2026. Blocker B1. |
| FrankenPHP, packaged | 1.13.0, SHA256 `64f4bfef3772b657bc1ec93f9174da87f19ddd0056de01c9a35021ebcf62fb88` | `packaging/runtime/stage-frankenphp.php:51-56` | Four advisories fixed in 1.13.2: GHSA-6q3j-62j9-f9hh, GHSA-fq24-r53r-xcx3, GHSA-ccgp-ch9h-533f, GHSA-4gfw-9v29-gp9h. 1.13.2 ships PHP 8.5.12, so one upgrade closes both the PHP and FrankenPHP halves. Blocker B1. |
| Temurin JRE, packaged | 25.0.4.1+1 | `packaging/runtime/stage-runtime.php:44` | Predates the 20 October 2026 Oracle CPU and the 21 October eMudhra CA distrust. Temurin 25.0.5+8 carries the CPU fixes but not the eMudhra fix; 25.0.5.0.2 is the fixed build, or remove the four roots. Medium M8. |
| Suwayomi, tested version | tested 2.4.2366, minimum 2.4.0 | `packaging/suwayomi.json` | 2.4.2366 was the latest release on 2026-09-30 and has no GitHub security advisory. Info I3. |
| Composer, checkout | 2.10.3 | `composer --version`, exit 0 | The audit README records 2.10.2. The measured binary is 2.10.3. No Composer advisory in range. |
| Node, checkout | 26.7.0, released 2026-08-05 | `node --version`, exit 0; https://nodejs.org/en/blog/release/v26.7.0 | Predates the 21 October 2026 Node security release. 26.12.1 is the fixed 26.x build. The release fixes ten issues in 26.x, including CVE-2026-111497 (high, Brotli decoder RCE) and three high TLS and DNS defects. Build-time only, not shipped. Info I2. |
| npm, checkout | 11.19.0 | `npm --version`, exit 0 | The dependency audit below is the only npm-side check. |
| livewire/livewire | 4.4.7 | `composer audit --format=json`, exit 1 | GHSA-mhf7-xp6r-6qrc, moderate 6.5, affected `>=4.3.0,<4.4.8`, patched 4.4.8. The vulnerable feature is unused in `app/`. Info I1. |
| Vite toolchain | vite 8.3.1, vite-plus 0.3.0, concurrently 10.0.5, oxfmt 0.64.0, vitest and @vitest packages 4.1.11, source-map-js 1.2.1, shell-quote 1.9.0, tinypool 2.1.0 | `package-lock.json`, `npm audit --omit=dev --json`, exit 1 | 11 vulnerable packages, 12 distinct advisories. All are build or test tooling held under `dependencies`; none is loaded by the shipped application. Info I2 and the per-advisory table below. |

`composer audit --format=summary`, exit 1, exact output:

```
Found 1 security vulnerability advisory affecting 1 package.
Run "composer audit" for a full list of advisories.
```

`composer audit --format=json`, exit 1. The one advisory:

| Field | Value |
| --- | --- |
| Package | `livewire/livewire` 4.4.7 |
| Advisory | GHSA-mhf7-xp6r-6qrc, "Authorization bypass in `#[Authorize]` via reordered parameter keys and `#[Json]` execution order" |
| Severity | Moderate, CVSS 3.1 6.5, `AV:N/AC:L/PR:L/UI:N/S:U/C:N/I:H/A:N` |
| Affected | `>=4.3.0,<4.4.8` |
| Patched | 4.4.8 |
| Published | 2026-10-22, advisory database 2026-10-23 |
| Tree check | No `#[Authorize]`, `#[Json]`, `#[Url]` or `#[Route]` attribute on any Livewire class in `app/`. Authorization is done inside methods or by middleware, so the attribute path is not reachable. |
| URL | https://github.com/advisories/GHSA-mhf7-xp6r-6qrc |

`composer audit` exits 1 when it finds an advisory. That exit code is the finding, not a
failed command. The installed version still answers for the advisory until 4.4.8 lands.
Updating is a code change and was deliberately not done by this audit.

`npm audit --omit=dev --json`, exit 1. Metadata:

```
{"info":0,"low":0,"moderate":4,"high":2,"critical":5,"total":11}
```

`npm audit --omit=dev`, exit 1. The complete output is saved verbatim at
`%TEMP%\opencode\npm-audit-2026-10-25.txt` (74 lines). Its summary line is:

```
11 vulnerabilities (4 moderate, 2 high, 5 critical)
```

The count is 11 vulnerable packages, not 11 advisories. Twelve distinct advisories sit
behind those packages because `vite` and `vite-plus` share six and `@vitest/mocker` has two.
All twelve are in the table below. Every package is build or test tooling: the shipped
application has no Node runtime, and none of these packages is required by a reader request.
`npm audit --omit=dev` reports them because `package.json` holds them under `dependencies`
rather than `devDependencies` (`package.json:9-19`). That is a packaging classification
defect, not a reader-runtime exposure. The fix is to move the build toolchain to
`devDependencies` and update it; neither change belongs to the security fix for a reader.

| Advisory | Package | Severity | CVSS in audit | Affected | Patched | Reaches a reader? |
| --- | --- | --- | --- | --- | --- | --- |
| GHSA-qxg6-89mg-rqv8 | `@vitest/mocker` | Moderate | 4.7 | `>=2.1.0-beta.7 <4.1.12` | 4.1.12 | No. Test-only manual mock registration. |
| GHSA-xp26-3454-mjgp | `@vitest/mocker` | Moderate | 5.9 | `>=4.1.11 <4.1.12` | 4.1.12 | No. Test-only Windows path traversal. |
| GHSA-pqg4-j6r4-53mv | `shell-quote` via `concurrently` | Critical | 8.1 | `>=1.8.4 <1.11.0` | 1.11.0 | No. Build script command construction. |
| GHSA-68fv-2mgg-jv7q | `source-map-js` | High | 7.5 | `>=1.0.0 <1.2.2` | 1.2.2 | No. Build-time source map parsing. |
| GHSA-5gmw-xhrv-c9v3 | `tinypool` via `oxfmt` | Critical | not scored | `<=2.1.0` | 2.1.1 or later | No. Formatter worker pool. |
| GHSA-85c8-ppgw-ccpr | `tinypool` via `oxfmt` | Critical | not scored | `<2.1.2` | 2.1.2 | No. Formatter worker pool. |
| GHSA-698v-4r3x-3vcv | `vite`, `vite-plus` | Moderate | not scored | `>=8.3.0 <=8.3.2`, `<=1.0.0` | 8.3.3, 1.0.1 | No. `launch-editor` argument escaping on Windows. |
| GHSA-r825-w8mm-5pxw | `vite`, `vite-plus` | Moderate | not scored | `>=8.3.0 <=8.3.2`, `<=1.0.0` | 8.3.3, 1.0.1 | No. `launch-editor` flag misinterpretation. |
| GHSA-587h-7wmj-h5pm | `vite`, `vite-plus` | High | not scored | `>=8.3.0 <=8.3.2`, `<=1.0.0` | 8.3.3, 1.0.1 | No. `launch-editor` existence check on Windows. |
| GHSA-9jrq-w75r-8gcw | `vite`, `vite-plus` | Low | not scored | `>=8.3.0 <=8.3.2`, `<=1.0.0` | 8.3.3, 1.0.1 | No. Dev server custom middleware. |
| GHSA-rq7h-c2jc-7f22 | `vite`, `vite-plus` | Moderate | not scored | `>=8.3.0 <=8.3.2`, `<=1.0.0` | 8.3.3, 1.0.1 | No. Dev server `server.fs.allow` on non-Windows paths. |
| GHSA-vfpm-58rq-9qcg | `vite`, `vite-plus` | Moderate | not scored | `>=8.3.0 <=8.3.2`, `<=1.0.0` | 8.3.3, 1.0.1 | No. Dev server `?vite-wasm-instance` read. |

Advisory pages, all checked 2026-10-25:

- https://github.com/advisories/GHSA-qxg6-89mg-rqv8
- https://github.com/advisories/GHSA-xp26-3454-mjgp
- https://github.com/advisories/GHSA-pqg4-j6r4-53mv
- https://github.com/advisories/GHSA-68fv-2mgg-jv7q
- https://github.com/advisories/GHSA-5gmw-xhrv-c9v3
- https://github.com/advisories/GHSA-85c8-ppgw-ccpr
- https://github.com/advisories/GHSA-698v-4r3x-3vcv
- https://github.com/advisories/GHSA-r825-w8mm-5pxw
- https://github.com/advisories/GHSA-587h-7wmj-h5pm
- https://github.com/advisories/GHSA-9jrq-w75r-8gcw
- https://github.com/advisories/GHSA-rq7h-c2jc-7f22
- https://github.com/advisories/GHSA-vfpm-58rq-9qcg

### PHP notes

CVE-2026-109618 is an out-of-memory handling defect. PHP uses `longjmp()` to abort a
request that reaches `memory_limit`; some contexts leave request memory uninitialized, and
request cleanup can then treat it as valid. The PHP Group scores it 9.2 critical, and
remote code execution has been demonstrated against Apache with `mod_php` and confirmed
through application-level paths on PHP-FPM. CVE-2026-109619 is a related stale global
state defect: request-owned state can survive after its memory is freed, and a later
request on the same worker can branch on it. The PHP Group also scores it 9.2 critical.
Both are fixed in 8.4.27 and 8.5.12. The checkout's 8.4.27 is patched. The packaged
8.5.11 is not, and is the Blocker.

CVE-2026-115560 is the 23 October session upload progress advisory. It is not fixed by
8.4.27 or 8.5.12. It requires all three of: `session.serialize_handler` set to
`php_binary` or `php_serialize`, a runtime `ini_set()` back to `php` before
`session_start()`, and session upload progress enabled. This tree has none of them:
`config/session.php:231` sets `'serialization' => 'json'`, no `serialize_handler`,
`session_start`, `upload_progress` or `ini_set` call site exists in `app/`, `config/`,
`routes/`, `bootstrap/` or `packaging/`, and `packaging/php/php.ini` does not enable upload
progress. It is recorded as conditional and not applicable, not as a blocker. The
recommended `session.upload_progress.enabled=0` hardening is still cheap and is listed in
the hardening plan.

### FrankenPHP notes

The four 1.13.0 advisories and their reach in this install:

- GHSA-6q3j-62j9-f9hh, high: embedded application extraction. Papyrus is not an embedded
  FrankenPHP application; `packaging/frankenphp/Caddyfile:120-126` runs `php_server` in
  classic mode with a document root. Not applicable.
- GHSA-fq24-r53r-xcx3, medium: queued requests after a client disconnect. This is in the
  request handling path and is treated as applicable until a PoC says otherwise. It is
  closed by 1.13.2 regardless.
- GHSA-ccgp-ch9h-533f, medium: cyclic arrays. The advisory's trigger was not reproduced
  against Papyrus; it is recorded as likely not applicable, and is closed by 1.13.2
  regardless.
- GHSA-4gfw-9v29-gp9h, low: libxml state leak in worker mode. `Caddyfile:95-119` records
  the deliberate choice of classic mode, so the worker-mode path is not used. Not
  applicable.

1.13.2 is the security release that fixes all four and ships PHP 8.5.12. 1.13.3 is the
current bugfix release; the hardening plan should take the newest 1.13.x that still
carries PHP 8.5.12 or later, and re-pin the SHA256. The exact 1.13.3 PHP version was not
re-measured in this session.

### Suwayomi notes

`packaging/suwayomi.json` is the version source of truth. It records tested 2.4.2366 and
minimum 2.4.0. 2.4.2366 was published 2026-09-30 and was the latest release when checked
on 2026-10-25. The GitHub advisory list for the repository was empty. The server is a
separate process on loopback and is not covered by `composer audit` or `npm audit`; the
release and advisory pages are the check. Its JRE is the Medium M8 finding.

## Threat model

### Assets, in the order the code puts them at risk

1. Reader data in the application database: reading progress, chapter notes, reading
   sessions, shelves, series states, insights. Single SQLite file.
2. The application database itself, including the `sessions`, `cache` and `jobs` tables,
   which carry session payloads, the Suwayomi bearer token cache and queue payloads.
3. The AniList OAuth token: one row per reader in `anilist_accounts`, plaintext, acting as
   that reader on AniList.
4. The Suwayomi credentials and bearer tokens: env-set password, JWT access and refresh
   tokens in the database cache.
5. The Suwayomi server instance: one per install, port 4567, loopback, shared by all
   readers in every mode, with its own library cache and data directory.
6. Backup and export products: `.tachibk` archives under the private storage tree, plus
   the reader-half snapshot the daily schedule writes.
7. Session cookies and passkey credentials of the readers.
8. Cover images: reader-chosen art under the `local` disk.

### Trust boundaries

- Browser to Laravel. The checkout serves over plain HTTP on loopback; the packaged
  install serves on localhost over plain HTTP through FrankenPHP. No TLS anywhere in
  either shipped shape, so every cookie about boundary travels unencrypted on the wire
  that a local process can already see.
- Laravel to Suwayomi. One HTTP hop to 127.0.0.1:4567, configured by env. In the shipped
  deployment the server is loopback-bound; moving it creates a network peer that answers
  with Admin(1) under AuthMode.NONE and reflects any origin with credentials.
- Reader browser to Suwayomi. None, by construction: every image and API read goes
  through the same-origin proxy route, and the browser never holds a Suwayomi token.
- Reader file system to Laravel. One uploaded backup file at a time, plus one typed
  server-side path on the import page. After upload, the file crosses
  `BackupReaders`, the Tachimanga and Tachibk parser chain, `ExtensionInstaller` (which
  installs Suwayomi extension packages the backup names) and the series resolver, which
  talks to those extensions' upstream sites.
- Laravel to AniList. Outbound, with a token bought by a reader-side voucher exchange.
- Queue workers to the same database as web requests. Backup, restore, export and import
  run rows are the receipt for work a web click starts and a worker finishes.
- The launcher and its data directory, out of scope as instructed, but named here because
  the application's `.env` and storage tree move into the shared data directory in the
  packaged shape.

### Attacker capabilities by deployment mode

- Single (default, shipped): one reader, registration closed after the first account. The
  realistic attacker set: a process on the same machine, a person at the keyboard, a
  visitor on the loopback network stack, and whatever the packaged installer ships
  vulnerable code to.
- Shared (aspirational, one env change): every reader is a potential attacker against the
  one shared Suwayomi namespace and against run-row keyed endpoints that carry no owner.
  Quotas and abuse controls are declared in `Deployment` and enforced by nothing
  (`Deployment.php:59-66`); `storage_quota_bytes` is written by nothing.
- Public (aspirational): adds strangers to the shared set. Nothing in the tree moderates
  or rate-limits them beyond the Fortify endpoints and the showcase throttle, so public
  today would be shared today's posture, with the same gaps and a larger surface.

### Abuse paths the code answers for, and the ones it does not

Answered: unauthorized file reads through the `local` disk's auto-registered route are
signature-gated (`vendor/.../ServeFile.php:27-30`); the proxy route allowlists only
manga, thumbnail, page and extension icon paths; every cover, proxy page and download
carries a Content-Type; the parser chain refuses on unknown magic bytes; the importer
writes run rows the reader can see.

Not answered: the packaged runtime's two PHP CVEs (Blocker); plaintext authorization
tokens at rest; whole-install restore and export buttons behind plain `auth`; export run
ids fetched by any reader; extension installs into the shared server from an imported
backup; an import page path field that accepts any readable file; guest session rows
growing with every page view; GET routes that record reading state.

## Findings

Ranked by what an attacker gains and how little they need to start. Every finding names the
route or file that answers for it. "1.5.0" means the fix belongs in the next release; "no"
means it can wait or is informational.

### B1. The packaged runtime ships two critical PHP RCEs and an unpatched FrankenPHP

**Severity:** Blocker.
**Route/file:** every request served by the packaged install. `packaging/runtime/stage-frankenphp.php:51-56`,
`packaging/php/php.ini:14-15`, `packaging/frankenphp/Caddyfile:21-22`.

**Expected:** the runtime a reader installs is a PHP release with the current critical
security fixes, and the web server wrapping it is not itself in an advisory range.

**Observed:** the packaged PHP is 8.5.11 ZTS inside FrankenPHP 1.13.0. PHP 8.5.11 predates
the 21 October 2026 fixes for CVE-2026-109618 and CVE-2026-109619, both scored 9.2 critical
and both capable of remote code execution. The checkout's PHP 8.4.27 is the patched release;
the packaged one is not. FrankenPHP 1.13.0 is also in range for four advisories fixed in
1.13.2, including a request-handling issue after a client disconnect.

**Evidence:** `stage-frankenphp.php:51-56` pins `FRANKENPHP_VERSION = '1.13.0'` and its
SHA256. `php.ini:14-15` and `Caddyfile:21-22` both record the runtime as "1.13.0 with PHP
8.5.11, ZTS". The audit README environment table independently records packaged PHP 8.5.11.
The FrankenPHP 1.13.2 release notes list the four fixed advisories and state that its static
binaries carry PHP 8.5.12. The PHP 8.4.27 announcement and the PHP Foundation's 21 October
post name 8.5.12 as the fixed 8.5 release. `php.ini:77-79` sets `memory_limit = 512M`,
`upload_max_filesize = 256M` and `post_max_size = 260M`, so an unauthenticated multipart
request can reach the memory-limit path that CVE-2026-109618 corrupts.

**Impact:** code execution as the Windows user running Papyrus, from a request that needs no
session. That user owns `%LOCALAPPDATA%\Papyrus`, the SQLite database, the Suwayomi data
directory, the backups and the launcher's files. The attacker can read every reader's
library, read the plaintext AniList token and the Suwayomi token cache, and persist by
changing the installed files.

**Preconditions:** a packaged install is running. The attacker can send an HTTP request to
the loopback listener. That includes a malicious page in the reader's browser posting a
form or multipart body to `http://localhost:<port>`, a local process, or another account on
the same machine. The loopback bind does not stop a browser from making the request.

**Existing mitigation:** `Caddyfile:86-88` binds `127.0.0.1 ::1`, `max_threads 8` limits
concurrency, `display_errors = Off` keeps errors out of responses, and the app has no TLS.
None of these fixes memory corruption in PHP. The `post_max_size` ceiling reduces the ease
of the OOM path but does not remove it, because 260M is still a reachable request and
`memory_limit` is 512M.

**Suggested direction:** rebuild with FrankenPHP 1.13.2 or newer, which carries PHP 8.5.12,
re-pin the SHA256 in `stage-frankenphp.php`, rebuild the installer and portable, and re-run
the sandbox gate. Add a build-time assertion that the packaged `php.exe -v` reports at least
8.5.12 and that `frankenphp.exe --version` reports at least 1.13.2, so a future pin cannot
silently regress. This is a dependency upgrade, not an application code change.

**1.5.0:** yes. This is the release blocker.

### H1. Whole-install backup, restore and export actions sit behind plain authentication

**Severity:** High.
**Route/file:** `GET /settings/backups` and its Livewire actions. `routes/settings.php:50`,
`resources/views/pages/settings/⚡backups.blade.php:403-420`, `:707-741`, `:894-917`.

**Expected:** a destructive whole-install action is limited to the install owner, or the
page is unavailable outside `single` mode.

**Observed:** the route is inside the `auth` group and nothing else. `start()` dispatches
`RunLiveBackup` with `auth()->id()`, `restore()` dispatches `RunLiveRestore` with
`auth()->id()` and a token, and `startExport()` dispatches the export job. None of the three
checks a role, an owner, a deployment mode or a gate. The page's own route comment says
"it reads the filesystem and nothing about the reader", which is true of the listing and
false of the actions: a restore replaces the live database for every reader.

**Evidence:** `routes/settings.php:37-50` places the page under `auth` and not `verified`,
with the quoted comment. `⚡backups.blade.php:403-420` shows `start()` dispatching
`RunLiveBackup::dispatch(userId: (int) auth()->id(), ...)`. `:707-728` shows `restore()`
dispatching `RunLiveRestore::dispatch(userId: (int) auth()->id(), token: $this->restoreToken)`.
`:894-917` shows `startExport()`. `app/Jobs/RunLiveRestore.php` restores the pair over the
live database. `app/Backup/ReaderHalfCapture.php:55` captures the whole database with
`VACUUM INTO`, not one reader's rows. No `Gate`, `authorize`, `can`, `isSingle` or owner
comparison appears in the component.

**Impact:** in `shared` or `public` mode, any account that can sign in can replace the live
database with an older or attacker-supplied pair, wiping or rolling back every other
reader's progress, notes, shelves and sessions. The same account can start a backup that
captures every reader's data, and can start an export. The restore is the destructive one;
the backup and export are confidentiality and disk-growth problems.

**Preconditions:** `shared` or `public` mode with registration open, so a second account
exists. In `single` mode the only reader is the owner, so the same actions are not an
escalation.

**Existing mitigation:** `auth` only. Livewire's CSRF protection stops a cross-site press
but not a signed-in account. The page shows a confirmation dialog, which is a usability
guard, not an authorization check.

**Suggested direction:** define an install-owner ability (the first user, or an explicit
owner flag) and require it for backup, restore and export; or gate the whole page to
`single` mode and return 404 elsewhere. Add a two-user test that proves the second account
gets 403 or 404 on each action. The route comment should be corrected in the same change.

**1.5.0:** yes.

### H2. Any authenticated reader can download any finished export by sequential run id

**Severity:** High.
**Route/file:** `GET /settings/backups/export/{run}`. `routes/settings.php:67-69`,
`app/Http/Controllers/ExportDownloadController.php:37`.

**Expected:** the download is limited to the reader who started the run, or to the install
owner.

**Observed:** the controller loads `LiveExportRun::query()->find($run)` and serves the file
if the row is complete, the name is a basename, the file exists and the byte count matches.
It never compares `started_by_user_id` with the signed-in reader. The run id is an
auto-increment integer in the URL. The model is deliberately not an `OwnedModel` because
the row is install-wide, but it does carry `started_by_user_id`, so the ownership fact is
present and unused.

**Evidence:** `ExportDownloadController.php:37` is the unscoped `find`. `app/Models/LiveExportRun.php:15-20`
explains the install-wide row and records `started_by_user_id`; the model extends `Model`,
not `OwnedModel`. `routes/settings.php:67-69` places the route under plain `auth`. The
export job writes the pressing reader's merged read marks and shelves into the file, so the
product is not merely a copy of shared chapter data.

**Impact:** in `shared` or `public` mode, one reader can enumerate completed runs and
download another reader's export. That discloses what the other reader has read, their
shelves and their reading positions. The ids are sequential, so enumeration is trivial.

**Preconditions:** shared or public mode, at least one completed export by another reader,
and a signed-in account.

**Existing mitigation:** the complete-state check, the basename check and the byte-count
check stop path traversal and half-written files. They do not stop a different reader.

**Suggested direction:** scope the query to `started_by_user_id = auth()->id()` unless the
caller is the install owner; return 404 for a row that is not the caller's. Add a two-user
test that proves the second account cannot fetch the first account's run. If exports are
meant to be install-wide, say so on the page and require the owner ability from H1.

**1.5.0:** yes.

### H3. Import auto-installs extensions and bypasses the per-user store trust gate

**Severity:** High.
**Route/file:** `GET /settings/import`, `App\Import\BackupImporter::installExtensions()`,
`App\Import\ExtensionInstaller::install()`. `resources/views/pages/settings/⚡import.blade.php:52`,
`app/Jobs/RunBackupImport.php:72`, `app/Import/ExtensionInstaller.php:152`.

**Expected:** installing an extension is an explicit trust decision, the same one the
sources page enforces. A backup should not be able to name a package and have it run without
that decision.

**Observed:** `installExtensions` defaults to `true` on the import page and in the job.
`ExtensionInstaller` filters the backup's sources and calls `ExtensionManager::installMany($wanted)`
without checking `ExtensionManager::isTrusted()` or any per-user trust record. The manual
sources page does check: `canInstallName()` requires `isTrusted()`, and `isTrusted()` reads
the signed-in user's trusted store ids. The import path has no equivalent.

**Evidence:** `⚡import.blade.php:52` `public bool $installExtensions = true;` and `:911-914`
the checkbox labelled "Install the extensions this backup needs". `RunBackupImport.php:72`
`public readonly bool $installExtensions = true` and `:131` passes it to the importer.
`BackupImporter.php:133` calls `installExtensions(...)`, and `:153-198` builds `$wanted`
from backup sources and calls `installMany`. `ExtensionInstaller.php:152` is the call.
`⚡sources.blade.php:518` and `:1505-1511` are the manual gate. `docs/SECURITY.md:107-108`
claims "Papyrus shows you each extension's signing key and makes trusting it an explicit
decision, so nothing installs itself quietly", which the import path contradicts.

**Impact:** a crafted backup naming a package from an untrusted store causes that package to
be installed and run in the shared Suwayomi server. In `shared` mode, one reader can add a
store (M7) and import a backup that names its package, so the trust boundary between readers
and the shared server is bypassed. The installed extension runs Kotlin with the Suwayomi
process's access to the reader's library and network.

**Preconditions:** an authenticated reader can reach the import page. A store that carries
the named package is registered, or the reader can add one. The backup names the package.
In `single` mode the reader is the owner, so this is a trust-UI defect rather than an
escalation; in `shared` or `public` mode it is cross-reader.

**Existing mitigation:** the import checkbox is visible and can be unticked. That is an
opt-out, not an authorization check, and the default is on. The parser validates the backup
format, not the trust of the packages it names.

**Suggested direction:** make extension installation require the same per-user trust check
as the sources page, for every package, before `installMany` is called. Default the checkbox
to off, or remove automatic installation from import entirely and show the missing packages
with a link to the sources page. Update `docs/SECURITY.md` in the same change and add a test
that a backup naming an untrusted package installs nothing.

**1.5.0:** yes.

### M1. Backup pairs carry live credentials and session identifiers

**Severity:** Medium.
**Route/file:** `GET /settings/backups`; `app/Backup/LiveBackup.php:99`,
`app/Backup/ReaderHalfCapture.php:55`, `app/Backup/BackupLibrary.php:54-65`,
`resources/views/pages/settings/⚡backups.blade.php:1421`.

**Expected:** a backup that a reader is invited to copy is either free of live credentials
or clearly labelled as a secret.

**Observed:** the reader half is a `VACUUM INTO` copy of the whole application database. It
therefore contains the `sessions` table, the `cache` table (where the Suwayomi bearer and
refresh tokens live), the `anilist_accounts` table with the plaintext OAuth token, the
`users` table with password hashes and remember tokens, and every reader's library rows.
The page prints the absolute backup directory and the docs invite the reader to copy the
file. Neither says the copy carries live credentials.

**Evidence:** `ReaderHalfCapture.php:55` `DB::select('VACUUM INTO ?', [$target])` with no
table filter. `LiveBackup.php:99` names the reader half `reader-<token>.sqlite`.
`BackupLibrary.php:54-65` puts backups beside the database file.
`⚡backups.blade.php:1421` renders `$this->directory()` in full. `config/session.php` and
`config/cache.php` use the database driver, and `config/queue.php` does too, so those tables
are in the same file. `app/Models/AniListAccount.php:39` hides the token from serialization
but does not encrypt the column.

**Impact:** a copied, synced or shared backup grants the holder the reader's AniList account
(until the token expires or is revoked), the Suwayomi session cache, and any still-valid
application session id. It also discloses every reader's library and notes. The risk is
concentrated on backups that leave the machine, which is exactly what the UI invites.

**Preconditions:** the attacker obtains a backup file. That can be a shared folder, a cloud
sync, a support bundle, a copied data directory or another account with filesystem access.

**Existing mitigation:** backups live under the private storage tree and there is no HTTP
route that serves an arbitrary backup file. The restore path verifies the manifest. Neither
is encryption or redaction.

**Suggested direction:** exclude `sessions` and `cache` from the reader half, or replace
them with empty tables in the copy; encrypt `anilist_accounts.access_token` with a `Crypt`
cast (M4); add a sentence beside the backup directory saying the file contains credentials
and should be treated like the database itself. If excluding tables is too invasive for
1.5.0, at minimum document the sensitivity and add the warning.

**1.5.0:** yes, at least the warning and the session/cache exclusion.

### M2. Import accepts any readable server-side path and reflects it in a refusal

**Severity:** Medium.
**Route/file:** `GET /settings/import`, the `path` field.
`resources/views/pages/settings/⚡import.blade.php:271-329`,
`app/Jobs/RunBackupImport.php:98-106`.

**Expected:** import reads an uploaded file, or a file under a directory the application
controls. A missing file is reported without disclosing the server's layout.

**Observed:** if no upload is present, `resolvePath()` trims the `path` field, checks
`is_file()` and `is_readable()`, and returns `realpath($given)`. There is no base-directory
check, no allowlist and no extension check. A missing or unreadable path produces a toast
that contains the full path. The queued job repeats the check and writes
`There is no file at {$this->path} when the import started.` into the refusal row. A file
that exists but is not a backup is refused by the parser after `hash_file()` reads it.

**Evidence:** `⚡import.blade.php:303-329` is the `$given` branch and the
`There is no readable file at :path.` toast. `:284` is the upload branch, which stores under
`imports` on the `local` disk. `RunBackupImport.php:98-106` is the `is_file` check and the
refusal sentence. `BackupImporter.php:101` hashes the path before the format reader sees it.
`BackupReaders` refuses unknown magic bytes, so the path is not a general file-read
primitive.

**Impact:** any authenticated reader can test whether an arbitrary server-side path exists
and is readable, and can learn the full path in the response. In `shared` mode this is a
filesystem disclosure to another account. It is not confirmed content exfiltration: the
parser refuses non-backup formats, and no response returns file bytes. The oracle is still
useful for locating the database, backups, logs and configuration.

**Preconditions:** an authenticated account that can reach the import page.

**Existing mitigation:** the format parser refuses non-backup files, and the import runs as
the signed-in user. The path is read, not returned as content.

**Suggested direction:** remove the free-text path field, or confine it to the imports
directory with a `realpath` prefix check. Redact the path in the toast and the refusal row
to the basename. If a server-path import is a real workflow, make it owner-only.

**1.5.0:** yes.

### M3. The backup parsers have no uncompressed size or memory cap

**Severity:** Medium.
**Route/file:** `GET /settings/import`; `app/Import/Tachimanga/TachimangaReader.php:240-276`,
`app/Import/Tachibk/TachibkStream.php:349-370`.

**Expected:** a reader-supplied archive is decompressed and parsed under a bound that
protects the worker's memory and the disk.

**Observed:** the Tachimanga reader streams `contents.zip` and the inner SQLite file to
temporary files with `stream_copy_to_stream()` and no uncompressed byte limit. The Tachibk
reader's `take($size)` builds a PHP string of the requested length, so a crafted length
field can ask for an allocation far larger than `memory_limit`. The only ceiling is the
256M compressed upload limit in `php.ini`; the packaged runtime has `memory_limit = 512M`
and stages temp files under `%TEMP%`.

**Evidence:** `TachimangaReader.php:248` creates the temp file, `:269` copies the stream,
and `:185-189` cleans up in `finally`. `TachibkStream.php:349-370` is `take()`, which
appends chunks until `strlen($value) < $size` is false. `php.ini:77-79` is the 512M/256M/260M
sizing. `⚡import.blade.php:274` checks the compressed size only.

**Impact:** a crafted backup can exhaust the PHP worker's memory or fill the temp drive.
The worker dies, the import row is left failed or abandoned, and a shared install can be
denied service by one account. Disk exhaustion can also affect the SQLite database and the
operating system.

**Preconditions:** an authenticated account that can upload or point at a backup. The
compressed file must be under 256M.

**Existing mitigation:** the 256M upload ceiling, the temp-file `finally` cleanup and the
parser's format checks. None bounds the uncompressed output or a single field.

**Suggested direction:** cap the uncompressed bytes copied per entry and the total staged
bytes; cap the Tachibk field length before allocating, and reject a length larger than the
remaining input or a configured maximum; run imports in a separate process with a memory
and disk quota. Add a test with a small compressed archive that expands past the cap.

**1.5.0:** yes.

### M4. AniList OAuth tokens are stored in plaintext and are not revoked on disconnect

**Severity:** Medium.
**Route/file:** `GET /anilist/callback`, `app/AniList/Tracking.php:72-93`,
`app/Models/AniListAccount.php:29-47`.

**Expected:** a bearer token that acts as the reader on another service is encrypted at
rest, and disconnecting invalidates it.

**Observed:** `anilist_accounts.access_token` is a plain `text` column. The model has
`#[Fillable]` including `access_token`, `protected $hidden = ['access_token']`, and no
`encrypted` cast. `Tracking::connect()` deletes the previous row and creates a new one with
the raw token. `Tracking::disconnect()` deletes the row and makes no remote revocation
call. The token is read on every tracking write and on the settings page's expiry display.
The expiry is enforced only reactively: a 401 or 403, or a GraphQL "invalid token" or
"unauthorized" message, deletes the row.

**Evidence:** `AniListAccount.php:29-34` is the fillable list, `:39` is `$hidden`, and
`:41-47` is the cast list, which has `token_expires_at` but not `access_token`.
`Tracking.php:72-81` is the create, `:88-93` is the delete. `Client.php:220-262` sends the
bearer token and handles 401/403 and the GraphQL error path. `OAuth.php:23-26` records that
AniList issues no refresh token. `AniListCallbackController.php:24-57` stores the token
after the state check.

**Impact:** anyone who obtains the database or a backup (M1) gets a bearer token that acts
as the reader on AniList for up to a year. Disconnecting in Papyrus does not revoke the
token on AniList's side, so a token copied before disconnect keeps working. The `hidden`
property only keeps the token out of serialized output; it does nothing at rest.

**Preconditions:** the reader has connected AniList. The attacker obtains the database or a
backup.

**Existing mitigation:** `$hidden`, the state check on the callback, and the reactive
401/403 deletion. None protects the token at rest or revokes it remotely.

**Suggested direction:** add an `encrypted` cast to `access_token` and migrate existing
rows; call AniList's token revocation endpoint on disconnect if one is available (the OAuth
documentation should be checked before promising it); document that a database copy carries
the token. If revocation is not available, say so plainly on the connections page.

**1.5.0:** yes for the cast and the documentation; revocation if the API supports it.

### M5. Privacy copy and the outbound list under-disclose AniList lookups, mail and cookies

**Severity:** Medium.
**Route/file:** `/privacy`, `/faq`, `/search`.
`app/Support/Outbound.php:49-64`, `app/Support/Legal.php:60-99`,
`app/AniList/Client.php:43`, `app/Search/TitleResolver.php:157-191`.

**Expected:** the privacy page lists every outbound payload and every cookie the reader
will encounter, and the outbound list is the single source of that list.

**Observed:** `Outbound::routes()` lists three things: AniList "if you connect your AniList
account", the manga source, and the extension repository. It says AniList receives the
series and chapter only once connected. The search page's AniList matching sends titles to
`graphql.anilist.co` without a connection, when the reader turns the matching on. The
privacy page says "This application collects nothing", "Nothing", "Three things, and all
three are things you set up yourself", and "no cookie". Guest pages set a session cookie
and an XSRF cookie, and the database session driver writes a session row. Mail is a fourth
outbound path when a shared or public operator configures a transport.

**Evidence:** `Outbound.php:53-54` is the AniList entry and its "Only once you have
connected it yourself" sentence. `Legal.php:60` is the promise, `:65` is "Nothing", `:74` is
"Three things", `:99` is "no cookie". `Client.php:43` is `https://graphql.anilist.co`;
`TitleResolver.php:157-191` builds the search query from the reader's titles.
`⚡search.blade.php:950-953` carries an explicit third-party warning, so the search page
itself is honest even though the privacy page is not. `Legal.php:82-83` describes mail, but
the outbound list does not include it. The guest probes measured `papyrus-session` and
`XSRF-TOKEN` on `/privacy`, `/terms`, `/faq`, `/showcase` and `/login`.

**Impact:** a reader cannot make an informed choice about the AniList lookup, the cookies
or the mail path from the page that is supposed to answer that question. The "collects
nothing" promise is false for the account, library, notes and sessions the app stores. This
is a privacy and trust defect, not a code execution path.

**Preconditions:** the reader uses AniList matching, or the operator configures mail, or any
guest loads a public page.

**Existing mitigation:** the search page's warning, the `Legal` docblock's acknowledgement
that "AniList sees the titles that are searched", and the default `MAIL_MAILER=log`. None of
these reaches the rendered privacy page.

**Suggested direction:** add the AniList lookup and mail to `Outbound::routes()`, with the
same who-initiated wording; correct "collects nothing" to name the local data the app
stores; replace "no cookie" with the session and XSRF cookies and their flags; add a test
that the rendered privacy page and the outbound list agree.

**1.5.0:** yes.

### M6. `docs/SECURITY.md` contains three claims the tree does not support

**Severity:** Medium.
**Route/file:** `docs/SECURITY.md:107-108`, `:124-126`, `:143`.

**Expected:** the security page an operator reads matches the code.

**Observed:** three claims are false or incomplete.

1. `docs/SECURITY.md:143` says "No application route is rate limited. Only the Fortify
   endpoints are." The public FAQ, privacy, terms and showcase routes carry
   `throttle:120,1` (`routes/web.php:57`, `:75`, `:79`, `:83`). The showcase cover route is
   deliberately not throttled, which the route comment explains.
2. `docs/SECURITY.md:107-108` says extension trust is an explicit decision and "nothing
   installs itself quietly". The import path defaults to installing extensions and does not
   check the per-user trust record (H3).
3. `docs/SECURITY.md:124-126` says the proxy forwards "only `format` and `updateProgress`".
   `SuwayomiProxyController.php:57` also allows `width` and `height`.

**Evidence:** the route middleware in `routes/web.php`; the import defaults in H3; the
query allowlist in `SuwayomiProxyController.php:57`.

**Impact:** an operator who reads the page will believe public routes are unthrottled (a
denial-of-service assumption), that imports cannot install untrusted code (H3), and that the
proxy is narrower than it is. The extension claim is the serious one because it hides a
real trust bypass.

**Preconditions:** none. This is a documentation defect.

**Existing mitigation:** none. The page has no test that compares it with the code.

**Suggested direction:** correct the three sentences, and add a small test or a doc check
for the rate-limit and proxy-allowlist claims where the values are stable enough to assert.
The extension sentence should be rewritten after H3 is fixed, not before.

**1.5.0:** yes.

### M7. Any authenticated reader can make Suwayomi fetch an arbitrary store URL

**Severity:** Medium.
**Route/file:** `GET /sources`, `addStore()`.
`resources/views/pages/⚡sources.blade.php:191-226`,
`app/Suwayomi/SourceCatalog.php:72-101`.

**Expected:** a URL that the server will fetch is checked against a scheme and a host
policy, or the action is owner-only.

**Observed:** `addStore()` validates `required|string|url|max:500` and passes the string to
`SourceCatalog::addStore()`, which sends it as the `indexUrl` of Suwayomi's
`addExtensionStore` mutation. There is no private-address, link-local, loopback or scheme
check in either place. Suwayomi performs the fetch. The response is parsed as an extension
store index, so this is not a general-purpose read proxy, but the request is made from the
Suwayomi process with its network position.

**Evidence:** `⚡sources.blade.php:191-196` is the validation and the call.
`SourceCatalog.php:72-101` is the mutation and the mirror write. The sources page is under
`auth` and `verified` (`routes/web.php:183`), so any verified account can reach it.

**Impact:** in `shared` or `public` mode, one reader can make the shared Suwayomi server
send requests to internal addresses, including loopback services and private network
hosts, and can use the success or failure of the store fetch as a reachability oracle. The
protobuf parser limits what can be read back, so this is request forgery and port scanning
rather than arbitrary response exfiltration.

**Preconditions:** a verified account. The target must speak enough HTTP for Suwayomi's
fetch to produce a distinguishable result.

**Existing mitigation:** the `url` validation rejects non-URL strings; Suwayomi is
loopback-bound, which limits who can reach it but not what it can reach.

**Suggested direction:** resolve the host before sending it, reject loopback, link-local,
private and multicast addresses, restrict the scheme to http and https, and consider
rejecting redirects to those addresses. If the store URL is meant to be an owner decision,
move the action behind the owner ability from H1.

**1.5.0:** yes.

### M8. The packaged JRE predates the October 2026 CPU and the eMudhra CA distrust

**Severity:** Medium.
**Route/file:** `packaging/runtime/stage-runtime.php:44`, the JRE copied into
`suwayomi/runtime` by `packaging/runtime/build-install.php`.

**Expected:** the JRE that Suwayomi runs on carries the current Oracle CPU and distrusts the
compromised eMudhra intermediates.

**Observed:** the pin is Temurin 25.0.4.1+1, released 2026-08-19. It predates the 20 October
2026 Oracle CPU and the 21 October eMudhra CA advisory. Temurin 25.0.5+8, released
2026-10-22, carries the CPU fixes but its tag predates the eMudhra certificate change;
25.0.5.0.2 is the build that blocks the compromised intermediates. The alternative is to
remove the four roots (`emsignrootcag1`, `emsignrootcag2`, `emsigneccrootcag3`,
`comodoaaaca`) from the packaged `cacerts`.

**Evidence:** `stage-runtime.php:44` `JRE_VERSION = '25.0.4.1+1'` and its SHA256.
`build-install.php` copies the JRE into `suwayomi/runtime`. `packaging/suwayomi.json`
records the Suwayomi version that runs on it. Oracle's 21 October alert is CVE-2026-91403;
OpenJDK's own page also cites CVE-2026-114820 for the same distrust change. The OpenJDK
advisory lists 25.0.5 and earlier as affected and 25.0.5.0.2 as fixed.

**Impact:** Suwayomi's outbound TLS to manga sources can be intercepted with a certificate
chaining to a compromised eMudhra intermediate, which exposes source credentials and
session cookies to a network attacker. The JRE CPU fixes also cover memory-safety and
sandbox issues that a malicious extension or a crafted network response could reach. The
exposure is narrower than B1 because Suwayomi is loopback-bound and its outbound hosts are
the reader's chosen sources, but the JRE is shipped code.

**Preconditions:** an attacker can intercept Suwayomi's outbound TLS, or can run code in
Suwayomi (an extension, which is already code execution in that process).

**Existing mitigation:** the Suwayomi listener is loopback-bound, and extensions are
reader-chosen. Neither fixes the JRE's trust store.

**Suggested direction:** update the JRE pin to 25.0.5.0.2, or apply the four-root removal
to the staged `cacerts` and document it. Re-run the Suwayomi smoke test and the sandbox gate
after the change. The pin and SHA256 must move together.

**1.5.0:** yes.

### L1. The login showcase publishes library titles to guests in single mode

**Severity:** Low.
**Route/file:** `GET /showcase`. `app/Http/Controllers/ShowcaseController.php:147-181`.

**Expected:** the signed-out cover wall shows art, not a readable list of the reader's
titles.

**Observed:** the JSON payload is `{"covers":[{"manga":...,"title":"...","url":"..."}]}`.
The controller gates on `papyrus.showcase.enabled` (default true) and `single` mode, and
then returns the primary reader's in-library series, favourites first, up to
`papyrus.showcase.count` (18 by default). A guest probe measured 18 entries and 2,670 bytes
on the checkout. The titles were not reproduced in this report.

**Evidence:** `ShowcaseController.php:147-181` builds the title field. `:106-110` is the
gate. The guest probe is `%TEMP%\opencode\guest-probes-2026-10-25\showcase.body.bin` and
`summary.json`.

**Impact:** anyone who can reach the loopback listener, or a misconfigured proxy in front of
it, sees the reader's collection titles without signing in. In the shipped single-reader
install the collection has one owner, which is the controller's stated reason for allowing
the wall at all, but a title list is more revealing than cover art and is not needed for the
visual effect.

**Preconditions:** `single` mode, showcase enabled, and a network path to the listener. The
packaged install binds loopback, so the realistic path is a local process or a proxy.

**Existing mitigation:** the single-mode gate, the enable switch, the loopback bind and the
120/minute throttle on the payload route.

**Suggested direction:** drop the `title` key from the payload and keep the cover URL and
manga id, or default `showcase.enabled` to false. The login page can render the wall from
the covers alone.

**1.5.0:** yes, the payload change is small.

### L2. The readiness gate trusts `REMOTE_ADDR` and cannot see past a same-host proxy

**Severity:** Low.
**Route/file:** `GET /ready`. `app/Http/Controllers/ReadinessController.php:69-96`.

**Expected:** the readiness endpoint answers only the launcher on the same machine.

**Observed:** the controller returns 404 unless `REMOTE_ADDR` is `127.0.0.1`, `::1` or a
`127.` address. That is correct for a direct connection and wrong for a same-host reverse
proxy: every remote client arrives with the proxy's loopback address, so every remote client
gets the 200 body. The body reports whether the app key is set, whether the database
answers and how many migrations are pending. The guest probe measured 200 from localhost;
a remote 404 was not simulated.

**Evidence:** `ReadinessController.php:69-84` is `isLoopback()`, `:91-96` is the response.
The route is registered outside the `web` group so it starts no session, which the
controller's docblock explains. The audit README's `/ready` 200 was measured on the
checkout, not through a proxy.

**Impact:** an operator who puts Papyrus behind a same-host reverse proxy exposes readiness
state to remote clients. The state is not a credential, but it tells an attacker whether the
app key is missing and whether migrations are pending, which is useful for timing an attack.
The walk's description of `/ready` as public is imprecise; it is loopback-gated, with this
proxy caveat.

**Preconditions:** the operator adds a same-host reverse proxy or otherwise terminates the
connection on the same host.

**Existing mitigation:** the `REMOTE_ADDR` check, the 404 rather than 403, and the absence
of a session.

**Suggested direction:** require a launcher secret in a header, or bind readiness to a
separate loopback-only listener that the proxy does not forward. If the proxy shape is
supported, document that `REMOTE_ADDR` is not a client identity and add a trusted-proxy
configuration.

**1.5.0:** partial. Document the caveat now; the token or separate listener can follow.

### L3. Imported uploads are never deleted

**Severity:** Low.
**Route/file:** `GET /settings/import`. `resources/views/pages/settings/⚡import.blade.php:284`.

**Expected:** the uploaded backup is removed after the run, or retained under a documented
retention policy.

**Observed:** an uploaded file is stored with `$this->backup->store('imports', 'local')` and
its absolute path is handed to the queued job. No job, importer or command deletes it. The
file remains under `storage/app/private/imports` indefinitely. Each import leaves another
copy, and the copy contains the credentials described in M1.

**Evidence:** `⚡import.blade.php:284` is the store call. `RunBackupImport` and
`BackupImporter` have no `unlink` or `delete` for the path; the only `unlink` calls in the
import area are the Tachimanga temp files. The `local` disk root is
`storage/app/private` (`config/filesystems.php:33-39`).

**Impact:** disk growth and retained copies of reader backups. A reader who imports three
files leaves three full backups, each carrying the database's credentials and library. On a
small drive this can also contribute to the disk pressure in M3.

**Preconditions:** one or more imports through the upload field.

**Existing mitigation:** the private storage tree and the OS file permissions. There is no
retention job.

**Suggested direction:** delete the uploaded file in a `finally` after the job reads it, or
add a retention command that removes imports older than a configured window. If the file is
kept for a retry, say so on the page and bound the retention.

**1.5.0:** yes, the cleanup is small.

### L4. Guest pages set a session cookie and a database session row

**Severity:** Low.
**Route/file:** `/privacy`, `/terms`, `/faq`, `/showcase`, `/login`.

**Expected:** a static public page does not need a session, and a guest page does not need a
database write.

**Observed:** guest responses set `papyrus-session` (HttpOnly, SameSite=Lax, no Secure over
HTTP) and `XSRF-TOKEN` (SameSite=Lax, not HttpOnly). With `SESSION_DRIVER=database`, each
guest page view writes a session row. The public pages carry `throttle:120,1`, and the
limiter also writes. The showcase payload route is throttled; the cover route is not, which
the route comment explains as a deliberate trade to avoid 500s under the 18-cover burst.

**Evidence:** the guest probe headers in
`%TEMP%\opencode\guest-probes-2026-10-25\*.headers.txt`; `config/session.php:172` (secure
from env, unset), `:185` (HttpOnly true), `:202` (SameSite lax); `config/session.php` and
`config/cache.php` use the database driver. `routes/web.php:56-88` is the public route set.

**Impact:** a public install accumulates session rows for guests and hands every guest a
session identifier. The cookie is HttpOnly and SameSite=Lax, so the direct session-theft
risk is low, and there is no Secure flag because there is no TLS. The database growth is the
more concrete cost.

**Preconditions:** any guest traffic on a public page.

**Existing mitigation:** HttpOnly, SameSite=Lax, the throttle, and the absence of a Secure
flag that would be meaningless over HTTP.

**Suggested direction:** make the static public pages stateless, or use a cookie-less
limiter for them. If the session is needed for Livewire, document why and add a retention
job for expired sessions. Set `SESSION_SECURE_COOKIE=true` when a TLS proxy is used.

**1.5.0:** no, unless the public mode is promoted.

### L5. The reader GET creates a new reading session on every open

**Severity:** Low.
**Route/file:** `GET /read/{manga}/{chapter}`.
`app/Http/Controllers/ReaderController.php:69-84`, `:110-118`,
`app/Reader/ReadingSessions.php:68-90`.

**Expected:** opening the same chapter twice does not create two reading sessions.

**Observed:** the reader route calls `openChapter()` and `openFor()`, and `ReadingSessions`
closes any open row for the reader and then creates a new row. A reload, a prefetch, a
back-button restore or a retry creates another row. The progress POST is a separate write,
so a page that opens and closes without progress still leaves a session.

**Evidence:** `ReaderController.php:69-84` is the open path and `:110-118` is
`recordProgress`. `ReadingSessions.php:68-90` is the close-then-create. The route is a GET
(`routes/web.php:211-213`), so link previews and browser prefetch can reach it.

**Impact:** reading-session rows and the insights that count them are inflated. A prefetch
can mark a chapter as opened before the reader actually reads it. This is a data-integrity
defect, not a confidentiality or authorization one.

**Preconditions:** any reader opening a chapter, with a reload or prefetch.

**Existing mitigation:** none beyond the incognito guard, which suppresses the write for
incognito readers.

**Suggested direction:** dedupe within a short window (for example, reuse an open row for
the same chapter within the session), or create the session on the first progress POST
rather than on the GET. Add a test that two GETs in a row produce one session.

**1.5.0:** no. It is a data-quality fix, not a security fix.

### L6. The application sends no security headers

**Severity:** Low.
**Route/file:** all responses. `bootstrap/app.php`, the controllers, the guest probes.

**Expected:** a browser-facing application sends at least `Content-Security-Policy`,
`X-Frame-Options` or `frame-ancestors`, `Referrer-Policy` and `Permissions-Policy`, and
`Strict-Transport-Security` when TLS is present.

**Observed:** the guest probe responses carry no CSP, no `X-Frame-Options`, no
`Referrer-Policy`, no `Permissions-Policy` and no HSTS. The checkout also sends
`X-Powered-By: PHP/8.4.27` and `Server: nginx/1.25.2`. The packaged `php.ini` sets
`expose_php = Off`, but the packaged headers were not measured. Some responses do carry
`X-Content-Type-Options: nosniff` (the export download and the cover and proxy paths), and
the cover 404 is `private, no-store`.

**Evidence:** the guest probe headers; `bootstrap/app.php` has no header middleware; the
controllers set only their own content types. `packaging/php/php.ini:111` is `expose_php =
Off`.

**Impact:** there is no clickjacking or framing defense beyond SameSite=Lax, which already
keeps authenticated cookies out of a cross-site iframe. There is no CSP as a second line
against an injected script, and no Referrer-Policy to keep reader URLs out of outbound
Referer headers. The checkout's version headers help an attacker fingerprint the stack; the
packaged headers may not.

**Preconditions:** an attacker can frame the app, inject a script, or read a Referer. The
SameSite cookie limits the framing impact for authenticated pages.

**Existing mitigation:** SameSite=Lax, HttpOnly session cookies, escaped Blade output and
`nosniff` on the file-serving paths.

**Suggested direction:** add a small middleware that sets `Content-Security-Policy` with
`frame-ancestors 'none'`, `Referrer-Policy: same-origin`, `Permissions-Policy` with the
features the app does not use, and `X-Content-Type-Options: nosniff` globally. Add HSTS only
when the request is secure. Test the headers on a guest page and an authenticated page.

**1.5.0:** yes, the middleware is small and low-risk.

### I1. The Livewire advisory is not reachable in this tree

**Severity:** Info.
**Route/file:** `composer audit`, `app/`.

**Expected:** an advisory in a locked package is assessed against the code that uses it.

**Observed:** `livewire/livewire` 4.4.7 is affected by GHSA-mhf7-xp6r-6qrc, but the
vulnerability requires `#[Authorize]` on a method with a model parameter or on a `#[Json]`
method. No `#[Authorize]`, `#[Json]`, `#[Url]` or `#[Route]` attribute appears on any
Livewire class in `app/`. Authorization is done inside methods or by route middleware.

**Evidence:** the `composer audit` JSON and a scan of `app/` for the four attributes.

**Impact:** none demonstrated. The package still answers for the advisory until 4.4.8, so a
future use of `#[Authorize]` would make it reachable. Updating is cheap and should happen
with the 1.5.0 dependency pass.

**Preconditions:** a future change adds the vulnerable attribute pattern.

**Existing mitigation:** the current code does not use the feature.

**Suggested direction:** update to 4.4.8 in the 1.5.0 dependency pass and keep the attribute
scan as a test or a review note.

**1.5.0:** yes, the update.

### I2. The Node and npm advisories are build and test tooling only

**Severity:** Info.
**Route/file:** `package.json`, `package-lock.json`, `npm audit`, the `node` binary on PATH.

**Expected:** a reader-facing runtime has no vulnerable Node package and the build machine
is not running a Node release with open security fixes.

**Observed:** the npm tree has 11 vulnerable packages and 12 advisories, all in the Vite,
Vitest, oxfmt, concurrently and source-map toolchain. The Node binary on PATH is 26.7.0,
released 2026-08-05, which predates the 21 October 2026 Node security release. That release
fixes ten issues in 26.x, including CVE-2026-111497 (high, Brotli decoder RCE),
CVE-2026-105482 (high, DNS name truncation), CVE-2026-105483 and CVE-2026-106069 (high, TLS
hostname bypass). Node 26.12.1 is the fixed 26.x build. The shipped application is PHP and
does not run Node. The packages are under `dependencies`, which is why
`npm audit --omit=dev` reports them.

**Evidence:** the per-advisory table above, `package.json:9-19`, `node --version` and the
Node release notes at
https://nodejs.org/en/blog/vulnerability/october-2026-security-releases and
https://nodejs.org/en/blog/release/v26.7.0.

**Impact:** none on a reader. A developer or build machine that runs the toolchain is
exposed to the npm and Node issues until both are updated.

**Preconditions:** running the build or test toolchain on a machine with the vulnerable
versions.

**Existing mitigation:** the toolchain and the Node binary are not part of the shipped
runtime.

**Suggested direction:** move the toolchain to `devDependencies`, update to the patched
versions in the table, update Node to 26.12.1 or later on the build machine, and re-run
`npm audit --omit=dev` to confirm it is clean. This is a maintenance task, not a
reader-facing security fix.

**1.5.0:** yes, as part of the dependency pass.

### I3. Suwayomi 2.4.2366 is current and has no published advisory

**Severity:** Info.
**Route/file:** `packaging/suwayomi.json`.

**Expected:** the tested Suwayomi version is a current release with no open security
advisory.

**Observed:** 2.4.2366 was published 2026-09-30 and was the latest release on 2026-10-25.
The repository's GitHub advisory list was empty. The minimum is 2.4.0, which is a
compatibility floor, not a security floor; an older install at the minimum has not been
assessed here.

**Evidence:** `packaging/suwayomi.json`; the Suwayomi releases and advisory pages checked
2026-10-25.

**Impact:** none at the tested version. The JRE it runs on is M8.

**Preconditions:** none.

**Existing mitigation:** the version pin and the compatibility check.

**Suggested direction:** keep the pin current and re-check the advisory list at each
release. Do not raise the minimum without a compatibility test.

**1.5.0:** no change needed.

### I4. Positive controls verified during the read

**Severity:** Info.
**Route/file:** `vendor/laravel/framework/src/Illuminate/Filesystem/ServeFile.php`,
`app/Http/Controllers/SuwayomiProxyController.php`, `app/Http/Middleware/RegistrationMustBeAllowed.php`,
`app/Http/Controllers/CoverController.php`, `packaging/runtime/build-install.php`.

**Expected:** the controls the app claims are present and do what they say.

**Observed:** the following held under inspection:

- `/storage/{path}` is signature-gated by Laravel's `ServeFile` and `ReceiveFile`; the
  `local` disk is private and `serve` is true (`config/filesystems.php:33-39`). An unsigned
  `/storage/` probe returned 404.
- The Suwayomi proxy allowlists only `manga/\d+(/chapter/\d+/page/\d+|/thumbnail)` and
  `extension/icon/[A-Za-z0-9._-]+`, and forwards only `format`, `updateProgress`, `width`
  and `height` (`SuwayomiProxyController.php:39-57`). The `width` and `height` entries are
  the M6 documentation drift, not a proxy hole.
- Registration is closed in `single` mode: `RegistrationMustBeAllowed` intercepts the
  `register` and `register.store` route names, and `/register` returned 404 in the guest
  probe.
- The cover route is per-user, returns `private, no-store` on a 404 and
  `private, max-age=300` with an ETag on success (`CoverController.php`).
- The build uses an allowlist for application files, denies `database/database.sqlite*`,
  empties `storage/` and verifies it is empty, and scans authored files for the builder's
  account name, the checkout folder name and RFC1918 addresses
  (`packaging/runtime/build-install.php:1163-1357`). It is a machine-identity scan, not a
  general secret scanner: `.env` is excluded by name and database files by path.
- The nine `Log::` call sites do not log tokens, passwords or full reader paths; the
  Suwayomi `AuthManager` logs only the exception message, and the GraphQL gateway logs
  errors at debug.

**Evidence:** the files and probes named above. The build scan was read, not run.

**Impact:** none; these are the controls that worked. They are recorded so the hardening
plan does not "fix" them and so the next audit can tell a regression from a known gap.

**Preconditions:** none.

**Existing mitigation:** the controls themselves.

**Suggested direction:** keep the signature gate, the proxy allowlist and the build
allowlist. Add tests where they are missing, especially the build's storage-empty check and
the proxy query allowlist.

**1.5.0:** no change.

## Outbound hosts and payloads

This is the complete set of network destinations the code can reach, what each receives, and
who starts the request. It is wider than `Outbound::routes()`, which lists three reader
choices and omits the AniList title lookup and mail; M5 records that copy gap.

| Destination | Request | Payload | Started by | Notes |
| --- | --- | --- | --- | --- |
| `anilist.co` | Browser navigation to `/api/v2/oauth/authorize`, then a server form POST to `/api/v2/oauth/token` | Authorize: `client_id`, `redirect_uri`, `response_type=code`, `state`. Token: `grant_type`, `client_id`, `client_secret`, `redirect_uri`, `code` | Reader presses Connect in Settings | `OAuth.php:30-32`, `:54-61`, `:74-115`. The client secret is application-level, not reader data. The token exchange is server to server over HTTPS. |
| `graphql.anilist.co` | POST JSON `{query, variables}` | Lookup: `variables.search` is the series title, with no account token and no reader id. Tracking: `Authorization: Bearer <token>`, `mediaId`, `progress`, `status`. Viewer: the token only | Search with AniList matching on, AniList import, or tracking after a chapter is read | `Client.php:43`, `:78-104`, `:114-142`, `:220-274`; `TitleResolver.php:157-191`; `Tracking.php:205-212`. AniList sees the title, the reader's IP address, the user agent and the timing. |
| `SUWAYOMI_URL`, default `http://127.0.0.1:4567` | POST `/api/graphql`; page and thumbnail paths are fetched through the app proxy | GraphQL `{query, variables}` with the Suwayomi bearer token when one is configured. Search terms, source filters, series and chapter ids, library and progress updates, extension names and store URLs, backup and restore operations. The login mutation carries `username` and `password` when UI_LOGIN is configured | Every library, search, reader, source, import and backup action; the login is application initiated | `Transport.php:25-38`; `Gateway.php:155-169`; `AuthManager.php:104-132`, `:175-195`. This hop stays on loopback. Suwayomi holds the reader's library and the source credentials. |
| Manga source sites | HTTP or HTTPS requests made by the source extension inside Suwayomi | Search terms, series and chapter requests, page requests, and whatever cookies or credentials the extension holds | Reader searches, browses, reads or downloads | Papyrus does not contact these hosts directly. The source site sees Suwayomi's address, not the browser's. `Outbound.php:57-58` describes this hop. |
| Extension repository | HTTPS fetch by Suwayomi of the store index and the extension package | Store index URL and package name | Reader installs a source or adds a store; Suwayomi re-reads every registered store index at startup | `SourceCatalog.php:72-101`; `ExtensionManager::installMany()`; `Outbound.php:61-62`. The URL is reader-supplied and has no host policy (M7). |
| Mail transport | SMTP or another Laravel mailer, only when the operator configures one | Recipient address, verification or password reset link | Registration or password reset in `shared` or `public` mode | `.env.example:50` defaults to `log`, which writes to a local file and sends nothing. A default single install auto-verifies its only account and sends no mail (`CreateNewUser.php:34-79`). |

What is not an outbound path:

- `lhtranslation.net` and `tachimanga.app` appear in import fixture data, not in a request
  Papyrus makes. Suwayomi may contact a source host after a reader imports and reads that
  source, which is the manga-source row above.
- The SQS endpoint in `config/queue.php` is an unused default. `QUEUE_CONNECTION=database`
  in `.env.example` and in `ConfigureInstall`, so no SQS request is made.
- The Laravel starter-kit links in the app header are ordinary links. Nothing is fetched
  from them until the reader clicks one.
- There is no analytics, telemetry, crash reporting, error reporting service, third-party
  JavaScript or font CDN in the tree. `Outbound.php:15-16` states the same, and the scan for
  outbound calls found only the destinations above.

The privacy consequence is in M5. The AniList lookup can happen without a connection when
the reader turns on search matching, so the outbound list's "Only once you have connected
it yourself" is false for that path. Mail is a fourth path when an operator configures a
transport. Suwayomi is local, but it is the component that contacts the reader's chosen
sources, and a reader can have many of them.

## Packaging and privacy review

### Runtime pins and web server

- FrankenPHP 1.13.0, PHP 8.5.11 ZTS and Temurin JRE 25.0.4.1+1 are the shipped pins. The
  first two are B1; the JRE is M8.
- `packaging/frankenphp/Caddyfile:86-88` binds `127.0.0.1 ::1`. The site addresses are
  written with `http://`, `auto_https off` is set, and there is no TLS listener. `admin off`
  is set, so the Caddy admin endpoint is not exposed.
- `max_threads 8` is a deliberate fixed count (`Caddyfile:28-40`). Classic `php_server` mode
  is used with `try_files`, not worker mode (`Caddyfile:95-126`). The access log records
  errors only, to a file in the data directory (`Caddyfile:132-145`).
- `packaging/php/php.ini` sets `display_errors = Off`, `display_startup_errors = Off`,
  `log_errors = On`, `error_reporting = E_ALL`, `expose_php = Off`, `memory_limit = 512M`,
  `upload_max_filesize = 256M`, `post_max_size = 260M` and `max_execution_time = 0` for long
  imports (`:77-95`, `:111`). Temp and session paths are under the data directory, not the
  system temp (`:107-109`). Opcache is on with timestamp validation (`:135-140`).
- The ini does not set `session.upload_progress` or `session.serialize_handler`. That is the
  reason CVE-2026-115560 is recorded as conditional rather than applicable.
- The checkout headers are not the packaged headers. The checkout sends
  `X-Powered-By: PHP/8.4.27` and `Server: nginx/1.25.2`; the packaged `expose_php = Off`
  should remove the PHP header, but the packaged response was not measured. No CSP,
  `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy` or HSTS was measured on the
  checkout (L6).

### Environment and cookies

- `ConfigureInstall` copies `.env.example` and overrides `APP_ENV=production`,
  `APP_DEBUG=false`, `SESSION_DRIVER=database`, `QUEUE_CONNECTION=database`,
  `CACHE_STORE=database` and `LOG_CHANNEL=stack`
  (`app/Console/Commands/ConfigureInstall.php:61-68`). It generates a 32-byte `APP_KEY` when
  the file has none and never replaces an existing key (`:175-190`). It writes `APP_URL` and
  `SUWAYOMI_URL` when they differ (`:200-211`).
- It does not set `SESSION_SECURE_COOKIE` or a trusted-proxy list. `.env.example` leaves
  `SESSION_SECURE_COOKIE` unset, sets `SESSION_ENCRYPT=false`, `SESSION_LIFETIME=120` and
  `SESSION_DOMAIN=null` (`:30-34`). `config/session.php:172` reads the secure flag from the
  environment, `:185` defaults HttpOnly to true and `:202` defaults SameSite to `lax`.
- With no TLS, the session cookie has no Secure flag. HttpOnly and SameSite=Lax limit theft
  and cross-site sending. L4 records the guest session cost.
- `.env.example` ships `MAIL_MAILER=log`, `PAPYRUS_DEPLOYMENT_MODE=single`,
  `PAPYRUS_SHOWCASE_ENABLED=true` and `SUWAYOMI_URL=http://127.0.0.1:4567` (`:50`, `:78`,
  `:97`, `:101`). A fresh single install closes registration after the first account and
  sends no mail.

### What is stored where

- An installed copy keeps its data in `%LOCALAPPDATA%\Papyrus`. A portable copy keeps it in
  `papyrus-data` beside the application (`packaging/launcher/src/DataLocation.php:53-92`).
  `bootstrap/app.php:138-142` redirects Laravel's storage path into that directory.
- The data directory holds `database.sqlite`, `storage/`, `logs/`, `backups/` and the
  `suwayomi/` data. The code and the staged runtime, including the JRE, stay in the install
  folder.
- The database holds accounts (email, password hash, remember token), library, progress,
  shelves, notes, ratings, tags, search runs and results, reading sessions, the AniList
  token, sessions, cache (Suwayomi tokens), import runs and licence acknowledgements. The
  privacy page's "collects nothing" is therefore false (M5).
- Backups are `VACUUM INTO` copies beside the database (`BackupLibrary.php:54-65`), so they
  carry all of the above, including live credentials (M1). The page prints the absolute
  backup directory.
- Imported uploads remain under `storage/app/private/imports` (L3). Import temp files use
  the data directory's temp in the packaged runtime (`php.ini:107-109`); the checkout uses
  the system temp.

### Build and installer posture

- The build copies an allowlist (`APP_ENTRIES`), empties `storage/` and verifies it is
  empty, refuses `database/database.sqlite*`, `database/backups`,
  `database/reader-snapshots` and `storage/design-review`, checks the required paths, boots
  `artisan --version` with the install's own PHP and resolved ini, and scans authored files
  for the builder's account name, checkout folder name and RFC1918 addresses
  (`packaging/runtime/build-install.php:62-135`, `:700-718`, `:1163-1357`).
- The scan is for machine identity, not a general secret scanner. `.env` is excluded by name
  and database files by path.
- The build, installer, portable and sandbox gate were not run. Nothing in this section is a
  measured installed artifact; it is the pinned input read from the tree.
- Installer signing was not assessed.

### Privacy copy compared with the tree

- The privacy page's "collects nothing", "Nothing", "Three things" and "no cookie" are not
  supported (M5). The account, library, notes, sessions and search history are stored
  locally; guest pages set session and XSRF cookies; AniList lookups and mail are missing
  from the outbound list.
- The location paragraph is accurate for an installed copy. A portable copy keeps data
  beside the app, which the paragraph does not mention.
- The FAQ reads the same `Outbound` class, so a correction in one place reaches both pages.

## Not verified and deferred probes

This audit did not run a write probe. The approved plan allows them against a scratch
install; this session was read-only, so each is described with the exact request it would
make and marked **not run**. A future session should run them against `build/install` or a
fresh sandbox, never against the live checkout.

| Probe | Exact request | Mark | Why it was deferred |
| --- | --- | --- | --- |
| Registration gate | `POST /register` with `name`, `email`, `password`, `password_confirmation` and a CSRF token from a guest session | not run | Creates an account. `/register` was already measured 404 in single mode. |
| Login | `POST /login` with a test account and a CSRF token | not run | Needs a test account and a session. |
| Import upload | Livewire `start` on `/settings/import` with a fixture zip in the `backup` field and `installExtensions=true` | not run | Writes an `ImportRun`, stores the upload and may install extensions. |
| Import path oracle | The same action with `path` set to a known temp file and to a missing path | not run | Write-side; would confirm the full-path refusal (M2). |
| Live backup | Press Backup on `/settings/backups` | not run | Writes a database copy and a run row. |
| Live restore | Press Restore with a `backup-<token>.json` pair | not run | Replaces the live database. |
| Export | Press Export on `/settings/backups` | not run | Writes an export file and a run row. |
| Cross-reader export | As user B, `GET /settings/backups/export/{id}` for user A's completed run | not run | Needs two accounts and an export; would prove H2. |
| CBZ download | `GET` the chapter CBZ route | not run | Reads chapter data and writes a temp archive. |
| Extension trust bypass | Import a crafted backup naming a package from an untrusted store | not run | Installs code into Suwayomi; would prove H3. |
| Store SSRF | `POST` `addStore` with `http://127.0.0.1:<port>/` and an RFC1918 URL | not run | Changes the shared Suwayomi store list; would prove M7. |
| Reader writes | `POST` progress, note, shelf and rating actions for a chapter | not run | Writes reader rows. |
| AniList connect | `GET /anilist/redirect`, then the callback with a real `code` | not run | Needs AniList client credentials and would contact AniList. |
| Mail | Configure SMTP and register in `shared` mode | not run | Sends mail and changes the environment. |
| Suwayomi direct | `GET http://127.0.0.1:4567/api/v1/...` without a token | not run | Touches the live service and may read reader data. |
| Remote readiness | Send `/ready` through a same-host proxy or with a non-loopback `REMOTE_ADDR` | not run | `REMOTE_ADDR` cannot be spoofed with curl and no proxy was started. |
| Signed storage | Generate a valid `storage` signature and fetch the file | not run | Needs `APP_KEY` and a private file; only the unsigned 404 was measured. |
| Packaged install | Run `build-install.php`, the installer and `Papyrus.cmd --open` | not run | Builds and starts an install. |
| Sandbox gate | `powershell -File build\sandbox\launch-gate.ps1` | not run | Needs several GB of free RAM and approval. |
| FrankenPHP or JRE exploit | Run a public proof of concept against the packaged runtime | not run | Exploit execution is out of scope and could compromise the host. |

Other gaps:

- No source code was executed, fuzzed or statically analysed beyond the dependency audit.
- No dependency licence or binary signature audit was performed.
- The packaged response headers, cookie flags and session behaviour were not measured.
  Their statements come from the pinned `php.ini`, `Caddyfile` and `.env` writer.
- No `shared` or `public` deployment was run, so cross-reader findings are proven from code
  and route shape, not from a second account.
- No live AniList call, mail delivery or Suwayomi socket measurement was made.
- No reader row, title, credential, token or file under `%LOCALAPPDATA%\Papyrus` was read.
- The `/showcase` payload was measured, but its titles were not reproduced in this report.
- The checkout's own worker and queue state was not inspected or changed.

## Sources consulted

All web sources were opened on 2026-10-25. Local files were read at HEAD `6572ba0`.

PHP and the October 2026 RCEs:

- https://www.php.net/archive/2026.php
- https://www.php.net/releases/8_4_27.php
- https://thephp.foundation/blog/2026/10/21/todays-php-security-release-and-how-were-strengthening-php-security/
- https://cert.europa.eu/publications/security-advisories/2026-018
- https://www.cve.org/CVERecord?id=CVE-2026-109618
- https://www.cve.org/CVERecord?id=CVE-2026-109619
- https://www.openwall.com/lists/oss-security/2026/10/23/23 for CVE-2026-115560

FrankenPHP:

- https://github.com/php/frankenphp/releases/tag/v1.13.2
- https://github.com/php/frankenphp/releases/tag/v1.13.3
- https://github.com/php/frankenphp/security/advisories/GHSA-6q3j-62j9-f9hh
- https://github.com/php/frankenphp/security/advisories/GHSA-fq24-r53r-xcx3
- https://github.com/php/frankenphp/security/advisories/GHSA-ccgp-ch9h-533f
- https://github.com/php/frankenphp/security/advisories/GHSA-4gfw-9v29-gp9h

Node:

- https://nodejs.org/en/blog/vulnerability/october-2026-security-releases
- https://nodejs.org/en/blog/release/v26.7.0
- https://nodejs.org/en/blog/release/v26.12.1

Java:

- https://www.oracle.com/security-alerts/cpuoct2026.html
- https://www.oracle.com/security-alerts/alert-cve-2026-91403.html
- https://openjdk.org/groups/vulnerability/advisories/2026-10-21
- https://github.com/adoptium/temurin25-binaries/releases/tag/jdk-25.0.5%2B8
- https://github.com/adoptium/temurin25-binaries/releases for the 25.0.5.0.2 build check

Suwayomi:

- https://github.com/Suwayomi/Suwayomi-Server/releases/tag/v2.4.2366
- https://github.com/Suwayomi/Suwayomi-Server/security/advisories

Dependency advisories:

- https://github.com/advisories/GHSA-mhf7-xp6r-6qrc for Livewire
- The twelve npm advisory URLs listed under Dependency audit, each checked on 2026-10-25
- `composer audit --format=json`, exit 1, saved as `%TEMP%\opencode\composer-audit-2026-10-25.json`
- `npm audit --omit=dev --json`, exit 1, saved as `%TEMP%\opencode\npm-audit-2026-10-25.json`
- The advisory API responses saved as `%TEMP%\opencode\advisories-api-2026-10-25.json`

Local sources:

- `docs/SECURITY.md`
- `docs/ENGINEERING-NOTES.md`
- `docs/audits/2026-10-25/README.md`
- `docs/audits/2026-10-25/walk.md`
- `packaging/suwayomi.json`
- `packaging/runtime/stage-frankenphp.php`
- `packaging/runtime/stage-runtime.php`
- `packaging/php/php.ini`
- `packaging/frankenphp/Caddyfile`
- `packaging/runtime/build-install.php`
- `app/Console/Commands/ConfigureInstall.php`
