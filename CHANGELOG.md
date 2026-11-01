# Changelog

Every user-visible change, newest release first. Written for someone deciding whether to upgrade.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project uses
[semantic versioning](https://semver.org/spec/v2.0.0.html).

The current version lives in [`VERSION`](VERSION) at the repository root and nowhere else. A build
reads it, stamps it into the artifact, and refuses to run if the file is missing or malformed, so
there is no second number anywhere to drift.

**Reading this document.** "Fixed" means a reader could hit the bug. Several entries name the
symptom rather than the cause, because the symptom is what a reader recognises. Every entry is
checkable: each carries the commit that made the change.

> **Repository history note (2026-11-01).** The public history was rewritten on
> 2026-11-01 to remove the design-review screenshots and machine-identity values.
> Commit hashes in entries written before that date refer to the pre-rewrite
> history, preserved in the owner-held offline archive. New entries use the
> rewritten hashes.

---

## 1.5.2 - 2026-11-01

The first published release after 1.5.0. It includes the 1.5.1 hardening that was
built and proven locally but never published, the repository history rewrite, and the
redirect fixes found during the remediation review.

### Security

**The public repository history no longer carries the design-review screenshots or
the machine-identity values.** The rewrite removed `storage/design-review` from every
commit and replaced the measured machine-identity classes. The pre-rewrite history is
preserved in the owner-held offline archive; no personal values are reproduced here.

### Fixed

**A refusal or export redirect now names its destination.** The bare redirects were
replaced with the login, dashboard or AniList destination they meant, except the
documented CBZ download exception. A reader who followed a refusal no longer lands on
an unrelated page.

---

## 1.5.1 - 2026-10-28 (not published)

The hardening release after 1.5.0. It closes the remaining Medium and Low findings from the
[2026-10-25 audit](docs/audits/2026-10-25/security.md) and the functional, copy and
accessibility defects the H3 manual walk found.

### Security

**An import asks before it installs extensions.** An import that carries the "Install the
extensions this backup needs" choice now surveys the backup first and stops at a review
grouped by catalogue store: signing keys, packages, unresolved packages, and the reader's
limit and force choices. Nothing installs until the reader ticks a store and confirms, and
the boxes start unchecked. Stored uploads are deleted on confirm, Import without extensions,
Discard, and expiry. The manual walk passed on the fixed installer. `8d5c94e` `0840723`

**A backup's reader half no longer carries live sessions or cached tokens.** Both reader-half
writers empty `sessions` and `cache` and vacuum the copy: the in-app backup and
`papyrus:snapshot` share one capture, and the stopped-app snapshot uses the launcher's child.
The tables stay in the copy so the restore schema check still matches, and a round trip
preserves the live rows. `658efb0`

**AniList tokens are encrypted at rest.** The token uses Laravel's encrypted cast, and a data
migration encrypts the plaintext rows written by earlier releases. The token is ciphertext in
the database and in a backup, and `APP_KEY` is the key. AniList publishes no token revocation
endpoint, so disconnecting deletes the row but leaves the token valid on AniList until it
expires; the connections page says so. `ff271bd`

**The privacy page names every outbound path and cookie.** It now lists five connections:
AniList title matching, AniList tracking, the manga source, an extension repository, and mail
on a shared or public install. The page describes the account, library and session data kept
in the local database instead of claiming it collects nothing, and a Cookies section names
`papyrus-session`, `XSRF-TOKEN` and the optional `remember_web_` cookie with the flags the
browser receives. `82974f2`

**The login wall no longer publishes library titles to guests.** The showcase payload
carries the manga id and cover URL only, and the controller no longer asks Suwayomi for a
title batch. The cache key moves to v2, so a payload cached before the shape change cannot
outlive it. `089b341`

**The readiness page's proxy blind spot is documented.** `/ready` answers only a loopback
transport address, which is right for the launcher's direct connection. Behind a reverse
proxy on the same machine every remote request arrives from loopback, so every remote client
receives the readiness body. The controller and `docs/SECURITY.md` now say so and tell an
operator not to forward `/ready`. A launcher secret or a separate loopback-only listener is
deferred to a later release. `e10d8fa`

**Every response carries browser security headers.** Framing is refused with
`frame-ancestors 'none'` and `X-Frame-Options: DENY`, reader URLs stay out of cross-origin
`Referer` headers, unused device features are turned off with `Permissions-Policy`, and
`X-Content-Type-Options: nosniff` is sent everywhere. HSTS appears only on an HTTPS request,
and error pages carry the same headers. `dffc531`

**The build toolchain's npm tree has no open advisories.** Every build and test package is
a dev dependency, so a production install carries no Node tree at all. `vite-plus` moves to
1.4.0 with `vite` resolved to its core package, and `concurrently` moves to 10.0.6.
`npm audit` and `npm audit --omit=dev` report zero vulnerabilities, down from eleven
packages and twelve advisories. The machine-wide Node install moves from 26.7.0 to
26.12.1, the October security release, and the asset build was verified on it. `3de8e13`

### Fixed

**Uninstall removes the extension and its source.** The Sources page sent `install: false`,
which Suwayomi accepted and ignored, so the extension stayed installed. It now sends
`uninstall: true`, which removed MangaTaro on the scratch server. `18a2f31`

**An import that installs extensions refreshes the source mirror.** The new sources appear
without a manual sync, instead of leaving the mirror one sync behind. `440c271`

**The console import says one extension in the singular.** A run that installed a single
extension printed the plural line. `0a2407d`

**The library's check summary says what is actually true.** It separates series that are due,
series never fetched, series checked recently and series with no source, and the button says
`Checking...` while a pass runs and `Nothing to check` when nothing is due. A series filed from
a backup has no source, so it can never be checked; it is named instead of being counted as up
to date. `8afa652`

**Two imports of the same file can be told apart.** Each history row now carries an absolute
date and time and the run's own result sentence, so a recent run and an older one no longer
look identical until opened. `8afa652`

**The source migration picker can be filtered.** The installed list measured 238 destinations,
which is a scroll rather than a choice. A filter narrows it by the label each checkbox shows,
the language is written once instead of twice, and every checkbox carries its label as its
accessible name. `8afa652`

**Browser tabs name the page they show.** Browse and series tabs used one static word each, so
two open catalogues or two series were indistinguishable in the tab strip. They now carry the
source or series name, and a series the server cannot name falls back to its id. `8afa652`

**The backups page names the folder instead of printing its path.** It says the folder is named
`backups` beside the database, and the column that shows whether a backup still matches is
called Health rather than Files. `8afa652`

**The confirm-password page says what the password is for.** It explains that Papyrus needs the
password again before the change, and offers a link back to the library for a reader who did
not mean to be there. `8afa652`

**The import guide points at the picker below it.** It said to pick the file above while the
file input sits below the guide. `8afa652`

**A search link carries no filter until the reader chooses one.** The default rating filter was
written into the address bar on every search, so a shared link carried filters the reader never
set. `8afa652`

**The shell has a main landmark and a skip link.** The page content is a native `main` with a
stable id, and the first link in the body jumps to it, so keyboard and screen-reader readers no
longer traverse the whole navigation on every page. `8afa652`

**Cover links and inputs have accessible names.** Library and search cover links say
`Open {title}` instead of being hidden from assistive technology, the series cover upload and
tag inputs are named, and a scanlator whose name is only whitespace or a zero-width space is
treated as no scanlator rather than as an unnamed group. `8afa652`

---

## 1.5.0 - 2026-10-26

The security release after 1.4.0. Whole-install actions now belong to the install owner, the
import and store paths refuse the cases the audit measured, and the packaged runtime moves to
the PHP, FrankenPHP and Java builds that close the October advisories. The two High findings
from the walk are fixed. The findings and the batch record are in the
[2026-10-25 audit](docs/audits/2026-10-25/security.md).

### Security

**The install has an owner.** The first account, whether created in the browser or with
`users:create`, becomes the install owner; an existing install promotes its first account once.
The new `manage-install` ability gates the whole-install actions: the Backups page, a restore, a
library export, and adding or removing an extension store. A reader keeps every other page, and
the Backups link is no longer offered to an account that cannot use it.

**Import and stores refuse the measured attack cases.** The server-path import field is owner
only, uploads are contained inside the import folder, and a staged archive is capped at 256 MiB
per entry and 384 MiB in total, with a 16 MiB cap per Tachibk field. A store address must be
http or https and cannot resolve to loopback, a private or link-local address, multicast, the
unspecified address, or an alternate spelling of loopback. Adding or removing a store is owner
only.

**The packaged runtime is current.** FrankenPHP 1.13.2 ships PHP 8.5.12, which closes
CVE-2026-109618 and CVE-2026-109619, and carries the fixes for four FrankenPHP advisories:
GHSA-6q3j-62j9-f9hh, GHSA-fq24-r53r-xcx3, GHSA-ccgp-ch9h-533f and GHSA-4gfw-9v29-gp9h.
The packaged Java runtime moves to Temurin 25.0.5+8 for the October CPU and the compromised
eMudhra roots are removed from its trust store.

**Livewire 4.4.8.** The update closes GHSA-mhf7-xp6r-6qrc, an authorization bypass in
`#[Authorize]` with `#[Json]`. Papyrus uses neither attribute, so direct exposure was unlikely,
but the dependency finding is closed.

**A backup pair is a secret.** The Backups page now says plainly that a pair contains the whole
application database, including live session rows, cached manga-server tokens, an AniList token
and password hashes, and that the folder must be treated as one. Excluding sessions and cache
from the reader half is planned for 1.5.1; this release warns rather than changes the shape of a
backup.

**Import can install source extensions.** A Tachimanga import can install the extensions the
file names so its series can resolve. The import page states this before the run. The install
owner accepted the behaviour for 1.5.0; the code path is unchanged.

### Fixed

**Search says what it actually asks.** The page used to say it searched every installed source
while the fanout was capped at 40. It now names the cap, the runnable total and how many
runnable sources the cap leaves out, so a reader cannot read the page and believe every
installed source was queried. Walk finding H1.

**Download unread ignores read chapters on other pages.** The bulk action read its read state
from the 50 chapters on the open page, so a read chapter on any later page looked unread. It now
reads the whole filtered series in chunks. Walk finding H2.

**The reader's shortcut no longer waits for a script.** `Papyrus.cmd` ran `php -n` to check the
runtime, and PHP with no script reads one from stdin. A double-clicked console leaves stdin open,
so the window sat blank before the launcher printed anything and no data directory was created.
The check now runs `php -n -v`, which exits without reading stdin. The gate and the tests had
redirected stdin from an empty file, which is why only the manual installer run found it.

The remaining Medium, Low and copy findings from the audit are scheduled for 1.5.1.

---

## 1.4.0 - 2026-10-25

The release after 1.3.0: the last UI/UX audit findings, time and navigation in plainer words, a
library check that can be stopped, reading time carried in from a backup, and backup uploads as
large as the server allows. 2026-10-20 to 2026-10-25.

The portable builder exists, but a portable archive is not published and its clean-machine first
launch is not proven. The distributable release remains the Windows installer. See the
[roadmap](ROADMAP.md) and [portable status](docs/PORTABLE.md) for details.

### Added

**Papyrus has its own mark.** The seal the empty library already used is now the application's mark:
the sidebar and the auth screens, the browser tab, the Windows shortcut, the installer and the
uninstaller all read one vector. The book is the Heroicons `book-open` outline, MIT. The 16, 24 and
32 pixel frames are drawn for those sizes rather than reduced from the 512, because a faithful
reduction of the outline at tab size is a red tile with a grey smudge. `26419e6`

**Reading time carried in from a backup now counts.** A Tachimanga `.tmb` backup carries daily
reading time, and it is now kept in its own ledger keyed by the file's sha256, so re-importing the
same file replaces that file's days without touching time Papyrus recorded itself. Insights adds
the two together, labels the imported part, and takes the largest value for a series and day when
two exports overlap. A `.tachibk` still carries read marks but no reading time. `8e26a23`

**A way back from Sources and Source health.** Sources offers Back to your library, Source health
offers Back to sources, and Migrate sources uses the same shared header, so a subpage is no longer
a place a reader has to escape with the browser's back button. `8481d8d`

**Sources are easier to choose.** The Sources page keeps its language filter for the extension catalogue. Installed site cards show a direct flag link for every language variant, along with the site count and health status; choosing a flag opens that source without expanding a long card. The mirror-drift notice gives a count and a sync action rather than a wall of extension names. `b4794cc` `73f8c8e` `942e544`

**The reading path is clearer.** The library points new readers to Sources, an empty chapter list explains the next step, and the reader has a visible return link to its series. Covers and pages use responsive image sizes through the same-origin proxy. `c0e0a2b` `ebde5b2` `0ae5053`

**The app explains recovery in reader-facing language.** Import, backup, security and source guidance now describe what happened and what the reader can do next. Adult content remains off until the reader confirms they are 18 or older. `6e0d77a` `fb2b8de` `7581156` `d1ed794` `bae1a3d`

**The questions the app can now answer.** The FAQ gained six answers written from the code that
does the work: what a search asks once you pin a site, what a block does and how long the wait is,
what happens when a series exists with no chapters, how to move one series or a whole shelf, what a
backup covers and what it never promises, and where the licences are acknowledged. The backup
answer now says what the Backups page actually does instead of telling you to copy a folder with
the app closed, and Settings has its own copy of the questions, so answering one no longer throws
you out of the app. `7ee558f` `3aa8d74`

**Your library can back itself up without stopping anything.** The Backups page now takes its own
pair of backups while the app and the manga server keep running, puts one back the same way, and
sends your shelves and your read marks out as a `.tachibk` file another reader app can open, with a
report of what was matched, what was skipped and why. The page no longer tells you to open a
terminal. `58919fe` `f9ce674` `3c9a4c5` `41f34f1` `f50de31`

### Changed

**Time is written the way a reader says it.** Insights and the import and backup panels say
`1 minute 30 seconds` instead of `90`, and never print a raw difference such as
`1273352.650004s`. Durations keep at most three units, largest first, and a duration shorter than a
minute keeps its seconds. Each saved backup also shows the date and clock it was taken beside its
age, so it can be matched against a message without converting a relative phrase. `50a73e0`

**Privacy says what Papyrus sends, and a default install sends nothing.** The welcome mail and the
command line flag that suppressed it are gone. Privacy has a section called Mail, and when it is
sent, and states that a default install sends nothing. `078570d`

**The UI/UX audit is closed.** A refused backup names an empty server plainly and marks the run,
while the elapsed timer stays with a run that is still running. Sources turns the Healthy pill moss
and shows a source's flags with a health drill-down. Health caches its liveness probe for 60
seconds, filters sites and paginates them. The library keeps five continue-reading cards and puts
unread, new and stale where the eye lands first. `43bedce`

### Fixed

**A library check no longer waits forever on one series.** A measured pass could hang on a source
that never answered, leaving the page at the same fraction with no way to stop it. Each series now
has a 15 second ceiling inside the slice's 45 second budget, Stop is read between series, and the
run's counters and finished rows are stored, so a reload restores the progress and a stopped run
reports how far it got and what it found. Stopping keeps every series already checked. `f49e566`

**A real backup is no longer refused for its size.** The measured 41MB Tachimanga backup did not
fit through the development limits, so the import page could offer an upload that could never
arrive. The packaged install now permits a 256MB upload and a 260MB post, the page and the upload
endpoint read that same server ceiling, and the `.tachibk` reader walks the archive in a stream
instead of holding it in memory whole. `7dd24f3`

---

## 1.3.0 - 2026-10-19

The first release since 1.2.0: the sources and shelf work of five feature phases, the licences
ledger, the reading insights page, and the reading room's craft pass. 2026-10-16 to 2026-10-19,
accepted in the browser as each phase landed.

### Added

**Move a shelf between sources as one guided run.** A Migrate tab on the sources page picks up to
five destinations for one source, compares series by series, asks before it carries anything, and
its card reports what it moved and where a run stopped. Each landed row shows its progress on its
own cover. `27aea65` `2c22df5` `2142d43` `f7de17a` `446e32f`

**Pinned sources decide what search asks.** A pin on a source scopes the search fanout to the
pinned set, and every count on the page says what it covers. `3cac973`

**The licences page.** Every package the install carries states its licence and links the text it
reads from, an absent text is refused or marked as measured rather than invented, and the ledger
holds a reader's acknowledgements, re-arming them when the version or the licence moves.
`36d2ecb`

**Reading insights.** The reading session ledger writes one row per chapter open at the seams the
reader already has, and `/insights` sums it into time per series, pages turned and readings, behind
the reader's own scope. `b05f624`

**Safer sources.** Flagged sources carry their conduct copy at the install press rather than on
every card, a store's catalogue loads itself after it is added, and a reader with no installed
source is sent to Sources instead of an empty search. `4f9e7cf` `c096d4c` `0d3c586`

### Changed

**The reading room had its craft pass.** The FAQ is rebuilt on the app's own disclosure pattern with
a real footer on every public shell, insights bars carry their covers, and the login passwords met
the audit's margins. `59fb26e`

### Fixed

**The bulk move's time window covers the measured walk.** The job window moved from 1800 to 3600
seconds from six measured runs, so a large move does not die at the middle of the shelf.
`4460a01`

**The mid flight rows read ready only.** A comparison table now appears while a run is still
flying, exactly one row per landed compare, so the report builds in front of the reader rather than
afterwards. `5bc4cb8`

---

## 1.2.0 - 2026-10-12

The release where Papyrus became something a reader installs and runs, rather than something a
developer runs from a checkout. Thirty-nine commits, 2026-10-10 to 2026-10-12.

### Added

**A Windows installer.** One `.exe`, per user, no administrator rights, uninstalling never touches
your library. `fed4d2f`

```
Papyrus-1.2.0-setup.exe    261.2 MB
```

**A portable runtime that runs from a folder with nothing installed.** FrankenPHP carrying PHP 8.5,
a staged JRE, and Suwayomi, launched by a batch file. `c175fc3` `6f7777f`

**Your data somewhere it can actually be written to.** `%LOCALAPPDATA%\Papyrus`, never under Program
Files, which is read-only for a normal account and fails every SQLite write. `6f7777f`

**A diagnostics bundle.** One command collects the logs of all four processes into a single archive
you can attach to a bug report, rather than asking a reader to send four files from four places.
`c31a2c8` `1ea1cda`

**Daily backups of your library.** Suwayomi already takes its own daily snapshot; Papyrus now takes
ours at 03:17, covering the half Suwayomi cannot see: read state per series and per chapter, shelves,
settings, and the import ledger. 867 series and 21,447 chapter states exist in no other file.
`a9830ab`

**A Suwayomi version check.** Papyrus records the Suwayomi release it expects and says plainly when
the running one is too old, rather than leaving you with GraphQL errors about fields that do not exist.
`3bf432f`

**A Settings page showing both versions.** Settings now has an **About** page with the Papyrus version
and commit, the Suwayomi actually running, and the one this install expects. `4b9eb1f`

**Your library choices actually persist.** Chapter order per series, and library shelf, sort and
search, were all shown but forgotten on reload. `1d77b2f`

**A queue that says when it is not draining.** `papyrus health` reads the jobs table rather than
looking for a worker process, and reports how long the oldest job has been due. `8487613`

**Workers for a checkout.** `packaging/dev-workers.ps1` starts the queue and the scheduler, and
`-Status` reports what is up. `8487613` `d3068b5`

### Fixed

**Your downloads were never being downloaded.** The packaged install's scheduler had dispatched
nothing since it was first installed. `artisan schedule:run`, the process that actually runs the
schedule, died every ten seconds with `could not find driver` and had failed 182 times while
`health` reported everything green. `52271cc`

The cause was one level below where anyone looks: `schedule:work` shells out to `schedule:run` without
passing it the ini, so the grandchild loaded no extensions at all. Your library was a reader app with
downloads switched off, reporting itself healthy throughout.

**`health` said a wedged scheduler was running.** It asked whether a process existed. A scheduler
that had stopped dispatching entirely still answered yes, for six minutes, with every other line
green. It now measures whether the scheduler is still writing. `a705fe4`

**A worker that looked healthy and drained nothing.** Twice. Once because `health` counted a
`queue:work` belonging to a different install, and once because a worker started through Herd's
`php.bat` comes up, appears in the process list, and never connects. `8487613` `d3068b5`

**A library check that stalled for 70 minutes.** The page said "Checked 0 of 670 series" with no way
to tell a slow queue from an absent one. `8487613`

**Choices that looked remembered and were not.** Chapter order, shelf, sort and search all reset on
reload. `1d77b2f`

**Your library was written inside the install folder.** Logs, cache and cover art, all under Program
Files, where a normal account cannot write. `c175fc3`

**A fresh install said "Papyrus did not start" over no lines.** `cmd.exe` refused to execute the
runtime and said only "Access is denied." Antivirus does this to a freshly written 623MB folder. The
launcher now probes the interpreter first and names the file. `fed4d2f`

**Uninstall left the browser running.** Suwayomi's embedded browser is launched by the JVM, not by
the launcher, so nothing ever looked for it, and `jcef_helper.exe` stayed open inside your data
folder. `fed4d2f`

**`stop` could not stop an install it did not start.** It matched on the install root, and an install
under `%LOCALAPPDATA%` has its data in a different place. `c9ffbe6` `d89b2ba`

**A space in a path broke the install.** A value was rewritten without quoting, so a data directory
containing a space, which is every default Windows user profile, could not be installed. `acdd47c`

**Continuous integration was red for 24 runs.** CI ran on `ubuntu-latest` for a Windows-only product.
Four tests failed honestly and a fifth thing was worse: on Linux the process-matching tests found
nothing and passed vacuously, so a green run would have reported the packaging layer as covered while
testing none of it. `559a7dd` `5ce3bea`

**Suwayomi was told the wrong port and the wrong data path.** `ef46d15`

**An install that could not reach Suwayomi started anyway**, and looked healthy. `bbd5446`

### Changed

- Four processes now share one clock, so a log line's timestamp means the same thing in all four
  files. `8632ad0`
- `packaging/` is analysed by PHPStan. Four defects were hiding in the layer it had never covered.
  `4fba764`
- CI names the PHP extensions a Windows runner does not enable by default, taken from the packaged
  runtime's own `php.ini`, so CI and the shipped artifact agree about what PHP is. `5ce3bea`

### Documentation

- [`docs/ENGINEERING-NOTES.md`](docs/ENGINEERING-NOTES.md) grew to 226 numbered lessons
- [`docs/DESKTOP-PACKAGING.md`](docs/DESKTOP-PACKAGING.md): defect table to 24
- [`docs/DISTRIBUTION.md`](docs/DISTRIBUTION.md), [`docs/LOGGING.md`](docs/LOGGING.md),
  [`docs/SETUP-SUWAYOMI.md`](docs/SETUP-SUWAYOMI.md)
- Six new documents, written after the release rather than during it: [ARCHITECTURE.md](ARCHITECTURE.md),
  [docs/INSTALL.md](docs/INSTALL.md), [docs/PORTABLE.md](docs/PORTABLE.md),
  [docs/SOURCES.md](docs/SOURCES.md), [ROADMAP.md](ROADMAP.md) and this one.

---

## Before 1.2.0

There is no 1.1.0 or earlier. The project began on 2026-09-29 and 1.2.0 is the first tagged shape.
Everything before it was a working checkout, and the numbered record of how it got there is in
[`docs/ENGINEERING-NOTES.md`](docs/ENGINEERING-NOTES.md), which is chronological and complete.

If you were running Papyrus from a checkout before this release, the most likely thing to hit is
that your reader records were written to the checkout's own `database/database.sqlite` rather than
anywhere an installer would look. See [docs/INSTALL.md](docs/INSTALL.md#migrating-from-a-checkout).