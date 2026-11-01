# Reader walk, 2026-10-25

> **Post-audit note (2026-11-01).** The 1.5.1 build was not published. The public
> history was rewritten on 2026-11-01; commit hashes in this walk predate the rewrite
> and resolve in the owner-held offline archive. The next release is 1.5.2, pending.
> See [../../RELEASE-STATUS.md](../../RELEASE-STATUS.md).

Status: **read-only walk complete, with documented coverage gaps.** Papyrus 1.4.0,
checkout served at `http://papyrus.test`. No fixes were made. This file is the only
artifact written.

## Method

- Signed in through the existing browser session. No credential was entered and no
  account action was taken.
- Signed-out checks used `curl.exe` without cookies. They cover HTTP status, headers,
  document title and HTML copy only. The shared browser stays signed in, so a guest
  rendering was not visually inspected.
- Desktop was measured at the browser panel's fill width, about 942 CSS px (927 to 942
  with the scrollbar). Mobile was a 390 px viewport, leaving a 365 to 390 px content
  box. Pages are only marked mobile in the table when their own body measured within
  that range. An early batch of resizes did not reflow background tabs, so pages that
  reported a wider body at a supposed mobile viewport are recorded as desktop only,
  not as a mobile result.
- Evidence is measured text, DOM bounds, document titles, HTTP status and database
  counts. No screenshots were taken, so no reader cover or title is captured in an
  image.
- Nothing was submitted. The reader, chapter downloads, backup export, restore,
  import, library check, source install and settings writes were left alone. The
  reader was not opened because `ReaderController::__invoke` records progress, opens a
  reading session and updates the series state on a plain GET.
- The walk began after a read-only inventory and ended with a second one. See
  **Read-only evidence** below.

## Route coverage

`D` is desktop at about 942 px, `M` is mobile at a 390 px viewport (365 to 390 px
content box). `Guest` and `Signed in` describe the session used.

| Route | Access | Width | Result | Notes |
| --- | --- | --- | --- | --- |
| `/` | Guest | D | 302 to `/login` | Correct gate |
| `/login` | Guest | - | 200 | Title `Log in - Papyrus`; rendered only through HTML |
| `/register` | Guest | - | 404 | Route is registered, registration is disabled for this install; no login link points here |
| `/forgot-password` | Guest | - | 200 | Not submitted |
| `/reset-password/{token}` | Guest | - | 200 | A placeholder token returned the form; not submitted |
| `/faq` | Guest | - | 200 | Public page |
| `/privacy` | Guest | - | 200 | Public page |
| `/terms` | Guest | - | 200 | Public page |
| `/showcase` | Guest | - | 200 JSON | Public cover wall payload; see security notes |
| `/ready` | Guest | - | 200 JSON | Key, database and migration checks |
| fallback, e.g. `/no-such-page` | Guest | - | 404 | Title `Page not found · Papyrus`; body copy is in the product voice |
| `/library` | Guest | - | 302 to `/login` | Correct gate |
| `/settings/profile` | Guest | - | 302 to `/login` | Correct gate |
| `/library` | Signed in | D, M | 200 | 867 series; mobile body 375 px |
| `/search` | Signed in | D, M | 200 | Idle search form; mobile body 375 px |
| `/insights` | Signed in | D, M | 200 | Empty state; mobile header fits 390 px |
| `/downloads` | Signed in | D, M | 200 | Empty state; mobile body 390 px |
| `/sources` | Signed in | D, M | 200 | 49 extensions, 238 sources; mobile body 375 px, 14,608 px tall |
| `/health` | Signed in | D, M | 200 | 51 sites, 223 language variants, 51 working; mobile body 365 px |
| `/browse/{source}` | Signed in | D, M | 200 | 20 cards; mobile body 375 px |
| `/series/{manga}` | Signed in | D, M | 200 | 641 visible chapters; mobile body 375 px |
| `/covers/{manga}` | Signed in | D | 404 | Empty body by design when there is no custom cover |
| `/migrate/sources` | Signed in | D, M | 200 | 238 unfiltered destination choices; mobile body 375 px |
| `/settings` | Signed in | D | 302 to `/settings/profile` | Expected redirect |
| `/settings/profile` | Signed in | D | 200 | Name, email, save and delete account |
| `/settings/connections` | Signed in | D | 200 | AniList connected, expiry shown; no action taken |
| `/settings/security` | Signed in | D | 302 to `/user/confirm-password` | Password gate reached |
| `/user/confirm-password` | Signed in | D | 200 | Stock copy; password not entered |
| `/settings/appearance` | Signed in | D | 200 | Theme page renders |
| `/settings/import` | Signed in | D, M | 200 | 14 import rows; mobile body 375 px |
| `/settings/backups` | Signed in | D, M | 200 | 2 saved backups; mobile body 375 px |
| `/settings/about` | Signed in | D | 200 | Papyrus 1.4.0, Suwayomi 2.4.2366, tested with 2.4.2366 |
| `/settings/licences` | Signed in | D | 200 | 450 packages, about 39,700 px tall |
| `/settings/faq` | Signed in | D | 200 | In-shell page |
| `/settings/privacy` | Signed in | D | 200 | In-shell page |
| `/settings/terms` | Signed in | D | 200 | In-shell page |

Not walked at all: `/dashboard`, `/up`, `/migrate/{manga}`, `/email/verify`,
`/anilist/callback`, `/downloads/chapter/{chapter}/cbz`,
`/settings/backups/export/{run}`, `/suwayomi/api/v1/{path}`, `/storage/{path}`,
`/showcase/cover/{manga}`, and the passkey and two-factor endpoints. Framework asset
routes under `/flux/*` and `/livewire-*` are not reader-facing and were not counted.

## Findings

### High

#### H1. Search claims to cover every installed source while searching 40

- Route: `/search`
- Expected: the coverage sentence matches what the search actually queries.
- Observed: the page says "Leave empty to search all installed sources." three lines
  above "Searching 40 sources". `/sources` lists 238 installed rows. The runnable
  search universe is 82 sources, and `SearchFanout::targets()` takes 40 of them. The
  route comment still describes an alphabetical cut, but the current code round-robins
  by extension package, so the claim that whole source families are cut off
  alphabetically is stale. The cap still leaves 42 runnable sources out, and the page
  does not name them unless pins are active.
- Evidence: rendered copy at desktop and mobile; 238 installed rows on `/sources`; the
  40 figure is rendered by the same page; 82 is the runnable count in the route comment
  and `SearchFanout`.
- Impact: a reader can search for a title, see no result and conclude the source does
  not have it, when that source was never queried. This is a false negative about the
  central task. The visible "Searching 40 sources" mitigates the contradiction but does
  not resolve it.
- Code: `resources/views/pages/⚡search.blade.php:63`, `:831`, `:1005`;
  `app/Search/SearchFanout.php:165-213`; `routes/web.php:188`.

#### H2. The series bulk download queues 583 already-read chapters

- Route: `/series/{manga}`
- Expected: the bulk action queues only unread chapters, and its count matches the
  unread total.
- Observed: the library card and the series header both say 633 of 641 read, leaving 8
  unread, while the button offers "Download unread 591". The header is consistent:
  `readCount()` and `visibleCount()` both use the filtered `$this->chapters`.
  `chapterStates()` loads only the current page's 50 chapters (`PER_PAGE`). The button
  and `downloadUnread()` read that page-scoped map, so the map can recognize at most 50
  of 641 chapters as read and treats the other 583 read chapters as unread.
  `641 - 50 = 591` matches the rendered count.
- Evidence: library row, header and button read from the same session; the component's
  `unreadDownloadableCount()` and `downloadUnread()` iterate `$this->chapters` but read
  `$this->chapterStates`, which `chapterStates()` slices to the current page. A focused
  test is needed to prove the exact queued IDs.
- Impact: pressing the button queues 591 chapters, 583 of them already read, not the 8
  the page implies. The count is accurate about what the action will queue; the label
  is not. This is wasted download and source load, not data loss.
- Code: `resources/views/pages/⚡series.blade.php:237`, `:775-778`, `:784-792`,
  `:1090-1096`, `:1228-1290`, `:1700`, `:1763`.

### Medium

#### M1. The library's check summary is ungrammatical and its bulk control looks broken

- Route: `/library`
- Expected: a plain sentence about when the library was last checked, and a control
  whose label explains its state.
- Observed: the header reads "All 867 series checked" next to "672 checked recently for
  at least 1 day before again", and a disabled "Check 0 series" button sits beside
  "Add series". The second sentence has no subject and no readable time.
- Evidence: rendered header at desktop and mobile.
- Impact: the first screen of the library is hard to parse, and a disabled "Check 0
  series" reads as a fault rather than as "nothing selected".
- Code: `resources/views/pages/⚡library.blade.php:1123`, `:1333`, `:1364`.

#### M2. Import history cannot distinguish its runs

- Route: `/settings/import`
- Expected: each import run is identifiable by when it ran, what it did and how much it
  wrote.
- Observed: 14 rows, all showing the same backup filename, a `Finished` or `Stopped`
  badge and `Show details`. No date, time, series count or chapter count is visible
  without opening each row.
- Evidence: rendered list at desktop and mobile; all 14 filenames are identical.
- Impact: after a repeated import a reader cannot tell which run is the recent one, or
  whether a run wrote anything, without opening rows one at a time.
- Code: `resources/views/pages/settings/⚡import.blade.php:961-979`.

#### M3. Source migration presents every language variant as an unfiltered choice

- Route: `/migrate/sources`
- Expected: a destination list that can be narrowed, with each name stated once.
- Observed: 238 destination checkboxes are rendered, covering every installed language
  variant. There is no filter or search. Each label repeats its code, for example
  `MangaDex (ES-419) (es-419)`, so the same source appears dozens of times.
- Evidence: DOM count of 238 option labels and rendered labels at desktop and mobile;
  the destination list is a long scroll region on a 375 px page.
- Impact: picking one destination is slow and easy to get wrong, in a workflow that
  changes where a whole shelf lives.
- Code: destination list in `resources/views/pages/⚡migrate-sources.blade.php`.

#### M4. No main landmark and no skip link

- Route: all pages
- Expected: a `<main>` region and a way past the navigation.
- Observed: a selector probe on the rendered shell found no `main` element, and there
  is no in-page anchor link. The page content is a generic region after the sidebar and
  header.
- Evidence: selector probe on a rendered page returned no match for `main` and none for
  `a[href^="#"]`.
- Impact: keyboard and screen-reader readers must traverse the full navigation on every
  page, with no shortcut to the content they came for.
- Code: `resources/views/layouts/app.blade.php:5-10` and the Flux sidebar layout it
  wraps.

### Low

#### L1. Browse and series document titles drop the name

- Routes: `/browse/{source}`, `/series/{manga}`
- Expected: the browser tab names the source or series, as the page body does.
- Observed: titles are `Browse - Papyrus` and `Series - Papyrus`.
- Impact: with several tabs open, or in history, the tabs are indistinguishable.
- Code: `resources/views/pages/⚡browse.blade.php:18`,
  `resources/views/pages/⚡series.blade.php:21`.

#### L2. The backups table labels a status column "Files"

- Route: `/settings/backups`
- Expected: the column says what the cell means, or the cell lists files.
- Observed: the `Files` cell reads "Backup is complete" in green, "One file has
  changed" or "Backup is incomplete". The same page also prints an absolute filesystem
  path from the checkout.
- Impact: a reader looking for file names finds a health verdict, and a machine path is
  exposed in the interface.
- Code: `resources/views/pages/settings/⚡backups.blade.php:1353`, `:1386`.

#### L3. The password confirmation page is stock framework copy with no way back

- Route: `/user/confirm-password`
- Expected: product wording and a cancel or back action.
- Observed: "This is a secure area of the application. Please confirm your password
  before continuing." There is a confirm button but no cancel link.
- Impact: a reader who reaches this page by accident must use the browser back button.
  The stock sentence is the only explanation.
- Code: `resources/views/pages/auth/confirm-password.blade.php:5`.

#### L4. Import copy points above to a picker that is below

- Route: `/settings/import`
- Expected: directional copy matches the layout.
- Observed: "pick it above" appears before the file picker and the server path field.
- Impact: a small but literal misdirection in a recovery workflow.
- Code: `resources/views/pages/settings/⚡import.blade.php:835`.

#### L5. The licence list includes build-time packages and is very long

- Route: `/settings/licences`
- Expected: the packages shipped with the install, with a readable grouping.
- Observed: 450 packages and 746 interactive elements in a page about 39,700 px tall.
  The JavaScript group includes build and development packages. One package is reported
  as not open source.
- Impact: a reader checking a licence obligation has to scan a list that mixes what
  ships with what only built it.
- Code: `resources/views/pages/settings/⚡licences.blade.php:44-46`, `:115`.

### Polish

#### P1. A clean search URL is rewritten before the reader types

- Route: `/search`
- Expected: `/search` stays clean until a search or filter is chosen.
- Observed: loading `/search` immediately rewrites the address to
  `/search?content[0]=SAFE&content[1]=MIXED`.
- Impact: a bookmark or shared link carries encoded filter internals, and the address
  bar looks like an error before the reader does anything.
- Code: the `content` URL-bound property in `resources/views/pages/⚡search.blade.php`.

## Not exercised

- `/read/{manga}/{chapter}`, because a GET writes reading progress and opens a reading
  session. This also means the reader shell, page images, chapter navigation, incognito
  mode and reading-time recording were not looked at.
- `/downloads/chapter/{chapter}/cbz` and `/settings/backups/export/{run}`, because both
  return a file and may create an export run.
- `/migrate/{manga}`, because mounting it starts matching work.
- `/settings/security` past the password gate; the password was not entered, so the
  password, passkey and two-factor controls were not reviewed.
- `/settings/connections` actions: test connection, disconnect, import progress.
- `/settings/profile` save and delete account.
- `/settings/appearance` controls; the page was read but no theme was switched.
- `/settings/import` import, inspect and re-apply.
- `/settings/backups` back up now, restore and delete.
- `/library` check, add series, create shelf, bulk select and bulk actions.
- `/series` chapter toggles, read-later, favourite, rating and download actions.
- `/browse` add to library.
- `/sources` install, update, uninstall, trust and language changes.
- `/downloads` refresh.
- Every form submission: login, forgot password, reset password, register.
- `/anilist/callback`, passkey and two-factor endpoints, email verification,
  `/dashboard`, `/up`, `/storage/{path}`, `/suwayomi/api/v1/{path}` and
  `/showcase/cover/{manga}`.
- Signed-out rendering: only HTML and HTTP were checked, not layout or interaction.
- Mobile for the settings pages other than import and backups, and for the profile,
  connections, appearance, about, licences, faq, privacy, terms, security confirmation,
  cover and 404 pages. Those are desktop-only results.
- Empty library, empty search results, no-result search, a running import, a running
  backup, a restore and the 500 page.

## For the security session

- `/showcase` returns 200 JSON without authentication and includes 18 series entries
  with title and cover URL. `/showcase/cover/{manga}` is public too. Decide whether
  this is acceptable in `shared` and `public` modes. In `single` mode the bind address
  is the only limit.
- `/ready` is public and reports key, database and pending-migration state. `/up` is
  public as well.
- Guest pages set a session cookie and, with the database session driver, a session
  row. The session cookie measured HttpOnly and SameSite=Lax, without Secure over HTTP
  (expected locally). `XSRF-TOKEN` is readable by script, which is normal for Laravel
  but should be checked against any CSP the app sends.
- The reader is a GET with side effects. Confirm that a link preview, a browser
  back/forward or a retry cannot create duplicate reading sessions or overwrite
  progress.
- `/suwayomi/api/v1/{path}` needs path and host checks. `/storage/{path}` is registered
  without auth middleware. `/covers/{manga}` returns an empty 404; check its cache
  headers.
- `/settings/backups/export/{run}` and `/downloads/chapter/{chapter}/cbz` need
  per-user authorisation checks on the run and chapter identifiers.
- The AniList callback, token storage and expiry display need review. The confirm
  password gate protects security settings but not the other settings routes.
- `/register` returns 404 while the route is registered. Confirm this is intentional
  and cannot be reached in a way that creates an account.

## What looked good

- The downloads empty state explains why a downloaded chapter matters: it reads
  without the source being reachable.
- Insights explains where its numbers come from and that creating them sends nothing.
- Search shows a count per language, collapses the long tail of single-source
  languages, explains the NSFW gate and leaves AniList matching off by default with an
  explicit third-party warning.
- The library's continue-reading row carries the next chapter and per-card progress,
  and the cover link is deliberately hidden from assistive technology while a labelled
  title link carries the destination.
- Health reports measured latency per site, a working count and how many were never
  asked, rather than a bare green tick.
- About has explicit too-old and newer-than-tested banners that name what may break,
  and prints both versions.
- Import explains the formats, that a `.tachibk` does not carry reading time, and why
  re-apply is dangerous.
- The 404 page says "This shelf does not exist." and offers "Back to the reading
  room", which fits the product.
- Every mobile page measured at a true mobile viewport (365 to 390 px content box) fits
  without horizontal scrolling: downloads, insights, library, search, sources, health,
  browse, series, import, backups and source migration.

## Read-only evidence

Measured after the walk:

- `chapter_states`: 21,449 rows. No row has an `updated_at` date of 2026-10-25; the
  newest is 2026-10-24. No row has `read_seconds` above zero.
- `reading_sessions`: 1 row, from 2026-10-24, with zero seconds.
- Downloads 0, import runs 14, live backup runs 2, restore runs 0, export runs 0,
  sources 238. These match the pre-walk inventory.
- `series_states`: 867 rows, 430 with a last-read chapter, matching the pre-walk
  inventory.

The numbers above are the check that the walk did not mark a chapter, start a session,
queue a download or run a job. A command exit code was not used as evidence.

## Limits

- One browser panel, two widths. Desktop is 942 px, not a wide screen; the 110 rem
  maximum was not reached, so very wide layout was not reviewed.
- Mobile was verified only where the body itself measured within the 390 px viewport
  (365 to 390 px). The remaining pages are marked desktop only rather than guessed at.
- Guest pages were checked as HTML, not as rendered pages.
- No reader title, cover, name, email, token or machine-specific path is written in this
  file.
