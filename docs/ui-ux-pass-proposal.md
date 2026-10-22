# UI/UX pass proposal

A proposal pass over the whole app, written for the owner to take one item at a
time. Nothing here is implemented; each block carries its own preview so the
owner can see the change before deciding.

Every block is judged on one question: does this surface feel like a person
designed it for a person, or like a machine executing a spec? The words matter,
but so do the layout, the spacing, the interactions and the structure. A block
that reads as machine-made is revised, not just reworded. Severity, surface, what the reader
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

- Onboarding
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
- Settings, FAQ, privacy and terms
- The empty and error states
- Copy and words (humanized copy pass)

## Onboarding

### 26. A fresh install has no first-run path at all

Severity: H

Surface: First run, from the shortcut to the first readable page

What the reader sees today: A reader who has just installed Papyrus and double-clicked
the shortcut lands on a login page that says "Log in to your account" with the
promise line "Your whole library, every source, one quiet room." and a footer of
four links. There is no sign-up link unless registration is open on that install,
and there is no sentence that says what Papyrus is or what to do first. After
signing in, the reader lands on an empty library whose doors are "Add series",
"Go to search" and "Bring a library from another app", none of which is the thing
the reader has to do first, which is to add a source. The sources page then opens
with a notice that reads "Sources are Keiyoushi extensions running inside
Suwayomi. Nothing is bundled: point Papyrus at a store, read its signing key, then
trust it if you recognise the publisher." followed by a paragraph about trust being
a gate in the interface rather than an enforcement the Suwayomi API offers. The
store URL is pre-filled in the "Add a store" input, the signing key sits below it,
and the "Trust this key" button asks the reader to compare a 64-character hex
string against something the publisher published. The Available tab reads "Nothing
is installable until you trust a store" with "1 store is registered. Compare its
signing key above against the one its publisher has published, then trust it." A
reader who has never heard of Keiyoushi, Suwayomi or an extension repository has
no way to know any of this, and nothing on the page says what a source is in one
sentence or what happens after it is added.

Why it is wrong or could be better: The app's whole point is launching Papyrus to
read your manga, and a fresh install that serves HTTP perfectly while showing the
reader nothing has not been proved to work (F26). The onboarding requirements in
the findings register (F26, F23, F27) ask for three things: name Keiyoushi and let
the reader add it without hunting for the store URL by hand; load the catalogue
automatically once a store is added, because manual Refresh catalogue is for
recovering from failure only; and make the first visit usable without reading the
docs. Today none of the three holds. The empty library's doors point at search and
import, both of which presuppose a source the reader does not have yet. The sources
page's notice is written for a reader who already understands the model, and the
signing-key comparison is the first thing the reader meets. There is no "what is a
source" sentence and no "what happens next" sentence.

Concrete proposal: Add a first-run path that does four things in order. First, name
the thing the reader needs in one sentence on the empty library: "Papyrus reads
from sources. A source is an extension repository that holds manga; Papyrus ships
with none, so the first step is to add one." Second, name Keiyoushi as the thing
to search for, without embedding its URL or any store URL, because the app must not
ship a link to a third-party extension repository: "The most common source is
Keiyoushi. Search for 'Keiyoushi extensions' in your browser, open the repository,
and paste its index URL into the field below." Give the "Add a store" input a
helper line that says exactly that, and keep the input empty so the reader pastes
what they found. Third, once a store is added and trusted, load its catalogue
automatically and show progress ("Loading the catalogue... N extensions found")
instead of asking the reader to press Refresh catalogue, which stays on the page
only as the recovery path when a load fails. Fourth, on the Available tab, once the
catalogue has loaded, show the first page of extensions with a one-line sentence
above it: "These are the extensions this store offers. Install one to start
reading." The path is three screens (empty library, sources, available) and uses
the components the app already carries: the empty-state doors, the store card, the
trust button, the Available tab. No new component, no new route, no tour overlay.
The empty library's "Add series" door stays; "Add a source" is added beside it as
the first door, because on a fresh install it is the first thing the reader can
actually do.

Files it would touch: `resources/views/pages/library.blade.php` (the empty-state
doors), `resources/views/pages/settings/sources.blade.php` (the one-sentence source
definition, the Keiyoushi search hint on the "Add a store" input, the automatic
catalogue load trigger, the "what happens next" line on the Available tab),
`app/Livewire/Sources.php` or wherever the store-add and catalogue-load logic
lives (trigger the load on trust, not on a button press).

Estimated cost: 90 minutes.

Preview: The current empty library is captured as
`onboarding-empty-library-current-2026-10-22T21-41-35-390.jpg` and the current
sources page as `onboarding-sources-current-2026-10-22T21-41-28-933.jpg`. The
mockup of the proposed first-run path is rendered below with the app's own tokens.

### 27. The catalogue does not load when a store is added

Severity: M

Surface: Sources, store card and catalogue load

What the reader sees today: After a store is added, the Available tab reads
"Nothing is installable until you trust a store" and the Refresh catalogue button
sits in the store card's action row. The reader has to press Refresh catalogue by
hand to see what the store offers. If they do not, the page stays empty and they
conclude the store did not work.

Why it is wrong or could be better: Manual Refresh catalogue is for recovering from
failure, not for the first load. The catalogue should load on its own once the
store is trusted, because a reader who has just trusted a store has already said
yes and should not have to ask again. The button stays for the case where the load
failed or the store's index changed.

Concrete proposal: Trigger the catalogue load automatically when a store transitions
from untrusted to trusted, and show progress on the Available tab ("Loading the
catalogue... N extensions found") while it runs. Keep Refresh catalogue as a
secondary action on the store card for the recovery case. No new component; the
existing poll and progress seam carries it.

Files it would touch: `resources/views/pages/settings/sources.blade.php` (the
progress line on the Available tab), `app/Livewire/Sources.php` (the trust-to-load
trigger).

Estimated cost: 45 minutes.

Preview: Current sources page captured as
`onboarding-sources-current-2026-10-22T21-41-28-933.jpg`. The proposed progress
line appears in the first-run mockup below.

### 28. The signing key and the trust step are unexplained

Severity: M

Surface: Sources, store card

What the reader sees today: The store card shows "SIGNING KEY" followed by a
64-character hex string, a "Trust this key" button and a "Remove" button, and the
notice above says "Trust is a gate in this interface, not an enforcement the
Suwayomi API offers. It is here so that you read the key before installing, and so
the cautious path is the default one." A reader who has never compared a signing key
has no way to know what they are looking at or what "trust" changes.

Why it is wrong or could be better: The trust step is the app's safety gate and it
reads like a developer's note. The reader needs one sentence that says what the key
is and what trusting it does, in the same voice as the rest of the notice.

Concrete proposal: Replace the developer-facing notice with one sentence in the app's
voice: "Every store signs its extensions with a key. Compare the key above against
the one the publisher has published; if they match, trust it, and the store's
extensions become installable." Keep the key, the Trust button and the Remove
button where they are. Drop the second paragraph about enforcement, which is a
developer's justification rather than a reader's instruction.

Files it would touch: `resources/views/pages/settings/sources.blade.php` (the store
card's notice copy).

Estimated cost: 15 minutes.

Preview: Current sources page captured as
`onboarding-sources-current-2026-10-22T21-41-28-933.jpg`.

### 29. The sources page ships the Keiyoushi store URL pre-filled in the input

Severity: H

Surface: Settings > Sources, "Add a store"

What the reader sees today: The "Add a store" input already contains the
Keiyoushi index URL when the page loads. A reader who has never heard of
Keiyoushi sees a long GitHub URL sitting in a field, with no explanation of
where it came from or what it is.

Why it is wrong or could be better: The app must not ship a link to a
third-party extension store. Doing so is a DMCA concern: the app provides
the means to reach a store, not the store itself. The URL in the field reads
as if the app endorses it, and a reader who copies it without reading the
signing key has skipped the one step that makes the cautious path the
default. The page already carries the conduct copy ("Trust is a gate in this
interface, not an enforcement the Suwayomi API offers"); the pre-filled URL
undercuts it.

Concrete proposal: Empty the input on load. Keep the helper line that tells
the reader what to look for ("The index file itself, not the repository
root. Keiyoushi publishes protobuf at index.pb.") and add one sentence above
it: "Papyrus does not ship with any store. Search for an extension
repository you trust, paste its index URL here, and compare its signing key
before you trust it." The field becomes a place the reader fills in, which
is what the trust step assumes they did.

Files it would touch: `resources/views/pages/settings/sources.blade.php` (the
"Add a store" input and its helper), and the test that asserts the pre-filled
value.

Estimated cost: 15 minutes, plus a test change.

Preview: The current state is captured as `onboarding-sources-current-...jpg`.
The mockup shows the same card with an empty input and the new sentence above
it.

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
`uxpass-login-desktop-2026-10-22T...jpg` in the walk set; the mockup shows the
proposed rhythm with the promise line first and the footer links at equal
weight.

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
`p7-step5-backups-populated-desktop-...jpg`. The mockup shows the modal shape
with the checkpoint timeline and the progress bar, drawn with the app's own
tokens.

### 11. The backups page has no "last restored" line per pair

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

### 12. The restore confirmation does not name what it replaces

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

### 13. The migrate-sources page does not say where you came from

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

### 14. The import page's run rows are an inline block

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

### 15. The licences page rows are uneven in height

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

### 16. The connections page has no test of its own connection

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

### 17. The insights page is numbers only, with no pictures

Severity: H

Surface: Insights

What the reader sees today: The insights page shows cards with numbers: minutes
read, pages turned, series read. There is no graph, no series cover, and no
picture of reading time over time. A reader who has read for a month sees
"42 minutes" and nothing else.

Why it is wrong or could be better: Insights should show what the reader's
reading looks like, not just count it. A number alone does not tell a reader
whether they read more this week than last, which series they spent time on, or
when they read. The page is about the reader's own reading, and a page about
reading with no pictures of reading is missing the point.

Concrete proposal: Add three pictures to the page, all drawn from data the app
already holds. First, a reading-time chart: a simple bar or line chart of
minutes read per day (or per week) for the chosen window, with the total on
the same line. Second, a series list with covers: the series the reader spent
time on, each with its cover, its minutes, and its pages, sorted by time spent.
Third, keep the empty state honest: when there is no reading yet, show one
sentence ("No reading recorded yet. Open a chapter and it will show up here.")
with a link to the library, and hide the zero cards until there is something to
show.

Files it would touch: `resources/views/pages/insights.blade.php`,
`app/Livewire/Insights.php` (or wherever the page's data comes from).

Estimated cost: 90 minutes.

Preview: Captured as `uxpass-insights-desktop-...jpg`. The mockup shows the
chart and the covered series list beside it.

### 18. The series page's empty reason is one sentence for three causes

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

### 19. The reader has no way back to the chapter list from the page

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

## Settings, FAQ, privacy and terms

### 20. The in-shell FAQ has no filter and will not scale

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

### 21. The FAQ does not open and close like an accordion

Severity: M

Surface: Settings > FAQ, and the public FAQ page

What the reader sees today: The FAQ is a list of questions. A reader expects to
click a question and have the answer open, and click again to close it. Today
the open and close is not clear, and the title is not the toggle.

Why it is wrong or could be better: A FAQ that does not behave like a FAQ is
confusing. The title should be the toggle, the answer should show and hide, and
the open and closed states should be easy to see.

Concrete proposal: Use a native disclosure (details and summary) for each
question, with the question as the summary and the answer inside. The privacy
question stays open on arrival. Show a clear open and closed state, for example
a small chevron that turns. Do not use a custom script when the browser already
does this. This matches the series page's folded description, which is the
house pattern.

Files it would touch: `resources/views/pages/settings/faq.blade.php`,
`resources/views/pages/faq.blade.php`, `app/Support/Faq.php`.

Estimated cost: 45 minutes.

Preview: Captured as `uxpass-faq-desktop-...jpg`. The mockup shows the
accordion with one question open and the chevron turned.

## Copy and words

### 24. The app's copy is written like a machine, not a person

Severity: M

Surface: All copy: FAQ, settings, backups, empty states, error pages

What the reader sees today: Sentences use long, heavy words that nobody says
out loud. They run long. They read like a manual. A reader who is already
unsure has to work to understand them.

Why it is wrong or could be better: The app is for a person reading manga on
their own machine. Its words should sound like a person explaining to a friend.
Short sentences. Plain words. No jargon. No filler.

Concrete proposal: Go through every sentence the app shows and rewrite it. Start
with the FAQ, then settings, then backups, then empty states, then error pages.
For each sentence, ask: would I say this to a friend? If not, say it the way
I would. Keep the meaning. Drop the padding. The research notes list the sources
this borrows from.

Files it would touch: `app/Support/Faq.php`, the settings pages, the backups
page, the empty states, the error pages.

Estimated cost: 3 hours.

Preview: The before and after is in the "Humanized copy" section below.

## Mobile-first image sizing

### 25. Image sizing is desktop first, with a mobile fallback

Severity: M

Surface: All images: covers, thumbnails, reader pages

What the reader sees today: Images are sized for a desktop screen and shrunk
for a phone. On a phone the browser downloads a desktop image and scales it
down. That is slow and wastes data.

Why it is wrong or could be better: Most readers use a phone. The image should
be sized for the phone first, and grown for a bigger screen only when there is
room. This is the mobile-first rule for images.

Concrete proposal: Use `srcset` and `sizes` on every image, with the smallest
size first and the larger sizes after it. Write the `sizes` attribute for a
phone first and add a media query for wider screens. Use `loading="lazy"` so
images below the fold wait. Use the `picture` element only when the art itself
changes between sizes. Measure the result on a phone and on a desktop.

Files it would touch: the cover component, the reader page, the browse and
search cards.

Estimated cost: 2 hours.

Preview: The research notes cite the sources this borrows from.

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
- Block 11 (last restored): current `uxpass-backups-desktop-...jpg`.
- Block 12 (restore confirm): current `uxpass-backups-desktop-...jpg`.
- Block 13 (migrate-sources origin): current `uxpass-migrate-sources-desktop-...jpg`.
- Block 14 (import run rows): current `uxpass-import-desktop-...jpg`.
- Block 15 (licences rows): current `uxpass-licences-desktop-...jpg`.
- Block 16 (connections test): current `uxpass-connections-desktop-...jpg`.
- Block 17 (insights pictures): current `uxpass-insights-desktop-...jpg`, proposed mockup.
- Block 18 (series empty reason): mockup only.
- Block 19 (reader back link): mockup only.
- Block 20 (FAQ filter): current `uxpass-faq-desktop-...jpg`.
- Block 21 (FAQ accordion): current `uxpass-faq-desktop-...jpg`.

- Block 24 (humanized copy): before and after in the Humanized copy section.
- Block 25 (mobile-first images): measured on a phone and a desktop.
- Block 26 (first-run path): current `onboarding-empty-library-current-2026-10-22T21-41-35-390.jpg`,
  `onboarding-sources-current-2026-10-22T21-41-28-933.jpg`, proposed mockup saved as
  `onboarding-first-run-proposed-2026-10-22T...jpg`.
- Block 27 (catalogue auto-load): current `onboarding-sources-current-2026-10-22T21-41-28-933.jpg`.
- Block 28 (signing key and trust): current `onboarding-sources-current-2026-10-22T21-41-28-933.jpg`.
- Block 29 (no shipped store URL): current `onboarding-sources-current-2026-10-22T21-41-28-933.jpg`, proposed mockup of the empty input.
## Ranked order by reader value

1. Block 10, the backups run panel (H)
2. Block 1, the login rhythm (M)
3. Block 4, the empty library notice (L)
4. Block 3, the notice dismiss name (M)
5. Block 5, the search input label (M)
6. Block 9, the health chevrons summary (M)
7. Block 11, last restored per pair (M)
8. Block 12, restore confirm naming (M)
9. Block 13, migrate-sources origin (M)
10. Block 14, import run rows (M)
11. Block 15, licences row height (M)
12. Block 16, connections test (L)
13. Block 17, insights pictures (H)
14. Block 18, series empty reason (M)
15. Block 19, reader back link (L)
16. Block 20, FAQ filter (L)
17. Block 21, FAQ accordion (M)
18. Block 24, humanized copy (M)
19. Block 25, mobile-first images (M)
22. Block 6, browse picker grouping (L)
23. Block 7, language row compaction (L)
24. Block 8, adult content badge (L)
25. Block 2, register 404 honesty (L)
26. Block 26, first-run path (H)
27. Block 27, catalogue auto-load (M)
28. Block 28, signing key and trust (M)
29. Block 29, no shipped store URL (H)

## Research notes

What this proposal borrows, and where it comes from. Each item names the source
and the thing taken from it.

- Mobile-first responsive images. The rule that the smallest size comes first
  and the larger sizes follow, written for a phone and grown for a wider screen,
  with `loading="lazy"` for images below the fold, comes from MDN's guide to
  responsive images and Smashing Magazine's guide to srcset and sizes. The
  `sizes` attribute written as `(min-width: ...) ... , 100vw` is the form both
  use. Source: MDN "Using responsive images in HTML"; Smashing Magazine
  "Responsive Images Done Right".
- Dashboard cards with covers and charts. The rule that a card must be readable
  without a tooltip, that five to seven priority metrics are the default and
  the rest sit behind an expandable section, and that the trend chart sits
  beside the value, comes from Pencil and Paper's dashboard UX patterns and
  PatternFly's dashboard card layouts. The "view as table" fallback for charts
  comes from Noir Book's accessibility notes for data-dense interfaces.
  Source: Pencil and Paper "Dashboard Design UX Patterns"; PatternFly
  "Dashboard"; Noir Book "Dashboard and Data-Dense Interface Design".
- Accordion disclosure. The rule that the title is the toggle, that the answer
  opens and closes on click, and that the state is visible, comes from the
  USWDS accordion guidance and the GOV.UK accordion write-up. The choice to
  use native `details` and `summary` rather than a script comes from Hassell
  Inclusion's comparison, which found native disclosure to be the safer default
  when the browser already does the work. Source: USWDS "Accordion"; GOV.UK
  "How we made the GOV.UK accordion component more accessible"; Hassell
  Inclusion "Accessible accordions part 2".
- Humanized microcopy. The rules used here (plain words, short sentences, no
  jargon, write as a person to a friend, read it aloud) come from NN/g's plain
  language guidance and from UX writing guides by SitePoint and The Copywriter
  Club. The "less is more" and "no jargon" rules come from UX Magazine's
  microcopy rules. Source: NN/g "UX Writing Study Guide"; SitePoint "7 Tips for
  Writing Clear, Human-friendly Microcopy"; UX Magazine "The Essential Guide to
  Microcopy".
- Typography and spacing tokens. The idea of a proportional type scale held in
  tokens, and of spacing that shares the same base, comes from Penpot's writing
  on design tokens and from Toptal's mobile typography guidelines. The rule that
  the scale is a tool and not a law comes from The Type Founders. Source:
  Penpot "Using design tokens for a proportional typographic scale"; Toptal
  "Mobile Typography Tips and Guidelines"; The Type Founders "Typography in your
  design system".

## Humanized copy

The app's copy was written like a manual. These are the sentences that change.
Each line shows the old wording and the new wording.

- FAQ, "How do I make a backup?": old "Copy the folder. There is no cloud
  copy, no sync service and nothing to export." new "Make a backup from the
  Backups page. It copies both halves while Papyrus keeps running. There is no
  cloud copy, so keep the file somewhere safe."
- FAQ, second line: old "Copy it while Papyrus is closed, and copy the whole
  folder rather than picking files. The library database keeps recent changes in
  a small sidecar file beside it, and copying only the main file is the quiet
  way to lose the last few chapters you read." new "If you would rather copy the
  folder by hand, copy the whole folder, not just the main file, and do it while
  Papyrus is closed. The library keeps recent changes in a small file beside the
  main one, and copying only the main file is how you lose the last few chapters
  you read."
- Backups page, run panel: old "checking_target, copying manga half" new
  "Checking target", "Copying manga half", and so on, in plain words.
- Backups page, empty state: old "No backups yet" new "No backups yet. Take one
  from the button below, or use `papyrus snapshot` from the terminal."
- Library, empty state: old "This library is empty" new "Nothing here yet. Add a
  series, or bring a library from another app."
- Search, empty state: old "No results" new "No series found. Try a shorter
  title, or pick a source on the left."
- Series, empty chapters: old "This is an upstream condition: the source
  answered, and its chapter listing was empty." new "The source answered, but its
  chapter listing was empty. The series may have moved, or the source may have
  changed its layout."
- Error page: old "A page fell out of its binding." new "Something went wrong
  on this page. Nothing you did caused it."
- Settings, save button: old "Saved" new "Saved."
- Toast, success: old "Operation completed successfully" new "Done."

The full pass touches `app/Support/Faq.php`, the settings pages, the backups
page, the empty states, and the error pages. Estimated cost: 3 hours.

