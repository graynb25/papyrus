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