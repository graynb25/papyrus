# Hardening plan, 2026-10-25

> **Post-audit note (2026-11-01).** The 1.5.1 build was not published. The public
> history was rewritten on 2026-11-01; commit hashes in this plan predate the rewrite
> and resolve in the owner-held offline archive. The next release is 1.5.2, pending.
> See [../../RELEASE-STATUS.md](../../RELEASE-STATUS.md).

Status: approved and complete in the tree. Batches A, B, C, D, E, F and G are committed in
`86e033a`. The 1.5.0 install tree passed the Windows Sandbox gate on run 19, including a
Windows source page read. The real setup executable installed 1.5.0 in the manual sandbox on
2026-10-26 and the reader flow worked, but the shortcut preflight hung; the fix is in
`86e033a`, the artifacts were rebuilt from it, and the fresh manual run on the rebuilt
installer passed on 2026-10-27, including the shortcut double-click. Batch H is recorded and
committed locally. The 1.5.1 hardening release is committed locally on 2026-10-28 and is not
pushed; its unsigned setup was built from `9dd0918` and its portable from `8ca2028` after the
first-launch storage fix, both on 2026-10-29. The H3 behaviour change is committed locally on
2026-10-27 and is not pushed; its manual proof passed
on a fresh disposable scratch install on 2026-10-28. The `86e033a` artifacts predate the change,
so anything built from them still carries the old behaviour. This plan turns the walk in
`walk.md` and the security assessment in `security.md` into release work for 1.5.0 and 1.5.1.
The audit was read at HEAD `6572ba0`; the batch changes and the audit documents themselves are
committed in `86e033a`.

The owner accepted the recommended defaults: keep the server-path import field for the owner
only, include the copy-only M1 backup warning in 1.5.0, hold L1 for 1.5.1, and approve the
runtime rebuild only after Batches A, C, D, E, F and G are reviewed.

## Owner decisions

Recorded on 2026-10-25:

- 1.5.0 carries the security-critical set. The remaining Medium, Low and copy findings go
  to 1.5.1. The two walk High findings stay in 1.5.0.
- Whole-install backup, restore, export and store changes use an install-owner ability.
  The existing `users.role` column is the seam. No new owner column.
- Import keeps its current automatic extension installation. The owner accepts the H3
  risk for 1.5.0. The code path is not changed. The documentation and the import warning
  must state the behaviour plainly.

The owner decision on H3 is a risk acceptance, not a finding closure. H3 stays open until
the behaviour changes or the deployment posture makes it irrelevant.

## Progress

- [x] Batch A, install-owner ability. Accepted 2026-10-25: 63 focused tests, 2525
  full-suite tests, PHPStan 0 errors, Pint clean.
- [x] Batch B, packaged runtime and JRE. Accepted 2026-10-26: built, and the install tree
  passed the Windows Sandbox gate on run 19 with 86 checks and no failures, including a source
  page read. `VERSION` is 1.5.0
  and the 1.5.0 changelog entry is written, so the build stamps the release
  once. Pins and hashes for FrankenPHP 1.13.2 (PHP 8.5.12) and Temurin 25.0.5+8 are measured;
  the JRE path uses the plan's alternative of removing the four distrust-listed roots because
  Adoptium publishes no 25.0.5.0.2 build. The staged `cacerts` has 117 entries, no emSign or
  AAA subjects, and none of the four distrust fingerprints. `build/install/version.txt` is
  1.5.0 at commit `86e033a`. The unsigned installer is 275,084,375 bytes (SHA-256
  `95a043c86c41cc95d5fd4ef3008fc1863b1ae88bfc2029c2cdde8e0480d7a5c5`) and the portable
  archive is 321,055,119 bytes (SHA-256
  `6d938c4988c440d04c3ab068627abe428ca7c092c97ccd81fda322aab09f43e7`), both 1.5.0.0. The
  manual installer sandbox ran the pre-fix build (`87e514aa4639bf8b19c43567fccc6c6cfee9f2568014c30bbec8ebf616e2ddd0`,
  275,084,054 bytes): it proved the setup executable installs the payload and the reader flow
  works, and found the shortcut preflight hang. The fix is in the rebuilt installer, which needs
  a fresh manual run. See the environment record below.
- [x] Batch C, backup and export authorization. Accepted 2026-10-25: 124 focused
  tests, 2533 full-suite tests reported by the batch, PHPStan 0 errors, Pint gate clean.
  Review corrected two comments only: the controller now says 403 rather than 404 for a
  refused caller, and the nav comment no longer calls Backups the last item.
- [x] Batch D, import and store request safety. Accepted 2026-10-25: 41 of 251 focused
  checks failed before the fixes; 251 passed after, plus 3 seam tests for the Tachibk cap
  refusal. Full suite 2582 tests, 7403 assertions. PHPStan 0 errors, Pint gate clean.
  Caps: 256 MiB per staged entry, 384 MiB total, 16 MiB per Tachibk field, each measured
  against the packaged fixtures and recorded in `config/papyrus.php`.
- [x] Batch E, H3 accepted risk, M1 warning and the security page. Accepted 2026-10-25:
  148 focused tests and 652 assertions independently, 2589 full-suite tests reported by the
  batch, PHPStan 0 errors, Pint gate clean, dash and machine-detail checkers clean. Copy
  only. H3 stays open and accepted; M1 keeps its warning only.
- [x] Batch F, Livewire update. Accepted 2026-10-26: `livewire/livewire` v4.4.7 to
  v4.4.8 within the unchanged `^4.1` constraint, one lock stanza plus content hash, no
  other package moved, `composer audit` exits 0 and `GHSA-mhf7-xp6r-6qrc` is gone.
  Papyrus uses neither `#[Authorize]` nor `#[Json]`. Focused suite 324 tests, 1024
  assertions independently; full suite 2589 tests reported by the batch. PHPStan 0
  errors, Pint clean, dash and machine-detail gates clean.
- [x] Batch G, the two walk High findings. Accepted 2026-10-26: `SearchFanout::runnableCount()`
  added from the same universe read as `targets()`, the search page now names the cap and
  the runnable total and shows the skipped count when no pins are active, and both bulk
  download controls read a chunked series-wide state map. The new download test failed on
  the old code (expected 2, got 11) and passes after. Focused suite 97 tests, 260
  assertions independently; full suite 2593 tests, 7474 assertions reported by the batch.
  PHPStan 0 errors, Pint clean, dash and machine-detail gates clean. With pins active the
  cap is named in the top line but the separate skipped count yields to the pin copy;
  recorded as remaining risk, not a silent case.
- [x] Pre-rebuild verification: Tachimanga shelves (owner-reported, see below). Accepted
  2026-10-26 on a scratch database copy: the real backup imported into 2 shelves, 97 of
  105 linked members placed, 3,322 reading days across 587 series, and both pages rendered
  with the expected values. No reader data or live database was written.
- [x] Manual installer sandbox, 2026-10-26. The real `Papyrus-1.5.0-setup.exe` installed 1.5.0
  per-user, and the reader flow passed: first account, community store, MangaTaro, One Piece
  search, Chapter 001 read and marked read. The shortcut did not start because `Papyrus.cmd` ran
  `php -n` with no script and the console stdin stayed open. The check is now `php -n -v`, with a
  regression test that keeps stdin open; all six gates pass. The fix is committed in `86e033a`
  and the artifacts were rebuilt from it. This is manual evidence, not a gate pass.
- [x] Fresh manual run on the rebuilt installer, 2026-10-27. The installer verified against the
  manifest before launch and again inside the guest. The post-install launch printed its
  preflight and opened the browser; the shortcut double-click printed the complete preflight and
  started the reader, so the hang did not reproduce; and the flow passed: first account,
  community store, MangaTaro, a One Piece search of 14 results from 2 of 2 runnable sources,
  1,202 chapters fetched, Chapter 001 read and marked read with the state persisted. Session
  `session-20261027-103455`. Still manual evidence, not a gate pass.
- [x] Reader walkthrough from the same session, 2026-10-27. `docs/WALKTHROUGH.md` and seven
  images under `docs/walkthrough` walk a reader from the setup wizard to reading, taken from
  this run's screenshots. Each image is cropped to the window it shows and the two third-party
  store tabs in the browser's tab strip are blurred out, so the guide names no store.
  `tools/make-walkthrough.ps1` regenerates the images from the session folder, which git
  ignores, so the committed images are the record.
- [x] Batch H, release close-out
- [x] H3 behaviour change, import review before extension install. Committed locally on
  2026-10-27, not pushed. Every import that installs extensions surveys the backup
  first, shows the stores and signing keys, and installs only from stores the reader trusts at
  install time. Stored uploads have a 24 hour life cycle. The manual proof passed on a fresh
  disposable scratch install on 2026-10-28; the evidence is in the section below. The
  `86e033a` artifacts predate the change.
- [x] 1.5.1, remaining hardening and reader copy. Committed locally on 2026-10-28. Its unsigned
  setup was built from `9dd0918` and its portable from `8ca2028` after the first-launch storage
  fix in `2e4a73d`, both on 2026-10-29. The setup passed the manual sandbox on 2026-10-29:
  per-user install, registration, a community store, MangaTaro, an One Piece search and a chapter read.
  The portable archive passed clean-guest runs on C: and D: on 2026-10-29, including the same
  reader flow and a layout check with no install or registry entry. Both are manual evidence,
  not gate passes.

Parked on 2026-10-25 after Batch E and resumed on 2026-10-26. The batch work and the audit
documents are committed as `86e033a` on `main`; nothing is pushed. The shelf re-import, the
runtime build and the final gates are done. Run 19
passed the Windows Sandbox gate on the install tree, including a source page read, so the
clean-machine bar is met for that tree. The manual sandbox then ran the real setup executable: it
installed 1.5.0 and the reader flow worked, but the shortcut preflight hung; the fix is in
`86e033a` and the artifacts were rebuilt from it. The fresh manual run on the rebuilt installer
passed on 2026-10-27, so the installer payload and its shortcut are now proven. The gate still
copies `build/install` and never runs the setup executable, so the two proofs stay separate.
Batch H's gates, write probes and re-walk are done and recorded with the batch below, dated
2026-10-27, and the close-out is committed locally; nothing is pushed.

## Environment and clean-machine record, 2026-10-26

### WSL2 and Docker are available

The earlier documents said virtualization was off and Docker was unavailable. That was wrong.
Measured on 2026-10-26: `HypervisorPresent` is `True`; WSL 3.0.3.0 runs kernel 6.18.54.1-1;
Ubuntu 26.04.1 is installed with 20 CPUs and about 7.8 GB RAM; Docker Desktop 4.96.0 is
installed per user. `VirtualizationFirmwareEnabled` still reads `False`, which is why the old
inference looked right, but it is not the gate: WSL2 runs. The Docker Linux engine is stopped
when not in use. WSL2 and Docker are test tools, not the supported desktop deployment.

### Docker soak, 2026-10-20

A differential soak ran the 1.3.0 packaged application tree on Linux with FrankenPHP 8.5.11
and Suwayomi 2.4.2366. It covered 90 beats at 30 second intervals from 09:31:49 to 10:08:46,
45 minutes. All 279 HTTP probes returned 200 (`/login`, `/up`, `/sources`, 93 each). The
in-container `suwayomi:check` returned 0 on 34 consecutive even beats from 09:45:28 to
10:08:46; the 13 earlier even beats failed because the probe omitted `-p papyrus-soak` and
Compose looked for a project under another name. Free RAM stayed between 3.95 and 4.31 GB.

This proves the 1.3.0 payload served pages and read Suwayomi back for 45 minutes under a
container stack. It does not prove the 1.5.0 tree, a chapter read, a Cloudflare-protected
source (CEF could not initialise in the container because `libXext.so.6` is absent), or the
Windows installer. Artifacts: `%TEMP%\opencode\papyrus-docker-soak-20261020\`
(`http-probes.csv`, `cli-probes.csv`, `compose-logs.txt`, `probe-soak.ps1`).

### Windows Sandbox passed on run 19, with the installer payload proven separately

The gate produced its first final report for 1.5.0 on run 15, and run 19 reached the source
read on the same day. Run 19 ended with `PASS, every check held`: 86 checks passed and none
failed. The report is `build\sandbox-results\sandbox-report.txt`, 10,056 bytes, SHA-256
`02413c0cdfb47475ffc681033d2d3eb02362208a7934008d7eb335267b94d650`; the screenshot is
`screen-after-start.png`, 1,556,164 bytes, SHA-256
`c0926a1af1d5fd3adf31c6f421eff1ab0645e6c818b0125c359b4cbc19cd42df`. Both were copied to
`%TEMP%\opencode\run19-20261026\`.

The guest had no host PHP or Java and no Java installed. It copied the install tree to
`C:\Program Files\Papyrus`, checked that no database, `.env` or logs shipped, and started it
through the reader shortcut with `--open`. The bundled PHP ran 8.5.12, Suwayomi answered
2.4.2366 on loopback, the database was current, the app reported ready on 8402, the queue
worker and scheduler started, Edge opened, `/ready` and `/up` returned 200, the first account
was created through `/register`, and `/sources` returned 200 with the page's eyebrow and no
failure callout. The data directory with a space in its path, the diagnostics bundle and
HTTPS through the bundled trust store also passed. Run 19 then added a community store's protobuf
index (signing key prefix recorded, 1,431 extensions), installed
`eu.kanade.tachiyomi.extension.all.mangataro`, mirrored two sources, fetched One Piece (manga
id 1) with 1,202 chapters, and called `fetchChapterPages` for chapter id 1, "Chapter 001:
Romance Dawn", which returned 53 page paths. Page 0 was requested through the app proxy at
`/suwayomi/api/v1/manga/1/chapter/1/page/0`: 200, `image/webp`, 175,432 bytes, SHA-256
`9996ffbb9d1fadac23357e56c611f93b1c07e980737592baa0658bf567574e6e`, the same first page WSL
run 3 read. `/sources` returned 200 with no failure callout after the catalogue was populated,
and `/series/1` rendered One Piece. The stop released 8402 and 4571 and left no Papyrus
process.

The twelve earlier deaths were `0x80370106`. Run 13 had enumerated the host `AMD Radeon RX
6600` in the guest despite `<VGpu>Disable</VGpu>`. The fix was a host policy, not a code
change: `HKLM\SOFTWARE\Policies\Microsoft\Windows\Sandbox\AllowVGPU` set to DWORD 0. The key
was absent before the run. The host is Windows 11 Pro 26H2 build 26300.9711 with Sandbox app
0.8.107.0. The same failure and fix are reported for this build and Sandbox version in
Microsoft's Windows Sandbox issue #88. With the policy in place the guest stayed up past four
minutes and wrote the screenshot and report.

One limit remains for the gate itself. The gate copies `build/install`; it does not run the setup
executable, so it proves the install tree and the launcher, not the installer payload. The separate
manual sandbox (`build\sandbox\launch-manual.ps1`) runs the real `Papyrus-1.5.0-setup.exe`, verifies
the copy against the manifest and records the install with a screenshot watcher. It ran on
2026-10-26 and again on the rebuilt installer on 2026-10-27; the records are the subsections
below, and both are manual evidence, not a gate pass.
Artifacts for run 19 are under `%TEMP%\opencode\run19-20261026\`.

Runs 16 to 18 added the source-access section and found three defects in the probe rather than
the install. Run 16 read `Sources mirrored 0` from a successful sync because the regex matched
the wrong spacing, while the app had mirrored 2. Run 17 fetched 1,202 chapters that all reported
`pageCount: -1`, and the probe's `pageCount > 0` filter treated unknown as no pages. Run 18
removed that filter and guessed chapter indexes 1, 0, 2 and 3; all four returned 404, because the
proxy does not fetch a chapter's images and the page path addresses the chapter's source order
rather than its id. The corrected probe calls `fetchChapterPages` for a chapter and requests the
path that mutation returns, which is what the reader does. Run 19 used it and passed with 86
checks and 0 failures. It also reached the two catalogue pages run 18 skipped: `/sources`
returned 200 with no failure callout and `/series/1` rendered One Piece.

The gate killed the Sandbox client but `vmmemWindowsSandbox` was still up after its 90 second
wait. `WindowsSandboxServer` was stopped by hand and the memory process drained more slowly.
Check for `vmmemWindowsSandbox` before the next launch. Run 18 left the compute system running
again, and it was released only with an elevated `hcsdiag kill` of the running WindowsSandbox
identifier. Stopping `WindowsSandboxServer` and `WindowsSandboxRemoteSession` did not release the
memory process. Run 19 printed the same warning after the report was written; an elevated
`hcsdiag kill` of the running WindowsSandbox compute system then closed the guest. The launcher
now prints that remedy with the warning.

Run 14's seven progress artifacts remain at `%TEMP%\opencode\run14-20261026\`; its
`progress.txt` is 2,264 bytes, SHA-256
`7de193d21379d7c6d4f7ca49597d243cbb37aa03323754f2925bd96e92ab2e06`.

### Manual installer sandbox, 2026-10-26

The manual sandbox ran the real `build\dist\Papyrus-1.5.0-setup.exe` in a fresh Windows Sandbox
guest. The guest verified the copy as 275,084,054 bytes with SHA-256
`87e514aa4639bf8b19c43567fccc6c6cfee9f2568014c30bbec8ebf616e2ddd0`, matching
`build\dist\manifest.json`. The setup completed as a per-user install at
`%LOCALAPPDATA%\Programs\Papyrus`; `version.txt` read 1.5.0 at commit `6572ba0`, and the install
tree held no database, `.env` or logs. The reader flow then passed through a direct start
(`start-direct.cmd`, which bypasses `Papyrus.cmd`): the first account, a community store,
MangaTaro, a One Piece search of 14 results from 2 of 2 sources, the 1,202 chapter series page,
and Chapter 001 read at `/read/1/1` and marked read.

The reader's shortcut did not start the install. `Papyrus.cmd` ran `php -n` with no script, PHP
reads the script from stdin, and a console leaves stdin open, so the runtime check waited forever
and the window stayed blank. The gate and `PapyrusCmdTest` had both redirected stdin from an empty
file, which is why only this run found it. The check is now `php -n -v`, with a regression test
that keeps stdin open. The run also found the separate `Supervisor::status()` scope defect
recorded for 1.5.1.

Verdict: the installer payload and the reader flow are proven on the real setup executable; the
shortcut is not. This is manual evidence, not a gate pass, and 1.5.0 must not ship until the
rebuilt installer passes a fresh manual shortcut run. The rebuilt installer from `86e033a` is
275,084,375 bytes with SHA-256
`95a043c86c41cc95d5fd4ef3008fc1863b1ae88bfc2029c2cdde8e0480d7a5c5`. Full evidence is in
`docs/ENGINEERING-NOTES.md` section 16.5; artifacts are in
`build\manual-sandbox-results\session-20261026-210533\`.

### Manual installer sandbox, fresh run on the rebuilt installer, 2026-10-27

The fresh run used the rebuilt installer from `86e033a`, 275,084,375 bytes with SHA-256
`95a043c86c41cc95d5fd4ef3008fc1863b1ae88bfc2029c2cdde8e0480d7a5c5`. The launcher verified it
against the manifest before the guest started, and the guest verified its copy again
(`installer-verified.txt`, `match=True`). The setup completed as a per-user install; the
post-install launch printed its preflight and opened the browser; and the shortcut double-click,
the exact path that had hung the day before, printed the complete preflight through "the app
reports itself ready", the queue worker, "Papyrus is running at http://localhost:8000" and
"Asked your browser to open it." The hang did not reproduce. The only console noise was a
Chromium GPU line about a missing video device, which the sandbox's absence of a video device
explains and which stopped nothing.

The flow matched the first run: first account registered, empty library, community store added,
MangaTaro installed, a One Piece search of 14 results deduplicated from 2 of 2 runnable sources,
1,202 chapters fetched, and Chapter 001 read at `/read/1/1` with the read state persisted on the
series page. The watcher took 41 frames and stopped on the stop marker. Verdict: the rebuilt
installer's payload, post-install launch, shortcut preflight and reader flow are proven on the
real setup executable. This is manual evidence, not a gate pass. Full evidence is in
`docs/ENGINEERING-NOTES.md` section 16.5; artifacts are in
`build\manual-sandbox-results\session-20261027-103455\`.

### Manual installer sandbox, 1.5.1, 2026-10-29

The 1.5.1 run used the unsigned setup from `9dd0918`, 275,072,638 bytes with SHA-256
`056cc3e55b7cba84bcbcb052652ceec839a7795a1567a05f532f537214993aae`. The launcher verified it
against the manifest before the guest started, and the guest verified its copy again
(`installer-verified.txt`, `match=True`, `version=1.5.1`). The setup completed as a per-user
install; the post-install launch opened the browser, and the console reported "Papyrus is running
at http://localhost:8000" with the data directory under `%LOCALAPPDATA%\Papyrus`. The first
account registered, a community store was trusted, MangaTaro installed, a One Piece search
returned 14 results deduplicated from 2 of 2 runnable sources, and Chapter 1 of 1,202 rendered at
`/read/1/1/1`. The watcher took 39 frames and stopped on the stop marker. Verdict: the 1.5.1
installer payload and reader flow are proven on the real setup executable. The portable archive
is covered separately below. This is manual evidence, not a gate pass. Artifacts are in
`build\manual-sandbox-results\session-20261029-093709\`.

### Automated install harnesses, 1.5.1, 2026-10-29

The setup was also measured by the two automated harnesses in a fresh Windows Sandbox guest. The
guest verified the copied installer against the manifest before either run. `verify-installer.ps1`
exited 0: silent install in 35s, 12,965 files under `%LOCALAPPDATA%\Programs\Papyrus`, data at
`%LOCALAPPDATA%\Papyrus`, `/ready` 200 with the key and database checks true and no pending
migrations, readable console output, stop exit 0, uninstall exit 0 in 13s, install removed, the
561,152-byte library byte identical, and no leftover install files or shortcuts.
`verify-install-immutable.ps1` exited 0: install in 42s, 12,965 files and 633.4 MB, ready in 15s,
`/ready` 200, a 30s hold, stop exit 0, 0 ports held, 0 Java processes left, 0 files added, 0
removed and 0 changed, then uninstall exit 0 with the install and data removed.

The wrapper that runs both harnesses in sequence hung after the first one because a child process
kept its redirected output open, so the second harness was started directly in the same guest. Both
harnesses exited 0 and their full output is in
`build\verify-sandbox-results\session-20261029-204802\`. This is automated evidence, not the
Windows Sandbox gate.

### Manual portable sandbox, 1.5.1, 2026-10-29

The portable zip was rebuilt from `8ca2028`, after the first-launch storage fix in `2e4a73d`,
because the archive has no storage tree and the first start must create it. It is 321,034,901
bytes with SHA-256
`0a430b7a9ad293ba33343cb55950c667a1c82642f5f044ea913ee25d2191efab`; the sidecar matches. The
archive has 12,964 entries, no `.env`, no database and no storage tree, and its readme names
`papyrus-data` as the data folder. The host first launch reproduced the fix before the guest runs:
`.env` written, 47 migrations applied, database created, exit 0.

Two clean Windows Sandbox guests ran the same archive. The C: run used
`C:\Papyrus Portable\Papyrus`, a path with a space, with data at
`C:\Papyrus Portable\papyrus-data`; session `session-20261029-200249-c`. The D: run created a
4 GB VHD inside the guest with diskpart, attached it as D:, and used `D:\Papyrus` with data at
`D:\papyrus-data`; session `session-20261029-201726-d`. Both guests verified the copied archive
hash before extraction and again inside the guest, started through `Papyrus.cmd`, and wrote
`papyrus-data` beside the program folder. Both registered the first account, trusted the
community store, installed MangaTaro, searched One Piece, and rendered chapter 1 of 1,203 at
`/read/1/1`. Both layout checks returned `PASS`: data beside the app, no `%LOCALAPPDATA%`
install, no Start Menu entry and no uninstall entry. The D: layout records `drive=D`,
`drive_kind=vhd` and `separate_volume=True`.

The guest's Storage cmdlets returned no volume facts, so both layout files leave
`volume_file_system`, `volume_size_bytes`, `disk_bus_type` and `disk_model` blank. The D: VHD is
proven by the diskpart output in `portable.log`, which records the create, attach, format and
drive-letter assignment. The C: run logged the launcher's warning that temporary uploads would
reach 102 characters, close to the 260-character limit; it did not block the run. Verdict: the
rebuilt 1.5.1 portable starts and reads on a clean machine from C: and from a separate D: volume.
This is manual acceptance evidence, not a gate pass. Artifacts are in
`build\portable-sandbox-results\session-20261029-200249-c\` and
`build\portable-sandbox-results\session-20261029-201726-d\`.

### Final gates, 2026-10-26

All six gates passed on the code tree after the shortcut preflight fix and before the final
documentation edits:

- `php artisan test --without-tty`: 2,599 tests, 7,498 assertions, 590,780 ms.
- PHPStan level 7 over `app/` and `packaging/`: 0 errors.
- Pint over `app/`, `tests/` and `packaging/`: clean.
- Dash checker: 654 files, no em or en dashes.
- Machine-detail gate: 634 tracked files, no account name, developer folder path or IP address.
- Its self-tests: 29 verdict cases and 20 skip cases, all correct.

The shortcut fix added a regression test that failed on the shipped `php -n` line, with the
command still running under an open stdin after 5.2 seconds, and passes on `php -n -v`. The edits
after the suite are documentation only, and the dash and machine-detail gates are re-run over the
tree after them. The counts are the measured counts on this tree, not a completion criterion.

### WSL2 acceptance substitute

The full plan is in `docs/ENGINEERING-NOTES.md` section 16.3. In short: install PHP 8.4 or
newer and a JRE in Ubuntu, copy the application payload without `runtime\` and `suwayomi\`
into a scratch directory, start a scratch Suwayomi with a fresh data directory, run migrations
and `suwayomi:check`, create the first account, require the `/sources` catalogue sentence
rather than a 200, read one chapter if the run is the source-access substitute, then soak for
at least 30 minutes with `/login`, `/up`, `/sources` and `suwayomi:check`.

Prerequisites installed in Ubuntu 26.04.1 on 2026-10-26: PHP 8.5.4 from the Ubuntu archive
with `pdo_sqlite`, `sqlite3`, `mbstring`, `xml`, `curl`, `openssl`, `fileinfo` and `tokenizer`;
OpenJDK 25.0.4.1; `sqlite3` 3.46.1; `rsync` 3.4.1; `unzip`. `php8.5-intl` 8.5.4-0ubuntu1.5
and `php8.5-zip` 8.5.4-0ubuntu1.5 were installed on 2026-10-27; both are in the Ubuntu 26.04
archive, so the earlier note that neither package existed was wrong. `intl` is required: the
Sources page formats byte counts through `Illuminate\Support\Number` and returns 500 without
it.

Run 1, 58 beats at 30 seconds from 17:34 to 18:02 UTC on 2026-10-26, stopped at beat 58 of
60, answered `/login` and
`/up` on all 58 probes and `suwayomi:check` exited 0 on all 29 even beats, but `/sources`
returned 500 on every authenticated probe because `intl` was missing. Run 2, 6 beats at 10
seconds on 2026-10-27 after installing `intl`, returned 200 for `/sources`, `/search`,
`/library`, `/settings/import`, `/settings/backups` and `/migrate/sources`; 18 of 18 HTTP
probes and 3 of 3 CLI checks passed. Run 3 on 2026-10-27 added a community store, installed
MangaTaro, fetched One Piece, and read all 53 pages of Chapter 001 through the authenticated
app proxy: 53 of 53 returned 200 `image/webp`, 7,766,490 bytes in total. That meets the
source-access substitute on this Linux userland. It does not touch the Windows installer,
shortcut or runtime. The full record is `docs/ENGINEERING-NOTES.md` section 16.4.

A WSL pass would prove the current source tree on a clean Linux userland. It would not prove
the Windows installer, the reader shortcut, the bundled Windows runtime, paths with spaces,
Windows file locking, or a clean Windows machine. It must not be recorded as a Windows
Sandbox pass.

### Shelf check, first pass, 2026-10-26

The owner reported on 2026-10-26 that the library showed no shelves and Insights was empty,
after a run in the walk session. Both instances were measured read-only; no reader data was
written.

Reader side, current tree, the new backup `Tachimanga_backup_2026-10-26_09-33-38-542.tmb`
through `TachimangaReader`: format `tachimanga`, schema 525, 1,283 library series, 43,166
read chapters, 2 categories (`"Youtube "` 20 members, `"Snowflakes"` 85), and 5,085 reading
days totalling 1,673,755 seconds. The file has no `default` category row; Tachimanga's
Default is its built-in unfiled bucket, so two shelves is the whole of what a file can carry.

Installed side, the checkout database copied with its WAL: 14 import runs, all 2026-10-09 to
2026-10-10, every one with `categories_in_file` null; 867 series states, 21,450 chapter
states, 0 categories, 0 category members and 0 imported reading days.

Commit dates: shelves landed 2026-10-20 (`58919fe`), reading days 2026-10-24 (`8e26a23`).
Both are in the 1.4.0 source `26419e6`. The imports predate both features, so the shelves and
Insights were never written rather than damaged or hidden, and no re-import has run since.

The remaining step is the scratch-database re-import after Batch G, which must show the two
shelves with members and populated reading days. `default` is expected to be absent.

### Shelf check, second pass, 2026-10-26

The new backup was imported into a fresh copy of the checkout database with the current
tree, extension installation off. The copy was asserted as the connected database before
any write. The run took 486.7 seconds and its row records `categories_in_file` 2,
`category_members_placed` 97, `category_members_skipped` 8 and `read_chapters_applied`
28,952. Series counters: 1,283 in the library, 863 resolved (696 from the catalogue, 167
from a source), 299 not found, 58 missing an extension, 63 on an unreachable source, and
11 filed without a source.

Result in the copy: 2 shelves, `Youtube` with 18 members and `Snowflakes` with 79, 97
category rows; 3,322 imported reading days across 587 series totalling 1,083,300 seconds.
The 8 skipped members are the ones whose series did not resolve in this pass.

Rendered in process against the same copy: `/library` returned 200 and its shelf pills read
`Youtube 18` and `Snowflakes 79`. `/insights` returned 200 and read `12 days 12 hours 55
minutes across 587 series`, with the imported-time line present. Both match the copy's
rows.

This was a scratch copy and no reader data or live database was written. The run did ask
Suwayomi for source lookups and may have refreshed uninitialized series, which is what a
normal import does; no Papyrus state outside the copy changed.

## Release 1.5.0: security-critical set

### Batch A. Install-owner ability

Goal: one account, whether made by browser sign-up or `users:create`, owns the install;
every other account is a reader.

Use the existing `users.role` column rather than adding a column. The value `admin` means
install owner. `users:create --admin` still writes an explicit owner when one already exists.
The first web or console account becomes the owner through the shared serialized rule.

Files:

- `app/Models/User.php`: add an `isInstallOwner()` method or equivalent that reads `role`.
- `app/Providers/AppServiceProvider.php`: define the `manage-install` gate in the boot seam.
  No gate definitions exist today.
- `app/Actions/Fortify/CreateNewUser.php`: give the first account the owner role without
  making `role` fillable. Use `forceFill` inside the same claim that closes registration, or
  an equivalent serialized write. A request must never choose its own role.
- `app/Console/Commands/UsersCreate.php`: create the account through the same owner rule.
  `--admin` still creates an explicit owner; no other command line value chooses a role.
- `database/migrations/2026_10_25_000000_promote_first_user_to_install_owner.php`: if no
  account has the owner role, promote the earliest account by id. Do nothing when an owner
  already exists.
- `tests/Feature/InstallOwnerTest.php`: first web account is owner, second account is not,
  the role survives a profile save, a posted `role` field is ignored, and the migration
  backfills an existing install without changing the reader count.
- `tests/Feature/UsersCreateCommandTest.php`: first console account is owner, later console
  accounts are readers, `--admin` still works, and an ownerless install promotes the earliest
  account.

Acceptance:

- A fresh single-mode install has exactly one owner, whether the first account comes from
  the browser or `users:create`.
- A shared or public install has one owner and any number of readers.
- The role is not fillable and no web request can set it.
- The migration is idempotent.

### Batch B. Packaged runtime and JRE

Goal: close B1 and M8 in the shipped artifacts, not only in the checkout.

Files:

- `packaging/runtime/stage-frankenphp.php`: move the pin from FrankenPHP 1.13.0 to 1.13.2
  or the newest 1.13.x that carries PHP 8.5.12 or newer. Move the SHA256 with it.
- `packaging/runtime/stage-runtime.php`: move the JRE pin from 25.0.4.1+1 to 25.0.5.0.2,
  or apply the four-root removal to the staged `cacerts`. The pin and SHA256 move together.
- `packaging/php/php.ini`: correct the verified-version comment.
- `packaging/frankenphp/Caddyfile`: correct the verified-version comment.
- `packaging/runtime/build-install.php`: add build-time assertions that the staged
  `php.exe -v` reports at least 8.5.12, `frankenphp.exe --version` reports at least 1.13.2,
  and `java.exe -version` reports the pinned build. A comment is not an assertion.
- `packaging/suwayomi.json`: unchanged unless the Suwayomi smoke test forces a change.
- `tests/Feature/LauncherDependencyListTest.php` or a new packaging test: assert the pins
  and the minimum versions, if the existing test seam can read them without a build.

Execution order:

```text
stage-frankenphp.php -> stage-runtime.php -> build-install.php
                                      -> build-installer.php
                                      -> build-portable.php
```

Acceptance:

- The staged runtime reports PHP 8.5.12 or newer and FrankenPHP 1.13.2 or newer.
- The staged JRE reports 25.0.5.0.2, or the root removal is documented and verified.
- `php artisan suwayomi:check` passes against the rebuilt install.
- The installer and portable are rebuilt.
- `build\sandbox\launch-gate.ps1` passes and the report, progress file and screenshot are
  read back. The gate must start the reader's actual shortcut path with `--open`.
- Source access is proven on the clean machine, not only a rendered page.

This batch changes tracked packaging inputs and produces build output. It needs a separate
`go` before any download, build, installer run or sandbox launch. Free RAM must be measured
and stated before the sandbox.

### Batch C. Backup and export authorization

Goal: close H1 and H2.

Files:

- `routes/settings.php`: put the backups page and the export download route behind
  `can:manage-install`.
- `resources/views/pages/settings/⚡backups.blade.php`: call `Gate::authorize` in a `mount()`,
  in `start()`, in `restore()` and in `startExport()`. The route middleware is not enough for
  a Livewire action that a crafted request can call directly.
- `resources/views/pages/settings/layout.blade.php`: show the Backups nav item only to the
  owner, so a reader is not offered a link that answers 403.
- `app/Http/Controllers/ExportDownloadController.php`: require the owner and, as defence in
  depth, treat a run started by another account as a 404 for a caller who is not the owner.
  Keep the existing 404 shapes for a missing, running, escaped-name or mismatched product.
- `app/Support/Faq.php`: the backup answer names the install owner, so a reader is not sent to
  a page they cannot open.
- `tests/Feature/Settings/BackupsPageTest.php`: a second account gets 403 on the page and on
  each action; the owner still loads the page; the owner-only copy is rendered.
- `tests/Feature/ExportDownloadTest.php`: a second account cannot fetch the first account's
  completed run, including by walking sequential ids.
- `tests/Feature/FaqTest.php`: the backup answer names the install owner.
- `tests/Feature/RunLiveBackupJobTest.php`, `RunLiveRestoreJobTest.php`,
  `RunLiveExportJobTest.php`: keep the job-level tests, because the jobs can be dispatched
  from code as well as the page.

Acceptance:

- Only the owner can see the backups page.
- Only the owner can start a backup, a restore or an export.
- A non-owner cannot download an export, even with a valid run id.
- The page copy says the actions are owner-only.

### Batch D. Import and store request safety

Goal: close M2, M3 and M7.

M2, import path:

- The free-text server path stays only for the owner. A non-owner sees the upload field and
  no path field.
- `resolvePath()` rejects a non-owner path before `is_file()` or `is_readable()`, so the
  refusal cannot become a path oracle.
- The refusal sentence names the basename, not the full path. The queued job rejects a path
  outside the app's `imports` directory unless the account is the owner, before it calls
  `is_file()`, and writes the same redacted sentence. An uploaded file under `imports` stays
  available to every reader.
- Files: `resources/views/pages/settings/⚡import.blade.php`,
  `app/Jobs/RunBackupImport.php`, `tests/Feature/ImportPageTest.php`,
  `tests/Feature/ImportProgressTest.php`.

M3, archive bounds:

- Add configured caps for uncompressed bytes per entry, total staged bytes and a single
  Tachibk field. Reject before allocating or copying past the cap.
- Files: `config/papyrus.php`, `app/Import/Tachimanga/TachimangaReader.php`,
  `app/Import/Tachibk/TachibkStream.php`.
- Tests: `tests/Feature/TachimangaReaderTest.php`, `tests/Feature/TachibkStreamTest.php`,
  `tests/Feature/ImportPageTest.php`. Use a small archive that expands past the cap. Assert
  the run is refused, the worker does not exhaust memory and the temp file is removed.

M7, store URL:

- Restrict `addStore()` and `removeStore()` to the owner. Both change the shared Suwayomi
  server rather than one reader's record.
- Resolve the host before sending it to Suwayomi. Allow only `http` and `https`. Reject
  loopback, private, link-local, multicast and unspecified addresses, including IPv4-mapped
  IPv6 and the decimal, octal and hexadecimal spellings of a loopback address.
- Files: `resources/views/pages/⚡sources.blade.php`, `app/Suwayomi/SourceCatalog.php` or a
  small support class beside it.
- Tests: `tests/Feature/SourcesPageTest.php`, `tests/Feature/SourceCatalogSyncTest.php`.
  Cover loopback, RFC1918, link-local IPv6, a non-HTTP scheme, a public URL and a hostname
  whose resolved address is private.
- Residual risk: Suwayomi performs the fetch and may follow redirects. The plan does not
  claim full SSRF protection. Owner-only access is the primary control. A DNS answer can
  change between the check and the fetch.

Acceptance:

- A non-owner cannot reach the server-path import or the store URL action.
- A crafted archive cannot expand beyond the configured cap.
- A store URL pointing at loopback or a private address is refused before the mutation.
- The refusal copy never echoes a full server path to a non-owner.

### Batch E. H3 accepted risk and the security page

Goal: make the accepted risk honest and correct the three false claims in
`docs/SECURITY.md`, without changing the import behaviour.

Files:

- `docs/SECURITY.md`: the import section must say that a backup can install the extensions
  it names and that this is not the same trust decision as the sources page. The rate-limit
  sentence and the proxy query allowlist sentence are corrected at the same time.
- `resources/views/pages/settings/⚡import.blade.php`: add a sentence beside the install
  checkbox saying the extension runs with the Suwayomi process's access and the import
  will install it unless the box is cleared. Copy only, no behaviour change.
- `resources/views/pages/settings/⚡backups.blade.php`: add the copy-only M1 warning that a
  backup carries live credentials and session identifiers. The exclusion and the AniList
  encryption are 1.5.1.
- `tests/Feature/ImportPageTest.php`: assert the warning is rendered.
- `tests/Feature/Settings/BackupsPageTest.php`: assert the backup warning is rendered.
- `tests/Feature/LegalPagesTest.php` or a new documentation test: keep the rate-limit and
  proxy claims from drifting again where the values are stable enough to assert.

Acceptance:

- `docs/SECURITY.md` no longer claims that nothing installs quietly.
- The import page states the default and the consequence.
- The backups page warns that a backup contains live credentials.
- The owner decision and the remaining risk are recorded in `CHANGELOG.md` in Batch H.

H3 remains an open High finding. It is not marked fixed.

### Batch F. Dependency update

Goal: clear the reachable dependency advisory without a general dependency sweep.

- Update `livewire/livewire` from 4.4.7 to 4.4.8 or newer within the existing `^4.1`
  constraint. Update `composer.lock`.
- Run the Livewire, search, import, backup and reader tests that exercise the changed
  package.
- Do not update npm, Node or unrelated Composer packages in this batch. Those are 1.5.1.
- Record the advisory URL and the new resolved version in `CHANGELOG.md`.

Acceptance:

- `composer audit` no longer reports `GHSA-mhf7-xp6r-6qrc`.
- The full suite still passes.
- No unrelated lockfile churn.

### Batch G. Walk High findings

W-H1, search scope:

- Replace "Leave empty to search all installed sources." with copy that names the cap and
  the runnable source count. Disclose how many runnable sources are skipped. Do not claim a
  full search when the cap leaves sources out.
- Measured: `⚡search.blade.php:63` defaults `$limit` to 40, the form allows 1 to 150, and
  `SearchFanout::targets()` cuts the runnable set to that limit unless it is negative. The
  only disclosure today is `sourcesNotPinned`, which is empty when the reader has no pins,
  so a cap cut is silent.
- Add one public count to `SearchFanout` for the runnable universe under the same options
  rather than re-implementing the filters in the page. The page's line should then read as
  "searching N of M runnable sources" with the cap named, and a skipped count when M is
  larger.
- Files: `resources/views/pages/⚡search.blade.php`, `app/Search/SearchFanout.php`.
  The `routes/web.php:185-192` comment already states the 40 target cap and an 82 source
  measurement, so it needs no correction unless the copy contradicts it.
- Test: `tests/Feature/SearchPageTest.php` and `SearchFanoutInterleaveTest.php`. Assert the
  rendered count matches `targets()->count()`, the runnable total is named, and the skipped
  count is not zero when the cap cuts the set. Also assert a reader with no pins sees the
  cap disclosure, which is the case the current page cannot show.
- Acceptance: a reader cannot read the page and believe every installed source was queried.

W-H2, bulk download:

- Stop reading the page-scoped `chapterStates` map in `unreadDownloadableCount()` and
  `downloadUnread()`. Load the reader's state for every filtered chapter in chunks, or use a
  dedicated series-wide read-state query.
- Measured: `⚡series.blade.php` has `PER_PAGE = 50`; `chapterStates()` returns states only
  for the slice on the open page, while both bulk actions loop over all filtered
  `$this->chapters`. A read chapter on another page therefore counts as unread and is
  queued. `DownloadLedger::enqueue()` receives the full id list at line 1258, which is the
  seam a test can assert exactly.
- Files: `resources/views/pages/⚡series.blade.php`.
- Test: `tests/Feature/DownloadTest.php` (the existing bulk-download block) with more than
  50 chapters and read states on both sides of the page boundary. Assert the button count
  and the exact ids passed to the download ledger. Confirm the test fails against the
  current code.
- Acceptance: "Download unread" queues only chapters the reader has not read, regardless of
  which page is open.

### Pre-rebuild verification: imported Tachimanga shelves

Owner-reported on 2026-10-26, before the runtime rebuild. This is a reader-path check, not
a code batch. The import path does carry categories: `TachimangaReader::categories()` joins
`CategoryManga` to shelf series and `BackupImporter::applyShelves()` creates shelves through
`Library::createCategory()` and files members. The report row records
`categories_in_file`, `category_members_placed` and `category_members_skipped`.

First pass, measured read-only on 2026-10-26 and recorded above: the file carries two stored
categories, `Youtube` and `Snowflakes`; `default` is Tachimanga's built-in unfiled bucket and
has no `Category` row. The checkout's imports all ran 2026-10-09 to 2026-10-10, before
shelves (`58919fe`) and reading days (`8e26a23`) existed, and left 0 shelves, 0 members and 0
reading days. So the reported emptiness is an old import, not damage and not a hidden shelf.
The current reader already returns both shelves, their members and 5,085 reading days from
the new backup.

Remaining acceptance step, after Batch G:

- Reproduce on the current checkout against a scratch copy of the database, using the new
  backup. Confirm `/library` lists `Youtube` and `Snowflakes` with their members, and that
  Insights shows reading time. `default` is expected to be absent.
- If the drop point turns out to be the code, fix it in 1.5.0 with a failing test first. If
  it is the old build only, record that and rebuild. Do not change reader data.

Passed on 2026-10-26. The scratch import produced both shelves with 97 members and 3,322
reading days, and the rendered pages showed them; see the second pass above.

Acceptance: the two shelves with their members and populated reading days appear from the
current tree on a scratch database, or the exact drop point is named with counts from both
sides and a dated finding.

### Batch H. Release close-out for 1.5.0

- `CHANGELOG.md`: 1.5.0 entry, including the H3 accepted risk.
- `VERSION`: 1.5.0.
- `docs/SECURITY.md`: the Batch E corrections and the new owner-only boundary.
- `README.md`: only if a reader-facing claim changed.
- Run all six gates on the final tree:

```powershell
php -d memory_limit=2048M artisan test --without-tty
php -d memory_limit=2G vendor/bin/phpstan analyse --no-progress --memory-limit=2G
php -d memory_limit=2048M vendor/bin/pint --test app/ tests/ packaging/
python ..\.tools\suwayomi\check-dashes.py
python tools\check-machine-detail.py
python tools\test-check-machine-detail.py
```

- Run the approved write probes against a scratch install or sandbox, not the live checkout:
  two-account backup, restore and export attempts; non-owner import path; crafted archive;
  loopback store URL. Record each result or mark it not run with the reason.
- Re-walk `/search`, `/series/{manga}`, `/library`, `/settings/import`,
  `/settings/backups`, `/sources` and `/migrate/sources` at desktop and mobile width.
- Commit locally only after the full body of work is reviewed. Do not push without approval.

### Batch H record, 2026-10-27

**Venue, owner-approved on 2026-10-27.** Windows Sandbox was not used. The venue is a scratch
install on the host: `build/install` copied to a scratch directory beside the repository,
12,962 files, with its own data directory, the app on loopback port 8010 and Suwayomi on
loopback port 4591. The copy was verified before it was started: `version.txt` names 1.5.0 at
commit `86e033a`, built 2026-10-27 03:56:30+00:00, and the copy's source held no database, no
`.env` and no logs. The launcher preflight passed, `/up` answered 200, and both listeners were
read from the socket, loopback only. `Papyrus.cmd` passes `--open` unconditionally, so the
launch also opened a browser tab on this desktop at the scratch address; harmless, and
recorded here so the number of tabs has an owner. Cookies were kept apart from the live
install by browsing the scratch one at `localhost` while the live one sits at `127.0.0.1`.

**Content the probes needed.** The first web account became the owner, Batch A's migration,
and a second account registered as a reader for the reader-side probes. A community store
was added through the page, its signing key prefix matched the one run 19 trusted, and the
catalogue mirrored 1,042 extensions; MangaTaro 1.6.0 installed healthy. A
search for One Piece answered with 14 results deduplicated from 2 sources and a `search_runs`
row; the series was added and `/series/1` listed 1,202 chapters from 4 scanlator groups. An
owner export ran to completion, `live_export_runs` state complete, and wrote a 366 byte
`.tachibk` carrying 0 series, which is correct for this library. The backups page showed the
owner-only sentence, the M1 credentials warning, and the path inside the scratch data
directory.

**Reader refusals, each read back from the page.** Registration closed after the first
account: `/login` shows no Sign up link. The reader's `/settings/backups` answers 403 "This
action is unauthorized.", which covers a restore attempt in the same refusal: the
whole-install page answers before any action exists to press. The reader's export route
`/settings/backups/export/{run}` answers 403 at the route middleware, before the controller's
own 404 layer, and the file is not delivered; that layer is defense in depth, not the primary
control. The reader's `/settings/import` is upload only: no server path field, the copy says
only the person who owns this server can import a file that is already on it, the H3 warning
is present, and Backups is absent from the reader's settings navigation. The reader's
`/sources` has no add store field or button and no Remove, and trust is per user.
`/migrate/sources` renders for the reader with the empty library message.

**Crafted archive, two stages.** A bare zip named `.tmb` was refused first, "No reader
recognises that file. This server reads backups from: tachimanga, tachibk. Tachimanga writes a
.tmb and Mihon or Suwayomi writes a .tachibk.", which is the positive control: the format gate
answers before any staging. The real fixture is a valid Tachimanga backup whose
`tachimanga.db`, inside `contents.zip`, unpacks to 314,896,384 bytes while the `.tmb` on disk
stays 43,955 bytes. Building it taught the seam: the reader stages only `contents.zip` and
`tachimanga.db`, so the payload that trips the per entry cap has to be one of those two; a
third inner entry is never staged, and a fixture that hid the payload there would have been
refused for the wrong reason, the database schema, and the probe would have proven nothing
about the cap. And `meta.json`'s `version` is the integer 520, not a version string. The live
refusal came mid staging with the exact sentence "That backup holds a file larger than 256 MB
when unpacked, which is more than this server reads. Nothing has been changed.", the
`import_runs` row holds state `refused` with that reason, and the copy's temp directory held
no import leftovers afterwards, only the runtime's own process plumbing. Both refusal rows sit
under the reader account, run 1 the unknown format and run 2 the cap.

**Loopback store address.** The owner typed `http://127.0.0.1:4591`, the install's own server,
into the add store field, and the page refused with the exact policy sentence "That store
address points at this server or a private network. Use a public address for the store." The
`extension_stores` table still holds exactly the one community store row, so the refused attempt
wrote nothing.

**Re-walk.** All seven routes rendered at desktop width, 1440 pixels, and mobile width, 390
pixels: library, search, series, import, backups, sources and migrate. Four accessibility
observations, each present at both widths: the library grid's cover card link has no
accessible name, while the resume card's link is labelled correctly; the series page's cover
upload input and tags input have no accessible name, and the fourth scanlator group button is
labelled only a zero width space; the migrate page's destination checkboxes are label wrapped
but report no accessible name to the checker; the search result cover links' accessible name
is the content badge, not the series title. What the checker read as good on the same walk:
the chapter mark and note controls, the search add buttons, and the owner's import path field
are all labelled. These four observations are now in the 1.5.1 walk list as W-M5, W-M6, W-M7
and W-L5.

**Where the extension install boundary sits.** The security page states the deliberate set:
whole-install backup, restore, export and store add or remove require the install owner, and
extension install is deliberately absent from that list because it is gated by per user trust
instead. The live reader confirmed it: with no store trusted, `/sources` says nothing is
installable and shows no Install control. A reader can uninstall an extension that is already
installed, which is by design because the extension lives on the shared server. That is
posture, not a defect, and it is now written here so the next sitting does not rediscover it
as a suspected escalation.

**Six gates, on `d1629dc` with a clean status, before these two documents were edited:**
2,599 tests, 7,498 assertions, no failures, 520 seconds; PHPStan 0 errors; Pint clean; the
dash checker 656 files clean; the machine detail gate 636 tracked files clean; the checker's
own self tests 29 of 29 and 20 of 20. The dash and machine detail counts sit one under the
earlier 657 and 637 because the machine detail gate deliberately scans untracked but
unignored files and a stray file was deleted. After these two documents were edited the two
gates that read documents were re-run: the dash checker scanned 656 files and found none, the
machine detail gate scanned 636 tracked files and found none, and the checker's self tests
read 29 of 29 and 20 of 20.

**What was not done here, on purpose.** No chapter was read on the scratch install; reading
through the app proxy is proven by run 19 and the manual installer run, not by this sitting.
No import was ever applied; both import attempts were refusals, and the import happy path is
the suite's to prove. The owner's restore was not exercised: it would re-prove an action that
is allowed by design and would destroy the probe state mid sitting. H3 stays open as
accepted. M1 ships as a warning only, and L1 waits for 1.5.1.

### H3 behaviour change, 2026-10-27

Status: committed locally on 2026-10-27, not pushed. The manual proof passed on a fresh
disposable scratch install on 2026-10-28 and is recorded below, so H3 closes as a finding. The
`86e033a` artifacts predate the change.

What changed:

- A submission that installs extensions no longer imports directly. `RunBackupImport` gains
  `surveyForConfirmation`, which reads the file under the same guards as inspect, groups the
  missing packages by catalogue store, and writes the run as `awaiting` with the store list,
  signing keys, unresolved packages, and the reader's limit and force choices. The survey
  installs nothing.
- The review shows every store with its signing key and packages, with the boxes unchecked,
  and offers Import, Import without extensions, and Discard. Import records trust only for
  store ids the review payload names; a crafted extra id is ignored.
- The confirm continues the same run through a conditional `awaiting` to `running` claim. The
  file path and the limit and force choices come from the run row, never from the request, and
  the account's path rule is checked again at run time.
- `ExtensionInstaller::install()` now requires the importing reader's trusted store ids. A
  package installs only when the catalogue places it in a trusted store. An empty list
  installs nothing, a catalogue failure holds every package, and held packages are reported
  separately from attempted ones. `BackupImporter` reads the ids from the database at install
  time, so a store revoked after the review is not used.
- Stored uploads are deleted on confirm, on Import without extensions, and on Discard. An
  hourly `ImportReviewExpiry` closes reviews older than 24 hours as `expired`, deletes their
  stored uploads, and sweeps unreferenced uploads older than the same period. Expiry and
  confirm both use a conditional state update, so one cannot overwrite the other.

Tests added: `ImportReviewTest` for the survey row, refusal cleanup, path guard, confirm
reuse, trust re-read, and expiry and sweep; trust, held and catalogue failure cases in
`ExtensionInstallerTest`; and the request shape and trust read in `ExtensionManagerTest`.
`ImportPageTest` covers the review rendering, trust narrowing, and the three actions.

The mutation check that matters: an install that ignores the trust list, or a confirm that
takes its path or limit from the request, fails these tests.

### H3 manual walk, 2026-10-28

Environment: a fresh disposable packaged install built from `0a2407d`, reported as version
1.5.0, in a scratch directory outside the checkout and outside the reader's data. The scratch
Suwayomi used its own ports and data directory. A scratch-only first account was created; no
reader data or credential from the live install was used.

Setup before the review: a community store was added and trusted through `/sources`, the
catalogue refreshed to 1,431 extensions, and MangaTaro 1.6.0 installed and browsed. One Piece
opened, its chapters loaded, and chapter 1 rendered 53 pages with the first page image at
1,364 by 1,004 pixels. The extension was then removed from the scratch Suwayomi and the
scratch trust row deleted, so the review began with no MangaTaro source and no trusted store.
Those two setup mutations were applied to the disposable services directly.

The backup was synthetic: one library series, One Piece, on the MangaTaro package, with two
chapters, one marked read, in an `Imported` category. It was uploaded through
`/settings/import` with "Read the file first and report" cleared.

The review showed "Needs your answer", "1 source in this backup needs an extension this server
does not have", a community store with its index URL and full signing key, the MangaTaro
package at 1.6.0 with one series, and the store box unchecked. Before the answer the run row was
`awaiting`, its stored upload was present at 1,710 bytes, the database held zero trust rows, and
Suwayomi held no MangaTaro source. That is the fail-closed state: a reader who closes the page
gets nothing installed.

A first review, opened before the source mirror had been resynced after the direct uninstall,
reported the extension as already present. It was discarded through the page: the row became
`declined`, `file_path` was null, and the stored upload was removed. That is the discard arm of
the upload life cycle, measured rather than inferred.

The second review was answered by ticking a community store and pressing Import. The same row moved
through `running` to `complete`: the page showed all four phases done and "1 series imported, 0
already up to date." The trust row was written, Suwayomi installed MangaTaro again, the One
Piece mapping resolved to Suwayomi manga id 1 with two chapters seen and one applied, and the
stored upload was gone.

Read-back after the import: the library showed One Piece at 1 of 1,202 read, marked Imported
and attributed to MangaTaro EN; `/series/1` showed it in the library with Continue reading; and
`/read/1/1` rendered the 53-page chapter, with the first page image 1,364 by 1,004 pixels
through `/suwayomi/api/v1/`. The Sources page did not list the new source until it was synced,
but its existing callout said "1 installed extension is missing from the source list below" and
offered Sync sources, so the drift was named and recoverable.

Found alongside the walk, outside H3: `ExtensionManager::setInstalled($pkg, false)` sends
`patch: {install: false}`. Suwayomi 2.4.2366 accepted the mutation and left `isInstalled` true;
`patch: {uninstall: true}` removed it and dropped the source. The Sources page Uninstall
control therefore does nothing on this server. This was not changed under H3; it is recorded
in 1.5.1 below. The import also leaves the source mirror one sync behind after it installs an
extension, which is recoverable through the drift callout and is recorded there too.

## Release 1.5.1: remaining hardening and reader copy

Security remaining:

- M1, fixed on 2026-10-28: both reader-half writers empty `sessions` and `cache` and vacuum
  the copy. `ReaderHalfCapture` covers the in-app backup and `papyrus:snapshot`; the launcher's
  `ReaderSnapshot` child covers `papyrus snapshot` while the app is stopped. Live session ids
  and cached Suwayomi tokens do not travel. Both tables stay in the copy so the restore schema
  check still matches. The backups page warning now names what is left out and still calls the
  folder a secret. `RunLiveRestoreJobTest` seeds both, proves the pair carries neither, and
  proves a round trip preserves the live rows; `SnapshotDatabaseTest` and `ReaderSnapshotTest`
  prove both snapshot writers scrub.
- M4, fixed on 2026-10-28: `anilist_accounts.access_token` uses Laravel's `encrypted`
  cast and a data migration encrypts existing rows. The token is ciphertext in the database
  and in a backup; `APP_KEY` is the key. AniList publishes no token revocation endpoint, so
  disconnecting deletes the row but does not invalidate the token on AniList's side; the
  connections page says so. Tests cover the stored ciphertext, the upgrade migration and
  the page copy.
- M5, fixed on 2026-10-28: `Outbound::routes()` lists five connections: AniList title
  matching, AniList tracking, the manga source, an extension repository, and mail on a shared
  or public install. The privacy page describes the local account, library and session data
  instead of claiming it collects nothing, and a Cookies section names `papyrus-session`,
  `XSRF-TOKEN` and the optional `remember_web_` cookie with their flags. `LegalPagesTest`
  compares the rendered page with the outbound list and measures the guest and remember
  cookie flags; `FaqTest` holds the two AniList paths apart.
- L1, fixed on 2026-10-28: the showcase payload carries the manga id and cover URL, and no
  title. The controller no longer asks Suwayomi for a title batch, and the cache key moves to
  v2 so a payload cached before the shape change cannot outlive it. `ShowcaseTest` asserts
  the key set, the absent titles, and the absent `mangas(` request.
- L2, fixed on 2026-10-28: the readiness controller and `docs/SECURITY.md` say that
  `REMOTE_ADDR` is the transport's answer, not the client's identity, so a same-host reverse
  proxy makes every remote client look local and receive the readiness body. The page tells
  an operator not to forward `/ready` and to keep it on the loopback listener the launcher
  polls. A launcher secret or a separate loopback-only listener is deferred to a later
  release; the route stays loopback-gated until one of the two lands.
- L3: covered by the H3 behaviour change. Stored uploads are removed on confirm, on Import
  without extensions, on Discard, and by the hourly expiry after 24 hours. A run that throws
  keeps its file for retry, and the same sweep is the backstop. The `finally` form was
  rejected because it would delete a file a retry still needs.
- L6, fixed on 2026-10-28: `SecurityHeaders` is global, so guest and authenticated pages,
  `/ready`, downloads, covers and the Suwayomi proxy all carry `Content-Security-Policy:
  frame-ancestors 'none'`, `X-Frame-Options: DENY`, `Referrer-Policy: same-origin`,
  `Permissions-Policy` with the unused device features off, and
  `X-Content-Type-Options: nosniff`. HSTS is added only when the request is secure. The
  exception handler applies the same headers through `respond`, because Laravel renders a
  404 or 500 outside the middleware pipeline. `SecurityHeadersTest` covers a guest page, an
  authenticated page, `/ready`, a 404 and the HTTPS-only HSTS case.
- I2, fixed on 2026-10-28: every build and test package is now a `devDependency`, so a
  production install has no Node tree at all. `vite-plus` moves from 0.3.0 to 1.4.0,
  `vite` points at `npm:@voidzero-dev/vite-plus-core@1.4.0` with a matching override
  because 1.4.0 refuses a plain Vite, and `concurrently` moves to 10.0.6. `npm audit` and
  `npm audit --omit=dev` both report zero vulnerabilities, down from eleven packages and
  twelve advisories, and `npm run build` succeeds on Node 26.12.1. The machine-wide
  `node` moved from 26.7.0 to 26.12.1 with winget, and the build and both audits were
  re-run on the default path.

Functional fixes found during the H3 walk:

- Extension uninstall, fixed on 2026-10-28: `setInstalled()` now sends
  `patch: {uninstall: true}`. The old `install: false` returned success and left
  the package installed on Suwayomi 2.4.2366. The new test asserts the patch, and
  the fixed manager removed MangaTaro from the scratch server, which then listed
  only the local source.
- Source mirror after an import installs, fixed on 2026-10-28: `BackupImporter`
  calls `SourceCatalog::syncSources()` when an install landed. A refresh failure is
  reported on the run's progress line and does not fail the import; the drift
  callout still offers Sync sources. The test seeds a stale mirror row and confirms
  it flips back to installed.

Walk findings, fixed on 2026-10-28:

- W-M1: library check summary grammar and the disabled "Check 0 series" control.
- W-M2: import history rows with date, counts and a distinguishable identity.
- W-M3: migration destination filter and de-duplicated labels.
- W-M4: a `<main>` landmark and a skip link.
- W-L1: browse and series document titles include the source or series name.
- W-L2: backups table column label and the absolute path in the interface.
- W-L3: product copy and a cancel action on the confirm-password page.
- W-L4: import copy that says "above" when the picker is below.
- W-P1: keep `/search` clean until a search or filter is chosen.
- W-M5: the library grid's cover card link has no accessible name. The resume card's link
  carries `aria-label="Open {title}"` and is the shape to copy.
- W-M6: the series page's cover upload input and tags input have no accessible name, and the
  fourth scanlator group button is labelled only a zero width space.
- W-M7: the migrate page's destination checkboxes are label wrapped but report no accessible
  name to the checker.
- W-L5: the search result cover links' accessible name is the content badge, not the series
  title.

All thirteen are in `8afa652`. The full suite passed with 2,671 tests and 7,896 assertions,
and PHPStan, Pint, the dash check, the machine-detail check and its self-tests were clean on
the changed tree.

## Accepted risks and deferred items

- H3 for anything built from `86e033a`: those artifacts predate the behaviour change, so the
  old automatic install remains in them. The tree is fixed and its manual proof passed on
  2026-10-28; a build from the tree carries the review. Revisit if `shared` or `public` mode is
  promoted beyond an experiment.
- L4 guest session rows: 1.5.1 or later, unless public mode is promoted.
- L5 reader GET creates a reading session: data quality, not security. Schedule separately.
- I3 Suwayomi 2.4.2366 has no published advisory. No change.
- I4 positive controls: keep the signature gate, the proxy allowlist and the build allowlist.
- Installer signing, the historical machine-identity rewrite and PHPStan coverage of
  `tests/` remain owner decisions outside this plan.

## Risks and controls

- B1's clean-machine acceptance is met for the install tree: run 19 passed every gate check on
  Windows and read a source page through the app proxy. The runtime update and rebuild are done:
  PHP 8.5.12, FrankenPHP 1.13.2 and the corrected JRE trust store are in both artifacts. The
  installer remains unsigned after this plan.
- The installer payload is proven by the manual sandbox, not by B1. On 2026-10-26 the real setup
  executable installed 1.5.0 per-user and the reader flow passed, but the shortcut preflight hung;
  the fix is committed in `86e033a` and the artifacts were rebuilt from it. The fresh manual run
  on the rebuilt installer passed on 2026-10-27 through the shortcut. B1's gate
  still copies `build/install` and never runs the setup executable, so do not close the installer
  on the strength of a green install-tree run alone.
- The owner-role migration can lock an existing install out of backups if it promotes nobody.
  The migration must backfill the earliest account and the test must cover an install with
  existing users and no owner.
- M2 owner-only paths reduce functionality for non-owners. That is deliberate. The upload
  path stays available to every reader.
- M3 caps must be generous enough for a real backup. Choose the limits from measured fixture
  sizes, not a guess, and report the cap in the refusal.
- M7 host checks can be bypassed by a redirect or a DNS rebind after the check. Owner-only
  access is the primary control. Do not claim the request is fully SSRF-proof.
- M1 and M4 are fixed in the reader-half copy: `sessions` and `cache` are emptied and the
  file is vacuumed, and the AniList token is encrypted at rest. A copied backup still
  carries the token's ciphertext, password hashes and remember tokens, so the backups page
  still calls the folder a secret.

## Verification matrix

| Batch | Focused proof | Final proof |
| --- | --- | --- |
| A | first and second account role tests, posted role ignored, migration backfill | six gates |
| B | staged `php -v`, `frankenphp --version`, `java -version`, build assertions | installer and portable built; install tree passed the Windows Sandbox gate on run 19, including a source page read; source access also passed on WSL; the manual sandbox proved the setup executable installs 1.5.0 and the reader flow works, and found the shortcut preflight hang, fixed in the tree; the fresh manual run on the rebuilt installer passed on 2026-10-27, including the shortcut double-click; the rebuilt 1.5.1 portable passed clean-guest runs on C: and D: on 2026-10-29 |
| C | two-account page, action and export tests | scratch-install write probe, re-walk backups page |
| D | non-owner path refusal, crafted archive cap, store URL refusals | scratch-install probes, re-walk import and sources |
| E | warning copy and documentation assertions | dash and machine-detail gates |
| F | `composer audit`, focused suite | six gates |
| G | search count and copy, bulk-download ids across the page boundary | re-walk search and series |
| H | full six gates, write probes, re-walk | artifacts rebuilt from `86e033a` and verified against the manifest; the changelog entry is in place; the probes and the re-walk are recorded in the batch |

## Order

1. A, because C and D depend on the owner ability.
2. C, because it closes the two remaining High findings that do not need the runtime build.
3. D, because it closes the import and store request paths.
4. E and F, which are small and documentation or dependency work.
5. G, the two walk High findings.
6. B, last in the code order, because it needs a separate build and sandbox approval. It may
   run in parallel with A to G, but nothing may run from `build/install` while a build is
   rewriting it.
7. H, after every batch is reviewed.

## Resolved decisions

- The server-path import field stays for the owner only.
- The copy-only M1 backup warning ships in 1.5.0; L1 waits for 1.5.1.
- The runtime rebuild and sandbox are approved after Batches A, C, D, E, F and G are
  reviewed.
