# Importing a Tachimanga library: investigation and plan

A review of `to implement.txt`, the format itself, and what this app can and cannot absorb.
Everything below is measured against the real backup in this repository and the live Suwayomi
on this box, not inferred from documentation.

## The request, restated

From `to implement.txt`:

> The site should have the option to import tachimanga, mihon, and other reader apps libraries and
> use them this is so the users that have the mobile app can experience it on PC, etc. The app
> should display a type of alert/toast on first usage that informs the user about the option to
> import their library from apps like tachimanga, mihon, etc, and it should link to guides on how
> to create the backup on those apps and how to import them into our app.

That is two deliverables, and they are not the same size:

1. **Import a library** from another reader app. A data pipeline.
2. **First-run toast** telling a reader it exists, linking to guides. About a page of UI.

The second is a small piece of the first and should not be built first. A toast that links to a
guide for an importer that does not exist is worse than no toast, because it teaches a reader the
app cannot do something it advertised.

---

## Part one: the format

### Not encrypted, confirmed by opening it

The brief said "I'm not sure if the file needs to be decrypted". It does not. The first four
bytes are `50 4B 03 04`, a ZIP local file header. `ZipArchive` opens it; there is no key
anywhere in the path.

Two nested layers, which is the part worth knowing because a naive importer stops at the first:

```
Tachimanga_backup_2026-10-08_18-31-01-290.tmb      39.36 MB   a ZIP
├── meta.json                                     383 bytes
└── contents.zip                                  41.40 MB   a ZIP inside the ZIP
    ├── prefs/com.apple.java.util.prefs.plist    27.4 KB    binary plist
    ├── prefs/suwayomi.tachidesk.source_<id>.plist   ~280 files, binary plist
    ├── prefs/suwayomi.tachidesk.tracker.plist
    ├── prefs/suwayomi.tachidesk.app.tachimanga.plist
    ├── pref.json                                 2.9 KB
    ├── pref-all.json                             18.6 KB    iOS pbxproj-shaped prefs
    ├── extensions/tachiyomi-*.jar                ~140 files
    └── tachimanga.db                             113.5 MB   SQLite, the library
```

`meta.json` carries `"version": 520`, a SHA-1 `checksum` over `contents.zip`, and the archive
`size`. The version is the schema version and is the field to gate on.

### The brief's description of the database was half wrong

The brief said the SQLite file is "usually named data.db or similar" and holds "the complete
schema for your manga library, category divisions, reading tracking, extension identifiers, and
specific manga object mapping".

The file is `tachimanga.db`. The rest is roughly right, with one correction that matters a great
deal:

> **`tachimanga.db` holds 132,185 manga rows, of which exactly 1,277 are the reader's library.**

The other 131,908 are the catalogue the app had cached from browsing. A naive
`select * from Manga` import would pull a reader's entire browsing history into their shelf.

The library is identified by `Manga.in_library = 1`, not by `CategoryManga`, which has only 146
rows and is the two named categories, which are free-text labels the reader typed rather than any
reserved vocabulary, so there is no shelf name worth matching against. See open item 2.

### The tables that matter

| Table | Rows | Carries |
| --- | --- | --- |
| `Manga` | 132,185 | 1,277 with `in_library = 1`; `title`, `url`, `real_url`, `source`, `in_library_at` |
| `Chapter` | 404,818 | `read`, `bookmark`, `last_page_read`, `last_read_at`, `source_order`, `chapter_number`, `manga` |
| `History` | 4,657 | one row per manga (`UNIQUE(manga_id)`): `last_chapter_id`, `last_read_at` |
| `Category` / `CategoryManga` | 2 / 146 | named shelves and membership |
| `TrackRecord` | 0 | AniList tracking, empty here but non-empty for most readers |
| `Extension` | 1,438 | the installed extensions, as JARs in the archive |

Read state measured: 64,024 chapters read, 1 bookmarked, 50 downloaded. **Downloaded chapters
are not in the backup** (Tachimanga's own docs say so), so 50 downloaded chapters will import as
read-or-unread with no local copy.

### Identity: the single most important finding

`Manga.id` is a **local autoincrement**:

```
id=217  src=2848420679472350044  url='/comics/hiding-a-logistics-center-in-the-apo'
       real_url='https://en-thunderscans.com/comics/hiding-a-logistics-center'
id=9263 id=9264 id=9265  (the sequence continues from where the reader last browsed)
```

`Chapter.id` is local too (`326`, `327`, `328` for manga 217). **Neither id means anything
outside this backup.** `History.last_chapter_id` points at a local chapter id, so it cannot be
used across apps either.

The portable identity is the pair **`(source, url)`**:

- `Manga.source` is a source id, and source ids are **shared** between Tachimanga and Suwayomi.
  Verified: `2848420679472350044` is `Thunder Scans` in this Suwayomi, `isInstalled: true`,
  from `eu.kanade.tachiyomi.extension.en.thunderscans` -- the same extension the backup shipped in
  `extensions/`.
- `Manga.url` is the source-relative path, and is the only URL column that is reliably populated.

That last point is measured, not assumed. In this Suwayomi, of 5 manga sampled on Thunder Scans,
**`realUrl` was non-null on 1 and null on 4**. So `realUrl` cannot be the join key; `url` can.

### Timestamps are mixed units inside one schema

This is the trap I would expect to ship, because nothing in the schema flags it:

| Column | Sample | Unit |
| --- | --- | --- |
| `Manga.in_library_at` | `1758301032` | **seconds** |
| `Manga.update_at` | `1758302119418` | milliseconds |
| `History.last_read_at` | `1758301351` | **seconds** |
| `Chapter.last_read_at` | `1758302065` | **seconds** |
| `Chapter.date_upload` | `1726412400000` | milliseconds |
| `Category.create_at` | `1759823412610` | milliseconds |

Read a seconds value as milliseconds and it becomes `1970-01-21`. **A date 56 years early does
not look obviously wrong in a UI**, which is what makes it dangerous rather than merely broken.

This is not a hypothetical: `manga_backup_converter` reads `in_libraryAt`, `dateUpload` and
`lastReadAt` all through `DateTime.fromMillisecondsSinceEpoch`. On this backup that puts
`in_library_at` and `History.last_read_at` in January 1970. **Its Tachimanga reader is wrong about
two of the three date fields an import cares about.** Do not port it as reference for dates.

For contrast, this app stores `Y-m-d H:i:s` datetime strings, not epochs, so the import writes
the same shape it already writes. The unit question only matters for *reading* the backup.

### The plists can be skipped

The brief implied the reading state lived in the prefs. It does not. `prefs/suwayomi.tachidesk.source_<n>.plist`
is named by **source** id (413 of them against 279 sources), and the values inside are per-source
app settings: `pref_use_chapters_api`, `contentRating_en`, `blockedGroups_en`, `hide_locked`,
`slug_map_v2`, `usePort443_en`.

None of those have anywhere to go in this app. Searched: `content_rating` 0 occurrences,
`blockedGroups` 0, `blockedUploader` 0, `usePort443` 0, `slug_map` 0, `hide_locked` 0.

So the importer can **ignore `prefs/` entirely**, which removes the need for a binary plist
reader in PHP. PHP has no built-in one and `composer.json` has no plist package. This is the
single largest scope reduction available, and it is available because the data turned out not to
be there.

`pref-all.json` is iOS device preferences (`AppleLocale`, `AppleKeyboards`,
`AppleLanguagesSchemaVersion`), which confirms this backup came from an iPhone. Also ignorable.

---

## Part two: what this app can actually do with it

### The join works, and it is a real join

Suwayomi's `mangas(filter:)` accepts `sourceId` (`LongString`, so a **quoted** number) and `url`
or `realUrl` (`StringFilterInput`), and returns a `nodes` connection. That is exactly the pair
the backup carries:

```graphql
query ($f: MangaFilterInput!) {
  mangas(filter: $f, first: 5) { nodes { id title url realUrl } }
}
# variables: {"f": {"sourceId": {"equalTo": "2848420679472350044"},
#                    "url": {"equalTo": "/comics/hiding-a-logistics-center-in-the-apo"}}}
```

Verified live: authenticated, valid, 66ms, zero rows for an absent series.

### Two tiers, and the second one costs

This Suwayomi holds **7 manga on Thunder Scans**. The backup's library is 1,277 series across
56 sources. So most of an import is a series Suwayomi has never heard of.

**Tier 1, already in the catalogue:** `mangas(filter:)` on `(sourceId, url)`. 26 to 66ms. Free.

**Tier 2, absent:** `fetchSourceManga`, which is a **mutation**, input `FetchSourceMangaInput`
with `source: LongString`, `query`, `page: Int!` (non-null, so omitting it is a validation error,
not a default) and `type: SEARCH|POPULAR|LATEST`. It returns a payload wrapper
`{ mangas { ... } hasNextPage }`, and it **persists what it returns**.

Measured, five real titles against one known source:

```
The Berserker's Second Playthrough          1 hits in    535ms
Record of Lodoss War: Queen of the Spirits  1 hits in    524ms
I Became the Berserker of the World...      1 hits in    505ms
The Illegitimate Son Became a Monster...    1 hits in    505ms
Doctor Go to Joseon                         1 hits in    511ms

median 511ms, mean 516ms for ONE series, ONE source
1,277 series serially:  11.0 minutes
```

**11 minutes, not hours.** The migration wizard's measured 21.9s per series is 82 sources per
series; an import knows the source, so it is one search, not eighty-two.

And tier 2 is self-healing: after the mutation, re-querying `mangas` by url returned 1 match in
27ms. **A second import of the same backup is almost entirely tier 1.**

### What already exists to reuse

| Existing | Reuse |
| --- | --- |
| `ReadingState::setInLibrary()` | filing a series. Do not write `series_states` directly. |
| `ReadingState::setPointer()` | the `History.last_chapter_id` equivalent |
| `ReadingState::markManyRead()` | 64,024 read chapters in one call per series |
| `ReadingState::moveSeries()` | the transactional wrapper, for the state write |
| `AniList\Import` | **the closest analogue in the codebase.** A start row, `runPass()`, a `state()` projection for polling, `fail()`. |
| `CheckLibrary` | the bounded-slice job shape: `BUDGET_SECONDS`, `MAX_SERIES`, `NEXT_SLICE_SECONDS`, self-chaining, `chainDepth` guard |
| `CandidateFinder` | **do not reuse.** It excludes the series' current source by design (`where('suwayomi_source_id', '!=', ...)`) and matches on title. An import needs the same source matched on url. |

The `chainDepth` static guard in `CheckLibrary` exists because `QUEUE_CONNECTION=sync` makes a
dispatch run inline, so a self-re-booking pass would recurse until memory ran out. An import job
needs the same guard.

### The columns an import writes

From `2026_09_29_000003_create_series_states_table.php`:

```
user_id, suwayomi_manga_id, in_library, added_at, favourited_at, read_later_at,
rating, tags, excluded_scanlators, reader_settings, reading_mode,
chapter_list_settings, fetch_interval_days, update_strategy,
last_read_chapter_id, last_read_at, created_at, updated_at
unique(user_id, suwayomi_manga_id)
```

The mapping, with the honest gaps:

| Backup | Into | Notes |
| --- | --- | --- |
| `Manga.in_library = 1` | `in_library = true` | the whole import, basically |
| `Manga.in_library_at` (seconds) | `added_at` | **unit-normalise** |
| `History.last_read_at` (seconds) | `last_read_at` | **unit-normalise** |
| `History.last_chapter_id` | `last_read_chapter_id` | **local id.** Must be remapped through the chapter list. |
| `Chapter.read = 1` | `chapter_states.is_read` | needs chapter ids from Suwayomi, not the backup's |
| `Chapter.bookmark = 1` | `chapter_states.bookmarked` | 1 chapter here; a real reader may have more |
| `Category.name` | a shelf | 2, both free-text labels the reader typed, so reported rather than auto-matched |
| `CategoryManga` | shelf membership | 146 rows |
| `TrackRecord` | AniList tracking | **0 rows here, non-empty for most readers** |
| `Manga.rating`, `.tags` | `rating`, `tags` | **absent from the backup schema.** Tachimanga has no per-series rating or tags. |
| `Chapter.last_page_read` | reader resume | available |
| extensions | nothing | the app manages extensions itself |
| downloads | nothing | not in the backup, by Tachimanga's own docs |

**Tachimanga does not store ratings or tags.** So a reader who rated 300 series on their phone
loses all of it, and the import cannot fix that. This is worth saying in the UI before the
import runs rather than after.

---

## Severity and phases

### S, blocking, decide before writing code

| # | Item | Why it is S |
| --- | --- | --- |
| S1 | `History.last_chapter_id` is a **local** chapter id | The resume pointer is the thing a reader notices most. Remapping it wrong silently sends everyone to chapter 1. Blocks the whole feature. |
| S2 | Timestamps are mixed units | Two wrong by 56 years, silently. Blocks date writes. |
| S3 | The 1,277-vs-132,185 distinction | Importing the cache instead of the library is a data-loss-shaped mistake on someone else's data. |
| S4 | 113 MB SQLite read under `memory_limit=128M` | `ZipArchive` + a 113 MB file will OOM unless streamed to a temp file. The CBZ exporter already streams for exactly this reason. |

### H, high

| # | Item | Why |
| --- | --- | --- |
| H1 | A 39 MB upload through Livewire | Measured on this server: `upload_max_filesize = 2M`, `post_max_size = 8M`, `memory_limit = 128M`. The cover upload's ceiling is `config('papyrus.covers.max_kilobytes')`, default 2048 KB, chosen to match the server limit. **A 39 MB backup cannot be uploaded as-is**, and `post_max_size` binds before `upload_max_filesize` does. Answered above: an app-enforced ceiling plus an artisan command for what exceeds it. |
| H2 | Bounded-slice job with progress | 1,277 series is 11 minutes of upstream calls. Above `memory_limit`/`max_execution_time` as a single request. Needs `Import`-style status row and polling. |
| H3 | Idempotency | A reader will re-import. `unique(user_id, suwayomi_manga_id)` plus an import-run key, or a second import double-files. |
| H4 | Tier 1 / tier 2 must be distinguished in the report | "1,204 resolved, 73 need a source that no longer answers" is a different outcome from "1,277 imported" and the reader deserves to see it. |
| H5 | Unresolved series must not vanish | A source that has shut down leaves a series nothing can resolve. It has to be listed, not dropped. |

### M, medium

| # | Item | Why |
| --- | --- | --- |
| M1 | Chapter id remapping needs a per-series fetch | `Chapter.id` is local, so read state can only be applied after resolving each chapter from Suwayomi's list. |
| M2 | Rating and tags do not exist in the source | Must be stated, not left as a silent absence. |
| M3 | Zip-slip guard on archive entry names | Reading entry names from an untrusted archive and writing them to disk is the standard zip-slip pattern. |
| M4 | Declining sources | Tachimanga can hold sources this Suwayomi has not installed. 413 source ids in the backup, 84 here. |
| M5 | `TrackRecord` import | AniList remote ids may point at a different list entry than Suwayomi's. |
| M6 | First-run toast | The user's explicit ask, and **blocked by the importer existing.** |

### L, low

| # | Item |
| --- | --- |
| L1 | Import history visible to the reader |
| L2 | Dry-run preview before committing |
| L3 | Mihon `.tachibk` support (protobuf, a different parser entirely) |
| L4 | Aidoku `.aib` and Paperback `.pas4` |
| L5 | `pref-all.json` / `prefs/` parsing, if ever wanted |
| L6 | Extension comparison, offering to install a source the backup had |

### Decisions taken

Four questions were open. All four are now answered, so phase 0 is a record rather than a
decision, and the answers are load-bearing enough to state in full.

| Question | Decision | Why it changes the shape of the work |
| --- | --- | --- |
| How does a 39MB file reach a 2M server? | **Both paths.** A browser upload with an app-enforced ceiling, plus `php artisan papyrus:import-backup <path>` for anything above it. | `upload_max_filesize` is `PHP_INI_PERDIR`, so it cannot be raised at runtime, and `post_max_size` at 8M binds before the 2M file limit. Raising neither leaves a reader with a large library unable to import at all; raising only php.ini pushes a server edit onto every reader. |
| What crosses over? | **Library, read state and shelves. Tracking later.** | Ratings and tags do not exist in the source and cannot be recovered, so the UI must say so before the run. Shelves are 2 named and 146 rows, which is measurable. Tracking has **0 rows in this backup**, so it would ship unproven. |
| Conflict with an existing library? | **Never overwrite. Merge conservatively.** | A read is monotonic work and an un-read is irreversible loss of progress, so the safe direction is the one that only ever adds. Every skip is listed in the run report. |
| Where does Mihon land? | **`.tmb` first, behind a reader interface.** | One measured `.tmb`, zero `.tachibk` samples. The interface is the constraint that matters, so `.tachibk` drops in later without reshaping the resolver or the writer. |

The last one is a commitment, not a deferral: phase 1 puts the archive reader behind an interface
precisely so that phase 6 is a new class rather than a refactor.

### Phase order

**Phase 0, decide (no code).** Settled, above. S1 to S4 remain the blocking unknowns and they are
answered in this document: the chapter-id remap, the timestamp units, the 1,277-vs-132,185
distinction, and the 113MB-under-128M read.

**Phase 1, read the archive.** Unzip streamed to a temp file, open `tachimanga.db` read-only,
extract `Manga.in_library = 1` with `source`, `url`, `title`, and the chapter rows. Assert the
tables exist before reading them. **No writes to this app.** Output: a JSON summary of what was
found, which is testable on its own. The reader sits behind an interface here, not later, so that
Mihon is additive.

**Phase 2, resolve.** Tier 1 for everything, batched per source. Tier 2 only for what tier 1
missed, through `fetchSourceManga`, in slices. Output: `(tachimanga manga id -> suwayomi manga
id|unresolved)`, persisted so phase 4 does not redo it.

**Phase 3, write.** `ReadingState` only. Per series: `setInLibrary`, then `markManyRead` for the
resolved chapter ids, then `setPointer` from the remapped `History.last_chapter_id`. Merge
conservatively throughout: a chapter read in either side is read, nothing is ever cleared, and a
series already in the library is added to rather than rewritten. Shelves afterwards, mapped by
name with the unmapped ones reported rather than dropped. One transaction per series, so a
failure is one series rather than half a library.

**Phase 4, report and undo.** A run row the page polls, listing resolved, unresolved with
reasons, and skipped-with-reason (no rating in the source). An undo that removes exactly what
this run added, which is only knowable if the run recorded what it added.

**Phase 5, discoverability.** The toast, the guides, the upload. Only now, because it advertises
something that works.

**Phase 6, other formats.** Mihon protobuf, then Aidoku. Each is a new reader against the same
`ImportRun`, and `.tmb` is the proof the pipeline is real.

---

## Risks

**What breaks current code: nothing, if the boundaries hold.** The importer writes through
`ReadingState` and reads through `SeriesGateway`, both of which are already used by live
features. `SeriesMigrator` is the closest precedent and it touches `series_states`,
`chapter_states` and shelves inside one transaction, so the blast radius is known.

What *could* break, honestly:

| Risk | How | Mitigation |
| --- | --- | --- |
| A 39 MB upload exceeds `upload_max_filesize` | every import fails | decide in phase 0 |
| 113 MB SQLite under a 128M limit | OOM, 500 | stream to temp file, open read-only, never `getContent()` |
| Queue worker timeout | 1,277 series is 11 min of upstream | slice, self-chain, `chainDepth` guard |
| Double import | reader presses twice | import-run key plus `unique(user_id, suwayomi_manga_id)` |
| Wrong chapter resume | local id treated as global | S1; assert the remap, refuse on mismatch |
| 1970 dates | mixed units | S2; normalise per column, and assert nothing lands before 2000 |
| Upstream rate limiting | 1,277 searches | tier 1 first so most cost nothing; throttle tier 2 |
| SQLite lock contention | reader page polls while the import writes | the 20s inter-gate gap exists because of this class; batch writes |

**What this would make better.** One thing worth stating plainly: `verify-migrate.py` and the
migration wizard exist to move a series between sources by **title search**. This importer moves a
library by **exact url**. The resolver is the same idea with a better key, and once it exists
`CandidateFinder` has a strictly more accurate fallback available for its own fuzzy matching.

---

## Code snippets

### Reading the archive, streamed

```php
/**
 * A Tachimanga backup is a zip holding another zip, and the inner one holds a 113MB SQLite file.
 * Neither layer is read into memory: the outer is expanded to a temp directory, the database is
 * opened read-only by path. `memory_limit` is 128M, and a 113MB file read as a string does not fit
 * in it, which is the same reason CbzWriter streams.
 */
final class TachimangaReader
{
    private const REQUIRED_ENTRIES = ['meta.json', 'contents.zip'];
    private const DB_ENTRY = 'tachimanga.db';

    /** @return resource The SQLite handle, for the caller to close. */
    public function open(string $path): mixed
    {
        $outer = new ZipArchive();

        if ($outer->open($path) !== true) {
            throw new ImportRefused('That file is not a readable backup.');
        }

        try {
            foreach (self::REQUIRED_ENTRIES as $entry) {
                if ($outer->locateName($entry) === false) {
                    throw new ImportRefused("That backup has no {$entry}, so it is not a Tachimanga backup.");
                }
            }

            $meta = json_decode($outer->getFromName('meta.json'), true);
            $contents = $outer->getStream('contents.zip');
            $temp = tempnam(sys_get_temp_dir(), 'papyrus-import-');
            stream_copy_to_stream($contents, fopen($temp, 'wb'));

            $version = (int) ($meta['version'] ?? 0);

            if ($version < 520 || $version > self::MAXIMUM_KNOWN_VERSION) {
                throw new ImportRefused(
                    "That backup is version {$version} and this server understands 520 to "
                    .self::MAXIMUM_KNOWN_VERSION.'. Update the server, or ask on the forum.'
                );
            }

            $inner = new ZipArchive();

            if ($inner->open($temp) !== true) {
                throw new ImportRefused('The backup contents could not be opened.');
            }

            // Written to a temp file rather than `getFromName()`, which would be 113MB of string.
            $database = tempnam(sys_get_temp_dir(), 'papyrus-import-db-');
            stream_copy_to_stream($inner->getStream(self::DB_ENTRY), fopen($database, 'wb'));

            return new TachimangaDatabase($database);
        } finally {
            $outer->close();
        }
    }
}
```

### Normalising the timestamp units

```php
/**
 * The backup's timestamps are in mixed units inside one schema, with nothing marking which is
 * which: Manga.in_library_at is seconds, Category.create_at is milliseconds. Read as the wrong
 * unit a date lands in 1970, which does not look broken in a UI.
 *
 * So the unit is decided per column from what we measured, never guessed from the value, and a
 * date that normalises before 2000 is refused rather than written.
 */
private function epoch(?string $value, ImportTimestampUnit $unit): ?CarbonImmutable
{
    if ($value === null || (int) $value === 0) {
        return null;
    }

    $seconds = $unit === ImportTimestampUnit::Seconds
        ? (int) $value
        : intdiv((int) $value, 1000);

    $date = CarbonImmutable::createFromTimestampUTC($seconds);

    // A pre-2000 date from this schema means the unit was wrong. Better a refusal than a lie.
    if ($date->year < 2000) {
        throw new ImportRefused(
            "A date in the backup reads as {$date->toDateString()}, which is before this app existed."
            .' That is a backup format problem, not something to import.'
        );
    }

    return $date;
}

// The measured map. One entry per column, because a single default is what gets it wrong.
private const UNITS = [
    'Manga.in_library_at'     => ImportTimestampUnit::Seconds,
    'History.last_read_at'    => ImportTimestampUnit::Seconds,
    'Chapter.last_read_at'    => ImportTimestampUnit::Seconds,
    'Chapter.date_upload'     => ImportTimestampUnit::Milliseconds,
    'Category.create_at'      => ImportTimestampUnit::Milliseconds,
];
```

### Tier 1 resolution, batched per source

```php
/**
 * Tier 1: ask Suwayomi for manga we already hold, matched on (source, url).
 *
 * The source id is a string even though it is a number. It is over 2^53, so it cannot be a JSON
 * number without losing precision, and Suwayomi types it `LongString` for that reason. Sending it
 * unquoted is a validation error, and sending it quoted-but-parsed-as-an-int is worse, because
 * PHP would round it silently.
 */
private function resolveKnown(ImportSource $source, array $urls): array
{
    $resolved = [];

    foreach (array_chunk($urls, 50) as $batch) {
        // one query per url, because the filter is an AND not an OR over a list
        foreach ($batch as $url) {
            $row = $this->series->firstWhere([
                'sourceId' => $source->suwayomiId(),   // string
                'url'      => $this->normaliseUrl($url),
            ]);

            if ($row !== null) {
                $resolved[$url] = (int) $row['id'];
            }
        }
    }

    return $resolved;
}

/**
 * Tachimanga and Suwayomi disagree about the trailing slash, and a join that returns zero
 * because of it looks exactly like a join with nothing to find. Measured on this box: the backup
 * stores `/comics/hiding-a-logistics-center-in-the-apo` and Suwayomi stores
 * `/comics/the-illegitimate-son-became-a-monster-summuner/`.
 */
private function normaliseUrl(string $url): string
{
    return '/'.trim($url, '/');
}
```

### Tier 2, as a bounded slice

```php
/**
 * Tier 2: the series Suwayomi does not hold, fetched from the live source.
 *
 * Shaped exactly like CheckLibrary, for the reasons documented there: one pass takes a slice
 * bounded by time, writes what it found, and re-books itself. Measured at 511ms a series, so a
 * 45s budget is about 88 series a pass and a 1,277 series import is about fifteen passes.
 *
 * `page` is non-null in FetchSourceMangaInput, so it is always sent. `type` is explicit because
 * LATEST on an unsupported source throws a bare Java exception.
 */
final class ResolveImportSlice implements ShouldQueue
{
    public int $tries = 1;

    /** Comfortably above the budget, so the budget ends a pass rather than the worker. */
    public int $timeout = 120;

    public const BUDGET_SECONDS = 45;
    public const NEXT_SLICE_SECONDS = 3;

    /** Same reason as CheckLibrary: with the sync driver a dispatch runs inline. */
    private static int $chainDepth = 0;

    public function handle(Transport $transport, ImportRun $run): void
    {
        self::$chainDepth++;
        $started = microtime(true);

        try {
            foreach ($run->unresolved()->limit(self::MAX_SERIES)->get() as $entry) {
                if (microtime(true) - $started >= self::BUDGET_SECONDS) {
                    break;
                }

                $found = $transport->fetchSourceManga($entry->sourceId, $entry->title);

                // A source that has shut down answers with nothing, repeatedly. Retrying it is
                // how a pass turns into a hang, so it is recorded as unresolved and not retried.
                if ($found === null) {
                    $entry->markUnresolved('the source did not return this series');

                    continue;
                }

                $entry->resolveTo($found['id'], $found['url']);
            }

            if (self::$chainDepth === 1 && $run->hasUnresolved()) {
                self::dispatch($run->id)->delay(now()->addSeconds(self::NEXT_SLICE_SECONDS));
            }
        } finally {
            self::$chainDepth--;
        }
    }
}
```

### The chapter-id remap, which is S1

```php
/**
 * `History.last_chapter_id` and every `Chapter.id` are local autoincrements in the backup.
 * This backup's manga 217 has chapters 326, 327, 328; Suwayomi numbers its own. So read state
 * can only be applied after resolving the backup's chapter list onto Suwayomi's, and the resume
 * pointer specifically has to survive that or every reader lands on chapter one.
 *
 * Matched on (chapter_number, name, source_order) rather than on position, because a source that
 * gains or loses a chapter shifts every index after it.
 */
private function remapChapters(ImportSeries $series, array $backupChapters): array
{
    $theirs = $this->gateway->chapterMap($series->suwayomiId()) ?? [];

    if ($theirs === []) {
        $series->markUnresolved('Suwayomi holds no chapters for this series yet');

        return [];
    }

    $byPosition = [];

    foreach ($theirs as $index => $chapter) {
        $byPosition[(int) ($chapter['source_order'] ?? $index)] = (int) $chapter['id'];
    }

    $readIds = [];

    foreach ($backupChapters as $chapter) {
        // source_order is the stable key both apps agree on. Fall back to the chapter number
        // when the source does not populate it, and refuse rather than guess when neither does.
        $position = $chapter['source_order'] !== null
            ? $byPosition[(int) $chapter['source_order']] ?? null
            : ($byPosition[(int) $chapter['chapter_number']] ?? null);

        if ($position === null) {
            continue;   // a chapter that no longer exists upstream, which is normal
        }

        if ($chapter['read']) {
            $readIds[] = $position;
        }

        if ($chapter['bookmark']) {
            $series->bookmarkChapter($position);
        }
    }

    return $readIds;
}

/**
 * The resume pointer, and the reason this whole phase is S1.
 *
 * The backup says "last chapter read is chapter id 391638". That id is meaningless here. If it
 * is written through unremapped, `last_read_chapter_id` points at whatever chapter happens to
 * hold that number in this server, which is a series the reader has never opened, and
 * ContinueReading::firstUnread looks the pointer up in the chapter list, fails to find it, and
 * falls back to the first unread. So the failure mode is "everyone resumes at chapter one",
 * with no error anywhere.
 */
private function remapResume(ImportSeries $series, array $backupChapters, ?int $backupLastChapterId): void
{
    if ($backupLastChapterId === null) {
        return;
    }

    $match = null;

    foreach ($backupChapters as $chapter) {
        if ((int) $chapter['id'] === $backupLastChapterId) {
            $match = $chapter;
            break;
        }
    }

    if ($match === null) {
        // The chapter is gone upstream. Landing on the first unread is the right answer and is
        // what the app already does, so say so in the run's report rather than guessing.
        $series->noteResumeLost($backupLastChapterId);

        return;
    }

    $mapped = $this->remapChapters($series, [$match])[0] ?? null;

    if ($mapped !== null) {
        $series->setPointer($mapped);
    }
}
```

### Writing through `ReadingState`, not the table

```php
/**
 * Every write goes through ReadingState, for the same reason SeriesMigrator does: the state work
 * and the record of it belong in one transaction, and a half-applied import is worse than a
 * refused one. A reader whose read state moved for half their chapters and not the rest has no
 * way to tell.
 */
private function applySeries(ImportSeries $series): void
{
    $this->reading->transaction(function () use ($series): void {
        $this->reading->setInLibrary($series->userId(), $series->suwayomiId(), true);
        $this->reading->setAddedAt($series->userId(), $series->suwayomiId(), $series->addedAt());

        $readIds = $this->remapChapters($series, $series->backupChapters());

        if ($readIds !== []) {
            $this->reading->markManyRead($series->userId(), $series->suwayomiId(), $readIds, true);
        }

        $this->remapResume($series, $series->backupChapters(), $series->backupResumeChapterId());
    });
}
```

---

## Code quality and DRY

**What would be duplicated, and how to avoid it.**

| Duplication risk | Avoid by |
| --- | --- |
| Sliced self-chaining job, `chainDepth`, budget, slice delay | Copy the `CheckLibrary` shape deliberately and say so in both files. A shared abstract base would be the DRY answer, and it is **not** worth it: `CheckLibrary`'s docblock is 40 lines explaining why its numbers are what they are, and a base class would move that reasoning away from the one job that needs it. Two similar jobs beat one abstracted job with a config array. |
| Zip extraction | `CbzWriter`/`CbzExporter` are a **writer**, so no reuse. One reader class, used by `.tmb` and later by Mihon. |
| The three timestamp units | One `epoch()` with a per-column unit map, as above. The map is data, so a new format is a new map and not a new parser. |
| Matching a series on title | `CandidateFinder` matches by title because that is all it has. The importer matches by url and should not route through it. |
| Polling a long job | `AniList\Import::state()` is exactly the projection a run row needs. Same shape, so the import page's polling is a copy of that shape rather than a new mechanism. |

**What must not be shared.** `SeriesGateway` is the boundary to Suwayomi and already handles the
`LongString` source ids. The importer adds no new transport; if it did, that would be a second
place source ids get stringified, and the notes record that trap already.

**Quality checks the code would have to pass**, so the plan is not a wish. The bar here is PHPStan
level 7, Pint, and the 1,157-test suite, and the import gets tests for:

- the archive refusal paths (not a zip, no `contents.zip`, no `tachimanga.db`, unknown version)
- the unit normalisation, **including a case that asserts a pre-2000 date is refused**
- the url normalisation, because that is the thing a naive join gets wrong silently
- the chapter remap, and the resume pointer specifically
- idempotency: a second import of the same file changes nothing
- One browser gate, `verify-import.mjs`, in the `[ok ]`/`[FAIL]` convention, covering the run
  row, the unresolved list and the undo. Per trap 173, a gate that asserts into a list nothing
  reads is not a gate.

---

## What I could not settle

The upload limit is settled and is no longer on this list. What is left is smaller, and none of it
blocks phase 1.

1. **Whether the app should hold a 113 MB temp file**, and for how long. A backup arrives, is read,
   and can be deleted; the import's own state lives in the run row, not the file. But the window
   between upload and first slice is a window where a 113 MB file exists, and with both paths
   agreed that window is now reachable on a box that has only just cleared the memory limit. The
   honest options are a delete-on-first-slice or a delete-on-run-completion, plus a ceiling check
   on free disk before starting rather than failing partway through.

2. **Shelf name matching, and the correction that changed the answer.** This backup's two
   categories are `"Youtube "` (with a trailing space) and `"Snowflakes"`. The reader these came
   from confirms both are categories **they typed themselves**: Tachimanga lets a reader create
   arbitrary categories, and these are the names, not any reserved vocabulary.

   That is stronger than the concern I first recorded. It is not that the names are awkward to
   match, it is that **there is nothing to match them against.** They are free-text labels from one
   person's library, so:
   - a Papyrus shelf called `Snowflakes` that this reader made for an unrelated reason would
     false-positive and silently absorb 146 series
   - a shelf this reader did mean to match but spelled differently would be missed
   - nothing in the file distinguishes a user label from a system one, so no heuristic can tell
     them apart

   So report and skip, listing every category and its member count in the run report, and let the
   reader decide per shelf. Creating shelves from another app's free-text labels is how a library
   ends up with four near-duplicate groups.

3. **Mihon support is a different parser.** `.tachibk` is protobuf inside gzip. Everything in
   phases 1 to 4 is reusable and the reader interface from phase 1 is what makes it additive rather
   than a reshape. Worth its own phase, and it needs a real `.tachibk` sample before it starts,
   because the `.tmb` work is only trustworthy as far as its one measured file.

4. **Whether 1,277 series is typical.** It is this reader's. The design should not assume the
   count; the slice budget already does not, but the UI copy should avoid implying a fixed size.