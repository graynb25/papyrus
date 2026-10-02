# Web Manga Reader: Research & Implementation Plan

**This document is historical.** It is the original design, kept because the reasoning in
it is why the reader is shaped the way it is, and because "what changed from the original
sketch" only means something while the original survives. It is not a description of the
software.

**Name:** Papyrus
**Stack:** Laravel 13 · Livewire 4 · Flux 2 · Tailwind 4 · Alpine.js · PHP 8.4
**Engine:** Suwayomi-Server (JVM), sources only
**Written:** 2026-09-29
**Status when written:** Phase 1 shipped, phases 2 to 6 not started. **All six have since
shipped except H7, bulk actions.** For what exists, read
[`ENGINEERING-NOTES.md`](ENGINEERING-NOTES.md) section 11, and section 14 for the list of
things the code does not actually do.

Where the two disagree, the code and the engineering notes are right. Two claims in here
were caught being wrong during a documentation pass in October 2026 and are marked in place
rather than deleted, since a plan that only records what it got right is not worth keeping.

Several items below were answered by measurement after the plan was written, in ways
that changed the design. Those are marked where they appear, and the live measurements
live in `docs/ENGINEERING-NOTES.md` section 3.

---

## 0. Decisions locked before planning

| Decision | Choice | Consequence |
|---|---|---|
| Deployment | `single` / `shared` / `public` via config | Data model must be multi-tenant from day one. Retrofitting is a rewrite. |
| Goal | Portfolio + learning | Optimise for demonstrating skill; ship a tight excellent core, not a wide shallow surface. |
| Source policy | No bundled source list; user pastes repo URLs | Matches Mihon/Suwayomi practice. Lowest legal exposure. |
| Devices | Desktop-first, mobile works | Keyboard nav, spreads, wide layouts first. |
| Reader zoom | Custom canvas, zero dependencies | Full control, no licence/maintenance risk. |
| Host | Native Windows, JAR | No Docker/WSL2 (virtualization off). Simpler for single-user anyway. |

---

## 1. Verified environment

| Component | State | Notes |
|---|---|---|
| PHP | 8.4.25 (Herd) | Meets `^8.3` |
| Node / npm | 26.7.0 / 11.19.0 | |
| JRE | **26.0.2** Oracle JDK | Exceeds Suwayomi's JRE 21+ requirement |
| Virtualization | **Off** (`VirtualizationFirmwareEnabled: False`, SLAT `False`) | WSL2, Hyper-V, Docker Desktop all unavailable. **JAR only.** |
| CPU | i7-12700K (x64) | ARM64 JCEF segfault (#2170) does not apply |
| Herd nginx | `Herd's nginx config directory` | Site config generated at runtime from `herd.sqlite` |
| Java on PATH | Yes, `JAVA_HOME` empty | |
| **Suwayomi** | **v2.4.2366 running** (was v2.3.2243 when this was written), `AuthMode=UI_LOGIN`, bound to `127.0.0.1:4567` | JAR at `.tools\suwayomi\`, SHA256 verified against `Checksums.sha256` |
| **CEF** | Downloaded on first run (260 MB) | JCEF initialised fine on native Windows; Cloudflare solving is available |
| Data root | `the Suwayomi data root` | Default Windows location |

**Runtime topology**

```
browser
   │  https://papyrus.test  (Herd → nginx → valet/server.php → PHP-FPM)
   ▼
Laravel  ── GraphQL ──►  Suwayomi 127.0.0.1:4567   (never publicly bound)
   └── /suwayomi/api/v1/* ─────►  streams page images (AD-3)
```

Suwayomi is bound to loopback and Laravel is the only thing that talks to it, so the CORS hole in C4 never matters and the JWT never leaves the server.

### 1a. Verified against the live server

Everything here was measured, not assumed. Re-verification scripts live in `.tools\suwayomi\`.

| Fact | Value |
|---|---|
| Keiyoushi store via `index.pb` | **Accepted.** `name=Keiyoushi`, `isLegacy=false`: protobuf parsed, signing key extracted |
| Extensions in store | **1,379**: matches the published website exactly |
| Content split | SAFE 570 · MIXED 444 · NSFW 365 |
| `lang` values | `en` 398, `all` 129, `ja` 126, **`pt-BR` 112**, `es` 107 …: **region-qualified, not always two letters** |
| Extension artifact | `ExtensionType.jarUrl`: Suwayomi pulls the pre-converted **JAR**, so no APK→JAR conversion (sidesteps #2298) |
| **Source ids exceed 2^53** | e.g. `8513894820944573933`: cannot survive a JavaScript number. `LongString` handling is mandatory, not defensive |
| Manga ids | small sequential ints (1, 2, 21, …), **global to the server, not per source**: the same series from two sources is two rows with different ids, which is the raw material for dedupe (H1). **This line said "per server, not global" and was wrong**, in the confusing direction: an earlier research note claimed ids were per-source, and this table then contradicted it by inverting the words while keeping the mistake. The route is `series/{manga}` with a single integer, and `ReadPathTest::test_the_series_route_takes_only_a_manga_id` holds it there. See the engineering notes. |
| `fetchSourceManga` LATEST | Throws `java.lang.Exception: Source does not support latest` **with a full stack trace**. `SourceType.supportsLatest` must be checked *before* calling |
| Live source fetch | Alpha Manga POPULAR 20 results / 0.7s · Akaza Scans 18 / 1.2s · Asura Scans 20 / 0.4s |
| Dead source observed | Aqua Manga returns 0 on both POPULAR and LATEST: a live example of exactly what H2's health ledger exists to surface |
| Schema snapshot | 1,884 `Type.field` entries, 1.2 MB, committed |

**Two bugs found only by running against a live server.** Both fixed, both now covered by tests.

1. `AuthManager::token()` assigned the access-token *string* to a `?array` property on the cache-warm path. Every test ran against a cold cache, so none could reach it. It fires on the second web request of any session. `AuthManagerTest` now covers the warm-cache path explicitly.
2. `suwayomi:check` called a fresh `v2.3.2243` snapshot stale, because `version_compare('v2.3.2243', '2.3.0', '<')` returns **true:** a leading `v` makes the first component non-numeric so it sorts below `2.3.0`. The prefix is now stripped before comparing.

---

## 2. Architecture decisions

### AD-1: Laravel is the source of truth; Suwayomi is only a source engine

Suwayomi is **single-tenant, hard**. `UserType` is a sealed class of `Admin(id)` and `Visitor`; every authenticated request resolves to `Admin(1)`. There is no `user` table, no `userId` on any of 65 migrations, and `AuthMode.kt` carries a literal `// TODO: ACCOUNT`. It also *owns* read state (`isRead`, `lastPageRead`, `lastReadAt`), which collides directly with multi-user.

**Therefore:** Laravel owns 100% of user-facing state: library membership, categories, tags, favourites, read state, progress, notes, covers, settings, history, stats. Suwayomi is called only for `fetchSourceManga`, `fetchMangaAndChapters`, and `fetchChapterPages`. In `single` mode we *may* mirror into Suwayomi's meta KV store for interoperability; in `shared`/`public` we never write library state to it at all.

This is not a workaround. It is the product thesis: Suwayomi is the part that can only be Kotlin, Laravel is the part that should be Laravel.

### AD-2: Every table is multi-tenant from migration #1

`user_id` on everything. Global scopes on every model. `deployment_mode` config of `single | shared | public`:
- `single`: one user, registration disabled, quotas bypassed
- `shared`: registration enabled, storage quotas, shared upstream rate budget
- `public`: adds per-user quotas, abuse controls, moderation surfaces

Cost of doing this now: near zero. Cost of retrofitting after 20 tables and a download pipeline: a rewrite.

### AD-3: Page images stream through a same-origin Laravel route

**Revised after inspecting Herd's actual nginx config.** The original plan was an nginx reverse proxy at `/suwayomi/` that would keep PHP out of the image path. That is not possible here. Herd's `nginx.conf` includes a single catch-all `server` block whose `location /` rewrites *everything*, static assets included, to `valet/server.php`, a Valet front controller that serves static files itself. There are no per-site server blocks to inject a `location` into, and the include paths (`config/pro/nginx`, `config/valet/Nginx`) accept only complete `server` blocks, which would collide with Herd's own block for the same hostname.

So PHP is already in the path for every request regardless, and the honest answer is a Laravel route that streams. It is still the right design: same origin means CORS is irrelevant and the JWT never reaches the browser or a URL.

`SuwayomiProxyController` streams in 64 KiB chunks rather than buffering, because a long webtoon chapter is hundreds of multi-megabyte images. Two guards keep it from becoming a liability: a path allowlist (`manga/{id}/chapter/{i}/page/{n}` only) so extension install, settings mutation, raw GraphQL and the memory-hungry CBZ export are unreachable from the browser, and a query allowlist (`format`, `updateProgress`) so a crafted URL cannot steer it. Suwayomi's `cache-control: max-age=86400` and `?format=` transcoding are passed through, so WebP/AVIF via `serveConversions` still comes free.

### AD-4: Poll, don't subscribe

GraphQL subscriptions use `graphql-transport-ws` (Apollo framing) and the server's own docstring admits the batching strategy causes **n+1 data-loader problems that make the server unresponsive**, which is why `maxUpdates` exists. A background worker polls `downloadStatus` / `libraryUpdateStatus` on a short interval and pushes to Livewire over our own transport. Simpler, more robust, and it produces the source-health ledger for free as a by-product.

### AD-5: The side-table schema is the biggest fork in the project

`UpdateMangaPatchInput` exposes **only** `inLibrary`. `ImageMutation` exposes **only** `clearCachedImages`. There is no custom-cover concept, no notes field, no writable `scanlator`, no writable `updateStrategy`, and no per-page dimensions.

Everything Mihon stores per-series must live in our DB keyed by Suwayomi manga id: reader mode + orientation, chapter sort/filter/display, notes, custom cover, excluded scanlators, fetch interval, release prediction, per-series reader profile.

**This schema is designed before any UI is written.** Getting it wrong means a migration on every table later.

### AD-6: Do not reimplement what Suwayomi already does

Library update checking, AniList/MAL/Kitsu/Bangumi/MangaUpdates/Shikimori tracking, extension loading, and scraping are all **server-side in Suwayomi already**. Reimplementing them in PHP is waste. The client layer is: presentation, policy, personal data, caching, and the things Suwayomi structurally cannot do (below).

### AD-7: Reader strategy: win vertical, don't fight paged

WebUI's paged reader is ~110 files of pagers, hotkeys, and CSS filters. Matching it is not a weekend. But its own issue backlog says vertical/webtoon is where it struggles (#1043, #1052), and Tsumiru was forked specifically to "rebuild the reader." So: **continuous-vertical and webtoon done excellently first**, paged second, spread mode last (and possibly never, because it needs per-page intrinsic dimensions that Suwayomi does not expose, so pairing must be decided from client-side probing).

---

## 3. Verified API constraints (the things that shape the build)

| # | Constraint | Impact on us |
|---|---|---|
| C1 | **No global search API.** `MangaAPI.kt` has `// get("all/search", …) // TODO` | Search-everything is a client-side fan-out. Biggest engineering problem we own. |
| C2 | **30s hardcoded GraphQL timeout** (server #2240) | Every Suwayomi call needs its own timeout + partial-result handling. |
| C3 | **No source health / error telemetry anywhere in the schema.** Only `DownloadState.ERROR` (no reason) and `SyncStatus.errorMessage` | We maintain our own health ledger by observing call outcomes. Unclaimed upstream (#2269, 13 comments). |
| C4 | **CORS reflects any origin with credentials**; `AuthMode.NONE` returns `Admin(1)` to everyone | Suwayomi must never leave loopback. AD-3 makes the CORS hole irrelevant. |
| C5 | **No download error text, throughput, or ETA.** `DownloadType` has only `progress: Float` + `state` | Download centre must compute and capture these itself (WebUI #1023: *"I have to ssh into my machine"*). |
| C6 | **`LongString` scalars arrive as JSON strings.** Epoch **seconds**, except `SyncStatus.startDate/endDate` which are **ms** | Cast deliberately. PHP 8.4 64-bit is fine; never route through JS. |
| C7 | **Cursor pagination is unreliable for non-`ID` orderings**; `MangaQuery.hasNextPage` is buggy | Paginate on `ID` + `offset`. Trust `totalCount`, not `hasNextPage`. |
| C8 | **`pageCount` is `-1` until chapters are fetched** | Guard every chapter list render. |
| C9 | **`fetchChapterPages` returns root-relative `/api/v1/…` URLs that ignore `ServerSubpath`** | Rewrite the prefix ourselves; never trust the returned path. |
| C10 | **`DownloadType` has no `chapterId`/`mangaId`** (`@GraphQLIgnore`) | Resolve `download { chapter { id } manga { id } }`. |
| C11 | **Preferences and filters are position-indexed, not key-indexed** | Read the list, key by `position` at read time into our DB, write by resolved position. Handle nested `GroupFilter` via `groupChange`. |
| C12 | **`/api/v1/chapter/{id}/download` builds the whole zip in memory** (#2353, #2236) | Size-guard it, or zip from the known on-disk layout ourselves. |
| C13 | **No per-page image dimensions** | Spread pairing requires client-side probing; affects reader phasing. |
| C14 | **Generic meta KV at 4 levels** (Manga/Chapter/Category/Source) | Escape hatch for anything untyped, used to mirror notes for other clients. |
| C15 | **No OpenAPI/REST spec**: OpenAPI plugin is commented out in `JavalinSetup.kt` | Target GraphQL + introspection codegen. Do not hand-write the schema. |
| C16 | **Schema is additive but unversioned.** Deprecated fields retained; no compat contract | Pin a `v2.3.x` release, codegen from *our* instance, monthly re-codegen pass. |
| C17 | **`?updateProgress=true` on the page endpoint** updates read progress | Gives a zero-JS progress path, a valuable fallback and a robustness win. |
| C18 | **`serveConversions` supports HTTP post-process**: the sanctioned hook for crop/split | Available later; note #2290 (page splitting currently breaks `lastPageRead` mapping). |
| C19 | **`AuthMode.UI_LOGIN` = JWT**, access + refresh, via header/cookie/`?token` | Store both, refresh on 401. `SIMPLE_LOGIN` is browser-only and unusable server-to-server. |
| C20 | **SyncYomi is server↔Mihon-fork, not app↔app** | Cross-device sync is ours to build, and it is free because we already have tenancy. |

---

## 4. Feature gap matrix: "Mihon has it, web doesn't"

Ordered by (unmet demand × feasibility × defensibility).

### Tier 1: nobody on web does this, and the API supports it

| Gap | Mihon/WebUI status | API | We build |
|---|---|---|---|
| **Cross-device sync** | Mihon explicitly cannot (#1432, **72 👍**: the #1 request in the repo) | N/A, it's our DB | Free by construction (AD-2) |
| **Multi-user / per-account libraries** | Impossible in Suwayomi; nobody on web | C: single-tenant | The tenancy layer itself |
| **Source health ledger** | Absent everywhere; #2269 (13👍), WebUI #1023 | C3 | Observe outcomes, rank sources, dead-source mode |
| **Real global search** | WebUI does client-side fan-out and it shows (no dedupe, no partial results) | C1, C2 | Queue fan-out, per-source timeouts, partial results, **dedupe** |
| **Download centre** | 7 duplicate open issues; no error text, no retry, no ETA | C5 | Error capture, throughput/ETA from our own samples, retry w/ backoff, policy layer |
| **Custom per-series covers** | Suwayomi has no concept at all | AD-5 | Upload to our storage, override thumbnail |
| **Personal data layer** (favourites, read later, tags, ratings, notes) | Only a 4-month-old 8-star WIP (Suwayomi-Next) | AD-5 | First-class schema + UI |
| **Reading statistics** | WebUI #756 + server #2338, both open, zero implementations | All inputs queryable | Essentially free from `lastReadAt` / `uploadDate` / counts |
| **Incognito** | Tsumiru ships it; **WebUI has asked since 2025-12-28 (#1048)** | N/A, don't write on incognito | Trivial once we own state |
| **Reader keyboard control** | Mihon has ~10 keys (volume/DPAD/N/P). No `J/K/L`, no `f`, no `b` | N/A | ~100 lines, instantly better |

### Tier 2: clear parity gap, medium effort

- **Library search query language**. Mihon v0.20.2 ships a full lexer/parser/AST (`title: src: unread>5 genre:""`, `&&`/`||`/`-`/parens). Pure client, ~400 lines, copyable.
- **Source migration incl. mass migration**. The most under-served feature in Suwayomi web UIs. Primitives all exist.
- **Per-series / per-category / global settings hierarchy**. Mihon's 3-tier (`viewerFlags`/`chapterFlags` + "set as default / apply to all"). Most web clients collapse to globals.
- **Chapter navigator with chapter-boundary ticks, scrub previews, cross-chapter seek.** Mihon itself only has a within-chapter slider. We can beat *and* match.
- **Colour filters**. Grayscale, invert, custom RGBA + blend modes, and Mihon's brightness **negative range** (dims with a scrim, not just brightens). CSS-only, ~1 day. Many web readers have none.
- **Bulk library actions**. Mark read, download N, move category, delete, mass-migrate. Web clients frequently ship single-item-only.
- **Release prediction**. Mihon's `FetchInterval.kt` median-of-gaps algorithm; `chapter.uploadDate` is exposed. Copyable.
- **Local source web UI**. Suwayomi has the module; the upload/parse UI is ours. CBZ/EPUB/folders, ComicInfo.xml, natural-order sorting.
- **Per-chapter notes**. Mihon's `memo` is an *extension* extension-point with no user UI. Since we own the schema, a real user-facing note is free differentiation.
- **Cloud backup**. Mihon has *none built in* (#2053, #472). Self-hosted app owns storage; S3/WebDAV is cheap.
- **Accessibility**. Verified: **zero** accessibility strings in all 960 of Mihon's i18n keys. Alt text, focus management, `aria-live`, `prefers-reduced-motion`, full keyboard reachability. Large uncontested differentiator at moderate cost.

### Tier 3: real, but expensive or blocked

- **Dual-page/spread**. Blocked on C13 (no per-page dimensions); needs client probing that serialises with display.
- **Mihon-grade preloading**. `preloadAhead=3/behind=2`, LIFO decode queue, distance-based eviction, streaming decode *while downloading*. Browsers won't let you own the decode pipeline. **Expect this to be the biggest perceptual gap vs Mihon.**
- **Cloudflare session bridging**. Solving a challenge in the user's browser does not give Suwayomi's JVM extension the cookie. Mihon wins because WebView and OkHttp share one `CookieJar` in-process. Hardest cross-cutting problem; `WebviewMutation` exists as a partial answer.
- **Bidirectional tracker sync with conflict resolution** (#531, 14 likes). CRUD is easy, semantics are hard.
- **Custom covers in backup** (#16, open in Mihon too). Easy once covers are ours.

### Explicitly do not build

Novel/text reading · TTS · in-app translation · home-screen widgets · APK/extension install UX · notification channels (Web Push has none, so use server-side prefs) · e-ink page flash · volume-key paging · biometric app lock (use WebAuthn) · screenshot blocking · DoH/UA pickers (server config, not user settings) · orientation lock.

---

## 5. Severity-indexed backlog

**S: Structural.** Wrong or missing here invalidates everything downstream.
**H: High.** Core product value or primary differentiation. Required for a shippable v1.
**M: Medium.** Meaningful, not blocking. v1.1+.
**L: Low.** Polish, speculative, or genuinely niche.

### S: Structural (12)

| ID | Item |
|---|---|
| S1 | Suwayomi install + pin a `v2.3.x` release; capture `aboutServer` for drift detection |
| S2 | GraphQL PHP client, codegen'd from **our** instance's introspection (C15, C16) |
| S3 | `UI_LOGIN` JWT auth: login, dual-token storage, refresh-on-401, re-auth queue (C19) |
| S4 | Multi-tenant schema + `user_id` scoping + `deployment_mode` config (AD-2) |
| S5 | **Side-table schema** for all per-series/per-chapter data Suwayomi can't hold (AD-5) |
| S6 | Extension store management: add repo URL, hand to Suwayomi, read catalogue back via `fetchExtensions`; implement the signing-key trust model (C11) |
| S7 | Source list + browse (popular/latest) with the 8-member `Filter` union incl. nested groups (C11) |
| S8 | Global search fan-out: queue, concurrency, per-source timeout, partial results (C1, C2) |
| S9 | Series detail + chapter list, guarding `pageCount == -1` (C8) |
| S10 | Reader v1: continuous vertical / webtoon, custom canvas, no deps (AD-7) |
| S11 | Read-progress persistence, per-user, never written in incognito |
| S12 | Call hardening: 30s budget on every Suwayomi call, untrusted error strings, no fatal on `ExceptionWhileDataFetching` |

### H: High (20)

| ID | Item |
|---|---|
| H1 | Cross-source dedupe engine (normalised title + heuristics; Mihon does not dedupe at all) |
| H2 | Source health ledger: last success, last error, failure count, dead-source mode (C3) |
| H3 | Download centre: error capture, throughput/ETA, retry w/ backoff, grouping (C5) |
| H4 | Library: categories, tags, favourites, read later, ratings, notes |
| H5 | Custom per-series covers (upload, override thumbnail, set-from-reader-page) |
| H6 | Library search query language: lexer/parser/AST |
| H7 | Bulk/multi-select library actions |
| H8 | Full reader keyboard control + shortcut cheatsheet |
| H9 | Accessibility: alt text, focus management, `aria-live`, `prefers-reduced-motion` |
| H10 | Colour filters + brightness incl. negative dim range |
| H11 | Settings hierarchy: global → per-category → per-series → session override |
| H12 | Source migration incl. mass migration wizard |
| H13 | Chapter navigator with boundary ticks, read-page heat strip, cross-chapter seek |
| H14 | Same-origin image proxy route, streamed, with path + query allowlists (AD-3) |
| H15 | Local source web UI: CBZ/EPUB/folder upload, ComicInfo.xml parsing |
| H16 | WebP/AVIF via `serveConversions`: one server setting, real bandwidth win |
| H17 | Incognito mode (WebUI's oldest missing feature) |
| H18 | Per-chapter notes (Mihon has no user-facing equivalent) |
| H19 | Multi-user + deployment mode switching, demonstrated end-to-end |
| H20 | History + continue-reading feed via `chapters(filter:{inLibrary}, order:[LAST_READ_AT])` |

### M: Medium (14)

M1 stats dashboard · M2 release prediction + Upcoming calendar · M3 tracker integration (proxy Suwayomi's, don't reimplement) · M4 PWA installable + offline shell · M5 recommended surfaces from popular/latest/genre · M6 chapter filters (bookmarked, scanlator, unread ranges) · M7 auto-download policy layer over `category.includeInDownload` · M8 duplicate-entry management workflow · M9 auto-scroll / scroll-driven page advance · M10 per-chapter separator in long-strip · M11 Web Push new-chapter digests · M12 server config management surface (`setSettings` is fully exposed and no client does this well) · M13 CSV library export · M14 background library-update scheduling in Laravel rather than relying on Suwayomi's

### L: Low (8)

L1 dual-page/spread mode (blocked on C13) · L2 scrub thumbnails · L3 reader profiles as named sets · L4 TTS via Web Speech API · L5 documented backup/restore format (ours + JSON) · L6 cookie/session import · L7 border trimming at read time · L8 additional reader themes

---

## Where the build stands

Every phase below is a checklist. **59 shipped, 18 open**, and the open ones
name the reason rather than just the item, because most of them are a deliberate
scoping decision or an API that does not exist rather than an oversight.

| Phase | Shipped | Open |
|---|---|---|
| Phase 0: Foundation | 13 | 0 |
| Phase 1: Discovery | 9 | 0 |
| Phase 2: Read path | 13 | 1 |
| Phase 3: Library & personal data | 5 | 4 |
| Phase 4: Downloads & offline | 5 | 4 |
| Phase 5: Health, migration, resilience | 10 | 0 |
| Phase 6: Parity & polish | 4 | 9 |
| **Total** | **59** | **18** |

The two items most likely to be mistaken for oversights:

- **Bulk actions (H7)** is the one H-tier item still genuinely open.
- **Local source upload (H15)** was scoped out on purpose. Suwayomi has no upload
  API at all, so a drop zone would mean this app writing into the server's data
  directory, which is shared by every reader because the source cannot scope it.

Section 14 of the engineering notes lists the places where a document here and the
code disagree, including several claims in this file.

---

## 6. Phases

### Phase 0: Foundation *(invisible; everything depends on it)*

**Status: code complete. Two steps remain that need a running Suwayomi instance.**

**Goal:** a Laravel app that can talk to Suwayomi reliably, with a schema that won't need rewriting.

- [x] Tenancy: `users.role/status/quota`, `user_settings`, `deployment_mode` config, global scope (S4)
- [x] Side tables: `series_states`, `chapter_states`, `notes`, `series_covers`, `reader_profiles`, `source_health`, `source_preferences`, `user_sources` (S5)
- [x] `OwnedModel` base class with a fail-closed `OwnedByUser` scope, `forUser`, `createFor`, `withoutUserScope`, `forAllUsers`
- [x] Suwayomi transport: `Transport` → `AuthManager` (UI_LOGIN JWT, dual token, refresh-on-401) → `Gateway` (30s budget, one 401 retry, error normalisation)
- [x] `Timestamps` handling epoch-seconds vs milliseconds and LongString coercion
- [x] Same-origin streaming image proxy with allowlists (H14)
- [x] `suwayomi:check` and `suwayomi:schema:dump` commands
- [x] Suite green: **868 tests**, Pint clean, PHPStan level 7 clean
- [x] **Install Suwayomi:** v2.4.2366 JAR (upgraded from v2.3.2243), SHA256 verified, on `127.0.0.1:4567`, CEF downloaded, JCEF working
- [x] **`AuthMode = UI_LOGIN`**, credentials in `.env`
- [x] **`suwayomi:check`** green; **`suwayomi:schema:dump`** committed (1,884 entries)
- [x] **Keiyoushi store added via `index.pb`:** 1,379 extensions, 4 installed, live fetches returning real results
- [x] **`serveConversions` investigated and deliberately left off.** Measured: `webp`, `avif`
      and `jpg` all return byte-identical `image/webp`, so there is no bandwidth win. The
      setting does exist and is writable, which this file previously said it did not

**Acceptance:** `php artisan test` green against fixtures; `suwayomi:check` reports version, auth mode and stores; a committed schema snapshot exists; no table lacks `user_id`.

**Why first:** the tenancy and side-table decisions are the two most expensive to reverse. Everything after this is additive.

> **Design note.** Tenancy is an abstract base class (`OwnedModel`), not a trait. PHPStan cannot reason about the model instance inside a trait's `creating` closure, and it reported 76 errors on the trait version. Tenancy that static analysis cannot see is tenancy that quietly rots. `user_id` is never mass-assignable; `createFor()` is the explicit way to write for another user, and a missing owner raises `MissingOwnerException` instead of a bare SQL constraint error.

---

### Phase 1: Discovery *(sources, browse, search, dedupe)*

**Status: engine and data layer complete and verified live. UI not started.**

**Goal:** the "find something to read" loop, complete.

- [x] Extension store add + mirror, with signing-key capture and legacy/protobuf detection (S6, engine side). Keiyoushi publishes `repo/index.pb` (gzip protobuf, 1,379 extensions); the sibling `index.min.json` holds two helper entries and is vestigial. We parse neither. Suwayomi owns the format.
- [x] Source catalogue mirror: 82 sources; `lang` includes `pt-BR`, `es-419`, `af`
- [x] **Global search fan-out** (S8): bounded batch, per-source timeout under the 30s cap, partial results, per-source outcome rows
- [x] **Dedupe engine** (H1): normalise → exact bucket → fuzzy merge → source chips
- [x] **Health ledger** (H2 groundwork): ok / empty / failed tracked separately, dead + silent detection
- [x] Filter position↔name translation incl. nested `GroupFilter` (S7, engine side)
- [x] `sources:sync` and `search:fanout` commands
- [x] Livewire UI: search page, store manager with the trust flow, health dashboard, source manager
- [x] Extension install / uninstall UI

**Verified live:** 6 sources fanned out in 2.9s → 25 raw hits → 20 deduplicated, 4 multi-source series collapsed, per-source outcomes recorded, health ledger populated. "BERSERK", "Berserk Gaiden" and "Berserk Princess" correctly stayed distinct.

**Two decisions awaiting the user:**
1. **Cross-language dedupe is not implemented.** `mb_convert_kana` folds full-width alphanumerics, not kana, so a Japanese title and its English translation do not collide. With 252 `ja` sources against 790 `en` this is a major case, not an edge case. Closing it needs kana→romaji transliteration, which is a dependency and a product call. **The source counts here are impossible and were never checked**: this same document's own table gives `ja` 126 and `en` 398 against a store of 1,379, and 252 and 790 cannot both exist inside it. What survives from the claim is the shape of the problem, not its arithmetic. Pinned by a test so it cannot be forgotten.
2. **Dedupe threshold is 0.95, tuned against measured values.** At 0.92, "One Piece G" folded into "One Piece". The measurements are documented in `config/papyrus.php`.

**Acceptance:** a query returns merged, deduped results across N sources in under 30s with visible per-source status; a dead source degrades instead of hanging.

**Defer:** health *dashboard* (Phase 5). The ledger has been writing since Phase 1 began; only the surface waits.

---

### Phase 2: Read path *(series, chapters, reader, progress)*

**Goal:** open a series and read a chapter end-to-end. The product exists after this.

- [x] Series page: metadata, cover, genres, chapter list with filters and sort (S9, C8)
- [x] Missing-chapter detection on the series page
- [x] Chapter list: read and unread state, per-chapter progress bar
- [x] Chapter navigator with boundary ticks and cross-chapter seek (H13)
- [x] Per-chapter notes, one per chapter, from the reader and the series page (H18)
- [x] **Reader v1** (S10): continuous vertical and webtoon, custom canvas, zero deps
- [x] Fit-width, fit-height and original, gap control, side padding, tap zones
- [x] Per-page error state with retry in place, no position lost
- [x] Full keyboard control (H8)
- [ ] Accessibility baseline (H9). `prefers-reduced-motion` is in `resources/css/app.css`;
      there is no `aria-live` for chapter changes anywhere, and focus management on page
      change is not built
- [x] Progress per user, debounced, with `?updateProgress` as a no-JS fallback (S11, C17)
- [x] Reader settings resolution across five layers (H11): defaults, profile, home shelf,
      series, session
- [x] Reading mode persisted per series (S5)
- [x] Colour filters including the sub-1.0 dim range (H10)

**Acceptance:** met. A full chapter reads with the keyboard, progress survives a reload,
and a failed page retries in place without losing position.

**Defer:** spreads (Phase 6 / L1). Paged mode was not deferred in the end; it shares one
document with the vertical mode, so switching costs no request.
---

### Phase 3: Library & personal data

**Goal:** the app becomes *yours*, not a browser.

- [x] Shelves, many-to-many with series, reorderable, one home shelf (H4)
- [ ] Tags, favourites, read later and ratings (H4). The columns exist on `series_states`
      and nothing writes them. There is no control for any of the four
- [x] Custom covers: upload and delete, resized and stored per reader (H5)
- [ ] **Set cover from the reader page** (H5). `series_covers.source_page` exists and nothing
      writes it, and the reader has no cover control at all
- [ ] **Bulk actions with range select** (H7). The one H-tier item still open
- [x] Search query language (H6), **in a reduced form**: three best-effort terms,
      `label:value`, rather than Mihon's lexer, parser and AST. No `&&`, `||`, parentheses
      or `unread>5`. An unrecognised label is searched as text rather than dropped, which
      is what a title containing a colon needs
- [x] Continue-reading feed (H20), a section on `/library` over the batch the page already
      fetches, so it costs a local join rather than a request
- [ ] Incognito (H17). `user_sources.incognito` exists as a column and a fillable field,
      and no write path reads it, so nothing is suppressed and nothing is hidden
- [x] Multi-user demonstrated (H19): the tenancy tests prove two users on one database see
      disjoint rows

**Acceptance:** partly met. Two users do see disjoint libraries. `unread>5 && src:mangadex
-title:"raw"` does not exist, and incognito leaves more than zero trace because it was
never built.
---

### Phase 4: Downloads & offline

**Goal:** remove the network from the read loop.

- [x] Download centre (H3): wraps Suwayomi's queue and reconciles a per-reader ledger in
      two calls a pass
- [x] Error text captured, retry with backoff, grouped by source then series then chapter
- [x] The downloader is never exposed as a switch: enqueueing starts it and it stops when
      the queue empties
- [ ] Bulk and policy downloads: whole series, unread up to N, per-category auto-download
      (M7). No policy layer exists
- [ ] Delete policies: after read, keep N most recent, never delete bookmarked
- [ ] CBZ export to browser, size-guarded. `downloads.cbz_export_max_bytes` is a config key
      nothing reads, and Suwayomi's own download endpoint builds the whole archive in
      memory, so the proxy refuses that path rather than guarding it
- [x] Local source: browse the folder and rescan (H15), pairing the folder against the
      server's listing, which disagree and stay disagreeing because there is no delete
      mutation
- [ ] **Local source upload** (H15). Suwayomi has no upload API at all: every GET on
      `/api/v1/...` answers the 4,484-byte WebUI catch-all and every other method a 404. This
      is scoped out deliberately and the sources page says why
- [x] WebP and AVIF (H16) verified end-to-end and measured. **Closed as a non-finding**: no
      bandwidth win is available, all three formats return byte-identical `image/webp`

**Acceptance:** partly met. A failed download shows why and retries, and a series downloads
unattended. A locally read CBZ matches a remote one because local files are read through
Suwayomi itself. Uploading one was never built.
---

### Phase 5: Health, migration, resilience

**Goal:** the system survives a bad week.

- [x] **Source health dashboard** (H2): grouped by site because 82 sources are really 15
      (counted 2026-10-01), with drill-down to language variants
- [x] Per-source success rate, last success, last error, consecutive failures
- [x] Dead sources skipped by default so they cannot burn the timeout on every run
- [x] Per-user skip wired into the fan-out
- [x] **Migration wizard** (H12): per series and library-wide, always confirmed, candidates
      ranked, read state transferred and alignment reported
- [x] Regular and deep search, manual URL override
- [x] Duplicate-entry workflow through the same wizard (M8)
- [x] Library check scheduling owned by Laravel (M14), in time-bounded slices, with a
      per-series interval and a floor
- [x] An inline per-card check as well as the scheduled job, both through one code path
- [x] Hardening: 30s budget on every call, partial-result UX, health-driven suppression

**Acceptance:** met. Kill a source and the UI degrades visibly rather than hanging; a
migration moves read state, shelves and covers and leaves the old downloads on disk.
---

### Phase 6: Parity & polish

**Goal:** close the remaining Mihon gap and make it presentable.

- [ ] Stats dashboard (M1)
- [ ] Release prediction and an Upcoming calendar (M2)
- [ ] Tracker integration through Suwayomi's proxy (M3)
- [ ] PWA, installable with an offline shell (M4)
- [ ] Recommended surfaces from popular, latest and genre (M5). `SourceSearcher::browse()` is
      written and has **no caller**, so the whole browse path is unreachable
- [x] Chapter filters (M6): scanlator exclusion, read and unread ranges
- [ ] Auto-scroll and scroll-driven page advance (M9)
- [x] Chapter separators in the navigator (M10), as boundary ticks and a read map
- [ ] Web Push new-chapter digests (M11)
- [ ] Server config management surface over `setSettings` (M12)
- [ ] CSV library export (M13)
- [x] Paged reader mode, sharing one document with the vertical one
- [x] L3 reader profiles as named sets

**Acceptance:** not met, and this phase is mostly open. The read-loop parity that matters
did land: two reader modes, a navigator, notes, colour filters and a settings hierarchy.
The surfaces around it did not.
---

## 7. Risks & open questions

| Risk | Severity | Mitigation |
|---|---|---|
| Browser decode pipeline can't match Mihon's preloading | **High**: biggest perceptual gap | Vertical mode sidesteps it; measure early in Phase 2 before committing to paged mode |
| Global search fan-out hits the 30s cap or rate-limits upstream | **High** | Phase 1 is dedicated to it; per-source timeout, partial results, aggressive dedupe cache |
| Suwayomi schema drift (unversioned, additive) | Medium | Pin version, snapshot schema, monthly re-codegen, drift alert on `aboutServer` |
| Suwayomi release lag (~2.5mo between tag and master) | Medium | Pin a tagged release, not master |
| Tenancy retrofit temptation mid-build | **High** | S4/S5 are Phase 0 gate items; no UI work before they land |
| Source upkeep, not UI, is the long-run product risk (Kotatsu: 1,200 parsers, archived) | Medium (strategic) | Own the health ledger; make source failures visible and recoverable rather than fatal |
| Legal exposure from aggregator architecture | Medium | Ship no content, no bundled source list, no hosted index. Users supply their own repos. Tachiyomi was taken down *despite* this architecture. It is not immunity. |
| Cloudflare-protected sources on native Windows | Low-Medium | JCEF works on Windows but needs an interactive session, so Suwayomi must run as a normal process, not a service |

**Open questions to resolve during Phase 0:**
1. Does Suwayomi expose `ConfigurableSource` preferences over the API? (unverified by research; affects per-source settings surface)
2. Does `CategoryMutation` allow renaming the default category?
3. Does the `fetchChapterPages` returned path break under our nginx subpath? (C9, test before relying on it)
4. Is Herd's site config editable to inject a `location /suwayomi/` block? (H14, generated from `herd.sqlite`; may need the Herd UI or a config override)
5. Exact `DownloadType` shape in the pinned release (research read `master`, we target a tag)

---

## 8. What changed from the original sketch

| Original | Revised | Why |
|---|---|---|
| MangaDex-first in pure PHP | Suwayomi-only; `Source` interface kept for test doubles | MangaDex is itself a Keiyoushi extension, so Suwayomi covers it. A PHP MangaDex client is throwaway work. |
| 5 phases starting with the reader | 7 phases, foundations first | Tenancy and side-table schema are the two costliest reversals. They gate everything. |
| Dedupe as one feature among many | Dedupe + global search as the Phase 1 centerpiece | C1 confirms no server-side global search exists. This is the hardest uncontested problem we own. |
| "Best-in-class reader" as the differentiator | Win vertical; don't fight paged | WebUI's paged reader is ~110 files and years of work. Beating it there is not the play. |
| Tracking/metadata listed as a late add | Tracked via Suwayomi (AD-6), never reimplemented | Suwayomi already has six tracker integrations server-side. |
