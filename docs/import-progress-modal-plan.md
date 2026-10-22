# Import progress modal: plan

Plan for Phase 9 item 1, the import progress modal. The inline run rows on the
import page are the defect; this modal is the replacement. The backups run panel
is the same family and this plan follows its design grammar, already written up
in the Phase 9 walk plan (`1792691136277-phase-9-walk-what-the-rendered-app-still-gets-wrong.md`,
"The Backups run panel: the defect and the target" and "Worked design direction for
the modal").

## Steps

- [x] Read the import surface and confirm the phase boundaries against the code
- [x] Replace the inline run block with a single-line list plus a modal
- [x] Add the checkpoint timeline and the live progress bar
- [x] Add the final verdict and the single next action
- [x] Move polling from the container to the modal
- [x] Rewrite the tests that read the inline block
- [x] Run all six gates on the final tree
- [x] Browser walk in dark and light faces

## 1. Problem statement

The import page (`resources/views/pages/settings/⚡import.blade.php`) renders every
run as an inline block under "Your imports". A run that is in progress polls the
page every 3 seconds and re-renders the whole block, so the page jumps. The block
prints the run's note, a progress bar only while the series loop is running, and a
started-at line. There is no focus trap, no dismissal, no progress bar while
extensions are being installed, and no elapsed time. A running job looks like a
paragraph.

The inline rows are the defect for four measured reasons:

1. **No source of truth for the reader.** The run's state lives in `ImportRun`
   (running / complete / failed / refused) and the page re-derives a verdict from
   counters every render. A reader who opens the page mid-run cannot tell whether
   the job is alive, stalled, or finished.
2. **Polling never stops cleanly.** `shouldPoll()` returns true while a run is
   running, but the polling attribute is bound on the whole container, so every
   poll re-renders every run row, including finished ones.
3. **Refused, failed and abandoned read the same.** `stateLabel()` maps both
   `failed` and `abandoned` to "Stopped", and `badgeColour()` colours them both
   orange or red without a sentence. A reader cannot tell a refusal (the file was
   the wrong format, or every source is gone) from a crash (the worker was killed)
   from a stall (no worker is serving the queue).
4. **No elapsed time and no checkpoint.** The only time shown is
   `started_at->diffForHumans()`, which is relative to now and moves on every
   render. There is no "N of M series" that stays put, and no stage name a reader
   can parse: the run's `progress_note` is free text such as
   `  250 of 1277 series, 250 written, 0 unchanged, 0 not found, 0 could not be read`.

The import modal is the replacement: one modal per run, opened from a single line
on the page, with a checkpoint timeline, a live progress bar, a final verdict, and
one next action.

## 2. Measured facts from the code

### 2.1 The model

`app/Models/ImportRun.php`. State column: `running | complete | failed | refused`
(constants `RUNNING`, `COMPLETE`, `FAILED`, `REFUSED`). Counters: `series_in_file`,
`library_series`, `series_processed`, `chapters`, `resolved`, `from_catalogue`,
`from_source`, `sources_missing`, `not_found`, `ambiguous`,
`series_on_missing_sources`, `categories_in_file`, `category_members_placed`,
`category_members_skipped`, `applied`, `filed_without_source`, `unchanged`,
`read_chapters_applied`. Plus `format`, `schema_version`, `file_name`,
`refusal_reason`, `progress_note`, `started_at`, `finished_at`. `isRunning()`
returns `$state === RUNNING`. `completionPercent()` returns
`round(applied / library_series * 100, 1)` or null when `library_series` is null.

Verdicts in code versus human names:

- `RUNNING` (running) -> "Running"
- `COMPLETE` (complete) -> "Finished"
- `FAILED` (failed) -> "Stopped"
- `REFUSED` (refused) -> "Refused"
- abandoned (running for longer than `STUCK_SECONDS`, 300s) -> "Stopped"

So there are four states in the database and five verdicts on the page, because
abandoned is a derived state. The modal must keep all five distinct.

### 2.2 The job

`app/Jobs/RunBackupImport.php`. Timeout 3600s, backoff `[30]`, one attempt (no `$tries` property and no queue default).
Dispatches with `userId`, `path`, `limit`, `installExtensions`, `inspectOnly`,
`force`. `handle()` finds the user, refuses if the file is missing, then either
inspects (writes a `COMPLETE` run with `library_series` null) or imports.

On any `\Throwable` it calls `markDeadRun()`, which finds the latest run for the
user still reading `running` and marks it `FAILED` with `finished_at` now and the
exception message in both `refusal_reason` and `progress_note`. Rethrows.

### 2.3 The importer and its phases

`app/Import/BackupImporter.php`. `import()` writes the run row at the start with
`state = running`, `started_at = now()`, then:

1. Reads the backup (`BackupReaders::for($path)->read($path)`).
2. Updates the row with `format`, `schema_version`, `series_in_file`,
   `library_series`, `chapters`.
3. Installs extensions (survey + install), writing
   `extensions: N already here, M installed of K attempted, ...`.
4. Applies series in a loop, writing
   `  N of M series, A written, B unchanged, C not found, D could not be read`
   every 25 series (and at the end).
5. Applies categories, writing `categories: <name>: N of M members landed, skipped: ...`.
6. Marks the run `COMPLETE` with `finished_at` now.

`onProgress(callable)` lets a listener receive each line. `say()` writes the line
to `progress_note` (and `series_processed` when `$done > 0`).

So the run has no named stage list like the launcher's snapshot. It has phases:
extensions, series loop, categories. The progress note is the latest line. The
series loop is the only phase with a fraction (series_processed / library_series).

### 2.4 Polling cadence

The page polls with `wire:poll.3s` while `shouldPoll()` is true. `shouldPoll()`
returns false before any dispatch, and true after a dispatch until a run reaches a
finished state (or is abandoned). `STUCK_SECONDS = 300`. `QUEUE_WAIT_SECONDS = 30`.

`queueStalled()` checks the `jobs` table for a row older than 30 seconds when no
run is going, so a queued-but-never-started job is reported. `abandoned()` returns
true when a run is still `RUNNING` and `updated_at` is older than 300 seconds.

### 2.5 Active run detection

`activeRun()` returns `$this->runs()->first()` (newest by id, limit 20). `runs()`
queries `ImportRun::query()->forUser(auth()->id())->latest('id')->limit(20)`.

### 2.6 Abandonment

`abandoned(ImportRun $run)` returns true when the run is still `RUNNING` and
`updated_at` is more than 300 seconds old. The page treats silence as the end of
the run regardless of cause (worker killed, OOM, machine down).

### 2.7 What data is available per stage

- Before the loop: `format`, `schema_version`, `series_in_file`, `library_series`,
  `chapters`, `progress_note` (extensions line).
- During the loop: `series_processed`, `progress_note` (series line), plus the
  counters that only move at the end.
- After categories: `categories_in_file`, `category_members_placed`,
  `category_members_skipped`.
- On completion: all counters, `finished_at`, `progress_note` (the final summary
  line).
- On refusal: `state = refused`, `refusal_reason` set, `finished_at` set.
- On failure: `state = failed`, `refusal_reason` and `progress_note` set to the
  exception message, `finished_at` set.

There is no per-stage timestamp. `started_at` and `finished_at` are the only
times. Elapsed time must be computed client-side from `started_at`.

### 2.8 Verdicts that exist

Five on the page: Finished (complete), Running (running), Refused (refused),
Stopped (failed), Stopped (abandoned). Plus "Queued" (polling with no run row yet)
and "Inspecting" (inspect-only complete with library_series null).

## 3. Stage-name map

The import has no launcher-style stage list. It has phases, and the modal's
timeline is built from them. The map below is the honest one: code name to human
title to one-sentence description, in the order a run moves through them.

| # | Code name | Human title | One sentence |
| --- | --- | --- | --- |
| 1 | (none, pre-loop) | Reading the file | The backup is opened and its series, chapters and sources are counted. |
| 2 | extensions | Installing what it needs | The extensions the backup uses are surveyed and installed from the store. |
| 3 | series | Bringing your series across | Each series is matched to a source, and its chapters and read marks are written. |
| 4 | categories | Filing your shelves | The shelves named in the file are created and their members are placed. |
| 5 | (terminal) | Finished / Refused / Stopped | The run ends, and says what it did or why it could not. |

The series phase is the only one with a fraction (series processed of library
series). The extensions phase reports once at the start and once at the end, so it
has no fraction. The categories phase reports one line per shelf.

The modal's timeline therefore has four checkpoint rows (Reading the file,
Installing what it needs, Bringing your series across, Filing your shelves) plus a
terminal verdict. The "Reading the file" row is pending until `library_series` is
non-null, then done. The "Installing what it needs" row is the current row while
`progress_note` starts with "extensions:". The "Bringing your series across" row
is current while the note matches the series line. The "Filing your shelves" row
is current while the note matches the categories line. The terminal row carries
the verdict.

This is a coarser timeline than the backups run's six stages, and that is correct:
the import does not stop between phases the way a snapshot does, and inventing
sub-stages would be fiction.

## 4. Design direction

### 4.1 Modal primitive

Flux's `flux:modal`, the same primitive the backups page's "Back up now" confirm
uses (`resources/views/pages/settings/⚡backups.blade.php` line 1290). Width
`max-w-lg`, centered, with a `flux:modal.close` button top right carrying "Close"
as its accessible name. Backdrop `bg-zinc-950/60 backdrop-blur-sm`, matching
`x-confirm`.

### 4.2 Header

The run's verdict word as the title: "Finished", "Refused", "Stopped", "Running",
"Queued", "Inspecting". A status dot in the tone colour (green for finished,
amber for refused, red for stopped, pulsing zinc for running, zinc for queued).
The token line beneath it in muted text: the file name and, when known, the format
and version. Example: `library.tmb, Tachimanga format version 520`.

### 4.3 Body: checkpoint timeline

A vertical timeline. Each stage is a row: a 20px circle on the left (empty ring
for pending, filled zinc for done, filled accent with a spinner for running,
filled red for failed), a vertical connector line between rows, and on the right
the human title in `text-sm font-medium` plus the stage's own sentence in
`text-xs text-zinc-400`. The running row is the only one with the spinner.

Stages in order, from the map in section 3:

1. Reading the file
2. Installing what it needs
3. Bringing your series across
4. Filing your shelves

Each row shows one of pending, running, done, failed. The current row is the one
running; earlier rows are done; later rows are pending. A refused or failed run
marks the row it stopped on as failed and leaves the rest pending.

### 4.4 Progress bar

A thin bar under the header: `h-1 bg-zinc-800` track with a `bg-accent` fill driven
by `series_processed / library_series`, plus "N of M series" and elapsed seconds on
the right in `text-xs text-zinc-400`. While running the bar animates; when
finished it fills and stops. While extensions are being installed (no fraction yet)
the bar is indeterminate or hidden, and the stage sentence carries the line.

Elapsed seconds are computed client-side from `started_at`, which is the only
timestamp the run carries. The modal must not recompute "started X ago" on every
poll the way the inline block does; it shows a single elapsed counter.

### 4.5 Footer

One button, primary, labelled with the next action:

- Finished (import): "Done" closes the modal and leaves the run in the list.
- Finished (inspect): "Done" closes the modal.
- Refused: "Try again" re-opens the form.
- Stopped (failed or abandoned): "Show details" expands the stage walk.
- Running: a secondary "Close" link beside the primary, so a reader can leave
  without cancelling. No primary button while running.

### 4.6 Focus rules

On open, focus moves to the modal. On close, focus returns to the row or button
that opened it. Escape closes only when the run is finished, refused or stopped;
while running, Escape does nothing and the close button is disabled, so a reader
cannot dismiss a job mid-flight.

### 4.7 Escape and close rules

While running: no Escape, close button disabled. The reader can still leave the page
(the run continues on the server) but cannot dismiss the modal into a state where
they forget it is running. Once finished, refused or stopped: Escape closes, and
the close button works.

### 4.8 Polling behavior

The modal polls the run row while it is open and the run is running, at 3s, using
the same `wire:poll.3s` shape the inline block uses. When the run reaches a
finished state the poll stops. The page-level polling is removed: the modal is the
only thing that polls, and it polls only while open. The inline rows stop polling
entirely.

### 4.9 Inline block reduction

The "Your imports" list becomes one line per run: the file name, the badge, and a
"Show details" link that opens the modal. No progress bar, no note, no started-at
line in the list. The page stops jumping on every poll, because the list no longer
carries anything that changes.

## 5. Art direction

### 5.1 Tokens

Use the app's existing tokens, no new colours:

- Running stage and progress fill: `--color-accent` (zinc in this app's palette).
- Finished: `--color-success` (green).
- Refused: `--color-warning` (amber).
- Failed / stopped: `--color-destructive` (red).
- Stage sentences and muted text: `--color-muted-foreground`.
- Track: `bg-zinc-800` / `bg-zinc-200` in light.

### 5.2 Status dot tones

Green dot for finished, amber for refused, red for failed/stopped, pulsing zinc for
running, static zinc for queued/pending. Dot size 8px (size-2), matching the
backups page's stage dots.

### 5.3 Spinner

Flux's `loading` icon on the running stage only. No other spinner anywhere in the
modal.

### 5.4 Connector lines

A 1px vertical line between stage rows, `border-zinc-200 dark:border-zinc-800`,
connecting the circles. The line stops at the last row.

### 5.5 Circles

20px (`size-5`) circles. Pending: empty ring, 1px border, transparent fill.
Done: filled zinc with a check. Running: filled accent with spinner. Failed: filled
red with an x. Refused: filled amber with an x.

### 5.6 Spacing

Stage rows: `py-2` between rows, `gap-3` between circle and text. Modal padding
`p-6`. Header to body `mt-4`. Body to footer `mt-6`.

### 5.7 Typography

Title: `text-lg font-semibold`. Token line: `text-xs text-zinc-500`. Stage title:
`text-sm font-medium`. Stage sentence: `text-xs text-zinc-400`. Progress counts:
`text-xs text-zinc-400`. Verdict sentence: `text-sm`.

### 5.8 Dark and light

The app forces the dark face. Tokens are defined for both, and the modal must
render correctly in light because the test harness runs in light. Use `dark:` variants
only where a token does not cover the case. No hardcoded hex.

### 5.9 Motion

The progress bar's width transition: `transition-all duration-300`. The spinner:
Flux's default. No other animation. The modal itself uses Flux's default enter/exit.

### 5.10 Accessibility

- Focus trap inside the modal while open (Flux provides this).
- `role="dialog"`, `aria-modal="true"`, `aria-labelledby` pointing at the title.
- The progress bar carries `role="progressbar"`, `aria-valuemin`, `aria-valuemax`,
  `aria-valuenow`, `aria-label`.
- Stage list carries `role="list"` with `role="listitem"` per row, or use a `<ol>`.
- Status dots carry `aria-hidden="true"`; the verdict word carries the meaning.
- Keyboard: Tab cycles within the modal; Escape closes only when finished/refused/stopped.
- Screen reader: the verdict sentence is announced on change via `aria-live="polite"`
  on the verdict region.

## 6. UI/UX principles

1. **Single source of truth.** The modal reads one run row. The list reads the same
   row. Neither invents a verdict the other does not agree with.
2. **Polling stops when done.** `shouldPoll()` on the modal stops the moment the run
   reaches a finished state. The page no longer polls at all.
3. **No page jump.** The list carries nothing that changes during a run. Only the
   modal polls, and only while open.
4. **One next action.** Finished: Done. Refused: Try again. Stopped: Show details.
   Running: Close (secondary). Never two primary buttons.
5. **Refused and failed are distinct.** Refused carries the refusal reason and an
   amber tone. Failed carries the exception message and a red tone. Abandoned
   carries "Stopped" and red. The reader can tell which happened.
6. **Elapsed time.** A single counter from `started_at`, shown in the modal only.
7. **Checkpoint states.** Pending, running, done, failed. Every stage row shows
   exactly one.
8. **No know-it-all framing.** No "obviously", "simply", "of course". The modal
   says what happened and what to do next.
9. **Production grade.** Match the surrounding code's naming, comment style and
   structure. DRY: one seam per responsibility.

## 7. Files to touch

- `resources/views/pages/settings/⚡import.blade.php` - replace the inline run
  block (lines ~704-785) with a single-line list plus a modal. Add the modal
  markup. Add `openImportRunModal()` / `closeImportRunModal()` methods if needed,
  or use Flux's modal trigger. Keep `shouldPoll()`, `abandoned()`, `stuck()`,
  `queueStalled()` as they are.
- `app/Models/ImportRun.php` - possibly add a `humanStage()` or `currentPhase()`
  helper that maps `progress_note` to one of the four checkpoint rows. Optional;
  the blade can do the mapping inline. Prefer the model if two callers need it.
- `tests/Feature/ImportPageTest.php` and `tests/Feature/ImportProgressTest.php` -
  rewrite the assertions that read the inline block. The progress tests that assert
  on `progress_note` text still hold, because the modal reads the same field.

No new migration. No new job. No new model.

## 8. What could break

- The `wire:poll.3s` attribute moves from the container to the modal. If a test
  asserts the container still polls, it fails. Rewrite the assertion, do not weaken
  it.
- The `runs()` computed is cached on the Livewire instance. The modal's open state
  must trigger a fresh read, or a run that finished between polls will not show.
  Use a fresh `Livewire::test` lifecycle in tests, per the craft pass's lesson.
- The `percent()` method returns null before `library_series` is known. The modal
  must handle null without showing 0%.
- The `wasInspection()` branch: an inspect run has no series loop and no progress
  bar. The modal must show "Finished" with the inspect note and no bar.
- Focus return: the opener is a link in a list that may re-render. Capture the
  opener element before opening.
- Escape while running: Flux's modal does not disable Escape by default. The modal
  must add `@keydown.escape.window` handling or use `:dismissible="false"` while
  running.

## 9. Test plan

- Render the page with no runs: the list is empty and no modal is open.
- Render with a running run: the modal opens on click, shows the running stage,
  the bar, and elapsed seconds. Polling stops when the run completes.
- Render with a complete run: shows "Finished", the verdict sentence, and "Done".
- Render with a refused run: shows "Refused", the refusal reason, and "Try again".
- Render with a failed run: shows "Stopped", the message, and "Show details".
- Render with an abandoned run: shows "Stopped" and red.
- Render an inspect run: shows "Finished" with the inspect note and no bar.
- Keyboard: Escape closes only when finished/refused/stopped; does nothing while
  running.
- Focus returns to the opener on close.
- The list no longer carries a progress bar or a note.

## 10. Gates to run

All six, on the final tree, before any commit:

1. `php -d memory_limit=2048M artisan test --without-tty`
2. `php -d memory_limit=2G vendor/bin/phpstan analyse --no-progress --memory-limit=2G`
3. `php -d memory_limit=2048M vendor/bin/pint --test app/ tests/ packaging/`
4. `python ..\.tools\suwayomi\check-dashes.py`
5. `python tools\check-machine-detail.py`
6. `python tools\test-check-machine-detail.py`

Plus a browser walk of the page in both dark and light faces, with the modal open
in every state, before the commit.

## 11. Further improvements, ranked

1. Per-run "last restored" line on the pairs table once a restore run exists.
2. Filter input on the FAQ when the count passes 20.
3. Collapse the sources language row to a compact chip row on narrow widths.
4. "N sources, M dead" summary beside each health page site chevron.
5. Move the library notice's dismiss name into the component so it is there on
   first paint.
6. Adult content gate reachable from Settings as well as the sources page.
7. "Last restored" per pair row.
8. Show which source the reader came from on the migrate-sources page.

## 12. What this plan does not cover

- The backups run modal itself (already planned and partially built in Phase 7).
- The export run modal (same family; follow this plan's grammar when it is built).
- The restore run modal (same family).
- The clean-machine sandbox gate (parked on the owner's word).
- Any change to the importer's phases or counters.
- The FAQ's substance (Phase 8).
- F32 and F33 (Phase 9 unit 2).

## 13. House rules that bind this work

No em or en dashes anywhere. Match the surrounding code's naming, comment style
and structure. DRY. Comments explain why, not what. Fail loudly and specifically.
No know-it-all framing. A plan that has not been looked at against the code is a
guess wearing a plan's clothes; this one was read against
`resources/views/pages/settings/⚡import.blade.php`, `app/Models/ImportRun.php`,
`app/Jobs/RunBackupImport.php` and `app/Import/BackupImporter.php` before it was
written.
