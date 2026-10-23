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

143. **A gate that failed to load is not a gate that ran.** Written on 2026-10-04 when it was
     true and it is no longer: nineteen of the twenty browser gates then could not run at all,
     because they are Python and no Python on this machine had `playwright`. **That is now
     fixed and the gates run again** (see the correction at the end), so the standing counts
     below are measured rather than inherited. The trap itself is not the missing package, and
     it did not stop being true when the package came back.

     What made it worth writing was that **`import playwright` failing at the top of a script
     is indistinguishable from a gate that passed**, because `python script.py` on that box
     printed "Python was not found" and exited 1, and a gate whose exit code nobody reads has
     stopped existing without saying so. `verify-october-pass.mjs` carries a comment saying it
     is a Node port of the Python original for exactly this reason, which is the only reason
     the pattern was known at all.

     The same shape arrived the same day from the other side, as `MODULE_NOT_FOUND` from Node
     with `playwright@1.63.0` installed and `npm ls` clean: the shell's working directory had
     reset to the workspace root, so `node verify-cover-reveal.mjs` was looked for one level
     up. The fix in both cases is an absolute path or an explicit `workdir`, because the
     harness resets the shell between commands.

     **Then it happened again, twice, and to my own tooling**, which is why this is the trap
     rather than a note. The gate runner written to measure all twenty-nine gates printed
     `clean` for all eighteen Python gates on a run where every one had crashed at import with
     `ModuleNotFoundError`, because its verdict was computed from the count of `[FAIL]`
     markers and nothing else. That is trap 156, and it is this one wearing the runner's
     clothes: **a gate that failed to load looks exactly like a gate that passed, and only the
     exit code and a check count that is greater than zero distinguish them.**

     The correction. Python is installed again, 3.14.8, and `playwright` 1.63.0 lives in a
     venv at `.tools/suwayomi/.venv`. All eighteen Python gates run against the live app, which
     is how `verify-search-syntax.py` was measured at 28 checks having been repaired earlier the
     same day. **They were never obsolete and they do not need porting.** The earlier decision
     to leave them as `.py` rather than rewrite nineteen scripts as `.mjs` was correct, and this
     is the evidence for it rather than an argument: the scripts ran, unchanged, the moment the
     interpreter came back.

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

Current standing: **1,157 tests**, 2,564 assertions, Pint clean, PHPStan
level 7 clean.

Prose: **zero em dashes and zero en dashes**, and five ellipses, which are all the truncation
marker in code rather than prose. Measured two ways, because measuring one of them was how the
claim used to be wrong:

```
php .tools/suwayomi/scan-dashes.php    0 em, 0 en, 5 ellipses across 292 files (app, database,
                                        tests, config, routes, resources)
git grep -P '\x{2014}'                 nothing, plus this directory, which the scanner does not
                                        cover
```

The scanner does not read `docs/`, so neither tool alone covers the repository and **a claim
saying "zero em dashes" while measuring one file is not a claim about the repository.** Three were
sitting in `app/Downloads/CbzExporter.php` and `tests/Feature/CbzWriterTest.php` at the time this
was written, from the CBZ work, and the number had been reported as zero through several commits
because the file being edited was the one being scanned.

**The browser gates are measured, not remembered.** `run-all-gates.mjs` runs them and this is its
output rather than a figure anyone typed. Measured on a healthy box, **29 of the 29 gates, in one
run of 34 minutes** (see the note on what that costs and why it used to be batched):

```
28 clean, 0 failed, 0 crashed, 0 asserted nothing, 1 counted elsewhere, of 29 gates
478 checks read from output, which excludes the 1 gate(s) this runner cannot count
```

**Twenty-eight of the twenty-nine now assert something the runner can count, and every one can
fail.** The twenty-ninth is `verify-browse-failure.mjs`, which prints `OK`/`RAW`/`SKIP` per target
site and is counted in words. There is no gate left that reports a finding into a list nothing
reads.

| | |
| --- | --- |
| clean, count read from its output | 28 gates |
| clean, under another convention | 1 gate, `verify-browse-failure.mjs` |
| failed | **0** |
| crashed | **0** |
| **not measured** | **0** |
| **checks read across the suite** | **478** |

Trap 155 is retired as a live concern rather than as a note. It was written about eight gates
that could not fail, and the truth was narrower and stranger: **six of the eight could fail and
two could not**, and all eight were invisible to the count because none printed the markers the
runner reads. Trap 173.

| Gate | Checks | | Gate | Checks |
| --- | --- | --- | --- | --- |
| `verify-reader.py` | 39 | | `verify-library.py` | 18 |
| `verify-rating-tags.mjs` | 34 | | `verify-continue-reading.py` | 18 |
| `verify-notes.py` | 33 | | `verify-covers.py` | 23 |
| `verify-navigator.py` | 30 | | `verify-library-check.py` | 22 |
| `verify-search-syntax.py` | 28 | | `verify-october-pass.py` | 24 |
| `verify-october-pass.mjs` | 23 | | `verify-cover-reveal.mjs` | 10 |
| `verify-migrate.py` | 21 | | `verify-downloads.py` | 20 |
| `verify-reader-asks.py` | 11 | | `verify-reader-fixes.py` | 11 |
| `verify-fixes.py` | 7 | | `verify-inflight.py` | 7 |
| `verify-fanout-coverage.py` | 7 | | `verify-drift-notice.mjs` | 7 |
| `verify-browse.py` | 12 | | `verify-fanout.py` | 5 |
| `verify-card-bookmark.mjs` | 9 | | `verify-cbz-export.mjs` | 15 |
| `verify-incognito.mjs` | 14 | | `verify-search-anilist.py` | 14 |
| `verify-source-chooser.mjs` | 13 | | `verify-sources-picker.mjs` | 3 |

The two buckets above are the runner's own, not a repartition of its output.
`run-all-gates.mjs` prints `28 clean, 0 failed, 0 crashed, 0 asserted nothing, 1 counted
elsewhere, of 29 gates` and `478 checks read from output`, and the table is that sentence broken
into rows.

The 478 is the sum of that table, added up from the file rather than estimated, which is the
whole point of the table being here. An earlier version of this paragraph said 399 and then 402,
both of which were arithmetic done in my head while editing and neither of which was ever
checked. **A total nobody added up is a guess wearing a total's clothes**, and it is the same
fault as a typed-in expected count one level up.

One gate is still counted in words rather than in numbers:
`verify-browse-failure.mjs` prints `OK`/`RAW`/`SKIP` per target site, so its assertion count is
the number of sites it visited rather than a figure in a table. It is counted, it can fail, and
the runner names it separately so the exclusion is visible rather than a quiet zero.

### What is still not clean

**Nothing fails, and nothing fails now because every gate can.** That is a different sentence from
last week's, when nothing failed because eight of the twenty-nine were incapable of it, and the
bucket table above is the proof rather than a claim.

Two things remain, and neither is a red gate:

- **`verify-browse-failure.mjs` is counted in words.** It prints `OK`/`RAW`/`SKIP` per target site
  and asserts once per site, so the runner cannot put a single number on it. It is not blind and
  not silent; it is in a different convention, and the convention is named so the exclusion is
  visible rather than a quiet zero.
- **`verify-reader-asks.py` reports one skipped section.** It checks the downloads page's series
  label, and there are no download rows on this account, so it prints `SKIPPED` in its summary and
  contributes 11 checks rather than 12. The old form printed a note in parentheses and said
  nothing, which is trap 151: a section that cannot run reported as a passing gate.

The first is a reporting convention and the second is a genuine coverage gap, and closing the
second means seeding a download row. `seed-finished-download.php` cannot do it, because it deletes
the centre first for the CBZ gate. That is the honest next piece of work on this suite, and it is
small.

`verify-library-check.py`, the gate that was not measurable for weeks, is not on this list. It was
never slow: it had a hardcoded per-series rate in a comment, a ceiling built from it, and a printed
message quoting it beside the real measurement. It runs in 54 seconds, and **the cost of finding
that out was one in-process probe, not an architectural decision.**

### It runs as one suite, and it used not to because of a number nobody measured

**`run-all-gates.mjs` with no arguments runs all twenty-nine in 34 minutes and is green.**
Measured, in one run, `28 clean, 0 failed, 0 crashed, 0 asserted nothing, 1 counted elsewhere, of
29 gates`, `478 checks read from output`.

This section used to be headed "Why it is not run as one suite", and it was wrong for the same
reason most things here have been wrong: it rested on a figure in a comment. The timings, in
seconds, from that run:

```
verify-fanout.py             290     a real 40-source fan-out against live upstream sites
verify-search-syntax.py       60     three real searches, each fanning out to 40 live sites
verify-cover-reveal.mjs       71     asserts nothing, and takes a while doing it
every other gate              10-62  27 gates
20s gap x 28 gates           560     mine, to stop SQLite contention faking failures
```

So one gate holds half the wall clock, the gap is a third, and the other twenty-seven share the
rest. Nothing here justifies splitting the suite by hand any more.

What the section claimed before, and why each claim was wrong:

- `verify-library-check.py` at `1639+`, "a gate that honestly reports on a full pass cannot also be
  short". It runs in **40 to 54 seconds**. The rate that ceiling was built from was the gate's own
  polling divided into its own series count. Trap 164.
- "three gates account for roughly seventy minutes of the seventy-five". Two of those three are
  the numbers just corrected, and the third is `verify-fanout.py` at 290 seconds rather than 283.
- "twenty-four gates run in about nine minutes; the three slow ones run when the answer is worth
  half an hour". **That is a batching habit built on a phantom.** A phantom is a fine reason to
  batch, because batching is harmless, and a terrible reason to believe you know what the suite
  costs.

One thing the batching did buy, and it is worth keeping: **a single run is the only run that
proves the gates do not fight each other.** Batches hide order dependence. The first full
single run found three failures that each passed alone and none of which passed together:

```
verify-continue-reading.py   a fully read series leaves the feed entirely
verify-library.py            filing adds a chip  card 0: 0 then 0
verify-rating-tags.mjs       removing one tag keeps the other
```

All three were the same fault as the rest of today, a fixed sleep read too early, and all three
only surfaced because the suite ran end to end for the first time. **A green suite run in batches
is a weaker claim than a green suite run in one go**, and the batching was hiding the difference
between them.

So: run it as one suite, and treat any gate that only passes in a batch as broken. The runner
still takes a comma-separated list of fragments, because that is now for re-running one gate
rather than for dodging the slow ones.

### Diagnosed, with the fault attributed

Every failure below was traced to a cause rather than re-run until it went away. **Nine were the
gate's fault and two were the app's**, which is close to the opposite of what the report looked
like before per-gate output was being kept.

| Failure | Whose fault | Trap |
| --- | --- | --- |
| `verify-covers.py`, 5 checks | gate: fixed sleeps read before the upload landed | 159 |
| `verify-fanout-coverage.py`, "expected 16 site groups" | gate: a typed-in number, and the app's 18 is correct | 161 |
| `verify-inflight.py`, "still Searching after 120s" | gate: an unsatisfiable check and a budget at the median | 162, 163 |
| `verify-library-check.py`, 4 checks | gate: a poll that fought the job, and a ceiling built from a rate that was the gate's own polling | 160, 164 |
| `verify-continue-reading.py`, crash | gate: `page.reload()` on a page that polls | 157 |
| `verify-rating-tags.mjs`, crash | gate: `const` inside a `try`, invisible to its own `finally` | 157 |
| `verify-browse-failure.mjs`, passes by skipping everything | gate: counted nothing as nothing found | 158 |
| `verify-reader-asks.py`, plain-text source name | gate: clicked whichever card sorted first | 102 |
| **`browseSites()` grouped by a stripped URL, not a parsed one** | **app**, fixed | 161 |
| **`$query` carried no `#[Url]`, so a search could not be bookmarked** | **app**, fixed | 163 |
| **The Suwayomi proxy answered an `<img>` with the app's own error page** | **app**, fixed. A failed page image shipped the whole layout, CSRF token included, as its body | 170 |

Two of these had nothing to do with the code and everything to do with the box: `verify-reader.py`
crashed on a locator that could never resolve and `verify-search-syntax.py` failed three
checks about filtered searches, both because Vite was down. Trap 165.
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

`run-all-gates.mjs` runs the whole suite and is how the counts in the standing are produced
rather than remembered. It takes an optional name fragment, so `run-all-gates.mjs verify-cbz`
runs one gate. Three details in it are load-bearing:

- It finds an interpreter **by probing for playwright** rather than by checking that a path
  exists, because a path that exists and cannot import the dependency is a gate that never runs
  rather than a loud error (trap 156).
- It reports **CRASHED**, **FAILED**, **no checks** and **clean** as four different verdicts
  instead of collapsing them, because a gate that asserted nothing and a gate that could not
  fail are different facts and one of them is a defect (trap 155).
- It writes every gate's whole output to `.tools/suwayomi/logs/gates/<gate>.log` and prints that
  path beside any failure. Re-running a browser gate to find out why it failed is slow, and not
  always possible, because the box it ran against has moved on (trap 157).

Gates run twenty seconds apart, and six was not enough. `verify-browse.py` died with a
Playwright timeout on `goto('/sources')` having started six seconds after the previous browser
gate finished; run on its own it exits 0 with nothing reported. Nine minutes added to a run of
about two hours is the right trade for a suite whose only value is that its failures mean
something. **A gate suite that reports contention as a defect teaches people to ignore it.**

One gate is declared in the runner as counting under a different convention rather than being
silently read as zero. `verify-browse-failure.mjs` prints `OK`/`RAW`/`SKIP` per site rather than
one line per check, while every other gate uses an identical `check()` helper, so the runner can
read twenty-eight of the twenty-nine and says so in words about the one it cannot. A zero there
would be a number nobody took.

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
| 7 (master plan) | In-app backup and restore | **Complete, and the page is true.** The reader half is captured through SQLite's own `VACUUM INTO` and the manga half through the server's own fresh export, both verified before the manifest is named and the pair renamed into place with nothing stopped; the reader half is swapped back under one `BEGIN IMMEDIATE` that refuses rather than forces, and the manga half rides the server's own import route with the server's own progress on the bar; the arrangement travels out as a `.tachibk` with the read marks and the shelf memberships merged in, adding only. **Measured:** the backup taken while the standing 10 s churn ran, the restore's four tables round-tripping on a scratch copy of the reader half, the export imported into the throwaway with the receiving pills read back (Snowflakes 13, Youtube 17), and the owner's real `.tmb` landing its two named shelves for the first time. **What it does not claim:** the export's basis is the server's library, and this box's servers hold one extension, so 80 of that file's 110 shelf members are on sources no server here can name. Section 15 carries the run notes. |
| 9 (master plan) | Adult content gate, back affordances, legal pages | **Built, and the hole it closes was measured before it was closed.** The rating is the catalogue's own `contentWarning`, and the defect was that a link could reach the adult rated extensions with nothing asked: `?content[]=NSFW` put **41 adult rated cards on the first page of 50** and a count of **874**, while the page still claimed they were hidden. The cause is Livewire's own URL hydration, which merges an array over the declared one **by key** (trap 332). The gate is now a per reader setting in `user_settings`, off until a confirmation that carries the 18+ statement inside it, and it is applied in the query rather than in the page: the catalogue and the fanout's universe exclude the rating server-side, the chip is not offered, the browse surfaces refuse with a named refusal, and the Installed slice names how many it is holding back rather than showing a count that is short. **Measured:** the gate shut reads **1,055** with **0** adult rated cards, the same address reads **487** with **0**, and the gate open reads **1,442** with **34**. The back affordances were walked before they were built: eleven candidates, and the house already had one on every page that needs it except the bulk migration page, which carries "← Back to sources" now. The legal pages are a privacy page and a terms page in the shape the owner chose, in the FAQ's own two seats, and the list of the three things that reach the internet is now one list read by both. Traps 332 and 333. |

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

Found by reading all 142 files under `app/` and tracing every public method to a caller.
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
tidiness. The list is now 1 to 219, each number once, ascending.

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
155. **A gate that cannot fail is worse than no gate, because it is read as passing.** Five of
     the eighteen Python gates append their findings to a `notes` list, print it under a
     heading like "could not verify / worth knowing", and `return 0` regardless:

     ```
     verify-browse.py             notes, return 0
     verify-fanout-coverage.py    notes, return 0
     verify-fixes.py              notes, return 0
     verify-inflight.py           notes, return 0
     verify-fanout.py             neither, return 0   (a diagnostic, not a gate)
     ```

     Every note they write reads like a failure. Measured on `verify-inflight.py`:
     *"the search still read 'Searching' after 120s, so the run may not be finalising"*, and
     the gate still exited 0. `verify-browse.py` appends *"no Next control on the browse page"*
     for months without anything acting on it.

     The convention was not chosen carelessly. `verify-reader-asks.py` and
     `verify-reader-fixes.py` were written later by the same hand and both end with
     `return 1 if problems else 0`, so the file that made them fail and the files that did not
     sit side by side without anyone noticing the difference. **The two conventions are
     indistinguishable from the outside**, because the failing one prints the same shape of
     output when it is clean.

     It was found by counting exit paths rather than by running anything: `Select-String` for
     `return 0` and `def check(` across all eighteen, which put five files in a bucket none of
     them knew about. **A gate's ability to fail is a property of its exit path, and it is
     cheap to check and invisible to review.**

     `verify-fanout.py` is the honest exception. It is a diagnostic: it runs a real fan-out and
     prints the database state afterwards, and "what did the run leave" is a question rather
     than a pass or fail. It should not be counted as a gate, and the only thing that makes the
     distinction real is saying so, which is why it is named in this list.

     The general shape, and it applies to any test suite and not only to these: **a check that
     reports without asserting is a log line.** The fix is not to make the wording stronger, it
     is to make the exit code agree with the report, so that the thing which noticed a problem
     is the same thing which turns the suite red.

     **Fixed, and the fix needed a distinction this trap had not made.** All four real gates now
     end `return 1 if notes else 0`. Three could simply be changed, because every note in them
     is a broken assertion. `verify-inflight.py` could not: one of its ten notes reads
     *"AniList import NOT browser-verifiable: anilist_accounts is empty, so the import card is
     behind 'connect AniList'"*, and that is not a defect. It is an honest statement that the
     subject cannot be looked at without a live OAuth round trip against anilist.co.

     So that gate now has two lists and two headings, `=== failures ===` and
     `=== could not verify, not a failure ===`, and only the first is fatal. **Making a gate
     able to fail is only half the work; the other half is not making it fail for something that
     is not broken**, which is the same problem as a permanently green gate wearing the opposite
     clothes. A gate nobody reads is a gate that has stopped existing whether it says yes or no.

     `verify-fanout-coverage.py` had a third fault in the same area. It declared a `problems`
     list that nothing ever appended to, printed it under its own heading, and put its actual
     findings in `notes` under a heading that reads as optional. So it advertised a category
     that could not occur and mislabelled the one that could. The dead list is gone and the
     findings print under `=== failures ===`.

     `verify-fanout.py` is still `return 0`, and that is right. It is a diagnostic, not a gate:
     it runs a real fan-out and prints the database state afterwards, and "what did the run
     leave" is a question rather than a pass or fail.

156. **A status line that cannot say "did not run" will eventually say "passed" when the
     answer is "did not run."** The runner written to measure the twenty-nine browser gates for
     the standing in this file printed **`clean`** for all eighteen Python gates, on a run where
     every single one of them had crashed with `ModuleNotFoundError: No module named
     'playwright'` before reaching its first assertion.

     Two faults, and the first one hid the second:

     - It hardcoded an interpreter path that existed but had no playwright in it. A path that
       does not exist is a loud error; a path that works but cannot import the dependency is a
       gate that never runs, which is the quiet kind.
     - The per-gate verdict was computed from the count of `[FAIL]` markers **alone**. The exit
       code was collected and then not consulted, and so was whether any check had run at all.
       Zero failures from a gate that produced zero checks is exactly zero, so `clean` was
       printed for a process that had died at import time.

     The general shape, and it is the same one as trap 155 seen from the other side. Trap 155
     is a gate that reports a problem and exits zero. This is a runner that observes a crash
     and prints a pass. Both come from **deciding the verdict from one signal**, when the signal
     available is a proxy for what was asked rather than the thing itself.

     Two habits fix it, and both are in the rewritten version:

     - **Derive a status from at least three things, and let the most specific one win.** Exit
       code, check count, and whether the output contains a crash marker. Order matters: a
       crash is tested first, because a gate that died at import has zero checks and a
       non-zero exit, which without the ordering would be reported as an ordinary failure,
       true but hiding that it never ran.
     - **Give "asserted nothing" its own bucket.** Five gates legitimately assert nothing (trap
       155) and one gate that ought to assert something and does not is a defect, and both look
       identical unless the runner is willing to say "I cannot tell these apart" instead of
       picking one.

     And when a runner writes its counts into a document, **only write the ones it measured.**
     The first version's table would have listed every crashed gate as `0`, which is not a
     measurement but would have been pasted into this file as one.

     A related detail, found while fixing it, and the reason the probe is a file rather than
     `-c` code: the runner has to launch Python through a shell to survive a space in the
     project folder's path, and **a shell splits `-c` arguments on whitespace**. So
     `spawnSync(python, ['-c', 'import playwright, sys; print("ok")'])` delivered the single
     word `import`, raised a SyntaxError, and made the runner report that no interpreter was
     available. Quoting a *path* is something a shell does correctly. Quoting *code* is not,
     and a probe that cannot run looks exactly like a probe that found nothing.
157. **A gate that fails in its cleanup reports the feature as broken when the feature is
     fine.** `verify-cbz-export.mjs` failed in the suite with `FAILED, 14 checks`, and every
     assertion in it had passed. The cause was one line of scope:

     ```js
     try {
         const PHP = 'C:/Users/<you>/.config/herd/bin/php.bat'   // block-scoped to the try
         ...
     } finally {
         spawnSync(`${PHP} ${SEED} --clear`)                    // ReferenceError: PHP is not defined
     }
     ```

     `const` inside a `try` block is not visible to its own `finally`. So the gate ran all
     fifteen checks, passed all fifteen, and then died on the line that was supposed to tidy up
     after it, exiting 1. The runner was right to call it FAILED and the report was useless:
     it printed the last line, which was the tail of a Node crash banner, `Node.js v26.7.0`, and
     not one word about what had crashed.

     Two rules, and the second is the one that would have saved the hour this cost.

     **Anything a cleanup path needs belongs where the cleanup path can see it.** Setup and undo
     belong in the same scope, at module level, not in the block that happens to do the setting
     up. This is the same shape as the `setCallback` note in trap 154: cleanup placed where the
     happy path reaches it and nothing else does.

     **Keep a failing gate's whole output, not its last line.** The runner was rebuilt to write
     every gate's full output to `logs/gates/<gate>.log` and to print that path beside a
     failure. Re-running a browser gate to find out why it failed is slow, and it is not always
     possible, because the box it ran against has moved on. Writing the log for gates that
     *pass* matters too, since the case worth reading about is the one that passed for the wrong
     reason.

     The general shape: **the exit code and the assertions answer different questions, and a
     report that shows only one of them will be read as the other.** A verdict beside a check
     count is worth reading on its own. Fifteen of fifteen passed and exit 1 is a different
     situation from none of fifteen, and it points at the last thirty lines of the file rather
     than at the feature.

     A second gate lost most of a run the same way, and this one is worth writing down because
     the log made it obvious and the status line did not. `verify-continue-reading.py` passed
     nine checks, with the reader rendering and 46 page images present, and then died on

     ```
     page.reload()
     playwright._impl._errors.Error: Page.reload: net::ERR_ABORTED; maybe frame was detached
     ```

     `ERR_ABORTED` on a reload is a race between the reload and a navigation the page starts
     itself. The library page polls, because the continue-reading feed is live, so a poll
     response landing mid-reload detaches the frame the reload was waiting on. **This is a gate
     fault and not an app fault**: a reader pressing reload is not served a broken page by it,
     and the nine checks before it had already proved the feature works.

     It is still worth fixing, because a gate that throws away every check after the crash is
     not reporting a pass and is not reporting a failure, it is reporting nothing while looking
     like a failure. All four `page.reload()` calls became `page.goto(f"{BASE}/library",
     wait_until="domcontentloaded")`, which is what the surrounding code already used, is subject
     to the same race without aborting on it, and does not wait on `load` so a slow image cannot
     hold it up either.

     The general shape, and it is the second time this has come up today: **a gate that dies
     after passing its checks has thrown away the evidence, and a reader cannot tell that from a
     gate that died because the feature is broken.** Both look like a red line. The check count
     is what separates them, which is why it is printed.

158. **A gate that skips everything reports exactly what a gate that passes everything
     reports.** `verify-browse-failure.mjs` exists to prove no browse page prints a JVM stack
     trace to the reader. It prints `SKIP` and continues when a named source is not in the
     picker, and the counter it reports on, `kotlin`, is only incremented for pages it actually
     looked at. So with both target sites renamed, or with the picker's anchors changing shape,
     it would examine nothing, increment nothing, and print **"no page showed the server an
     exception verbatim"**, exit 0, and be correct by accident.

     That is `every()` over an empty array wearing a different hat, and it has the same fix:
     count the subject and refuse to report on none of it. The gate now tracks `examined` and
     `skipped` separately and has three outcomes rather than two:

     ```
     a stack trace shown to a reader            -> fail
     no stack trace, across every target        -> pass
     no target examined at all                  -> fail, and say why
     ```

     The third was previously reported as the second. **"I found nothing" and "there was nothing
     to find" are different results**, and a gate that cannot tell them apart will eventually
     report the bug it was written to catch as fixed, having never looked at the page. Verified
     both ways: `verify-browse-failure.mjs "A Site That Does Not Exist"` exits 1 and prints
     "no page was examined", and the real run exits 0 having examined two pages.
159. **A gate that probes whatever URL is in the DOM measures the wrong thing if it reads too
     early, and reports it as the app's arithmetic being wrong.** `verify-covers.py` failed five
     checks on a loaded box:

     ```
     [FAIL] the upload reports success        (detail: a list of chapter names)
     [FAIL] the cover now points at our route   mi/api/v1/manga/76/thumbnail?format=webp
     [FAIL] it is bounded at 800 on the long edge  800x1200
     [FAIL] the proportions followed from it      800x1200
     [FAIL] adding it to the library works
     ```

     Four of those five were one race. The gate slept 3.5 seconds after `set_input_files`, which
     is Livewire's temporary upload plus the component update that decodes, rotates, resamples
     and re-encodes a 1000x1500 image. Under load that is not enough, so at the moment it read:

     - the success toast had not been dispatched,
     - the cover `img` still carried **Suwayomi's** thumbnail, and
     - the byte probe, which fetched whatever `src` it found, therefore measured **Suwayomi's
       800x1200 thumbnail** and compared it against the expectations for a resized upload.

     So `800x1200` was never the app's output. `CoverStore` computes
     `scale = min(1, 800 / max(1000, 1500)) = 0.533`, giving 533x800, which is precisely what the
     gate expected and precisely what it then measured once the wait was fixed: `533x800`.
     **A number that is exactly wrong in a way that is easy to explain is usually the wrong
     number**, and 800x1200 is 1000x1500 scaled to a width of 800, which is not what any code
     here does.

     What made this convincing rather than merely likely: the same gate had passed 23/23 earlier
     the same day, and no application code had changed since, because `git diff HEAD -- app
     config database resources routes tests` was empty. **When a gate fails a check it passed
     before, and nothing in the code changed, the gate changed or the machine did.** The default
     assumption should be the gate, because a gate is a script with a sleep in it and the
     application is a thing that was already reviewed.

     The fix is a `settle()` helper that polls a predicate to a timeout instead of sleeping a
     fixed number of milliseconds, and the six call sites that used a sleep now wait for the
     thing they are about to assert. Not one of them got slower: they got correct. A sleep is a
     claim about how long something takes, and the machine is not a constant.

     The general shape, and it is worth stating plainly because the failure was expensive to
     read: **a probe that reads state is measuring the system at the moment it reads, and
     nothing about that moment is guaranteed by anything else in the file.** Polling for the
     condition being asserted is the assertion. Anything else is a guess with a duration
     attached to it, and the guess is only correct on an idle box.
160. **A budget set to the measured rate is not headroom, it is the same number wearing a hat.**
     `verify-library-check.py` failed with `'All 21 series checked' after 843s for 21 series,
     40.2s each` against a ceiling of `max(420, total * 40)` = 840s. Three seconds over, on a job
     that completed correctly and rendered exactly the string the gate was looking for.

     That ceiling was itself a repair. It was 420s and the pass took 423s for 20 series. So the
     first fix raised it to 40s a series and it was still three seconds short, because the
     multiple only accounts for the per-series work and none of everything else: the page loads,
     the assertions that run before the pass, and the last series finishing after the final
     slice delay. **A multiple of the observed rate has no slack in it by construction**, so
     raising the rate is the same mistake with a bigger number.

     What ships is a base plus the multiple: `300 + total * 70`. The base carries the fixed
     costs and 70s a series against a measured 40.2s is about 1.7x. Generous on purpose, because
     this gate runs for a quarter of an hour and a false failure costs more than a slow pass.

     The same loop had a second fault, and it is the one worth remembering. It polled by
     reloading the whole page, so over a fourteen minute pass it issued around **two hundred full
     page loads of a page whose entire job is to be heavy.** Polling harder made the job it was
     waiting for slower. Now every eight seconds rather than every four, and the state being
     watched only changes when the pass finishes anyway.

     And the count drift was this gate's own doing, made visible at last. It checks one series
     inline before the full pass, which makes that series no longer due, so the button loses one.
     Read before the inline check settled, `total` was the pre-check count and the pass completed
     one short of it, reported as "a full pass leaves nothing due" failing on a pass that did
     exactly what it was asked. That is why `total` read 21, then 20, then 19 across three runs
     with nothing in the app changing, which looked like a fact about the library. It now waits
     for the button to actually change rather than for a fixed time, and prints the count.

     The general shape, across all three: **a timeout, a poll interval and a precondition are
     all claims about how long something takes, and none of them is a measurement of whether it
     finished.** Wait for the thing. Where a deadline genuinely has to exist, give it a base for
     the fixed costs rather than trusting the marginal one.

     **Superseded on the specifics, kept for the shape.** The 40.2s a series quoted throughout this
     trap was itself the artifact, for the reason set out in trap 164: it was the gate's elapsed
     time divided by its series count, so it contained the gate's own polling. The real per-series
     cost is about 0.7s and the gate runs in 54 seconds. The lesson stands and the numbers do not,
     which is worth noticing, because **a trap that teaches correctly from figures that turn out to
     be wrong is still a trap that misleads.** The durable half is the last paragraph: wait for the
     thing rather than for a duration.
161. **Stripping a URL is not parsing it, and the difference is a query string in a place a
     reader will see it.** The browse picker groups sources by site, and the key was built by
     removing the scheme with a regular expression and taking the rest:

     ```php
     $host = (string) preg_replace('#^https?://#', '', rtrim((string) $host, '/'));
     ```

     Measured across the 84 installed sources, one of them is published as
     `https://lhtranslation.net/manga/?m_orderby=views`, so the group's key, and the host
     printed under the site name, was

     ```
     lhtranslation.net/manga/?m_orderby=views
     ```

     A reader was shown a query string as a domain. Worse, the grouping **key** depended on it, so
     an extension changing its default sort order would silently split one site into two groups
     and neither reader nor maintainer would know why.

     `parse_url($homeUrl, PHP_URL_HOST)` is the whole fix. **A host is a component of a URL, and
     asking for the component is a different operation from deleting the parts you did not
     want.** Two tests, both verified to fail without the change: one that a path and query do
     not survive into the displayed host, and one that two sources on one host with different
     paths share one group and the group says "2 sources".

     Found while chasing a gate that reported "expected 16 site groups, saw 18". That was a stale
     number in the gate, and the probe written to settle it produced this instead. **A
     diagnostic asked a simple question and returned something nobody was looking for**, which is
     the argument for writing the probe even when the expected answer is obvious.
162. **A page said a word in two unrelated places and a gate watched for the word.** The search
     page renders "Searching" twice:

     ```
     L898   permanently, above the button:  "Searching :count sources"   (how wide the fan-out is)
     L934   during a run, on the progress:  "Searching :count of :total sources"
     ```

     `verify-inflight.py` decided the run had finished with `"Searching" not in body`. That is
     never true. The only way through was the `or "checking" in body.lower()` escape hatch, and
     the word "checking" does not appear either, so **the check could not pass at all** and the
     gate sat reporting "still Searching" for its entire budget, then exited 0 because it could
     not fail (trap 155).

     Measured from the other side, the run was healthy throughout: the two runs the gate started
     both completed 40 of 40 sources, in 178s and 165s, while the page was being told they had
     not. So this is the most expensive shape a wrong gate takes. Not a flake, not a slow box,
     and not a broken feature, but a check that was unsatisfiable and had been for as long as
     the fan-out width line existed. **A structural impossibility in a gate reads exactly like a
     performance problem**, because both present as "it says it is still working".

     It waits now for `Searching \d+ of \d+ sources` to disappear, which is the string that is
     actually about the run.

     The rule this is an instance of, and it is the same one as trap 159 from the other side: a
     probe reading a whole page and matching a substring is reading the page, not the thing.
     Scope the match to the subject, because the page is full of other sentences.

163. **A gate that typed into a debounced field and waited less than the debounce ran a
     different search than the one it meant to.** The query input is
     `wire:model.live.debounce.500ms`. `verify-inflight.py` filled it and waited 400ms before
     clicking submit, so the value had not reached the server.

     This one was hiding behind another. The gate believed it had searched "one piece" and
     measured something else, and the something else was larger, and the run overran its budget,
     and the budget overrun looked like a stuck run. Three faults in a chain, each pointing at
     the next. Waiting 1200ms fixed it, and the database then confirmed `query: 'one piece'` on
     the run.

     **A wait shorter than the debounce it is racing is not a wait.** The same shape as trap 160:
     where a duration is load-bearing, read it from the definition rather than picking something
     plausible.

     And while measuring this, the run timings gave the answer to a question that had been open
     all day. Over the 40 most recent finished runs on this box: **fastest 14.0s, median 109.5s,
     slowest 211.0s, with 18 of the 40 over 120 seconds.** The gate budget was 120s, which is
     the median, so it failed roughly half the time by construction. `verify-fanout.py`
     independently drove a run to `complete, 40/40, results: 421`, which is the same thing said
     from the other direction.

     **The gap this trap opened has been closed.** `$query` on the search page now carries
     `#[Url(as: 'q', except: '', history: false)]`, and `mount()` runs the search when a query is
     present, so a bookmarked link is a bookmarked search rather than a pre-filled box. Two
     details are worth keeping.

     `history: false`, because its neighbours do not use it and copying them would have been
     wrong. `$langs`, `$content` and `$anilistOn` are chips, where one history entry per change
     is what a reader wants. This one is `wire:model.live.debounce.500ms`, so a reader typing
     "one piece" settles two or three times, and `push` would fill the back button with
     keystrokes. The rendered `wire:effects` now reads `{"query":{"as":"q","use":"replace"}}` and
     a test asserts exactly that, because the difference between the two modes is invisible from
     PHP and is the entire point of the setting.

     The second half is the part that fixing only the attribute would have missed. With `#[Url]`
     alone, a bookmark restored the box and the filters and showed an empty results area, which
     reads as a broken page rather than as a search waiting to be run. The reader asked for
     those results by saving the link, and making them press Search again is asking twice.
     **A URL that describes a state is only half a feature; something has to act on it when it
     arrives.**

     The gate that found the gap now asserts it, so it cannot quietly reopen: after submitting,
     the address bar must carry `q=`. Measured after: the submit lands on
     `/search?content[0]=SAFE&content[1]=MIXED&q=one+piece`.

     And a note on how the test for it was written, because two attempts were wrong before the
     third was right. Asserting `value="one piece"` in the markup fails, because a `wire:model`
     input takes its value from the component snapshot rather than from server-rendered HTML.
     Asserting against a `Livewire::test()` component to reach the URL asserts on a framework
     internal and would have quietly proved nothing. What the page does render is
     `wire:effects`, which is the binding contract itself, and it has to be HTML-entity decoded
     before a regex will see it because the attribute is written escaped.
164. **A rate invented to justify a ceiling that had already been raised is the same mistake as a
     typed-in expected count, and it survives three rounds of measuring the wrong thing.**
     `verify-library-check.py` was written off as unmeasurable. The reasoning, which was recorded
     here as a finding rather than a suspicion, was that a full library check "takes up to 27
     minutes" and that no ceiling is both honest and short:

     ```
     420s fixed                -> 423s for 20 series, 21.2s each, missed by 3s
     max(420, total * 40)      -> 843s for 21 series, 40.2s each, missed by 3s
     300 + total * 70          -> 1639s for 19 series, 86.3s each, missed by 9s
     ```

     The per-series cost "went 21s, then 40s, then 86s on the same code with no deploy in
     between", which was read as the box being variable. **It was the gate's own arithmetic.**
     Every one of those numbers was `elapsed / total`, where `elapsed` runs from the click to the
     moment the gate noticed the answer, so it counted the gate's own polling: a `page.goto` of a
     heavy `/library`, a 1.5 second wait and a 6.5 second wait, once every eight seconds. A number
     that contains the observer cannot be used to measure the observed.

     `probe-check-cost.php` calls `CheckLedger::check()` once per series in-process and times each
     one, which takes the gate out of the measurement entirely:

     ```
     10 series: median 718ms, min 368ms, max 1129ms, mean 739ms
     ```

     That is what `CheckLedger` had said in its own comments from the start (361ms to 1617ms,
     median 876ms). The gate runs in **54 seconds** and passes 22 of 22.

     Three things generalise, and the first is the one that cost the weeks:

     - **Measure the subject, not the measurement of the subject.** A stopwatch around your own
       polling measures the polling. When a number disagrees with the code's own comments, the
       cheapest next step is the one that settles it, and it took about ten minutes.
     - **A remembered rate in a comment is not weaker than a remembered rate in an assertion.**
       Both are asserted and neither is checked. This one was written down, believed, cited in the
       notes as a measurement, and used to raise a ceiling three times.
     - **"It varies with load" is a conclusion, and it is usually the conclusion you reach when you
       have not found the constant yet.** Three data points that disagree are not a trend.

     The earlier version of this trap ended by deferring to a product decision: smaller slice,
     faster check, resumable job, or accept. **There was no product decision to make.** The feature
     was never slow, and the honest cost of finding that out was one probe rather than a redesign.

     Two smaller things in the same gate. It polls by reloading, and it used to do so every four
     seconds, which over a quarter of an hour is several hundred full page loads of the heaviest
     page in the app, made slower by the polling. Now every eight. And the count it asserts on
     moved 21, then 20, then 19 across three runs because the gate checks one series inline
     before the full pass, which makes that series no longer due; it now waits for the button to
     actually change and prints the count, so a moving number is visible as a moving number.
165. **A run against a broken environment reports the environment as eight regressions, and the
     tool that printed the table did not know the difference.** The queue worker and Suwayomi
     were down when a session resumed, so they were started. Vite was not, and was missed,
     because the check was "does the page reference the dev server" and the page was being served
     from `public/build` for the eight gates that ran before anyone noticed.

     The result: `verify-browse.py`, `verify-card-bookmark.mjs`, `verify-cbz-export.mjs`,
     `verify-continue-reading.py`, `verify-covers.py`, `verify-downloads.py` and
     `verify-drift-notice.mjs` all failed or crashed, every one of them a gate that had passed
     hours earlier with no code change in between. Unstyled pages have no JavaScript, so every
     locator-driven gate breaks at once and the failures look like a deploy went wrong.

     What makes it worth writing down is not the mistake, it is that **the runner reported it as
     a result.** It had a preflight for the Python interpreter (trap 156) and none for the three
     processes the gates actually need, so it cheerfully produced a table of eight failures it
     had caused.

     It refuses now. Before the first gate it checks that Suwayomi answers, that the app answers,
     and that **the stylesheet the page itself asks for** can be fetched, and exits rather than
     starting. The third one is the part worth copying: the assets are served from the dev server
     over IPv6 localhost, so the URL in the page is `http://[::1]:5173/...` and a hand-written
     probe against `127.0.0.1:5173` gets a connection refused that reads like a different fault.
     Asking for the stylesheet by the URL the page asked for is the only version of the question
     that is actually about the app.

     **Verify the thing the tool will report on, before it reports.** An interpreter probe when
     the gates are Python, a stylesheet probe when the gates drive a browser. A preflight that
     checks something adjacent is worse than none, because it converts an unknown into a false
     all-clear.

     With Vite up, `verify-search-syntax.py` passes 28 checks, and the three "skipped sources"
     failures it had reported were the same cause. **Three failures that all pointed at one
     feature, in a run where seven unrelated gates had also failed, was a broken box and nothing
     else** - and it would have been very easy to spend the afternoon on a skipped-source bug
     that does not exist.
166. **A probe that changes state and does not put it back will make the next gate report a
     defect that is not there, and the failures will all be true.** This one cost an afternoon
     and it was mine.

     `probe-star-render.mjs` clicked the fourth star on series 76 to see what the star row
     rendered, read it, reloaded, read it again, and exited. It left four stars behind.

     `verify-rating-tags.mjs` then failed four checks and looked, to me, like a gate
     contradicting itself:

     ```
     [FAIL] nothing is checked when the series is unrated
     [FAIL] an unrated row clips every star away entirely    clips: 0, 0, 0, 0, 100
     [FAIL] clicking the fourth star checks exactly it        checked: .....
     [FAIL] the rating survives a reload
     ```

     Every one of those is a **correct observation of a series that was already rated four**, and
     the reason they looked like contradictions is that they contradict *each other*: two checks
     saying the row is unrated and two saying it is four stars, on the same page. The click check
     is the one that gave it away. `checked: .....` on a click of the fourth star is not a broken
     radiogroup, it is a **tap-to-clear**: clicking a star you have already chosen clears the
     rating. The feature working as designed produced the failure.

     A probe of the live DOM proved the app right, with the database confirming no rating existed:

     ```
     unrated page : --shown 0, all five clipped away, every aria-checked false
     after click  : --shown 4, stars 1-4 filled, exactly one aria-checked true
     after reload : identical, so the write was a write
     ```

     Note that one `aria-checked="true"` in a five-star row is **correct**. A radiogroup has one
     selected member, not five, and a gate expecting four checked stars is asserting the wrong
     shape.

     Then the gate passed 34 of 34, twice in a row.

     Two fixes, and the second is the one worth keeping:

     - **The probe clears in a `finally`.** Trap 142 is a gate leaving state behind. This is the
       same thing in a diagnostic's clothes, which is worse, because a diagnostic looks like
       something you are free to run.
     - **The gate establishes its own empty state before asserting it.** Its cleanup ran at the
       end and only *counted* a leftover rating rather than clearing one, so any run that died
       before reaching the clear button poisoned the next one. **"Unrated" is not a fact about the
       app, it is a fact about what the last run left**, and a gate that asserts an empty state has
       to make it empty rather than assume it.

     The general shape, and it is the reason this one was so expensive: **the cheapest thing to
     believe about a gate failure is that the gate is wrong**, and that belief is usually right, so
     it gets acted on. What would have caught it in two minutes was the database. Before changing
     a gate because its assertions disagree, ask what state the assertions were made against, and
     whether anything you ran today wrote to it.
167. **A gate that fails a different check on each run has one fault, not several, and it is
     usually leaking into its own next step.** `verify-october-pass.mjs` reported one failure per
     run and the failing check moved:

     ```
     run 1:  pressing a read toggle flips it
     run 2:  pressing it again clears it
     run 3:  pressing a read toggle flips it
     ```

     Two checks in different places, on different pages, cannot both be intermittent for
     unrelated reasons. Both were the same fault: an `aria-pressed` attribute read a fixed number
     of milliseconds after a click, on a Livewire round trip that does not reliably finish in
     300ms on a loaded box.

     ```js
     await english.first().click();
     await sleep(300);
     check("pressing it again clears it", (await english.first().getAttribute("aria-pressed")) === "false");
     ```

     That is trap 159 for the third time in this file, in a third gate, which is the point worth
     recording: **a sleep is a claim about how long a request takes, and twenty-one of them in one
     file is twenty-one chances to be wrong.**

     The part that made it look like two problems is that **the first race poisoned the gate after
     it**. The second pill click was a restore, and nothing checked that it had landed, so a
     round trip that had not finished left the English language filter switched on. The next thing
     this gate does is search, and it searched under whatever filter state it had inherited. So
     one fault produced a failure in the pill and a different failure in the search that followed.

     **A restore nobody verifies is not a restore.** It is an intention, and the next check reads
     whatever the race left behind. Both toggles now restore inside a `finally`, wait for the
     state to come back, and assert it:

     ```
     [ok] the language filter was put back
     [ok] the read flag was put back
     ```

     23 of 23, three runs in a row, having previously failed one check per run.

     The diagnostic worth keeping, because "flaky" is where a gate gets abandoned rather than
     fixed: **a gate that fails a different check each time is telling you where the fault is not.**
     Two failures in two places narrow it to whatever they share, and what two Livewire toggles
     share is a round trip and a sleep.

168. **A gate that crashes on a selector was clicking the wrong element, and the wrong element was
     three faults at once.** `verify-october-pass.py` died on check 8 of 24 every single run:

     ```
     playwright._impl._errors.TimeoutError: Locator.click: Timeout 30000ms exceeded.
       - waiting for locator("form button[type=\"submit\"]").first
         - locator resolved to <button type="submit" data-test="logout-button" ...>
       - element is not visible
     ```

     The account menu's logout item is a submit button inside a form. It is hidden when the menu
     is closed. `form button[type="submit"]` matches it, because a valid selector matching a
     hidden element is not a wrong selector, it is a selector that has not been made specific.
     The same one string was behind three separate symptoms:

     | Where | What it looked like | What it was |
     | --- | --- | --- |
     | the click | `Timeout 30000ms exceeded` | a hidden button, waited on for 30s |
     | `styled()` | reported "unstyled" on a styled page | `bounding_box()` of a hidden element is `None` |
     | the login click | would have signed out the gate | whichever submit came first in the document |

     `verify-search-syntax.py` had already been fixed for exactly this and was sitting next to it
     with the scoped form to copy. **A fix applied to one gate and not the sibling that shares the
     selector is a defect waiting for a run**, because the selector is duplicated precisely in the
     places nobody looks after the first one works.

     Two more in the same file, both the same mistake in a different costume:

     - **It looked for a toast's text in the body.** `updateAll()` reports through `Flux::toast`,
       and a toast is gone in seconds. Reading the body a moment later finds nothing, so the check
       reported a missing feature on every run. **Assert the control, not the notification of it.**
       A button is durable; a toast is a rumour.
     - **It asserted one of two literal strings for a thing that has two states.**
       `"Connect AniList" in body or "ANILIST_OAUTH_CLIENT_ID" in body` failed because this reader
       *is* connected, and a connected account is shown a **Disconnect** button. That is the gate
       asserting a fixture instead of a behaviour. The claim worth making is that the card offers
       the action appropriate to the account's state.

     And one that was a timeout rather than a wrong element: `results, deduplicated` matched only
     the plural of a `trans_choice` that also has a singular form, so a search that found exactly
     one result read as a search that never finished. **A gate that matches half a plural fails on
     success**, which is the same shape as a budget set at the median (trap 163): both are
     assertions that only hold for the common case.

169. **A gate that reads a page before the write it is asserting on will fail for reasons that
     look like the app's.** `verify-continue-reading.py` seeds a series with read state and then
     asserts that the feed card moved. Three checks started failing at once, all reporting the
     same thing:

     ```
     reading moves the destination   None -> None
     and the card reports the progress  wanted 'of 49, 3 read' in ''
     a gap is offered rather than skipped
     ```

     Three failures whose message was an empty string is not three findings. The subject series
     was resolving in the app, to "continue at 1 of 6", and was absent from the page.

     The feed renders six cards ordered by `last_read_at` descending, and the seeder's subject is
     required to have **no** last-read pointer so the read set is the only thing that can move it.
     No pointer means the series was never opened, which means its series timestamp is null, which
     means it sorts behind everything the reader has touched. It was the seeder's own requirement
     that put the subject outside the window the page draws.

     It passed for as long as it did because fewer than six other series had been opened. **The
     gate was green by coincidence of data volume, and nothing in it noticed the coincidence
     going away.** Earlier runs of the library-check pass and the October pass opened six series,
     and the gate started failing on a fixture it had never checked was reachable.

     Two fixes, and the second is the one that generalises:

     - The seeder stamps its subject with `last_read_at = now()` and says so. `now()` is the
       largest value the sort can see, so the subject leads the feed without anyone's state being
       cleared. This is declaring the feature's precondition rather than faking state: `openChapter`
       is the only thing that sets a series timestamp in normal use and it sets a pointer at the
       same time, so "timestamp without a pointer" is not a state a reader reaches.
     - **The gate reloads after seeding.** It was reading the card off a page loaded before the
       write, which is trap 167 one level up: read the value before the click that changes it and
       the wait-for-change can never fire.

     And a check that names the precondition, so the next occurrence is legible in one line rather
     than inferred from three empty strings: "the series the seeder moved is in the feed".

     The general shape, and it is the fourth time this suite has hit it: **a fixture that depends
     on a bounded set has to assert it is inside the bound.** Six cards, forty shelves, a page of
     twenty, a feed with a limit. Every one of those is a place a gate's subject can fall out of
     sight, and a gate that assumes it is inside reports an empty string when it is not.

170. **A route that answers bytes must not answer with a document, and a gateway must not report
     an upstream failure as its own.** `verify-continue-reading.py` failed three runs in a row on
     "no console errors" with three HTTP 500s and said nothing else. `probe-5xx.py` replayed the
     gate's own reader loop and printed them:

     ```
     500 GET http://papyrus.test/suwayomi/api/v1/manga/7/chapter/2/page/0?format=webp
         <!DOCTYPE html> <html lang="en" class="dark"> <head> ...
             <meta name="csrf-token" content="nsk3SRATwyla9jVBj9uw6GyVlSJPLsMkIPWrm6Vl" />
     ```

     The whole authenticated layout, **CSRF token included**, as the body of a request the browser
     was about to treat as a picture. Two faults, and the first was hiding the second:

     | | Was | Now |
     | --- | --- | --- |
     | body | the app's error page, 20KB | `text/plain`, one line |
     | status | whatever Suwayomi said, so 500 for a blocked source | 502, with 404 kept |

     `abort($response->status(), ...)` is the right tool for a web page and the wrong tool here.
     Laravel's handler renders the application layout, and this route exists to serve `<img>`.

     The status is the more interesting half. Suwayomi answers 500 when the manga source behind a
     page is blocked or down. Relaying that verbatim told the browser **Papyrus** had failed, when
     the app had done its job and something it depends on had not. That misreport is why this
     surfaced as a console error on a gate whose subject was the continue-reading feed rather than
     anything a blocked source was doing, and why it read as a gate fault for three runs before
     anyone looked at which request it was.

     Two things worth keeping:

     - **A status-code assertion is not enough on its own.** The 404 case was already correct
       before the change, so a test on status alone would have passed on half the fix. The body is
       asserted separately, and that is the test that fails without the change.
     - **The same `abort()` was on the allowlist rejection**, one branch up, returning HTML for
       the same consumer. Fixed for the same reason rather than left as the branch nobody had
       reported.

     The tests were also wrong in a way worth naming, because it is the trap 161 shape: the
     existing `Http::fake()` in `setUp()` registered a catch-all, `Http::fake()` **appends**, and
     the first stub registered wins. So four new tests each re-faking the same pattern were
     answered by `setUp()` and reported `200` for a `502`. The fake now reads the status from a
     property at request time, and the comment above it says why.

     **A test that cannot fail is worse than a test that fails, because it is found later.**

171. **A green suite run in batches is a weaker claim than a green suite run in one go, and the
     batching is usually why nobody can tell the difference.** The runner's own guidance was to
     split the suite, on the grounds that three gates took seventy of its seventy-five minutes.
     Two of those three figures were wrong (trap 164) and the whole suite turns out to run in
     **33 minutes, green, as one run**.

     So the advice was harmless and the belief behind it was not. It was also load-bearing in a
     way nobody intended: **batches hide order dependence**, and order dependence is only
     observable when gates run in the same session against the same database.

     The first full single run found three failures that each passed alone and none of which
     passed alongside the others:

     ```
     verify-continue-reading.py   a fully read series leaves the feed entirely
     verify-library.py            filing adds a chip  card 0: 0 then 0
     verify-rating-tags.mjs       removing one tag keeps the other
     ```

     All three were a fixed sleep read too early, which is trap 167 for the fifth and sixth time.
     But that is the small half. The large half is that the fixture behind one of them had been
     quietly searching for a library in the right shape, and other gates were the ones reshaping
     it: `seed-continue-reading.php` required a subject with no last-read pointer, `verify-library.py`
     opens readers, and once a full run had opened every library series the seeder found no
     qualifying subject at all and exited. **The seeder established nothing and depended on
     everything.** It now sets its own two preconditions and prints that it did.

     Two rules, and both are about what a passing run is evidence of:

     - **Run the whole thing at least once, end to end, before believing any of it.** A suite
       validated only in batches has an untested claim in it: that the parts do not interfere.
       "No batch has ever failed" is not the same statement as "it passes".
     - **A fixture that hunts for a library in the right shape is a fixture whose shape is
       somebody else's decision.** Set up what you need, say you set it up, and let the next
       reader of the state know.

172. **"Zero em dashes" measured on the file you just edited is not a claim about the repository.**
     The standing carried "zero em dashes" through a long run of commits, and it was reported as a
     standing rather than as a habit, which is what made it read as repo-wide. It was not. What had
     actually been measured, each time, was the one documentation file being edited that day.

     Asked properly, with two tools because neither covers the whole tree:

     ```
     php .tools/suwayomi/scan-dashes.php   0 em, 0 en, 5 ellipses across 292 files
     git grep -n -P '\x{2014}' -- .        three hits
     ```

     ```
     app/Downloads/CbzExporter.php:167       ... cleanup that can be tested at all <em dash> setCallback ...
     tests/Feature/CbzWriterTest.php:25      ... a reader depends on <em dash> the names, the byte-for-byte
     tests/Feature/CbzWriterTest.php:26      ... contents, and the CRCs <em dash> rather than the bytes ...
     ```

     Spelled out rather than quoted, because a trap about em dashes that contains three of them
     is a trap that puts three back. The mark is `<em dash>` and the line numbers are the ones the
     grep printed.

     Three, in files from the CBZ export work, invisible to a scan of the docs. The scanner covers
     `app`, `database`, `tests`, `config`, `routes` and `resources`, and **not `docs/`**, so the
     two checks are not redundant and neither is sufficient.

     Two things worth keeping from it:

     - **A standing is a claim about everything it names.** "Zero em dashes" names no scope, so it
       is read as the repository, and it has to be measured that way or it should say which tree it
       covers. Both tools and both scopes are now in the standing, so the next reader can rerun
       them.
     - **The scanner counted five ellipses and all five are correct.** They are `'…'` as a
       truncation marker in output strings, each with a matching assertion in a test. A byte scan
       that finds something is the start of an inspection, not the finding: `substr($key, 0,
       20).'…'` reads as `substr($key, 0, 20).'.'` in a cp1252 console, so the console said one
       character and the bytes said another. **Trust the byte scan, including when it disagrees
       with what you can see**, which is the same instruction already in section 6 for the PowerShell
       version that reported zero in a file that had thirty.

173. **A gate can fail and still be invisible, and the two faults are not the same size.** The
     standing said eight gates asserted nothing and could not fail (trap 155). Asked what each one
     actually did, the answer was not eight of a kind:

     | | could fail | printed `[ok ]`/`[FAIL]` |
     | --- | --- | --- |
     | 6 gates | yes, `return 1 if notes else 0` | no |
     | `verify-cover-reveal.mjs` | yes, `process.exit(failures ? 1 : 0)` | no, it printed `PASS`/`FAIL` |
     | `verify-fanout.py` | **no**, `return 0` unconditionally | no |

     So six of the eight were fine and merely unreadable, one was fine and used its own
     convention, and exactly one was genuinely inert. The notes had them all in one bucket because
     the bucket is what the runner could see, not what the gates were.

     **The runner counts `[ok ]` and `[FAIL]` markers, so a gate that asserts correctly and
     prints its findings its own way is counted as silent.** That is a reporting convention
     masquerading as a capability claim, and it made the suite look worse than it was while making
     the standing impossible to trust. Six gates were doing real work the whole time.

     What fixing it found, which is the part worth having:

     - **`verify-fanout.py` could not fail at all**, and its own docstring says what it exists for:
       "asks the database directly whether the run finished and whether every source reported."
       Nothing asserted either half. Both hang defects it was written to catch, a run stuck at
       `status = running` and one whose `sources_done` never reaches `sources_total`, were
       undetectable by the gate written to detect them.
     - **Its first run failed, and it was the gate that was wrong.** The poll looked for
       `status: done` and `status: failed`; the app writes `complete`. So it reported a run that
       had finished perfectly, with 40 of 40 sources reporting, as hung. **A gate that reports
       healthy runs as hangs is worse than one that reports nothing**, because it will be believed
       the first time a real hang appears and dismissed as flakiness.
     - **`verify-cover-reveal.mjs` was the healthiest of the eight** and the standing could not
       tell. It counted its surfaces, printed `PASS` per surface and exited non-zero on any. Ten
       assertions the whole time.

     The lesson is about the shape of the standing rather than about gates:

     - **A bucket that says "cannot fail" is a claim about a runner's parsing, not about a suite.**
       Before writing it down, ask each gate what would make it red and be able to answer. Six of
       eight could not be described that way.
     - **Converting a gate to a counted convention is not cosmetic.** It took `check()` helpers in
       eight files and it immediately surfaced a false hang report that had been sitting in a log
       nobody read. **A finding nobody can see is not a finding, and a convention nobody counts is
       not a verdict.**
     - Trap 155 stays, because two gates still cannot fail and the reader-asks section still skips.
       What retired is the claim that eight of twenty-nine were inert, which was wrong.

174. **The fixed-sleep fault is countable, and it has been counted: 29 places.** A `wait_for_timeout`
     after a click, followed by a read of what the click changed, is the shape that has produced a
     false failure in seven gates now. Each time it read as a feature that stopped working, because
     a check that reads too early and a check that is right print the same thing.

     The seven, all confirmed by fixing them:

     | Gate | Was | Now |
     | --- | --- | --- |
     | `verify-october-pass.mjs` | 2000ms after a read toggle | waits for the value to change |
     | `verify-library-check.py` | 1000ms after a click, then read a toast 120s later | polls for the toast |
     | `verify-library.py` | fixed sleeps in both filing directions | polls each direction |
     | `verify-inflight.py` | 2000ms for selection-mode checkboxes | polls |
     | `verify-fanout.py` | 120s budget, read `"Searching"` that is never absent | polls the progress string |
     | `verify-october-pass.mjs` | 1200ms after the Updates tab | `waitFor()` |
     | `verify-notes.py` | 1800ms after saving a note | polls until the editor closes |

     The last two were found by the 29 of 29 run after this one, which failed on
     `verify-notes.py` "saving closes the editor" and `verify-october-pass.mjs` "the updates view
     offers update-all". Neither carried a detail string, so the reports read as missing features.

     **Twenty-nine more places have the same shape and are listed rather than fixed**, because
     converting them blind would be a large change to gates that currently pass:

     ```
     verify-reader.py             12    verify-fixes.py                 1
     verify-notes.py               3    verify-drift-notice.mjs         1
     verify-search-anilist.py      2    verify-library.py               1
     verify-browse.py              2    verify-covers.py                1
     verify-reader-asks.py         2    verify-october-pass.py          1
     verify-migrate.py             2    verify-october-pass.mjs         1
     ```

     29 across 12 gates, counted by running the audit and tallying its output rather than by
     reading the list and adding it up. The first version of that block said `verify-reader.py 8`
     where the audit says 12, and summed to 15 against a stated 29, because it was written from
     memory of a long output rather than from the output. **A tally is arithmetic, and arithmetic
     in prose is exactly the thing this document keeps catching.**

     How the count was made, so it can be remade: a fixed wait of 300ms or more whose **preceding
     line is a click** and whose following three lines read a value. The wait must be at least
     300ms and the preceding line must be a click, because a wait after a page load is not the
     fault and a wait after a check is not either. The audit was validated against a synthetic
     instance before its silence was believed, which is worth doing with any audit whose result is
     "nothing found": **a check that reports zero is indistinguishable from a check that is
     broken until you watch it catch something.**

     That same audit caught this: `page.waitForTimeout` does not exist in Playwright for Python, so
     a process count of `java` processes was being read as evidence that Suwayomi was up. It was
     two Android Studio JVMs, and Suwayomi was down. The runner's preflight reads the actual
     endpoint and refused to start, which is why the suite reported an environment failure rather
     than twenty-nine red gates. **My ad-hoc check was satisfied by the wrong process; the tool's
     was not.**

175. **A backup format that looks open-source is not necessarily readable, and a file that opens is
     not a file that is safe to load.** Tachimanga's `.tmb` turned out to need no decryption at
     all: byte zero is `PK\x03\x04` and `ZipArchive` opens it. The work was not in the crypto, it
     was in three places nobody warns about. It is a zip inside a zip, so a reader that opens the
     outer archive and looks for the database finds nothing. It holds a 113.5MB SQLite file
     against this server's `memory_limit` of 128M, so `getFromName()` on it is a 113MB PHP string
     and it has to be streamed to a temp file and opened by path. And **the library is 1,277 rows
     inside a table of 132,185**: the rest is catalogue the app cached while browsing, so a plain
     `select * from Manga` files a reader's entire browsing history as their shelf with no way to
     tell afterwards. Measured, not assumed, and each of the three is invisible until it is
     measured.

176. **`sprintf` with more arguments than placeholders fails silently, and the substitution lands
     somewhere unrelated.** Building a SQL query as a positional `sprintf` list, one argument too
     many, put `is_downloaded` into the `FROM` clause. PHP accepts surplus arguments without
     complaint, and the only symptom was `no such table: is_downloaded` naming a table that
     exists. Three guesses at column names were wrong in this one reader (`History.manga` is
     `History.manga_id`; `Manga` has no `is_delete`; `is_delete` exists on five of twenty tables),
     so the pattern is the finding as much as the bug. **Use named PDO parameters, and `pragma
     table_info` to assert the columns a query binds before running it.** SQLite's message for a
     missing *column* is `no such table: <the column>`, which is actively misleading, so the guard
     names what is missing in words instead.

177. **A test whose only example already satisfies the invariant cannot catch its removal.** The
     URL-normalisation check asserted that a series came back as `/comics/hiding-a-logistics-...`,
     and the one fixture row that survived the `in_library` filter was stored without a trailing
     slash, so the raw value and the normalised value were identical. Deleting `normaliseUrl()`
     outright left all eighteen tests green. It was caught by mutating the source and re-running,
     the same discipline as validating an audit against a synthetic instance, and the fix was a
     fixture whose rows disagree with each other. **This is trap 173 again, in a different coat: a
     check that cannot fail reads exactly like a check that passes.** The specific version here is
     that a fixture convenient to write is usually a fixture that agrees with the code.

178. **`ZipArchive::close()` throws on an archive that never opened, and a `finally` runs on an early
     `return`.** A reader's `recognises()` opened the archive inside a `try` and closed it in the
     `finally`, so every file that was not a zip at all died with `ValueError: Invalid or
     uninitialized Zip object` instead of returning false. The caller's contract is that
     `recognises()` answers yes or no for anything, because it tries each reader in turn. **Test the
     open before the close is reached rather than putting the close in a `finally`.**

179. **A count of "read" chapters is table-wide or library-wide, and the difference is 24,000 rows.**
     Tachimanga's `Chapter` table holds 404,818 rows of which **64,024** are read, but only
     **54,870** belong to the 1,277 series that are `in_library = 1`, and of those **40,160** are
     read. Every one of those numbers was correct and every one of them was the wrong number for
     an import, which sees only the library subset. **When a count is quoted from a table rather
     than from the filtered set the import actually reads, it is a fact about the file and not
     about the work.** `Manga.in_library` is the shelf flag; `CategoryManga` is 146 rows of
     category membership and is not the library.

180. **Sharing an id space is not sharing a set of ids, and the difference is three quarters of a
     library.** Tachimanga and Suwayomi do use the same source ids, which is why the url join works
     at all: `2848420679472350044` is Thunder Scans in both. Measured on a real 1,277 series
     library: the series sit on **56** sources, this server has extensions for **13** of them, and
     the other 43 hold 117 series on the largest one alone. **An import is bounded by which
     extensions are installed, not by how good the join is**, and a report that says "8 of 56
     resolved" against a report that says "8 of the 13 that could be asked" are the same run and
     completely different news. Two failures, two sentences: "install that extension" is something
     a reader can act on, "could not be reached" teaches them their data is gone, and it is not.

181. **`isInstalled` is on the extension, not the source, and asking for it on the source reads as
     an empty catalogue.** `SourceType` has no `isInstalled` field, so
     `{ sources { nodes { id isInstalled } } }` is a validation error. A probe that swallowed that
     reported **0 installed sources** and concluded that every source was missing, which is a
     plausible-looking wrong answer rather than a crash. **A query that returns nothing is the
     result most likely to be a malformed query**, and the two are told apart by printing the
     response rather than by reading it. Same trap one level up: two probes disagreed about whether
     the url join worked, and the second had mislabelled its own input, so the number that settled
     it was the one where the input was printed.

182. **A cache that spans a write makes the write report itself as a no-op.** `SeriesLocator`
     answers "which extensions are installed" once and reuses it, because an import asks that
     question per series and 1,277 series against a fresh query each time is 1,277 queries to
     learn the same thing. That is the right cache. But an install *changes the thing being
     cached*, so the installer's post-install survey read the pre-install answer and reported
     **`installed: 0`**. Nothing threw, the sources genuinely became available, and the run's
     own counters said otherwise. **A cached read must be invalidated by whoever invalidates the
     world, and only a caller that writes may do that.** A reader that only reads must leave the
     cache alone, because that is the case the cache exists for.

183. **Classify an outcome by a named value, never by searching its user-facing sentence.**
     `BackupImporter` decided a series sat on a missing source by looking for the phrase
     "not installed" in the reason string. That put a database column at the mercy of prose: a
     plainer sentence would have reclassified **195 series** as genuinely not found, with
     nothing failing and no test able to see it. `Resolution` now carries `missing_source` and
     `unreachable_source` as constructors, and the ledger matches on the tier. **If a string
     reaches the database, it is a bug waiting for an editor.**

184. **A counter that lands in two buckets makes the totals exceed the library, and it reads as
     rounding.** Every unresolved resolution counted as `not_found` and the ambiguous ones also
     counted as `ambiguous`: **1,290 accounted for out of 1,277**. Nothing overflowed, no
     assertion caught it, and the report looked like a small arithmetic error rather than a
     counting bug. `BackupImporter` now buckets by the resolver's tier with exactly one
     increment per series, and `test_the_buckets_add_up_to_the_library` sums them against the
     library size. **Assert that your categories sum to the whole.** It is the only check that
     catches a category that belongs to two parents.

185. **A global scope means an unauthenticated command finds nothing, and looks like it found
     everything.** The ledger restricts rows to whoever is signed in; a command run from a
     shell has nobody signed in, so the scope degrades to `user_id is null`. The importer read
     its own ledger through that scope, so on a second run it found no rows, decided every series
     was new, and hit the **unique index on `(user_id, source_id, source_url)`**. The delta, the
     whole reason an interrupted import is safe to resume, worked only in tests, because the
     tests authenticate. Reads and writes for a named user go through `forUser($user)`. **A
     suite that authenticates cannot see this class of bug at all**, which is the argument for
     testing the command as well as the class.

186. **SQLite does not enforce column types, so a mistyped column passes every test on the
     database this project ships with.** `imported_series.last_seen_run_id` was declared
     `timestamp` because the line above it ended in `_at` and this one ends in `_id`. Measured:
     inserting `7` into that column stores the integer `7` and `typeof` reports `integer`, so
     every write succeeded, every read came back correct, and the suite was green. It would be
     wrong on any database that coerces. Same family, same file: the three import models had no
     `casts()` at all, so `$run->applied` arrived as the string `'1'` and `$source->obsolete`
     as the integer `1`. `assertTrue` on the latter failed; `== ` on the former would have
     passed while a report printed a string. **A passing test on SQLite is evidence about your
     code, not about your schema.**

187. **`trans_choice` does not process `{1}`, and a pluralising sentence nobody rendered is a
     sentence nobody wrote.** Laravel's `choice()` splits the key on the pipe, takes a whole
     branch, and runs `makeReplacements` over it. `{1}` is not a placeholder it knows, so it
     reaches the output as text. Measured:

     ```
     trans_choice('{1}:count series sits...|[2,*]:count series sit...', 1, ['count' => 1])
       -> '  {1}1 series sits on a source this server has no extension for.'
     ```

     This project's blade uses the `{1} :count language variant|[2,*] :count language variants`
     style, which reads well in a template precisely because the singular branch is one short
     clause and nobody needs the marker. Copying that shape into prose produces literal braces,
     and **nothing fails**: no exception, no failed assertion, just a report that says `{1}` to
     a reader. The fix is that both branches carry the whole sentence, and
     `.tools/suwayomi/check-choice-output.php` renders every one of them at 1 and at 195
     because reading them is the only check that works. **A string that varies at runtime is
     code, and should be run at least once before it ships.**

188. **A per-run table read without the run id is a table that grows without telling you.**
     `imported_sources` holds one row per source per run, on purpose, so that a second run can
     say "still gone" without re-checking a repo. The report read every row the user had rather
     than this run's, so measured after two runs against the real backup it printed all seven
     dead sources **twice**, and its "and 4 more" was computed as 14 minus the 10 it showed.
     The number about nothing is the tell. **Any query over a per-run table needs the run id,
     and a count that can exceed the number of distinct things is a count that is wrong.**

189. **A sentence built from the library size is a claim about series you never looked at.**
     With `--limit=3` on a run that changed nothing, the report said "All 1,277 series already
     match what is here" after examining three of them. It read as an honest summary and was
     false about 1,274 series. The fix is to build the claim from what the run actually
     processed, and to say so when that is less than the library. **A percentage or a total
     copied from the input rather than from the work is the most comfortable lie an importer
     can tell**, because on a partial run it looks exactly like a successful full one.

190. **`fetchExtensions` refreshes the catalogue and installs nothing, and its name says
     otherwise.** The import's extension step existed to make three quarters of a reader's
     library reachable: measured, 1,277 series on 52 sources with extensions for 13. It called
     `fetchExtensions`, on the reasonable reading that fetching extensions installs them.
     Measured against the live server:

     ```
     before: 18 installed packages
     fetchExtensions returned: 1428 extensions
     after:  18 installed packages
     gained: 0
     ```

     The step had never installed a single extension. The working route was already in the app,
     unused by the import: `updateExtension(input: {id, patch: {install: true}})` one package at
     a time, keyed by pkgName. Measured the same way: 17 installed, asked for one, got 18, and
     nothing else moved. **The suffix on a mutation says what it returns, not what it does**, and
     the only way to tell is to read the server's state before and after. This also removes a
     cost the old route could not avoid: `FetchExtensionsInput` takes no filter, so it installed
     all 1,428 extensions or none, against the 32 the library needed.

191. **A test that asserts on the request cannot catch a request that does the wrong thing.** The
     installer suite asserted that `fetchExtensions` was sent, and passed throughout the time
     the call was installing nothing. Every assertion in that suite was about the shape of the
     traffic, none about the state on the other side. It is a natural way to write the test,
     because the fake is what you have, and it is the one way that cannot fail: **assert on the
     server's state, and use the request log only to explain a failure.** The rewritten suite
     makes the fake's installed set mutable, mutates it in the `updateExtension` branch, and
     every test asserts on what landed. Five mutations on the install route are caught; the
     equivalent of the first one, put back, takes four tests down.

192. **A stale `public/hot` serves the whole app with no stylesheet, and only `npm run dev`
     cleans it up.** `@vite` reads `public/hot` and, when it exists, emits every asset URL
     against the dev server. That file is written when the dev server starts and removed when
     it exits cleanly. Kill it instead and the file survives: every CSS and JS request 404s,
     every Tailwind class becomes inert, and the page renders as raw HTML. Measured: a login
     failure came out as a full-screen unstyled SVG and a line of 8px text, which reads as a
     broken design rather than a missing dev server. Worse, `npm run build` does not clear it,
     so the one command that should produce a self-contained app leaves the app still broken.
     The build now deletes the file first, which is what makes the build a recovery rather
     than only a way to compile. **A marker file that switches an app from "dev" to "prod" mode
     needs whoever switches back to the other mode to clean it up**, and the recovery path has
     to be the obvious command rather than a file deletion.

193. **SQLite sorts integers before text, so a `Carbon` in a `where()` against an epoch column
     matches every row.** The `jobs` table stores `created_at` as an integer Unix timestamp.
     Passing a Carbon to `DB::table('jobs')->where('created_at', '<', now()->subSeconds(30))`
     makes the query builder bind it as a datetime *string*, and SQLite compares by storage
     class rather than by value, so every integer sorts before every string. The condition is
     then true for every row in the table whatever its age. Measured: the check meant to say
     "nothing is processing this queue" was true unconditionally, and the two tests around it
     passed for the wrong reason until the third one, asserting a *fresh* job is not yet
     considered stalled, caught it. Neither an age check nor a date range is the kind of
     condition that fails loudly when its comparison is wrong; it just answers a question the
     reader did not ask. **Write the epoch yourself whenever the column is an epoch**, and when
     a time-based predicate looks too eager, test it with a value it must not match.

194. **Laravel's default queue is `sync`, so a long job runs inside the request that dispatched
     it.** `php artisan new` writes `QUEUE_CONNECTION=sync`, and it is the right default for
     most apps. It is wrong for anything measured in minutes. Importing 1,277 series is about
     eleven minutes of upstream requests; dispatched with `sync`, that entire run happens inside
     the Livewire request, the browser sits there until something upstream gives up, and the
     reader sees a page that stopped responding rather than a page that told them anything. It
     probably still finishes, which is worse, because it also looks broken. `phpunit.xml` here
     forces `sync` so the suite needs no worker, which means the test suite cannot see this at
     all unless a test says so out loud. **Any job whose cost is measured in minutes needs the
     queue configuration asserted deliberately rather than inherited**, and the page that
     dispatches it should check `config('queue.default')` and say so before the reader finds
     out the expensive way.

195. **A row written only at the end of a long run cannot be watched while it happens.** The
     `import_runs` migration said in terms that its counters were "what a page polls while the
     import is going", and no page did, so nothing polled them and the only route in was a
     command printing to a terminal. Then a page was built, and it found the row was written
     once at the end: for eleven minutes a polling page would have shown a run that had started
     and nothing else. **A table described as progress needs a column that moves while the work
     moves**, and it has to be a column separate from the final tallies: writing `applied` every
     25 series would make a run that died at 60% claim it had imported 60% of the library, with
     no way to tell that apart from one that finished.

196. **A guard written into one of two queries over the same rows is a guard on one of them.**
     `CheckLibrary::dueIds()` filtered out series whose `suwayomi_manga_id` is null, because a
     series filed from a backup has no Suwayomi manga to check against, and casting that null to
     `0` would book a pass for a manga that does not exist. `CheckLedger::candidates()` had its
     own query for the forced pass and carried no such filter, so the 195 filed series out of
     867 reached `(int) $state->suwayomi_manga_id` as manga `0` and `check()` threw
     `Series 0 is not in this reader's state` on the first of them. Measured against a real
     import: `Check series` reported success having checked nothing.

     The general shape: **the same rows fetched twice are two queries, and a condition written
     into one of them is a property of that query rather than of the data.** The null was never
     impossible, it was only new: nothing wrote a filed series before the importer existed, so
     the first reader to import a backup was the first reader this could happen to. The fix is
     `whereNotNull('suwayomi_manga_id')` beside the other branch's conditions, and the general
     answer is to put the filter where the value is read rather than in a helper somebody else
     remembered to call.

197. **One exception ended a pass that was only a slice of a whole library.**
     `CheckLedger::checkDue()` loops a slice bounded by time and count, 45 seconds and 60
     series, and called `check()` bare. `check()` throws `InvalidArgumentException` for a manga
     the reader does not hold, so a throw on the third of sixty cost the other fifty-seven and
     stopped the chain, because `CheckLibrary::handle()` re-books the next slice *after* the pass
     returns and a job that dies inside the loop never reaches that line. Measured: the job
     failed four times in 5ms, checked nothing, and re-queued itself never.

     The catch is `Throwable` rather than `SuwayomiException`, because `check()` already turns
     that one into a failed row and what lands here is the unexpected: a malformed row, a source
     returning nonsense, a cast that fails. Each is reported and counted as failed so the pass
     keeps going. The general shape: **a self-chaining job has to reach its re-book from every
     path, and a loop over another system's data is somewhere a throw is expected rather than
     exceptional.** A reader who pressed one button asked for 867 checks, and four is a worse
     answer than 866.

198. **Three queries answered "what is due" and only two of them agreed.**
     `CheckLibrary` asks `CheckSchedule::due()` whether anything is left and re-books if so.
     `due()` had no `whereNotNull('suwayomi_manga_id')`, so a series filed from a backup with a
     dead source came back from it, `isDue()` called it due for ever because nothing ever
     checked it, and the chain never ended. This was the third query needing the filter:
     `CheckLedger::candidates()` and `CheckSchedule::summary()` were both fixed for it earlier
     and this one was missed, so the three disagreed and the disagreement was not cosmetic.
     Measured: 95 dispatches against a worker that checked nothing else, every one of them
     re-checking the same phantom. A reader watching that sees a job plainly running and a
     number that never moves.

     The general shape: **a concept like "due" that more than one query has to answer is a
     concept with a definition, and the definition has exactly one home.** Three copies of a
     predicate is three chances to forget a branch, and the one that is wrong is the one nothing
     else calls. The fix was the filter; the answer is that "what is due" means "what a pass
     would visit", and that sentence belongs beside the first query rather than in a comment on
     the third.

199. **A check that always fails never stops being due, so the job that chains on it chains
     for ever.**
     `chapter_list_checked_at` moved only when a source *answered*, on the reasoning that a
     request which never landed is not information. That reasoning is sound and the consequence
     is not: a series whose extension is broken never gets an answer, so it stayed due for ever.
     Four series answering "Could not find environment bundle" failed on every pass, kept their
     clock null, and were offered to the chain for as long as the worker ran.

     The clock now moves on every outcome, written once in `record()` because every path through
     `check()` funnels there and a rule enforced in one place cannot be half applied. It means
     "we asked" rather than "the source answered", which is what the column is called. Nothing is
     lost: the outcome and its error are still in `library_checks`, the page still shows which
     series failed, and a reader can still press Check on one of them individually because that
     path forces. `test_a_failure_does_not_advance_the_clock` asserted the old rule deliberately,
     so it was replaced rather than deleted, with the measurement that proved it wrong kept in
     place.

     The general shape: **"do not record this as progress" is a rule that will eventually be read
     as "keep offering this for ever".** Reserving a place for a result that never arrives is
     correct; re-offering that place unconditionally is not. Retry on an interval, not on
     nothing having happened.

200. **`LockTimeoutException` is not a `SuwayomiException`, so every catch in the app missed
     it.**
     `AuthManager::renew()` takes a cache lock with `->block(5, ...)`. When it cannot, Laravel
     raises `Illuminate\Contracts\Cache\LockTimeoutException`. Nothing in the app caught that
     type, so it escaped as an unhandled 500. Measured: a worker holding the lock while it
     checked series, and a reader loading the library at the same time, which produced an error
     page instead of a library. That is not an edge case any more, because running a worker is
     the documented way to use this app.

     Translated in `AuthManager` rather than in each caller, because from anything above the
     gateway "could not get a token" is the same fact as "the server is not reachable right
     now", and the gateway's contract is to throw `SuwayomiException` for that. Verified by
     holding the lock and asking for the library: 867 entries, page rendered.

     The general shape: **a lock timeout is a transport failure wearing a different class, and
     the place to translate it is the layer that owns the lock.** Catching it in every caller
     would have been eleven chances to miss one, and the one that missed would be the page that
     matters.

201. **The test set the property the view never set, and called the method the test never
     called.**
     The library page's pagination arrows call `setPage()`. No component declared
     `WithPagination`, and `git log -S WithPagination` across the whole repository returns
     nothing, so both arrows had thrown on every click since the codebase was imported. The
     suite was green because `test_the_page_pages_at_sixty` proved paging by setting the `page`
     property directly, which works whether or not `setPage` exists at all. The tests and the
     view disagreed about how to change pages and only the view was wrong.

     Fixed by declaring `setPage()` rather than importing the trait, because this component
     paginates by hand: `$page` is a typed property that `normalise()` clamps and `updated()`
     resets on a filter change, and `WithPagination` keeps its own state in `$paginators`, so the
     trait would give the component two sources of truth for one number and a reset that never
     fires.

     The general shape: **a test that drives state the way the code under it does not is testing
     a second implementation.** When a control calls a method, something has to call that method,
     or the control is unpinned no matter how many tests cover the state it changes.

202. **An assertion both paths satisfy proves nothing about which check did the work.**
     `test_an_item_from_another_readers_run_is_not_moved` has been in the suite throughout.
     Delete `forUser()` from `BulkMover`'s query and it still passes, because `SeriesMigrator`
     refuses the pair for want of an offer either way. What it never noticed is that the mover
     then writes REFUSED onto *another reader's row*.

     Found by a mutation harness: 11 of 21 mutations to the bulk migration survived, and most
     were this shape. The suite was green against code that silently did the wrong thing in
     every one of them.

     The fix is to assert the observable that *separates* the checks rather than the outcome they
     share. Another reader's row must be left untouched, not merely not moved. An item from a
     different run must not move *even with a real offer for it*. A tampered destination must be
     refused *even when that destination was genuinely offered*.

     Two of the survivors were not gaps at all and are worth noticing as such. The catch around
     `CandidateFinder::findOn()` is unreachable, because `CandidateFinder` catches at both the
     places it can throw, so no test can kill a mutation of it. And dropping `forUser()` is
     inert, because `BulkMigrationItem extends OwnedModel` and `OwnedModel::boot()` installs a
     global `OwnedByUser` scope, so the tenancy is real and simply lives a layer below the line
     the comment points at.

     The general shape: **when two checks can each produce the outcome you are asserting, assert
     the side effect instead.** The outcome is the intersection; the side effect is the difference.

203. **A ledger written only by observation has no way to say "never asked".**
     The Health page showed six zeros with 223 sources installed. `source_healths` was empty, and
     `search_runs` was empty too, so no search had ever been run in this app and the dashboard was
     telling the truth. One search filled 60 rows and turned it into 50 sites.

     That left a defect behind it. A source only gets a ledger row by being asked, so the 163
     sources nobody searched had no row, and a source with no row is *absent* rather than counted
     as unobserved. The page said "223 sources are grouped into 50 sites" and showed 50, which
     reads as a miscount and is really an omission. `STATE_UNOBSERVED` was unreachable, and the
     class docblock argued against exactly this: "a dashboard that showed every unobserved source
     as a blank or a 100% would be lying about 93% of the catalogue". Omitting them is a quieter
     version of the same lie.

     Seeded from `SourceCatalog::syncSources()`, the one place the catalogue changes, so there is
     no second call site to forget. Counters at zero and no timestamp, because a seeded row
     carrying `last_success_at` would read as a successful call that never happened. Existing rows
     are never touched, so a seed cannot reset an observation.

     The general shape: **a table that only exists as a record of activity cannot also answer
     "what have you not looked at".** If absence is a state a reader is meant to see, absence has
     to be written down deliberately, because it will otherwise never be written at all.

204. **The image was in the database the whole time, and the endpoint that serves it needs a
     credential the browser does not have.**
     `sources.icon_url` and the extension list's `iconUrl` were both stored and both fetched from
     the first build, and neither had ever been rendered. Fifty rows of a name, a badge and a
     package name is what "flat, boring, generic" looks like, and the data to fix it had been
     there the whole time.

     The icon also cannot be pointed straight at Suwayomi. Measured: the endpoint answers 401
     anonymously with a twelve byte body, and 200 image/png with a token. A browser cannot hold
     that token, so the icon has to come through the app's own origin, which is why
     `extension/icon/{packageName}` became an allowlist entry on the existing proxy rather than a
     new route. `extension/list` is asserted refused in the tests because it is one character
     away and enumerates the catalogue.

     The general shape: **"we have the data" is not the same as "we show the data", and a column
     that is written and never read is the cheapest possible bug to find and the easiest to leave
     for years.**

205. **`public/build` is not in git, so a class you have never used silently does nothing.**
     `break-words` had been added to a card and the long single-word source names were still
     clipped. The class was absent from the compiled stylesheet, which is a gitignored build
     artifact, and a missing Tailwind class is not an error: it is a string in the markup that
     matches nothing. This is the `npm run dev` trap the README already warns about, met in the
     one place it was least expected.

     Worth stating how it was found, because the browser disagreed with the source for a reason
     that had nothing to do with either: the markup was right, the CSS was stale, and only
     comparing the two found it. Searching the compiled sheet for the class is a ten second check
     that would have answered it immediately.

     The general shape: **a class name is not a promise that the rule exists, and a generated
     artifact cannot be reviewed in a diff.**

206. **`migrate:fresh` leaves an ordinary signature, and it is in the batch column.**
     Worth recording because it answers a question that cost real time to ask: was there ever a
     second account here, destroyed by a `migrate:fresh`?

     The answer was no, and the strongest evidence was structural rather than forensic. A fresh
     migrate runs every migration in a single batch, so the `migrations` table would hold all 34
     in batch 1. It holds 31 in batch 1 and one each in batches 2, 3 and 4, which is an ordinary
     incremental run over three days of adding migrations. The database file's NTFS creation time
     is also unchanged, so it was never deleted and recreated.

     The forensics agreed: `sqlite_sequence` for `users` is 1, `series_states` ids 1 to 867 are
     contiguous, `freelist_count` is 0 with `secure_delete` off so carving was viable and found one
     bcrypt hash in the whole file, and the WAL's 294 stale frames held cache pages and no user
     records.

     The general shape: **a schema's own bookkeeping can answer questions about its history that
     no log kept.** Before spending an afternoon recovering data, check whether the thing you
     suspect actually happened, and whether the database says so.
207. **A protobuf flag that is absent is not a flag that is false, and !== 0 says it is true.**
    `integer($bytes, 4) !== 0` reads as true whenever the field is missing, because `null` !== 0,
    and protobuf omits a false rather than writing a zero. The .tachibk reader shipped that way
    and reported **2,911 chapters read out of a file holding 1,616**, while every other number it
    printed stayed correct.

    That is the shape of failure worth fearing: not a crash, not a wrong-looking count, but a
    plausible report with one number in it quietly inverted. Nothing on screen contradicts it.

    It survived a fixture that set a read flag to zero, because that is not what the format does.
    It was caught by running the reader on a file a person had actually made and comparing the
    count against a second decoder written in another language.

    The general shape: **the fixture must reproduce the shape of the data, not the shape of the
    idea.** "Unread" in this format is an absent field, and a fixture that writes `false` is
    testing a file no writer produces.

208. **Protobuf writes a fixed32 little endian, and unpack('G') reads it big endian.**
    g is little endian and G is big, they look identical, and getting it wrong does not
    raise. 700.5 comes back as a denormal so small it reads as `0`. Every decimal chapter
    number silently became zero and every whole one became nonsense.

    The general shape: **byte order errors are silent, and a language that names both variants
    with two characters gives you no way to notice.** Read the format's specification for the
    order rather than for the field.

209. **A dropped ternary branch is still valid PHP when it is the last argument.**
    `sprintf('Read a %s backup%s.', $format, $schemaVersion === null)` compiles. The call is
    complete, $schemaVersion === null is the third argument, and the trailing ) closes the
    enclosing call. It printed Read a tachibk backup1. and every test passed.

    This one is worth keeping for the shape rather than the instance. The intermediate state was
    **syntactically valid and semantically broken**, and PHP will not help you. A ternary written
    across several lines needs a test that asserts on the sentence, not on the exit code.

210. **A nullable value through %d becomes a confident zero.**
    ImportedBackup::$schemaVersion is now ?int, because .tachibk states no version anywhere
    and a `0` reads as a version the server recognised. The type was changed and one caller was
    fixed; the second, `number_format($backup->schemaVersion)`, turned `null` into '0' in the
    progress note and nothing failed.

    The general shape: **widening a type does not migrate its callers.** PHP will coerce `null`
    into `number_format` and '%d' without a word, so every consumer of a newly nullable value
    has to be found by the type checker and not by memory.

211. **A count of what survived a filter is not a count of what was in the file.**
    `totalInFile` was `count($series)` after unnameable series had been skipped, so a file
    holding three series reported two. The comment next to the skip said it still counted, which
    made the bug a documented lie rather than an undocumented one.

212. **A sentence that is true of one format can be false of the next one.**
    import:inspect ended every report with "the rest is what the app cached while browsing". For
    Tachimanga that is the single most important sentence in the output, because 131,908 of its
    132,185 rows are browse cache. For .tachibk there is no rest, so the same sentence told a
    reader that a thing existed in their file which does not exist in that format at all.

    The general shape: **adding a format re-tests every sentence that a format can reach.** The
    branch that was written for the first case is now also the default for the second.

213. **A listing index is not a source order, and the writer prefers the one it is given.**
    `SeriesWriter::pair()` tries `sourceOrder` before `number`. A `.tachibk` chapter field
    holds a 1 based index in the backup's own listing, which is the app's sort order rather than
    anything the source published. Mapping it would have let a sort choice override a chapter
    number that is definitely right, and a source that inserts a chapter would move every read
    flag after it onto the wrong chapter.

    The general shape: **an easy field is not a safe field.** Ask what a consumer does with the
    value before deciding what it means, and prefer the key that means what it says.

214. **A wire type and a value type cannot be correlated in a single PHP type.**
    The walk yields `[wire type, value]` where the value is an int or a string depending on the
    wire type, so every caller that wanted a nested message had to repeat the same two checks and
    PHPStan could not follow it. messages() does the filtering once and narrows with is_string
    rather than casting, because a cast claims a type the walk never promised.

215. **A generator that declares Generator<K, V, null, null> trips `return.missing`.**
    Declaring the third and fourth type parameters as `null` tells the checker the generator
    returns a null explicitly, and then it wants the `return null` that a generator never needs.
    `@return Generator<int, array{int, int|string}>` is the accurate declaration.
216. **A test one second away from a boundary is a coin flip, not a test.**
    `AuthManager::ACCESS_MARGIN` is 30 and `isFresh()` is `time() < $expiresAt - 30`. The
    test for a token *outside* that margin captured `time() + 31`, so it had exactly one
    second of slack before the branch it was asserting on would flip. It passed in isolation and
    failed once in a full run, on a machine that was also running something else.

    That is the shape to look for: a fixture value chosen as the *nearest* value on the correct
    side of a threshold. It reads as the most precise test available, and it is the one that
    fails at random.

    `AuthManager` reads `time()` directly, so Laravel's `travelTo` cannot reach it and
    there is no clean way to freeze the boundary. The fix was to move the value to 300 seconds
    and leave the margin pinned to the second from the *other* side, where `time() + 29` is
    inside the margin and stays inside for the whole run. A boundary needs one tight case, not
    two, and the tight one should be the one that cannot go the wrong way as time passes.

    The general shape: **when a test is pinned to a threshold, check which side of it the clock
    is on, and check that direction.** Otherwise the suite's stability depends on how busy the
    machine is.
217. **Two java.exe processes with the same command line are a launcher shim and its child.**
    Recorded because this project stated the opposite for most of a session, and the correction
    matters more than the mistake did.

    On this machine, two java.exe processes run `-jar Suwayomi-Server-v2.4.2366.jar`. Read
    naively that is a duplicate server, and it was described as one, in a note about single
    instance handling. It is not. One is
    `C:\Program Files\Common Files\Oracle\Java\javapath\java.exe` using **6MB**, and the other
    is the real JVM at `C:\Program Files\Java\jdk-26.0.2\bin\java.exe` using **722MB** and
    owning port 4567. The first is Oracle's launcher shim, which stays resident as the parent of
    the process it spawns.

    The lesson is not "check memory instead". It is that **process identity is not a fact you can
    read off the process table.** Name, command line and even path can all be shared by something
    that is not the thing you are detecting.

    So three rules for anything that supervises a process: check the **port** or a lockfile you
    own, never a process name; launch by **absolute path**, never by `PATH`, because whatever is
    on `PATH` may be a shim or may not exist at all; and treat a stale lock as normal after a
    crash, storing pid plus start time so a recycled pid cannot be mistaken for a live process.

    Worth noting the failure would have been silent in both directions. A launcher that saw two
    processes would refuse to start a second Suwayomi, and one that saw the 6MB shim would think
    the server was dead while it was serving.
218. **A config that adapts without error can still reject every single request.**
    Two examples from the same FrankenPHP config, both producing a bare 400 on every route
    with an empty error log and a config that had validated:

    - `auto_https off` stops Caddy redirecting to HTTPS. It does **not** turn TLS off. The
      listener still expects TLS and answers `Client sent an HTTP request to an HTTPS server`.
      The address needs an explicit `http://` prefix, which also keeps QUIC out, since h3 is
      always over TLS.
    - `root * <path>` is Caddy module syntax. Used inside a block whose directive is
      nginx-style, the wildcard changes the matcher, and the documented bare form is what
      works.

    The general shape: **a successful parse is a statement about syntax, not about behaviour.**
    A config that validated is exactly as unverified as one that was never run, and the failure
    mode of getting it wrong at serving time is a four hundred with nothing to go on, which is
    worse than a crash because it survives a log check.

219. **An ini that names directories the installer did not create fails at the first subprocess,
    far from the cause.**
    The packaged `php.ini` pointed `sys_temp_dir`, `upload_tmp_dir` and
    `session.save_path` at three directories inside the runtime. The first thing that used one
    was `artisan about`, which shells out through Symfony Process, and the error was:

    `A temporary file could not be opened to write the process output: fopen(.../tmp/...):
    Failed to open stream: No such file or directory`

    which reads as a bug in `artisan about` and is not one. Nothing was wrong with the command,
    the framework, or the application. A directory did not exist.

    The general shape: **configuration that points at a path is a promise that something else
    creates it**, and the consumer of that promise is rarely the thing that looks broken. The
    installer has to create every path a config names, and the check that proves it is to run
    something that shells out, because that is the first thing to notice.

220. **Two controls in one button group must have the same lifetime, and nothing enforces that.**
    A reader sorted a series' chapter list newest-first, opened another series, came back, and got
    oldest-first. The reading-mode buttons either side of it, in the same group, remembered.

    The cause was two correct decisions. `setReadingMode` writes through `ReaderSettings`, into the
    same column the mode is read back from, so it survives a navigation by construction. `setOrder`
    assigned a Livewire property, which survives a re-render and nothing else. Neither was wrong. The
    defect was only ever visible in the comparison, and there was no check anywhere that says two
    controls a reader would expect to behave alike must.

    What let it survive is that a reset is indistinguishable from a choice. The button is
    highlighted from the property, so a forgotten value looks exactly like a freshly made one, and
    nothing on screen says a choice was lost.

    The general shape: **a highlighted control is not evidence that a choice was remembered**, and
    the test has to read the value back on a fresh mount rather than off the component that set it.
    Asserting on the same instance passes against the original code, because the property is set.
    That test is the whole thing here: the first version of it would have passed and the reader
    would still have lost their sort.

221. **Making a value reachable from the URL means clamping it on the way in.**
    The library page's shelf, sort and search were plain Livewire properties, driven by `$set(...)`
    and `wire:model`. They went into the URL so they would survive navigation, which is what
    `browse`, `health`, `search` and `sources` already do with `#[Url]`.

    There is a `normalise()` on that page whose own comment says a sort of `"title; drop table"`
    must never reach the query builder, and it ran from `updated()` and `setPage()`. Both are
    reached only by interacting with the page. So the moment the values became typeable, a shelf id
    belonging to somebody else arrived unvalidated, and the filter is applied in PHP, so nothing
    broke: the library filtered down to nothing and the page said "This shelf is empty" about a
    shelf the reader never had.

    No crash, no injection, no error. A wrong answer about the reader's own data, which is worse
    than an empty page.

    The general shape: **every path that can set a value needs the validation that value assumes,
    and a validation reachable from one path is not reachable from another.** The path that existed
    when the check was written was the only one anybody imagined, which is the same shape as the
    known-installs exclusion list in `packaging/dev-workers.ps1` that went stale the moment Papyrus
    was actually installed.

    Worth recording next to it: the audit that found this nearly got it wrong the other way. It
    looked for `#[Url` with a backslash under `-SimpleMatch`, matched nothing anywhere, and reported
    `browse` as a second instance of the same bug until its declaration was read directly. **A grep
    that cannot match what is there is not evidence that what is there is absent.**

222. **A test that runs on a platform the product does not exist on is worse than no test.**
    CI ran on `ubuntu-latest` for twenty-four consecutive pushes, every one red, while the product
    was a Windows executable: an Inno Setup installer, `frankenphp.exe` against `php.exe`, a batch
    file entry point, a Windows JRE, and data in `%LOCALAPPDATA%\Papyrus`.

    The failures were honest and were all the same shape: four tests asserting `%LOCALAPPDATA%` and
    a `C:` drive letter, which do not exist on Linux. Moving to `windows-latest` cleared three of
    four immediately.

    The fourth is the reason it matters. `ProcessFinder` shells out to PowerShell, so on Linux every
    process-matching test found nothing and **passed vacuously**. `PapyrusCmdTest` skipped outright,
    because there is no `cmd.exe`. A green run on the wrong platform would have reported the
    packaging layer as covered while testing none of it.

    Windows runners also enable a smaller default PHP extension set: `composer install` refused three
    locked packages because `ext-fileinfo` was absent, which is why the suite never started. The
    extensions are now named explicitly, taken from the packaged runtime's own `php.ini` so CI and
    the shipped artifact agree about what PHP is.

    The general shape: **a check on the wrong platform does not fail loudly, it passes quietly, and
    the tests that would have caught the bug are the ones it skips.** One job, not a matrix, on
    purpose: a Linux job with the Windows-only tests excluded would be green and would cover the
    layer least likely to break.

223. **A process that exists is not a process doing this install's work.**
    The same mistake three times in this project, in three layers, and each time it reported success.

    `health` asked whether a `queue:work` process was running. One was, belonging to the packaged
    install, draining a different database, while a bulk library check sat unreserved for seventy
    minutes. The check now asks whether a job that is **due** has been taken, which is a thing a
    foreign worker cannot fake.

    Then `packaging/dev-workers.ps1`, written to fix that, excluded two install paths **by name**.
    Those were the two installs that existed when it was written. Papyrus was then actually
    installed, its worker appeared, neither exclusion matched, and the script reported
    `ok queue pid 6448` over a checkout with no worker at all, with six jobs unreserved for
    twenty-five minutes. The rule is now a shape: this checkout's workers carry a bare relative
    `artisan`, so any path-qualified `artisan` is another install's, whatever that path turns out to
    be.

    Then the same script started workers through Herd's `php.bat`. A worker through the wrapper comes
    up, appears in the process list, and drains nothing. The evidence that it was never connected at
    all was that no write-ahead log sat beside the database. Swapping in `php84\php.exe` drained all
    six jobs in about a second.

    The general shape: **liveness is not participation, and a batch wrapper is not the thing it
    wraps.** Process presence, a path on an exclusion list, and a resolved command are three
    different questions from "is this install's queue being served", and each was answered by the
    cheapest question available. Ask the question that cannot be answered by something else.

224. **A flag configures the process you pass it to. An environment variable configures everything
    it spawns.**
    The packaged install's scheduler had been dispatching nothing for as long as it had been
    installed. `artisan schedule:run`, the process that actually runs the schedule, died every ten
    seconds with

        PDOException: could not find driver
        sqlite:...\AppData\Local\Papyrus\database.sqlite

    and had failed 182 times, in an install whose `health` reported all four processes healthy.

    The cause is one level down from where anyone looks. `schedule:work` does not run the schedule.
    It shells out to `schedule:run`, building that command from `PHP_BINARY`, and it passes no `-c`
    of its own, because Laravel assumes it inherited a configured interpreter. The launcher had been
    careful. Both `queue:work` and `schedule:work` were given `-c <ini>` explicitly, with a comment
    explaining that a worker without `pdo_sqlite` pops nothing and exits quietly. The care stopped at
    the edge of the process it was applied to.

    And the edge was exactly the problem, because **this launcher's own PHP loads no ini at all**. It
    is invoked with no `-c` and no `PHPRC`, which is why the launcher cannot touch a database itself
    and has to shell out for anything that can. Every grandchild it started therefore inherited a
    bare interpreter, found no ini, loaded no extensions, and died on its first query.

    The fix is `PHPRC` on both workers, which is the documented way to tell a PHP interpreter where
    its ini is, and which is inherited through arbitrarily many generations. FrankenPHP has had it
    all along, for exactly this reason. The two workers had only the flag, because the flag was the
    thing that had been thought about.

    Measured, on the real install, over a 60 second window: `could not find driver` went from 2 per
    25 seconds to 0, and `scheduler.log` changed from a wall of stack trace to

        2026-10-12 22:03:00 Running [App\Jobs\SyncDownloads] .. 320.88ms DONE
        2026-10-12 22:03:10 Running [App\Jobs\SyncDownloads] .. 2.28ms DONE
        2026-10-12 22:03:20 Running [App\Jobs\SyncDownloads] .. 2.86ms DONE

    which is the part that matters. `SyncDownloads` had never once run. The install was not degraded,
    it was a reader app with downloads switched off, reporting itself healthy throughout.

    Three silences stacked, and each is worth naming on its own:

    - **A supervisor watches the processes it started, not the processes they start.** The child
      dies inside a parent that is still alive, so the parent's liveness says nothing at all. This is
      the same lesson as 223 arriving from a different direction: liveness is not participation.
    - **A stack trace in a log nobody reads is a log nobody wrote.** The grandchild's output went to
      a temp file under `tmp/`, not to `scheduler.log`, so the file a person would open was the one
      that was clean.
    - **Every ten seconds looks like a log line rather than an outage.** A scheduled job failing
      silently is the quietest failure in any queue system, because a task that never fires produces
      no event at all. Nothing to alert on, nothing to notice.

    The general shape: **when you configure how a process behaves, ask how many processes that
    configuration is supposed to reach, and verify the answer at the far end rather than at the
    start call.** Counting start calls is not the same as counting processes, and the difference is
    where children live. Where a parent shells out to something that then does the real work, the
    environment is the only channel that reaches it, and a command line argument reaches nothing.

225. **A fix applied to one occurrence of a pattern leaves the other occurrences in place, and the
    other occurrences are usually nearby.**
    `Health::workers()` emits two lines, for `queue` and `scheduler`, from the same loop and in the
    same shape. Both asked whether a process existed. The queue half was found to be worthless and
    replaced with a real question, whether a job that was due had been taken, read from the jobs
    table. The scheduler half was three lines away, written by the same loop, and said the same thing.

    It stayed wrong for long enough to matter. On a real install a scheduler stopped dispatching:
    the process alive, 0.016 seconds of CPU across ten seconds, its log frozen at 2,017 lines, and not
    one `schedule:run` child spawned in a sampled 32 seconds. `health` said

        ok    scheduler  running  schedule:work

    for six minutes, and every other line was green. Nothing crashed and nothing was lost loudly. The
    schedule that refreshes series metadata simply never ran again, and the only reason it was found
    at all is that the same log I was reading for something else had stopped moving.

    The file's own docblock, written when the queue half was fixed, opens by explaining that the queue
    worker and the scheduler "write nothing at all when healthy, which is correct behaviour and the
    worst possible diagnostic", and says those lines are "the liveness signal for exactly the
    processes that cannot report their own death". The code said the opposite, on the second line,
    while the comment above it described the problem correctly. **The explanation had been written
    down next to the bug and had been believed about the other line.**

    Three things follow, and only the first is about code.

    First: **when a check is replaced, find every other place the same check is made.** Not by
    searching for the pattern, which finds the text rather than the intent, but by asking what else
    this code already believes, and then going and looking. Two lines from a corrected line is close
    enough that a reviewer reads it as covered.

    Second: **a comment describing a hazard is not a fix for it, and it can actively hide the hazard.**
    The comment said the diagnostic problem was understood. Having written down that you understand a
    problem, it is easy to stop looking, and the remaining instance of the problem then reads as
    already dealt with. This is the inverse of the stale-list trap in 221, and worse: a list is at
    least honest about being a list.

    Third, and the part that made this cheap to fix: **prefer a filesystem fact to a parsed string
    when the two clocks disagree.** The obvious implementation reads the newest line out of
    `scheduler.log` and subtracts it from `time()`. That is wrong by five hours here, because the
    scheduler renders its timestamps at UTC+5 while the launcher runs at UTC, and the result would
    report a perfectly healthy scheduler as silent since yesterday. A file's modification time is a
    unix timestamp and does not care which timezone anyone prints it in. `filemtime()` against
    `time()` is timezone-independent, needs no parsing, and does not break when Laravel changes the
    line format.

    And the part worth keeping from the diagnosis, which is a trap rather than a fix: **fifty-one
    held `framework/schedule-*` cache mutexes looked exactly like the answer.** `SyncDownloads` is
    `everyTenSeconds()->withoutOverlapping()->onOneServer()`, Laravel's default overlap expiry is
    1440 minutes, and a scheduler that stopped with a mutex held would skip every later tick in
    silence, which is precisely the symptom. It was a good hypothesis and it was wrong. Clearing all
    fifty-eight rows changed nothing, and the dispatching did not resume. `withoutOverlapping()` with
    a day-long default expiry remains a latent hazard on an install that is killed mid-job, but it is
    not what happened here, and writing it up as the cause would have been the more interesting story
    and the wrong one.

    The general shape: **a check that has been corrected is evidence that the pattern was understood,
    and therefore evidence that a second instance of it is probably still there.** The cost of fixing
    one is that the neighbourhood stops being suspicious.

226. **Two copies of a rule will disagree, and `require_once` deduplicates by path rather than by
    class name.**
    Adding a Suwayomi compatibility check meant answering one question twice: is this version string
    something `version_compare` can order? `build-install.php` needed to refuse to ship an unusable
    `suwayomi.json`, and `SuwayomiCompatibility::read()` needed to decline to compare with one. So it
    was written twice.

    They disagreed immediately. The build rejected `latest` and a recorded `v2.4.2366`. The reader
    accepted both. And the disagreement was worse than a redundant implementation, because the two
    answers were not the same question: **a file the build refused to ship could still be compared
    with if a person put it there by hand**, and the build's guarantee was therefore about the build's
    own path and not about the thing it guarantees.

    The fix was to make the class the owner and have the build ask it, which meant the build loading
    a launcher class early. And that produced a second lesson, this one:

        Fatal error: Cannot redeclare class Papyrus\Launcher\SuwayomiCompatibility
          (previously declared in .../packaging/launcher/src/SuwayomiCompatibility.php)
          in .../build/install/packaging/launcher/src/SuwayomiCompatibility.php

    `require_once` remembers the **resolved path** it has already included, not the class name. The
    same file content at `packaging/launcher/src/` and at `build/install/packaging/launcher/src/`
    are two paths, so the guard does not fire and PHP declares the class a second time. The build
    loads the launcher from the repository to validate against it, and loads it again from the built
    tree to prove the built tree boots. Both are correct and they cannot coexist.

    The resolution was ordering rather than mechanism. The build now only checks that the file is
    present and is JSON at the point it copies it, and asks the class whether the two numbers are
    usable **after** the launcher loop has loaded it from the built tree, which is the first moment
    the class exists. One rule, asked once, by the code that has to live with the consequences.

    Three things worth keeping.

    - **A rule implemented in two places is two rules, and the tests will only find the difference
      if one of them happens to be covered.** Here the reader's half was the loose one, and a test
      that fed it `latest` is what caught it. Write the test against the loosest caller, not the
      strictest.
    - **`require_once` is a file guard, not a class guard.** Anywhere the same source can be loaded
      from two roots, which in a build that assembles a tree by copying is the normal case rather
      than an edge case, it will eventually load from both.
    - **Sharing a rule is not the same as sharing the code that holds it.** The obvious way to stop
      copying the rule is to `require` the owner, and here that was worse than the copy, because the
      owner also had to be loaded later from somewhere else. Sharing means ordering, sometimes, and
      not just deduplication.

227. **A method that answers a question, and a second method beside it that answers it differently,
    will disagree for the life of the feature and nothing will fail.**
    `Supervisor` decided which processes were its own in two places. `stopAll()` asked `tokensFor($name)`,
    which returns `[data directory, role]`. `isRunning()` built its own marker from a bare
    `queue:work`, `schedule:work`, `frankenphp`, or a jar basename, and ran its own PowerShell probe to
    match it.

    `stopAll()` was the correct one and had been correct for some time, because it had been fixed once
    already for a different defect: a stop that reached into another install. Its docblock explains the
    reasoning in detail, including why the data directory and not the install root, including why two
    launchers can share a root.

    `isRunning()` was never fixed, because nobody was looking at it, and its bare markers match **any**
    install on the machine.

    What it cost, measured rather than argued: with a second copy of the install in another folder, its
    `health` reported the installed app's four processes as its own and **every line green**, while
    failing on a database that was not there. A reader running a portable copy alongside an installed one
    would have been told everything was fine.

    Nothing failed for as long as both existed, and the reason is worth stating precisely, because it is
    what let this survive: **`health` is only ever run by somebody who already believes something is
    wrong, and this particular wrong answer says everything is fine.** A diagnostic that is consulted
    during an incident and that reports health is not itself checked against an incident. It gets a pass
    every time because "everything is fine" is what you want to hear, and nobody notices a diagnostic
    agreeing with them.

    So this is 225 twice over, and 223 at the layer above:

    - **223:** liveness is not participation. Here participation was not even established. The process
      existed; it did not belong to the thing asking.
    - **225:** the fix for one occurrence of the pattern was already in the file, correct, with a
      docblock explaining why, three lines away from the occurrence that was wrong. Having understood
      the problem made the second place look taken care of.

    Two things worth carrying.

    **The fix was a deletion, not a correction.** `isRunning()` now calls the same
    `$this->finder->find($this->tokensFor($name))` that `stopAll()` does, and its own probe is gone. It
    was a second implementation of `ProcessFinder::find()` with one token where that takes a list. Had
    it been corrected in place, the file would still have held two implementations of process matching,
    one of them right, and the next disagreement would have had the same cause and taken the same time
    to find. There is now exactly one question and one answer, and the argument for `ProcessFinder` is
    recorded in `ProcessFinder`.

    **Two of the four lines were never wrong and are still green from a second copy.** `suwayomi` and
    `web` report on whether something is answering a *port*, not on process identity, and that is
    deliberate: the launcher adopts a Suwayomi already on its port rather than starting a second one,
    so "something is answering on 4567" is the true and useful answer. Only the two workers were
    matched by process, and only those two were wrong. Worth saying because the fix looks smaller than
    the defect did, and a reader of the commit message deserves to know which half was real.

    The general shape, and it is the addition to 225: **when you find a duplicated question, check
    whether the two copies are ever compared.** Here they were not compared by any test, because no test
    could fail on a discrepancy between two correct-in-different-ways methods. What would have caught it
    is a test asserting a property both must satisfy, and the one that now exists asserts that every
    lookup names this install's data directory. It is a property, not a value, so it does not need
    updating when the markers change, and it fails against the old code.
228. **A guest's loopback is not your loopback, and the error a wrong address gives is the error a
    broken install gives.**
    Two wrong turns in a row on this, both against a sandbox that was completely healthy. First
    `papyrus.test`, which is a host-only name and cannot resolve in a guest at all. Then `8402`,
    which is not the wrong port so much as the wrong machine: it is the port the clean-room gate
    forces, not the 8000 a real install uses.

    Both came back as `ERR_CONNECTION_REFUSED`, which is the single least informative error this
    project can produce. It is what a broken install says. It is what a wrong port says. It is what
    typing a guest's address into a host browser says, because the guest has its own network stack
    and its own `127.0.0.1`, and the host's browser asking the host's loopback for the guest's
    service is asking a machine that never had it.

    Papyrus binds `http://127.0.0.1:{port}` in the Caddyfile, loopback only, and that is deliberate:
    it is the security posture a reader ships with, and nothing here relaxes it for convenience. So a
    host browser can never reach a sandboxed Papyrus at any address, and the honest answer to "how
    do I look at this" is a browser *inside* the guest.

    What makes this cheap to state now is `build/sandbox/hold-open.ps1`, which prints the address and
    says which machine to type it into. The general shape, and it is the addition to the "worked,
    just not for the reader" family: **when a local address fails, establish which loopback you are
    on before theorising about the port.** Two of the three variables in a local URL are not local at
    all once virtualization is involved, and the port is the only one anybody thinks about.

229. **"The guest did not start" and "the guest has not started yet" are different claims, and the
    process list cannot tell them apart.**
    Launched the sandbox, waited twenty seconds, found no `WindowsSandbox.exe`, and concluded the
    launch had failed. It had not. The guest was booting, took its usual minute or two, and then
    ran the logon command perfectly: 12,644 files copied, four processes up, `/ready` answering 200
    in fifteen seconds. Every assertion about the launch in that first check was wrong, and all of
    them were confident.

    The trap is that "no process yet" and "process died" are the same observation. The evidence that
    separates them is the results folder, because it is the only thing written by the guest and read
    from the host. By the time I looked again there were three files in it and the whole question
    was settled.

    The mirror image is worse, because it looks like the same failure while meaning the opposite.
    Windows Sandbox refuses to start a second guest while one is open, and it does so by exiting
    **zero, with no output and no window.** I read that as "the sandbox is broken" while a perfectly
    good guest was running underneath it, and started debugging the wrong thing entirely. The
    distinguishers are `vmmemWindowsSandbox` and `WindowsSandboxServer`, which persist for the life
    of a guest and were right there in the process list the whole time.

    The general shape: **a silent exit code is not a failure report, and a process list is not a
    progress file.** Check for the thing the subject would have written if it were getting anywhere,
    not for the thing that launched it.

230. **A library backup parked in `database/` blocks the build, and the fix is to move it, not to
    delete it.**
    `build-install.php` refused to report success: the build contained files that must not ship, and
    named `database/papyrus-20261013-031701.sqlite`. The guard is right and I would not weaken it. An
    install carrying a library looks exactly like one that does not until somebody opens it, and
    "opened it and found 867 series they never installed" is the worst thing a packaged app can do.

    The tempting response is to delete the file. That would have destroyed 4.9MB of somebody's
    actual reading history: 867 series, 21,448 chapter states, 223 sources, 1,277 imported series,
    verified by reading the file before touching it. It was a timestamped backup somebody had
    deliberately taken, and it happened to be sitting in the one directory the build copies.

    So it moved to `.tools/backups/`, which `.gitignore` line 77 already covers, after copying,
    comparing SHA256, and re-reading the copy from its new home to prove it was intact. The build
    then passed: no developer data across 12,644 files.

    The underlying shape is older than this instance: `database/.gitignore` hides `*.sqlite*`, so
    `git status` is silent and clean and will never warn anybody. The file is invisible to the one
    tool a developer trusts to tell them what is not going to ship, and visible only to the build.
    That is an acceptable arrangement, provided the build is the thing that complains, which is
    exactly what it did. The lesson is not about the backup. It is that **a build guard which fires
    on somebody's data must be answered by moving the data, and the move is only safe if the guard
    named the file and the data was read before it was moved.**

231. **A feature that closes a door must not close the only key, and the fix is a window rather than
    an opening.**
    `single` deployment mode disabled registration. That was correct and deliberate: it is a
    one-reader install, and an open sign-up form is a hole in it. It also meant a brand new install
    had no way to make its first account, because the only route to one was the form that had just
    been closed.

    Found by following the setup instructions, opening the site, and trying to log in with the Suwayomi
    username from `.env`. That account belongs to the manga engine, not to this app, and the mode that
    would have let the reader create one was shut by design. So the two halves of the product were
    each correct and the product was unusable, which is the most expensive combination there is and the
    one no unit test in this project would ever have caught.

    The answer that shipped was `users:create`, a console command. True, and a reader still had to
    open a terminal to use the application they had just installed. The answer that shipped now is
    that `single` mode opens sign-up **while nobody has registered**, and closes it the moment the
    first account exists. Not an opening: a window. The security property the mode exists for is
    fully intact, and a fresh install has a door.

    Three things that had to be true for this, and each was a bug first:

    - **The link and the endpoint must ask one question.** The login page gated its sign-up link on
      `Deployment::allowsRegistration()` and the middleware gated the route on the same method, which
      is lesson 227's exact shape waiting to happen: two answers to one question, able to disagree,
      with every test green because both were individually correct. It is now one object,
      `Registration::isOpen()`, and both call sites ask it.
    - **A check and a use is not atomic.** Reading a user count and then writing a row has a gap in
      it, and two browser tabs submitting the form at once is not a contrived thing to imagine on a
      loopback install. Both requests see zero users, both pass, and a `single` mode install ends up
      with two readers, which breaks the invariant the mode is named for. So the POST takes a lock
      and re-reads the count while holding it. The test proves the lock turns the second one away by
      actually holding it, rather than asserting the method returns false for reasons it does not
      explain.
    - **The pre-filled value is only read in the state it is written.** The remembered address goes in
      the file when the first account is created, and is read only while registration is open, which
      is the only state where that is true. Read afterwards, it could be stale: wipe the database and
      it would offer an address belonging to nobody.

    ## The part that was refused rather than built

    "Save the account so it launches with the user each time" most naturally means the password too,
    and that was declined rather than overlooked. Laravel stores the password as a hash, so anything
    that could sign the reader in unattended would have to keep the **plaintext** on disk, readable by
    anything running as that user, unrotatable without redoing setup. `users:create` refuses that on
    purpose: it generates a password and prints it once, and warns on stderr when `--password` is
    used, because a command line leaks into shell history and the process list. Writing the plaintext
    into a file the launcher reads on every start is that same leak with more reach and less
    visibility.

    So what is stored is the address, which buys the thing that actually mattered: the one field
    nobody has to retype. The general shape, and it applies well beyond this: **when a request asks to
    persist a secret so a system can authenticate unattended, ask what the plaintext would have to
    look like, because that is the only version that works, and it is rarely worth it.**

232. **A configuration that reads as a security boundary is not one, and I asserted it was without
    checking.**
    `packaging/frankenphp/Caddyfile` said `http://127.0.0.1:{{APP_PORT}}`. The installer guide said
    "by default nothing outside your computer can reach it". The launcher's comment said the app
    binds loopback. Three places saying the same thing, in the same voice, none of them true.

        Caddyfile said                         http://127.0.0.1:8000
        the listener was actually bound on     ::           the IPv6 wildcard, every interface
        http://192.0.2.1:8000/ready         200
        http://192.0.2.2:8000/ready          200

    Windows Firewall had inbound-allow rules for `frankenphp.exe`, so nothing local was holding the
    line back either. A reader's library, their reading history and their account were answering on
    the local network. For contrast, nginx on the development checkout binds `127.0.0.1` correctly on
    the same machine, so nothing about the host made this unavoidable.

    ## The part that is my fault and not the code's

    I told a reader this was unreachable, in a previous session, while explaining why a sandbox
    address would not work. That claim came from reading the Caddyfile and not from asking the
    socket. I then wrote the claim into a docs file and repeated it in conversation. This is the
    project's own lesson about a confident wrong answer, and the reason it is lesson-shaped rather
    than a code comment is that the failure was mine, not the configuration's: **naming a loopback
    host in a site address does not restrict which interfaces the listener binds.** The only
    directive that does is an explicit `bind`, and it is now `bind 127.0.0.1 ::1`.

    What makes this one so cheap to ship broken is that every artefact points the same way. The file
    says loopback, the docs say loopback, the comment says loopback. A reader, or an agent, checking
    any one of them finds nothing to question. The check that would have caught it is the one nobody
    makes: ask the machine.

    ## What the fix cost, which was the interesting part

    The first attempt replaced the site address with `http://localhost:8000` and added the bind. That
    broke the app completely. The launcher waited sixty attempts and reported "the web server started
    but never reported ready", and Caddy's access log was full of:

        msg: "NOP"  status: 0  host: 127.0.0.1:8000

    `NOP` is Caddy's word for no route matched. A site address is also the `Host` it matches, and the
    launcher's own readiness probe sends `Host: 127.0.0.1:8000`. So the socket bound, accepted every
    connection, and served nothing at all. The fix was to list both spellings on one block:

        http://127.0.0.1:{{APP_PORT}}, http://localhost:{{APP_PORT}} {
            bind 127.0.0.1 ::1

    Which is also what made the second half of the change work. The passkey relying-party id is
    `parse_url(APP_URL, PHP_URL_HOST)`, `APP_URL` is `http://localhost:8000`, and a browser cannot
    use a passkey at all on an IP address. So passkeys on the packaged install had never worked, and
    Chrome's refusal told the reader to use an address the launcher was never giving them. Both names
    are now listed, the reader is handed `localhost`, and the probes keep the literal.

    ## The test that fails against the shipped file

    `LauncherBindTest` asserts the explicit `bind` exists and that every address any `bind` names is
    loopback. A test asserting the site address is loopback would have passed against the broken file,
    which is the whole defect: the address was always right and always useless.

    All three mutations are killed, and the third one caught me. Removing `localhost` from the site
    address initially left all ten tests green, because the assertion read the raw file and the
    comment block I had written explaining the `localhost` rationale contains that exact string. A
    test reading comments reports on the author's explanation rather than on the program. I fixed the
    same class of bug in `SandboxRunnerTest` earlier the same day and reintroduced it here, which is
    the argument for writing the lesson down rather than fixing it once.

    The general shape, and it is the addition to the "a check that reports a verdict about something
    it did not read" family: **a security claim that comes from reading configuration is not a
    security claim. Ask the socket, from an address that is not supposed to work, and see whether it
    works.**

233. **The one image anybody looks at is the one that was right, and the wrong ones were silent.**
    Generating an app icon produced a perfect five hundred and twelve pixel render and two completely
    empty small ones: `favicon-16.png` at 96 bytes and `favicon-32.png` at 104 bytes, both fully
    transparent, no error, no warning. Had the page shipped, the icon in every browser tab would have
    been a blank square while the file anyone would open to check it looked perfect.

    The cause was one line. The downscale loop tracked the *target* size rather than the canvas size, so
    it never halved, and the final resample was then told its source was 64 by 64 when the source
    really was 2048 by 2048. It read the empty top left corner.

    ## Three diagnoses, two of them wrong, and the third was the code

    This is the part worth keeping. The obvious answer was that `imagecopyresampled` does not
    area-average across an extreme ratio, which is documented, true of GD, and was not the cause. The
    second answer was that my own measurement was reading `imagecolorat` on a palette image, because
    GD writes a low colour PNG as a palette and `imagecolorat` then returns an index rather than a
    colour. That one turned out to be a real bug in the measuring script, and it was still not the
    cause: the same zero appeared after fixing it.

    What settled it was a standalone probe running the same GD calls with correct bookkeeping. It
    printed solid `E9553B` pixels all the way down to sixteen pixels and held ink between 34 and 39
    percent at every size. GD was innocent. The difference had to be in my code, and the only
    difference was that initialisation.

    The general shape, and it is the addition to the "a confident wrong answer beats no answer" family:
    **when a check reports something absurd, suspect the check before the code, then suspect the code
    before doubting the platform, and get the platform out of it with a probe rather than an
    argument.** I argued with the reader instead, and I was wrong twice for reasons that had nothing
    to do with either of my two theories.

    The generator now refuses to write an icon carrying less than five percent ink. That guard is the
    point of the file, and it is a refusal rather than a warning because nothing else about this class
    of failure is loud.

234. **A check that reports zero is more dangerous than one that reports wrong, because zero looks like
    a filter working.**
    Two of the three bugs on the way to the licences page produced empty lists rather than incorrect
    ones, and both passed every assertion that existed at the time.

    The npm lock file reader filtered on a `name` field. `lockfileVersion 3` records no `name` on a
    dependency at all: the name is the path after the last `node_modules/`. The filter therefore
    rejected all 307 entries and the reader reported zero JavaScript packages, which is exactly what a
    reader that had correctly found none would look like.

    The licences page was asserting that an absent licence counts as closed, on the reasoning that
    silence is not permission. That reported twenty three packages as not open source when exactly one
    is. The other twenty two were platform specific prebuilt binaries for darwin, freebsd and arm that
    record no licence field at all. A page that overstates its own restrictions is not evidence of
    compliance either; it is a false accusation about twenty two packages.

    Both are now three-state: named as proprietary, licensed, and not licensed, with the counts
    reported apart so a change to one cannot inflate the other. The general shape: **assert on the
    magnitude, not just the sign.** "Is it empty" is satisfied by an empty list, an empty list, and an
    empty list, which is why this is the shape that survives.

235. **The mutation that survived had not happened, and the one after it found my own duplication.**
    A mutation on the licence classification reported as surviving: the suite stayed green. The
    explanation was that PowerShell's `-replace` takes a regular expression, the pattern contained `?`
    and `||`, and it matched nothing. The file was never changed.

    That is the `ANCHOR MISSING` case the project's own harnesses already distinguish, arrived at by
    hand rather than through one. A mutation reported as a survival must be checked against the file
    before it is reported as a gap, because the two mean opposite things and one of them costs nothing.

    Redone with literal replacement and by line index, both mutations were killed immediately.

    Then the second one, which killed correctly, exposed something real underneath: the classification
    rule existed twice, once static for construction and once as an instance method for `isUnrecorded()`.
    Two implementations of one question in new code, written the same afternoon, which is lesson 227 and
    a fairly humbling illustration of how much that failure mode is about attention rather than
    complexity. Any mutation that made the two disagree would have been caught by nothing at all,
    because both were correct.
236. **An IP address is PII whatever kind it is, and I shipped two of the reader's while writing up a
    security fix.**
    While documenting the loopback bind fix I pasted the two real LAN addresses into ROADMAP.md as the
    evidence that the listener was reachable from outside the machine. Committed, pushed. The gate I
    had built for exactly this fired afterwards rather than before, which is the whole story.

    The reader's correction, which is the part worth keeping: **base addresses are issued per region,
    a machine's address locates it, and an address is PII regardless of kind.** I had reasoned about
    this by category, and the categorising was the error. RFC1918 versus public changes *who* can be
    hurt, never *whether* the address identifies. A private address still maps the shape of somebody's
    network, which vendor's DHCP is serving it, and how it is subdivided.

    Which is worse in my own gate, because I wrote the reasoning down: it listed three private ranges,
    stopped, and its docstring said RFC1918 addresses "are not personally identifying on their own, and
    the argument that they are has been made and rejected." That was me rejecting it. The consequence
    was concrete and immediate: **a public address, the one that genuinely pins a house to a region,
    walked straight through a gate that was designed to catch addresses.** The rule was narrower than
    the threat, and it had been narrowed by the author's opinion rather than by measurement.

    ## What changed, and what the tests found

    The rule is now an allowlist rather than a denylist: reserved and documentation space is permitted,
    everything else is refused. Four exemptions, each defensible in one sentence: loopback and
    unspecified (identical on every computer, and the bind fix needs them), RFC5737 and RFC3849
    documentation space (reserved by the IETF for appearing in documents), broadcast, and the cloud
    metadata endpoint `169.254.169.254` (the same literal on every provider's every VM).

    `fe80::/10` is deliberately *not* exempt. A link-local IPv6 ends in an interface identifier derived
    from the MAC address, so it is a hardware fingerprint in plain sight and more identifying than most
    public addresses.

    Then the gate got a test file, which it had never had, and the test immediately found seven wrong
    answers. Two were mine:

    - **The RFC1918 check ran after `10 <= a <= 126`.** So `192.0.2.1` was reported as "a routable IP
      address, which locates a machine", which is both the wrong verdict and the wrong reason. It was
      still *refused*, so the gate still exited 1 and nothing looked broken. **A correct exit code from
      an incorrect classification is the most dangerous shape a gate can have**, because it teaches the
      reader that the output can be trusted and stops them reading it.
    - **`192.0.2.1` is the address the reader actually leaked, and the gate was only catching it by
      accident**, via a branch written for a different range.

    ## The one that would have shipped again

    The real find. The gate read `git ls-files`, which lists the *index*, and a new file is not in the
    index until somebody stages it. So a leak written into a fresh file was invisible for exactly as
    long as it took to run `git add`, which is the window `git add -A`, `git commit`, `git push` sits
    in. Planted as a test: an untracked, unstaged file holding three real addresses returned "no IP
    address of any kind" and exit 0. Nothing was wrong with the rules at all; the file was simply never
    in the list. It now reads untracked files too, with `--exclude-standard` so `.gitignore` is still
    honoured, and the whole scan still takes 0.8 seconds.

    **A gate is only as good as the set it reads.** Writing correct rules against an incomplete
    corpus is the same mistake as writing correct code against wrong inputs, and I have made that
    mistake enough times now that it is worth naming as a category.

237. **Validating after matching is not validation, and I proved it with a module built for it.**
    The IPv6 branch of the gate accepted anything shaped like hex groups and colons and handed it to
    `ipaddress.ip_address()` to confirm. That produced 1,400 false positives on a clean tree, because
    `e::f` and `a::BAC` are *both genuinely valid IPv6*. They were also, in the source, PHP type hints
    like `Cache::forget(...)` and log timestamps like `20:14:38`.

    The lesson is not "regexes are hard". It is that **the shape has to carry the discrimination before
    validation runs**, because validation answers "is this well formed", which is a different question
    from "is this an address in this context". `ipaddress` was never going to help: those strings are
    real addresses, written by accident, by a compiler.

    Fixing it by narrowing to full eight-group and bracketed forms, plus the `fe80:` prefix for the
    compressed link-local range, took the count to zero with the rules unchanged. Four hex characters
    with a digit first is a prefix no type hint or colour produces, which is why that one is safe.

    I also nearly shipped an IPv6 scan as *measured fact* when it was my own bad regex: the first scan
    reported 1,353 hits for `::` and hundreds for fragments like `e::c`, and I had to discard the whole
    output rather than report it. A measurement of the wrong thing is more dangerous than no
    measurement, because it looks like diligence.

238. **A relative path passed to a build tool fails twenty lines later and says nothing about itself.**
    Rebuilding `build/install` from current source, I passed `--into=build/install`. The build copied
    12,228 files, validated 20 required paths, staged a 179MB JRE, and reported
    `resolved ini: no tokens left, resolved by the launcher itself`, before failing with

        the install cannot boot with its own resolved php.ini.

    Every one of those steps was true and none of them pointed at the cause. `Supervisor::path()`
    joins the root onto whatever it resolves, so a relative root produces relative paths in every
    file the launcher writes, and the probe's php.ini ended up with
    `extension_dir = "build/install/runtime/ext"`. That is a correct path from the repository and a
    wrong one from inside the install, because PHP reads it relative to the process rather than to
    your shell. A real install passes an absolute root and is unaffected, which is why this is a trap
    for whoever runs the build rather than a defect a reader would meet.

    Worth about an hour of reading the build's own output backwards, so it is now refused at the top
    of the script, where the sentence can name the fix.

    The general shape joins several others here: **a failure message that reports where you ended up
    rather than what you passed is a failure you have to reverse engineer.** The build's message was
    not wrong. It was about a php.ini, and the thing that mattered was a command line argument.

239. **A skip list naming the directories that exist is a list that goes stale, and mine went stale in
    twenty minutes.**
    The machine-detail gate skipped `/build/install/` and `/build/dist/`. I created
    `build/install-stale` to compare two builds, and the gate scanned all 12,000 files of it and
    reported 480 findings, every one of them an ASN.1 object identifier or an RFC range
    definition inside spomky-labs/pki-framework.

    Not one was machine detail. All of them were the gate reporting a stale list rather than a
    finding, which is the worst outcome available, because 480 false positives teach a reader to
    ignore the output, and then the one real finding is ignored too.

    Worth noting that this lesson's own examples are the same shape. Naming them would have the gate
    reporting two more findings from this file, so they are described rather than written down. An
    ASN.1 object identifier and a benchmark range are both address-shaped with every octet in range,
    which is the whole difficulty in one pair of examples.

    This is the "naming the paths you know about is a list that goes stale" shape from the project's
    own defect families, and I wrote a fourth instance of it into the tool built to prevent the class.

    The rule is now structural rather than enumerative: everything under `build/` is generated except
    `build/sandbox`, which is hand-written source that happens to live there. A new build directory is
    skipped without anybody remembering to add it.

    And the test that found it is the second thing worth keeping. The skip list had **four entries
    that never matched anything**, because `rel` is a repo-relative path like `vendor/laravel/...`
    which does not start with `/vendor/`. It went unnoticed for two reasons that had nothing to do with
    each other: `vendor` is in `.gitignore`, so the untracked scan removed it before the prefix was
    consulted, and `storage/logs` did match, because somewhere in its history the gate was invoked
    with an absolute root. Two files agreeing by accident, and which form was in play on a given run
    depended on the caller. That only became visible when a test asked.

    **A skip rule that is never exercised is not a skip rule, it is a comment.** Now every prefix is a
    path component, so it holds whatever the caller passes, and 14 cases pin the behaviour.

240. **My first version of the new guard printed this machine's path into a build message.**
    Having just been told twice not to put machine detail in tracked files, I added a relative-path
    guard whose help text ended:

        Pass an absolute path, for example:
          --into=<the developer folder>\\build\\install

    That is the account folder layout, in a tracked file, as an example. The machine-detail gate would
    never have caught it, because the gate scans the repository and this string only exists *at run
    time* on this machine, which is precisely the window AGENTS.md warns about: nothing is exposed
    until it is executed.

    The fix is that the message names no path at all and says the default is already absolute. Worth
    recording because the reflex was to be helpful by printing the very value that is forbidden, and
    being helpful felt like it outranked the rule. It does not.
241. **A public page is not a public page until it has rendered signed out, and nothing else will
    tell you.**
    The FAQ was meant to answer "does this thing send my data anywhere" for a reader who has just run
    the application and does not yet trust it. That reader is, by definition, not signed in.

    The first version used Livewire's default page layout, which is the application shell. The shell's
    sidebar reads `auth()->user()->name` in four places. So the page whose entire purpose is to
    reassure a signed-out reader was the one page on the site that threw
    `Attempt to read property "name" on null`, on the very first request from the very person it was
    written for.

    What makes this worth recording is how it survived every check I ran. The route was added and I
    confirmed it was defined. The view was added and I confirmed it compiled. The links were added in
    three places. **I never rendered it signed out.** Four green checks, and the one that mattered was
    the one I did not think to do. `test_the_page_is_reachable_without_an_account` now does it, and it
    is the first test in the file rather than one of the last.

    Two smaller things came out of the same fix, both of them mine guessing at a framework rather than
    reading it:

    - `#[Layout('components.layouts.auth')]` raised `MissingLayoutException` on a view that exists,
      because page layouts resolve as the anonymous component namespace `layouts::auth`, not as a view
      path. The exception names a file that is sitting right there.
    - Writing `#[Layout(...)]` on its own line above `new #[Title(...)] class` does not attach the
      attribute to the class. Livewire compiles a page component by injecting a `return` ahead of the
      class, so the attribute lands on the `return` and the compiled file fails to parse with an error
      naming neither the view nor the attribute. Both attributes have to sit together on the `new`.

242. **A privacy page that says the wrong thing is worth less than no privacy page, so the claims live
    in PHP and the tests assert on them.**
    The request was for a page making it clear this is local and captures nothing. The obvious first
    draft of the headline answer was "nothing leaves your computer", and it is false in three specific
    ways:

    - Connecting an AniList account sends the series and chapter number you finished.
    - Downloading a chapter contacts the manga source, because that is how a chapter is fetched.
    - Installing a source extension downloads a jar from a repository.

    None of those is surveillance. All of them are things the reader started. But a claim with an
    unlisted exception is exactly the failure this page exists to prevent, so the exceptions are their
    own block with their own heading, and every one of them says who initiated it.

    Which is why the content is `App\Support\Faq` rather than markup. Prose is the weak point in a
    codebase that tests everything else, and a privacy statement is the easiest thing in an application
    to let rot: it stays true right up until somebody adds a script, and nobody re-reads the paragraph.
    The tests assert that every entry in `outbound()` appears in the privacy answer, so dropping one is
    a failing test rather than a quietly overstated promise.

    The outbound list was not guessed. Every URL in `app/` and `config/` was listed: `anilist.co` and
    `graphql.anilist.co`, both behind an OAuth flow the reader starts; `lhtranslation.net` and
    `tachimanga.app`, both inside import fixtures; `sqs.us-east-1.amazonaws.com`, an unused default in
    the queue config. There is no analytics, telemetry, crash reporting or error reporting anywhere in
    the application, and that is now something the page states and a test would catch being changed.

    The sign-in screen's existing promise, "no tracking, no telemetry, no third party between you and
    your sources", is now a link to this page. An unverifiable claim on a sign-in screen is worth
    exactly as much as the reader's suspicion of it, which is usually less than the claim deserves but
    more than the claim can prove on its own.

243. **The same mutation reported a survival three separate times, and every one was an anchor miss.**
    Lesson 235 recorded this once. It has now happened again, twice in this session, and both times
    the culprit was a PowerShell `.Replace()` whose anchor included a line ending that the file did not
    use, which is not an error and produces a file that is completely unchanged.

    What makes it keep happening is that the tool and the report agree. PowerShell printed no warning,
    the test run printed a clean `0 failed`, and "0 failed" plus "the mutation should have failed" is
    indistinguishable from "the mutation was ineffective" unless somebody goes and reads the file.

    So this is now a habit rather than a lesson: after any mutation reported as surviving, check the
    file before believing it, and if the anchor is missing, redo the mutation by line index, which has
    no escaping to get wrong. Line index is slower and it is worth it, because it is the only form
    whose failure mode is loud.

    The third one was killed three ways once applied: one assertion failure and two errors, the errors
    coming from the `LogicException` guard in `Faq::privacy()` firing because no entry claimed to be
    the privacy entry. A guard that refuses to return a wrong answer, and a test that notices, is the
    whole shape of the thing.

244. **Signing is refused by omission, because an unsigned artefact looks exactly like a signed one.**
    `build-installer.php` now signs the finished `.exe` with Authenticode, and the design constraint is
    that unsigned must be a *decision*. An unsigned installer on disk is the same size, has the same
    name and shows the same icon; the only difference is something a reader has to know to look for.

    So the rules are: ask with `PAPYRUS_SIGN=1`, refuse if no certificate is configured, refuse if the
    signature does not verify, and require `--unsigned` to skip. A build that quietly produced unsigned
    installers whenever nobody happened to have a certificate is a build that trains everyone to
    distribute unsigned installers.

    `PAPYRUS_SIGN=` set to *nothing* counts as unset, and that is load-bearing rather than pedantic. An
    empty value is what a shell export or an unpopulated CI secret looks like, and it is precisely the
    shape of "signing was on and then quietly was not".

    Verified against a real certificate rather than a mock, which immediately paid for itself: signtool
    answered `You cannot use the /td option twice`, because the code passed the timestamp digest
    algorithm in the base arguments and again in the timestamp block. A mocked tool returning exit zero
    proves the function ran. It says nothing about whether the file is signed, which is the only question
    the reader will ever have an opinion about.

245. **A zip cannot be signed, so the portable ships a hash and says why in the archive itself.**
    Measured rather than assumed, because the whole distribution story depends on it. A real
    certificate, a real `signtool.exe`, a real zip:

        SignTool Error: This file format cannot be signed because it is not recognized.

    Authenticode covers PE images, catalogs, MSI packages and PowerShell files. A zip is a container.
    There is no configuration that makes it work, and the correct response is to stop looking.

    So the portable is distributed the way every other project distributes a tarball: a published
    SHA-256 beside it, and a reader who compares it knows the file is the one that was published. The
    `.exe` gets the stronger mechanism because the `.exe` can.

    Which makes reproducibility load-bearing rather than tidy. A zip whose bytes change every build has
    a hash that changes every build, and "is this the file you published" becomes unanswerable. Entries
    are sorted before writing, and two builds of identical content are asserted to produce identical
    hashes. Measured: two runs into sibling output directories give byte-identical archives.

246. **A build that packages its own previous output, silently, forever.**
    `--out` inside `--install` is now refused. It was not, and it looked completely fine:

        run 3   3 files
        run 4   5 files      the archive contains dist1/Papyrus-1.2.0-portable.zip

    Each build packages the last one, the entry count climbs, and on the real install that is a 305MB
    file absorbing a copy of itself. No warning, no non-zero exit, and the entry count looks plausible
    at every step.

    Found by a test that asserted reproducibility and failed, which is the only reason it surfaced: the
    behaviour is invisible from the output and obvious from the archive contents. The fix is a refusal at
    the top, and the test now pins it.

247. **Two more of my own checks that could not have failed, in the same afternoon as two that could
    not fail the other way.**
    The portable's non-PE test captured `$threw` by value into a closure, so the closure set its own copy
    and the assertion read an untouched `false`. It reported "signing a non-PE file must be an error"
    against code that was refusing correctly throughout. Usually this project's notes are about an
    assertion that cannot be reached; this time the signal never left the closure.

    And PHPStan found the mirror image: `if ($staged === 0)` in the portable, which is unreachable
    because `$staged` counts the injected readme and is therefore provably one or more. The intent was
    "an empty install must not become an archive", and the check now counts what came from the install
    rather than the total, which is what the intent was all along.

    Worth pairing with lesson 243. Three of the five problems in this session's packaging work were
    checks that could not report a result, and not one of them was a hard piece of logic. They were
    ordinary assumptions, written quickly, about whether a variable held what it was supposed to.

248. **A failure I called a flake was a defect of mine, and I named it one without checking.**
    The portable build test that asserts reproducibility failed about one run in fifteen. I could not
    reproduce it on demand, and I wrote the following into this file:

        The cause of the original failure was not pinned. It appeared twice in five runs and then not
        once in thirteen, which is the worst shape a failure can have.

    That is a false claim, and it was false in the most convenient possible direction. It converted a
    defect I had written into bad luck in the environment, which cost nobody anything to believe and
    spared me from finding it.

    The reader noticed, which is the only reason it was ever found, and the correction is worth more
    than the defect.

    ## What it actually was

    A loop that built twice and compared hashes. The script looked reproducible: six consecutive builds
    of the same tree gave six identical digests. So the script was fine and the suspicion moved to the
    test, which was the right instinct and the wrong reason, because the test was also fine.

    What was needed was to stop guessing and build the thing that answers the question. Forty attempts
    of two builds each, and on attempt 14 the pair disagreed, with a report of which entry differed:

        entries: 5 and 5
        DIFFERS PAPYRUS-PORTABLE.txt
          run 0: digest 5b1162caffc0b05d, size 1366, mtime 2026-10-14 00:07:30
          run 1: digest 5b1162caffc0b05d, size 1366, mtime 2026-10-14 00:07:32

    Same content, same size, same CRC, timestamp two seconds apart. `addFromString()` stamps the entry
    with the time of the call. Files added with `addFile()` take their timestamp from the file on disk
    and are stable on their own, so exactly one entry in the archive was different on every build.

    The fix is one line, `setMtimeName()`, pinned to 1980-02-01 because that is the earliest instant
    the zip format can represent. Verified with the same hunt that found it: eighty builds, one
    distinct digest.

    ## Why this mattered rather than being cosmetic

    A zip is the one artefact in this project that cannot carry an Authenticode signature. The published
    SHA-256 is the only thing a reader can check it against, and a digest that changes on every build of
    identical content answers nothing at all. The reproducibility was not a nicety I had added for
    tidiness; the whole distribution story depended on it, and I had claimed it while it was false.

    ## The general shape

    **An unreproduced failure is an unexamined suspect, not a mystery.** The words "flaky" and "the
    cause was not pinned" are claims about the world, and making them about my own code needs evidence
    that does not exist yet. What I should have written, after thirteen clean runs, was: I do not know
    why this fails, and I am not going to guess, and here is what I am going to build to find out.

    That would have taken ten minutes. What I wrote instead was an excuse, and it arrived dressed as
    candour, which is the part worth remembering: **being precise about the limits of an explanation is
    still making one up if you have not looked.**

249. **A certificate I made, reported as made, and could not sign with.**
    `make-certificate.php` wrote a `.pfx`, printed a thumbprint, and exited zero. Following its own
    documentation then failed:

        Cannot find path 'Cert:\CurrentUser\My\<thumbprint>' because it does not exist.

    A pfx on disk is not a certificate in the Windows store, and every documented way of signing by
    thumbprint reads the store. So the script was producing something nothing could consume, reporting
    success, and the failure only appeared at the point of use.

    The script now installs what it makes, into `Cert:\CurrentUser\My` rather than `LocalMachine`, because
    that needs no administrator and a single developer's machine should not be changed more than it has
    to be.

    Two smaller traps in the same fix, both of which produced an error that pointed somewhere else:

    - **`ConvertTo-SecureString ''` throws.** The script's default is a blank password, and importing the
      pfx with `ConvertTo-SecureString '' -AsPlainText -Force` fails with "Cannot bind argument to
      parameter 'String' because it is an empty string". Omitting `-Password` entirely is the only
      spelling of "no password" PowerShell accepts.
    - **A path with a space.** `Get-PfxCertificate -FilePath <path>` splits at the first space unless
      the value carries its own quotes inside the command string, which AGENTS.md already records for a
      different tool. It is a property of the shell rather than of one script, and the command is built
      as an array for the same reason.

    Worth pairing with the lesson about a skipped dependency and the one about an empty list: **the
    artefact existed, the tool succeeded, and the thing it was for did not work.** Nothing in the pipeline
    checks that an input is usable in the way the next step will use it, which is why the certificate gets
    read back and the signature gets verified by asking Windows rather than by asking the tool.

250. **I told the reader self-signing would not remove the warning, and nearly corrected myself into the
    opposite claim.**
    It does not remove it. Measured both ways, with the count of trusted roots read back afterwards:

        root absent from every store    ->  UnknownError, signtool verify /pa exits 1
        root imported to CurrentUser\Root ->  Valid

    But I got there by nearly writing the reverse, because an intermediate measurement said `Valid` with
    nothing trusted. The root was still in the store. Every attempt to remove it had silently failed and
    reported success: `Remove-Item` on the certificate object, which has no `Delete()` method, and a
    `Where-Object` filter on a Subject that begins `OU=self-signed, O=Papyrus` and so does not match the
    pattern I wrote.

    Removing it through the `X509Store` API and re-reading the count settled it in one command. That is the
    general shape and it is the same one as lesson 248: **"I removed it" is a claim about an action, not a
    fact about the world.** The count has to be read back, and if the count is the thing under dispute, the
    measurement is not finished.

    What made this one dangerous rather than merely sloppy is the direction. The false reading would have
    said self-signing removes the Windows warning, which is the thing a reader most wants to hear and the
    thing least true. Getting it wrong in the pessimistic direction is a nuisance. Getting it wrong in the
    flattering direction is a person trusting a file they should not.

251. **A guard that refused the wrong thing, and reported that it had refused the right thing.**
    `--out=..` writes a private key into the parent of the repository. On this machine the parent is
    the developer's own folder, which is exactly where the machine-detail gate exists to stop things.

    The guard that was supposed to stop it read:

        str_starts_with($comparable($resolvedRoot).'\\', $comparable($resolvedHome).'\\')

    which asks whether the root starts with the home, with the separator appended to the haystack
    rather than the needle. So it is:

      - **false** for every genuine escape, because `C:\Users\<you>\...` does not start with `d:\data\`,
        which is the one case it exists for, and
      - **true** when the home and the root are the same directory, because appending a separator to
        both sides makes the two strings identical.

    It printed a confident refusal with the wrong reason attached. The tell was the message: `--out=.`
    was being rejected as "cannot be above the repository root", which is a different problem, and a
    message naming the wrong cause is worth more suspicion than one naming the right cause.

    Three separate escapes turned up before any of it was caught, and each wrote a real key:
    `--out=` resolved to the repository root, `--out=.` to the repository root a second way, and
    `--out=..` to the folder above. All three printed success.

    Worth pairing with the check-that-cannot-fail family: **a guard that produces output is not a guard
    that works**, and the cheapest way to tell them apart is to read what the output says rather than
    only that there is some.

252. **A test that passed against code doing the thing the test exists to prevent.**
    The test was "a scratch home must not install anything into `Cert:\CurrentUser\My`". Removing the
    guard that prevents it changed nothing, and the test passed.

    The cause was not the guard being unreachable. The test passed `--password=probe`, and
    `installIntoStore()` already refuses any non-empty password because a password must not reach a
    command line. So the store was untouched whether or not the guard was there, and the test proved
    nothing at all.

    A test has to isolate the thing it is testing from every other thing that could produce the same
    outcome. Here there were two refusals in series and the test was sitting behind the wrong one.

    The fix was to use the blank default password, which is the only configuration in which the guard
    is the only thing standing between the script and the machine. That is not a detail of the test;
    it is the whole reason the test can say anything.

253. **A cleanup that deleted the developer's real signing certificate.**
    The store test records which thumbprints were present before it ran, and removes any that appeared
    afterwards. Correct design, and it deleted the actual certificate the project signs installers with.

    `storeBefore` was assigned inside the one test that asserted on the store, and read in `tearDown`,
    which every test runs. So the other eleven tests read an empty baseline, found a Papyrus
    certificate that had been there all along, and removed it as a stray.

    The general form: **a cleanup that removes whatever it did not find at the start will delete things
    that were never the test's, if the start is not recorded on every path that runs the cleanup.** The
    baseline is not a detail of one test. It is an invariant of the class, and the only correct place to
    establish one is `setUp`.

    What made it survivable was that the certificate was still on disk as a `.pfx`, so the same identity
    came back rather than a new one being minted. Minting a replacement would have been invisible: the
    thumbprint would differ and nothing would say why.

    Worth pairing with the portable readme lesson. There, a claim was made and nothing checked it.
    Here, a check was written and it was narrower than its name, which is the quieter version of the
    same mistake.

254. **A check that reports a file missing because its own matcher is wrong is the loudest
    false report there is, and it is still a false report.**

    I opened the 305MB portable and asked whether eight required files were in it. The matcher was
    `str_ends_with($name, '/packaging/launcher/papyrus.php')`. The portable has no wrapper
    directory: Windows extracts it so that `packaging/launcher/papyrus.php` lands beside the
    application rather than inside a folder named after the archive. The leading slash never
    appears, so the check reported the launcher entry point, the snapshot command, the run record,
    the reader half, the backups library, the Suwayomi version record, the packaged PHP and the
    web server as all MISSING. Every one of them was present. The archive was complete.

    The portable build says so itself, at line 148 of `build-portable.php`: *"Unpack this anywhere
    and run Papyrus.cmd."* Nothing is nested, because the thing the reader runs is at the top level
    next to the readme, which is `PAPYRUS-PORTABLE.txt` and is injected as a string rather than
    staged from disk, so it does not appear in a search for files on disk either.

    This is the family AGENTS.md lists as *"A grep that cannot match what is there is not evidence
    it is absent"*, which has now cost time three separate times: trap 138 with a CSS class, trap
    151 with an `aria-label`, and this with a path separator.

    The asymmetry is the part worth keeping. A false MISSING gets investigated, because a reader
    can go and look at the archive and see the file sitting there. A false PRESENT never does: it
    is the answer that was wanted, it agrees with everything else already believed, and nothing
    revisits it. So the false negative is the harmless direction, which is exactly backwards from
    how much attention a line reading MISSING attracts.

    The guard, which now runs before any negative is printed: push one path that is known to exist
    through the same predicate and print what came back. If the predicate returns false for a file
    that can be seen, the predicate is what is broken, not the archive.

255. **Subtracting one count from another is not a count of the remainder, and the number is
    plausible enough to file a bug on.**

    I was checking the documented gate that `Papyrus.cmd` is CRLF in the install and that the
    build fails if any bare LF survives. My probe counted line endings like this:

    ```
    $crlf   = substr_count($bytes, "\r\n");
    $bareLf = strlen($bytes) - $crlf;      // wrong
    ```

    The repository copy is 5186 bytes and contains no CR at all. It reported **5186 bare LF**,
    which is the file's length wearing a label. The portable copy came out as **5068 bare LF** and
    119 CRLF, which is close enough to the documented failure state that the next step would have
    been reporting that the build's line-ending gate had stopped working.

    It had not. The build does what AGENTS.md describes: `build-install.php` rewrites the file
    with `implode("\r\n", $cmdLines)`, then counts `/(?<!\r)\n/` and fails the build if that count
    is not zero. The real arithmetic is `substr_count($bytes, "\n") - $crlf`. Measured after the
    correction, the repository copy is 118 LF and no CRLF, and the archived copy is 119 CRLF and no
    bare LF, so the gate is doing exactly what it claims.

    The general form: **a subtraction across two different units produces a number whose units are
    neither.** The minuend here was bytes and the subtrahend was a count of two-character
    sequences. Any result is possible, and the most likely results are small ones that sound like
    real measurements.

    The correction is not the arithmetic, it is the habit: a probe that prints a count has to print
    the counts it subtracted from beside it. `5186` next to `0 CRLF` next to `0 CR` is obviously
    wrong to a reader. `5186 bare LF` on its own is a measurement.

256. **A field that names a day the reader has not reached, because the build dropped the
    timezone it had recorded on purpose.**

    `build/install/version.txt` is written as:

    ```
    built 2026-10-15 00:01:32+00:00
    ```

    The offset is there deliberately, because that file is the build's own record and an instant
    without a zone is not an instant.

    The release manifest then takes ten characters of it:

    ```php
    'released' => substr((string) ($stamped['built'] ?? date('c')), 0, 10),
    ```

    so `releases/1.2.0.json` records `"released": "2026-10-15"`. The build machine is
    `SA Pacific Standard Time`, UTC-05:00, and its local date at the time of the build was the
    14th at 19:09. PHP's default timezone is UTC, so `date()` agrees with the manifest and both
    name a day the person who built the thing had not got to.

    Nothing errors and nothing is obviously wrong. It is a date field whose meaning depends on a
    clock the reader does not have, produced by cutting a string at a fixed offset from a value
    that was written to be unambiguous.

    The fallback is worse than the case it falls back from. `date('c')` does carry the offset, so
    when it is the fallback the offset was present in the source and absent from the output, and
    anyone reading `releases/1.2.0.json` afterwards cannot tell which of the two produced the
    value they are looking at.

    The general form: **truncating a formatted value to a convenient length silently discards the
    part that made it unambiguous.** `substr($stamp, 0, 10)` keeps the date and throws away the one
    thing that says which date it is.

257. **A gate whose pattern names one spelling of an address reported five failures and one
    false pass against an install that was working.**

    The clean-machine gate read the port the launcher had chosen out of the launcher's own output:

    ```powershell
    $match = Select-String -Path $startLog -Pattern 'running at http://127\.0\.0\.1:(\d+)'
    ```

    `Supervisor::appUrl()` prints `http://localhost:%d`. It does so deliberately, and the comment
    above it says why: `localhost` is the address the app advertises in `APP_URL` and the one a
    WebAuthn relying-party id is derived from. So the pattern matched nothing, `$reportedPort` stayed
    null, and one run produced all of this:

    ```
      Papyrus is running at http://localhost:8402          <- the install, serving, correct
      [FAIL] the launcher reached running  never reported a port
      no app port was ever reported, so the web checks below cannot run
      [FAIL] /ready is 200   got 0
      [FAIL] /ready says ready
      [FAIL] /up is 200      got 0
      [FAIL] /up returns html
      [ok  ] port  released                                <- the port name was empty
    ```

    Six lines, one root cause. The install was up on 8402 for the whole run. The gate had probed
    `http://127.0.0.1:/ready`, with nothing after the colon, and reported the connection failure as
    an endpoint that was down.

    The last line is the one worth keeping. `$appPort` was null, `Get-NetTCPConnection -LocalPort $null`
    returns nothing, and `$null -eq $still` is therefore true, so a check that had not been performed
    was recorded as a pass, with the port name printed as nothing at all. Trap 156 again, and in the
    form that is hardest to see: **a check whose subject is missing does not fail, it passes.**

    Three changes, and the third is the one that would have caught the other two:

    - the pattern accepts both spellings, because a pattern that names one will break again the
      next time the launcher deliberately changes what it prints
    - the web checks are guarded on the port existing, so they cannot report a URL that was never
      valid
    - a missing port is recorded as a named failure rather than announced and stepped over

    And the fix left one thing behind, which the next clean-machine run caught. The check carried its
    failure text as a literal, so once the pattern matched and the run went green it printed:

    ```
    [ok  ] the launcher reached running  never reported a port
    ```

    A passing line quoting the failure it used to report. Same fault as `port  released` with an
    empty name: **a string that survives the fix it was written for.** The detail now says what
    happened, which on the passing run is `reported port 8402`. Worth pairing with the habit this
    file keeps recommending, of reading a green line's wording as carefully as its colour, because
    the colour was right and the sentence was wrong.

    And the general form, which is the fourth time in this file: **a selector that misses its
    subject reports a failure that does not exist.** Trap 138 with a CSS class, trap 151 with an
    `aria-label`, trap 254 with a path separator, this with a hostname. Four different subjects and
    one shape, which is the argument for reading the thing the check is pointed at whenever it
    surprises you, before believing the check.

258. **A security fix was applied to one of two listeners, and the one nobody checked was the
    one serving the library.**

    `LauncherBindTest` exists because FrankenPHP was listening on the IPv6 wildcard while every
    document in this project said nothing outside the machine was reachable. It is fixed, with an
    explicit `bind 127.0.0.1 ::1` in the Caddyfile, and the test asserts on that `bind` line
    specifically rather than on the site address, which was already right and already useless.

    Suwayomi was never in scope. It is the other half of the same stack, it holds the library, and
    its own default is every interface, which it writes into `server.conf` itself:

    ```
    server.ip = "0.0.0.0" # default: "0.0.0.0"
    ```

    So a launcher that writes only the port is not declining to choose an address. It is choosing
    the wildcard. Measured on a running install before the fix, and taken to the end rather than
    stopped at the socket:

    ```
    Get-NetTCPConnection -LocalPort 4567 -State Listen     LocalAddress :::4567   java
    127.0.0.1:4567      reachable
    192.0.2.1:4567    reachable
    192.0.2.2:4567     reachable

    { sources { totalCount } } unauthenticated                    Unauthorized
    login as the configured reader, from 192.0.2.1              succeeded
    { sources { totalCount } } carrying that token                223
    ```

    A reader's whole library, 223 sources on the machine this was measured on, readable by anything
    that could route to it and guess a password. Stopping at the `Unauthorized` would have been the
    comfortable answer and would have been wrong about what a weak password on a reachable login
    endpoint is worth.

    **How it was found, which is the part worth keeping.** A person watching the sandbox noticed that
    only one of the two firewall prompts appeared. Windows prompts for a listener beyond loopback
    and does not prompt for one bound to loopback, so the missing prompt was a measurement of the
    second listener. It was not a defect report, it was an absence of one, and nothing in the
    project's own gates was looking for it.

    Two general forms:

    **A control that stops being visible has not necessarily stopped working.** The natural reading
    of "the PHP prompt stopped appearing" is that something regressed. The opposite was true: that
    prompt disappearing was the fix working, and the surviving JDK prompt was the unrepaired half.
    Neither conclusion is available without checking, and the check is a socket listing rather than
    a judgement.

    **A fix phrased as a property of one file does not generalise to the stack.** "Bind the listener
    to loopback" was applied to `packaging/frankenphp/Caddyfile` and asserted there. The other
    listener's address lives in a file the *server* writes and rewrites, which is why it was not
    obviously in scope and why the guard has to live where that file is written. The launcher now
    pins `server.ip` on every start, through the same `setValue()` seam that already owned the
    port, and the annotation Suwayomi puts after the value survives the rewrite.

259. **A leak check that reads filenames reported "no developer data" across 12,672 files
    while the tree carried this machine's address inside a comment.**

    `build-install.php` walks the whole install and refuses to finish if it finds a `.env`, a
    database file, a log, or a populated framework cache. It then says:

    ```
    verified: no developer data in the build, checked across 12672 files
    ```

    That sentence was true about the check and false as a claim, because the walk looked at where
    each file is and never at what is inside it. An identity reaches a build far more easily in a
    comment than in a filename. This machine's two LAN addresses were written into a doc comment in
    `SuwayomiConfiguration.php`, the file was copied into the install, and the build reported
    twelve thousand files checked.

    The order is what makes it a story rather than a note. The build ran first. The tracked gate,
    which does read contents, ran second, and failed on eight lines across three files. By then the
    tree already contained them and an installer was a minute from packaging it.

    So the check now reads contents too, and looks for three things:

    - the account name of whoever ran the build, read from the environment at run time rather than
      hard coded, so it finds the next machine's name and not this one's
    - the checkout folder name
    - any RFC1918 address, with loopback, `0.0.0.0` and the RFC5737 documentation space allowed,
      because they mean every computer or no computer

    Text files only, and under 4MB, because the jar, the JRE and FrankenPHP are the majority of the
    tree, are not ours, and a byte scan across them cannot find a comment.

    Two things about that check that had to be established rather than assumed.

    **The pattern was measured in both directions, because a leak check is only useful if it is
    wrong in one direction.** A false positive stops a build that was fine, which is recoverable and
    loud. A false negative ships the address to everybody, which is neither. Seventeen cases: the
    three RFC1918 ranges including both boundaries of the `172.16/12` one, loopback, the wildcard,
    `192.0.2.1`, `2001:db8::1`, two addresses sitting just outside the `172.16/12` range so its edges
    are tested rather than assumed, addresses
    inside sentences rather than standing alone, and four and five digit final groups that look like
    addresses and are not.

    Two of those cases had to be reworded rather than corrected, and the reason is worth having.
    They were addresses just outside the `172.16/12` range, used to show the range's edges, and the
    tracked gate refused both of them as routable addresses that locate a machine and its region.
    That is right and it is stricter than the pattern I had written: the rule this project holds is
    that an address is identifying whatever kind it is, because base addresses are issued per region,
    so a number outside RFC1918 is still somebody's patch of the world. The first version of this
    check exempted only RFC1918 and would have accepted both.

    **And it was run against a planted leak before being believed.** A guard nobody has watched
    fail is not known to work, which is trap 247 and trap 252 in one line, and this project has now
    shipped several of them.

    The general form, which is worth more than the fix: **"checked across N files" is a statement
    about effort and not about coverage.** The number reads as thoroughness and is compatible with a
    check that examined nothing but paths. What makes a leak check a leak check is that it opens the
    files.

    And the part I have to record honestly, because it is the fourth time. Traps 236 and 240 are
    about putting this machine's address and path into tracked files. This was the same mistake,
    made again, while writing trap 258, which is about a document claiming a protection it did not
    have. The gate caught it in under a minute, which is the only reason it cost ten.

260. **A guard that flags Guzzle and Symfony fails every build, and a build that always fails
    gets its guard deleted rather than fixed.**

    The content leak check added in trap 259 was written as "read every file, skip the big ones and
    the binary ones", which reads as stricter. Run against the install it named:

    ```
    - vendor/guzzlehttp/guzzle/src/ProxyOptions.php      (an RFC1918 address)
    - vendor/symfony/http-foundation/IpUtils.php          (an RFC1918 address)
    - vendor/livewire/flux/stubs/.../flag.blade.php      (an RFC1918 address)
    ... nine more
    ```

    Every one of them is correct code. `ProxyOptions` and `IpUtils` are libraries whose entire
    subject is private address ranges. The check could not be made to pass by fixing the code, and a
    guard that stops every build stops being read as a guard within a week.

    So the scan is an allow-list of what this project authors, which is 360 files, rather than a
    deny-list of what it does not, which is 12,257. An allow-list is the right direction here: a
    third-party tree that appears in a later version is skipped rather than fatal.

    And an allow-list goes stale in the *other* direction, which is trap 239 exactly. A new directory
    of ours would simply stop being scanned, which is the failure the check exists to prevent, and it
    would be silent. So both lists are enforced against each other: every top level entry in the
    install must appear in one of them, and an unclassified entry stops the build with a message
    saying which list it belongs in. Staleness is now loud in both directions instead of quiet in
    one.

    **A guard applied after the thing it prevents is a note about a guard.** The same block had its
    size cutoff in the wrong place:

    ```php
    $bytes = @file_get_contents($path);          // the 174MB jar goes into memory here
    if ($bytes === false || str_contains($bytes, "\0")) { continue; }
    if (strlen($bytes) > 4 * 1024 * 1024) { continue; }   // the cutoff, one check too late
    ```

    ```
    Fatal error: Allowed memory size of 134217728 bytes exhausted
    (tried to allocate 182960648 bytes) in build-install.php on line 1184
    ```

    The cutoff existed and was correct. It was written after the allocation it was meant to prevent,
    so it described a policy the process had already broken. `filesize()` is asked first now, and a
    file over the limit is never opened.

    **And it was proved against a planted leak before being believed.** A file carrying this
    machine's real address and account name was dropped into `app/`, and the build refused and named
    it: `app/_leakprobe.php (the account name and an RFC1918 address)`, exit 1, zero vendor false
    positives. That is the difference between a check that exists and a check that works, and it
    costs eighty seconds to establish.

261. **A test that added a fixed tenth of a second to a value compared to the second, failed three
    runs in ten, and its comment said it could not.**

    `ProcessIdentity::isSameProcess()` compares start times *to the second*, because the two sources
    disagree below one: `REQUEST_TIME_FLOAT` carries microseconds and Windows CIM reports
    `CreationDate` at a whole second. The test asserted that a start time a tenth of a second later
    is still the same process, which is the property that matters and is worth testing.

    It is also false whenever the process started at `x.9`, because `x.9 + 0.1` is the next second,
    which the comparison correctly rejects. About one run in ten. Measured:

    ```
    run 1..7   passed
    run 8      FAILED
    run 9      FAILED
    run 10     FAILED
    ```

    Three consecutive, because consecutive runs land in the same fractional window of the second.
    A single green run would have proved nothing, which is the only reason this is worth writing
    down: the first version of this test passed when run alone and the fix was to stop using a
    fixed offset.

    What makes it worth more than the three lines is the comment above it:

    > `+0.1` cannot cross a boundary from a value that already has microseconds of its own only by
    > accident, so it is the accepting case

    That was written believing the arithmetic had been checked. It had not, and it is false in the
    most ordinary case there is. Trap 216 is this, from earlier in this file: a test one step from a
    boundary is a coin flip. Here the lesson had been written down once already and then not
    applied, which means writing it down is not the same as having learned it, and the only thing
    that actually caught it was running the test twenty times instead of once.

    The offset is now half of whatever is left in the current second, derived from the real
    fraction, which is positive and short of the boundary whatever the fraction happens to be. The
    assertion message carries the start time, the fraction and the offset, so a future failure says
    which of those went wrong rather than only that a number did not match. Twenty runs, twenty
    passes.

    **And then the full suite found two more of the same shape, in code I had not touched.**
    `RunRecordTest::test_it_reports_its_age` failed on a real run with:

    ```
    Failed asserting that 3601 is identical to 3600.
    ```

    It built the start stamp from `time() - 3600`, formatted it, and then let `view()` read the
    clock again. Same fault, one second between the two readings. And `JwtTest` compared
    `time() + 300` against a token the helper had minted from its own `time() + 300`.

    The sharper lesson is not about offsets. It is that **the seam to avoid this already existed and
    was not being used.** `RunRecord::view()` has taken an injectable clock as its first argument
    since it was written:

    ```php
    public function view(?int $now = null, int $stalledAfter = 120): array
    {
        $now ??= time();
    ```

    Three tests were passing `time()` themselves and letting the default fire. The fix was to use the
    parameter, not to widen an assertion. `MintsTokens` now takes the issuing time for the same
    reason, so every token a test mints can be placed on the clock deliberately rather than at
    whatever second the run happened to start in.

    So the order of the lesson matters. Above, the fix was to derive a safe offset from the real
    clock because there was no way to hold the clock still. Here, there was one in the signature the
    whole time, and the test that used it badly was three lines long.

    **A test that reads a clock twice and compares the two readings is wrong however carefully the
    arithmetic is done**, because the variable is which second it lands in, and that is not a
    property of the code under test. Search for it rather than waiting to be told: the shape is
    `time() - N` on one line and an exact assertion on a number derived from it on another. Three
    instances had been sitting in this repository, in three files, for the length of time it took
    one of them to lose a coin toss in front of me.

262. **Something opened, it looked authoritative, and it was the wrong program.**

    A person watched the clean-machine gate run and reported twice that Papyrus did not open. What
    was on screen both times was `http://127.0.0.1:4571/library`, which is Suwayomi's own web UI:
    Library, Updates, History, Browse, Downloads, Settings, About. Papyrus is on `8402`.

    Two separate causes, and the first one made the second one impossible to see clearly.

    **Suwayomi opens a browser of its own.** Its default is `server.initialOpenInBrowserEnabled = true`
    and it fires on every start. Meanwhile the app opens nothing unless `--open` was passed, and only
    `Papyrus.cmd` passes it, because the gate runs `papyrus.php start` on a machine with no browser
    worth opening.

    So the reader is shown a manga server's administration panel, unprompted, while the application
    they wanted opens nothing at all. That is the entry in this file's own table as *"It worked, just
    not for the reader"*, in the worst order available: the wrong thing opened confidently and the
    right thing was silent. A reader who double-clicked the shortcut, seen the only window that
    appeared, and concluded the app was broken, would have been right about the appearance and wrong
    about the cause, and nothing in either window would have corrected them.

    Turned off in `prepare()` alongside the address and the backup path, because the launcher already
    writes that file on every start and no reader has a reason to know the setting exists.

    **And the gate stopped everything and left the guest standing.** Its own report says so:

    ```
    [ok  ] port 8402 released
    [ok  ] port 4571 released
    [ok  ] no papyrus process survived the stop  found 0
    ```

    A stopped install that still looks like a running one, with a stale browser window in front of it.
    So the second report was of an instance the gate had deliberately killed, and there was nothing in
    the window saying so. `launch-gate.ps1` now closes the guest once it has read the report, and waits
    for `vmmemWindowsSandbox` to go before returning, because the next launch is refused silently while
    one is still up.

    The general form: **a gate that tears down what it tested must say so where it leaves off**, in the
    place the person is actually looking. The report says it, in a file. Nobody reading a browser window
    is reading a report.

    And a smaller one, from checking the change: `powershell -File` does not fail on a parameter the
    script does not declare. It puts it in `$args` and carries on:

    ```
    powershell -File t.ps1 -KeepOpen      ->  KeepOpen is: True     extra args: 0
    powershell -File t.ps1 -NotASwitch    ->  KeepOpen is: False    extra args: 1
    ```

    So "I passed a flag and it did nothing" and "the flag is not being read" are the same observation,
    and I used that non-distinguishing check to conclude a `param()` block was broken when it was
    working. The distinguishing check is the one above: bind the real flag and read the value back.

263. **A fallback that named a broken install instead of detecting it, and left a corrupt archive
    with a hash file beside it for three days.**

    `build-portable.php` read the version out of the install's own stamp like this:

    ```php
    $version = preg_match('/\d+\.\d+\.\d+/', $version, $matches) === 1 ? $matches[0] : '0.0.0';
    ```

    A missing or unreadable `version.txt` became `0.0.0`, and the build carried on. That is a
    fallback doing the opposite of its job: the case it covers is precisely the one where the answer
    is unknown, and it answered anyway.

    **Why the stamp is the thing to ask about.** `build-install.php` rewrites the install directory in
    place, so it empties that directory and then refills it. A build killed part way through leaves a
    tree with none of the install in it, and no `version.txt`, because that file is written near the
    end. So the absence of the stamp is not a corner case, it is the signature of the failure.

    Measured on exactly that state:

    ```
    files           12143 from the install, plus the readme
    size            70.8 MB
    ```

    against 12672 files and 623 MB for the same version. Nothing objected. 12143 is a number, and this
    script had nothing to compare it against, which is the same fault as the leak check that reported
    "checked across 12672 files" while reading no file contents: **effort read as coverage.**

    And the fallback was not harmless, it was load bearing in the worst way, because it produced a
    plausible name. The artefact it left was sitting in `build/dist` when this was found:

    ```
    Papyrus-0.0.0-portable.zip        0.001 MB   10/13/2026 18:43
    Papyrus-0.0.0-portable.zip.sha256
    ```

    A 1KB file that does not open as a zip, beside a hash file whose whole purpose is to say this
    archive can be trusted. A reader who downloaded it and checked it against the sidecar would have
    been told the check passed. Three days old, because nothing looks at `build/dist` unless a build
    is being run.

    So the portable now refuses, in three parts, each naming the mistake: no `version.txt` at all, a
    `version.txt` that does not hold a version, and a version that is not the repository's. The last
    one is a check `build-installer.php` already had for the `.exe`, so this was the same mistake one
    stage later in the half of the build that lacked it.

    Three lessons worth keeping, and the second is the one I would have got wrong.

    **A fallback in a build script is a place where a failed check becomes a successful answer.** The
    instinct to keep going is right for a warning and wrong for an identity: if the thing you cannot
    determine is what the artefact will be called, there is no safe default, because the default is a
    name somebody will look for.

    **Three existing tests were passing on a fault I had not introduced.** They staged an install
    containing a `.env` or a database and asserted that the build refused it and named the file.
    Adding an earlier check moved which refusal they hit, and all three failed. The correct response
    was to give them a complete install, because each was meant to be testing one fault and the new
    check had made them collide with another. The tempting response is to reorder the checks so the
    old tests pass untouched, which would have meant putting the weaker check first on purpose. Worth
    noticing how easily that would have looked like care.

    **And the artefact was the evidence, not the build log.** Nothing in a successful run says the run
    was wrong. The `0.0.0` zip says it, loudly, to anybody who opens `build/dist`. The lesson from the
    filename is that a defect can sit next to its own proof for days without anybody looking at the
    directory where the proof is.

264. **A standing permission to commit is not a standing permission to push, and the cost of a
    push was known before five of them were made.**

    One session, five commits pushed to `main`, five full CI runs started:

    ```
    685e1af   46.5 min
    6697149   55.2 min
    2ced03c   63.2 min
    5bb8f35   53.0 min
    ```

    Roughly **218 minutes** of a limited free allowance, four of them on commits that were superseded
    within minutes of each other, on a machine that was not paying for any of it. The fifth never ran:
    *"The job was not started because recent account payments have failed or your spending limit needs
    to be increased."*

    The two facts that should have stopped it were both held at the time. The suite was measured at
    936, 1016 and 1075 seconds across three runs, and a Windows CI runner is several times slower, so
    the cost was not a mystery. And the constraint was already known, because it had been raised when
    code signing came up and money came with it.

    What was missing was not information. It was the step where the two facts are put next to each
    other. One question, early, would have covered it:

    > This will cost about an hour of your Actions allowance. Push now, or hold locally?

    Four words of consequence. It was not asked once, in five opportunities.

    **And the permission had been written down wrongly, which is the part worth keeping.** For years
    this file said:

    > Then commit and push. Both are expected without asking.

    Read as a unit, that is a licence to push five times in an afternoon. It is now split in two, in
    `CONTRIBUTING.md`, with the measurement above it: a commit is free, a push spends somebody's
    minutes, and the two permissions are granted separately.

    **The general form: a standing permission erodes the thing that made it worth granting.** It was
    granted for ordinary work. Nothing about it said *for ever, without regard to cost*, and treating
    it that way was not following the instruction, it was extending it past what anyone agreed to.

    One mitigation, added the same day: `concurrency` with `cancel-in-progress: true`, so a second push
    cancels the first rather than stacking with it. That caps the damage of the mistake. It does not
    excuse the mistake and it is not permission to push.

265. **A fixture is not a data set, and two mistakes about that cost 224 seconds a run.**

    `ReaderSnapshotTest` built a SQLite database with 867 series and 21,467 chapters. Those were the
    reader's real library numbers, taken from `AGENTS.md` because they were to hand.

    Ten of its twelve callers wanted "a database with some rows in it": a path containing a space, a
    `VACUUM INTO` that fails, output in a shape we do not understand. Four tests already passed small
    sizes explicitly, because they cared about the counts. Nobody had asked whether the other eight did.

    And the rows went in one `exec()` at a time, outside a transaction:

    ```php
    for ($i = 1; $i < $series; $i++) {
        $pdo->exec('INSERT INTO series_states (suwayomi_manga_id) VALUES ('.$i.')');
    }
    ```

    Every `exec()` outside a transaction is its own commit, and every commit is an fsync. 22,332 of
    them, per call.

    ```
    224.7 s   before
      1.8 s   after, and 13 of 13 passing
    ```

    The suite went from 15.6, 16.2 and 16.9 minutes to 11.14. The whole regression had arrived with
    the snapshot work and nobody had connected the four-minute class to the hour-long CI run.

    Two separate mistakes, and fixing only the first one is the more attractive option because it is
    less work. The transaction is the mechanism. The size is the cause.

    **Neither is the reason a test should not be library-sized.** The honest reason is that a synthetic
    twenty thousand row database is not a small version of the reader's library, so a test passing on it
    says nothing about one. The question a large fixture appears to answer, "does this survive real
    scale", is answered by a real install and by the sandbox gate, and answered there properly.

    The general form: **numbers copied out of production into a test are the numbers of a different
    system**, and they arrive looking like a specification when they are a copy.

    A note on the fix, because it nearly went wrong: making the bulk insert transactional and folding
    the first row into the loop passed the speed target and failed three assertions by exactly one.
    The counts were not the problem. The shape was, and the comment above the fixture now says so, so
    the next tidy-up does not remove it and break three tests the same way.

266. **Three false negatives in one verification command, and the strongest evidence in this file
    came from the failures of the checker.**

    The command was checking a document for five coloured status marks. It reported three unmet
    requirements and one of them was real. The other two were the checker being wrong, and a third was
    hiding in the same output:

    | Reported | Actually |
    | --- | --- |
    | three good references look dangling | the heading pattern required a trailing dot after `0.3` |
    | zero of the colour marks are present | PowerShell cannot cast a surrogate pair to `[char]` |
    | (nothing) | it called `preg_match_all`, a PowerShell function that does not exist |

    That last one is the dangerous shape. The others printed a wrong number. This one printed nothing,
    and nothing reads as agreement.

    All three came from writing the verification in the shell it happened to be convenient in, rather
    than in something that could tell it was wrong. Rewritten in PHP, which handles unicode and whose
    functions exist, it found the real fault immediately and then a second one after the first was
    fixed.

    The real fault is worth as much as the lesson: the eleven findings were in a table and their
    reasoning was in a separate block underneath, so a finding's severity and a finding's risk lived in
    different places. That was found by a shape check, not by reading it.

    **The general form: a check that cannot report its own failure is not a check.** Three of the four
    traps in this section about verifying things turned on a verification reporting a confident wrong
    answer, and here three of them happened at once in a single command. What makes the difference
    between them is whether anything distinguishes "I looked and found nothing" from "I did not manage
    to look", and a shell pipeline cannot make that distinction.

    The fix is not more care with the shell. It is that every count a verification prints must be a
    count it actually took, and a language where the functions exist.

267. **A fixture whose path has no space in it cannot test anything a space breaks, and two
     tests named for the defect were passing the whole time.**

    Running `papyrus snapshot` produced a correct pair of both databases and then left the install
    unable to start. The JVM's own log named the line:

        server.conf: 70: Expecting end of input or a comma, got '\'

    and the line said this:

        server.backupPath = "C:\\My Projects\\papyrus\\...\\backups" Paper Comics\\papyrus\\...\\backups"

    The value, written twice. `setValue()` captured the existing value as `(\S+|"[^"]*")`, and
    those alternatives are tried in that order, so `\S+` wins even on a quoted path, and `\S+` stops
    at the first whitespace. This project sits under `C:\My Projects`, so the capture
    took `C:\My Books` and the rest of the line fell into the trailing group and was put back
    after the new value. Every start grew it again.

    Two tests exist for exactly this. `test_preparing_twice_does_not_grow_the_file` and
    `test_preparing_repeatedly_settles_on_one_value` both passed, for as long as the defect was
    live. The fixture was:

        sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-suwayomi-'.getmypid().'-'.uniqid()

    `sys_get_temp_dir()` here is `%LOCALAPPDATA%\Temp`. No space. So every value
    written had no space in it, so `\S+` matched the whole value and behaved.

    Reverting the fix now kills both, and kills them without the new read-back assertion, so the
    tests are the thing that catches it:

        Failed asserting that 347 is identical to 249
        the value, duplicated five times after four starts

    **The general form is the one in trap 265, and it is worth stating again because it cost more
    this time.** A fixture is not a data set, and the more specific version is worse: a fixture
    whose *own name* differs from the input it models. `ReaderSnapshotTest` used a real library
    because the numbers were to hand, and cost 224 seconds. This one used a path with no space
    because that is what `sys_get_temp_dir()` returns, and cost the backup feature.

    The tell is worth having: when a test is named after a shape, check that the fixture has that
    shape. A test for a path with a space, held in a path with no space, is a test that reads as
    covering something and covers nothing.

    The fix has two halves and both are needed. The capture is now `"[^"]*"` first, which takes a
    quoted value whole however much of it is whitespace or a `#`, and only then `[^#]*?` up to an
    optional trailing comment. And every `setValue()` reads the line back afterwards and refuses to
    leave anything but the value it wrote, because the write always succeeded, nothing ever failed,
    and the consequence arrived on the next start in a process that is not this one.

268. **A checker written to confirm the thing you already know is the one that has to be
     distrusted, and mine answered "yes" about the file the JVM had just refused.**

    To verify the fix against the real artefact, I wrote a checker for the exact condition typesafe
    had quoted: a backslash outside quotes. It reported that `server.conf` was fine.

    That file had killed a JVM four minutes earlier. The checker was wrong in the ordinary way,
    because its rule was "a value holding a backslash and no quote", and the broken value held both,
    so the quote test passed it. The line is a quoted string followed by a trailing token, and a
    rule about unquoted values does not describe it.

    Fixed, it then reported `# Network does not parse` on line 1, because it had not been taught
    that a comment is not an assignment. That one is the cheaper mistake and it is still a confident
    wrong answer about a file that had been read correctly a hundred times.

    So the rule was restated as typesafe states it, rather than paraphrased: a value is either one
    quoted string and nothing after it, or a bare run holding neither a quote nor a backslash.

    **The general form.** A verification written after the defect is known is the one with the least
    independent checking, because the expected answer is already in mind and a rule that is slightly
    wrong reads as agreement. That is trap 266 for the third time in this file, and the three times
    are the point: this is not a habit I am going to shake, it is a shape the work has.

    What catches it is to run the checker against the broken thing first and require it to say so.
    A checker that cannot call the known-bad case broken has not been tested, and it is about to be
    trusted with a claim.

    Which it was. My first version of it, and I printed its verdict about the real file before
    noticing.

269. **A test that reaches outside its own scratch directory is not a test of the class, it is a
     report about whoever ran the build last.**

    `DataLocationTest::test_choosing_a_location_does_not_create_it` failed on a full-suite run, for
    the first time in this project's history, and nothing had changed in it:

        Failed asserting that directory "<repo>\build\papyrus-data" does not exist.

    The test builds its own root at `build/dataloc-<pid>` and derives the candidate directory as
    `dirname($root).'/papyrus-data'`, which is `build/papyrus-data`. That is not a test path. It is
    the data directory the real launcher creates when it is run out of `build/install`, because a
    portable install keeps its data beside itself rather than in `%LOCALAPPDATA%`. So the test
    passed until the first time anybody ran the install, which on this project was during an
    unrelated verification.

    The trap is not the collision. It is that the obvious repair is to delete the directory, and that
    directory holds a reader's backups. A test failure whose cheapest fix destroys somebody's data is
    worse than a test that never existed, because it is a trap with a prize in it.

    Fixed by putting the install root inside a per-run scratch directory, so
    `dirname($root).'/papyrus-data'` is inside a directory this run created and nobody else can
    write to. The intent is untouched, because the candidate was always only ever a sibling of the
    root.

    **The general form.** When a test's fixture reaches into a path the project itself uses, the
    failure is about the machine's state rather than about the code, and it will not reproduce on the
    next person's machine, which is exactly when a real defect of the same name would be dismissed.
    A path a test asserts about should be one the test made.

    Which brings up the second half of this, which is the part that is actually dangerous.

    Running that install left 1217 untracked files under `build/`, because `build/papyrus-data` was
    not in `.gitignore`. Three of them were a `database.sqlite`, a Suwayomi `.mv.db` and a real
    `.env` carrying a generated `APP_KEY`. A `git add -A` would have committed all three, which
    section 8 forbids in as many words, and the ignore file already had the identical case written
    down for `build/health-data` a few lines above:

        A scratch data directory the launcher is pointed at ... It holds a database, logs and a lock
        file, none of which belong in a repository, and it appears the first time anyone runs the
        launcher from the repository root.

    "the first time anyone runs the launcher from the repository root" is this case exactly. The rule
    was written, it was written about a different directory, and the directory next to it behaved the
    same way. That is trap 267's shape one level out: the thing you knew was true of the thing in
    front of you, and you did not check whether it was true of the thing beside it.

    So `/build/papyrus-data/` is ignored now, next to `/build/health-data/`, for the same reason and
    in the same words. And the count that made it worth doing is the number to remember: **1217
    untracked entries, 3 of them a database or a secret, sitting in the working tree for as long as
    it took to notice.** A `git status` that is not clean is not noise to be ignored when you did not
    expect it.

270. **An assertion about behaviour is not an assertion about the report, and a command whose job
     is largely to report is exactly where the two come apart.**

    Closing the Papyrus window and running `papyrus snapshot` printed:

        Stopping Papyrus...
          Nothing was running, so nothing needed stopping.

        Starting Papyrus again...

        A pair has been written to ...

    and then started nothing. No process, nothing on 4567, nothing on 8000.

    `SnapshotCommand::conclude()` printed that line before the `$wasRunning` test that decides
    whether anything is started. The reader who does the ordinary thing, close the window and take a
    backup, is told their app is coming back when it is not, and a reader who believes that will not
    go and look.

    What makes it worth a number rather than a line is the rest of the same run. The run record for
    that very snapshot says:

        "restarting": { "state": "done", "message": "it was not running, so there was nothing to
         start" }

    and `RestoreCommand` guards the identical line with `if ($wasRunning)`. One command disagreed
    with its own record and with its sibling, and the console is the one the reader reads.

    The test for this already existed:

        $this->assertSame(['stop'], array_column($this->calls, 'event'));

    Nothing running, nothing started. It passed for as long as the message was wrong, because it
    asserts what was **called** and not what was **said**.

    **The general form.** A command that does a thing and a command that tells you about the thing
    are two contracts, and a test on the first says nothing about the second. That is most acute
    where the reporting is most of the product: a backup, a restore, a health check, a refusal. The
    reader has no other way to find out what happened, so the words are the interface, and words
    that describe an action that did not happen are worse than no words, because they are checked
    less often than exit codes.

    The cheap form of the rule: when a test proves something was or was not done, add the assertion
    that says so. Both halves, in both directions. "Nothing was started" should also be checked for
    the absence of the message claiming something was, and "something was started" for its presence,
    because a command that is silent in one of those cases has a defect the first assertion cannot
    see.

    Removing the guard now kills the test, with the misleading output quoted in the failure, which
    is the check being about the message rather than about the exit code.

    Found by doing the thing rather than by reading it. The round trip this defect came out of was
    the first time `snapshot` had been run against a real install in this project's history, and it
    is the third defect in these notes that only a real run produced.

271. **`tempnam()` creates the file it names, and a fixture that appends a suffix to what it
     returned has been leaking two files per call into the reader's machine for a month.**

    Almost every fixture in this project used `tempnam()` as though it returned a name:

        return tempnam(sys_get_temp_dir(), 'papyrus-fixture-').'.'.$suffix;

    That makes a second file, `something.tmb`, and abandons the first, `something.tmp`, for ever.
    Nothing deleted it, nothing noticed, and the oldest of them was 2026-10-09.

    Measured in `%LOCALAPPDATA%\Temp` on 2026-10-15:

        70,576  zero-byte .tmp files nothing adopted or deleted
         6,475  .tmb files, every one with an orphaned .tmp sibling of the same stem
         4,505  covXXXX.tmp.jpg files, each with an abandoned .tmp beside it

        82,457 files removed, 0 refused, 810 left alone and accounted for

    Then the directory was crowded enough that `tempnam()` stopped working in it at all. This is the
    part that matters, because the directory is not ours:

        before   20 calls, 0 returned a path, 20 returned false, 20 warned
        after    20 calls, 20 returned a path, 0 returned false, 0 warned

    while `is_dir` said yes, `is_writable` said yes, and 47.7 GB were free. A writable directory with
    room on it, in which the one function that needs to write is failing, and the only symptom is a
    warning nobody reads.

    **It was not only a test problem.** `TachimangaReader::stage()` unpacks an imported backup into a
    file it names with `tempnam(sys_get_temp_dir(), ...)`. So on a reader's machine left crowded by our
    tests, importing a backup could not unpack it, and the message it printed was about disk space.

    The class has carried a `$temporaryDirectory` seam, documented as "for a server whose system temp
    is small", and **no test had ever used it**. So the tests had always gone to the same directory the
    reader goes to, and the fixture bug and the production bug were the same bug with different
    reach.

    ## The general form

    A test fixture writes to a shared location and does not clean up. That is not a tidiness problem,
    it is a resource problem, and the resource it exhausts is one the production code also uses. The
    tests were paying it in a directory the reader would eventually need.

    What made it a month rather than a day is that **nothing failed while it happened.** Every test
    passed. The suite stayed green. The cost showed up as a slow run, which reads as the machine being
    slow, and then as twenty two failures in a file nobody had touched, which reads as a regression in
    that file. The cause was in a line that had been correct-looking since the day it was written.

    So: a fixture that writes outside its own scratch directory has a defect even when every assertion
    passes, and "the suite is green" is not evidence that the suite is tidy. The 15.3 minutes and then
    22.4 minutes were the honest symptom, and 8.4 minutes after the cleanup is the proof of what it was.

    ## What fixed it, and why not thirteen patches

    `TestCase` now owns temporary files. `temporaryFile()` puts a fixture inside a scratch directory
    that `tearDown()` removes whether the test passed, failed or threw, and all thirteen call sites go
    through it.

    Fixing thirteen call sites is how a fourteenth gets written, and a fourteenth would have had the
    same bug for the same reason the first thirteen did. The directory rather than the file is also
    deliberate: a fixture that makes two files out of one call cannot leak by being clever, because
    there is a whole directory to remove rather than one path to remember. The rule that was not being
    followed was "delete what you made", and the fix moves that out of thirteen places that have to
    remember into one place that cannot forget.

    ## And the two errors I made doing it

    The first version of `temporaryFile()` put `random_bytes(4)` in the filename. Four random bytes
    contain a path separator and a null byte often enough that twenty two tests failed with
    `No such file or directory` on paths like `papyrus-fixture-/<two bytes>.tmb`. It is hex now, and
    the comment above the line says why, because that is the sort of thing a tidy-up removes.

    And I rewrote `tests/TestCase.php` with the write tool rather than editing it, reproduced only the
    part I was adding, and deleted `skipUnlessFortifyHas()` that was already in the file. Fifteen tests
    failed with "Call to undefined method", which is the only reason I found out.

    The general form of that one is trap 269's, one level out: **a file you are adding to is not a
    file you own.** Check what is already in it against what is in the committed copy, by diff, before
    you finish. The write tool replaced sixteen lines with ninety six and I read the ninety six.

272. **A PowerShell `Start-Process` redirect target that is not a real file returns nothing, and
     prints no error, and the gate dies with an empty pid.**

    The sandbox gate was changed to start the install the way a reader does, through `Papyrus.cmd`,
    which pauses on failure so a person can read the reason. That pause would hang a gate for ever, so
    stdin was redirected from the null device:

        -RedirectStandardInput 'NUL'

    What happened, on a real run:

        started through Papyrus.cmd, which is the shortcut a reader double clicks
        launcher started, pid , watching its output

    An empty pid. No `launcher-start.log`. No `launcher-start.err`. No error of any kind. `Start-Process`
    did not create the guest and did not report that it had not.

    **The general form: a launch that fails quietly is worse than one that fails loudly, because the
    gate goes on to report something else entirely.** The run carried on to the next section and would
    have reported a missing install, which is not what happened.

    `-RedirectStandardInput` takes a **file path**. It does not take a device name, and PowerShell
    will not open `NUL` as one. Redirect from an empty file instead:

        Set-Content -Path 'C:\results\stdin-empty.txt' -Value '' -NoNewline

    **And create it before the call that reads it.** The first fix put the `Set-Content` in the probe
    section, which is three hundred lines *after* the `Start-Process`, so it would have failed the same
    way. A redirect target that does not exist is not a redirect. That ordering mistake was found by
    reading where the line had landed, not by running it again.

    **What to check when a launched thing produces nothing:** is the pid empty, and does the log file
    it was supposed to write exist. Both answers were available in the results folder and neither was
    looked at, because the script's next line printed something that looked like progress.

273. **A refusal that cannot name its cause is a new way for the gate to die silently.**

    The next run of the same gate refused to start:

        REFUSING TO LAUNCH: vmmemWindowsSandbox is already running (pids ).
        Only one guest at a time, and Windows Sandbox reports a refusal by exiting zero in silence.

    There was no guest running. The check had matched nothing and printed an empty list between its
    parentheses, and the owner had closed the window by then. So the message asserted a fact, named no
    evidence for it, and was wrong.

    **The general form is trap 266 again, and it is worth the repetition: this script exists to catch
    the three ways the gate dies without saying so, and it has produced a fourth.** An empty list in a
    diagnostic is not cosmetic. It is the difference between "one guest, pid 1234" and "nothing
    matched and here is why", and a reader cannot tell which they are looking at.

    A check should print the count alongside the values. If the count is zero, say so in words and say
    what it looked for. If it is not zero, name what it found. **Never let a diagnostic format an empty
    result into a confident sentence**, which is the same defect as the `bound to:` line in F2 and the
    "Starting Papyrus again" line in F16: a message describing a state nobody established.

274. **A here-string is an interpreter, and it edited the prose it was asked to carry.**

    Traps 207 to 216 went into this file on 2026-10-10 through a PowerShell double-quoted
    here-string. The prose contained markdown backticks, and every one that started a code span
    next to a special letter was read as an escape and executed: `` `n `` became a newline
    inside "null", `` `f `` a form feed inside "false", `` `0 `` a NUL, and a dollar name
    behind a backslash was read as a variable and replaced with nothing, leaving the backslash
    alone.

    The fragments still read as English, which is why nobody caught it: "writes alse is" and
    "reads as ." passed for prose for five days. The bytes themselves stayed out of sight for
    a narrower reason: a diff reads only the first 8,000 bytes, so this file's diffs rendered
    as text all along, while `git add` reads the whole file, saw the NUL, and stopped applying
    `text=auto eol=lf`, leaving the CRLF lines a whole-file rewrite brought in on 2026-10-13
    to be stripped by hand. One NUL in a small file does make its diff go binary, and
    generalizing that cost a wrong first reading of this one.

    The same class of write put two BEL bytes into `packaging/installer/build-portable.php` on
    2026-10-13, where a backtick before an "a" became the byte and swallowed the letter.

    The general shape: **text handed to an interpreter belongs to the interpreter.** Write file
    content with a tool that copies bytes, and after any write that went through a shell, read
    the artifact back and count what you expect: control bytes at zero, and the words where
    they were put.

## And the lesson underneath both, which cost more than either

The gate was changed to test something it had never tested, in a script that had never been run with
the change, while the owner was waiting. Three things went wrong in sequence, and each one was
preventable by a check that already exists in this repository:

1. `Start-Process` returned nothing and the run carried on, because nothing asserted that the launcher
   had actually started before the next section began. **A gate needs its own preconditions asserted,
   not assumed from having arranged them.**
2. The fix for that put the file creation three hundred lines too late. It would have failed the same
   way and I would not have known until the next run.
3. The refusal message then named a guest that was not there, and I reported it as the gate refusing
   rather than as the gate's check being wrong, because I had no reason to doubt a check in a script
   that had always worked.

**And the run before that: I invoked the guest-side script on the host** and reported its three FAILs
as a finding. Those FAILs described the owner's machine, with Oracle Java on PATH and Herd PHP, not the
install. `run-in-sandbox.ps1` runs inside the VM; `launch-gate.ps1` runs on the host. Both names are in
section 3 of the operating notes, and I read them and still ran the wrong one.

The general form, and it is the same as trap 267 and 269 and 270 and 271: **a thing done in a hurry, in
a place where it had never been done before, is the place defects live.** The pre-flight exists for
exactly that moment and it costs five minutes.

275. **A check that cannot pass is not a check either, and the healthy shape is the one that says so.**

    The F22 fix asked the diagnostics bundle for content instead of existence, and it wrote the
    strictest version of that: every collected file non-zero. The first full run with it, on an
    otherwise green install, failed:

        [FAIL] the bundle has collected logs  found 9, empty: frankenphp.txt, php.txt

    Those two are empty because the install is healthy. `frankenphp.log` is Caddy's log at level
    ERROR, so it has nothing to say when nothing errored, and `php-error.log` is PHP's error log, so
    it has nothing to say when nothing failed. The collector writes an empty file for a silent source
    on purpose, so absence stays visible in the bundle, and a test pins that behaviour. The rule as
    written could never pass, and the reasoning behind it had only asked the other question: would a
    bundle that collected nothing pass this? It would not, and that was F22 fixed. Nobody asked
    whether a bundle where everything worked would pass.

    The general form is trap 266 mirrored. 266 says a check that cannot fail is not a check. This is
    the other half: **a check that cannot pass is not a check either, and it fails the loudest way
    there is, on the healthy case it exists to protect.** Both halves are answered by the same small
    step, which is to run the check against both known states before keeping it: the shape that must
    fail, and the shape that must pass, each one measured rather than imagined. The F22 finding was
    written from the broken run alone.

    The fix names the two silent-by-design files, requires content from every other, and was run
    extracted from the script that ships against four shapes: the measured healthy one passes, an all
    empty bundle fails naming the seven, a lost source fails naming it, and a third file going quiet
    fails by name the day it happens.

276. **An array literal is not a command line, and the comma binds before the plus.**

    The F18 fix had to start the shortcut through cmd.exe, and the shortest way to write it looked
    right:

        @('/c', '"' + $shortcut + '"')

    which is not two elements. The comma binds tighter than the plus, so that is four: the switch, a
    quote, the path, and another quote. `Start-Process` joins its argument list with spaces, so
    cmd.exe received `/c " C:\Program Files\Papyrus\Papyrus.cmd "` and tried to run `C:\Program`.
    Measured twice: the broken construction exited 9009 and recorded no exit code, and the fixed one
    parsed the real exit code out of the file the runner writes.

    The general rule: **a command line is assembled by a command line's rules, not by array syntax.**
    Parenthesise the concatenation, or better, stop building a command line in the caller at all.
    The fix writes a small runner `.cmd` whose text cmd.exe owns, and passes it as a single element
    with no spaces in its path. A path with a space never reaches the caller's own quoting, because
    the caller no longer quotes anything.

277. **`ExitCode` is empty until the process has been waited for, and empty is not zero.**

    `Start-Process -PassThru` without `-Wait` returns the object before the process has finished, and
    reading `ExitCode` on it returns nothing, silently. The same invocation with `-Wait` reads the
    code correctly, which is why one helper in this project has always worked and the other one read
    a blank. A blank read here is not zero: the launcher's exit code was the number the check existed
    to report, and the check reported an absence instead.

    The gate no longer depends on it. The runner writes `%errorlevel%` to a file, and the check reads
    the file, saying `not recorded` when it is absent. **Three states, named: zero, not zero, and not
    recorded.** The third is a real answer about the check, and collapsing it into either of the
    others is how a property that was never read becomes a passing verdict.

278. **A guard that refuses over a file its own list knows how to remove, and names no remedy.**

    The rebuild refused:

        the build contains files that must not ship:
          - database/backups/papyrus-20261015-163725.sqlite
          - database/backups/suwayomi-20261015-163725.mv.db
        Refusing to report success.

    Both halves were right: the walk check exists because anything matching a library's shape in an
    artifact is either the developer's data or a build artefact, and those files were the developer's
    data. But the same script already strips `database/database.sqlite` and the journal, wal and shm
    beside it, printing a line as it goes, because a developer's library is normal in a checkout. The
    copies are the same class of file, one directory over, and the refusal did not say what to do
    about them. They were not going anywhere on their own: the checkout's own backup run wrote them,
    and every build would fail while they sat there.

    The fix is not a leniency. `database/backups` joins the strip list, and the build prints
    `removed: database/backups` like the rest. **When two rules in one script cover the same
    location, the automatic one owns it, and the loud one firing anyway is a gap in the automatic
    one, not a finding about the tree.** And a refusal that names its cause but not its remedy is
    half of trap 273: the reader can see what is wrong and has nowhere to go.

279. **Two blocks that read one computed can agree their way into rendering one diagnosis twice, and `assertSee()` agrees with every copy.**

    The series page has two callouts, the fetch failure above the scanlator filter and the
    empty-chapter card inside the chapter area, and both read `emptyReason()`. Agreement was the
    intent: they used to print their own sentences, and they disagreed about which failure was
    which. Unifying them on the same computed got the wording right, and got it rendered twice
    when `loadError` was set and the chapter count was zero, so a source that answered and had
    nothing produced the same heading and body in both places. Measured on the walkthrough of
    2026-10-16 with a DMCA-emptied listing, then reproduced by a test that counts the string on
    the rendered HTML: `2` before the fix, `1` after, and the condition now says the standalone
    callout renders only beside a chapter list still on the page.

    The general form: every test written with `assertSee()` passed the whole time this
    duplication existed, because seeing the text is true for any number of copies. Where a
    rendered thing must be exactly one, the assertion is a count.

280. **A stub for one response, written in the shape of a neighbouring response, carries a layer the real call does not have.**

    The automatic-load cycle needed the fake to answer two mutations it had never mirrored
    before, and both were first written by copying a neighbouring branch's nesting and renaming
    what differed. Each carried one wrapping layer the real call does not return, and the
    gates that run on every edit caught it twice before the shape said what the code reads.
    The general form: **a stub inherits its shape from the call it stands in for, and from
    nothing near it.** The extra layer is invisible in a green test, because reading one key
    too deep hands back nothing and the rest of the assertion does not care; it costs its
    sentence the first time someone trusts the stub's world over the call's.

281. **A conf key's type is in the exception, and conf normalization can rewrite the file after the failure.**

    The rebuilt scratch server's first boot died before any port listened:
    `ConfigException$WrongType: extensionStores has type STRING rather than LIST`. The line had
    been written as a bare string because one store and one value read scalar to the writer,
    while the server accepts a list, which the error named plainly. Worse, after the failure the
    server had normalized the file itself, so the line on disk no longer said what had been
    written there. Measured 2026-10-16 in the scratch root; the recipe beside the jar now
    carries the list form. The general form: **the type of a configuration key is read from the
    error, not from the key's name.** And after a conf-driven failure, diff the file against
    what was meant before editing by eye, because the file may no longer be the one left.

282. **A reply is not the work it acknowledges, and a persisted catalogue outlives both the restart and the comment that says otherwise.**

    Measured live on 2026-10-17, cold trust on the scratch server: the `fetchExtensions` reply
    arrived before the fetch itself had downloaded or parsed anything, named zero extensions,
    and the same server held 1,434 a minute later. The render riding on that reply said "0
    extensions" with nothing explained, and the same walk found the catalogue surviving a
    restart, so the comment above refresh() claiming "after a restart is nothing at all" was
    describing a server that no longer exists; it is corrected in the same batch. The general
    form: **a call that acknowledges asynchronously turns a running fetch into a settled zero
    the moment the reply is counted.** Whether a state survives a restart is measured by
    restarting it, not by trusting the sentence that mentions it.

283. **A conduct notice attaches to sites, and containment matching turns one site's name into an accusation against every other name containing it.**

    The first matcher matched inside the name rather than against it, and the live catalogue
    paid for it: filtering "Comix" on the real Keiyoushi index lists eight extension names
    (GlobalComix, ArbxComix, Comix, GEDE Comix, ILikeComix, KingComiX, PornComix, ComixTopia),
    and seven of them are unaffiliated publishers that received the scramble notice. The two
    upstream issues behind the class, Keiyoushi extensions-source 12130 and 16684, name exactly
    one extension, the English "Comix". The fix, exact on the name plus a parenthetical
    qualifier, was pinned by the two live shapes a word-boundary matcher would still have
    accused: "GEDE Comix" survives a boundary matcher and dies on exact matching. The general
    form: **a notice about a site's conduct sits on evidence about that site, and a substring
    match is similarity, which is not evidence.** When the live corpus can drift against a
    matcher, the corpus belongs in the test: real names measured off the real list, one that
    must match and two that must not.

284. **State set inside one Livewire testable belongs to that testable, and a second one mounts a fresh component with the property back at its default.**

    The note's first test called `trust` on one testable and then asked a second `page()` for
    the html, and the note never appeared: the fresh mount re-read the property's default and
    rendered the page honestly, minus everything the action had just decided. The existing
    failure-reporting test chains `assertSee` on the same instance for exactly this reason, and
    the general form explains it: **an action's state is readable in the request it ran in, and
    every helper that mounts a component starts from defaults.** Assertions about what an
    action produced chain onto the instance the action ran on, or set the property up front and
    test the render as its own question.

285. **Two dialogs on one page can carry the same button label, and a click that reports ok is not a click that did the thing.**

    Measured in the browser on 2026-10-17, walking the install warning: the store removal dialog
    and the warning both carry a `Never mind`, and an unscoped click recorded ok while the
    warning stayed open at opacity 1, read straight off the element. The pressed label belonged
    to an element inside the other dialog, which is closed, and the warning received nothing;
    the inference is confirmed afterwards by the scoped clicks, not by the click result. This
    Flux version stamps `data-modal="<name>"` on the dialog element, and clicking scoped to
    `dialog[data-modal="install-warning"]`, first the ghost close and then the dialog's own
    cancel, each closed the dialog and ran the server clear in the same pass, measured. The
    general form: **a selector that names no dialog can name the wrong one, and then never
    fail.** Press buttons inside a named dialog through its `data-modal` scope, and treat a
    click that reports ok as the reason to measure the effect, not as the effect.

286. **A hook nothing in the framework calls is not a hook, and a test that is its only caller tests nothing the page does.**

    Measured on the migrate page on 2026-10-17: its poll was declared through a `with()` array
    method, and the page never carried a `wire:poll` directive in its whole history. Livewire
    4.4.7 does ship a `with()` feature, `SupportWithMethod`, and what it does is call the method
    and merge the returned array into the view's data: the template receives a `$poll` variable
    the template never names, and no vendor code ever turns a `with()` return into a directive.
    Polling exists only as a rendered attribute. The row turned ready in the database twice live
    on 2026-10-16 while the card kept saying it was searching until a full reload, which is the
    symptom the migration plan recorded. The test called `instance()->with()` and asserted the
    array it returned, so it stayed green through every one of those live failures: the test was
    the method's only caller, and the method was the mechanism's only referent. The general form:
    **a contract only the test knows about was never a contract with the browser.** The
    replacement asserts what the browser reads, that the rendered html carries `wire:poll` while
    a search runs and carries none once it settles, on the block that shows progress, the same
    idiom the search page proved working with a probe.

287. **A refusal scenario named for its cause is not named for its message, because the cause and the message live in two different stages.**

    Measured on the migrate page on 2026-10-17, while writing the acceptance tests for the two
    protective refusals. The first test asserted the reader-facing line for a destination emptied
    between search and move as "That source lists no chapters for it, so there is nothing to move
    to." and the run refused it: **that sentence belongs to the other path.** When a fresh fetch
    succeeds with an empty list, the move still walks into the mapper, which refuses because none
    of the reader's chapters could be matched, so the line the reader sees is "None of this
    reader's chapters could be matched to the new source, so nothing would carry over." Only a
    fetch that *errors* takes the no-chapters path, and the fixture needs both the empty list and
    the fake 503 set to reach it. The general form: **the fixture names where it stops; the
    message names where it stops; those are two different places unless measured, and every
    assertion between them is a guess the first run will catch.

288. **A nullable column behind a boolean cast reads as null and not as false, and an `!== []` guard waves it into the loop.**

    Same session, found by the run rather than by reading: `series_migrations.unmatched_chapters`
    stores `null` when the reader chose not to carry chapters at all, because nothing was
    attempted, and the summary callout guarded it with `!== []`, which `null` passes (they are
    different things), so `foreach` took null. The two failing tests were the two not-carried
    moves, which is why the default path never saw it. The general form: **a guard against the
    empty case is not a guard against the absent case, and the choice that skips a stage is
    exactly what produces the absent case.** Guard `!== null && !== []` at the callout, and the
    tests that exist because of the choice, not the ones that exercise the default.

289. **A green suite proves the tree's migrations, and proves nothing about the checkout's applied ones.**

    Measured on 2026-10-17, on the first live press covered by this session's batch. The test
    environment rebuilds its schema from the migrations on every run, so all 2,063 tests passed
    while the checkout's own `database/database.sqlite` still had the table without this batch's
    columns, and the first live move died with `table series_migrations has no column named
    carried_chapters` while writing the run row. The transaction rolled back clean, the reader's
    data untouched, and `php artisan migrate` was one 13 ms migration away. The general form:
    **the schema the tests run against is not the schema the reader runs against, and a green
    suite has never promoted a migration into a database.** A batch's own database is a live
    thing that only `migrate` touches.

290. **A count and the list beside it must reconcile; a reader who adds them up and falls short believes something broke.**

    Measured in the same walk. The summary said 54 chapters found no match, and the details list
    named 12. Both true: the count is FROM rows minus carried rows, and the FROM list carries
    doubled scanlator variants that collapse to one destination row each, while the list names
    the rows that got nothing. The reader cannot do that arithmetic, and the first reader to try
    came out of the guess with a defect. Measured in the same press: the row said 1,194 chapters
    at search time, and the listing the press actually fetched matched 2,388 of the 2,442 it had
    to place, so a row's count is the candidate as searched, not the destination as pressed. The
    general form: **a report's parts are read as one claim even when they were made at different
    moments, so every count earns a named vintage, and where a list cannot name every row inside
    a count, the rule of the list is stated on it rather than left for the reader to discover.**

291. **A path that leaves early must still write what it did, because the page reads the row that
     survives it.**

    Measured on 2026-10-17, on the stop feature's own test. The comparison loop checked for a stop
    at the top of every series, written the counts into the row only once every three seconds, and
    returned straight from the check without writing anything. A stop that landed inside that
    window left the row at `series_checked = 0` above the two item rows it had made, and the page
    rendered the contradiction: "You stopped it, so 3 series were never looked at." naming one
    more than the rows backed. The test that caught it asserted the tally first, which passed,
    and the row second, which did not, because the tally is the loop's own arithmetic and the row
    is the only thing the reader's page reads. The general form: **an exit that arrives after the
    work owns the record of that work; a throttled writer has gaps, and early exits fall in them.**
    Write the counts on the way out, and assert the row, not the return value.

292. **A fix on one page has no force on the page copied from it, and nothing re-proves the whole unless somebody does it on purpose.**

    Measured on the bulk sources page on 2026-10-17: this walk built it from the same blueprint
    as the single series wizard, and it landed with the wizard's `with()` poll idiom exactly as
    trap 286 found it there, dead method and all, after the trap was written and the wizard was
    fixed. The correction sat one file away, inside the same working tree, while the copy was
    being written, and nothing flagged the transcription: the wizard's poll test cannot run
    another page's render, and there is no lint rule that heeds a Livewire idiom. Live on the
    copy, run 4 went ready at 19:32:38 and the card still read "Looking up your series" at
    19:33:10; run 5, ready 19:39:40, was the same seconds later; both settled only after a
    reload or a reader's click. After the same fix ran here, run 7 was caught between two
    passive browser reads, the first saying "Matching: SPY×FAMILY, 1 / 7" and the second the
    settled card, with nothing clicked between them: the flip the wizard's idiom produces,
    now on the second page, with the directive riding the block that unmounts when the run
    settles.

    The layered lesson: **a transcription reproduces whatever it transcribes, including a bug
    the source no longer has; a page whose predecessor was proven behavior by behavior is
    proven nowhere until those proofs are run against it too.** The verification that carries
    is the one aimed at the copy's own rendered html, exactly what the wizard's replacement
    test became.

293. **A page's empty state answers for every way the page can end up empty, and one of those ways is not empty.**

    Measured on the bulk sources page on 2026-10-17, live: the offer table keyed on pending
    rows alone, so once the move completed the card fell into the one copy it owns for an
    empty table, "Nothing on that source is on the new one", over a run whose every row said
    moved. Rows refused at move time, the only written record of a refusal's reason, rendered
    nowhere in that state. The badges for both already existed inside the table's own loop,
    unreachable, because the rows that carry them never satisfied the loop's collection. Both
    were caught in the same press: the walk moved six series on the rebuilt scratch library,
    reloaded the page, and the page denied the move it had just made. The fix reads the table
    from the rows that hold a destination and are not skipped, so pending rows keep the tick
    row above them, and a decided row keeps its place, its numbers, its badge and its reason;
    the exits disappear on a moved row, because a moved row is not waiting for anything. The
    general form: **the copy an empty state prints is a claim about how the page came to be
    empty, and a state no design line was written for will answer with the nearest line it
    has, so a table whose rows keep changing after the reader acts has to be read from the
    rows and not from the reader's next action.**

294. **The reader's half outlives the server's half, and a lens that joins them by id turns a rebuilt server into fiction on its own terms.**

    Measured on the scratch walk of 2026-10-17, after the host restart killed the scratch
    Suwayomi with no restore: the server came back with an empty database and handed the first
    small manga ids to entirely different series, while Papyrus's own `series_states` rows
    survived the restart untouched, because they live in the reader's sqlite. The library lens
    merges the persisted rows onto the live server library by manga id, so every stale row
    joined whatever now wears its id: the pickers counted holdings on five languages with real
    counts, and the leaving list named "Mini - Jujutsu Kaisen dj" and "Beyond Memory" inside
    them, series the review reader never saved, because ids 118 and 862 were reassigned in the
    rebuild. The page said nothing: nothing in the lens compares the id against the title it
    carries. The eight stale rows were removed by explicit id as the review reader's throwaway
    data, after a readback of what each row had joined, and what came out the other side reads
    clean: eight rows, one source's language at a time, nothing held by a series the reader
    never saved.

    Two facts worth keeping apart. The first is walk data: the eight fixtures re-added from
    search are unfetched, `chapters_here` 0 for all of them, which was exactly the condition
    that produced the pre-restart refusals, and run 4's move drove the new from side fetch
    backstop over them live: six series moved with zero refusals in about nine seconds, the
    one to three seconds between item timestamps being each live fetch, and the card's own
    line counted 2,213 chapters kept their place. The second is the hazard that stays open,
    now the register holds it: **a server that loses its data and rebuilds reassumes its id space,
    while the reader's half keeps its claims, so the join needs an agreement check between the
    id and the title behind it, and absent one the lens can carry read state across series.**
    Nothing in the batch defends a reader from that; the register names the condition.

295. **The boot's handlers live until the parent teardown runs, and a teardown that never calls the parent keeps them on the stack.**

   Measured on 2026-10-17, on the full suite's summary: five tests marked risky, all five in
   `SupervisorProcessIdentityTest`, each carrying both messages, "Test code or tested code did
   not remove its own error handlers" and the exception handlers one beneath it. The class sets
   no handler anywhere, and neither do the launcher classes it exercises, so the flags named a
   fault the code did not contain.

   The hunt, in the order it happened, because the shape matters. The summary JSON counts risky
   but prints no names, and the harness funnels phpunit's own stdout, so a redirect captured the
   counts and nothing else; `--log-events-text`, written by PHPUnit's side channel, named all
   five and both causes. A throwaway probe then walked both stacks the way the compare itself
   does (set, capture, restore, and rebuild) around every step of one flagged test's body, and
   the stacks sat flat across every launcher step: the push the flags report is not in the code
   the test runs. Instrumenting the vendored compare itself, snapshot against restore, with a
   watcher kept on a byte hash and the vendor file restored byte for byte afterwards, settled
   it: a sibling launcher class whose sixteen tests all stay flat read one handler and none both
   before and after, the flagged five read one flat at the snapshot and one extra of each kind
   at the restore, and walking a probe test around its own parent calls showed the change land
   inside `parent::setUp()` and leave inside `parent::tearDown()`. Laravel boots the application
   there, `HandleExceptions` pushes its error and exception closures, and the framework's own
   teardown pops them; the five skipped `parent::tearDown()` entirely, so the boot's handlers
   rode on into the compare, and the repair is one call at the end of the file's own teardown.
   Removing that call again put all five flags back, and putting it back removed them, so the
   round trip worked from both ends.

   Two lessons. A handler the runner flags is most likely to be one whose cleanup is a call you
   left unmade rather than code that registered anything. And the risky count deserved reading
   before the names were needed: it had been sitting in every suite summary the tool layer
   prints, and nobody had looked at it.

296. **A failure message that carries the whole rendered page cannot be read from a console capture. Redirect the run into a file and grep the file.**

   Measured on 2026-10-17, judging the Migrate tab's failing-first run. `assertSee` puts the
   entire render into its failure message, one miss carried 73,600 characters of HTML, and the
   tool layer's console capture wraps long lines mid-token: a hunt for the failing tests' names
   over the capture returned `test_a_migrate_row_opens_the_compos` and stopped inside the name,
   and a context probe over the capture matched a needle's own echo at the end of the message
   instead of a page render. Redirected to a file under the temporary directory, the same run
   answered straight: a JSON line reading `"tests":66,"passed":60,"failed":6` on line one, and
   every failing test name whole. The capture is a line-wrap lottery; the file is not.

   Companion lesson, the same evening, in the same run: one of the seven new tests passed before
   its fix existed, because its discriminating needle ("Harbor Comics") also renders in the
   source stacks, which read the same `sources` table row the fixture had made. A bogus probe
   needle, run once for the failure dump it bought, found the pass's real reason in one round;
   the test was rewritten so its first needle renders nowhere but the one branch the view owns.

297. **A global reader scope fails closed inside jobs, and a run's own rows are not protected by re-filtering them.**

   Measured on 2026-10-17, live on the checkout, during batch 2's acceptance walk. `OwnedByUser`
   answers an absent reader with `whereNull(user_id)`, which is right for a page and silently
   wrong for a queue worker: `BulkSourceMatcher::targetsFor()` reads the run's destinations
   through the `targets()` relation, the worker holds no authenticated reader, so every
   destination row returned as nothing. A browser-created list shape run (run 8) refused with
   the leaving side's message before one search went out, and a scripted replica (run 9) that
   wrote two real destination rows compared against zero of them and skipped a series the
   target provably held, recording a skip that named no source. The job had resolved the run
   itself through `forUser`, so its rows were already one reader's: the relation read needed
   the same fact, `withoutGlobalScope(OwnedByUser::class)` on the one collection every branch
   of the method reads. The suite had not once executed this read without a logged-in reader,
   which is why 2,107 green tests carried it. Red-first: a test that calls the matcher after
   `Auth::logout()`, reading writes back through `forUser` so the assertion sees the rows the
   context cannot; it failed on zero searches, and removing and restoring the scope drop
   killed and healed it on the final text.

298. **A queue worker runs the code image it started with, and its refusals look like today's code.**

   Measured 2026-10-17, the same evening: the dev worker had been up since 09:19 and every
   batch 2 edit landed after 18:00, so the job that consumed run 8 ran the morning's matcher.
   The acceptance chase at first assumed the refusal message was today's, but the text it
   recorded belonged to a shape the current code cannot produce for a list-shaped row, and
   that mismatch is what exposed the stale worker. A worker never reloads classes on its own:
   any batch that changes queue code must stop and start the workers as part of the batch
   itself, before a live accepted run can claim to have exercised the new code. The restart
   cost nothing and both workers now run the real tree.

299. **A data shape that arrives in two forms needs one naming seam, and a page that tests one surface lets the other surfaces rot.**

   Measured 2026-10-18, live on the checkout, during the multi destination acceptance walk. `BulkMigration`
   carries its destinations either in the run's single columns or as `bulk_migration_targets` rows, and
   three render surfaces each made their own call about which name to print. The computed the running
   heading reads handles both shapes; the card's rows printed a static `on the new source` under a row
   whose own stamp carried the name that produced it; and the earlier comparisons list read
   `$one->to_source_name ?? $one->to_source_id`, printed `MangaFire (JA) to , 0 matched` in the browser,
   read live before the walk's first multi target run settled, and left every list shaped run's entry
   naming the from side and a blank where the destinations belong. Each surface re-implemented the
   split by hand, and 2,108 green tests carried the two broken ones because the page tests asserted
   the surface that happened to be right. The naming moved onto the model as
   `BulkMigration::destinationNames()`, read once through both shapes: the item's own stamp first,
   the run's columns filling for the runs stamped before the list, the targets in pick order otherwise,
   with the same deliberate scope drop the matcher makes. Four page tests went red against the
   untouched copy: a run whose row names the destination that produced it, a row carrying no name of
   its own borrowing the run's single destination, the earlier comparisons naming both destinations
   in place of `to ,`, and the names answering after `Auth::logout()`; removing the scope drop
   afterwards killed exactly that last one on the restored tree.

300. **A reset is an enumeration, and a re-render can show a reset the model never did.**

   Measured 2026-10-18, live on the checkout, the same sitting. After every Compare press the compose
   component resets `approved`, `setAside`, `targets` and `pick`, and `limit` was never in the list:
   the stop value typed for one run rode the property into the next run's job while the Stop after
   field re-rendered showing 0. Run 11's job then received the value of the press before it, its row
   carries the polite stop sentence `This compared the first 1 series and stopped there. There are
   more, so raise the limit to compare them all.`, and the field's face said 0 throughout; the
   matching press of the walk that rode nothing ticked was refused on empty picks, so the same form
   carried one cleared field whose display was true and one uncleared field whose display said it
   had been. Only the property and the row settle it. The stop value joined the reset list under the
   comment naming the one a shorter list left behind, and a compose state test sets the limit to 1,
   compares, and asserts the property went to 0; red on the old list, green on the new one.

301. **A stylesheet built before its markup ships classes the reader cannot see, and the gates read HTML, so no gate can notice.**

   Measured 2026-10-18 on the checkout. The served asset `app-DAcTgqkC.css` was built 2026-10-10 at
   10:55, and the unread chip's markup landed on 2026-10-17, so the chip's own classes had never been
   compiled: the served file carries no `text-[10px]` and no `bg-zinc-900/80` rule, and `top-1` with
   `left-1` are absent beside present `top-2` and `start-2` because nothing in the older tree used the
   smaller pair. `position: absolute` without an offset keeps the element's static position, so the
   chip stayed in flow beneath its cover and, missing its size and background utilities, rendered as a
   bare white 16px semibold number under every landed row; the mid flight capture shows exactly that
   shape. One rebuild (`npm run build`, 11.6 seconds) moved every missing class into the served file,
   and the page then failed in the next way instead, which is trap 303. The rhythm this buys: a sitting
   that changes a class list in blade also runs the asset build and then looks at the page, because
   `assertSee` proves only what the server sent, and only a capture proves what the browser drew.

302. **A Suwayomi restart that does not pin rootDir comes up against the server's default root, and the file that tells which root is real is the H2, never the caches.**

   Measured 2026-10-18 when the scratch server restarted after the host reboot. The default root sat
   in the system's temporary directory with thumbnail caches and no H2 at all; the only real database
   was the recipe root's `database.mv.db` (9.0 MB). A default-root restart answers healthily against
   an empty library while the real state sits elsewhere: no crash, no error, a green check over an
   empty room. The restart pins rootDir exactly as the scratch recipe writes it, and continuity is
   then proved by the source lens on the answers, not by the process being up.

303. **An absolutely positioned overlay inherits both its anchoring and its paint order from whoever else in the chain is positioned, and a slot that overlays artwork has to own both.**

   Measured 2026-10-18 on the run card's unread chip, in three live states. With the atom's root
   positioned only when glow or tilt is set, nothing between the chip and the page was positioned, and
   the first and seventh rows' chips reported identical bounds (301, 912): the results card's padding
   box origin plus the 4px offsets, so every chip in the run stacked on one corner. With the root made
   relative the chips moved to their own covers' corners (341, 962 and 341, 1712, six row pitches
   apart), and the captures still sampled art colours inside the chip's box, because the frame beside
   it carries `z-index: 1` and a positioned element with auto z order paints beneath it despite coming
   later in the DOM: computed style knew exactly where the chip was and the pixels had none of it.
   Adding the wrapper the atom now owns (`absolute inset 0`, `z-index: 10`, pointer events pass
   through unless a child opts back in) flipped only the paint, and the same corner then read the
   chip's dark background against the artwork (rgb 104,85,85 over an art average of 157,111,103). A
   computed style locates an element; only a capture says whether anyone can see it.

304. **A burst of clicks on a wire checkbox reports success and carries no state, and the beat plus the outcome is the only proof it landed.**

   Measured 2026-10-18 on the migrate compose panel, whose destination checkboxes carry
   `wire:click="toggleTarget(...)"` with `@checked` derived from state, so the drawn check is
   native to the input and proves nothing about the server's array. Five toggles fired seconds
   apart with no settle beat between them, a Compare press then read `targets === []` and refused
   with the two condition sentence, and a repeated fresh mount with one toggle and a press
   dispatched on the first try, as did a later five toggle run with three seconds between
   clicks, whose DB row carried all five destinations in pick order. Every tool click returns
   clicked, no page error is logged either way, and a refused press creates no row, so the
   reads left are the toast and the next attempt. The rhythm that holds: one toggle per beat,
   the press last, and the run's own target rows as the verdict, which is the same shape as
   every other claim this file makes: the click is not the state, the state is what the row
   says.

305. **A write and its badge in one component can read different member sets without either noticing, when the page keys with one rule and the write uses another.**

   Measured 2026-10-18 on the sources page's first pin build. The page's site cards key on the
   home_url's bare host, lowercased by the page's own `hostOf()` helper, with `local` as the
   bucket for a source that publishes none; the health page, which had the pin first this sitting,
   keys its rows by the raw home_url string and shared an `ofSite()` scope built for exactly
   that. The card controller borrowed the scope: a pin pressed from a card wrote rows for the raw
   home_url's sources but the badge asked the page's card key, `hostOf(...)` can return null on a
   URL that parses nothing, and the reader saw the pin land in the toast and the database while
   the card kept its unpinned face. The first test run caught it from the render side, and the
   failure's own html is the diagnosis: one `Pin for search`, the else branch, beside a database
   that held the row, meaning the write and the badge had derived members differently. The fix
   gives the page one private derivation the write, the clear and the badge all ask through, the
   `ofSite()` docblock now says it serves the health page's raw keys only, and the rule the
   sitting buys is the DRY one phrased for keys: when a component shows members through a
   derived key, every action on that key re-derives with the same helper, or one of the two
   halves is describing a different group and the page is lying to somebody.

306. **A confirm dialog that opens on a scripted click is invisible to the snapshot machinery, a short label matches its own trigger, and a page visit can itself be a beat that writes a run.**

   Measured 2026-10-18 during the phase 4 live walk, where all three cost a beat each. First,
   the panel's scoped snapshots cannot descend into a flux modal's subtree: a selector limited to
   the dialog returns the whole document's text with an empty elements list, so the modal state
   is read with `inspect` on `dialog[open]` (and on the footer wrapper that said `Never mind Pin`
   when words were needed), and the confirm is clicked through its `ui-close` wrapper, because
   flux renders each footer button inside one and the nth wrapper is the only stable address.
   Second, clicking by visible text is a fuzzy match: the label `Pin` resolved to
   `Pin for search`, the trigger underneath the dialog, every time. Third, opening a search URL
   with `?q=` fires a run in that same mount: the unpin beat was followed by a plain visit to the
   old query, which wrote its own row (8 sources asked, all answered, at the visit's timestamp),
   and for a while the page's `Searching 8 sources` line next to result cards stamped
   `+6 sources` looked like a page lie until the rows were read: the line describes the run the
   visit itself launched in the same beat, and the `+n` list counts the sources the title was
   found on, which is why an asked-and-empty local source sits in no list. The walk's verdicts
   stay where they always are: the row of the run, not the geometry, and now also not the
   assumption that only clicked things write.

307. **A directory PHP itself created moments earlier refuses a write into it on Windows, and the same bytes placed by PowerShell land fine.**

   Measured 2026-10-18 while placing tracked licence texts into fresh directories next to the
   packages they belong to. `mkdir(..., 0777, true)` followed in the same process by
   `file_put_contents()` into that directory kept answering `No such file or directory`,
   repeatedly, for a directory whose creation the run had just reported; the same placement
   always succeeded from a PowerShell script (`New-Item -Force`, then `Copy-Item`), and writes
   into directories that had existed before the process started never failed either. The
   mechanism was never isolated and the report claims only what repeated: a fresh directory
   under a just-written parent was the one case the PHP write kept losing. The lesson is to
   place bytes beside text with the shell when the directory is new, and to read the file back
   and hash it afterwards, which is how the nine placed texts were verified rather than assumed.

308. **A PharData archive iterates one level deep: `foreach ($tar as $entry)` yields only the top directory, and `getFilename()` is only the leaf.**

   Measured 2026-10-18 on the registry tarballs, where every package ships a single `package/`
   directory. The plain `foreach` returned exactly one entry, as silent as an empty archive,
   and the members are visible through `new RecursiveIteratorIterator($tar,
   RecursiveIteratorIterator::CHILD_FIRST)`. Inside that iterator `getFilename()` returns only
   the leaf, so every `LICENSE` looks like the package root's, and the member's real path is
   read off `getPathname()` with the archive's own name and separator cut off the front. A
   listing that looks like one file names one file: check the count against expectation before
   deciding what an archive does not contain.

309. **`gzfile()` is a text-shaped tool: it splits compressed archive bytes at newlines and the re-joined bytes are damaged.**

   Measured 2026-10-18 on the same tarballs: an `implode('', gzfile($tgz))` followed by a
   checksum readback produced a mismatch exactly as a corrupted archive would, and
   `file_get_contents('compress.zlib://'.$tgz)` was byte exact against the same file. Gzip
   wrappers that promise lines are for logs and not for anything that has to survive a hash;
   read archived bytes through the stream wrapper and prove it by the checksum, the way the
   nine staged texts were proved before they were placed.

310. **A readback probe that guesses the base of an artifact's relative path reports everything as missing.**

   Measured 2026-10-18 the same sitting, on this house's own oldest finding family. The probe
   joined each manifest row's `file` value onto the install root and printed 145 promised
   files missing; corrected against what the consumer actually does, every one existed. The
   manifest's `file` values are relative to the `licences` directory, and the page's read seam
   resolves them there (`$this->root().'/licences/'.$file`), so the base a checker uses has to
   be taken from the consumer's resolution code, not from the directory the checker happens to
   stand in. One read of the resolution line turned all missing into none missing. What makes
   this worth its number is what the grade has bought so far: checkers in this ledger have now
   reported missing files as present, a corrupted file as fine, and here present files as
   missing, all with confident output, so a checker's belief and the artifact's are compared
   every time by construction, not by mood.

311. **A must-not-ship list carries the directories development has produced so far, and the next feature produces the next one.**

   Measured 2026-10-18 on the phase's first real build: the strip list cleared
   `database/backups` by name, and the final scan refused over
   `database/reader-snapshots/reader-half-20261018-031700.sqlite`, the checkout-side snapshot
   directory the reader-half work writes into (`SnapshotDatabaseTest` names it as its
   expectation). It had never shipped because the builds before it caught it, which is the
   list doing its work, and the remedy was the list growing an entry and the build printing
   `removed: database/reader-snapshots`, not removing anything from the checkout: the file is
   the developer's data, already ignored by `database/.gitignore`'s `*.sqlite*` line. The
   general rule for adding a feature that writes data into the checkout beside the code: a
   strip-list entry joins that feature in the same commit, because a list of directories known
   at build time is a list of directories built once before today.

312. **A teardown cleanup that cannot fail is a leak: unlink() refuses a populated directory, and the suppression turned that refusal into silence.**

   Measured 2026-10-18 while sweeping the temp directory at close out. `TestCase`'s scratch
   seam registered every path it created and removed them in `tearDown()` with
   `@unlink($path)`, which reads as a sweep and is not one: unlink() cannot remove a
   directory that still holds files, every fixture directory here ends the test holding
   files by design, and the `@` suppressed exactly the refusal that mattered. Ten fixture
   directories per full suite run survived; fifty measured on one day at five pids, after
   three suites and a pair of focused runs, in the reader's own temp directory, which is
   the family that grew to 82,457 files before it was first found in 2026-10. The seam now
   walks the tree with `RecursiveIteratorIterator` and `CHILD_FIRST`, unsets the iterator
   before asking the base directory to leave (measured the same day: an un-closed walker
   holds a directory handle and `rmdir()` refuses a handled directory on Windows, so the
   base was being blamed for a cleanup that had actually finished), and then checks what it
   did: anything still on disk is named in the exception, verified by the two seam tests
   which were killed by reverting the walk and by removing the survivor check, each to the
   right failure. Two Windows specifics worth their ink: a read-only attribute does not
   stop unlink(), which deleted it without complaint, and an open file handle does.

313. **A date built from a date-only format is not midnight: it carries the clock's own time of day, with the microseconds, and a refusal built on it refuses nearly every day of the year.**

   Measured 2026-10-19 on the insights page's clamp. The bound came in as a URL parameter, the
   URL wiring worked from the beginning (the attribute hook had already delivered the value when
   mount ran, verified by instrumenting mount itself), and the value still wiped to the whole
   ledger every time. The wipe was the page's own future check: `createFromFormat('Y-m-d', ...)`
   fills the fields the format does not name with the current clock time, so parsing at 07:41
   produced a moment well after `today()` at midnight, `isAfter()` was true, and every valid day
   was refused as future. The clamp to start the parsed moment at its own midnight is the whole
   repair: a day is compared against a day. The lesson deeper than Carbon is the one the page's
   first tests were wearing: two clamp inputs (a malformed string and a future date) passed both
   before and after the fix, because refusal and wipe render the same whole ledger, so an
   arrange where both branches produce the same answer proves nothing. The numbers that mattered
   were the ones a bound window genuinely counts, and the accidental passes were rewritten to
   those before any of this was believed.

314. **A checker's verdict has two families, failure and error, and a reporter that reads one reads half the truth.**

   Measured 2026-10-19 through the mutation run of this phase. Killing a test silently is not
   the only red a mutant can produce: removing the controller's `openFor()` call produced no
   row, and the test died with `No query results for model`, which the runner reports as an
   error, not a failure. The session's own reporter read the failures key alone, got an empty
   count twice, and reported two mutants as survived, one of which was then worked around by
   tests that did not need to exist. The contradiction (an instance dump of a property that
   still held the default, against a suite that swore the row was there) forced the re-read,
   and the queries settle it in one line: no INSERT exists, the count is zero, so sole() on an
   empty query throws. Before concluding a mutant survives, read the whole verdict shape it can
   produce, which is the same family as trap 266 turned up here as a reporter, not a gate.

315. **Reaching for a line-surgery shell when the edit tool is one tab away buys a BOM.**

   Measured 2026-10-19, same sitting as trap 313's hunt. A regex replace across a PHP file via
   `Set-Content -Encoding utf8` both missed the pattern and prepended three bytes; the next
   `php -l` refused the file with `Namespace declaration statement has to be the very first
   statement`, and the byte readback read `239 187 191`. The file was not tracked yet this
   time, so the fix was a byte slice and not `git checkout --`, which is the recovery of the
   older trap and does not exist for an untracked file. The file tools have never added a byte
   they did not mean to, and every Perl-and-regex-shaped shortcut of this sitting (the probe
   suites included) cost more than the edit it was avoiding.

316. **A click whose subject is below the fold reports ok and changes nothing, and a snapshot
     handed a stale tab reports a page nobody asked about.**

    Measured 2026-10-19 through the whole insights walk on `papyrus.test`. Buttons out of the
    viewport's bottom edge repaid every press with `ok: true` and no state change: the Available
    tab's link on `/sources`, an extension's Install, and probably every silent press of the
    sitting that came before. The same button pressed for real once it was in the viewport,
    which is the geometry under trap 304's burst clicks: the click tool does not scroll its
    subject into view, so a press past the fold lands nowhere. The rhythm that holds is one
    press per beat with a scroll (or a list filter) whenever the element is not already visible,
    because a call that reports ok is never evidence it did the thing.

    The second mechanics is the panel's own tab memory. After the scratch server died mid
    reader and was booted again, every snapshot answered with the outage's error page against
    tabs the panel believed current, while the tab the `browser.open` call had actually made
    held a healthy reader. A snapshot says what it saw and not which tab it saw; passing the
    `tabId` from the open call into every following action is the one habit that removes the
    whole class, and not trusting any page read that arrives without its own tab.

    Third, the source pages: `/browse/{source}` binds the ledger's row id, not Suwayomi's source
    id. A freshly installed extension's Suwayomi id (`6084907896154116083`) 404s until
    `sources:sync` mirrors the server's installed sources, and then it is the ledger row id for
    that `Source` row that answers (MangaFire (EN) mirrored at row `157`). The Suwayomi id is
    the probe's Api key; the ledger row is the web address, and the two are never the same
    number anywhere in this app.

317. **An access audit lists every element it read, and a fix list built from its rows alone repairs what is not broken while the real holes ride beneath it.**

    Measured 2026-10-19 on the library and browse sweeps. The panel's audit reported thirteen
    cover links with no accessible name; reading the markup first showed both grids' cover
    anchors carrying `aria-hidden="true"` and `tabindex="-1"` on purpose, the name sitting one
    link further down at the title, so most of the reported rows were anchors the tree already
    excludes and the genuine gaps were hiding among them: the continue rail's card link, whose
    name really was missing, three placeholder-only inputs, and a Check button whose aria
    label interpolated an empty title into "Check  for new chapters now" with a double space.
    The whole repair was six lines. The lesson both ways: an audit row is a starting place for
    reading the markup, not a defect list, because a batch ordered by audit count spends its
    budget on anchors whose tree membership is already empty; and the audit's own rows were the
    only reason the real holes were found at all, since they sat inside the same list of
    suspects.

318. **A drawn line is not a rule: ask the computed style and the glyph before filing a paint defect.**

    Measured 2026-10-19 chasing an underline that showed under the new FAQ page title in every
    capture. The stylesheet's `.display-page` sets no decoration, and a probe through headless
    Chromium returned `textDecorationLine: none` while placing the H1 itself at the stroke's
    coordinates. It is the typeface: Bricolage Grotesque's capital Q ends in a flat horizontal
    tail, which under a three letter word reads exactly like an underline. The same glyph has
    drawn that tail on every display heading this app ever shipped, so it is the app's own
    typography and not a defect any sitting introduced. The same capture pass suggested a
    second ghost, a footer not sitting at the bottom of its shell: measured, the body's paint
    ends at the footer plus the shell's own padding, and the document then scrolls 192px
    further for the auth ambient glow's `-bottom-48`, on every auth shell since it was
    written. Two non defects, one principle: pixel complaints get measured at the element and
    the font before they become work orders, because an eyeball read of a capture is how a
    sitting burns its budget repainting the app's own letterforms.

319. **A face this seat cannot reach is not a face nobody can reach: headless Chromium prefers light.**

    Measured 2026-10-19. The Appearance picker renders zero interactive elements from the
    browser panel, so the light theme sat numerically verified and not pixel drivable for a
    sitting's worth of contrast findings. A Playwright probe against the same scratch server
    told a different story on the first run: the headless default is
    `prefers-color-scheme: light`, so the FAQ page's light face rendered complete, wash, cards,
    amber callout and footer included, with no setting touched. The seat limitation stays true
    and was recorded honestly when found; the boundary of that limitation was wider than the
    tool that named it, which is the general shape: every not-drivable verdict about a scheme
    or a viewport carries the exact one script cost of probing an independent browser before
    the word boundary is written down as an arrival.

320. **A scratch `php -S` without its docroot serves a working app that eats its own assets.**

    Measured 2026-10-19 restarting the scratch server. `php -S 127.0.0.1:8017 <router>` without
    `-t public` points the built-in server's document root at the repository root, so every
    asset path like `/build/assets/app-*.css` reaches the router as an application route, the
    app answers rather than refusing, and the page then renders unstyled with every asset a
    404: an app that works on every route and shows the reader nothing, which is the F26
    family of green checks over a blank room. The restart command belongs in full in the one
    place a next sitting will look for it, and its first probe is an asset path, not a route:
    `php -S 127.0.0.1:8017 -t public "<Temp opencode>\papyrus-router.php"`, where `<Temp opencode>`
    is this machine's own on-disk scratch root, which is deliberately not named here.

321. **A row whose shape is a race is a row the reader reads three different ways, and the capture is what retires the theoretically tidy fix.**

    Measured 2026-10-19 reshaping the licences ledger. The rows were `flex flex-wrap
    justify-between` under the name span, so the duty cluster shared the name's line only while
    one lock file word happened to be short enough: the ledger column is the settings slot's
    fixed 512 pixels at every window width (`max-w-lg` on `pages/settings/layout.blade.php`), the
    duty cluster needs roughly 360 to 400 of them, and 102 of the 139 PHP rows plus 152 of the
    307 row section lost the race and pushed their buttons onto a third line. Widening the
    window changes nothing, because the column does not move; the flip is decided by the package
    name alone. The first repair forced `sm:flex-nowrap` with `shrink-0` kept on the cluster,
    and the before/after capture killed it before the owner saw it: the name cell collapsed to
    about 110 pixels, every mid-length identifier broke over two lines, and the two nette rows
    whose licence names three clauses turned their names into a 606 pixel letter column. The
    landed shape gives each ingredient its own seat on every row, with no responsive branch: the
    name line carries the licence at the far right, the provenance line is the second, the duty
    line is the third, and `break-all` on the name means no lock file word can ever outgrow its
    cell again. Two lessons, both bought here: a layout repair is not verified from the class
    list, it is verified from the pixels and the element boxes on both sides; and when two
    children of a wrap line fight over a fixed width, the durable fix is to give the contested
    thing its own line for every row, not to make the loser lose more gracefully.

322. **A press that looks unchanged is read through three lenses, and only the fresh page load answers.**

    Measured 2026-10-19 walking the reshaped licences page. A wire press on `I have read this
    licence` was followed by an element reference read straight after the morph, which answered
    with the pre press text: the locator chain re-resolved through a node Livewire had replaced,
    so the read described a page nobody could see. A fresh page load showed `Acknowledged` in the
    same row. The second lens failed the other way: a scratch read through the model answered
    zero acknowledgement rows while the row on screen claimed a state, and the reason is the same
    fail closed scope trap 298 died over: without an authenticated reader, `OwnedModel`'s lens
    hides the rows instead of throwing, so a CLI read through Eloquent is the wrong tool for
    "does the row exist" and a silent zero is the shape it returns. The raw table read gave the
    row, its user id, its package key and its stamp. Both halves are the one lesson: after a
    state press the verdict is a fresh load of the page, and when the page and a probe disagree,
    the probe's lens is suspect before the feature is.

323. **A version bump is a grep that finds literals and a test suite that finds gates, and the grep alone is never done.**

    Measured 2026-10-19 on 1.2.0 becoming 1.3.0, the first bump of this file since the installer
    landed. The greps found every shipping doc that names a version: the changelog's release
    section, the install doc's download line, the parked gate's installer premise, and the
    builder's own manifest, and the edit fixed each. The suite still went red six deep, all in
    `PortableBuildTest`: the fixtures stamp their installs `1.2.0` in the very interest of the
    build's own deliberate cross check, the tree now says `1.3.0`, and `build-portable.php`
    refused all six with the version sentence instead of naming the `.env`, the database or the
    manifest each test stages. Two lessons, both paid for. First, a bump's blast radius is not
    greppable, because part of it lives behind a constant a test reads at runtime; these fixtures
    were written with their own `version.txt` so that the stamp check would pass, and the check's
    premise is exactly what one changed number beat. The fixture now stamps `stampVersion()`, read
    from the same `VERSION` file the build checks, and the mismatch test keeps its own `0.9.0`
    because it tests the mismatch on purpose. Second, a refusal fired early masks the refusal that
    is being tested, so six red lines quoted a version disagreement that no failing test meant:
    read a red suite for what the message is, not only what it says. Also worth keeping from the
    red run: its summary counted 5,889 assertions against a green baseline of 5,905, because a
    failing test stops counting its own asserts, so a red run's counts do not compare to a green
    baseline at all.

324. **An empty PHP array is a JSON list, and a GraphQL input object is not a list.**

    Measured 2026-10-21 against a live Suwayomi v2.4.2366 on both seats.
    `BackupGateway::createBackup()` sent `['input' => ['flags' => $flags]]` with its own
    default `$flags = []`, and an empty PHP array json-encodes as `[]`, a JSON list, which
    the server refuses before the resolver ever runs:

    ```
    Variable 'input' has an invalid value: Expected type 'Map' but was 'ArrayList'.
    Variables for input objects must be an instance of type 'Map'.
    ```

    The same call with `flags` as an empty **object** (`{}`), with no `flags` key at all,
    and with every flag a boolean all answer with a url. So the in-app backup's manga
    half and the in-app outward export, both of which call `createBackup()` with no
    flags, could not ask a real server for anything, and every one of them would have
    been refused by any real server, every time. The unit suites did not see it because
    `BackupGatewayTest`'s fake answers `mutation CreateBackup` without ever reading the
    variables. The fix is a cast, `(object) $flags`, and the test that keeps it honest
    reads the recorded request's body and refuses a `"flags":[]` on it. The general
    shape: **a fake that answers a request without reading it is a fake that cannot see
    a malformed request**, and the wire shape of a GraphQL variable is part of the
    request.

325. **A server whose library holds nothing writes a real backup, and a reader must be
    told that rather than told their server is broken.**

    Measured 2026-10-21 against a live v2.4.2366 whose library was empty: its own
    fresh export is 352 bytes, gzip magic `1f8b0800`, and its payload holds exactly one
    category record (field 2, name `Default`, no order) and one Suwayomi-only field
    9001, and **zero** series records. `TachibkReader::recognises()` refuses that file,
    and it is right to: its gate is narrow on purpose, so a CBZ is never read as a
    backup. But the refusal's words ("not a Mihon or Suwayomi backup") are true of the
    bytes and wrong about the cause, because the server answered with a genuine backup
    of an empty library. The app turned that into a `SuwayomiException` saying the
    download "does not read as a Mihon or Suwayomi backup", which told a reader their
    manga server was broken when it was answering fine. `TachibkFile` now reads the
    file's own top level fields so the refusal can name which of the two it is, and the
    file is not read as a library at all. The general shape: **a refusal that is true of
    the bytes and wrong about the cause teaches the reader the wrong thing**, which is
    the same lesson F26 filed for a fresh install that could not read a source.

326. **A `.tachibk` carries the server's library and nothing else, and the reader's own
    arrangement can only ride on what the file names.**

    Measured 2026-10-21 against a live v2.4.2366 holding 2,834 manga rows of which 5
    were in library: the server's own fresh export carried exactly those **5** series
    records and no others. The 2,829 rows it held were the source's browse cache, and a
    backup is a backup of the library. So an export whose basis is the server's file can
    carry a shelf order only for a series the file already names, and a series the
    reader has state for but the server does not hold in its library has no record for
    that order to attach to. This is the same design the reader half already lives
    with: `ReadingState`'s own docblock says the server is single-tenant, so every
    authenticated request resolves to `Admin(1)` and its "library" belongs to whoever
    logged in last, and the app therefore owns library membership in its own
    `series_states` table and never writes the server's library flag. **The consequence
    is that the arrangement travels only over the series the server's library holds,
    and a round trip that measures the arrangement has to measure it over that set.**

327. **A series' shelf order is field 17, a shelf record's order is field 2, and a
    duplicate order discards a shelf rather than merging two.**

    Measured 2026-10-21, first by reading the pinned jar's own writer
    (`Suwayomi-Server-v2.4.2366.jar`, whose `suwayomi.tachidesk.manga.impl.backup.proto.models`
    serializers hold each field's number in their constant pool, read with `javap -v`),
    and then by importing files into a live v2.4.2366 and reading the receiving
    reader's own pills back. The jar's own numbers: `Backup.backupManga` 1,
    `backupCategories` 2, `backupSources` 101; `BackupManga.source` 1, `url` 2, `title` 3,
    `chapters` 16, **`categories` 17**, `history` 104, `lastModifiedAt` 106;
    `BackupCategory.name` 1, **`order` 2**, `flags` 100, `uid` 602; `BackupChapter.url` 1,
    `name` 2, `scanlator` 3, **`read` 4**, **`bookmark` 5**, `chapterNumber` 9,
    `sourceOrder` 10. **All twenty-four of the repository's own `TachibkSchema`
    constants match the jar's own numbers digit for digit, and zero differ.** The
    measured behaviour on the receiving side: a file whose shelf records carry orders 10
    and 20 and whose series carry the order 10 put **all five** of its series on the
    shelf whose record carried 10; **two shelf records carrying the SAME order leave the
    earlier one empty and give the members to the later one**, so the orders must be
    unique across the file; a series pointing at an order no shelf record carries is
    **dropped**, which is the only forward rule a format can have; and a shelf record
    with **no order field at all** is the order 0 shelf, which a series pointing at 0
    lands on. A chapter's read flag round trips too: a file with one chapter marked read
    under field 4 produced a read chapter on the receiving reader. The general shape:
    **the format's paperwork is in the writer's own jar, and reading it settles the
    questions a sample alone cannot**, because the sample can show a field's bytes but
    not what the writer meant by them.

328. **A pointer at a scratch copy is not a pointer at the live database, and the
    ledger's own history is the only witness to what it held.**

    Measured 2026-10-21, and paid for. A probe pointed the app at a scratch copy of the
    checkout's own `database/database.sqlite` with `config()` and `DB::purge()`, and
    ran the real `.tmb` import against it. The import's own bookkeeping (the run row,
    the ledger's stamps, the shelf landing) went to the scratch copy, correctly. The
    reader half's rows went to the **checkout's own file**, because `SeriesWriter` writes
    through Laravel's own connection and the connection pool had an open handle on it:
    521 extra series states and 12,718 extra chapter states, and an import run left
    `running` at 900 of 1,277. Two repairs were then needed and the second one is the
    lesson. The first deleted the import run, and the ledger's `imported_series.import_run_id`
    carries **`on delete CASCADE`**, so the **920** ledger rows that pointed at it went
    with it and the ledger fell from 1,277 rows to 357. The second put those rows back
    and then could not tell which of the restored server ids the import had added and
    which the standing ledger had earned, **because the import had overwritten the
    ledger's own record of what those rows carried before it ran**. The repair that
    worked used a witness that predates the damage: the pair this phase's own backup
    had taken twenty minutes earlier, whose reader half was measured to carry all
    fifteen standing numbers. The general shape: **a destructive flow against a live
    database needs the database's prior state kept, and the way to keep it is the
    repository's own backup**, because the file's own history is overwritten by the very
    thing the restore is undoing. And a cascading foreign key is a delete that takes
    more than it names: `on delete CASCADE` on a run row takes the ledger with it.

329. **A path fragment is not an element, and a new route that contains the old one
    turns the old check into a check that cannot fail.**

    Measured 2026-10-21, while giving the FAQ an in-shell seat. The Settings navlist's
    FAQ item pointed at the public route, and the fix pointed it at the in-shell one
    instead. **The string `/faq` is a substring of `/settings/faq`**, measured with
    PHP's own `str_contains` on the two paths, so a test that asserted the page
    carried the old path as a fragment would have gone on passing against the new href
    without ever checking that the navlist had moved at all. The general form: **a
    path is not an element, and a check on a fragment is a check on a string rather
    than on a thing.** The assertion that holds it is on the whole `href`, and the one
    that catches a nav item still pointing out of the app is the negative one, the
    public route's href absent from the page.

    The neighbouring truth is worth saying, because it is what the old check did
    rather than what a fragment check would have done: `route()` returns an absolute
    URL, and `http://papyrus.test/faq` is **not** a substring of
    `http://papyrus.test/settings/faq`, both measured. So the old assertion would
    have failed loudly rather than quietly. **A check that fails loudly is a check; a
    check that passes for the wrong reason is a comment.**

330. **A Blade directive that does not exist reaches the browser as text, and the
    page's own guard only looks inside a component's attributes.**

    Measured 2026-10-21, while giving the sources page's family groups a memory.
    The markup wanted a conditional `open` attribute, and the obvious spelling
    `@open(...)` is **not** a directive: this Laravel carries `@checked`,
    `@selected`, `@disabled`, `@required` and `@readonly`, and no `@open`. Blade
    does not refuse an unknown directive, it leaves the text where it was, so the
    page rendered `<details class="group" @open(in_array(...)) x-on:toggle="...">`
    and the browser was handed a broken tag.

    Two things make this worth writing down. First, **the page still rendered**:
    no exception, no failed test, no console error, just an attribute that is
    not an attribute. Second, **the repository already had a guard for this
    family** and it did not catch it: `test_no_directive_is_left_uncompiled_in_an_attribute`
    looks for `@something(` inside a `wire:` or `x-` attribute, and this one sat
    between two attributes on a plain element. The general form: **a guard that
    looks in one place is a guard about that place**, and a directive that does
    not exist is not a syntax error, it is a silent string. The fix is the
    ordinary conditional attribute, and the shape that holds it is a regular
    expression that cannot be satisfied by the word "open" appearing inside
    another attribute's value.

331. **An `sr-only` label has no `innerText`, so a check that reads the rendered
    text reads a named control as an unnamed one.**

    Measured 2026-10-21, while auditing every input on every page for an
    accessible name. The rule the audit used was the house's own: a control is
    named when it has an `aria-label`, an `aria-labelledby` that resolves, a
    label element that points at it, or a wrapping label. The series page's tag
    input has a `<label for="series-tags" class="sr-only">Add a tag</label>`, so
    `input.labels` holds one entry and its `textContent` is "Add a tag", and the
    input **is** named. The audit read that label's `innerText`, which is empty
    for a visually hidden element, and reported the input as unnamed. Measured
    directly afterwards: `labels.length` 1, `innerText` empty, `textContent`
    "Add a tag", and the accessible name computed from the label "Add a tag".

    The general form: **a name is not a rendering, and a check that reads the
    rendered text asks the wrong question about a control that is deliberately
    not rendered.** `textContent` is the text; `innerText` is the text a reader
    would see, and the second is empty for exactly the elements a screen reader
    is the only reader of. A label that exists only for a screen reader is the
    case where the two differ most, which is why the difference matters here.

    The neighbour is worth naming because it is the same mistake with the
    opposite sign: **Flux's own `label` prop renders a `<ui-label>`, not a
    `<label>`**, so the server-rendered markup carries no `<label>` element and
    the input carries no `id`. In the browser the component's own script wires
    the caption to the input with `aria-labelledby`, and the name is the visible
    caption, measured: the search page's query input is named "Search" in the
    browser, not "Series title" as its placeholder says. A first reading of the
    server-rendered markup said the caption was not associated at all, and that
    reading was wrong, because it was made against the markup before the
    component's script had run. **Measure the thing a reader gets, not the thing
    the server wrote.**

332. **A URL parameter merges an array over the declared one BY KEY, so a link
    can replace a default rather than widen it.**

    Measured 2026-10-21, on the sources page, while building the adult content
    gate. The page's rating filter is `#[Url(as: 'content', except: '')] public
    array $content = [SAFE, MIXED]`, and the catalogue is filtered by it. The
    gate's whole claim was that a link could not reach the adult rated
    extensions, and the default was right: the Available tab read **1,055
    extensions** with **0** of its fifty cards carrying an NSFW badge.

    **The address bar reached them anyway.** With `?content[]=NSFW` in the
    address the count line read **874** and **41** of the fifty cards carried
    an NSFW badge, and the page's own helper line went on claiming they were
    hidden. The number is the catalogue's own: **387 NSFW** plus **487 MIXED** is
    **874**.

    The mechanism is Livewire's own, read out of the framework rather than
    guessed: `BaseUrl::setPropertyFromQueryString()` returns early when the
    parameter is **absent**, so the declared default stands, and when the
    parameter is **present** it merges the decoded array over the current one
    **by key**. So `content[0]=NSFW` lands on key 0 and **replaces** SAFE
    rather than joining it, and the page's filter became `[NSFW, MIXED]`.

    Two things follow, and the second is the one that bit. **A query parameter
    on an array property is not additive, so a link can narrow a page's scope as
    well as widen it**, and a default that looks like a floor is not one. And
    **the merge is silent**: nothing in the page's own code changed, nothing
    threw, and the page rendered a list the reader never asked for and the
    application never agreed to show.

    The general form: **an address bar is a second caller, and it arrives
    without asking.** A control that decides what a reader sees has to be
    decided by the application and not by a parameter, which is why the gate
    ended up a stored per reader setting and the rating filter ended up
    narrowed by it in the query rather than by the address.

    The neighbour is worth naming because it is the same attribute and the
    opposite behaviour, and the code in the tree has to answer both: the
    sources page's own comment describes a Livewire that hydrated an **absent**
    array as empty, and the code in `mount()` defends against it. **The current
    framework does not need that defence**, and the code is right to keep it
    anyway, because a defence against a framework's older behaviour is cheap
    and a page that changes its filter when a reader opens a link is not.

333. **A trait whose only users live in a Blade file is code the analyser never
    sees.**

    Measured 2026-10-21, on the same sitting, and the analyser said it out
    loud rather than letting it pass. The adult content gate is shared by the
    two pages that filter by the catalogue's rating, and both of them are
    anonymous classes inside `.blade.php` files, because that is how every page
    in this application is written. A trait looked like the natural home for
    the shared behaviour, exactly as `App\Concerns\PasswordValidationRules` is
    the home for the shared validation rules.

    **PHPStan reported it as "Trait App\Concerns\AdultContentGate is used zero
    times and is not analysed."** It does not read Blade, so a trait used only
    there has no class in the analysed tree that uses it, and its body is never
    type checked at all. Every rule in it would have been a rule nobody checked,
    and the trait's own use of `$this->content` would have gone unexamined,
    which is the part that would have bitten.

    The fix was the shape the analyser can see: a **base class**,
    `App\Livewire\AdultContentPage`, which is a file under `app/` and is read.
    It carries the shared property with its `#[Url]` attribute too, because an
    attribute on an inherited property is still found: Livewire's own
    `AttributeCollection::fromComponent()` reads the class's properties with
    `ReflectionObject::getProperties()`, inherited ones included.

    The general form: **a shared behaviour belongs where the tool that checks
    it can see it.** The question to ask of any new seam is not "where do the
    users live" but "where does the analyser read from", and the answer for a
    project whose pages are anonymous Blade classes is a class in `app/`, not a
    trait. The same question applies to a base class, an interface and a
    service: all three are visible to the analyser, and a trait whose users are
    all in a file the analyser does not parse is not one of them.

334. **An elapsed timer is a reader sentence, not the clock's raw measurement.**

    Measured 2026-10-23 in the Backups modal. Carbon's `diffInSeconds()` returned
    `34.34455`, and the template printed it as `34.34455s`. That number describes
    the instant a three-second Livewire poll happened to render, not a useful fact
    about the backup. A reader needs `34 seconds elapsed`.

    The page had two modal paths, one for the launcher's record and one for the
    live row, so wording the live path alone would have let the same defect return
    through the other door. `elapsedTime()` is the one formatter for both: it floors
    a non-negative duration to whole seconds and carries the singular and plural
    words. `BackupsPageTest` travels 34 seconds and reads `34 seconds elapsed` from
    the rendered live modal. A time test that only calls the helper would leave the
    rendered sentence unproved.
---

## 15. Phase 7 run notes: the backup that never stops anything, and the round trip

**Which Phase 7 this is.** Three numbering systems are live in this repository and they do not
agree, so the first thing this section has to say is which one it is. This is the **master
plan's Phase 7**, the in-app backup and restore, which is Phase 7 of the umbrella plan
("Migration parity, source pinning, licences, insights, truthful backup, install alerts"). It is
**not** the UI/UX pass whose commit was labelled "Phase 7: the UI/UX pass" (`59fb26e`), which was
its own separately numbered sitting and closed on 2026-10-19. And it is **not** the feature phase
7 in section 11's table, which is this app's own phase numbering and whose last row is the design
overhaul. The work sat under a plan of its own, "Master plan Phase 7: in-app backup/restore that
never stops the services, and the Mihon-format round trip", with eight steps. Steps 0 to 6 are
built, ticked and committed locally; step 7 is the paperwork this section belongs to, and its
three pieces are this run notes, the findings register's own line for F5, and the release notes
line that sits beside Phase 8's.

**What the phase was for.** The owner's hard requirement, quoted in the umbrella plan: backup and
restore in-app, **without stopping Papyrus or Suwayomi**. The app said the opposite. The backups
page carried an amber banner that told the reader to open a terminal, and the page's own comment
gave the reason: the manga half has to be copied with nothing writing it, and proving that means
Suwayomi has to be stopped, which means the application has to be stopped, which means the request
that asked for the backup is gone. The route file repeated the refusal, and the page's own empty
state, rendered through `RunOutcome::read("")`, answered "Papyrus has to be stopped to be backed up",
which is true of the launcher's own snapshot and was the app's answer for both. So the app had a
page that could only list backups another tool had made, and no way to make one.

**What it built.** Five pieces, in the order they landed.

- **The import lands the reader's arrangement** (commit `58919fe`). A `.tmb` file's own shelves now
  land in Papyrus, named, with a per item report: the run row carries `categories_in_file`,
  `category_members_placed` and `category_members_skipped`, and every skipped member carries its
  reason. The shelves are the app's own (`categories` and `category_series`), not a second table
  holding the same placement, because the owner chose to reuse what the library page already
  drives.
- **The app takes its own pair** (`58919fe`). The reader half is captured through SQLite's own
  `VACUUM INTO`, which is the one way to get a consistent copy of a database that is being written.
  The manga half is the server's own fresh export. Both halves are verified before the manifest is
  named: the reader half's `PRAGMA integrity_check`, the manifest's two digests against the files on
  disk, and the row counts against the live database's. Then the two files and the manifest are
  renamed into place together. The page lists the pair with its size, and the run row carries the
  progress.
- **The app puts its own pair back** (`f9ce674`). The reader half is swapped under one
  `BEGIN IMMEDIATE`, which is refused rather than forced when a write transaction holds the
  database, and the manga half rides Suwayomi's own import route with the server's own progress
  read by the modal's bar. The confirm modal reads nothing but the pair's own manifest and files,
  so the numbers in it are the numbers in the pair.
- **The app sends its own arrangement out** (`3c9a4c5`). One `.tachibk` carrying the server's fresh
  library with Papyrus's read marks and shelf memberships merged in, in the format Mihon and its
  forks read. The merge only ever adds: a chapter the reader has read and the file does not carry
  gains a read flag, a series filed on a shelf gains that shelf's order, and nothing is ever
  un-read or un-filed. The page names the per item counts and offers the download.
- **The page stopped telling the reader to open a terminal** (`41f34f1`). The amber banner is dead,
  the route comment leads with what the page does instead of a refusal, and the launcher's own last
  run and the three live run tables now read in one place, so a reader sees one "last time" rather
  than two surfaces that disagree. The populated page was rendered in a browser, four captures on
  disk, which is what closed the F5 finding.

**The acceptance runs, and what they measured.** Four beats, and the seat each was measured on is
named, because the seat is part of the claim.

1. **The backup, taken while the queue churns.** The churn is the standing one:
   `routes/console.php` runs `SyncDownloads` on `everyTenSeconds` and the scheduler's own log
   carries it every ten seconds. The backup was taken through the app's own job, so the run row is
   the job's own: `live_backup_runs` id 1, `complete`, **884 series states** and **21,456 chapter
   states**, against Suwayomi v2.4.2366. The pair verified every way the plan names: the
   manifest's two digests matched the files on disk, the reader half's `PRAGMA integrity_check` read
   `ok`, and the staged file's row counts equalled the live database's.
2. **The restore, round-tripping the row counts.** Measured on a **scratch copy** of the checkout's
   own database, never on the checkout's own file, with the manga half going to the throwaway. The
   copy was put into a different state from the pair on purpose, because a round trip that starts
   and ends on the same numbers proves nothing. After the swap **four tables moved and came back**
   (`series_states` 883 to 884, `chapter_states` 21,454 to 21,456, `imported_series` 945 to 1,277,
   `reading_sessions` 0 to 7) and the other forty of the forty-four were unmoved. The throwaway's
   own log reads `restore(legacy): finished with state SUCCESS`.
3. **The export, imported into the throwaway.** The product was POSTed raw as `application/x-gzip`
   to the throwaway's own `/api/v1/backup/import` and answered **HTTP 200 in 497 ms** with
   `{"missingSources":[],"missingTrackers":[],"mangasMissingSources":[]}`. The file carried **35
   series records, 10 shelf records and 5,753 chapters of which 370 read**, and the library the
   throwaway ended up with was read back through its own GraphQL: the pills read **Snowflakes 13**
   and **Youtube 17**, and the read flags came back per series.
4. **The `.tmb` round trip, from the owner's own screenshot.** The real 41,272,047 byte file was
   imported for the first time, and the two named shelves landed: the receipt reads
   `categories_in_file` **2**, `category_members_placed` **102**, `category_members_skipped` **8**
   with their reasons, and the copy's own tables read `Youtube` with 18 members and `Snowflakes`
   with 84. **22,967 read chapters applied.** The export that followed carried the arrangement, and
   the receiving reader was read back **after its library was emptied first**, so the only thing
   that could have put the members on the shelves is the file.

**Two real defects the acceptance found.** Both were found by measuring against the live servers
rather than by reading the code, and both would have made the acceptance measure the breakage
instead of the feature.

- The `createBackup` mutation's empty flags travelled as a JSON list, which **every real server
  refuses before the resolver runs**: `Variable 'input' has an invalid value: Expected type 'Map'
  but was 'ArrayList'.` So the in-app backup's manga half and the outward export could not ask a
  real server for anything at all, ever. The unit suites had not seen it because the fake answered
  the mutation without ever reading the variables. Trap 324.
- A server whose library holds nothing writes a real backup with no series record in it (352 bytes,
  one category record, zero series records), and the app was calling that a server outage when the
  server was answering fine. It is now refused in words that name the cause, which is the F26
  shape: a refusal that is true of the bytes and wrong about the cause teaches the reader the
  wrong thing. Trap 325.

**The honest limits.** Three were measured; the third was a copy defect rather than a limit of
the feature, and it is corrected below.

- **The export's basis is the server's library.** A `.tachibk` carries the server's library and
  nothing else, and Papyrus owns library membership in its own table and never writes the server's
  library flag, so the arrangement travels only over the series the server's library holds. On the
  throwaway this phase measured, the server held 2,834 manga rows of which 5 were in library (the
  other 2,829 were the source's browse cache), and the file carried exactly those 5. Trap 326.
- **This box cannot name the owner's shelves.** The `.tmb`'s two shelves sit on **18 distinct
  sources**, both servers here hold exactly **one** extension (MangaFire), and the two shelves hold
  **zero** MangaFire members, so **30 of the file's 110 shelf members** are the ones a server on
  this box can name and **80** are on sources no server here holds a source for. The import
  installed the **14** non-obsolete packages the shelves need with **0 refused** and the
  throwaway's package count went from 8 to 82, and the members still did not resolve, because the
  file's 49 MangaFire rows carry a url form (`/manga/...`) that the server's extension does not
  write (`/title/...`). **That is a fact about this box's extensions and not about the format.**
  The format's own half was proved separately and directly: a hand built file with a shelf record
  and a series carrying its order under field 17 was imported into a live reader, and the series
  came back on that shelf and no other, with the read flags round tripping beside it. Trap 327.
- **The public FAQ used to contradict the page, and the correction is held by a test.** The
  backup answer said "Copy the folder ... Copy it while Papyrus is closed" and that there was
  "nothing to export", both of which this phase made false. Phase 8 rewrote the answer
  (`7ee558f`) to say what the Backups page does, what a pair holds and what it never promises.
  The release review then read every shipped answer against the page it describes, and two
  more had drifted: the adult content answer said the switch sat "in the same row as the
  rating filter" when both pages give it a row of its own, and the move answer promised four
  questions flatly when the AniList row is asked only of a reader who has connected an
  account. Both are corrected, and `FaqTest` now reads the pages' own markup for the gate's
  row and the dialog's `trackConnected` check, so a later move of either control has to come
  with the copy.

**The mishap, owned.** The acceptance sitting broke this checkout's own database and put it back. A
probe pointed the app at a scratch copy of `database/database.sqlite` with `config()` and
`DB::purge()` and ran the real `.tmb` import against it. The import's own bookkeeping went to the
copy, correctly. The reader half's rows went to the **checkout's own file**, because the import's
`SeriesWriter` writes through Laravel's own connection and the pool still held an open handle on
it: 521 extra series states, 12,718 extra chapter states, and an import run left `running` at 900
of 1,277.

The repair used the repository's own tool and the repository's own earlier backup, and the two
intermediate attempts are worth naming because the first nearly cost the ledger. The first deleted
the import run, and the ledger's `imported_series.import_run_id` carries `on delete CASCADE`, so
the **920** ledger rows that pointed at it went with it and the ledger fell from 1,277 rows to
357. The second put those rows back and then could not tell which of the restored server ids the
import had added and which the standing ledger had earned, **because the import had overwritten
the ledger's own record of what those rows carried before it ran**. What worked was a witness that
predated the damage: the pair this phase's own backup had taken twenty minutes earlier, whose
reader half was measured to carry all fifteen standing numbers. The lesson is trap 328, and the
general shape is worth carrying past this phase: **a destructive flow against a live database needs
the database's prior state kept, and the way to keep it is the repository's own backup**, because
the file's own history is overwritten by the very thing the restore is undoing.

**The lessons are numbered, 324 to 328.** Trap 324, an empty PHP array is a JSON list and a
GraphQL input object is not. Trap 325, a server whose library holds nothing writes a real backup
and the reader must be told that rather than told their server is broken. Trap 326, a `.tachibk`
carries the server's library and nothing else. Trap 327, a series' shelf order is field 17 and a
duplicate order discards a shelf rather than merging two. Trap 328, a pointer at a scratch copy is
not a pointer at the live database and the ledger's own history is the only witness to what it
held.

**The standing numbers at the close.** 884 series states with 882 in library, 21,456 chapter
states, the import ledger at 1,277 rows with 614 server ids, 14 import runs, `reading_sessions`
rows 1 to 7 all on manga 10, bulk runs 1 to 19 with run 7's seven pending offers standing at ids
25 to 31 and 78 pending items across all runs, `categories` and `category_series` at 0, and the
 three live run tables with `live_backup_runs` at 1 and the other two at 0. All six gates green on
 the final tree at **2,344 of 2,344 tests with 6,388 assertions**.

### Trap 334: Internal names are not reader copy

A backup page can be structurally clear and still sound like a log file. The first humanization pass
changed the stage keys, but left the nouns that belonged to the implementation in the sentences:
"reader half", "manga half", "manifest", "pair" and "against Suwayomi". A reader needs the result
and the consequence, not the storage arrangement. The page now says "your library", "your manga
data", "backup details" and "what will be replaced", while the code keeps its precise internal names
for the job and the manifest. The test fixture had to be changed with the production wording: a test
that injects the old sentence is not evidence that the new sentence is rendered. The broader copy
review remains open until the owner has read the other settings, empty states and error pages.

The first FAQ trust cluster was then revised as one unit: privacy, account, library storage, network
access, offline reading, sources, adult content and search scope. The facts stayed the same, but the
answers now lead with what the reader can do and keep implementation terms such as "loopback" out of
the public explanation. `FaqTest` holds the public claims and passed 26 tests with 179 assertions.

### Trap 335: A restore needs a stage key, not a sentence parser

The backup modal already had a stable stage key beside each reader-facing note. The restore row had
only the note, even though its page needed the same checkpoint timeline. Reusing the sentence would
have made the modal depend on wording and would have left old rows impossible to classify. The restore
job now stores `progress_stage` beside `progress_note`, and the page maps that key to six reader-facing
checkpoints. The browser proof staged a running row at the manga restore step, read 3 of 6 with the
first three checkpoints complete and the fourth running, then deleted that row after reloading the
page. No real restore was pressed.

