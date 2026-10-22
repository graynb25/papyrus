# UI/UX pass proposal

A proposal pass over the whole app, written for the owner to take one item at a
time. Nothing here is implemented; each block carries its own preview so the
owner can see the change before deciding. Severity, surface, what the reader
sees today, why it is wrong or could be better, the concrete proposal, the
files it would touch, the estimated cost, and a preview.

Severities: S data loss, credentials, or the library reachable by someone it
should not be. H the feature does not work for the reader, or a gate cannot
detect a real failure. M correct but costly or unverifiable without effort.
L housekeeping, wording, consistency.

Grouped by surface, ranked within each group. The order of groups is the order
a reader meets them, from the first run to the settings page.

Walked in a real browser against the standing stack on 2026-10-22, signed in as
a throwaway account minted for the walk. Screenshots are in the workspace's
`.openchamber/screenshots/` with names that carry their own timestamp and what
they show. Mockups are rendered with the app's own tokens so they read as the
app.

## Surfaces

- Login and public shell
- Library
- Search and browse
- Sources
- Health
- Backups
- Migrate and migrate sources
- Import
- Licences and connections
- Insights
- Series and reader
- Settings and the in-shell FAQ, privacy and terms
- The empty and error states

## Login and public shell

### 1. The login page's footer links are the only navigation a signed-out reader has

Severity: M

Surface: Login (`/login`), and the auth shell footer

What the reader sees today: A signed-out reader lands on a centred card with the
promise line and a footer of four links (FAQ, Privacy, Terms, Log in). The
footer is the only map of the app a reader has before they trust it, and it
sits at the bottom of a tall page with a lot of empty space above it on a
desktop width.

Why it is wrong or could be better: The reader who has just run an unknown
download is the one who most needs to read what the app collects, and the
footer is the only door. On a wide screen the page reads as one card floating
in space, and the footer's links are small and quiet against the promise line.
The promise line itself is the strongest sentence on the page and it is the
one thing a reader should see first.

Concrete proposal: Tighten the vertical rhythm so the card sits in the upper
third of the viewport on desktop, keep the footer where it is but give its links
the same weight as the promise line's second clause, and put the promise line
first in the reading order. No new component, no new copy. The footer stays
shared by the simple shell and the content shell so it cannot drift.

Files it would touch: `resources/views/pages/auth/login.blade.php`,
`resources/views/components/auth-footer.blade.php`,
`resources/views/layouts/auth/simple.blade.php`.

Estimated cost: 20 minutes.

Preview: The current login page is captured as
`uxpass-login-desktop-2026-10-22T...jpg` in the walk set; the mockup below
shows the proposed rhythm with the promise line first and the footer links at
equal weight.

### 2. The register route answers 404 with no link back to sign in

Severity: L

Surface: `/register` when registration is closed

What the reader sees today: "This shelf does not exist. The page you asked for is
not here." with one button, "Back to the reading room". A reader who typed the
URL expecting a sign-up form gets a 404 that reads like a broken page.

Why it is wrong or could be better: Registration is closed on purpose, and that is
fine, but the 404 page does not say so. It reads as a dead link rather than as
a decision. The reader's next step is to sign in, and the page does not offer it.

Concrete proposal: Replace the generic 404 with a short, honest line: "Sign-up
is closed on this install. If you have an account, sign in." with a link to the
login page, and keep the generic 404 for everything else. One Blade branch on
the registration flag.

Files it would touch: `resources/views/errors/404.blade.php` or the route's own
handler, `routes/web.php`.

Estimated cost: 15 minutes.

Preview: The current 404 is captured in the walk set as `02-register-404-...jpg`.
The mockup shows the same card with the honest sentence and a sign-in link.

## Library

### 3. The empty library's notice dismiss button is named after the copy is made

Severity: M

Surface: Library, empty-library toast

What the reader sees today: The toast's dismiss button has no accessible name
on first paint; the name "Close notification" is applied by the shell script
after the copy is made. A screen reader meeting the notice before the script runs
hears an unnamed button.

Why it is wrong or could be better: The naming seam lives in the wrong place for
a control that appears with the notice. The fix is to move the name into the
component so it is there on first paint. Already flagged in the Phase 9 walk
findings as item 6 and held here as a proposal rather than a change.

Concrete proposal: Put `aria-label="Close notification"` on the button inside the
toast component's own template, and remove the post-hoc naming from the shell
script. One attribute, one deletion.

Files it would touch: the empty-library toast component,
`resources/views/components/behaviors.blade.php`.

Estimated cost: 10 minutes.

Preview: Captured as `p9-library-toast-desktop.jpg` in the walk set.

### 4. The library notice is the only thing on an empty shelf

Severity: L

Surface: Library, empty state

What the reader sees today: An empty library shows "Good reading. Nothing added
yet." with an "Add series" button, "Go to search", "Bring a library from another
app", and a notice card "This library is empty" with its own close button. The
notice and the empty state say the same thing twice.

Why it is wrong or could be better: Two surfaces for one fact. The notice is the
louder one and it competes with the empty state's own copy, which is the one
that carries the doors.

Concrete proposal: Drop the toast notice on an empty library and let the empty
state carry the message and the doors on its own. Keep the toast for genuine
errors and for the "series added" confirmation, which are different facts.

Files it would touch: `resources/views/pages/library.blade.php`,
`app/Livewire/Library.php` or wherever the notice is raised.

Estimated cost: 20 minutes.

Preview: Captured as `04-library-empty-...jpg` and `p9-library-desktop.jpg`.

## Search and browse

### 5. The search page's query input is named by its placeholder

Severity: M

Surface: Search, query input

What the reader sees today: The query input's visible caption is a Flux label
that renders as a `<ui-label>` rather than a `<label>`, so on first paint the
input's accessible name comes from its placeholder ("Series title"). The caption
is wired to the input by the component's own script, but only after hydration.

Why it is wrong or could be better: A placeholder is not a name, because it goes
the moment the reader types. The input needs a real label that points at it by
`for` and `id`, so the name survives typing and is there before hydration.

Concrete proposal: Add a visible `<label for="search-query">` (or `aria-labelledby`)
to the input and give the input the matching `id`. Keep the placeholder as a
hint. One line in the component and one in the Blade.

Files it would touch: `resources/views/pages/search.blade.php`,
`app/Livewire/Search.php` if the id is bound there.

Estimated cost: 15 minutes.

Preview: Captured as `p9-search-desktop.jpg`.

### 6. The browse page's source picker is a wall of sources with no grouping

Severity: L

Surface: Browse, source picker

What the reader sees today: A grid of source cards with their icons, each one a
door into that source's catalogue. On a wide screen the grid is wide and dense,
and there is no grouping by language or by whether the source is already
installed.

Why it is wrong or could be better: A reader looking for one scanlation site has
to scan the whole grid. Grouping by language (the same grouping the sources page
already uses) would make the picker scannable without changing what it offers.

Concrete proposal: Group the picker's cards by language with a small heading per
group, reusing the language-name map the sources page already uses. No new data,
no new query.

Files it would touch: `resources/views/pages/browse.blade.php` or the picker
partial.

Estimated cost: 30 minutes.

Preview: Captured as `p9-browse-desktop.jpg`.

## Sources

### 7. The sources page's language row is heavy at desktop width

Severity: L

Surface: Sources, language row

What the reader sees today: 23 language pills wrapping over three lines at desktop
width, each carrying its count. Functional, but visually heavy.

Why it is wrong or could be better: Optional polish. The pills are the owner's
chosen shape, so this is a compaction rather than a redesign: collapse to a
compact chip row with a "more" affordance on narrow widths, and keep the full
row on desktop.

Concrete proposal: Make the row a horizontally scrolling chip strip below a
threshold width, with a fade and a "show all" toggle, and leave the wrapping row
on desktop where there is room.

Files it would touch: `resources/views/pages/settings/sources.blade.php`.

Estimated cost: 25 minutes.

Preview: Captured as `p9-sources-languages-desktop.jpg`.

### 8. The sources Available tab shows the adult content badge and button as two actions

Severity: L

Surface: Sources, Available tab

What the reader sees today: An "Adult content off" badge plus a "Turn it on"
button that opens a confirm dialog, so one decision is presented twice.

Why it is wrong or could be better: Minor duplication. The dialog is the real
gate and the badge is status, but today both read as actions.

Concrete proposal: Keep the badge as status and make the button the only action:
the badge becomes a plain text label, and the button keeps its confirm dialog.
No new component.

Files it would touch: `resources/views/pages/settings/sources.blade.php`.

Estimated cost: 10 minutes.

Preview: Captured as `p9u2-nsfw-gate-shut-available.jpg`.

## Health

### 9. The health page's site chevrons carry no summary

Severity: M

Surface: Health, site drill-down rows

What the reader sees today: 25 site chevrons are named, but their expanded state
has no summary line, so a reader who opens one learns nothing until they read
every row.

Why it is wrong or could be better: The expanded state is where the dead sources
live, and there is no "N sources, M dead" line to scan first. A reader scanning
for the one dead source has to open every site.

Concrete proposal: Add "N sources, M dead" beside each site chevron in its
expanded state, computed from the site's own source rows. One line in the loop
that renders the chevron.

Files it would touch: `resources/views/pages/settings/health.blade.php`.

Estimated cost: 15 minutes.

Preview: Captured as `p9-health-desktop.jpg` and `p9-health-named-desktop.jpg`.

## Backups

### 10. The backups run panel is an inline block that cannot be read

Severity: H

Surface: Settings > Backups, "The last time"

What the reader sees today: A cramped inline block that repeats the same run
summary in two places, prints raw snake_case stage names (`checking_target`,
`copying manga half`), shows no live progress and no dismissal, and makes the
whole page jump every 3 seconds while a run is in flight.

Why it is wrong or could be better: A long-lived job is shown as if it were a
paragraph. The reader cannot tell what is happening, how far it has got, or what
to do next, and the page reflows under them while they read it. This is the
highest-value item in this pass.

Concrete proposal: Replace the inline block with a modal carrying a checkpoint
timeline (stages: Checking target, Stopping, Copying manga half, Copying reader
half, Writing manifest, Restarting, each pending, running, done or failed), a
live progress bar with elapsed seconds, a final verdict sentence, and one clear
next action. The inline block becomes a single line: "Last run: finished 22
hours ago." with a "Show details" link. The page stops jumping on every poll.

Files it would touch: `resources/views/pages/settings/backups.blade.php` (the
"The last time" section).

Estimated cost: 90 minutes.

Preview: The current page is captured as `uxpass-backups-desktop-...jpg` and
`p7-step5-backups-populated-desktop-...jpg`. The mockup below shows the modal
shape with the checkpoint timeline and the progress bar, drawn with the app's own
tokens.

### 11. The running backup never says how far it has got

Severity: M

Surface: Settings > Backups, "The last time" while a run is in flight

What the reader sees today: "Started" / "being staged now" with no bar, no
elapsed time, and no stage name a reader can parse, while the job polls every 3
seconds.

Why it is wrong or could be better: The run is alive and progressing, but the
surface only restates the latest note, so the reader cannot tell whether it is
moving or stuck.

Concrete proposal: The modal's checkpoint timeline and live progress bar, with
elapsed seconds and "N of 6" stages, driven by the run's own progress note and
timestamps. Ships with item 10.

Files it would touch: `resources/views/pages/settings/backups.blade.php`.

Estimated cost: included in item 10.

Preview: Ships with item 10's modal.

### 12. The backups page has no "last restored" line per pair

Severity: M

Surface: Settings > Backups, pairs table

What the reader sees today: Two rows with identical "Restore" buttons and no
per-row status of whether a restore has ever run, so a reader cannot tell which
pair is current.

Why it is wrong or could be better: The restore surface never says what the pair
holds in a scannable way beyond the table, and a reader who restored a week ago
has no record of it.

Concrete proposal: Add a "last restored" line to each pair row once a restore run
exists for it, reading the restore run's own row and timestamp.

Files it would touch: `resources/views/pages/settings/backups.blade.php` (pairs
table).

Estimated cost: 30 minutes.

Preview: Captured as `uxpass-backups-desktop-...jpg`.

### 13. The restore confirmation does not name what it replaces

Severity: M

Surface: Settings > Backups, restore confirm dialog

What the reader sees today: The confirm dialog exists, but its copy is thinner
than the backup's: it does not name the pair's token or the counts it will
replace, and it does not show the checkpoint timeline.

Why it is wrong or could be better: A restore is destructive and names nothing of
what it takes, so a reader cannot confirm they are about to overwrite the right
thing.

Concrete proposal: Name the pair's token and the counts it will replace in the
confirm dialog, and show the same checkpoint timeline the backup modal uses.

Files it would touch: `resources/views/pages/settings/backups.blade.php`
(restore confirm).

Estimated cost: 30 minutes.

Preview: Captured as `uxpass-backups-desktop-...jpg`.

## Migrate and migrate sources

### 14. The migrate-sources page does not say where you came from

Severity: M

Surface: Settings > Migrate sources

What the reader sees today: The page has "Back to sources" but does not show
which source the reader came from along the "Move a whole source" path.

Why it is wrong or could be better: A reader who opened the page from a
particular source has to go back to remember which one they were moving, because
the page carries no origin.

Concrete proposal: Show which source the reader came from on the migrate-sources
page, beside the existing "Back to sources" link, read from the query string or
the referrer and named on the page.

Files it would touch:
`resources/views/pages/settings/migrate-sources.blade.php`.

Estimated cost: 20 minutes.

Preview: Captured as `uxpass-migrate-sources-desktop-...jpg`.

## Import

### 15. The import page's run rows are an inline block

Severity: M

Surface: Settings > Import

What the reader sees today: The import page shows run rows as an inline block
with the run's progress note and counts, re-rendering the whole row on every
poll. A long import makes the page jump.

Why it is wrong or could be better: Same family as the backups run panel: a
long-lived job shown as a paragraph. The fix is the same shape: one line per
run with a "Show details" link opening a modal with the checkpoint timeline.

Concrete proposal: Replace the inline run block with one line per run (file name,
badge, "Show details") and a single modal carrying the checkpoint timeline, the
progress bar, the verdict sentence and one next action. Reuse the same modal
component as the backups page rather than a second one.

Files it would touch: `resources/views/pages/settings/import.blade.php`,
`app/Models/ImportRun.php`.

Estimated cost: 45 minutes, plus reuse of the backups modal.

Preview: Captured as `uxpass-import-desktop-...jpg`.

## Licences and connections

### 16. The licences page rows are uneven in height

Severity: M

Surface: Settings > Licences

What the reader sees today: Some licence rows are one line and some are two,
because the button cluster and the name cell fight for the same 512 px slot, so
the row height depends on the length of the package name.

Why it is wrong or could be better: The settings slot is a constant width, and
the old one-line fit was a race a row could win only on a short lock-file word.
The page reads as uneven.

Concrete proposal: Every row becomes one three-line shape: name with its licence,
then the source, then the duty buttons. One shape for every row, no race.

Files it would touch: `resources/views/pages/settings/licences.blade.php`.

Estimated cost: 30 minutes.

Preview: Captured as `uxpass-licences-desktop-...jpg`.

### 17. The connections page has no test of its own connection

Severity: L

Surface: Settings > Connections

What the reader sees today: The page lists the connection settings and saves
them, but does not say whether the connection it just saved actually works.

Why it is wrong or could be better: A reader who mistyped a key saves it and
sees "saved" with no signal that the key is wrong. A "Test connection" button
that runs the same check the app runs on start would close the loop.

Concrete proposal: Add a "Test connection" button beside the save button that
runs the connection check and reports the result as a toast, reusing the
existing check rather than a new one.

Files it would touch: `resources/views/pages/settings/connections.blade.php`,
`app/Livewire/Connections.php`.

Estimated cost: 25 minutes.

Preview: Captured as `uxpass-connections-desktop-...jpg`.

## Insights

### 18. The insights page has no empty state that names the reader

Severity: L

Surface: Insights

What the reader sees today: An empty insights page shows the cards with zero
values and no sentence telling the reader what would fill them. A reader who has
never opened a chapter sees "0 minutes" and nothing else.

Why it is wrong or could be better: The page is about the reader's own reading,
and an empty page should say so and point at the library rather than at zero.

Concrete proposal: When there are no reading sessions, show one sentence ("No
reading recorded yet. Open a chapter and it will show up here.") with a link
to the library, and hide the zero cards until there is something to show.

Files it would touch: `resources/views/pages/insights.blade.php`.

Estimated cost: 20 minutes.

Preview: Captured as `uxpass-insights-desktop-...jpg`.

## Settings and the in-shell FAQ, privacy and terms

### 19. The in-shell FAQ has no filter and will not scale

Severity: L

Surface: Settings > FAQ

What the reader sees today: 15 questions in one long column, privacy open on
arrival, no search.

Why it is wrong or could be better: Fine for the current count, but it will not
scale past about 25 questions. The fix is deferred until the count crosses 20.

Concrete proposal: Add a filter input above the list when the count crosses 20,
filtering on the question text. Defer until then.

Files it would touch: `resources/views/pages/settings/faq.blade.php`.

Estimated cost: 20 minutes when triggered.

Preview: Captured as `uxpass-faq-desktop-...jpg`.

## The empty and error states

### 20. The series page's empty reason is one sentence for three causes

Severity: M

Surface: Series, chapter list empty

What the reader sees today: When a series has no chapters, the page says "The
source returned no chapters" with the body "This is an upstream condition: the
source answered, and its chapter listing was empty." The same sentence covers a
series that moved, a source that changed its layout, and a source that is
broken.

Why it is wrong or could be better: The app cannot tell which of the three it
is, and it says so honestly, but the sentence reads as a single cause. A reader
who sees it cannot tell whether to wait, to search manually, or to give up.

Concrete proposal: Keep the honest sentence and add a second line that names the
three possibilities in plain words and points at the migrate page ("If the
series moved, you can move it to another source from here."). No new data.

Files it would touch: `resources/views/pages/series.blade.php`.

Estimated cost: 15 minutes.

Preview: No capture of an empty series; the proposal carries the mockup only.

### 21. The reader has no way back to the chapter list from the page

Severity: L

Surface: Reader

What the reader sees today: The reader page shows the chapter's pages and the
settings panel, but the only way back to the series is the browser's back button
or the series link in the breadcrumb.

Why it is wrong or could be better: A reader who opened a chapter from search
rather than from the series page has no in-app way back to the series. The back
affordance should be on the page.

Concrete proposal: Add a "Back to the series" link in the reader's header,
pointing at the series route for the manga the chapter belongs to, in the same
shape as the migrate wizard's back link.

Files it would touch: `resources/views/pages/reader.blade.php` or
`app/Http/Controllers/ReaderController.php`.

Estimated cost: 15 minutes.

Preview: No capture of the reader in this pass; the proposal carries the mockup
only.

## What this pass does not touch

- The clean-machine sandbox gate and F26's verification. The gate is parked on
  the owner's word (eleven deaths to 0x80370106) and is not run in this pass.
  F26's fix is committed but never sandbox verified.
- The push. Nothing is pushed; the push decision is the owner's.
- The sandbox park itself, and the resumption bar (one hands-off run ending
  "PASS, every check held" with 67 checks).
- F4's history rewrite, which is destructive and needs the owner's approval.
- F13's decision about gating on `tests/`.
- F10's signing, which is deferred and needs the owner's decision on where the
  key lives.
- F27's email verification ask, F29's sources language dropdown reflow, and
  F23's product gap, which are the owner's named run and are handled in Phase 9
  unit 2.
- The NSFW gate's already-built shape (Phase 9 unit 2), which is held as is.
- The clean-machine gate's premise checks, which are not re-run here.
- No application code is changed by this pass. The proposal is the only
  artifact.

## How each improvement is verified

The six gates from AGENTS.md section 2 run on the tree that carries each
improvement, not on the branch it was based on:

1. `php -d memory_limit=2048M artisan test --without-tty`
2. `php -d memory_limit=2G vendor/bin/phpstan analyse --no-progress --memory-limit=2G`
3. `php -d memory_limit=2048M vendor/bin/pint --test app/ tests/ packaging/`
4. `python ..\.tools\suwayomi\check-dashes.py`
5. `python tools\check-machine-detail.py`
6. `python tools\test-check-machine-detail.py`

Each improvement that changes a rendered page is also verified in a real browser
against the standing stack, with a throwaway account minted for the walk and
deleted afterwards, read back gone. A change whose test could pass without the
fix gets a mutation check: the fix is reverted, the test must die, the fix is
restored, the test must live. A change that touches time, ports or processes is
run more than once.

The improvements that are L-severity polish carry a lighter bar: the unit tests
for the touched page, the dash scan and the machine-detail scan, and a
screenshot read back from disk. No improvement is claimed verified until the
artifact is read back from disk, per AGENTS.md section 0.6.

## Preview index

Each block above carries at least one preview. The current-state captures are in
the workspace's `.openchamber/screenshots/` with names that carry their own
timestamp and what they show. The proposed-state mockups are rendered with
`openchamber_render` using the app's own tokens and are saved beside the
captures.

- Block 1 (login rhythm): current `uxpass-login-desktop-...jpg`, proposed mockup saved as `uxpass-login-proposed-2026-10-22T...jpg`.
- Block 2 (register 404): current `02-register-404-...jpg`, proposed mockup saved beside it.
- Block 3 (notice dismiss): current `p9-library-toast-desktop.jpg`.
- Block 4 (empty library): current `04-library-empty-...jpg`, `p9-library-desktop.jpg`.
- Block 5 (search input): current `p9-search-desktop.jpg`.
- Block 6 (browse picker): current `p9-browse-desktop.jpg`.
- Block 7 (language row): current `p9-sources-languages-desktop.jpg`.
- Block 8 (adult content badge): current `p9u2-nsfw-gate-shut-available.jpg`.
- Block 9 (health chevrons): current `p9-health-desktop.jpg`, `p9-health-named-desktop.jpg`.
- Block 10 (backups run panel): current `uxpass-backups-desktop-...jpg`, `p7-step5-backups-populated-desktop-...jpg`.
- Block 11 (running backup): ships with block 10.
- Block 12 (last restored): current `uxpass-backups-desktop-...jpg`.
- Block 13 (restore confirm): current `uxpass-backups-desktop-...jpg`.
- Block 14 (migrate-sources origin): current `uxpass-migrate-sources-desktop-...jpg`.
- Block 15 (import run rows): current `uxpass-import-desktop-...jpg`.
- Block 16 (licences rows): current `uxpass-licences-desktop-...jpg`.
- Block 17 (connections test): current `uxpass-connections-desktop-...jpg`.
- Block 18 (insights empty): current `uxpass-insights-desktop-...jpg`.
- Block 19 (FAQ filter): current `uxpass-faq-desktop-...jpg`.
- Block 20 (series empty reason): mockup only.
- Block 21 (reader back link): mockup only.

## Ranked order by reader value

1. Block 10, the backups run panel (H)
2. Block 1, the login rhythm (M)
3. Block 4, the empty library notice (L)
4. Block 3, the notice dismiss name (M)
5. Block 5, the search input label (M)
6. Block 9, the health chevrons summary (M)
7. Block 12, last restored per pair (M)
8. Block 13, restore confirm naming (M)
9. Block 14, migrate-sources origin (M)
10. Block 15, import run rows (M)
11. Block 16, licences row height (M)
12. Block 17, connections test (L)
13. Block 18, insights empty state (L)
14. Block 19, FAQ filter (L)
15. Block 20, series empty reason (M)
16. Block 21, reader back link (L)
17. Block 6, browse picker grouping (L)
18. Block 7, language row compaction (L)
19. Block 8, adult content badge (L)
20. Block 2, register 404 honesty (L)
