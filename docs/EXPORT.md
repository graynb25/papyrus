# Exporting to another reader

Companion to [`IMPORT-ANALYSIS.md`](IMPORT-ANALYSIS.md), which covers reading other apps'
backups. This covers the other direction: producing a file that Mihon, Tachiyomi or Suwayomi
can import, so a reader can take their library to a phone.

Status: investigated and designed. Nothing built yet.

## The finding that decides the design

**Suwayomi can already export a `.tachibk`, and Papyrus should not use it.**

That is the opposite of the conclusion the investigation started with, and the reason is a
measurement rather than an argument.

Everything needed is there. `createBackup` exists, it works when called through the
application's own Gateway, and the file it produces reads perfectly with the
`TachibkReader` this project already has:

```
createBackup(input: { flags: { includeManga, includeCategories, includeChapters,
                               includeHistory, includeTracking, includeClientData,
                               includeServerSettings } }) { url }
  -> /api/graphql/files/backup/org.suwayomi.tachidesk._2026-10-10_16-09.tachibk

76,633 bytes, gzip, recognised by TachibkReader
library_series 4    chapters 2,911    chapters_read 1,616
```

That is a working export, and the round trip through our own reader is proof the file is
well formed. So on the face of it the feature is one HTTP call and a download link.

**And it exports four series.** The reader's shelf has 867.

| | Count |
| --- | --- |
| Papyrus `series_states` for this reader | **867** |
| of those, carrying a `suwayomi_manga_id` | 672 |
| series in Suwayomi's own library, which is what `createBackup` exports | **4** |

The 867 exist in Suwayomi. Asking for one, `manga(id: 41)`, returns "The Greatest Estate
Developer" with 223 chapters. They are not marked `inLibrary`, and `createBackup` exports the
library rather than everything the server holds.

So handing export to Suwayomi produces a backup of four series for a reader with 867, and
reports success. **That is the worst possible failure for this feature**: a small, valid,
plausible file.

## What this means

Papyrus has to build the file, because the shelf Papyrus shows is not the library Suwayomi
exports. Delegating gets the format right and the contents wrong, which is harder to notice
than being wrong at both.

The format is not the obstacle. Every field a `.tachibk` needs is available:

| Needed | From | Available |
| --- | --- | --- |
| series source id | `MangaType.sourceId` | yes |
| series key | `MangaType.url` | yes |
| series title | `MangaType.title` | yes |
| author, artist, description, genres, status, cover | `MangaType` | yes |
| chapter key | `ChapterType.url` | yes |
| chapter name | `ChapterType.name` | yes |
| chapter number, fractional | `ChapterType.chapterNumber`, a `Float` | yes |
| read flag | `ChapterType.isRead` | yes |
| scanlator | `ChapterType.scanlator` | yes |

The field numbers are already measured and written down in `TachibkSchema`, from real bytes,
so the writer is the inverse of a reader that is already proved against a real file.

## The honest cost, which is scale not format

867 series and the chapters behind them is a lot of API traffic. A naive walk is one query per
series and one per chapter list, and at 867 series that is a request count a reader will notice
and a timeout budget that has to be designed for.

Three ways out, in order of preference:

1. **Ask Suwayomi for the shelf in batches.** Whether its GraphQL can return many manga in one
   query is the open question. `manga` takes a required `id` and there is no obvious batch form
   in the committed snapshot, which is the thing to check first.
2. **Cache what Papyrus already has.** `series_states` holds the title and the Suwayomi id for
   672 of 867, so the series half of the export needs no API call at all for those. Only the
   chapters do.
3. **Make it a queued job with a progress report**, which the import side already has the
   shape of. An export of 867 series is a minutes-long operation and should say so rather than
   spinning.

## The two formats

### `.tachibk`, for Mihon, Tachiyomi and Suwayomi

The valuable one, and the one to build first. A writer plus a queue job plus a progress report.

### `.tmb`, for Tachimanga

Only Papyrus can do this, because Suwayomi cannot. The schema is already owned by this project
in `TachimangaSchema`, measured column for column from a real 39MB backup.

Writing it is a different job from writing the `.tachibk`, and it is a larger one: a `.tmb` is
a zip holding a zip holding a SQLite database, and a database has constraints a protobuf does
not. Every NOT NULL, every unique index, and the exact ordering the reader depends on have to
be right, and the only way to know is to write one and feed it to the existing
`TachimangaReader`.

The advantage is that the verification is total. The project already has a reader for that
format and a builder for its fixtures, so "does Tachimanga accept this" is answerable without
a phone.

### Which to do first

`.tachibk`, clearly. It is the format the most readers want, the schema is measured and
proved, and the round trip is verifiable with tooling already in this repository.

## Things that are settled and things that are not

Settled, by measurement:

- Suwayomi's `createBackup` works, and its output is a valid `.tachibk` this project can read.
- It exports Suwayomi's library, which is 4 series, not the 867 on the shelf.
- Every field the format needs is available through the API.
- The field numbers are measured and documented, not published.

Not settled:

- Whether Suwayomi can return many manga in one query. This decides whether the export is a
  few hundred requests or a few thousand, and it should be the first thing checked.
- Whether `isRead` from the API is authoritative or whether Papyrus's own `chapter_states`
  should win where they disagree. This matters and is not obvious: Papyrus holds 21,447
  chapter states of its own, and after an import those may not match Suwayomi.
- What to do about the 195 shelf rows with no `suwayomi_manga_id`. They are series the reader
  can see and that cannot be exported, and the report has to say so rather than silently
  producing a smaller library.

That second one is the real design question in this feature, and it deserves an answer before
any code is written.