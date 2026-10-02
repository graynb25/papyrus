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
123. **A right answer for the wrong reason still has to be corrected.** H16 concluded there
     was no image-conversion setting, citing `serveConversions` being absent from the
     schema. It is present, on four types, and writable through `Mutation.setSettings`. The
     conclusion that survived is the one that was measured rather than inferred: all three
     of `webp`, `avif` and `jpg` come back byte-identical `image/webp`, so there is no
     bandwidth win either way. But a note saying "there is no setting here" reads as "this
     was tested", and the next person skips the test. The correction is the point, not the
     tidiness. This one sat in a document for weeks before anybody read the schema it was
     describing, which is the argument for re-reading a claim whenever the server moves.
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

Current standing: **920 tests**, 1,979 assertions, Pint clean across 205 files, PHPStan
level 7 clean, zero em dashes. Plus 39 checks in `verify-reader.py`, 30 in
`verify-navigator.py`, 33 in `verify-notes.py`, 20 in `verify-covers.py`, 18 in
`verify-library.py`, 18 in `verify-downloads.py`, 21 in `verify-migrate.py`, 14 in
`verify-search-anilist.py`, 21 in `verify-library-check.py` and 17 in
`verify-continue-reading.py`, all of which need a browser that produces frames. The
October 2026 feature pass added 21 checks in `verify-october-pass.mjs` over the search
filters, the result-card library toggle, the read toggles, the updates view and the
connections page.

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

Five pages are live at `/search`, `/sources`, `/health`, `/library`, `/downloads` and
`/series/{manga}`, plus the reader at `/read/{manga}/{chapter}`. `/dashboard` redirects to
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
Nothing in this section is fixed, because the brief for that pass was documentation only.
It is written down so the next person does not have to rediscover it, and so no document in
this repository is trusted further than it has been checked.

### 14.1 Documented as working, not implemented

| The documents say | The code has |
|---|---|
| Deployment mode `shared` enforces **per-user quotas** (README table, section 12) | `Deployment::enforcesQuotas()` and `::quotaFor()`, called from `tests/Unit/DeploymentTest.php` and **nothing else**. `users.storage_quota_bytes` is written by nothing. |
| Deployment mode `public` exposes **moderation surfaces** (README table, SECURITY.md) | No moderation surface exists. `isShared()` and `isPublic()` are also uncalled, so the only distinction the app makes at runtime is single against not-single. |
| `suwayomi:schema:diff` is a command (config/papyrus.php:374) | There is no such command. The diff is `suwayomi:schema:dump --dry-run`. |
| `search.fanout_concurrency = 8` bounds in-flight jobs | Read by nothing. **No concurrency cap is applied to the batch**, which is the opposite of what section 5 of this file says the fan-out does. |
| `downloads.cbz_export_max_bytes` size-guards an export | No CBZ export exists. Suwayomi's `/api/v1/chapter/{id}/download` builds the whole archive in memory; the proxy refuses that endpoint rather than guarding it. |

The registration rule is the exception and now holds: `RegistrationMustBeAllowed` 404s
`register` and `register.store` when the mode disallows it, and
`tests/Feature/RegistrationGateTest.php` proves it through requests. See trap 121 for why
that took as long as it did.

### 14.2 Config keys no code reads

Six of the 47 keys in `config/papyrus.php` are read by nothing:

| Key | Why it is still there |
|---|---|
| `suwayomi.jwt_audience` | Intended audience check. `Jwt` reads `exp` only and never verifies, deliberately. |
| `search.fanout_concurrency` | Never applied. See above. |
| `downloads.cbz_export_max_bytes` | No export to guard. |
| `downloads.poll_interval` | The schedule uses `everyTenSeconds()`; the number is written twice, in `SyncDownloads::INTERVAL_SECONDS` and in `routes/console.php`. |
| `reader.preload_ahead` | The reader does its own preloading in plain JavaScript, reading configuration injected by `@js(...)`. |
| `reader.preload_behind` | As above. |

Two more are read by the job that shadows them rather than the config:
`anilist.gap_seconds` and `anilist.pass_budget_seconds` both appear in
`ResolveAniListTitles.php`, but only as `private const GAP_SECONDS = 2` and
`private const PASS_BUDGET_SECONDS = 45`. Setting the config key changes nothing.

### 14.3 Public methods nothing calls

Unreachable from production and from tests alike, unless noted:

| Member | Note |
|---|---|
| `SourceSearcher::browse()` | The entire POPULAR and LATEST browse path. Nothing calls it, including tests. It is the code a "browse this source" feature would need. |
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
  immediately: `ReadingState.php:103` and `SearchSourceJob.php:33`.
- **`ReaderSettings::gap` is documented and defaulted inconsistently.** The docblock
  explains why a webtoon needs a non-zero gap; the default is `0`.
- **The trap list is out of order**: 1 to 67, then 75 to 77, 83 to 122, 78 to 82, 68 to 74.
  All 122 numbers appear exactly once, so this is a sort defect rather than lost content,
  but it makes "see trap N" hard to audit.
- **`.env.example` documents 9 of the 32 env-backed keys.** The rest are discoverable in
  `config/papyrus.php`, which is where they are commented.
- **`.env.example` misdescribes `SUWAYOMI_PUBLIC_PATH`** as a path where "page images bypass
  PHP entirely". They do not: `SuwayomiProxyController` streams every image through PHP, and
  this file's own AD-3 says so.
- **`.well-known/passkey-endpoints` carries no middleware at all**, unlike every other route.
- **The reader is described as carrying no framework**, and its script does not use one, but
  `wire:navigate` is on every chapter-navigator segment, so the rendered document does
  reference Livewire. `ReadPathTest` checks for a snapshot payload rather than for the
  attribute, which is why it passes.

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
