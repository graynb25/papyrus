# Phase 9 ranked walk findings

Written from the four-beat live walk of the scratch app on 2026-10-22, signed in as
the throwaway account minted for the walk (id 12, `p9i2-walk@example.test`, deleted
afterwards and read back gone). The tree is at `573bf29`. No application code was
changed; this document is the only artifact.

The list merges the eight findings from the Phase 9 walk plan's Findings table with
the eight further improvements ranked by reader value, de-duplicated where they
name the same surface, and re-ranked by reader value and severity. Three pairs
merged: the language row (finding 5 with further 3), the FAQ filter (finding 6
with further 2), and the pairs table "last restored" line (finding 3 with further 7).
That leaves 13 blocks.

Severity: S data loss, credentials or reachability. H the feature does not work for
the reader. M correct but costly or unverifiable without effort. L housekeeping,
wording, consistency. none means the surface is held as designed and needs no change.

## 1. The backups run panel is an inline block that cannot be read

Severity: H

Surface: Settings > Backups, "The last time"

What the reader sees: A cramped inline block that repeats the same run summary in
two places, prints raw snake_case stage names (`checking_target`, `copying manga half`),
shows no live progress and no dismissal, and makes the whole page jump every 3
seconds while a run is in flight.

Why it is wrong: A long-lived job is shown as if it were a paragraph. The reader
cannot tell what is happening, how far it has got, or what to do next, and the
page reflows under them while they read it.

Concrete fix: Replace the inline block with a modal carrying a checkpoint timeline
(stages: Checking target, Stopping, Copying manga half, Copying reader half, Writing
manifest, Restarting, each pending, running, done or failed), a live progress bar
with elapsed seconds, a final verdict sentence, and one clear next action. The
inline block becomes a single line: "Last run: finished 22 hours ago." with a "Show
details" link that opens the modal. The page stops jumping on every poll.

Files: `resources/views/pages/settings/⚡backups.blade.php` (the "The last time"
section)

Preview: Replace the inline run block with a modal so a backup run reads as a job
rather than a paragraph.

## 2. The running backup never says how far it has got

Severity: M

Surface: Settings > Backups, "The last time" while a run is in flight

What the reader sees: "Started" / "being staged now" with no bar, no elapsed time,
and no stage name a reader can parse, while the job polls every 3 seconds.

Why it is wrong: The run is alive and progressing, but the surface only restates
the latest note, so the reader cannot tell whether it is moving or stuck.

Concrete fix: The modal's checkpoint timeline and live progress bar, with elapsed
seconds and "N of 6" stages, driven by the run's own progress note and timestamps.

Files: `resources/views/pages/settings/⚡backups.blade.php`

Preview: Add a live progress bar and elapsed time to the running backup modal.

## 3. The backups page has no "last restored" line per pair

Severity: M

Surface: Settings > Backups, pairs table

What the reader sees: Two rows with identical "Restore" buttons and no per-row
status of whether a restore has ever run, so a reader cannot tell which pair is
current.

Why it is wrong: The restore surface never says what the pair holds in a scannable
way beyond the table, and a reader who restored a week ago has no record of it.

Concrete fix: Add a "last restored" line to each pair row once a restore run exists
for it, reading the restore run's own row and timestamp.

Files: `resources/views/pages/settings/⚡backups.blade.php` (pairs table)

Preview: Show "last restored" per pair row once a restore run exists.

## 4. The restore confirmation does not name what it replaces

Severity: M

Surface: Settings > Backups, restore confirm dialog

What the reader sees: The confirm dialog exists, but its copy is thinner than the
backup's: it does not name the pair's token or the counts it will replace, and it
does not show the checkpoint timeline.

Why it is wrong: A restore is destructive and names nothing of what it takes, so a
reader cannot confirm they are about to overwrite the right thing.

Concrete fix: Name the pair's token and the counts it will replace in the confirm
dialog, and show the same checkpoint timeline the backup modal uses.

Files: `resources/views/pages/settings/⚡backups.blade.php` (restore confirm)

Preview: Make the restore confirmation name the pair and show the checkpoint timeline.

## 5. The health page's site chevrons carry no summary

Severity: M

Surface: Settings > Health, site drill-down rows

What the reader sees: 25 site chevrons are named, but their expanded state has no
summary line, so a reader who opens one learns nothing until they read every row.

Why it is wrong: The expanded state is where the dead sources live, and there is no
"N sources, M dead" line to scan first.

Concrete fix: Add "N sources, M dead" beside each site chevron in its expanded
state.

Files: `resources/views/pages/settings/⚡health.blade.php`

Preview: Add a source and dead count summary line to each health site chevron.

## 6. The library notice's dismiss button is named late

Severity: M

Surface: Library, empty-library notice

What the reader sees: The dismiss button on the empty-library toast is named by the
shell script after the copy is made, so on first paint it has no accessible name.

Why it is wrong: A screen reader meeting the notice before the script runs hears an
unnamed button, and the naming seam lives in the wrong place for a control that
appears with the notice.

Concrete fix: Move the "Close notification" name into the component so it is there
on first paint, instead of being applied after the copy is made.

Files: the empty-library toast component plus `resources/views/components/behaviors.blade.php`

Preview: Name the library notice's dismiss button in the component on first paint.

## 7. The adult content gate is only reachable from the sources page

Severity: M

Surface: Settings navigation and `App\Support\AdultContent`

What the reader sees: A reader who turns adult content off later has to find the
sources page to turn it back on, because the gate lives only there.

Why it is wrong: The setting is a per-reader preference, but its only door is on
the page that lists extensions, so a reader who is not looking at extensions has no
path to it.

Concrete fix: Add the adult content gate to the settings navigation, reusing
`App\Support\AdultContent`, so it is reachable without the sources page.

Files: settings navigation plus `App\Support\AdultContent`

Preview: Make the adult content gate reachable from Settings as well as Sources.

## 8. The migrate-sources page does not say where you came from

Severity: M

Surface: Settings > Migrate sources

What the reader sees: The page has "Back to sources" but does not show which source
the reader came from along the "Move a whole source" path.

Why it is wrong: A reader who opened the page from a particular source has to go
back to remember which one they were moving, because the page carries no origin.

Concrete fix: Show which source the reader came from on the migrate-sources page,
beside the existing "Back to sources" link.

Files: `resources/views/pages/settings/⚡migrate-sources.blade.php`

Preview: Show the originating source on the migrate-sources page.

## 9. The sources Available tab carries two controls for one decision

Severity: L

Surface: Settings > Sources, Available tab

What the reader sees: An "Adult content off" badge plus a "Turn it on" button that
opens a confirm dialog, so one decision is presented twice.

Why it is wrong: Minor duplication; the dialog is the real gate and the badge is
status, but today both read as actions.

Concrete fix: Keep the badge as status and make the button the only action. Already
close.

Files: `resources/views/pages/settings/⚡sources.blade.php` (Available tab)

Preview: Keep the adult content badge as status and the button as the only action.

## 10. The sources language row is heavy at desktop width

Severity: L

Surface: Settings > Sources, language row

What the reader sees: 23 pills wrapping over three lines at desktop width, with
counts on every pill.

Why it is wrong: Visually heavy but functional; the pills are the owner's chosen
shape, so this is optional polish rather than a defect.

Concrete fix: Collapse to a compact chip row with a "more" affordance on narrow
widths. Not blocking.

Files: `resources/views/pages/settings/⚡sources.blade.php` (language row)

Preview: Collapse the 23 language pills into a compact chip row on narrow widths.

## 11. The FAQ has no filter and will not scale

Severity: L

Surface: Settings > FAQ

What the reader sees: 15 questions in one long column, privacy open on arrival, no
search.

Why it is wrong: Fine for the current count, but it will not scale past about 25
questions.

Concrete fix: Add a filter input above the list when the count crosses 20. Defer.

Files: `resources/views/pages/settings/⚡faq.blade.php`

Preview: Add a filter input to the FAQ once it passes 20 questions.

## 12. The library's cover anchors are aria-hidden by design

Severity: none (hold)

Surface: Library grid

What the reader sees: Cover anchors are `aria-hidden` with the accessible name on
the title below, so a screen reader hears the title rather than the cover.

Why it is wrong: Not wrong. This is correct by design (trap 317): the cover is a
picture and the link into the series is the title.

Concrete fix: Hold. Do not change.

Files: library grid views

Preview: Hold the cover anchors as aria-hidden; the title carries the name.

## 13. The Installed tab hides adult-rated extensions and counts them

Severity: none (hold)

Surface: Settings > Sources, Installed tab

What the reader sees: Adult-rated installed extensions are hidden and counted ("N
hidden") while the gate is off.

Why it is wrong: Not wrong. Verified and honest; the count is the honest reading
of what the server holds back.

Concrete fix: Hold.

Files: `resources/views/pages/settings/⚡sources.blade.php` (Installed tab)

Preview: Hold the Installed tab's hidden adult-rated count as the honest reading.
