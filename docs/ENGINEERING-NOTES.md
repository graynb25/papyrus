# Engineering notes for Papyrus

A self-hosted web manga reader. Laravel 13 + Livewire 4 + Flux 2 + Tailwind 4 + Alpine,
on top of **Suwayomi-Server**, which runs the Tachiyomi/Keiyoushi extensions.

This file is the reference for anyone picking this up, human or agent. If something here
contradicts the code, the code is right and this file needs fixing.

## The three documents

| File | What it is for |
|---|---|
| `docs/ENGINEERING-NOTES.md` | This file. How the project works, what has been measured, what has bitten. |
| `README.md` | Running it, and the shape of the codebase. Start here if you have never seen it. |
| `docs/SETUP-SUWAYOMI.md` | Installing and configuring Suwayomi, including why it runs as a JAR. |
| `docs/READER-PLAN.md` | The original plan and its backlog. Historical now; the phase table in section 11 is current. |

---

## 1. The one fact everything follows from

**Keiyoushi extensions are Android APKs containing Kotlin. PHP cannot run them.**

Suwayomi-Server is a JVM application that does run them, behind roughly 200 AndroidCompat
shim classes and a bytecode repair step. It exposes a GraphQL API.

> Suwayomi parses. Papyrus presents.

Every architectural decision below falls out of that sentence. Never propose re-implementing
a parser in PHP. The `docs/READER-PLAN.md` section on what changed from the original sketch has
the list of dead projects that already tried. If a needed source does not exist, the options
are (a) write a Tachiyomi extension in Kotlin, (b) add a source to Suwayomi, or (c) write one
targeted PHP parser for one site, as a last resort.

---

## 2. Verified environment

| Thing | State |
|---|---|
| PHP | 8.4.25, served by Herd. On this machine Herd's own `php.bat` is the one on PATH. |
| Node / npm | 26.7.0 / 11.19.0 |
| JRE | 26.0.2 Oracle JDK, which satisfies Suwayomi's JRE 21+ requirement |
| Virtualization | **OFF.** No WSL2, no Hyper-V, no Docker. Suwayomi runs as a **JAR**. |
| CPU | i7-12700K x64, so the ARM64 JCEF segfault does not apply |
| Suwayomi | **v2.4.2366**, running, `AuthMode=UI_LOGIN`, `127.0.0.1:4567`. Upgraded from v2.3.2243; H2 schema migrated 59 to 65 with no errors and all 82 extensions intact. |
| JAR | `..\.tools\suwayomi\Suwayomi-Server-v2.4.2366.jar`, SHA256 verified against the release's `Checksums.sha256`. The v2.3.2243 jar is kept beside it so a rollback is one move. |
| CEF | Downloaded on first run (260 MB). JCEF works on native Windows. |
| Herd site | `papyrus`, a symlink out of Herd's own `Sites` directory into this repo |
| Data root | Suwayomi's own, which on Windows is `%LOCALAPPDATA%\Tachidesk`. Set by Suwayomi, not by us. |
| Dev credentials | A throwaway username and password in `.env`, which is git-ignored. Local only, loopback bound. Generate one rather than reusing a password. |
| Second dev environment | A second Windows account with its own Herd install and its own Suwayomi data root (October 2026), serving the same repo at the same hostname. Useful for checking that nothing depends on one machine's state. Credentials live in that account's `.env`, not this repository's. |
| App | `papyrus`, served at `http://papyrus.test` |
| Schema snapshot | `resources/schemas/suwayomi-introspection.json`, 1,884 entries |

**Run Suwayomi as a normal process, never a Windows Service.** JCEF needs an interactive
desktop session. A service runs in Session 0 with no desktop, and Cloudflare protected
sources then fail silently.

---

## 3. Verified Suwayomi behaviour

Measured, not assumed. Re-verify with the scripts in `..\.tools\suwayomi\`.

| Fact | Value |
|---|---|
| Keiyoushi store | Accepted at `https://github.com/keiyoushi/extensions/raw/repo/index.pb`, `isLegacy=false` |
| Extensions | **1,379** (SAFE 570 / MIXED 444 / NSFW 365) |
| `index.min.json` on the same branch | Two helper entries, so it is vestigial. `index.pb` is the real catalogue. |
| Extension artifact | `ExtensionType.jarUrl`. Suwayomi pulls the **JAR**, not the APK. |
| **Source ids exceed 2^53** | e.g. `8513894820944573933`. **Never** route these through JavaScript. |
| **Manga ids are GLOBAL, not per source** | `manga(id:)` resolves with **no source named**, and ids are distinct across sources. Manga 174 is One Piece on MangaDex, 76 is a Berserker title on Asura Scans. Both resolve from the bare int. **An earlier research note claimed ids were per-source; that was wrong.** The series route therefore carries one number, not a source/manga pair. |
| Manga id / chapter id width | 32-bit `Int!`. Distinct from source ids, which are `LongString` and exceed 2^53. |
| `manga(id:).chapters` | Takes **no arguments at all**. No `first`, no `offset`, no `order`. A 41 chapter series comes back whole. Paginate for the browser, not the server. |
| `ChapterType.pageCount` | **`-1`** until that chapter's pages have been fetched. True for every chapter of a freshly fetched series. Only `fetchChapterPages` produces a real count. |
| **`ChapterType.uploadDate` is MILLISECONDS** | `'1767198093000'`. Read as seconds it renders as year **57970**, and across a real 41 chapter series that put **all 41 chapters in the future**. |
| `ChapterType.fetchedAt` is seconds | `'1790776081'`, in the same payload as the millisecond field above. Mixed units within one document. |
| `MangaType.age` / `.chaptersAge` | `'88'`. Days since last update, not a timestamp. Passing either to a date decoder gives 1970. |
| `fetchChapterPages` | 2.0s for a 22 page chapter. Returns `pages` as root-relative `/api/v1/...`, and sets a real `pageCount` as a side effect. The slowest call in the app. |
| `fetchMangaAndChapters` on an unfetched manga | Throws bare `java.lang.Exception: No chapters found`, delivered as a GraphQL error on **HTTP 200**. An upstream condition, not our fault. |
| `lang` | Region qualified: `en`, `ja`, `pt-BR`, `es-419`, `zh-Hant` |
| `fetchSourceManga` | A **mutation**. An unsupported `LATEST` throws a bare `java.lang.Exception` with a stack trace, so check `supportsLatest` first. |
| Filter union | 8 members. **None carries a key.** Only `name` plus an ordinal `position`. |
| `GroupFilter.groupChange` | A **single** nested change, not a list. Several children means several entries sharing the outer `position`. |
| `default` on the filter union | Five different types across the members. Alias it per member or GraphQL raises `FieldsConflict`. |
| Dead source example | Aqua Manga returns 0 on POPULAR and LATEST with no error at all. |
| GraphQL timeout | Hardcoded 30s server side (Suwayomi-Server#2240) |
| Auth | `UI_LOGIN` only. `SIMPLE_LOGIN` is browser only, in memory, roughly 30 minutes. |
| Access token life | **300s**, `exp` claim, `token_type: access`. |
| Refresh token life | **5,184,000s** (60 days), `token_type: refresh`. |
| `refreshToken` mutation | Takes the refresh token, returns `accessToken` only. **No rotation**: the same refresh token works repeatedly. |
| **Expired token on a `@RequireAuth` field** | **HTTP 200**, not 401. The body's `errors` holds `Exception while fetching data (/x) : Unauthorized` plus `suwayomi.tachidesk.server.user.UnauthorizedException`. |
| Bad refresh token | Also HTTP 200, with a GraphQL error. Even the shape complaint ("expected to have 3 parts") comes back this way. |
| `extensions` filtering | Two separate arguments: `condition: ExtensionConditionInput` (flat scalars only) and `filter: ExtensionFilterInput` (nested, with `and`/`or`/`not`, `in`, `includesInsensitive`). **Use `filter`.** |
| `extensionCount` at 1,379 rows | 65 to 270ms server side, so narrowing in Suwayomi is cheaper than paging the catalogue into PHP. |
| **82 sources are 15 sites** | Keiyoushi ships one extension per language and they share a `home_url`. Measured: 61 MangaDex, 7 MangaFire, 14 singletons, plus `Local source` with no URL. Group any per-source view by `home_url` or it repeats one site 61 times. |
| **Language variants are NOT redundant** | The 61 MangaDex variants return the same 51 manga rendered in **different scripts**. "One Piece (Official Colored)" in EN is "ONE PIECE カラー版" in JA. Measured on a "one piece" run: 36 variants share one title set, 25 each hold something the others do not. Collapsing to one variant per site loses **35%** of titles. **Fan out to every source.** |
| MangaDex script coverage | Per variant: EN 15 latin titles, 0 other. JA 6 latin, 9 other. AR 13/2, BN 14/1, ZH-HANT 14/1, RU 13/2. So the minority scripts are reachable only through their own variant. |
| Set cover over MangaDex | 25 of 61 variants reach all 51 titles; 5 variants reach 59%. **Not used**, because which 25 are redundant is specific to one query and there is no evidence it holds for the next. |
| Fan-out over 82 sources | 30.8s, 1080 raw hits, 91 after dedupe, 30 multi-source series. |
| **`downloadStatus.queue` is a plain list** | `queue: [DownloadType!]!`. No connection, no `pageInfo`, no `id` on a `DownloadType`, so it cannot be paged or addressed. It is also **only what is happening now**: an entry leaves the instant its chapter finishes. The one lasting trace is `ChapterType.isDownloaded`. |
| `DownloadType` fields | `position`, `progress`, `state`, `tries`, `chapter`, `manga`. That is all of it. **No error text, no throughput, no ETA**, so a download centre has to record its own history. |
| **`DownloadType.progress` is a FRACTION** | 0 to 1, **stepping once per page**: measured 1/28 apart on a 28 page chapter, 0.0357, 0.0714, ... to 1. It looks dead if you sample a fast chapter twice and believe it. |
| Enqueue input field | **`id`**, not `chapterId`. `EnqueueChapterDownloadInput.id: Int!`; the batch form is `EnqueueChapterDownloadsInput.ids: [Int!]!`. Sending `chapterId` is rejected as an undefined field. |
| **Enqueueing starts the downloader by itself** | Verified with the downloader STOPPED: enqueue five chapters, and half a second later `state` is `STARTED` with five queued, with no `startDownloader` call. It also **stops itself** when the queue empties. So no code needs to touch that switch, and no stop control is drawn. |
| `DownloadState.ERROR` | Carries **no reason**. `tries` is a retry counter. Neither tells a reader why a chapter failed. |
| `pageCount` after a download | Filled in. Measured: `-1` before, 15 / 25 / 26 / 24 / 28 / 28 after. **A download is also what makes a chapter's length knowable.** |
| **A downloaded chapter reads through the reader's own URL** | `manga/76/chapter/1/page/0` returns `image/webp` (181KB, then 1MB) with no source request, which is the path the image proxy already allows. Verified in the browser: `papyrus.test/suwayomi/api/v1/manga/76/chapter/1/page/0?format=webp`. **This is why downloads are worth building at all**: going offline needed no URL work. |
| `api/v1/download/{chapterId}` | **Not an API.** Returns 200 `text/html`, 4,484 bytes: the WebUI's index page. A catch-all, not an endpoint. |
| `mangas(filter: {id: {in: [...]}})` chapter nodes | `isDownloaded` rides along free on the same call the library page already makes, which is how a sync resolves finished-versus-failed in **one** bulk call rather than one per chapter. |
| **Source migration** | **Not exposed at all.** Zero GraphQL fields across 33 query, 82 mutation and 6 subscription fields, checked live rather than from the snapshot. No REST v1 endpoint either: every candidate path is either the 4,484-byte WebUI index or a Ktor 404 naming the method and path. A real miss says `Endpoint PUT /api/v1/... not found`, which is what makes "not found" distinguishable from the catch-all. The capability is in the server and behind its WebUI, and it would act on `Admin(1)`'s library, which in a multi-reader app is the wrong owner. So the wizard composes the move. |
| `MangaType.trackRecords` | `totalCount: 0`. **AniList or MAL id is not usable as a migration key**, because it is only populated once a reader has logged into a tracker. Available, not present. |
| Migration search, live | 81 sources, one term each: **21.9s sequential** in a bare process, **27.6s** on a queue worker, p50 143ms and worst source 1459ms, zero failures. 199 raw hits, **7 sites**, 7 candidates. |
| Migration candidate fetch | 370ms to 1346ms per site, and it **writes chapter rows into the server's database** whether or not a reader ever opens the series, which is why the budget caps fetches rather than sites. |
| Chapter alignment across sources | `chapterNumber` places **41 of 41** uniquely. `sourceOrder` 41 of 41. `name` places **0 of 41**: the two sources label the same chapter `Chapter 1` and `Ch. 1`. Any matcher leaning on the label would carry nothing and look like it worked. |
| Live candidate ranking | 7 sites, all seven titles at similarity 1.000, so the title barely discriminates and the chapter count does the work: 41 (delta **0**), 39 (-2), 28 (-13), 21 (-20), 210 (+169), 333 (+292), and one `No chapters found`. |
| **AniList's public API** | `https://graphql.anilist.co`, **HTTP 200 with no key**, no OAuth. Measured 774ms to 835ms a lookup. The tracker through Suwayomi is the wrong door and stays unused: `trackRecords` is empty unless a reader logs in. |
| AniList rate limit | **`x-ratelimit-limit: 30` per minute**, not the 90 AniList documents for an authenticated client. `x-ratelimit-remaining` counts down and a 429 carries `Retry-After`. This is the constraint the whole integration is shaped around: 91 distinct titles is over three minutes of requests. |
| **AniList's title fields disagree with each other** | Searching "The Berserker's Second Playthrough" returns media 205085 whose romaji is **"Game Sok 2-hoecha Gwangjeonsa"** and native **"게임 속 2회차 광전사"**. The English title appears **only in `synonyms`**. Matching must read synonyms plus all three title forms. |
| Cross-script identity | Measured live: `Chainsaw Man` and `チェンソーマン` both resolve to **105778**. `One Piece` and `ワンピース` both to **30013**. This is the join the fuzzy title matcher cannot make, because there is no shared text. |
| What AniList staff gives | The **original** author: `Original Story: Insaengmanghaesseum, Story & Art: Isang`, where sources variously report `Life Ruined`, `Isang`, `Isang, Life Ruined` and an empty string. |
| AniList coverage of a fan-out | **71 of 91 titles have no entry**, mostly doujinshi and anthologies that never will. So "asked and told no" has to be a recorded state, and its sentinel must never become a grouping key. |
| AniList grouping, measured | On a "one piece" fan-out, 1,080 hits become **91 title groups**, of which eight are One Piece in different scripts (`海賊王`, `海贼王`, `ون بيس`, `Ўан Піс`, `वन पीस`, `ワンピース`, ...) plus the renamed pairs it fixes: Gakuen + Academy, Koisuru + in Love. And it does **not** over-merge: `Fischer's×ONE PIECE`, `Cursed One Piece` and the doujinshi keep their own ids. |
| **Settings resolve in five layers** | defaults, profile, **home shelf**, series, session. The shelf sits below the series on purpose: a shelf says how a group reads, and a series that disagrees is the more specific statement. A series on several shelves resolves through `default_category_id`, because merging arbitrary shelves in arbitrary order is not deterministic. |
| **Suwayomi cannot store a cover** | `UpdateMangaPatchInput` has **exactly one field**, `inLibrary`. So H5's custom covers are a Laravel file plus a route, which matches the tenancy model anyway. |
| **There is no local source upload API** | Every GET on `/api/v1/...` returns the **4,484-byte `text/html` WebUI catch-all** and every other method a 404. But a file dropped into `Tachidesk/local/` **is** discovered: a real three page CBZ with ComicInfo.xml was found as manga 2899 and its chapter list fetched. So H15 is a file drop, not a source written in PHP. Local source is **id `0`**, lang `localsourcelang`. |
| No delete mutation | There is no way to remove a manga from Suwayomi. A probe series stays in its database, the same shape of limitation as the missing migration primitive. |
| `sources { id }` is invalid | `SourceNodeList` has **no `id` field** in v2.3.2243. And there is no `searchManga` on the root `Query`: searching a source is the `fetchSourceManga` **mutation**, which is what the app's own searcher uses. |
| **A re-read costs what the first read cost** | Ratio **0.95x**: min 361ms, median 876ms, max 1617ms across 8 series. Suwayomi does **not** cache, so every check is a real upstream request to one of 82 sites. This is the whole reason a floor exists, and it is why AniList's count was rejected as a substitute. |
| AniList's count as a chapter-count substitute | **Rejected on measurement.** 4 of 6 sampled series had **no AniList entry at all** (ongoing Korean webtoons, which its `MANGA` type covers thinly), and both that did **over-count**, by +9 and +17. It counts the original release including chapters no scanlation has. A reader shown "57 unread" who opens 48 has been told something false. |
| Live library check | 13 of 14 series, **14.3s, 1099ms a series**. 12 with chapters, 1 `no_chapters`, 0 failed. All 14 then showed a real unread count. 13 of 14 carried a fingerprint where **0 did before**. Zero fingerprints disagreed with a batch read, which is what would mark the whole library stale at once. |
| A source with no chapters is an answer | MangaDex returned `No chapters found` for One Piece (id 174), which is `initialized: true, totalCount: 0`. The check records `no_chapters` and **moves the clock**: it is a successful answer, and leaving the timestamp alone made the series due forever, so every press re-asked a source that had already said no. No fingerprint is written, since there is no list to describe. |
| **WebP/AVIF is a non-finding** | H16 claimed "one server setting, real bandwidth win". **The setting exists and setting it changes nothing.** Correcting an earlier note here: `serveConversions` *is* on `PartialSettingsType`, `PartialSettingsTypeInput$`, `Settings` and `SettingsType` in the committed v2.4.2366 snapshot, and `Mutation.setSettings` takes it, so it is writable. The finding that stands is the one measured rather than inferred: with it off, all three of `webp`, `avif` and `jpg` come back byte-identical `image/webp`, so there is no bandwidth win to be had either way. A conclusion reached for the wrong reason is still worth correcting, because the next reader would otherwise assume it had been tested. `?format=webp`, `?format=avif` and no format at all return **byte-identical `image/webp`, 679KB a page**. Suwayomi transcodes already and the reader already asks for webp (`ImageUrl::defaultFormat()`, default `webp`). **Nothing to build and nothing to gain.** |
| **Measuring image bytes needs the body, not Content-Length** | A chunked response carries no length header, and `CURLINFO_CONTENT_LENGTH_DOWNLOAD` reported 0, so the first attempt measured `-0.0 KB` for every format. Strip the header block and `strlen()` what is left. |
| Page routes need a bearer, the proxy needs a session | `api/v1/.../page/N` is `@RequireAuth`, so a bare curl gets **401**. The app's own proxy route sits behind `auth` middleware, so the same curl gets a **302 to the login page**. Measuring image bytes means asking the server directly with `AuthManager::token()`; the proxy is a passthrough with a path allowlist, so the server's bytes are the reader's bytes. |
| **Chapter ids are not derivable from the manga id** | Manga 2's chapters are **720, 721, 722**, not `mangaId * 100 + n` (201, 202, 203). A live check that guessed them marked chapters which do not exist, the resolver correctly ignored them, and the output claimed the read set had no effect. **Always read ids out of a batch response** rather than deriving them. The unit tests missed this because their fake mints ids the way the arithmetic assumes. |
| The continue-reading feed is free | One local join, **1ms** for 13 series, over the batch the library page already makes (**257 to 270ms** for 14 series, 575 chapter nodes). The alternative, one `fetchMangaAndChapters` per series, is **3.5 to 4.4 seconds**. Measured both ways. |
| **The read set is not what moves the feed; the pointer is, and it is consulted first** | Measured: **13 of 14** dev series have a `last_read_chapter_id`, and reading chapters 1 to 3 of one correctly changed nothing because the pointer at chapter 14 took precedence. The pointer is only honoured when the chapter after it exists and is itself unread. Checking the pointer *after* the read-set walk makes it unreachable, which is the whole failure it exists to prevent. |
| Chapter labels are not derivable from numbers | Two sources label one chapter `Chapter 1` and `Ch. 1`. Real labels measured: `Chapter 1: History's Strongest and Clumsiest hero is Born!`, so a position alone loses information the reader will actually see. |
| Flux 2 component surface | `Flux::error()` and `flux:section` **do not exist**. `FluxManager` exposes boot, scripts, classes and asset helpers only. Report failures with a `danger` toast, which the app already does in ten places. |
| **A GraphQL comment inside a heredoc document is rejected** | `/* ... */` is valid GraphQL and the server's ANTLR parser is not: it comes back as `Invalid syntax with ANTLR error 'token recognition error at: /'`. Put the explanation in the PHP around the heredoc. |
| `chapter_states.read` does not exist | The column is **`is_read`**, mirroring Suwayomi's own `isRead`. A query naming `read` returns every row with the column null and **no error**, so a feature that depends on it silently sees nothing read. |
| **The page endpoint addresses chapters by source order, not by id** | Measured on v2.4.2366: `fetchChapterPages` for chapter 3178 (`sourceOrder` 2) returns `/api/v1/manga/116/chapter/2/page/0`, and the id-shaped URL answers 404 `text/plain`. `fetchChapterPages` itself takes the id; only the REST page path is positional. Pass the server's own paths through `Gateway::resolvePageUrls()` rather than rebuilding them from ids, which is what `ReaderController` does now. |
| **`Collection::push` inside a getter mutates the thing it reads** | `DedupeGroup::strictestContentWarning()` pushed the primary row onto `alternatives`, so the group grew by one on every call and any count read after it was wrong by that much. It shipped for months because the one caller read the count before calling it. The fix is `concat`, which returns a new collection. |
| `UpdateExtensionPatchInput` carries three booleans | `install`, `uninstall` and `update`, verified in the committed v2.4.2366 snapshot. `update: true` pulls the store's current artefact over an installed extension. `ExtensionManager::update()` shipped unused for months: the page drew the "update" badge and no control anywhere acted on it. |
| **AniList's OAuth issues no refresh token** | The access token lives about 365 days and that is the whole credential. A rejected token cannot be renewed, so the account row is deleted and the reader connects again. The token endpoint takes a form post and both `expires_in` (seconds) and `expires` (epoch) have been seen in responses, so both are read. |
| **Source thumbnails fail transiently** | Three of eighteen cover requests answered 500 on one page load and 200 on the next, upstream of anything we control. For the login showcase this makes every fetch path into one fact: a cover that cannot be streamed is a 404, whatever was thrown, because an error page in the reader's console for a slot the page hides anyway is noise with no reader in it. |
| **Suwayomi NPEs on an unregistered source** | `fetchSourceManga` for a source id whose extension is gone answers a bare `java.lang.NullPointerException at SourceMutation.kt:264` (46 occurrences in one log, all the same shape), and `source(id:)` answers a GraphQL field error about its non-null `SourceType` resolving null. Neither names the real problem. The registry query (`sources { nodes { id } }`) is the honest check, and the browse page asks it on the failure path only. |
| **Extension JARs can vanish while the mirror insists they are installed** | Measured on this box: 6 of 18 extension packages on disk against 84 sources in the mirror, the survivors all stamped Oct 1 and the cause unrecoverable from any log. Suwayomi came up with 73 of 84 sources registered, browse of any missing one died in the NPE above, and the mirror drift notice was the only signal pointing at it. The repair was `ExtensionManager::installMany()` over the mirror's own package list: 11 installs, zero failures, registry back to 84 of 84. A fresh Suwayomi start after a crash is the moment to re-diff `sources { nodes { id } }` against the mirror rather than trusting either side. |

---

## 4. Architecture decisions

| # | Decision | Why |
|---|---|---|
| AD-1 | Laravel owns 100% of user state; Suwayomi is a source engine only | Suwayomi is single tenant (every request resolves to `Admin(1)`) and it *owns* read state, which collides with multi user |
| AD-2 | Every user table has `user_id` from migration 1 | Retrofitting tenancy later is a rewrite. `single` is one user with registration off, never a different schema. |
| AD-3 | Page images stream through a Laravel route | Herd rewrites *everything* to `valet/server.php`. There is no per site server block to add a `location` to, and PHP is already in the path. |
| AD-4 | Poll, do not subscribe | GraphQL subscriptions use Apollo framing and the server's own docs admit it goes unresponsive under load. Polling also produces the health ledger for free. |
| AD-5 | Side tables for everything Suwayomi cannot store | `UpdateMangaPatchInput` exposes only `inLibrary`. No notes, no covers, no reader flags, no writable scanlator. |
| AD-6 | Never reimplement what Suwayomi does | It already has scraping, extension loading, and six tracker integrations server side. |
| AD-7 | Win vertical and webtoon; do not fight paged | WebUI's paged reader is about 110 files and years of work. Its own issues say vertical is where it struggles. |

---

## 5. Traps

Each of these cost real time. Do not repeat them.

1. **Never guess a GraphQL field name.** Read the committed snapshot
   (`php artisan suwayomi:schema:dump`) or introspect live. Two schema shapes have already
   differed from the research reconstruction.
2. **`fetchSourceManga` is a mutation.** Writing `query` fails validation.
3. **64 bit ids arrive as JSON strings.** `Timestamps` handles epoch seconds versus
   milliseconds. `SyncStatus` uses ms, everything else uses seconds.
4. **`LongString` ids exceed 2^53.** They have to stay server side.
5. **`hasNextPage` is unreliable** for non `ID` orderings. Paginate on `ID` plus `offset`
   and trust `totalCount`.
6. **`pageCount` is `-1`** until chapters are fetched.
7. **`DownloadType` has no `chapterId` or `mangaId`.** Resolve `download { chapter { id } }`.
8. **CBZ export builds the whole zip in memory** (#2353). Size guard it.
9. **Suwayomi's CORS reflects any origin with credentials**, and `AuthMode.NONE` hands
   `Admin(1)` to everyone. Loopback only, always.
10. **Herd routes everything through PHP.** Do not plan an nginx level reverse proxy.
11. **`flux:checkbox.group` is a wrapper, not an item.** The individual checkboxes nest
    inside it: `<flux:checkbox.group wire:model="x" variant="pills">` wrapping
    `<flux:checkbox value="..." label="..." />`. Repeating the group per option renders
    bare label text with no control, and raises no error.
12. **A stale `public/build` silently breaks styling.** With no `public/hot`, Laravel serves
    the last production bundle, so newly written Tailwind classes are simply not in the CSS.
    The symptom is correct HTML with unstyled elements, and `display: block` on something
    that should be `display: flex`. Check `public/hot` before debugging the template, and
    run `npm run dev`.
13. **Verify the rendered HTML, not just the model layer.** A search can populate rows
    correctly and still explode inside Blade. `assertSee` and `assertDontSee` on rendered
    output catch what model assertions cannot, such as proving covers are proxied
    (`assertDontSee('upstream.test')`) rather than hotlinked.
14. **An expired token is HTTP 200, not 401.** `@RequireAuth` throws inside the resolver and
    graphql-java reports it as a field error, so a `status() === 401` check never fires and
    nothing re-authenticates. `Gateway::rejectedAuth()` matches the Kotlin class name
    `suwayomi.tachidesk.server.user.UnauthorizedException` for this reason. Matching the bare
    word "Unauthorized" would false-positive on a source whose own error mentions a 401.
15. **Read the token's `exp` claim, never a configured TTL.** The access token lives 300
    seconds. `token_ttl` was set to 3000, never measured, so the app spent 45 minutes of every
    50 handing out a dead token, and trap 14 stopped anything noticing. `Jwt` decodes the
    payload and returns null when it cannot, which forces the caller to renew instead of guess.
16. **The refresh token is not rotated.** Measured: refreshing twice with the same refresh
    token works. So keep it for 60 days and renew with it, rather than re-sending credentials
    every 5 minutes.
17. **Re-check the cache *inside* the lock.** Processes read the cache before they queue, so
    without a second read every one of them renews. On a 300 second token that is a login
    stampede every 5 minutes across a fan-out.
18. **`condition` is not `filter`.** `ExtensionConditionInput` takes flat scalars only, so it
    cannot express "content in SAFE or MIXED" or a case insensitive name match.
19. **Two diagnostic traps of my own.** Printing `date('H:i:s', $exp)` for a 60 day token looks
    identical to a 5 minute one, so show the date. And `AuthManager` memoises the pair in
    memory, so a script that seeds the cache between scenarios must reset the instance or it
    measures the previous scenario's token.
20. **This Flux build ships no tabs.** No `flux:tabs`, no `flux:tab`. Use `flux:button.group`
    wrapping `flux:button` with `:variant` switched per item.
21. **`@disabled(...)` corrupts a component tag.** The directive is for plain HTML elements; on
    `<flux:button>` it compiles to invalid PHP (`unexpected token ":"`) and the page dies with a
    parse error rather than an attribute error. Use `:disabled="$condition"`.
22. **`Flux::toast()` is positional, not fluent.** `Flux::toast($text, $heading, $duration,
    $variant)`. There is no `->title()->body()->send()` chain and no `Flux::error()`.
23. **A second `Http::fake()` does not replace the first.** Laravel keeps the earliest matching
    stub, so re-faking inside a test silently serves the original data. Have the stub read from
    a property instead.
24. **`[] > 0` is true in PHP 8.** An array always compares greater than a non-array, so
    `$rows > 0 ? $rows : $default` picks the empty array. Use `$rows !== []`.
25. **A method named `component()` on a test class is a fatal error.** `TestCase` already has a
    protected `component()`.
26. **`@js()` does not compile inside a component tag's attribute.** Neither does `@disabled()`
    (trap 21). `{{ }}` and `{{ Js::from($x) }}` do. Written raw, `@js()` reaches the browser as
    text and Alpine throws `Invalid or unexpected token` on click. **Livewire's test harness never
    runs Alpine**, so assert on the rendered HTML: no `wire:x="...@foo("` may appear.
27. **`#[Url(as: 'content', except: '')]` on an array property eats the default on a deep link.**
    Loading `?view=available` with no `content` parameter hydrates the array as empty rather than
    keeping the declared default, so the NSFW filter silently did nothing and the tab reported
    1,364 extensions instead of the 999 that SAFE and MIXED match. Apply array defaults in
    `mount()`, never only on the property.
28. **Check the rendered page in a browser before calling a page done.** The three bugs above all
    passed 194 tests. The Alpine error and the wrong extension count were both visible on the
    first click.
29. **`Query\Builder::select()` RESETS the column list before adding.** So a `->select(DB::raw(...))`
    placed after your `->selectRaw(...)` calls silently discards every one of them, and the query
    comes back with three columns instead of twelve, with no error anywhere. Use `addSelect()`.
30. **Filter on a SELECT alias in the OUTER query, never the inner.** `where('state', ...)` against
    a grouped rollup compiles to a reference to the alias, which is not a legal filter. SQLite
    reports that as `misuse of aggregate: sum()`, which sends you hunting through the aggregates
    instead of at the `where`.
31. **Paginating a grouped rollup needs a derived table.** Wrap it in `fromSub()` first, or
    Laravel's row count wraps the select in a scalar `count(*)` and the aggregates inside get
    rejected. That also moves the computed columns onto the outer query as plain columns, which
    is where the filters belong anyway.
32. **`whereIn(...)->update()` writes nothing when no row exists yet.** A per-user preference
    table is empty for anyone who has never touched that preference, so a mass update matches
    zero rows while the interface reports a change that did not happen. Use `upsert()`.
33. **PowerShell `[System.IO.File]` calls ignore the PowerShell working directory.** They resolve
    against the process CWD, so `ReadAllText('.env')` after `Set-Location` fails with "file not
    found" even though the file is right there. Pass absolute paths.
34. **Do not bulk-edit source files with PowerShell string slicing.** Cutting a line range out of
    a PHP file that way removed a live method and an import, which PHPStan could not see because
    the result still parsed. Edit methods one at a time and let the gates confirm.
35. **`#[Url(as: ..., except: '')]` on an array property discards the declared default.** A deep
    link with no `content` parameter hydrates the array as empty, so the NSFW filter silently did
    nothing and the tab reported 1,364 extensions instead of the 999 that SAFE and MIXED match.
    Apply array defaults in `mount()`.
36. **Mixed timestamp units inside one payload.** `ChapterType.uploadDate` is milliseconds and
    `ChapterType.fetchedAt` is seconds, side by side, and the schema types both as `LongString`.
    Read as seconds, `uploadDate` put all 41 chapters of a real series in the year 57970.
    `Timestamps::detectUnit()` separates them by magnitude, past 1e11.
37. **A CLI script reading a user-owned table sees nothing.** The tenant scope fails closed with
    no auth context, so a diagnostic reports "no runs recorded" when four exist. Log in as a user
    first. This is the scope working, and it reads exactly like data loss.
38. **A measurement that produces an impossible number is a broken script.** A set-cover run
    reported 729% coverage because the running total merged duplicates instead of
    intersecting them. Find the arithmetic before you believe the data.
39. **Redundancy observed in one query is not redundancy in general.** 36 MangaDex variants
    returned identical titles for "one piece", which looked like 36 wasted requests. They were
    the Latin-script copies of manga the other 25 variants hold in other scripts. Measure what
    is being discarded before discarding it.
40. **Livewire mount() arguments come from the route parameter name.** A component declaring
    `mount(int $mangaId)` behind `series/{manga}` makes the container try to resolve an int with
    no default, and the page 500s. Name the parameter after the route segment.
41. **`Livewire::test()` builds a new component per call.** State set on one instance is
    invisible to the next, so a test asserting after `->set()` must hold the Testable in a
    property rather than calling `Livewire::test()` again.
42. **Check `public/hot` before blaming a template.** A dead Vite server leaves `public/hot`
    pointing at nothing and every page renders unstyled, which reads as a broken layout. It cost a
    screenshot that looked like an entirely unstyled page, and a hunt through the image proxy
    before the cause turned out to be a process that had died.
43. **A proxy diagnostic that bypasses the session lies about auth.** `setUserResolver()` on a
    request makes `auth()->user()` return a user, but the session guard still has no session, so
    the auth middleware redirects and the harness reports a broken proxy. Suwayomi was serving
    198KB of webp the whole time. Exercise the proxy through the kernel with a real login, or
    assert against the direct upstream call.
44. **`resources/js/app.js` is empty, so nothing from the app's own entry point reaches a page
    that does not use Livewire.** Alpine does still load, because `partials.head` pulls in Flux
    and Flux pulls in Livewire, so this is not an Alpine trap. But the reader was written in
    Alpine directives that never took effect, and nothing about the rendered HTML looks wrong.
    A directive that quietly does nothing is the failure mode; assert the absence of `x-data`
    and friends, not the presence of the controls.
45. **The in-app browser panel produces no frames, so it delivers no rAF callbacks, no
    IntersectionObserver callbacks, and no scroll events.** Measured: `scrollY` moved and the
    pages rendered perfectly while all three were dead. Anything driven by the rendering
    lifecycle cannot be verified there. `browser.inspect` also reports only the first class of
    an element, so it cannot be used to check a class toggle either. Verify with Playwright
    (`..\.tools/suwayomi/verify-reader.py`), which produces frames and does deliver scroll events.
46. **A stalled eager image means `window.load` never fires.** Gating the reader's start on load
    left the pages rendering and the counter, the progress posts and the keyboard all dead,
    because Asura held one page open. The DOM is parsed by the time an inline script at the end
    of body runs, so measure immediately.
47. **`var` beats a function declaration when they share a name.** `var track =
    document.getElementById(...)` next to `function track() {}` in the same scope leaves `track`
    as the element: the declaration's hoisted assignment is overwritten when the `var` runs.
    `typeof track` printed `object`. Name the DOM node `pagedTrack` and the function `trackPage`.
48. **`content-visibility: auto` was never the problem, and proving that took a while.** It was
    the obvious suspect for a dead IntersectionObserver. Removing it changed the geometry and
    made the real cause look like it might have been fixed. Never change two things when
    diagnosing one; the title-probe technique below settles these questions in one round trip.
49. **A `<button>` fires `click` and no `change`.** The settings panel listened for `change` on
    the container, which meant every range and checkbox worked and every button, the mode switch
    and the background swatches among them, did nothing at all with no error. The controls do not
    share an event, so the wiring routes both to the same handler.
50. **`fill()` on a range input is not a user gesture.** Playwright sets the value and fires the
    events, but it does not drag a thumb, so a check that passes this way has not proved the
    control is reachable by a finger. Good enough for behaviour, not for layout.
51. **A `display: none` container reports width 0, so a check against it is meaningless.** The
    zoom check read `0 -> 0` in paged mode and read as a reader bug. Measure the container that
    is actually laid out.
52. **A `#[Computed]` method is read as a property, and calling it as a method skips the
    cache.** `$this->entries()` runs the body again; `$this->entries` memoises for the request.
    The library page called its computed methods, and every card asking `isFiled()` once per
    shelf re-ran a Suwayomi round trip: a fourteen series library took 30 seconds and timed
    out. A fake cannot see this, because a fake answers in microseconds. Only counting how
    many times the batch query went out catches it, which is what
    `LibraryTest::test_the_page_makes_one_batch_call_however_many_cards_it_renders` is for.
53. **Two computed properties that read each other are a cycle, and it presents as an
    undefined property.** `filings()` read `matching()`, and `matching()` needed `filings` to
    filter by shelf. Livewire resolves computed properties re-entrantly, so the inner `filings`
    never produces a value and PHP reports it as undefined. Renaming `filtered` to `matching`
    changed nothing, which is exactly what made it look like a name collision. Break the
    cycle: the lower one reads the unfiltered data.
54. **`auth()` with no arguments returns the AuthManager, so `auth()->id` is undefined and
    `auth()->id()` is the call.** It throws "Undefined property" just like trap 53, which is a
    coincidence worth knowing about.
55. **Blade's `@disabled` directive does not compile inside a component tag.** It emits
    `<?php if: echo 'disabled'; endif; ?>` and the compiled view is a parse error. Use the
    bound attribute, `:disabled="$page <= 1"`, which is what the rest of the codebase does.
56. **A Flux component's surface colour is tuned for a page background, not for artwork.**
    Measured: `flux:badge color="zinc"` compiles to `oklab(0.715 / 0.15)` with zinc-800 text,
    which over a dark cover is 15% grey on near-black and invisible. A control that floats over
    a cover needs a background chosen for that, so the unread count is a plain span at
    `bg-zinc-950/85`. Separately, `flux:badge` takes a colour name (`zinc`, `red`, `amber`),
    not `primary` or `accent`, neither of which exist in its map.
57. **Vite's dev server dies with a 4GB heap after a long session, and an unstyled page is
    the only symptom.** A dead server leaves `public/hot` pointing at nothing, Tailwind never
    compiles, and the page renders as one enormous stretched column with no error anywhere.
    Restart it with `set NODE_OPTIONS=--max-old-space-size=6144 && npm run dev`, and look for
    absurd card widths in a screenshot before blaming the template.
58. **`papyrus/` is a git repository even though the workspace root is not.** The environment
    reports the workspace as not a repo, so it reads as though there is no safety net. There is
    one. I deleted a whole directory holding the starter kit's
    `app/Livewire/Actions/Logout.php`, and `git checkout --` restored it. Before removing a
    directory, ask what is in it; `git ls-files` answers.
59. **Livewire's single-file parser injects `view($data)` at the LAST `};` in the class body.**
    `Parser::injectViewMethod` matches `/}(\s*);/`, takes the last match, and replaces one
    character there. A class body that ends up with a stray `};` after the closing brace gets
    the method spliced in outside the class, and the compiled view dies with "unexpected
    token protected, expecting end of file". It bit me twice from the same cause: once from a
    `match` expression, which ends in `};`, and once from an off-by-one when I inserted the
    closing tag and left the class closed twice. Read the generated file in
    `storage/framework/views/livewire/classes/` rather than guessing which `};` it found.
60. **A single-file component must not declare its own `render()`.** The parser injects
    `protected function view($data = [])`, and with a `render()` present the page asked for a
    view named `downloads` instead of `pages::downloads` and threw "View not found". Livewire
    infers the view from the file name, which is why every other page here omits it.
61. **The closing `?>` is load-bearing.** `SingleFileParser::extractClassPortion` matches
    `/<\?php\s*.*?\s*\?>/s`. Without the closing tag the match fails and you get "Class
    contents not found". The match is non-greedy, so exactly one `?>` may appear in the PHP
    block, and it must be the last thing in it. `artisan view:cache` passes either way, because
    Blade does not care. Only Livewire does.
62. **`trans_choice` takes a pipe-separated key and the number second.** The signature is
    `trans_choice($key, $number, array $replace, $locale)`. Writing the
    `Translator::choice` shape, singular then plural then the count, puts an int where the array
    belongs and throws "Argument #3 ($replace) must be of type array, int given".
63. **A `#[Computed]` is cached on the Livewire instance, and a direct database write does not
    invalidate it.** Marking a chapter read through the model leaves the component's
    `chapterStates` computed holding the pre-write value, so a test that marks read and then
    re-reads the same instance is measuring the cache. Ask a fresh component, which is also
    what a reader gets on the next load.
64. **`$request->data()` returns `stdClass` for nested variables.** In an `Http::fake` closure,
    `$request->data()['variables']['input']['id']` throws "Cannot use object of type stdClass as
    array". Cast at each hop: `(array) ($request->data()['variables'] ?? [])`, then
    `(array) ($variables['input'] ?? [])`.
65. **`Schedule::job()->everyTenSeconds()` needs `schedule:work` running.** Measured in dev:
    `queue:work` was running and `schedule:work` was not, so the ten-second schedule never
    fired, nothing dispatched the reconciler, and the ledger sat still while the page polled
    its own frozen rows. A feature that depends on a poll must not depend on a second
    long-running process. The job now books its own next pass through the queue, and the
    schedule is the safety net for after a restart.
66. **A self-re-dispatching job loops forever under the sync queue driver.** The test suite
    runs `QUEUE_CONNECTION=sync`, so a dispatch is not deferred, it runs inline: the pass
    re-books itself, the re-book re-enters, and a download the server never settles exhausts
    128MB in about a second inside Gateway's server-error branch. Guard with a depth counter
    held in a `static` on the job, not a constructor argument: carried on the job it would
    survive into the next tick of a real chain and cut the chain short after a few passes. A
    test that asserts an answer will not catch this one, so there is a test that asserts
    memory use instead.
67. **Pint restores LF for PHP files but does not touch `.blade.php`, and
    `[System.IO.File]::WriteAllLines` writes CRLF on Windows.** The repo is LF-only
    (`.gitattributes`: `* text=auto eol=lf`). A Blade file edited that way comes out entirely
    CRLF, which breaks every later multi-line `.Replace()` against it. Pint hides this for
    `.php` and leaves it visible in Blade. Check with a byte scan, not by eye.
68. **`?->` is for object method calls, not for invoking a nullable callable.** A
    progress callback written `$onProgress?.($n, $total)` is a parse error at the `?.`,
    and PHP points at the `.` rather than at the intent. Bind a guarded closure once
    instead: the callback runs per source, which is 82 times for a full sweep.
69. **A Flux component's `size` and `variant` take a sealed set of values, and an
    unhandled one throws from inside the component.** `size="default"` does not exist;
    the values are `base`, `sm`, `xs`, `lg`. The error is "Unhandled match case
    'default'", raised in a compiled vendor view, which reads like a Blade problem and
    is not one.
70. **A queued job carries `failOnTimeout: false` by default, so a job that exceeds
    the worker's `--timeout` is deleted with no record at all.** Measured: four
    consecutive live runs of the migration search each left a row saying `searching`
    and nothing in `jobs` or `failed_jobs` to show a job had ever started. Set
    `public bool $failOnTimeout = true;` on any job whose runtime is uncertain, and
    remember the worker's own `--timeout` is what actually kills it regardless of what
    the job asks for.
71. **A closure that captures its own throttle timestamp by value never throttles.**
    `use ($lastWrite)` captured the moment the sweep began and never moved, so the
    comparison was always against the start and the closure wrote **at most once**. The
    row therefore sat at its initial value for the whole run while the page polled it.
    It needs `use (&$lastWrite)`.
72. **A probe that prints "dispatched" without calling `dispatch()` is
    indistinguishable from a broken worker.** This one cost three rounds of worker
    forensics: the row never moved, the queue was empty, the worker's log was empty,
    and every reading pointed at the worker. The script had never dispatched anything.
    **Assert that the queue depth went up immediately after dispatching, and stop the
    probe there if it did not.** One assertion turns a three-hour mystery into an
    instant one.
73. **A closure variable needs a variable to be passed by reference, so `end(file($p))`
    is a fatal rather than a call.** And a `use` statement after executable code is a
    parse error, which is what a here-string edit tends to produce.
74. **A map built in the wrong direction is still a valid-looking map.** The migration
    undo script reported "41 of 41 chapters map back" and then moved nothing, because
    it mapped the restored series' chapters onto the moved-away ones. Only the browser
    gate's restore step caught it, which is the argument for gates that mutate and then
    put things back rather than asserting nothing.
75. **Blade only strips `{{-- --}}`. The spaced `{{ -- -- }}` form compiles to an
    echo.** The comment text lands in the output as PHP and the view fails with
    "syntax error, unexpected identifier", naming a word from the comment rather than
    the construct that is wrong.
76. **An Eloquent model name is snake-cased, and `AniListMedia` becomes
    `ani_list_media`.** The migration said `anilist_media`, so every write failed at
    runtime with "no such table" rather than at boot. Pin `protected $table` whenever a
    class name has an internal capital that snake case breaks on.
77. **A CSS attribute selector cannot hold an unescaped colon, so
    `[wire:model*="x"]` is a syntax error in the browser.** Quoting the value does not
    help. Locate by accessible name instead, which is what a screen reader offers anyway.
78. **`foreach ($map as $id)` on an id-keyed map gives `true`, not the id.** In a
    `Http::fake` that builds a batch response from `$libraryIds[$mangaId] = true`, the
    loop then emits a series numbered `1` for everything asked for, so every assertion
    about the response silently reads the wrong series. `array_keys()` first.
79. **`Flux::error()` does not exist in Flux 2.** `FluxManager` has no such method and
    the call raises "Call to undefined method Flux\FluxManager::error()". The app's
    convention is a `danger` toast. The one pre-existing `Flux::error` was on a catch
    branch no test reached, which is why it survived.
80. **`flux:section` does not exist in Flux 2 either.** A view that uses it compiles
    under `view:cache` only if the branch is unrendered at build time, so a page that
    renders it in a rarely-taken branch can pass every test and fail the cache.
81. **`updateOrCreate()` on an `OwnedModel` raises `MissingOwnerException`.** `user_id`
    is deliberately not mass-assignable, so a queued job with no authenticated user
    finds no owner to fall back to. Use `createFor($userId, $attrs)` and update the
    existing row by hand.
82. **`$request->data()` decodes JSON into nested `stdClass`, not arrays.** So
    `$request->data()['variables']['id']` raises "Cannot use object of type stdClass as
    array". `data_get($request->data(), 'variables.id')` is the form that works.
83. **Chapter ids are not derivable from a manga id.** Manga 2's chapters are 720, 721,
    722, not `mangaId * 100 + n`. A live check that guessed them marked chapters that do
    not exist, the code correctly ignored them, and the output claimed the feature was
    inert. Read ids out of a batch response. Unit tests miss this when their fake mints
    ids the way the arithmetic assumes.
84. **`chapter_states.read` does not exist; the column is `is_read`.** A query naming
    `read` returns every row with the column null and no error, so a feature depending
    on it silently sees nothing read.
85. **A `/* ... */` comment inside a GraphQL heredoc is rejected** with `Invalid syntax
    with ANTLR error 'token recognition error at: /'`. It is valid GraphQL and the
    server's parser is not. Put the explanation in the PHP around the heredoc.
86. **A new `ul.grid` on a page breaks every gate that counts `ul.grid > li`.** The
    continue-reading feed is a list of the same shape as the library grid, so an
    unscoped selector summed the two and a shelf check picked an index from the wrong
    list. Scope selectors to the specific grid.
87. **A gate that hardcodes a count breaks when the data changes.** The continue-reading
    gate asserted `of 5, 3 read` against a series the seeder picked, which had 6
    chapters. Have the seeder report the numbers and parse them.
88. **`{{ json_encode($x) }}` inside a `<script>` produces `&quot;` and kills the whole
    script.** Blade HTML-escapes the echo, so `mode: "vertical"` arrives as
    `mode: &quot;vertical&quot;` and the browser reports `Unexpected token '&'`. The reader's
    IIFE then throws at parse time, so **every statement after it never runs**: the
    counter froze at 1, the mode would not switch and taps did nothing, with no error
    naming the reader. Use `'{{ $x }}'` for a JS string. Found by `node --check` on the
    script pulled out of the rendered page, which is the only way to get a line number
    for a browser parse error.
89. **The reader had no feature test at all**, only a live browser gate, so a 502 from
    a unit test rendering it looked like the app was broken rather than the fixture
    being missing. The fixture needs a chapter with a real `pageCount`, because
    measured `ChapterType.pageCount` is `-1` until pages are fetched.
90. **`pageCount` is `-1` for 98% of a library.** Measured, **12 of 575 chapters** carry
    a real one, because it is populated only once a chapter's pages have been fetched.
    So any feature needing a per-chapter fraction has no denominator for almost
    everything, and the first measurement of this was wrong in my favour: the probe
    never asked for `pageCount`, so `(int) (null ?? -1)` produced -1 for every chapter
    and reported 0% when the truth is 2.1%. **Ask for the field before concluding it is
    absent.**
91. **An `UPDATE` against a `chapter_states` row that does not exist affects nothing
    and reports nothing.** A test helper that set `last_page_read` on a chapter the
    reader had never opened recorded nothing, and the state came back `unknown`, which
    looked like a navigator bug. Go through `ReadingState::recordProgress()`, the app's
    own path. And note that `recordProgress` **recomputes `is_read` from the page against
    the total**, so passing a total un-marks a finished chapter at page 3 of 20. That is
    correct: the app cannot know the reader finished if the page says otherwise.
92. **A full-width `position: fixed` bar at the bottom covers the reader's corner
    controls.** The mode switch, the settings button and the back link are already fixed
    at `bottom: 1rem; z-index: 20`, so a new bar on the same edge hides them, and the
    symptom is "the mode button does nothing" rather than "something is on top of it".
    Offset the controls by a CSS custom property the bar sets, and hold the bar's legend
    to one line so the offset is a fact rather than a function of text wrapping.
93. **Four Playwright behaviours that each cost a round of gate debugging.**
    `wait_for_selector` defaults to `state="visible"`, and a `loading="lazy"` image far
    down a chapter is never visible, so the wait hangs and the gate concludes the page
    has no images at all. `page.goto` **raises** on a non-2xx, and a chapter the source
    has nothing for is a 502 by design. An absolute `href` prefixed with the base URL
    becomes `http://papyrus.testhttp://papyrus.test/...` and fails with
    `ERR_NAME_NOT_RESOLVED`, which reads as "the chapter has no pages". And a locator is
    bound to a page, so reading attributes on the second loop iteration reads a document
    that no longer exists: **collect every href before navigating**. `fetchChapterPages`
    is a measured 2.0s, so a fixed 1200ms wait is inside it; wait for the selector.
94. **A card cannot offer a shelf it is already in, so the candidate cannot exist inside
    a shelf filter.** The library gate looked for an "Add to a shelf" button while the
    grid was filtered to a shelf, and measured only 12 of 14 series are on both shelves,
    so every visible series was already filed there and the check failed with "0 with a
    shelf to add". The page was correct. Look for the candidate on the unfiltered grid.
    Two attempts to clear the filter by clicking an "All" chip also failed: Flux puts a
    count span inside the button, so the accessible name is not the label. **Reloading
    resets the Livewire property** and does not depend on the chip bar's markup.
95. **A custom property declared on one element is invisible to its siblings.** The
    navigator's height was declared on `.reader-nav` and read by the three corner
    controls to lift them clear of it. Custom properties inherit *downwards*, and the
    controls are siblings, so they fell back to `0px` and sat exactly where the navigator
    was: measured, the legend at x 1323 to 1413 with the mode switch and the back link on
    top of it. Declare it on `body`.
96. **Equal z-index loses on document order, so "is visible" is not "can be seen".** The
    note panel and the chapter navigator were both `z-index: 30` and the navigator came
    later, so the panel rendered underneath it. Every assertion passed: the panel reported
    itself visible, and the reader saw nothing. **Found by reading a screenshot**, which
    no assertion had done. Three separate overlaps then turned up in one panel, so the
    gate now asks geometric questions of boxes: is the open panel the topmost thing at
    its own centre, and is anything the reader must press inside it under a corner
    control. The layering is now deliberate: 30 navigator, 40 unused, 50 panel, 60
    corners, because a control that closes a surface cannot live under that surface.
97. **`hidden` does nothing when a stylesheet sets `display`.** The note counter was
    shown and hidden with the `hidden` attribute, and `[hidden]` only works through the
    user-agent stylesheet, which any author `display` beats. It sat showing "0" in an
    empty box. Needs `.thing[hidden] { display: none; }`.
98. **Laravel's `string` rule rejects an empty string.** `trim('') === ''` counts as not a
    string, so `['present', 'string']` cannot express "clearing this is a legitimate
    request", and the clear came back as a 422 that read as a bug. `nullable` is what
    lets it through, and it works because `ConvertEmptyStringsToNull` turns `''` into
    null before validation.
99. **A Flux tooltip around a button makes the button unclickable.** Playwright reported
    "intercepts pointer events" on a control that was visibly present; for a reader that
    is a button that does nothing. The tooltips elsewhere in that row wrap a
    non-interactive icon, and a tooltip around a control has to be click-through to be
    worth having. A native `title` instead.
100. **A Flux modal renders a native `<dialog>`, so it has no `role="dialog"`.** And it
    will not open from a Livewire **server-side** dispatch reaching
    `$store.flux.modal.show()`: the gate waited thirty seconds on a textarea that stayed
    invisible. The store methods are `show` and `close`, not `open`. An inline expand on a
    chapter id needs none of this and has no flash, because the body never makes a round
    trip between opening and being right.
101. **`PHPStan` level 7 and `view:cache` both pass a `#[On]` attribute with no import.**
    The attribute resolves against the component's own namespace and fails at runtime,
    when Livewire instantiates the class. Check that every attribute used has a matching
    `use`, since nothing else will.
102. **Three gates were not re-runnable, and each failed as though the feature were
    broken.** The library-check gate hardcoded `total = 14` and slept a fixed 18s for a
    pass that chains with a 3s delay between slices. The migrate gate asserted the label
    "Look for another source", which becomes "Search again" once a search exists, and a
    search persists between runs. The library gate looked for a control that cannot exist
    under the filter it had applied. **A gate has to be re-runnable**, so locate controls
    by what they *do* (`wire:click="findSources"`) rather than by a transient label, read
    counts from the page rather than carrying them, and poll rather than sleep.
103. **A malformed JavaScript string in a gate looks exactly like a broken application.**
    A stray backtick in a `wait_for_function` template literal made the injected script
    throw `Unexpected end of input`, which surfaced as a page console error *and* as the
    wait failing instantly, at 0s against a 420s timeout. It cost four runs before the
    0s gave it away. Build the string in Python and hand the page a finished value.
104. **`document.styleSheets` walking reports `false` for a rule that is really there**, so
    it cannot tell "the utility is absent" from "the stylesheet was unreadable". The
    `.size-5` check said the utility was missing while `public/build/assets/*.css` plainly
    contained it, and I spent a measurement chasing the wrong layer. **Grep the built CSS
    file** for a utility question.
105. **If the reader page renders as one enormous logo, check whether Vite is listening.**
    Everything looked broken at once: no shell, no sidebar, `ui-sidebar` computing to
    `display: inline`, a 1424x1495 SVG eating every click. That is not a Tailwind problem,
    it is no stylesheet at all, because the page points at
    `http://[::1]:5173/resources/css/app.css` and nothing answers there. The dev server
    had died. `npm run dev` is `vp dev`, and the log line is `VITE+  v0.3.0`.
106. **A ceiling set above what memory can hold is not a ceiling.** The cover upload's pixel
    guard was written at 40 megapixels, then 20, and both were refused *by PHP* rather than
    by the check, because 40M pixels is four bytes for 160MB and 20M is 80MB against a
    `memory_limit` of 128M that is also carrying the request. It settled at **12
    megapixels**, which is 48MB and leaves room for the decoded original, the resized copy,
    the file and the app. A limit that the runtime rejects anyway is not protecting
    anything. Assert the arithmetic instead of the number: `$limit * 4 < memory_limit / 2`.
107. **A feature test that builds a large image measures PHP, not the code.** A 6 megapixel
    fixture is 24MB decoded, the test holds one while the code makes another, and the suite
    process is already carrying a loaded application: it passed in isolation and killed the
    run in a full suite, which is the worst shape a test can have. Exercise the same path
    with a small image and the bound configured low, and let the arithmetic assertion carry
    the number. `scale < 1.0`, `imagecopyresampled` and the encode do not care whether the
    bound is 800 or 200.
108. **`getimagesizefromstring()` returns an array with string keys**, so `max($info)`
    compares *strings* and returns `'width="500" height="700"'`. Index `[0]` and `[1]` for
    dimensions, and never take the max of the whole array.
109. **`parse_ini_string('memory_limit=128M')` returns the string `'128M'`.** Multiplying it
    gives 1MB, so every assertion about memory passes for the wrong reason. Match the
    shorthand with a regex and scale it.
110. **`Livewire::test()->set()` with an `UploadedFile` reads the file's own properties.**
    A hand-built `new UploadedFile($path, $name, $mime, null, true)` fails inside Livewire
    with `Undefined property: UploadedFile::$name`, which names neither the cover nor the
    page. Use `UploadedFile::fake()->image(...)`.
111. **`imagerotate` returns a new image and does not rotate in place.** Rotating and then
    reading the dimensions from *before* the rotation resizes a portrait to landscape
    proportions, which is exactly the bug a phone photo triggers. Return the image from the
    rotate, destroy the original, and read the width and height after.
112. **`Tailwind` bracket classes cannot be selected as attributes.** `aspect-[2/3]` is a
    class, so `img[aspect-\[2\/3\]]` matches nothing and the gate failed on a timeout with
    no clue. Put a `data-test` attribute on the element, which is what the account
    deletion modal already does.
113. **A route you deliberately cached will defeat your own gate.** The cover route serves
    `private, max-age=300`, so the check that the route 404s after a removal was answered
    from the browser cache with the bytes that were there before. `fetch` with
    `cache: 'no-store'` and a cache-busting query, since the route ignores unknown query
    parameters.
114. **`filters` is on `SourceType`, and `Source` is not in the schema at all.** I concluded
    a source's filter list was unreadable by introspecting `__type(name: "Source")`,
    which has no `filters` field because the type does not exist. The error
    `Field 'isLocal' in type 'SourceType' is undefined` says so directly.
    `SourceFilterTranslator` and `SourceSearcher` were in the codebase the whole time, and
    `SourceSearcher:162` already runs `source(id:) { filters { ... } }` with a `name` on
    every member. **Introspect the type the query actually returns**, not the one whose
    name sounds right. This changed the H6 recommendation from "no query language" to
    "best-effort syntax", so the cost of getting it wrong was a whole wrong decision.
115. **A source's filter *names* are not a vocabulary.** Measured across 14 sources: all
    offer filters, and only **3 offer an author filter**. MangaDex, the largest at 61 site
    variants, has 12 filters and no author. The names are `Author`, `Author / Artist`, a third
    variant of that with a note about comma separation appended, `Artist`, plus
    Portuguese ones like `Conteúdo`.
    So a uniform `author:` has to be pushed where it exists *and* post-filtered where it
    does not, or a reader who typed it gets an empty page.
116. **`Http::fake()` called twice does not replace the first registration.** A test that
    called it again to change what the server reports was still answered by the stub
    installed in `setUp()`. Seven tests failed as though the feature were broken. Register
    the fake once and let it read a property, so a test says what the server holds rather
    than installing a second one.
117. **An unclosed bracket in a nested array literal is reported several lines away.**
    `Http::response(['data' => ['mangas' => ['nodes' => array_merge(...)]])` said
    "Unclosed '[' does not match ')'" on a line that is itself balanced, because the outer
    `['data' =>` bracket was short one `]`. Assign the pieces to variables and build the
    response from them: the shape becomes readable and the mistake cannot hide.
118. **`trans_choice($key, $replace, $number)`.** Passing the replace array into the number
    slot is refused outright, `Argument #3 ($replace) must be of type array, int given`,
    and nothing renders. The count goes last.
119. **`Request::data()` is not reliably an array across every request a fake saw**, so
    `Http::assertSent(fn ($r) => $r->data()['query'])` threw "Cannot use object of type
    stdClass as array" on some requests. Read `$request->body()` and match the string.
120. **Suwayomi's login is `username`, not `email`, and it was asked about twice.**
     Papyrus's own login is email only, which makes the Suwayomi one look like an
     oversight by comparison. It is not. Measured on v2.3.2243 and again on v2.4.2366:
     `LoginInput` has `password` and `username` as `NON_NULL`, plus `clientMutationId`, which Relay adds; the mutation
     accepts `username` and refuses `email` with "a field name 'email' that is not
     defined for input object type 'LoginInput'"; and all 614 files in the bundled web
     UI say "Username", with no email-flavoured login string anywhere in them. Renaming
     `SUWAYOMI_USERNAME` to `SUWAYOMI_EMAIL` would have broken authentication against the
     version installed. This is item 5 again: measure the thing on the server you are
     actually running, because a plausible-sounding rename is indistinguishable from a fix
     until it is deployed.
121. **A unit test on a predicate is not coverage of the behaviour.**
     `Deployment::allowsRegistration()` was written with the deployment modes,
     documented as the thing that closes `single`, and unit tested to return false in
     `single`. The unit test passed the entire time and nothing ever called the method,
     so `/register` answered 200 on a one-reader install and anyone who could reach the
     site could create an account. The suite ran in `single` mode and RegistrationTest
     passed too, because it asserted that Fortify works in an app where registration had
     been switched off, and only the absence of the gate made that consistent. There is
     now a RegistrationGateTest that makes requests, and the answer is a 404. The general
     form: a test asserting that a function returns a value is evidence about the
     function. Only a test that goes through the entry point is evidence about the app.
122. **A command that mass-assigns a field the model has not marked fillable will not
     tell you.** `role` is absent from `User` fillable on purpose, so `users:create
     --admin` passed `role` to `create()`, Eloquent dropped it, the column default of
     `user` applied, and the command printed `Role: user` without erroring. The fix was
     to write the field after creation rather than widen fillable, which is the same
     reasoning as `createFor()` over setting `user_id`: mass assignment is for web input,
     and a console command that must set a privileged field should do so where it is
     visible. Then read the column back and assert it, because the alternative is a flag
     that does nothing.
123. **An upsert's insert rows must carry the end state.** `upsert()` applies the update
    column list only to rows that already exist. A new row is written from the insert
    values alone, so a "mark all read" whose inserted rows omitted `is_read` filled the
    database default of false into exactly the chapters the feature was pressed for,
    and the count query reported three rows while every flag was wrong. The first test
    that counted flags rather than rows caught it.
124. **A computed read inside its own action caches the pre-write value for the render.**
    The series page's library button read `$this->state->in_library`, wrote the row, and
    the re-render showed the old label, because the `#[Computed]` was memoised during the
    action and the render reused it. This is trap 63's shape, live on a real button: the
    fix is `unset($this->state)` after the write, and the browser was the only place the
    wrong label was visible, which is the argument for gate five.
125. **A Playwright click on a node that is about to be morphed does nothing.** The
    search grid streams in over several Livewire morphs, and a locator clicked while
    the count is still growing presses a node the next morph replaces: no request, no
    error, and the assertion that follows reads the new node with its old state. Wait
    until the count stops moving before pressing anything.
126. **A deferred `wire:model` commits on the next request, and if nothing else ever
    requests, it never commits.** The library's bulk-selection checkboxes were bound with
    plain `wire:model`, which writes the browser's copy of `selected` and defers the send.
    The bulk bar is gated on `count($this->selected) > 0`, evaluated server-side on
    render, so ticking boxes produced correct client state and no bar at all: measured,
    three boxes ticked, `$wire.get('selected')` returned `["116"]`, zero POSTs left the
    browser, and clicking an unrelated sort button flushed the pending model and made the
    bar appear. The reader's path was "select some series and nothing happens". Fix is
    `.live`, and the search page already used `wire:model.live.debounce.500ms` for the
    same reason. Twelve feature tests passed over it, because `Livewire::test()->set(
    'selected', [...])` bypasses the binding entirely: a test that sets the property is
    testing the action, not the control. A control is only tested by driving it.
127. **An `OwnedModel` query inside a queue job reads nothing.** `SearchSourceResult extends
    OwnedModel`, so a bare `SearchSourceResult::query()` still carries the `OwnedByUser`
    global scope, which resolves against `auth()`. A queued job has no authenticated user,
    so the scope matched no rows and the guard it fed always answered "not recorded". The
    guard's job was to stop a retried job counting its source twice, and it was silently
    inert: a real fan-out on 2026-10-02 came back `sources_done` 41 against
    `sources_total` 40, status `partial`, while returning 547 results, because the retry
    re-recorded a source whose outcome the first attempt had already committed. The fix is
    `forUser($this->userId)`, which strips the scope and states the reader explicitly.
    Writes are unaffected, because `createFor()` takes the user as an argument, which is
    the asymmetry that hid this: every write was correctly attributed and the read beside
    it saw nothing.
128. **A lock you cannot take is a different failure from a lock you take too late.**
    `SearchSourceJob::feedNextSource()` pops the next pending source under
    `Cache::lock(...)->block(...)`, and the old comment called a timeout "slow". It is not
    slow, it is a hang: the pop never happened, so the source stays pending and nothing
    will claim it, and if this was the last job to finish then `sources_done` can never
    reach `sources_total` and the run sits at "39 of 40" for ever. A swallowed feed has to
    spend its own slot, recording the shortfall so the counter balances and the run
    terminates. Same shape as trap 70's depth counter: the cheap fix is not to swallow,
    it is to make the unwind observable.
129. **`busy_timeout` does not apply to SQLite's deferred-transaction upgrade, so no value
    of it can fix a `database is locked` raised by a read-then-write.** Seven
    `SearchSourceJob` failures carry `SQLSTATE[HY000]: General error: 5 database is locked`,
    and the stack trace puts the throw inside Laravel's own bookkeeping, at
    `DatabaseBatchRepository::updateAtomicValues()`. That method is a transaction which
    reads the batch row and then updates it, and two framework facts make the outcome
    predictable rather than rare. `SQLiteGrammar::compileLock()` returns `''`, so its
    `lockForUpdate()` compiles to nothing on SQLite and there is no row lock at all. And
    the transaction is **deferred**: it opens on the SELECT and has to *upgrade* to a write,
    and when another connection has committed since that snapshot was taken SQLite returns
    `SQLITE_BUSY` immediately, without consulting the busy handler. It is the documented
    reason SQLite advises against upgrading a deferred transaction where a deadlock is
    possible, with `BEGIN IMMEDIATE` as the usual answer.

    Measured, twelve out of twelve reproductions, slowest after **3 ms**, against
    `journal_mode = wal` and `busy_timeout = 10000`. A ten second timeout that gives up in
    three milliseconds is the signature of the upgrade path. That is why adding WAL and a
    timeout in an earlier pass reduced these failures without removing them: the setting
    was correct and it was never going to help.

    Laravel already classifies `database is locked` as a concurrency error and will retry
    it, so the missing piece is only an attempt count. `updateAtomicValues()` calls
    `transaction($callback)` and the signature defaults `$attempts` to **1**, so the
    built-in retry never engages.

    **Diagnosed, deliberately untreated.** The cost of leaving it is small, because trap
    127's guard means a collided job is now a cheap re-entry rather than a lost source. A
    subclass overriding that one method, bound in place of `Illuminate\Bus\BatchRepository`,
    was written and then removed: the binding did not take effect, and shipping an override
    whose effectiveness cannot be demonstrated is worse than documenting the limit. Recorded
    so the next reader starts from the measurement rather than from seven failed jobs.
130. **A 404 from AniList's `Media(search:)` means "no match", not "refused", and a fake
    must be copied from a real response rather than from a tidier one.** Measured
    2026-10-02 against the live API: a search matching nothing answers **HTTP 404** with
    `errors[0].message = "Not Found."` and `data.Media` null. Every fake in the suite
    answered the same case with a 200 carrying `Media: null`, which is a response that
    endpoint does not produce, so the client read the real 404 as a refusal.

    The consequence was a feature that could not complete. `ImportAniListProgress` backs
    off and re-books on a refusal, so a library whose first title is not on AniList never
    got past it: the job released, retried the same title, hit the same 404, and the
    connections page sat on "Checking 0 of 0 series" for ever. Thirty-two tests were green
    over it, because the fake was asserting a belief about the API rather than the API.
    A rate limit is 429 and a dead token is 401; both are still refusals, and `forUser()`
    still treats 404 as an error because there it is one.

    The wider lesson is trap 131's shape: **a fake is a claim about the world.** When it is
    easier to write, it will encode what would be convenient, and the suite then defends
    the convenient thing. Any fake standing in for a third-party response should be pasted
    from a real one, status code and all, and ideally a test should assert the shape the
    remote actually returns.
131. **A test that fakes a third-party response is asserting a claim about that service,
    not about our code.** This has now cost two features and one production outage, so it
    is recorded as a rule rather than an anecdote.

    What each fake got wrong, and how it was found:

    | Fake said | Reality | Cost |
    |---|---|---|
    | 200 with `Media: null` for no match | 404 with a Not Found error | The import could not complete. Trap 130. |
    | `Http::fake` for concurrency | Jobs dispatch inline on the sync driver | The cap itself was never asserted; proved only that every source got searched. |

    Neither was visible from the test file. The first needed a live request to notice, and
    the second needed the mutation check: break the cap, and confirm the test goes red.
    A fake that is never checked against the real thing is a comment with assertions on it.

    The general test, worth applying before believing any suite: **if a test would still
    pass if the remote service changed its mind about something, it is testing the fake.**
    Ask what the real service does, not what is convenient to return, and where it can be
    checked cheaply, check it.
132. **A fan-out ordered by name and capped at a number below the source
    count does not search the sources it leaves out, it silently renames which ones
    matter.** `SearchFanout::targets()` ends in `orderBy('name')` and the search page
    passes a limit of 40. With 82 sources installed and 61 of them named "MangaDex (...)",
    the first 40 by name are ten scanlation sites plus thirty MangaDex languages, so
    MangaFire's seven sources, MangaTaro, Thunder Scans and Weeb Central are never
    dispatched at all.

    A reader reported this as "search only seems to hit MangaDex and Atsumaru". It was true,
    and the obvious explanation was wrong: the same report also turned up the
    truncated-sync bug that excluded nine sources. Two independent causes, one symptom, and
    fixing the first did not move the second. Measured after that fix: run 87 queried 40
    sources and Asura Scans was among them, which made the remaining gap look closed until
    the 40 were counted.

    The order is the part that makes it look deliberate. Alphabetical is a reasonable
    default for a list a human reads and a poor one for a truncation a machine applies,
    because the sources that fall off are the ones that happen to sort late rather than
    the ones that are slow or broken.

    **Fixed, and measured rather than asserted.** The slice is now taken round robin over
    `extension_pkg_name`: every site's first source, then every site's second, and so on,
    capped as before. Run 88 against the same catalogue still dispatched exactly 40 sources,
    so the cost of a search did not move, but 20 of the 40 were non-MangaDex against 10
    before, and **15 of the 16 installed packages were reached** rather than 14. MangaFire
    returned all seven of its sources, Weeb Central, Thunder Scans and MangaTaro one each,
    every one of them `ok`. The package still missing is Comix, which is dropped as a dead
    source after five timeouts, which is the exclusion working rather than failing.

    Grouping here is by extension package and is deliberately **not** HealthDashboard's
    grouping. The dashboard groups for display and collapses MangaDex to a single tile,
    where dropping a variant loses a script. This grouping exists only to make a cap fair,
    so it must leave every variant queryable; the two look alike and are opposites. Seven
    tests in `SearchFanoutInterleaveTest`, three of which fail if the round robin is
    reverted.

    The limit also stopped being a SQL `LIMIT`, because the interleave cannot happen before
    the rows exist. A non-positive limit keeps SQL's meaning, which is worth not losing:
    `LIMIT 0` is no rows and `LIMIT -1` is all of them.

    `/browse/{source}` was built while this was open and is still worth having. It is not the
    mitigation any more, it is the second door.

    The general shape: **a cap combined with an ordering is a policy, and a policy nobody
    chose is still a policy.** When a limit and a sort live on opposite sides of a class,
    the pair decides what gets searched and no single line of code says so.

133. **A right answer for the wrong reason still has to be corrected.** H16 concluded there
     was no image-conversion setting, citing `serveConversions` being absent from the
     schema. It is present, on four types, and writable through `Mutation.setSettings`. The
     conclusion that survived is the one that was measured rather than inferred: all three
     of `webp`, `avif` and `jpg` come back byte-identical `image/webp`, so there is no
     bandwidth win either way. But a note saying "there is no setting here" reads as "this
     was tested", and the next person skips the test. The correction is the point, not the
     tidiness. This one sat in a document for weeks before anybody read the schema it was
     describing, which is the argument for re-reading a claim whenever the server moves.

134. **A listing call is a write, and what it writes can be worse than what it read.**
    `fetchSourceManga` returns manga rows and Suwayomi persists them, so a browse does not read
    the catalogue, it overwrites it. The status it writes is UNKNOWN, because a listing carries no
    status, so **browsing a source resets the metadata of every manga it lists**.

    Measured, MangaFire, five series, twice through:

        after fetch:   One Piece ONGOING · Devil Butler ONGOING · God Slayer ONGOING
                       Seolgyesa COMPLETED · Blue Lock ONGOING
        after browse:  One Piece UNKNOWN · Devil Butler UNKNOWN · God Slayer UNKNOWN
                       Seolgyesa UNKNOWN · Blue Lock UNKNOWN

    Chapter counts survived untouched, 2,440 and 1,521 and the rest, so nothing looked broken. A
    reader reported exactly this and phrased it better than the code had: they fetched a series,
    the chapters arrived, and **it still said UNKNOWN**. They were right, and the reason was that
    the browse had just written UNKNOWN over the answer a moment earlier.

    This is why a listing reports UNKNOWN on every row, measured across 122 rows from five
    sources. It is not that the sources do not know their own ongoing and completed titles. It is
    that the listing wrote the ignorance itself.

    It also invalidates a measurement that had looked clean. "3 of 3 listed UNKNOWN, 3 of 3 real
    after fetch" was true, and useless, because the next browse undid it. **A fix that re-reads
    what another read is about to overwrite is not a fix**, and the only way to notice is to
    perform the sequence the reader performs rather than the one that proves the point.

    The repair is two calls, because the costs differ by twenty times. `fetchManga` alone, for a
    series whose only gap is its status, measured a flat 186ms to 555ms whatever the chapter
    count. `fetchMangaAndChapters`, for a series with no chapters at all, measured 1,642ms to
    7,142ms and scales with the chapter count. Booking the thorough one to fix a missing word
    would re-read 2,440 chapters on somebody else's server to change a badge.

    The general shape: **an API that returns records is not thereby a read.** If the server keeps
    them, every listing is a write wearing a query's clothes, and anything another caller wrote
    first is now gone.

135. **A `#[Computed]` read as a property is not the computed.** `$this->series` and
    `$this->series()` are not the same thing, and the difference is not a warning. Read as a
    property it evaluates to null, so `if ($this->series === null) return;` is **always true**,
    the guard silently disabled the code beneath it, and the symptom was a job that was never
    dispatched. The same component already used `$this->series` correctly in its markup, which is
    what made it look right.

    The general shape: **a guard written against the wrong accessor is not a guard.** It reads as
    protection and behaves as an early return, and the test that should have caught it asserts on
    the outcome rather than on the call.

136. **`ReadingState::series()` and `loadOrCreate()` do not save.** `series()` returns an
    unsaved model and `loadOrCreate()` returns an existing row or that unsaved model, so neither
    persists anything. A caller that read either one and assumed a row now existed went on to ask
    something that requires one, and `CheckLedger::check()` throws `InvalidArgumentException`
    for exactly that. The fetch job caught the throw and reported it, so the failure mode was a
    silent "no chapters" for every series a reader had never added.

    The name is the hazard. `loadOrCreate` is a promise, and the only way to know whether it keeps
    it is to read it, which is what everyone does once. `setInLibrary()` and `save()` are the
    two calls that actually write, and they are separate.

137. **Alpine's `x-init` is one expression, and `requestAnimationFrame(fn)` calls `fn` unbound.**
    Three shapes of the same infinite-scroll sentinel failed in a row, and two of them logged
    nothing worth reading. A method on `x-data` reached through `requestAnimationFrame(observe)`
    has `this` as the window, not the Alpine scope, so `this.$el` is undefined and
    IntersectionObserver rejects its argument. Wrapping in an arrow to keep `this` does not help,
    because Alpine evaluates `x-init` inside `return (expr)`, so a statement list fails with
    `Unexpected token 'const'`.

    What works is a single IIFE, because it is one expression and captures `$el` and `$wire`
    lexically:

        (() => {
            const el = $el
            const io = new IntersectionObserver((entries) => {
                if (! entries.some((e) => e.isIntersecting)) return
                io.disconnect()
                $wire.loadMore()
            }, { rootMargin: '900px 0px' })
            io.observe(el)
        })()

    A third failure came first and is the one worth remembering: `if (! this.$wire) return` at the
    top of the setup is a race, because at `x-init` on a page that has just hydrated `$wire` is
    not attached yet. The observer was never created, the sentinel sat below the grid doing nothing,
    and scrolling to the bottom changed nothing with no error anywhere. **An observer that was
    never built and a scroll handler that is not wired look identical from the outside**, so a
    verification that only counts rows cannot tell them apart.

138. **A verification that cannot reach its subject reports success.** The finished download a
     reader was looking at belongs to `papyrus@localhost.test`, not to the account the browser
     checks sign in as. A check signed in, found the downloads page empty, printed "no download
     rows on this account" and exited cleanly.

     That is the dangerous shape: **an empty result from a check that could not run is
     indistinguishable from a pass.** The count it printed was true and the conclusion it drew
     was wrong, and a green run said nothing about the feature.

     Two accounts both called "Reader" is what made it possible, so the sidebar could not say
     which one a screenshot came from either. A reader looking at a page and a check looking at
     the same URL are not looking at the same data, and nothing on the screen says so.

     The fix is to seed the subject rather than reach for a password nobody wrote down: create
     the row for the account the check can reach, check it, delete it. Then the check depends on
     no credential, and the row it examines is one it put there and knows the provenance of.
     The alternative, using the reader's real row, would have required guessing their password.

     The general shape: **a check that reports "nothing to see" must prove it looked.** The count
     of things examined belongs in the output next to the conclusion, and a zero deserves a
     sentence saying why zero is the right number rather than a silent pass.

139. **A dialog opened by the click renders the state the click has not delivered yet.** The
     source chooser was a `<button>` carrying both `wire:click` and an Alpine
     `$dispatch('modal-show')`, because that is how `<flux:modal.trigger>` works: the wrapper
     div's whole job is to dispatch on click, so there was no obvious way to do it any other
     way. The dispatch runs the instant the button is pressed. The Livewire round trip carrying
     which sources to list has not come back. So the dialog opened showing a heading and
     nothing under it, then filled in a moment later.

     It passed a feature test and failed in the browser, because a feature test drives the
     method and then reads the rendered output, and the rendered output is correct. Only a real
     click has the two events racing.

     The fix inverts the direction: the button only asks (`chooseSource`), and the action
     dispatches `open-source-chooser` after its own response has been applied, which is when
     the list is genuinely in the component. One `x-on:open-source-chooser.window` on the page
     root turns that into Flux's `modal-show`. It costs the reader the round trip before the
     dialog appears, and that is the right trade: an empty dialog reads as broken and a slow
     one does not.

     The general shape: **an imperative client-side open is a race with the server round trip
     that fills it, and the race is invisible to any test that calls the method directly.**

140. **A lookup keyed on a value the page recomputes fails exactly when the page recomputes
     it.** The chooser remembered which group the reader clicked by the group's *primary*
     manga id, and found the group again with "which group's primary is this id". A search
     streams: results arrive one source at a time and the deduplicator regroups from scratch on
     every render, so which result is a group's primary is a ranking, and the ranking moves.
     A source answering after the click is enough to make the reader's id an alternative, and
     the lookup then found no group at all and returned an empty chooser.

     Measured, and the growth is the tell: the chooser's list went from two entries to nine
     while it was open, because more sources kept answering.

     `DedupeGroup::hasManga()` asks whether an id is one of the group's own. The ids inside a
     group do not move as results stream in, so membership survives the regrouping that
     primacy does not. The general shape: **an identifier chosen out of a recomputed structure
     is a ranking, not an identity.** Reach for something inside the thing rather than
     something about it.

141. **A state map that excludes the row a button acts on shows the wrong state.** `libraryMap()`
     on the search page was keyed on group *primaries*, which was correct while a card could
     only act on its primary. Letting the reader bookmark an alternative made that wrong: an
     alternative the reader already held had no entry, so the button offered to add a series
     that was already on the shelf. Adding is a no-op, so the button looks broken.

     The failure is quieter than a wrong link, because the common case still works. It only
     shows for a reader who already has one copy of a title on a second source, which is
     exactly the reader the feature is for.

     One query either way, so extending the `whereIn` to every result in every group costs
     nothing measurable. The general shape: **widening what a control can act on means
     widening the state it reads, in the same commit.** The two are the same list and one of
     them will be forgotten.

142. **A gate that dies before its cleanup leaves its button presses behind.** The chooser gate
     presses a real bookmark against a real account, which is the only honest way to check one.
     A run that crashed on a locator timeout never reached its revert click, and the reader's
     library kept the series. Two rows were added this way over two runs, and one of them was
     still there a full working session later.

     The cleanup script now deletes only rows a gate *created today*, and leaves
     `in_library=false` rows alone, because those are what `setInLibrary` itself writes for a
     series a reader looked at and declined. Guarding on `created_at` rather than on a hard
     coded id list is what makes it safe to run against an account that is not a fixture.

     `probe-chooser-cleanup.php --fix`, and it prints what it would remove before it removes
     it. The general shape: **a check that writes to real state must be able to fail between
     the write and the undo**, so the undo needs to be a command rather than a line at the end
     of the script.

143. **Nineteen of the twenty browser gates cannot be run on this box.** They are Python, and
     no Python on this machine has `playwright` any more. The uv-managed interpreters under
     `.local\bin` are all clean, and the Windows Store Python was never the one that ran them.

     The browsers themselves are still installed, under `%LOCALAPPDATA%\ms-playwright`, and the
     npm `playwright` in `.tools/suwayomi` drives them, which is how `verify-october-pass.mjs`
     and `verify-source-chooser.mjs` work. So the gates are not obsolete, they are
     misfiled: nineteen scripts need porting before they can check anything again.

     The trap is not that they fail, it is that **`import playwright` failing at the top of a
     script is indistinguishable from a gate that passed**, because `python script.py` on this
     box prints "Python was not found" and exits 1, and a gate whose exit code nobody reads is
     a gate that has stopped existing without saying so. `verify-october-pass.mjs` carries a
     comment saying it is a Node port of the Python original for exactly this reason, which is
     the only reason the pattern was known. The same shape arrived on 2026-10-04 from Node, as
     `MODULE_NOT_FOUND` with `playwright@1.63.0` installed and `npm ls` clean: the shell's
     working directory had reset to the workspace root, so `node verify-cover-reveal.mjs` was
     looked for one level up. **A gate that failed to load is not a gate that ran**, and this
     one printed a stack trace rather than a summary line, which is the difference between a
     failure you can read and a green run that says nothing. The fix is an absolute path or a
     `workdir` that is set on every call, since the harness resets the shell between commands.

144. **A dev server that is up can still be serving a stylesheet missing utilities.** The search
     grid rendered one card per row at 1541px wide inside a 1440px window. Every card was a
     grid item with `min-width: auto` and a long `truncate`d line inside it, and `display: grid`
     computed to `grid-template-columns: none`, so the container was already too wide to divide
     and the grid had a single auto track.

     That reads as a markup bug and it is very nearly impossible to blame on the CSS from the
     outside. Two things had to be ruled out before the real cause appeared: the custom
     `.stagger-enter` rules (animation only, harmless) and a regression from the change under
     test (`git stash`, re-probe, identical). The change was innocent, which is the useful part:
     a stash-and-re-probe is the only cheap way to separate "I broke it" from "it was like
     that".

     The cause was `@tailwindcss/vite` serving a module it had not regenerated. `grid-cols-2`
     was absent from the stylesheet entirely, proved by putting a fresh `<div class="grid
     grid-cols-2">` in the page and reading its computed `grid-template-columns`: `none`. And
     `npm run build` generated all four classes correctly, which is what located the fault in
     the dev server rather than in Tailwind or the markup.

     The general shape: **an up process is not a correct process.** The existing note on Vite
     covers it being *down*, which is loud. Being up and serving a stale module is silent, looks
     exactly like a code defect, and sends you into the wrong file. When computed styles
     contradict markup that is obviously right, test the utility on a bare element before
     reading any application code.

     Two smaller lessons from the same afternoon. A browser gate must close its browser in a
     `finally`: one that throws partway leaves Chromium resident, and on a box with under a
     gigabyte free that is what kills the JVM the next check needs. And this machine ran out of
     memory twice with Suwayomi and the queue worker evicted, which surfaced as two gates
     reporting "no results" rather than as an out-of-memory error. Free memory is worth reading
     before believing a gate that suddenly finds nothing.
145. **The picker and the server can disagree with nothing on screen saying so.** The report was
     "add sources, look at the picker, see the old ones". The picker is built from the local
     `Source` mirror, and installing an extension only tells the server, so the mirror stayed
     where it was. Measured on the box: 84 sources installed on the server, 82 in the mirror,
     the two missing being extensions added at 11:43 and 11:44, and the mirror's newest row
     timestamped 23:15 the night before.

     The install path toasted "Sync sources to make it searchable", which is honest and
     useless: it names a button on a different tab, so the reader is told to go and find it.
     Nothing on the picker itself said it was behind.

     **Mirroring after our own installs is not the whole fix.** Suwayomi has an extension
     interface of its own and this page's footer tells readers to deal with Suwayomi directly,
     so an extension installed there produces no event Papyrus ever sees, and no amount of
     tidying our own install button reaches it. Both halves are needed: mirror on install and
     uninstall, and say what is missing.

     The second half has to be cheap enough to run on every render, and it nearly was not.
     Asking the server how many sources it has is one query and costs a measured 185ms median,
     1,945ms on the first call. Asking which extensions are installed is the same one query for
     a filtered list of seventeen rows, costs **29.8ms**, and names them, so the notice can
     say which rather than how many. The extension list the page already fetches for its own
     tabs, compared against a local `pluck`, is the entire check, and the local side measures
     0.30ms. `syncSources()` on its own is 125ms against 1,030ms for `syncAll()`, because a
     store's index has not moved when an extension is installed.

     Two smaller lessons. A failed mirror is reported as its own fact and never as a failed
     install: the extension **is** installed, and telling a reader it failed sends them
     looking for a problem that is not there. And the notice is empty when the server cannot
     be asked, because a banner claiming sources are missing, printed because the server was
     unreachable, is a lie with a button on it.

     The general shape: **a cache with no invalidation and no staleness signal will be read as
     the truth.** "Behind" has to be visible where the stale list is displayed, not announced
     once by the operation that caused it.

146. **Four ways a fake can pass a test that should have failed, all in one helper.** Adding
     mirroring to the sources page took four attempts to get a green test that meant something,
     and every failure looked like the production code being broken. None of it was.

     **The branch matched nothing.** The catalogue document is
     `query Sources { sources { ... extension { pkgName isInstalled } ... } }`, and the branch
     tested for `query Sources`. It reads correctly and matches, because the operation name is
     not in the body at all: `Gateway::execute()` sends the document, not the name. The fake
     fell through to a generic `['ok' => true]`, `syncSources()` read zero nodes, and the test
     failed on an empty table for a reason that had nothing to do with mirroring. It then had
     to be matched on `sources {`, and **before** the extension branch, because the catalogue
     document contains the substring "extension" and anything matching that word first serves
     the extension list to the catalogue query.

     **`Request::data()` is not arrays.** Nested JSON arrives as `stdClass`, so
     `['variables']['filter']['and']` is "Cannot use object of type stdClass as array". Read
     from `json_decode($request->body(), true)` instead, which is the shape the indexes assume.

     **A filter the fake ignores feeds the test a lie.** The real server filters server-side.
     A fake returning every node regardless handed the drift check every *uninstalled*
     extension in the catalogue, and the notice would have accused the reader of missing
     sources they never added.

     **And the two guards before the code under test.** `canInstallName()` refuses to install
     what is already installed, and `isTrusted()` reads the reader's own trust rows, so an
     untrusted publisher cannot be installed at all. Both make `install()` return before
     mirroring. The subtle one is that `trustedIds` and `extensions` are computed: chaining
     `->call('trust')->call('install')` on one Livewire instance evaluates the trust guard
     against a cache written *before* the trust, so the test has to use a second instance. And
     the page opens on the Installed view, where an uninstalled extension is not listed at all,
     so `canInstallName()` finds nothing and returns false. Both look exactly like a broken
     mirror.

     Two more, from the same afternoon and the same shape. A computed property is cached for
     the instance that ran the action: `browseSites()` was rendered with no sources and would
     answer with an empty list forever after, so the assertion has to go through a fresh
     instance, which is what a page load does. And a diagnostic that matches nothing and
     prints nothing is the same failure as a check that finds nothing and prints nothing: a
     helper that guessed two short package names instead of the mirror's own
     `eu.kanade.tachiyomi.extension.*` values reported "marked 0 row(s)" and exited zero, so
     the gate ran against an unchanged mirror and reported the notice missing. It now exits 2
     when it changes no rows.

     The general shape: **a fake is a program with its own bugs, and a test that fails for a
     reason you cannot name is telling you the fake is where to look.** Print what the fake saw
     rather than reasoning about what it should have seen.

147. **A component attribute written `attr="{{ ... }}"` is escaped text, and the component
     escapes it again.** The confirm modal's `action` prop was fed `wire:click` targets built
     at the call site with `Js::from(...)`. `{{ }}` escapes at the call site, the component
     received the escaped string, and its own `wire:click="{{ $action }}"` escaped it once
     more: the browser decoded one level and Livewire compiled `uninstall(&#039;...&#039;)`,
     a SyntaxError at `&`, one per extension on the page, none of them visible in any
     server-side test. **There is no HTML parser between a Blade call site and the component
     it renders**, so "the browser decodes attributes" does not apply. Anything carrying
     quotes goes through the `:prop` binding, which passes the value unescaped. Integer
     parameters are safe by accident: `e()` has nothing to say about digits.

148. **A positioned sibling paints above a static image.** The cover skeleton underlay sat
     before the image in the DOM inside the same stacking context, but the underlay was
     `position: absolute` and the image was static, and positioned elements paint above
     static ones: every cover in the app rendered under a permanent shimmer veil. Found in
     the first browser screenshot, where it read as a wash over the whole grid; no test
     renders pixels. The fix is `position: relative` on the image and nothing else.

149. **An animation with `fill-mode: both` holds its final keyframe forever.** The reading
     room's rows carry a per-depth blur, and reusing the `blur-in` keyframes for their
     entrance let the animation's final `filter: blur(0)` override that depth blur
     permanently, because a filled animation outranks the declared property. An entrance and
     a resting state that share a property must agree on its final value: the rows got their
     own opacity-only keyframes.

150. **"Slow, and some never load" was two facts, and neither was the code under change.**
     The report arrived the day after a design pass, so the redesign was the obvious suspect,
     and it was innocent in both directions. Measured: `fetchMany` on a live source answered
     in 117ms and a live browse in 47 to 627ms, while the failing pages were all sources
     whose extension JARs had vanished from Suwayomi's disk between one session and the next
     (6 of 18 packages left; the folder was gutted with nothing in any log saying by whom).
     The server answered those with its own NPE, which the page printed verbatim as a stack
     trace, and the mirror still listed every source as installed, which is why the picker
     kept offering them.

     Two lessons. **A redesign is the suspect for anything reported after it, so measure the
     unchanged path first**: one timing call on a live source cleared the design in a minute
     and pointed at the server. And **the mirror and the server disagreeing is a fact the
     page must survive**: the browse page now classifies the failure through the registry
     query and says the source is gone rather than showing Kotlin, but the deeper fix is that
     the sources page's drift notice was the only thing pointing at this, and nothing
     re-checks registration at startup.
151. **A capture-phase `load` listener cannot hear an image that loaded while it was still
     detached, and morphdom builds every new node detached.** The cover atom's blur-up holds
     its image at `opacity: 0` until `is-loaded` is added, and `behaviors.blade.php` added
     that from a capture-phase `load` listener plus a settle pass on `DOMContentLoaded` and
     `livewire:navigated`. Both are correct and both miss the same case. Morphdom builds the
     incoming nodes in a detached container and swaps them in afterwards, so a cover whose
     bytes are already cached fires its `load` **while detached**, where a listener on
     `document` cannot reach it. It then arrives already `complete`, and nothing ever resolves
     it.

     Measured, on `/browse/4` after the Second Pressing pass:

     ```
     first paint      20 covers   20 revealed   0 complete-but-hidden
     after one append 40 covers   20 revealed  20 complete-but-hidden
     manual settle                 40 revealed
     ```

     So the bytes were there, the network was clean, and 20 of 40 covers were invisible behind
     the shimmer, and the reader's description was "scroll loads them, then they vanish and we
     go back to that loading loop". It was not a loop: the grid appends one page per scroll,
     six scrolls and six `livewire/update` posts, measured. Each append added a fresh screenful
     of permanently hidden covers, which reads as the loop.

     This arrived with the redesign and could not have arrived before it: the old markup was a
     plain `<img>` inside `.cover-frame` with no opacity to hold it back, so a missed reveal was
     invisible. **A reveal that depends on an event is a new failure mode the moment the image
     gains an initial state.** It was invisible to every one of the 1,076 tests, because a
     feature test renders HTML and this is `opacity` on an element in a browser.

     The fix is a settle pass on Livewire's own lifecycle, registered through `livewire:init`
     because `@fluxScripts` comes after this include and `Livewire` is not defined yet at
     parse time. `verify-cover-reveal.mjs` is the gate, over the login wall, the library grid,
     the series hero, the browse grid across four scrolls and the search grid while it streams.

     Four smaller lessons from the same pass. **Ask the element what state it is in rather
     than whether it looks loaded**: the counter that mattered was
     `complete && naturalWidth > 0 && !is-loaded`, because `is-loaded` is also added on `error`
     and a broken cover counts as resolved. **An `opacity: 0` start state makes "loaded" and
     "arrived" different questions**, and only the second one is visible in a screenshot. And
     **a check whose selector misses its subject reports a failure that does not exist**:
     the first series probe looked for `.flux-callout`, which matches nothing, defaulted to
     "still loading at 30s", and reported five healthy pages as broken. Flux renders
     `data-flux-callout`. That is trap 138 with a CSS class instead of a wrong account.

     The fourth is a measurement artefact that cost an hour of chasing a bug that was not
     there, and it is the reason `verify-cover-reveal.mjs` asserts on element state rather
     than on a screenshot. After the fix, every cover reported complete, sized,
     `opacity: 1`, `filter: none`, on screen, non-zero box: 80 of 80, and **the screenshot
     still showed a dozen empty frames in exactly the places the page said were fine**.
     Three separate readings agreed with each other and disagreed with the picture, so the
     picture was the suspect rather than the other two.

     The cause: `page.screenshot()` captures the compositor surface, and a `loading="lazy"`
     image that has been decoded but not rasterised is simply absent from it. Asking for
     `element.screenshot()` forces a paint per element, which is why one disagreed with the
     other. Walking the grid top to bottom and capturing again rasterises everything, and the
     second frame is complete:

     ```
     before the walk   80 of 80 report drawn   a dozen frames empty in the capture
     after the walk    80 of 80 report drawn   every frame present
     ```

     **A blank region in a screenshot is a question, not an answer**, and the first thing to
     ask is whether the browser painted it at all. This is trap 45's shape with the opposite
     polarity: that one is a browser that delivers no frames, this one is a capture that
     delivers an incomplete frame, and in both cases the pixels are the least reliable witness
     in the room. `probe-blank-pixels.mjs` settles it by screenshotting each cover on its own
     and asking whether the bytes have any variance, which distinguishes "flat fill" from
     "artwork" without a human looking.

     ### The second half: a page that told the reader the wrong thing

     Trap 151's companion, and it was not in the same file. `CheckLedger::check()` records
     `ERROR` or `NO_CHAPTERS` with the server's reason, and the series page read none of it:
     every zero-chapter series rendered one sentence. Measured on the dev library, 8 series had
     no chapters, 4 of them recorded `Cloudflare bypass currently disabled`, and all 8 were
     reported as "The source has not listed any chapters for this series". That is not a
     wording preference. It is a different claim: one says the source has nothing, the other
     says it was asked and could not answer.

     The retry button was worse, because it had no wording at all. Its catch branch called
     `Flux::error()`, which trap 79 says does not exist in Flux 2, so pressing the one control
     that exists for a failing source raised "Call to undefined method
     Flux\FluxManager::error()" and 500ed the Livewire request. Measured: the browser saw a 500
     and the gate reported it as **"the series chapter list offers read toggles: 0"**, because
     the page never came back. The gate was right about the count and wrong about everything
     else. Trap 79 said the one pre-existing `Flux::error` was "on a catch branch no test
     reached", and the October pass reached it.

     Fixed in three parts: a `danger` toast carrying the recorded reason, `emptyReason()`
     reading the ledger row, and the two callouts that printed different sentences for one
     condition now reading the same computed. The classification is on the message because
     that is what `CheckLedger` does and because the manual retry writes no ledger row, so
     there is no state to read.

     And **a gate carrying a fact about the data is trap 102 wearing a series id**.
     `verify-october-pass.mjs` hardcoded manga 112 with a comment saying it had chapter rows.
     It sits on a Cloudflare-blocked source, so it cannot have them, and the gate failed on a
     series behaving exactly as designed. It now reads candidates off `/library` and takes the
     first with rows: series 116 of 20. **The count belongs in the failure message**, so the
     next reader can see what it looked at.

     ### A third one, found only because the second was fixed
     Trap 129's untreated `database is locked` had a user-visible consequence nobody had
     connected to it. The login wall is eighteen covers at once by design, and every one of
     them was rate limited, and Laravel's limiter counts by writing: `increment()` on the
     database store is a transaction that reads the counter and then updates it. On SQLite that
     is trap 129's deferred-transaction upgrade. Measured: 6 of 72 cover requests answered 500
     across four browser rounds, and the trace landed inside
     `DatabaseStore::increment()`. Sixteen ids asked one at a time returned 16 of 16 as 200, and
     an unthrottled route under the same concurrency did not fail, which is what put the blame
     on the limiter rather than on Suwayomi or the proxy.

     The obvious fix is to move the counter off SQLite, and it was measured before it was
     believed. `FileStore::increment()` is a read-modify-write with no lock:

     ```
     limiter store = file, 140 concurrent requests, limit 120 a minute
       200 served 140    429 limited 0
     ```

     **A rate limit that silently stops limiting is worse than a blank cover.** So the covers
     carry no limit and the payload, which is one request per page load, keeps its own. Measured
     after: 0 of 19 concurrent cover requests fail, 0 of 72 in the browser, and 130 sequential
     payload requests give 120 served then 10 limited.

     The general shape, and it is the one worth keeping: **the fix for a concurrency bug is not
     the first thing that makes the symptom go away.** Moving the counter made the 500 vanish
     and the limit vanish with it, and only the second measurement caught that. The check that
     caught it is the one nobody writes: after changing a limiter, ask for more than it allows.

152. **A gate that resolves its remote dependency before checking its local one turns every
     caller into a network caller.** Incognito is a per-source preference, and every write path
     that answers "should this record?" knows a Suwayomi manga id and nothing else, so the
     first version resolved manga to source with `manga(id:)` and only then asked the local
     `user_sources` table whether that source was incognito.

     Seven `ReadingStateTest` tests died on `Incorrect username or password`. That test
     registers **no `Http::fake()`**, so the call went to the real server on port 4567 with the
     real credentials from `.env`. The suite had been hermetic for `ReadingState` and this
     change is what broke that, and it is trap 131 in the direction nobody checks: not a fake
     that lies, but the absence of one, so the real world answered.

     The order is the whole design and it was obvious in hindsight:

     ```
     no incognito sources for this reader   -> false, one local indexed query, no network
     has incognito sources                  -> resolve manga to source, then compare
     ```

     Nobody has turned incognito on on a fresh install, so the first branch is the only one
     that runs until somebody opts in. Asking the cheap question first makes the feature free
     when unused and reachable by the unit tests; asking the expensive one first makes it a
     dependency whether or not anyone wants it. **When a decision needs a local fact and a
     remote fact, find the order in which the local fact makes the remote one unnecessary.**

     Five more from the same pass, all of them costs that were paid in guesses first.

     **Counting a library by source is trap 52 wearing a new hat.** The incognito control shows
     how many of a reader's library sits on a site before it is pressed, and the obvious
     implementation resolves each series. Measured:

     ```
     one manga(id:) per series   1,379ms for 20 series   (69ms a series)
     one fetchMany batch           350ms for 20 series   (20 returned)
     agreement                     20 of 20
     ```

     Extrapolated to the 300-series library this app is designed for, the naive form is over
     twenty seconds of rendering one page. It agreed with the batch on every id, so the batch
     is what ships and the agreement is what justifies it.

     **A comment saying you were careful is not a mechanism.** The test class documents trap
     116, that a second `Http::fake()` does not replace the first, and then installs one in
     `setUp` and three more inside tests. The `setUp` fake answered every case, so three tests
     passed or failed for reasons that had nothing to do with what they set up. The fix was to
     make the fake read properties (`$knownSeries`, `$knownMany`, `$manyFails`) rather than
     being reinstalled, which makes a per-test stub impossible to write by accident.

     **`#[Computed]` resolves through `__get`, which returns a value and not a reference.** A
     per-site memo written as `$this->counts[$key] ??= ...` on a computed property is silently
     discarded: *"Indirect modification of overloaded property has no effect"*. Nothing warns,
     the memo does not memoise, and the only symptom is the query the memo existed to avoid.
     The drill-down test caught it because it asserts on a count and the count came back wrong.

     **A control that changes a preference is a destructive gate, and needs its own undo.**
     `verify-incognito.mjs` presses it, reads a chapter, and presses the reverse in a
     `finally`, because trap 142's failure mode here is a reader whose preferences the test
     suite changed. `probe-incognito-state.php` answers the question that leaves behind: is
     anything actually suppressed. It reported 0 of 1 rows incognito, and zero is the only
     acceptable answer.

     **A selector that misses its subject reports a failure that does not exist**, for the
     third time in this file. The gate asked whether `body.innerText` contained "Back to
     chapter list" and concluded a working reader was unusable, because the back link is an
     icon whose only text is an `aria-label`. Reading the accessible name fixed it. Trap 138
     with a CSS class, trap 151 with an `aria-label`.

     And one placement finding worth recording, because it is a property of the feature rather
     than a bug in it: **the health page's site rollup joins `source_healths` to `sources`, so
     the incognito control only renders for a site that has been asked about at least once.** A
     reader who has never searched sees no sign that the feature exists. That is a consequence
     of putting the control in the drill-down, which is where Skip already lives and where one
     button can retire 61 MangaDex variants. Correct, and still worth knowing.

153. **`@open` is not a Blade directive, and an unregistered directive is not an error.**
     The tags panel on the series page was written `<details @open($this->tags !== [])>`,
     which reads like it should work and looks like the idiomatic way to conditionally open a
     disclosure. Measured on **Laravel 13.34.0**: it is not a registered directive.

     ```
     Blade::compileString('<details @open(true)>')       ->  '<details @open(true)>'   (unchanged)
     array_key_exists('open', Blade::getCustomDirectives())  ->  false, 21 directives registered
     ```

     The compiler leaves it alone, it reaches the browser as a literal `@open(...)` attribute,
     and the HTML parser ignores an attribute it does not recognise. The details element is
     simply never open. No exception, no log line, no rendered comment: the feature looks
     switched off. The fix is `{{ $condition ? 'open' : '' }}`, which compiles to an `echo`
     and works.

     **An unregistered Blade directive is a silent no-op**, and it is the mirror image of a
     registered one that no longer exists. The compiler cannot warn about something it does not
     recognise as a directive attempt, so the only detection is behavioural.

     It was caught by a browser assertion on `details.open` that ran *before* the gate clicked
     anything, which is the general lesson: **assert the state the server was supposed to
     produce before the gate does anything to the page.** A check that runs after a click
     reports what the click did.

     And the probe almost lied about it. `probe-blade-open-directive.php` prints
     `renders open: yes` for `@open(true)`, because its own regex looks for `\bopen\b` in the
     output and matches the literal text `@open(true)`. **A probe can be fooled by the very
     thing it is measuring**, which is why the probe asserts on the compiled string and the
     directive registry as well as on the rendered markup. Three readings, one of which is
     about the probe.

     Four smaller ones from the same pass.

     **A formula that varies continuously where the answer is binary is the wrong shape.** The
     filled star is a solid glyph behind an outline one, clipped with `inset(0 X% 0 0)`, so a
     filled star wants `X = 0` and an empty one wants `X = 100` with nothing between. The first
     version was `(max - min(index, shown)) / max * 100`, which is smooth across the row and
     wrong at every star: on a four-star rating it produced `80, 60, 40, 20, 20`, so no star
     was ever full and the fifth was fuller than the fourth. CSS cannot ask "is this at or
     below that", but it can turn the comparison into arithmetic and clamp it:
     `clamp(0, (index - shown) * 1000, 100)`. **When the only two right answers are 0 and 100,
     a formula producing everything between is a formula that is wrong everywhere.**

     **`every()` on an empty array is `true`.** The first run of `verify-rating-tags.mjs`
     reported three passing checks against zero stars, because the component's root was missing
     the `class="rating"` its stylesheet keys on and every star-counted assertion was vacuous.
     Trap 151 said "an empty result cannot pass"; that was about comparing a count to what
     rendered, and this is the same failure with the check itself having nothing to iterate.
     The gate now fails loudly before any per-item assertion runs.

     **A disclosure is a toggle, not an open button.** Once the server-side open worked, the
     gate's unconditional click on the summary *closed* the panel, and two checks then failed
     for the opposite reason to the run before. The gate now reads `details.open` first and
     only clicks when it is shut, and the assertion about the server's open state has to run
     before that click or it can only ever report what the click did.

     **Locate a summary by what it contains, not by the text it shows.** The summary reads
     "Tags" with no tags and "1 tag" with one, so a gate searching for the word "Tags" works
     exactly once and then times out for ever. It failed on a page that plainly said "1 tag"
     beside a working editor. A containment selector or a `data-` attribute survives the text
     changing; text does not.

     And **a cleanup that reports what it could not undo is not a cleanup.** The first version
     walked a snapshot of the chips taken before the first click, with a `.catch(() => {})`
     around each removal, and left one tag behind while reporting every check green. The
     `.catch` that keeps a cleanup from throwing is the same thing that hides a cleanup that
     failed. It now re-reads, retries to a bound, and counts a leftover as a gate failure,
     because a gate that leaves a reader's library in a state it chose cannot be re-run safely.
---
---

## 6. Working philosophy

**Verify, do not infer.** Every field name, enum value and behaviour comes from the live
server or the committed snapshot. When research and the running server disagree, the server
wins and the research gets corrected in writing.

**Check every published format before calling something absent.** I declared Keiyoushi's
index vestigial after reading `index.min.json`. It held 1,379 extensions, in `index.pb`.
That was the worst error in this project. Look for a second format.

**State what was measured.** Mark inference as inference. The plan separates "verified
against the live server" from "research says".

**Measure thresholds, do not guess them.** The dedupe threshold is 0.95 because "One Piece
G" scores 0.9412 against "One Piece". The measurements live in `config/papyrus.php` so the
number is defensible.

**Never weaken a security boundary to silence a symptom.** The tenant scope fails closed with
no authenticated user. A CLI reading zero rows was fixed by *naming the user explicitly*,
not by relaxing the scope.

**Fix causes, not error messages.** `firstOrNew` leaves DB defaulted columns null, so seed
the counters rather than papering over the failure in the caller.

**Severity is a gate.** **S** items are structural: wrong now, expensive later. No UI work
before S items land. H, M and L are features and can be reordered freely.

**Ask with options and a recommendation.** For any decision with real trade offs, present
concrete choices with the trade off named, and say which one is recommended and why. The
user decides.

**Write like a person, not a press release.** No em dashes. They are the clearest single
tell that a machine wrote something, and this project should not read like one. Use a comma,
a colon, a full stop, or split the sentence into two. If a thought needs a dash to survive,
it probably needs to be two sentences.

The same applies to the other habits that make text read as generated:

- "It's not just X, it's Y" and every variation of that shape
- Announcing what you are about to do before doing it
- Stacking three adjectives where one would do
- Bullet lists of three where a sentence would be better
- Words like robust, seamless, leverage, delve, crucial, comprehensive
- Bold text used for emphasis rather than headings
- Finishing every section with a neat one line summary

Say the thing, then stop. If something needs explaining, give the actual reason rather than
framing it as a revelation.

**Code comments earn their place or go.** Explain *why* a decision was made when it is not
obvious, especially where a number looks arbitrary or a workaround looks unnecessary. Stay
silent when the code already says it. No rhetoric in docblocks.

Run `php ..\.tools/suwayomi/scan-dashes.php` before calling prose done. It counts em dashes, en
dashes and ellipses byte by byte. An earlier PowerShell version reported zero em dashes in a
file that had thirty, so trust the byte scan.

**Lint new files.** Parse errors do not fail the test suite when nothing loads the file. Run
`php -l` across `app/` and `tests/` after writing code.

---

## 7. Conventions

- **Laravel 13 attribute style**: `#[Fillable([...])]`, `#[Hidden([...])]`, and a `casts()`
  method. Match the existing `User` model.
- **User owned models extend `OwnedModel`**, which gives a fail closed global scope plus
  `forUser()`, `createFor()`, `withoutUserScope()` and `forAllUsers()`.
  **Global tables extend `Model`**: `sources`, `extension_stores`, `source_healths`, and two that are easy to forget because they read like per-reader data: `source_preferences` (a cached snapshot of a source filter list, shared by every reader) and `anilist_media` (a shared title cache keyed by normalised title). These
  are server truth, not user truth.
- **Do not use a trait for tenancy.** PHPStan cannot reason about the model instance inside
  a trait's `creating` closure, which produced 76 errors. `OwnedModel` is an abstract class
  for that reason.
- `user_id` is **never** in `#[Fillable]`. Use `createFor($user, [...])` to write for
  another user. Mass assigning it would let request input write rows for someone else.
- **Suwayomi integration** lives in `app/Suwayomi/`, **search logic** in `app/Search/`,
  commands in `app/Console/Commands/`, and Livewire pages in `resources/views/pages/`.
- **Service split.** `SourceCatalog` mirrors what the server says is registered, so every
  write to `extension_stores` and `sources` happens there. `ExtensionManager` owns the
  catalogue and the per-user decision to install from it. When one needs the other, the
  caller composes them. Duplicating a mirror method across the two is how they drift.
- **Tests**: `tests/Unit` for pure logic, `tests/Feature` for anything touching the DB. Name
  tests for the behaviour, not the method.
- **Shared test fixtures** go in `tests/Support/`. `MintsTokens` exists because a
  `Http::fake()` that returns the string `"token-1"` exercises the wrong path: the real
  server issues a JWT and `AuthManager` reads `exp` from it. A token with no readable
  expiry is correctly refused, which quietly makes such a fake useless.
- Docblocks carry the *why*, especially where a decision looks odd. A future reader needs
  to know that a 20 second timeout sits under a 30 second server cap, and that 0.95 came
  from a measurement.

---

## 8. Quality gates

All four must pass before work is done, from `papyrus\`:

```bash
vendor/bin/pint
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
php artisan test
php artisan view:clear        # after editing any .blade.php, or a stale compiled view lies to you
```

`composer test` runs four steps: config:clear, pint --parallel --test, phpstan and artisan
test. Note that phpstan needs `--memory-limit=1G`, because the default 128M crashes on
parallel workers, and that the composer script does **not** pass it, so run phpstan by hand
when the parallel workers die.

A fifth gate is not a command: **open the page.** Between 194 passing tests and a
browser, three separate bugs turned up that the suite could not see, including an
Alpine error on every button of the source manager. See trap 28.

The reader added a sixth: **a browser that produces frames.** The in-app panel renders
nothing, so it cannot verify anything driven by the rendering lifecycle, and three of
the reader's bugs lived exactly there (traps 45 to 47). When the page under test
depends on scroll, animation or intersection, Playwright is the gate and the panel is
not. When a browser question has consumed two guesses, set `document.title` from inside
the suspect function and read it back with a snapshot: one round trip beats an
argument about what the code must be doing.

Current standing: **1,148 tests**, 2,540 assertions, Pint clean, PHPStan
level 7 clean, zero em dashes. Plus 39 checks in `verify-reader.py`, 30 in
`verify-navigator.py`, 33 in `verify-notes.py`, 20 in `verify-covers.py`, 18 in
`verify-library.py`, 18 in `verify-downloads.py`, 21 in `verify-migrate.py`, 14 in
`verify-search-anilist.py`, 21 in `verify-library-check.py` and 17 in
`verify-continue-reading.py` and 21 in `verify-browse.py`, all of which need a browser that produces frames. The
October 2026 feature pass added 21 checks in `verify-october-pass.mjs` over the search
filters, the result-card library toggle, the read toggles, the updates view and the
connections page.

`verify-cover-reveal.mjs` is the gate for trap 151 and the only one that runs on the **login**
wall rather than behind a sign-in. Ten checks across five surfaces, because the cover atom is
shared and a reveal can break on one of them without breaking the others: it asks each image
whether it is `complete`, whether it has `naturalWidth`, and whether it carries `is-loaded`,
and fails on any image that has artwork and no reveal. Ten is not the point. The point is that
the count is compared against what the page rendered, so an empty result cannot pass.

`verify-incognito.mjs` is the gate for trap 152, and the only one that is destructive. It
presses the control, reads a chapter, and presses the reverse in a `finally`, because a gate
that dies between the write and the undo leaves the reader's preferences changed and every
later gate then measures a box it did not set up. `probe-incognito-state.php` exists to answer
the question that leaves behind: is anything actually suppressed right now. It reported zero,
which is the only acceptable answer.

`verify-rating-tags.mjs` is the gate for trap 153, 33 checks, and destructive in the same way.
It asserts the star fill by reading each star's computed `clip-path` rather than by looking at
it, because a screenshot of a star row says the colour is vermilion and nothing about how much
of each star is filled. Two checks exist only because the first version of the gate could pass
on nothing: `mustReadStars()` fails loudly when the row is absent, because `every()` over an
empty array is `true`. It also reports what its own cleanup could not undo, and counts that as
a failure.

`verify-cbz-export.mjs` is the gate for trap 154, 17 checks. It reads the central directory
of the archive **the browser downloaded**, with a reader written inside the gate that shares
no code with the writer, so the check is not the server agreeing with itself. It also stops
outright if it cannot seed its own finished download, which it can: the first version printed
that failure and then passed every remaining check on a row a manual run had left behind.
Seeding goes through `shell: true` because Node refuses to spawn a `.bat` directly, which is
the same Windows detail as the Python launcher.

### Running the gates

Three processes have to be up, and their absence has its own signature each time:

| Process | Command | If it is missing |
| --- | --- | --- |
| Suwayomi | `java -jar Suwayomi-Server-v2.4.2366.jar` in `the Suwayomi data root` | Every chapter list is empty and every reader is a 502. Data root missing entirely is a different thing from stopped, check for `Tachidesk` before assuming the process died. |
| Vite | `npm run dev` (which is `vp dev`) | Every page renders with no styles, and the app logo SVG stretches to fill the viewport and swallows clicks. Trap 105. |
| Queue | `php artisan queue:work --timeout=300`, **one only** | `SyncDownloads` and `CheckLibrary` chain through the queue, so a library check pass silently stalls partway and the gate reports the feature as broken. A pile-up also produces `database is locked` in SQLite. |

---

## 9. Commands

```bash
php artisan suwayomi:check              # connectivity, auth mode, version, stores, drift
php artisan suwayomi:schema:dump        # introspect and commit a snapshot, with a field diff
php artisan sources:sync                # mirror stores and the source catalogue
php artisan sources:sync --add-store=<index.pb url>
php artisan sources:sync --refresh      # re-read every store first
php artisan search:fanout "berserk" --lang=en --limit=6
php artisan search:fanout "one piece" --limit=100 --include-dead
```

`search:fanout` forces the sync queue driver. It is a diagnostic, and a job silently waiting
in a queue tells you nothing.

All of these except the last assume Suwayomi is already running and reachable.

### Verification scripts

`..\.tools\suwayomi\`, one level above this repository, holds the throwaway scripts that
produced the measurements in this document. They are not part of the application and nothing
depends on them. They are deliberately outside the repository: several carry the credentials
of a local seed account.

| Script | What it answers |
|---|---|
| `suwayomi:check` (command) | Is the server up, which auth mode, any schema drift |
| `schema-inspect.php <Type...>` | Field list for named GraphQL types |
| `field-args.php <Owner> <field...>` | Arguments on a query or mutation field |
| `measure-token-ttl.php` | How long an access token actually lives |
| `measure-refresh-token.php` | How long a refresh token lives, and whether it rotates |
| `diag-expired-token.php` | The verbatim body an expired token produces |
| `verify-auth-recovery.php` | Which of the three auth recovery paths runs |
| `inspect-source-families.php` | How many real sites sit behind the source list |
| `inspect-health.php` | The distribution of states in the health ledger |
| `verify-site-rollup.php` | Tiles and rows agree, per source and per site |
| `test-extension-manager.php` | Server-side filtering, against the live server |
| `verify-sources-page.php` | Everything the `/sources` page computes, read only |
| `scan-dashes.php <file...>` | Byte scan for em dashes, en dashes, ellipses |
| `scan-source-dashes.php` | The same across `app/`, `config/`, `routes/`, `tests/` |
| `verify-reader.py` | The reader end to end, in a browser that produces frames |
| `verify-library.py` | The library page, including that the grid is actually styled |
| `verify-downloads.py` | A real download, driven by the queue worker, and the pages reading from disk |
| `verify-migrate.py` | A real search across 81 sources, the ranking, a real migration, and putting it back |
| `verify-browse.py` | The `/sources` site picker groups 82 sources into 16, that MangaDex is one group of 61 rather than 61 rows, and that `/browse/{source}` renders covers for both tabs, files to a shelf and pages. Written before the templates, and it is what caught the raw upstream cover URLs and the `Library::attach()` id mismatch |
| `verify-search-anilist.py` | The opt-in switch, that it starts off, and that it survives a reload |
| `verify-continue-reading.py` | That a feed card opens the reader at the right chapter, and that reading moves it |
| `seed-continue-reading.php` | Marks chapters read for the gate, with real ids read out of the batch |
| `verify-library-check.py` | The check button, the floor copy, and a real whole-library pass in the browser. Polls by reload rather than sleeping, and reads the series count from the button's own label |
| `verify-navigator.py` | The chapter strip's three fill states, that it does not cover the reader's controls, and cross-chapter seek |
| `verify-notes.py` | The reader's note panel and the series page's marker and inline editor, that they write the same row, and that nothing in an open panel is hidden under a corner control |
| `probe-search-input.php` | What one `fetchSourceManga` can carry, and whether a search filter can be pushed or only read, which decided H6 |
| `probe-source-filters.php` | How many of the 82 sources offer filters, and whether any of them offers an author. Measured: all do, 3 of 14 sampled do, MangaDex does not |
| `probe-local-source-now.php` | The local source's id and language, what it is holding, and whether the folder is readable from this machine |
| `probe-local-rescan.php` | Which `FetchSourceMangaType` re-reads the folder, and whether a rescan works at all. Measured: all three answer, LATEST at 15ms |
| `probe-series-state-columns.php` | Which `series_states` columns need a value, because a row that silently fails to be created makes every later assertion read as a broken feature |
| `verify-covers.py` | A real upload through the browser, that the bytes come back bounded at 800px, that removing it restores the source's cover, and that the route 404s once the row is gone |
| `probe-cover-sizes.php` | What source covers actually weigh and at what size, which is what makes the 800px bound a measurement rather than a taste decision |
| `probe-series-state-columns.php` | Which `series_states` columns need a value, because a row that silently fails to be created makes every later assertion read as a broken feature |
| `probe-notes.php` | What a note has to sit on, and that a note-created `chapter_states` row moves no count |
| `probe-navigator-data.php` | What share of chapters carry a `pageCount`, which is what forces the navigator's three fill states |
| `probe-suwayomi-health.php` | Whether a gate failure is the parsing layer being down, separated from Papyrus |
| `probe-shelf-state.php` | How many series sit on one shelf and how many on every shelf, which decides whether the library gate has a candidate |
| `probe-failed-jobs.php` | What the failed jobs were and which feature each belongs to, so a gate that stalls is not blamed on the wrong thing |
| `probe-image-formats.php` | Whether `serveConversions` exists and what the bytes weigh, which closed H16 |
| `probe-continue-reading.php` | Whether the feed can be resolved from the existing batch, and what the alternative costs |
| `live-continue-reading.php` | A real batch, a real resolution, and that marking chapters read moves it |
| `live-library-check.php` | A real pass: what was due, what it found, what the page now says, and whether the fingerprints agree |
| `reset-library-check-state.php` | Puts the library back to never-checked, which the check gate needs to exercise both states |
| `probe-unread-count.php` | Whether AniList's chapter count could replace fetching, and the answer |
| `probe-library-check-cost.php` | What a re-check costs against a first fetch, and whether concurrency is worth it |
| `probe-anilist.php` | Whether AniList answers without a key, and what it returns |
| `probe-anilist-grouping.php` | Whether grouping by AniList id merges the right things on a real fan-out |
| `live-anilist-check.php` | Real lookups, the grouping difference they make, and that a failure caches nothing |
| `undo-migration.php <to> <from>` | Reverses a migration through the app's own mapper and state mover |
| `reset-migration-state.php` | Clears search rows and migration records so a run starts clean |
| `verify-october-pass.mjs` | The October 2026 feature pass end to end: named language pills, the result-card library toggle, the read toggles, the updates view, the connections page. A Node port of the `uv`-run convention, because this machine lost its Python toolchain; run with `node verify-october-pass.mjs` from `.tools/suwayomi`, which holds the `playwright` npm package |
| `shot-overhaul.mjs` | Full-page screenshots of the design overhaul's surfaces: the Reading Room logged out (desktop and mobile), library, series, search, downloads, health, sources, appearance, the reader and a 404, written to `.tools/suwayomi/shots/`. A screenshot pass, not a gate: it asserts nothing, it is the pixels a design change is judged on |
| `verify-cover-reveal.mjs` | Every cover on every surface reaches its reveal: the login wall, the library grid, the series hero, the browse grid across four scrolls and the search grid while it streams. Fails on any image with `naturalWidth > 0` and no `is-loaded`, which is the shape trap 151 shipped. Trap 151 |
| `verify-browse-failure.mjs [sites]` | That a browse of the sites named in the report says what is true instead of printing a JVM stack trace. Art Lapsa answered a non-null `source` field resolving to null and Asura Scans a `SourceMutation.kt:264` NPE; both now go through the registry check, and the gate fails on any callout matching `/kotlin\|NullPointerException/`. Goes back to `/sources` before every lookup, because the first run looked for the second site on the first site's page and reported it missing |
| `probe-refresh-toast.mjs [manga]` | That pressing Refresh chapters on a series whose source cannot answer produces a rendered sentence rather than a 500, and names any failing request rather than counting console errors. Every 500 the browser saw, by status, resource type and path |
| `probe-picker-links.mjs` | Every browse link the source picker actually renders, with its text. Written because a gate reported a site missing from the picker and the answer was the gate's own locator |
| `probe-showcase-concurrent.php [ids...]` | Nineteen showcase covers asked at once with curl_multi, which is what a browser does and what a loop cannot. 1 of 19 failed before the limiter came off the cover route, 0 of 19 after. The loop version returned 16 of 16 and proved nothing |
| `probe-showcase-load.mjs` | The same thing through a real browser, four rounds with a fresh context each so the cache cannot hide a repeat. Named for the login wall rather than the route, because the wall is what asks for eighteen covers at once |
| `probe-throttle-still-works.php` | That 130 requests to `/showcase` give 120 served and 10 limited. Written because the fix for a 500 was to move a rate limiter off a write, and a limiter that stops limiting is worse than a blank cover. 140 of 140 served is the shape of that failure |
| `probe-throttle-cause.php` | Whether a throttled route or an unthrottled one is what fails under concurrency, which is what placed the blame on the limiter rather than on Suwayomi, the token lock or the proxy |
| `probe-blank-covers.mjs` | Which covers are blank and what the server answered for each, by name. `is-loaded` is added on `error` as well as on `load`, so a failed request counts as resolved and a tally of "hidden" is 0 while a screenshot shows six empty cards |
| `probe-cache-keys.php` | What is in the cache table and which key is being incremented. The 500's trace named a digest, and neither `suwayomi.tokens` nor its lock hashes to it, which is what ruled out the token path and pointed at the limiter |
| `probe-blank-vs-hidden.mjs` | Whether the covers are painted, asked two ways: the element state, and two full-page captures of the same scroll position, one before and one after a walk down the grid. It is how the screenshot artefact was told apart from a real defect, and it is why `verify-cover-reveal.mjs` asserts on element state |
| `probe-blank-pixels.mjs` | Whether a cover's own screenshot has any variance in it, which separates a flat fill from artwork without a human looking. The only check that can settle "the frame is empty" when every DOM reading says otherwise |
| `probe-painted.mjs` | One cover described fully: `src` against `currentSrc`, natural size, box size, opacity, filter, z-index, and what else lives in the frame to paint over it. The underlay and scrim answers are trap 148's shape asked as a question |
| `probe-cover-artwork.mjs` | Whether a resolved cover has artwork in it, which `is-loaded` cannot say because it is also added on `error`. Separates resolved-but-blank from resolved-and-drawn, and prints the response status and content type for every image on the page |
| `probe-series-cost.php` | What one series page actually costs the server: every library series with its chapter count, status and `manga(id:)` latency. The answer is that the read is 13ms to 167ms, so a slow series page is never the read |
| `probe-series-chapters.php [ids] [--fetch]` | Whether a zero-chapter series is waiting on us or on its source, and what a live `fetchMangaAndChapters` makes of it. `--fetch` is opt-in because it is a write costing 1.6s to 7.1s |
| `probe-no-chapters-reason.php` | What the ledger recorded for every zero-chapter series, beside what the page tells the reader. This is the gap trap 151's second half closed: 4 of 8 were recorded as `Cloudflare bypass currently disabled` and all 8 were reported as having no chapters |
| `probe-registry-drift.php` | What the server registers against what the mirror holds, in both directions, writing nothing. The safe way to look while `SourceCatalog::syncSources()` is suspect, since running it marks whatever it did not see as uninstalled. Trap 145 and 14.5 |
| `probe-downloads.php` | Whether the download queue can be enqueued, watched and dequeued at all |
| `probe-download-progress.php` | Whether `progress` moves at all, and at what resolution |
| `live-download-check.php` | That the reconciler chain works when only a queue worker is running |
| `live-migration-check.php` | That the search completes through a real queue and what it ranks |
| `probe-job-lifecycle.php` | Row, queue depth and worker log watched together, which is what separated a wedged worker from a probe that never dispatched |
| `probe-migration-cost.php` | What the search and the per-site fetches cost, and what it ranks |
| `probe-migration-match.php` | How well a series can be matched across sources, and by which signals |
| `probe-migration-chapters.php` | Whether read state survives, per key: number, order, label |
| `probe-migration-timing.php` | Whether a search belongs in a request or behind a job |
| `where-to-download.php` | Which live series has unread chapters worth downloading |
| `probe-library-batch.php` | Whether a library page can fetch its rows in one call |
| `probe-library-unread.php` | What the library needs to compute an unread count |
| `seed-library.php` | Fill the dev library from whatever the live server holds |

Three are worth keeping for future work. `inspect-source-families.php` re-measures the
site grouping the dashboard depends on, `verify-site-rollup.php` is the cross check
that the tiles never disagree with the rows, and `verify-reader.py` is the only way to
check the reader's half that depends on the browser delivering events (trap 45).
`live-download-check.php` earns a place for a different reason: it is the only check that
proves the reconciler moves against a live server, and the chain it exercises was broken
by an absent `schedule:work` (trap 65) while every test still passed.

`verify-reader.py` runs under `uv`, since there is no system Python:

```bash
uv run --python 3.12 --with playwright python ..\.tools\suwayomi\verify-reader.py
```

---

## 10. Running it

Suwayomi runs as a normal Windows process, never a Windows Service, because JCEF needs an
interactive session. `docs/SETUP-SUWAYOMI.md` has the install, the checksum and the config.

Then two processes beyond Herd, both from `papyrus\`:

```bash
npm run dev                # without this, new Tailwind classes are not in the CSS
php artisan queue:work --timeout=300     # see below
```

The search page detects a stalled run and says so, rather than spinning forever.

`php artisan schedule:work` is **not** required, and that is a decision rather than an
oversight. The ten-second `SyncDownloads` schedule is the safety net for after a restart, but
the job books its own next pass through the queue, so the worker above is enough. Relying on
the schedule alone is what left the ledger frozen on a box where only `queue:work` was
running, with every test still green (trap 65).

**`--timeout=300` is not optional.** (`composer dev` does not honour this: it runs
  `queue:listen --tries=1 --timeout=0`, so start the worker by hand if you want the rule to
  hold.) The migration search measured 27.6s on the worker, and
the default `60` kills it, because a job's own `timeout` does not raise the worker's limit and
`failOnTimeout` defaults to false, so the job vanishes with no record (trap 70). Raise it, and
keep `failOnTimeout = true` on the job so a run that does exceed it says so.

Then `http://papyrus.test`, signed in as `reader@example.test` / `secret1234`.

Session cookies are per hostname, so a rename to the Herd site name logs you out of
every existing tab. That is expected, not a fault.

---

## 11. State

| Phase | Name | Status |
|---|---|---|
| 0 | Foundation | **Complete.** Tenancy, side tables, Suwayomi transport, image proxy, schema tooling. |
| 1 | Discovery | **Engine and search UI complete.** Fan out, dedupe, health ledger, filter translation, sync commands, and the search page at `/search`. All verified live. |
| 1 | Auth | **Complete.** `Jwt` reads the expiry from the token, `AuthManager` renews with the 60 day refresh token under a lock, and `Gateway` recovers from the HTTP 200 auth error. All three paths verified live. |
| 1 | `ExtensionManager` | **Complete.** Server-side catalogue filtering, install/uninstall/update, bulk install with per-package failures, and the per-user trust decision. 17 tests, verified live. |
| 1 | Source manager UI | **Complete** at `/sources`: stores with full signing keys, trust flow, tabbed catalogue, bulk install, paging. 24 tests, and confirmed in a browser, which is how two test-passing bugs were found. |
| 1 | Health dashboard | **Complete** at `/health`, grouped by site because 82 sources are only 15. Worst-wins rollup, drill-down to language variants, per-user skip wired to the search fan-out. 19 tests. |
| 1 | Fan-out shape | **Investigated and closed.** Site collapse would have lost 35% of results, so the fan-out still queries all 82 (as counted on 2026-10-01). Measured, not assumed: see the decision table. |
| 2 | Read path | **Complete.** `/series/{manga}`, `/read/{manga}/{chapter}`, the progress endpoint and the per series settings endpoint. `SeriesGateway`, `ReadingState`, `ImageUrl` and `ReaderSettings` behind them. Reader is a plain document on purpose: measured 2.0s for `fetchChapterPages`, so it runs once and page turns cost nothing. |
| 2 | Reader modes | **Complete.** Continuous vertical and paged in one document, sharing the same URLs so switching costs no request. Taps turn pages in paged mode and fall through to the chapter at the first and last page, with the accessible name following. Page tracking is a scroll listener rather than an observer, measured (trap 45). Verified end to end in Playwright. |
| 2 | Reader settings | **Complete.** `ReaderSettings` resolves defaults, then the user's default profile, then the series columns, and clamps at the end. Resolution and clamping are server side so the browser renders values rather than validating them. 20 unit tests plus feature coverage. |
| 2 | Reader settings UI | **Complete.** A panel for mode, width, gap, zoom, background and the two layout behaviours, writing through one debounced PUT. 31 checks in `verify-reader.py` cover it including that a setting survives a reload and that the panel reopens showing what is stored. |

| 3 | Library | **Complete** at `/library`. Categories as a many-to-many, per-series reader settings, unread computed from `chapters.totalCount` minus our own read rows, and a chapter list fingerprint so a changed list is flagged rather than silently miscounted. 54 tests plus 15 checks in `verify-library.py`. |
| 4 | Downloads | **Complete** at `/downloads`, plus a per chapter button and a per series "download unread" on the series page. A `downloads` ledger per reader, because Suwayomi keeps nothing: the queue is only what is happening now, and `ERROR` arrives with no reason. Reconciled in **two calls per pass** (the queue for live progress, the library's bulk `mangas` call for `isDownloaded`), since finished and failed look identical from outside the queue. The shared downloader is never surfaced as a control: enqueueing already starts it and it stops itself when the queue empties. 52 tests plus 18 checks in `verify-downloads.py`. |
| 5 | Source migration | **Complete** per series, at `/migrate/{manga}`. A candidate search behind a job, ranked on the measured signals rather than a blended score, a chapter mapper that keys on `chapterNumber` because that is the only key two sources agree on, and a transactional move that merges rather than overwrites when the destination is already in the library. 52 tests plus 20 checks in `verify-migrate.py`, which migrates for real and then puts the library back. **Mass migration is not built**, deliberately: it is a loop over this flow plus a picker. |
| 6 | AniList matching | **Complete**, as the cross-language dedupe key the plan called M3. Suwayomi's own tracker is unusable (`trackRecords` is empty without a reader login), so the lookup goes to AniList's keyless public API. Per-reader opt-in, **off by default**, because a lookup sends a title to a third party and on a self-hosted box that is the deployer's reading habits leaving the machine. Dedupe keys on the id when cached and on the title otherwise, so a miss is exactly the pre-AniList behaviour. `isAdult` is recorded beside the source's rating and never overrides it. 37 tests plus 14 checks in `verify-search-anilist.py` and a live check. |
| 6 | Library checks | **Complete**, and it is what makes two dead columns real. A re-read of a series is `fetchMangaAndChapters`, and it is the only honest source of a chapter count. `CheckLedger::check()` is the one code path, called inline by a per-card button (1099ms measured live) and in slices by a self-chaining job. The floor is `series_states.fetch_interval_days`, present since migration 3 with nothing reading it. `chapter_list_checked_at` and `chapter_list_fingerprint` were both **always null before this**, so the library's staleness badge could not fire at all: measured 0 of 14 fingerprinted. A check writes both. |
| 6 | Continue reading | **Complete**, and it is the cheapest feature in the project: a local join over the batch the library page already makes. Measured **1ms** for 13 series against the batch's 257ms, where the obvious per-series implementation is 3.5 to 4.4 seconds. Ordered by last read, each card links straight to the next unread chapter, and a fully read series leaves the feed rather than being offered chapter one. Respects the shelf and title filters, because a page filtered to one shelf must not offer a series outside it. |
| 6 | Chapter navigator | **H13 done.** Boundary ticks, a read map and cross-chapter seek turned out to be one thing: a segment per chapter in source order, filled by how much of it is read, clickable to jump. The fill has **three states and not two**, because measured only **12 of 575 chapters carry a `pageCount` (2.1%)** and `pageCount` is `-1` until a chapter's pages have been fetched. So `read` needs no denominator, `partial` needs both sides, and `unknown` is most of a real library. Two states would render `partial` and `unknown` identically, and a strip of 107 empty segments reads as broken when it is telling the truth, so a legend states the three. Costs one extra batch call (`fetchManyWithPages`, flat at 246ms for 300 ids), and it is tolerant: an unreachable server costs the scrubber and nothing else. 24 tests, 30 checks in `verify-navigator.py`. |
| 6 | Chapter notes | **H18 done, and the storage predates the feature.** Migration 5 already made a polymorphic `notes` table, `Note` has existed since, and `ChapterState::notes()` was declared and never called: measured **0 rows**. So this is a write path and two surfaces, not a schema. **A note may sit on a chapter nobody has read**, which is the useful case ("read this one first"): it needs a `chapter_states` row, and measured such a row moves the read count by **0**, because the read count, the continue-reading feed and unread all filter `chapter_states` on `is_read`. The chapter navigator is the one consumer that sees every row and it reads such a row as `unknown`, which is correct. **One note per chapter as a database fact** (migration 22, unique index, and safe because there were 0 rows to merge). One PUT: an empty body clears, because clearing and typing are the same gesture. 42 tests, 33 checks in `verify-notes.py`. |
| 6 | Custom covers | **H5 done, and the storage predates the feature.** Migration 6 made `series_covers` and `SeriesState::cover()` has been declared since: measured **0 rows**. Suwayomi cannot hold a cover at all, `ImageMutation` exposing only `clearCachedImages` and `UpdateMangaPatchInput` only `inLibrary`, so the file and the route are ours. **The 800px bound is measured, not chosen**: source covers are at most 800px on the long edge and weigh 77KB to 193KB, and the grid slot is 173px, so a custom cover resized to match is indistinguishable from a proxied one. **The 12 megapixel ceiling is memory arithmetic**, four bytes a pixel against a 128M `memory_limit`, and it is the guard that matters because 2MB of PNG can be 20000 by 20000. Served by a **Laravel route rather than the public disk**, because covers are per reader and this is multi-tenant from migration 1; and because source covers are *already* served through a Laravel route (`suwayomi.proxy`), so it is no new cost class. Applied on picking a file rather than behind a Save button, since Livewire already shows a real progress bar. 40 tests, 20 checks in `verify-covers.py`. |
| 6 | Local source | **H15 done, and scoped down to what is true.** Suwayomi has **no upload API at all**: measured, every GET on `/api/v1/...` answers with the 4,484-byte WebUI catch-all and every other method a 404. A drop zone would mean Laravel writing into Suwayomi's data directory, and the folder is shared by every reader because the source cannot scope it. So H15 **reads and rescans** and says why it does not upload. The pairing is the feature: the folder and the server's listing **disagree and stay disagreeing**, because there is **no delete mutation**, so measured manga 2899 is listed while the only file left is its `ComicInfo.xml`. Showing either side alone would be lying about the other. The rescan is `fetchSourceManga(source: "0", type: LATEST)`, measured at 15ms; all three types answer and return the same listing, and LATEST is the one whose meaning is "what is new here". One folder per series is the shape, read from what Suwayomi actually picked up. 31 tests. |
| 6 | Parity | **H11, H10, H13, H18, H5 and H15 done.** The five layers resolve in order, with the home shelf as the third and the session as the fifth, and a null in any layer falls through rather than erasing what is below. Colour filters (brightness including the sub-1.0 dim range, contrast, hue, invert, grayscale) are **session-scoped by default**, because a reader dimming for a dark room does not want that at a lit desk tomorrow; "remember for this series" is the explicit way to make it durable. **H6 shipped**, with three terms rather than four: measured across all 82 sources, `genre` reaches 77, `status` 74 and `sort` 69. `author:` was cut because only 15 of 82 offer an author filter and, measured over 704 search rows from 76 sources, only 10.4% carry an author while `status` reads `UNKNOWN` on 99%, so there is nothing to check a term against afterwards. A term is pushed where the source has a matching filter and the source is skipped and counted where it does not. 57 tests, 28 checks in `verify-search-syntax.py`. Still open: **H7** bulk actions. **H16 is closed by measurement**: `serveConversions` is not in the live schema and all three formats return byte-identical `image/webp`. |
| 7 | Search results to library | **Complete.** Every result card carries a library toggle, over the cover's lower corner, with the state batch-loaded for the whole result set in one query and the write going through the same `ReadingState::setInLibrary` path the series page uses. The toggle sits outside the anchor, because a button inside a link navigates instead of toggling. Language filters are pills carrying human names (`Portuguese (Brazil)`, not `pt-BR` (2)), resolved through `App\Support\LanguageName` with an explicit map for every code seen on this box, intl's display name second, and the raw code last, because intl answers a well-formed tag it cannot name with "qq (Unknown Region)". The submit button moved beside the search box, which is where the gesture was anyway. |
| 7 | Extension updates | **Complete.** The Updates view's rows carry an Update button, and the view header carries Update all, both gated on trust exactly as install is: an update is a new signed artefact about to execute. `updateAll` asks the server for the full out-of-date list rather than trusting the page's fifty rows, and failures are collected per package and rendered inline. Pressing Update had no control anywhere before this, so the only route to the badge was Suwayomi's own WebUI. |
| 7 | Manual read marking | **Complete.** The dot on each chapter row is a toggle, and the chapter toolbar carries Mark all read and Mark all unread. The bulk path is an upsert whose inserted rows carry the end state (trap 123), and it touches only the read flag, so a page position survives being marked unread and back. Marking read moves the continue-reading pointer forward to the furthest chapter in source order, whatever direction the list is displayed in; Mark all unread clears the pointer with the flags, because a pointer onto an unread chapter would have continue-reading offer the middle of a series the reader says they have not started. |
| 7 | AniList tracking | **Complete, opt-in by connecting.** A reader who connects at `/settings/connections` hands the app an OAuth token that acts as them on AniList, and from then on a chapter finishing (by reading or by being marked read) pushes that series' progress: the largest chapter number among read chapters, floored, set CURRENT, or COMPLETED when AniList's own chapter count says it is. The join runs on the same title-keyed cache the search dedupe uses, and a title AniList has never heard of is recorded as answered-no and never asked again. The push is a queued job with a per-series pending marker, so a continuous reading session books one job rather than one per progress ping. AniList issues no refresh token, so a rejected token deletes the connection and the reader connects again. The deployer must create their own application at anilist.co/settings/developer and set `ANILIST_OAUTH_CLIENT_ID` / `ANILIST_OAUTH_CLIENT_SECRET`, with a redirect URI matching `anilist.callback` byte for byte; until then the page says what is missing instead of offering a dead button. |
| 7 | AniList import | **Complete, and verified the hard way.** The other direction: a card on the same page pulls the progress AniList already holds into the series in the library. It marks chapters **read** and never unread, adds nothing to the library, leaves a series AniList does not know alone and lists it as unmatched, and runs ten series a pass as a self-re-booking chain because title lookups are rate-limited to 30 a minute. It shipped broken and 32 green tests sat over it: AniList answers an unmatched search with **HTTP 404**, which the client read as a rate-limit refusal, so a library whose first title is not on AniList never got past it. Trap 130, and trap 131 for why the tests missed it. Verified against the live API afterwards: 20 of 20 series processed, 4 unmatched, 0 marked read, which is the correct answer for a library of isekai and doujinshi titles. |. That is a weaker claim than every other row here, and the seven defects in 14.6 include two in this feature. |
| 7 | Bulk library actions | **H7 done, after being broken.** Selection mode, shift-click range select, and a fixed bulk bar: file to a shelf, unfile, mark read, mark unread, remove from the library. Shipped unusable, because the checkboxes used a deferred `wire:model` and the bar is gated on a server-side count, so ticking a box committed nothing and the bar never appeared. Twelve tests passed over it; see trap 126 and 14.6. |
| 1 | Fan-out slice | **Closed, after a policy nobody chose.** The 40-of-82 cap was applied to an alphabetical sort, so MangaFire (7 sources), MangaTaro, Thunder Scans and Weeb Central were never dispatched. The slice is now a round robin over `extension_pkg_name`: every site's first source, then every site's second, capped as before. Run 88 dispatched exactly 40 and reached **15 of the 16 installed packages**, with 20 non-MangaDex against 10. Trap 132. |
| 7 | Series auto-fetch | **Done, and it was one bug wearing two faces.** `SeriesGateway::find()` reads Suwayomi's cache and `fetchMangaAndChapters` fills it, so a series reached from search or browse had never been fetched: no chapters, status UNKNOWN, and the Refresh button as the only way out, with 19 of 20 titles on a source in that state. The series page now books a fetch on mount and polls while it runs. **Two calls, because the costs differ twentyfold**: `fetchManga` alone for a series whose only gap is its status, a flat 186ms to 555ms measured, and `fetchMangaAndChapters` for one with no chapters, 1,642ms to 7,142ms and scaling with the chapter count. One claim per reader, so a second series never queues behind the first, and nothing prefetches the grid: there is one worker, so a grid prefetch would put twenty requests in front of the series the reader actually clicked. Trap 134. |
| 7 | Browse infinite scroll | **Done.** An IntersectionObserver keyed on the page counter, firing a viewport-height early so the next page is there before the reader reaches it. `hasNextPage` was already selected in the mutation and thrown away, and it is the only honest end-of-scroll signal: Asura Scans answers true four pages deep and Akaza Scans answers false on its first, so "fewer rows than last time" cannot tell a finished source from an odd one. Rows are no longer sliced to `results_per_source`, which was discarding 35 of MangaFire's 50 and 41 of Manga Demon's 56 on every scroll. Trap 137. | **It is kept honest about what it is missing.** The picker reads the local `Source` mirror, and installing an extension only tells the server, so the list was two sources behind after two extensions were added, with nothing on screen saying so. Mirroring now happens after Papyrus's own installs and uninstalls, which is `syncSources()` at a measured 125ms rather than `syncAll()` at 1,030ms because a store's index has not moved. That half cannot reach an extension installed through Suwayomi's own interface, so the page also compares the live installed-extension list it already fetches against the mirror and names what is absent, with the existing sync offered right there: 29.8ms against 185ms for asking the server instead, because the extension list is a filtered seventeen rows and it says which rather than how many. A failed mirror is reported as its own fact and never as a failed install, and the notice is empty when the server cannot be asked. Traps 145 and 146. |
| 7 | Search within a source | **Done.** `fetchSourceManga` takes a `SEARCH` type, so searching the one source a reader is already in was always available and only the input was missing. A link to the global search would be the wrong door twice over: that search covers 40 of 82 sources chosen by a round robin, so four sites including MangaFire, MangaTaro, Thunder Scans and Weeb Central are not in it at all. Fires on submit rather than per keystroke, measured on Asura Scans: a term that matches answers in 211ms and one that matches nothing in 1,673ms. Reuses the browse scroll, so a search that is one page simply stops. |
| 7 | Source name links to its catalogue | **Done.** The series page showed the source as plain text with the id in the payload and no way to get back to everything that site lists. Resolved through the mirror on Suwayomi's id rather than on the name, because 61 of the 82 sources are named "MangaDex (...)" and a name lookup lands on an arbitrary one of them. Falls back to plain text when the source is not in the mirror, which happens when an extension is uninstalled while its series is still cached. |
| 7 | Downloads name their series | **Done.** The row read `Chapter {suwayomi_chapter_id}`, a database id, so a finished download read "Chapter 14430" and gave a reader nothing to recognise it by. The row already carried `suwayomi_manga_id`. Titles and chapter names come out of one batched `fetchMany()`, which the library page already uses and whose payload **already** carried `chapters { nodes { id name } }`: 200 rows at one `manga(id:)` each is about 15ms times 200 measured, and the batch is 246ms for 300 ids. The whole row is now the link, stretched with an `::after`, because a link that only covers its own words fails four times out of five clicks. |
| 7 | Fan-out concurrency | **Closed, after four hang paths.** `search.fanout_concurrency` bounds the window: pending source ids sit in the cache and each finishing job hands the next one to the batch. Verified in production conditions: five consecutive complete 40-of-40 runs returning 378 results each. |
| 7 | Source chooser | **Done.** A search card that turned up the same series on more than one source named the others under its title and then linked both the cover and the title to whichever one the deduplicator ranked first. The card was already telling the reader there were others, so the information existed and only the action was missing. Clicking such a card now opens a chooser listing every source with its own link and its own bookmark, because the two answers differ: a reader may want to read the site they trust and keep the copy on their shelf. A single-source card keeps its plain link, since a chooser over one entry is a dialog that can only be dismissed. **Three bugs, all found in the browser and all invisible to a feature test.** The dialog was opened by the click, which is a race with the round trip that fills it; the group was looked up by its primary, which a streaming search moves underneath the reader; and `libraryMap()` covered primaries only, so an alternative already in the library offered to add itself. Traps 139, 140 and 141. **The cover's bookmark was then taken off these cards entirely.** It used to sit on every card filing the primary, which put two bookmarks on one picture answering two different questions: one silently picked a source the reader had not chosen and never named, and the one in the chooser filed whichever source it sat beside. A reader who wanted Asura Scans and pressed the bookmark on the cover got Manga Demon, and nothing on screen said so. A card that needs a decision has no shortcut past the decision. Single-source cards keep theirs, because a button that files the only candidate is not a guess. The card's "+N sources" line is now the only thing telling the reader a choice exists, so it has to stay: a check that reads it off the button rather than the card passes on a card that has lost every name it had. The choice is not remembered between searches, since a stored preference needs a per-group key and group identity shifts as sources answer, so the key would have to be the manga id picked rather than anything about the group. |
| 7 | Design overhaul | **Complete.** "Second Pressing": the cover atom as one `x-cover.art` component with blur-up, glow, tilt and sheen; `x-page-header` on every page; glass material on panels and all Flux modals; a shared-element view transition from grid cover to series hero; `x-confirm` modals replacing the native `confirm()` dialogs whose buttons no test parses; the reader re-tokenised onto the app's oklch values with no architectural change; bento stat tiles on downloads and health; skeleton loading states; the Reading Room login built from the collection itself behind the public, single-mode gated `/showcase` endpoints, with a seeded ink garden on a fresh install; custom 404 and 500 pages. All pins held: 1,076 tests green, the October browser pass green, PHPStan level 7 clean, zero em dashes. Traps 147 to 149. |

Six pages are live at `/search`, `/sources`, `/health`, `/library`, `/downloads` and
`/series/{manga}`, plus the reader at `/read/{manga}/{chapter}` and four settings pages at
`/settings/profile`, `/settings/connections`, `/settings/appearance` and
`/settings/security`. `/migrate/{manga}` carries the migration wizard. `/dashboard` redirects to
`/library`: the route name survives because it is in the sidebar and in saved links, and a 404
for a reader with the app open is worse than a hop. `/` now redirects the same way (library
when signed in, login when not): the starter kit's Laravel marketing page was the one screen
in the app that said nothing about reading.

The interface was redesigned around one system, "Ink & Vermilion", and the tokens live in
`resources/css/app.css`: a zinc ramp re-pointed in oklch (warm paper in light, cool ink in
dark), one saturated vermilion accent (`--color-vermilion-*`, also the Flux `--color-accent`),
Bricolage Grotesque for display, Instrument Sans for the interface and an Instrument Serif
italic for the editorial moments. The cover card (`cover-frame`, `cover-scrim`,
`cover-progress`), the horizontal rail (`rail-scroll`) and the staggered entry
(`stagger-enter`) are the shared components every page builds from. The reader keeps its own
inline styles on purpose and shares only the accent values.

The "Second Pressing" pass (October 2026) deepened the system without replacing it. The cover
atom became one component, `x-cover.art` (frame, blur-up reveal, scrim, glow, progress), which
four hand-copied grids now share; page headers became `x-page-header`; a `.glass` material
covers panels and every Flux modal (`dialog[data-flux-modal]`, styled unlayered on purpose);
covers tilt, sheen and morph into the series hero through a shared-element view transition
whose name is applied on click and cleared after navigation. The login page is "The Reading
Room": a perspective wall built from the collection itself, served by the public, single-mode
gated `/showcase` endpoints (`ShowcaseController`, `PAPYRUS_SHOWCASE_ENABLED`), with a seeded
generative ink garden where there is no library to show. The reader was re-tokenised onto the
same oklch values by copy rather than by import, so it stays a plain document; its test
constraints (no Alpine, no canvas, no rAF, no IntersectionObserver) were untouched and still
hold. Destructive `wire:confirm` browser dialogs became `x-confirm` modals except where a test
parses the button or the button lives inside a menu.

Downloads turned on a second measurement, about what a feature is worth:

```
manga/76/chapter/1/page/0    200  image/webp  181KB      after downloading chapter 1
```

The reader's own page URL serves a downloaded chapter, so going offline needed no URL work at
all. And the cost of the ledger is the same two calls the library page already makes, because
`isDownloaded` rides along on the bulk `mangas` call for free.

The library's shape came out of measurement, not taste:

```
mangas(filter: {id: {in: [...ids]}}, first: N)
  300 ids, one call, 246ms, 63KB      against one manga(id:) per series: ~15ms each, 4.5s
```

So a library page is flat in library size and there is no local metadata cache anywhere.
Three schema facts fell out of the same probe and are all in
`SeriesGateway::fetchMany`:

- the filter is an `IntFilterInput` while `MangaType.id` returns a `LongString`, so the
  variable is declared `[Int!]!`. `[LongString!]!` is a type mismatch and `[Long!]!` is an
  unknown type.
- an id the server does not know is dropped silently. Seven in, six out, no error.
- `chapters` takes no arguments here either, exactly as on `manga(id:)`.

And Suwayomi's `unreadCount` is never requested: it is computed from `Admin(1)`'s read
state, and it reported 41 unread on a series where this reader has read thirteen chapters
of. Unread is `chapters.totalCount` minus our own read rows, in one grouped query.

Live proof of Phase 1, the widest run so far:

```
search:fanout "one piece" --limit=100
  82 sources targeted, 30.8s elapsed
  1080 raw hits -> 91 after dedupe, 30 multi source series collapsed

"chainsaw man" -> 5 sources, 3 raw hits, 2 deduped:
  Chainsaw Man (+1 more)
  Tatsuki Fujimoto Before Chainsaw Man: 17-21   <- spinoff correctly kept separate
```

That run also filled the health ledger for the first time: 82 of 82 sources observed,
0 dead, 0 failing. Every source came back either working or reachable but silent. Two
sites in the list are known to be dead upstream and did not fail here, so a single
clean run proves the plumbing works and nothing about which sources are good.

61 of those 82 queries went to MangaDex, one per language, and the obvious conclusion was
that this was 61 requests for one answer. It is not. Those variants return the same 51
manga in different scripts, so each is the only route to its own titles. See trap 39
before proposing to collapse them.

---

## 12. Decisions taken

| Decision | Choice | Reasoning |
|---|---|---|
| Cross language dedupe | **Deferred to AniList in Phase 6 (M3)** | Measured: a transliteration library yields the *reading*, since ワンピース becomes "wanpisu", scoring **0.40** against "One Piece" (進撃の巨人 scores 0.14). It cannot bridge to the translation. Only AniList or MAL canonical IDs can, so no dependency goes in now. |
| Dashboard grain | **Grouped by site, drill down to sources** | Measured: 82 installed sources are 15 distinct `home_url` values, and MangaDex alone is 61 language variants of one site. A per-source list would repeat one site 61 times, which answers nothing. A site inherits the *worst* state of its variants, because one unusable language region is still a source the reader cannot use. |
| Extension trust | **Per user, enforced in the action** | Suwayomi keeps its own trusted repo list in server config and exposes no trust surface through GraphQL, so the gate is ours. It is refused in the action rather than only hidden in the template, because anyone can call a Livewire method from the console. |
| Suwayomi deployment | **Native Windows JAR, no Docker** | The machine has virtualization switched off for a macOS dual boot. A container would need it. Enabling it was declined: zero benefit today and asymmetric risk. |
| Explanation style | **Explain decisions, skip framework basics** | Cover the reasoning behind each choice. Do not stop to teach Livewire, Flux, Alpine or modern Laravel patterns. |
| Fan-out shape | **No site collapse. Every source, every time.** | Measured, and the measurement reversed the plan. The 61 MangaDex variants return the same 51 manga in different scripts: EN gives 15 Latin titles, JA gives 6 Latin and 9 Japanese. Each variant is the only route to its own script. Collapsing to one variant per site keeps 64% of titles. Set cover says 25 variants reach the full union, but which 25 are redundant is a fact about one query, not about the catalogue, so nothing is collapsed. The 30.8s is a background run and is acceptable. |
| Dedupe threshold | **0.95** | Measured, not guessed. At 0.92 "One Piece G" folded into its parent. The measurements are in `config/papyrus.php`. |
| Reader zoom | **Custom canvas, no dependencies** | Full control, no licence to track, no maintenance burden. |

---

## 13. Non-goals

Never build: novel or text reading, TTS, in app translation, home screen widgets, APK
install UX, notification channels (Web Push has none), e ink page flash, volume key paging,
biometric lock (use WebAuthn), screenshot blocking, DoH or UA pickers (those are server
config, not user settings), orientation lock.

Legal posture: ship no content, no bundled source list, no hosted index. Users supply their
own repo URLs. Tachiyomi was taken down *despite* this architecture, so it is not immunity,
and it is not something to monetise.

---

## 14. Known gaps between the documents and the code

Found by reading all 94 files under `app/` and tracing every public method to a caller.
The pass that found them was documentation only, so nothing here was fixed at the time and
every entry was a live gap. Entries that later passes closed are marked **closed**, because a
gap list that reports a resolved gap as open is worse than no gap list. What remains is
written down so the next person does not have to rediscover it, and so no document in this
repository is trusted further than it has been checked.

### 14.1 Documented as working, not implemented

| The documents say | The code has |
|---|---|
| Deployment mode `shared` enforces **per-user quotas** (README table, section 12) | `Deployment::enforcesQuotas()` and `::quotaFor()`, called from `tests/Unit/DeploymentTest.php` and **nothing else**. `users.storage_quota_bytes` is written by nothing. |
| Deployment mode `public` exposes **moderation surfaces** (README table, SECURITY.md) | No moderation surface exists. `isShared()` and `isPublic()` are also uncalled, so the only distinction the app makes at runtime is single against not-single. |
| `suwayomi:schema:diff` is a command (config/papyrus.php:374) | There is no such command. The diff is `suwayomi:schema:dump --dry-run`. |
| `search.fanout_concurrency = 8` bounds in-flight jobs | **Now true.** Closed in this pass. See 14.6 for what it took. |
| `downloads.cbz_export_max_bytes` size-guards an export | **Now true.** Closed by the CBZ export. Suwayomi's `/api/v1/chapter/{id}/download` still builds the whole archive in memory and the proxy still refuses it; the archive is assembled in this app instead, and the cap is enforced against a running total as the pages are written. See 14.6 for what it cost to find that the cap could not be a pre-flight. |

The registration rule is the exception and now holds: `RegistrationMustBeAllowed` 404s
`register` and `register.store` when the mode disallows it, and
`tests/Feature/RegistrationGateTest.php` proves it through requests. See trap 121 for why
that took as long as it did.

### 14.2 Config keys no code reads

Four of the 41 leaf keys in `config/papyrus.php` are read by nothing. The count was 47 keys and 6 unread before this pass; `search.fanout_concurrency` stopped being dead then, and `downloads.cbz_export_max_bytes` stopped being dead with the CBZ export.

| Key | Why it is still there |
|---|---|
| `suwayomi.jwt_audience` | Intended audience check. `Jwt` reads `exp` only and never verifies, deliberately. |
| `downloads.poll_interval` | The schedule uses `everyTenSeconds()`; the number is written twice, in `SyncDownloads::INTERVAL_SECONDS` and in `routes/console.php`. |
| `reader.preload_ahead` | The reader does its own preloading in plain JavaScript, reading configuration injected by `@js(...)`. |
| `reader.preload_behind` | As above. |

`PAPYRUS_SEARCH_CONCURRENCY` was undocumented in `.env.example` while it was dead, which
was defensible. It is the one knob that decides how hard the reader hits Suwayomi, and it
is now load-bearing, so it is documented.

Two more are read by the job that shadows them rather than the config:
`anilist.gap_seconds` and `anilist.pass_budget_seconds` both appear in
`ResolveAniListTitles.php`, but only as `private const GAP_SECONDS = 2` and
`private const PASS_BUDGET_SECONDS = 45`. Setting the config key changes nothing.

### 14.3 Public methods nothing calls

Unreachable from production and from tests alike, unless noted:

| Member | Note |
|---|---|
| `SourceSearcher::browsePage()` | **Called**, by `/browse/{source}`. Renamed from `browse()` and retyped: it used to return a `SourceSearchOutcome`, which is a *search* answer whose `ok`/`empty`/`error` split the health ledger depends on, and which slices to `results_per_source` because that bounds a fan-out. Applied to a browse page that slice discarded most of every one. |
| `SearchFanout::filterCache()` | |
| `SourceFilterTranslator::nameToPosition()` | `addresses()` and `toChanges()` are both live; this is the other half of the same idea. |
| `DownloadGateway::clear()` | |
| `ChapterNavigator::finished()` | |
| `Timestamps::now()` | |
| `SeriesMigrator::lastMigrationFor()`, `::strandedDownloads()` | `strandedDownloads()` is the source of the "stranded downloads" wording in the docblocks, and it is not wired into the download centre. |
| `CheckLedger::failures()` | |
| `AuthManager::isAuthenticated()` | |
| `HealthRecorder::SILENT_AFTER_EMPTY`, `HealthDashboard::FAILING_AFTER` | Declared constants whose values are re-hardcoded as SQL literals inside `siteStateSql()` and `siteRankSql()`. Changing the constant changes nothing. |
| `Deployment::isShared()`, `::isPublic()`, `::enforcesQuotas()`, `::quotaFor()` | The last two are covered by tests only. See 14.1. |
| `resources/views/dashboard.blade.php` | Unreachable: the `dashboard` route is a redirect to `library`. It is the only consumer of `components/placeholder-pattern.blade.php`. |

Dead columns and unread relations: `users.last_seen_at` is written by nothing;
`categories.note` is read and written by nothing; `SeriesState::isFavourite()`,
`::isQueuedForLater()`, `::chapters()`, `::notes()`, `::cover()` and
`::defaultCategory()` are declared but never traversed, because the series page goes
through `ReadingState` instead.

Three of those four were closed by the incognito pass and one was not, so the list is worth
reading as the shape of a half-built column rather than as a to-do list.
`user_sources.incognito` **was** on it, as a fillable field with no reader. It is now read by
`Incognito::suppresses()` and written by `Incognito::setFor()`, gated at all three read-state
writers, and has a control on the health page.

The ratings and tags pass closed two more of the same kind, and the shape held exactly:

| Column | Before | After |
|---|---|
| `series_states.rating` | exists, nothing writes it | `ReadingState::setRating()`, one to five or `null`, with a star radiogroup on the series page |
| `series_states.tags` | exists, nothing writes it | `setTags()`, `addTag()`, `removeTag()`, normalised in one place, with a tag editor on the series page |

`series_states.rating` and `::tags` no longer belong on this list, which is the fourth time a
"column with no writer" turned out to be a missing feature rather than a schema mistake. The
lesson is the same each time and it is about reading: **a column, a fillable entry and a cast
are not a feature.** Nothing about `series_states.rating` distinguished it from `::users.
last_seen_at`, which is on the same list and still is.

Still open: `series_covers.source_page` is unwritten and the reader has no cover control at
all, and `ReadingState::addReadSeconds()` has one caller that cannot use the gate.

That last one is the interesting half. `ReadingState::addReadSeconds()` is declared and has
**no callers**, measured: one declaration, nothing else in `app/`. It is the only read-state
writer that cannot answer the incognito question from its own arguments, because it takes a
chapter id and a manga id of `0`, so there is nothing to resolve a source from. It is left
unwired on purpose and the docblock says why. The general lesson is trap 121's, in a new
place: **a method with no callers is not yet a feature, and the signature it grew is the
thing to check before wiring it up.**

### 14.4 Smaller inconsistencies worth knowing

- **`Deduplicator` has two construction paths.** `AppServiceProvider` binds it as a
  singleton without a `TitleResolver`; the search page ignores the container and news one up
  with the resolver. So `search:fanout`, the diagnostic command, never exercises AniList
  grouping.
- **Trap 70 is not followed by its own project.** It says to set `failOnTimeout = true` on any
  job whose runtime is uncertain. Only `FindMigrationCandidates` does. The four
  self-re-booking jobs, including `CheckLibrary` whose docblock cites the trap, leave it
  default.
- **Two orphaned docblocks**, each documenting nothing because another docblock follows it
  immediately: `ReadingState.php:103` and `SearchSourceJob.php:34`.
- **`ReaderSettings::gap` is documented and defaulted inconsistently.** The docblock
  explains why a webtoon needs a non-zero gap; the default is `0`.
- **`.env.example` documents 12 of the 36 env-backed keys.** The rest are discoverable in
  `config/papyrus.php`, which is where they are commented.
- **`.well-known/passkey-endpoints` carries no middleware at all**, unlike every other route.
- **The reader is described as carrying no framework**, and its script does not use one, but
  `wire:navigate` is on every chapter-navigator segment, so the rendered document does
  reference Livewire. `ReadPathTest` checks for a snapshot payload rather than for the
  attribute, which is why it passes.

The `.env.example` bullet about `SUWAYOMI_PUBLIC_PATH` misdescribing the image path has been
**deleted rather than corrected**, because the code was fixed instead: the file now says
every image streams through a Laravel route and cites AD-3. A gap list that reports a
resolved gap as open is worse than no gap list, since the next reader cannot tell which
entries are live.

The trap-numbering bullet has gone the same way, and the detail is worth keeping because the
disambiguation is not guessable from position. The list ran 1 to 67, then 75 to 77, 83 to 122,
then 78 to 82 and 68 to 74, then 123 upward, and **123 was used twice**: once for "A right
answer for the wrong reason still has to be corrected" and once for "An upsert's insert rows
must carry the end state". The bullet claimed every number appeared exactly once, which was
false, so a reader auditing it had no way to know whether a citation pointed at the trap they
meant. The tie is broken by the citation rather than by line order: the one place the document
cites trap 123 is the manual-read-marking row, and it describes the upsert trap, so that entry
kept the number and the other became 133 and moved to the end. The 68-to-82 block was
**re-ordered and not renumbered**, because trap 70 is cited eleven times elsewhere and trap 45
four times, and renumbering to close the gap would have broken a dozen citations to buy
tidiness. The list is now 1 to 154, each number once, ascending.

The `failOnTimeout` note above is also out of date. Three jobs set it now:
`FindMigrationCandidates`, `SyncAniListProgress` and `ImportAniListProgress`. `CheckLibrary`
and `SyncDownloads` still leave it default, and `SearchSourceJob` still does, which now
matters more than it did: `SearchSourceJob` feeds itself through the batch, so it is a
chained job with an uncertain runtime and no timeout declaration.

### 14.5 The source catalogue reads absence as an uninstall

`SourceCatalog::syncSources()` writes every source it sees as `is_installed = true`, then
issues one update marking **every source not in that response** as `is_installed = false`,
stamped with the same `last_synced_at`.

There is no way to tell that apart from a reader uninstalling an extension. A partial
response, one source that failed to enumerate, or a server that was mid-restart all produce
the same row.

This is not hypothetical on this box. The documents below say 82 installed sources. The live
mirror said **73 of 82** when this pass checked, stamped `2026-10-01 23:38:11`, while
Suwayomi itself still listed all 82. The nine that had flipped were Weeb Central, MangaTaro,
Alpha Manga, Akaza Scans, Asura Scans, Aqua Manga, Eva Scans, Lagoon Scans and Manga Demon,
which is to say the nine that had been erroring in the browser gates.

Two things follow. The source counts in these documents are point-in-time and should be
read as such. And a source that fails repeatedly can remove itself from the catalogue via
the path meant to record a reader's decision, which is the wrong direction for a fault to
push in.

### 14.6 What a browser pass found that the feature tests could not

Three features were in flight and uncommitted: the fan-out rolling window, H7 bulk library
actions, and the AniList progress import. Two hundred and four tests were green across
them. **All three turned out to be broken in a browser, and a fourth bug was hiding behind a
fake that asserted the wrong shape of a third-party response.** This subsection records what
changed, because the pattern matters more than the individual bugs: **every defect below is
invisible to a test that sets the property under test.**

| What was wrong | How it was found | Why no test saw it |
|---|---|---|
| Bulk bar never appeared when boxes were ticked | Drove the checkboxes: `$wire.get('selected')` was right, zero POSTs left the browser | `Livewire::test()->set('selected', [...])` bypasses `wire:model` entirely. Trap 126. |
| A retried fan-out job counted its source twice | One real fan-out came back `done=41` against `total=40`, stamped `partial` with 547 results | The retry only happens when the batch's own bookkeeping throws, which the sync driver never does. |
| A job's "already recorded" guard was silently inert | Chasing the 41 above to its cause | It read through a tenancy scope that a queue job resolves to nothing. Trap 127. |
| A feed that could not take its lock hung the run | Reading, after the comment calling it "slow" | Needs two processes contending on one cache key. |
| A source deleted mid-run left the run one short for ever | Reading the branch: it fed and returned without touching a counter | Needs a row to vanish between dispatch and execution. |
| The import's page said "Checking N of M" for six hours on timeout | Reading: `failOnTimeout` is on and nothing wrote `phase = failed` | The job's `handle()` was never driven by any test. |
| Two import passes could clobber each other's tally | Reading: `runPass` is a read-modify-write over one cache key | The lock has no test, for a reason recorded below. |
| The import never got past its first unmatched series | A real run against a live AniList account: the job released and retried the same title for ever | Every fake answered an unmatched search with a 200 carrying `Media: null`. AniList sends a **404**. Trap 130. |
| The import reported "0 marked updated" for a library it never looked at | Reading the page text after the run | The tally never distinguished an empty library from a finished one. |

Three things are honest gaps rather than fixes:

- **`database is locked` is not fixed.** Seven `SearchSourceJob` failures carry it, three
  from 2026-10-01 and the newest at 2026-10-02 19:12. WAL and `busy_timeout = 10000` are
  both confirmed set on the connection, so the timeout is not surviving the contention. The
  throw lands inside `DatabaseBatchRepository::decrementPendingJobs`, inside the batch's
  own transaction. The double-count guard makes the symptom harmless, but the lock
  contention itself is unexplained and untreated.
- **The import's pass lock is untested.** Both ways to test it were rejected on
  measurement, and the reasoning is recorded at the top of `tests/Feature/AniListImportTest.php`.
  Briefly: a held lock is not observable through a second `get()` on this cache store, and
  holding the lock from the test blocks for the full 300 seconds the real path waits.

The AniList import **was** browser-verified, and that is how the 404 was found. It needed an
account, which is a setup fact rather than a code one and is worth stating because it cost
time: the connection had been made against `papyrus@localhost.test`, whose library is empty,
while the series live under `reader@example.test`. An import that walks an empty queue
correctly marks nothing, so the page showed a zero tally that looked like a failure. It was
not until the two accounts were compared that the mismatch showed, and not until the
connection was moved that the real bug could be reproduced at all.

A related trap, smaller but the same shape: **a report of "it does not work" is a claim about
the setup until the account, the data and the code have each been checked separately.** Two of
the three explanations here were not code, and the third was only visible once the first two
were eliminated.
154. **`Content-Length` is the obvious way to size a download before sending it, and Suwayomi
     does not send it for page images.** The CBZ export's size guard was built on it: ask each
     page for its length, add them up, refuse before the response starts. That is the right
     shape, because a guard that fires after the first byte hands the reader a truncated archive
     their unzip tool calls corrupt, which is indistinguishable from a bad download.

     Measured against v2.4.2366 on a live page, none of the three ways of asking work:

     ```
     HEAD               200, Content-Type text/plain, no length, no Accept-Ranges
     Range: bytes=0-0   200, no Content-Range
     GET                Server, Date, Content-Type, Cache-Control, Connection
     ```

     Jetty answers page images with chunked transfer encoding. So every page reads as unknown,
     the guard could only ever refuse, and the pre-flight that established this cost **20.5s**
     against **1.9s** for the whole export. Twenty-two of twenty-two pages reported no length.

     What ships instead: build the archive into a temp file, check the running total as it
     goes, delete and refuse on overflow, otherwise rewind and send. Memory stays flat at one
     chunk per page, the reader never receives a truncated archive, and the response now
     carries a real `Content-Length` rather than an estimate. Measured: 22 pages, 19.3 MB,
     1.4s to build, **peak memory 32 MB**.

     **A guard whose premise is false is not a conservative guard, it is a feature that does
     not work**, and it refuses every single time rather than failing sometimes, which is
     exactly what makes it easy to believe. The general shape: before sizing something you are
     about to send, measure whether the source will tell you. Every content-length in this
     file is worth checking against the server that serves it rather than assumed, because
     that code only reads it when it happens to be there.

     Five smaller ones from the same feature, all of them found by running it.

     **`pack('V', "\x50\x4b\x03\x04")` writes four zero bytes.** The ZIP record signatures were
     written as 4-byte strings and handed to `pack('V')`, which takes an integer, and a string
     converts to 0. Every header in the archive began `00 00 00 00` and `ZipArchive` refused it
     with error 19. **A signature is not a number**, and the difference is invisible until a
     reader looks.

     **CRC-32 is "invert, shift eight times per byte, invert", and PHP removed the incremental
     form.** `crc32()` takes exactly one argument in PHP 8, so a chunk-by-chunk stream has to
     fold its own. The first version ran the register from zero with no inversion at either
     end, which is the one's complement of the checksum: it wrote `0xEAAEB7DE` for a payload
     whose CRC is `0x3B15CE19`. Both errors were invisible to the size checks and would have
     shipped as archives readers call corrupt, which looks exactly like a truncated download.

     **A `finally` that closes a reader replaces the assertion failure with its own error.** The
     round-trip test called `$zip->close()` in a `finally`, and closing an archive that failed
     to open throws, so *every* failure in that file was reported as "Invalid or uninitialized
     Zip object" instead of what actually went wrong. Two genuine bugs hid behind that one
     line. **A cleanup step must not be able to raise, or it hides the thing it cleans up
     after.**

     **A streamed response has no body until it is read.** `getContent()` returns an empty
     string for a `StreamedResponse`; the assertion is `streamedContent()`. Four failures came
     from that at once, all reporting an archive with zero entries and a `Content-Length` of
     zero, on an endpoint that had just answered 200 with a working archive.

     And **`setCallback` is the wrong place to delete a scratch file.** It fires when the
     response has been sent, which is exactly the moment a reader who cancels stops listening,
     so the one case most worth cleaning up is the one it is least likely to reach. A
     `finally` around the send runs on that path too, and it is the only version a test can
     see: `setCallback` never fires under a test, so a test asserting "nothing was left behind"
     would have passed against a build that leaks on every export.
