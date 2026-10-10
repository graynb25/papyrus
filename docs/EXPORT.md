# Exporting to another reader

Companion to [`IMPORT-ANALYSIS.md`](IMPORT-ANALYSIS.md), which covers reading other apps'
backups. This covers the other direction: producing a file that Mihon, Tachiyomi or Suwayomi
can import, so a reader can take their library to a phone.

Status: **designed, not built.** Phase 0 packaging comes first; see
[`DESKTOP-PACKAGING.md`](DESKTOP-PACKAGING.md).

## The finding that decides the design

**Suwayomi can already export a `.tachibk`, and Papyrus should not use it.**

That is the opposite of the conclusion the investigation started with, and the reason is a
measurement rather than an argument.

Everything needed is there. `createBackup` exists, it works when called through the
application's own Gateway, and the file it produces reads perfectly with the `TachibkReader`
this project already has:

```
createBackup(input: { flags: { includeManga, includeCategories, includeChapters,
                               includeHistory, includeTracking, includeClientData,
                               includeServerSettings } }) { url }
  -> /api/graphql/files/backup/org.suwayomi.tachidesk._2026-10-10_16-09.tachibk

76,633 bytes, gzip, recognised by TachibkReader
library_series 4    chapters 2,911    chapters_read 1,616
```

That is a working export, and the round trip through our own reader is proof the file is well
formed. On the face of it the feature is one HTTP call and a download link.

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

## The format is not the obstacle

Every field a `.tachibk` needs is available through the API:

| Needed | From |
| --- | --- |
| series source id, key, title, author, artist, description, genres, status, cover | `MangaType` |
| chapter key, name, scanlator | `ChapterType.url`, `.name`, `.scanlator` |
| chapter number, fractional | `ChapterType.chapterNumber`, a `Float` |
| read flag | `ChapterType.isRead` |

The field numbers are already measured and written down in `TachibkSchema`, from real bytes
rather than a published schema, so the writer is the inverse of a reader that is already proved
against a real file.

## The honest cost, which is scale not format

867 series and the chapters behind them is a lot of API traffic. A naive walk is one query per
series and one per chapter list, and at this size that is a request count a reader notices and
a timeout budget that has to be designed for.

1. **Check first whether Suwayomi can return many manga in one query.** `manga` takes a
   required `id` and the committed snapshot shows no obvious batch form. This decides whether
   the export is a few hundred requests or a few thousand, and it is the first thing to answer.
2. **Reuse what Papyrus already holds.** `series_states` carries the title and the Suwayomi id
   for 672 of 867, so the series half of the export needs no API call for those. Only chapters do.
3. **Make it a queued job with progress**, which the import side already has the shape of. An
   export of 867 series takes minutes and should say so rather than spinning.

## Merged read state

Papyrus never writes read state to Suwayomi. There is no mutation for it anywhere in the
codebase; the only mutations are extensions, search, downloads, covers, auth, series metadata
and AniList. The two read states are therefore genuinely independent, and since Suwayomi's
library is 4 series, its read state covers almost nothing.

**The rule: a chapter is read in the export if either side says read.**

| Field | Rule | Why |
| --- | --- | --- |
| `is_read` | true if either says true | a read is monotonic work, and an un-read is irreversible loss of progress |
| `last_page_read` | the larger | it is a position, and further is further |
| `read_seconds` | the larger | it is an accumulation, and the larger is the more complete record |
| `last_read_at` | the later | it is a timestamp, and the later is the more recent one |

Three reasons, in order of weight.

**It is already this project's stated principle.** `IMPORT-ANALYSIS.md` records, for imports:
"A read is monotonic work and an un-read is irreversible loss of progress, so the safe
direction is the one that only ever adds." The import side and the export side should not
disagree about what a read is, and a special case on one side is harder to explain to a reader
than the same rule twice.

**The asymmetry is brutal in practice.** A spurious read is one tap to undo on a phone, and a
reader may not notice it for a week. A lost read is gone, and on a phone with no Papyrus there
is nothing to recover it from.

**The evidence makes the intersection indefensible here.** Taking the intersection would
export a 867 series library that reads as almost entirely unread, because Suwayomi only knows
about 4 of them.

### How it is verified, so it can be checked later

The merge is a rule that can be silently wrong, so it has to be falsifiable. The run report
records, per series and in total:

- how many chapters were read on both sides, so agreement is visible
- how many were read only by Papyrus
- how many were read only by Suwayomi

If the Suwayomi-only count is zero, as the evidence predicts, the merge did nothing and the
report says so rather than implying it protected something.

## Plan: `.tachibk`

### 1. A protobuf writer

`ProtoBuffer` currently only reads. The writer is the natural companion and the encoder already
exists in `tests/Support/BuildsTachibkBackups.php`, written to build fixtures. Lifting it into
`app/Import/Tachibk/ProtoWriter.php` rather than writing a second one is the obvious move, and
the fixture builder then uses the shipped writer, which means every fixture in the suite is
produced by the code under test.

Risk: near zero. The field numbers are measured and the reader is proved.

### 2. A run record

A new `export_runs` table mirroring `import_runs`: state, counts, started and finished,
progress note, and the path to the artifact. This is what makes a queued export reportable at
all, and it is one migration.

The alternative, reusing `import_runs`, couples two different operations in one table and
makes every import report ambiguous. Not worth it.

### 3. The job

`App\Jobs\ExportBackup`, dispatched from a settings page.

```
load the shelf              867 rows, no API calls for 672 of them
resolve each series         batched, or one at a time with a concurrency cap
load Papyrus chapter_states keyed by suwayomi_chapter_id
merge per the table above
write the protobuf          streaming, so memory is one series not the library
write the artifact          %LOCALAPPDATA%\Papyrus\exports\<timestamp>.tachibk
record the run              including the omission report
```

Progress is reported by series count, because that is the only number the reader can picture.

### 4. The omission report

Not a nicety. An export that silently produces a smaller library than the reader sees is the
failure mode this whole design is trying to avoid, and it is easy to hit here because 195
shelf rows already have no `suwayomi_manga_id`.

The report names, by title:

- series on the shelf that could not be resolved, **195 today**
- series whose Suwayomi lookup failed, and why
- series that resolved but carry no chapters
- the merge audit from the section above
- **what the format cannot carry**: shelves, notes, source preferences and excluded scanlators
  are Papyrus-only and do not exist in `.tachibk`

That last one has to be said before the reader exports, not discovered afterwards.

### 5. Verification

| Check | What it proves |
| --- | --- |
| write then read with the existing `TachibkReader` | the writer is the inverse of a proved reader |
| mutation check on the merge rules | each rule is load bearing, and none is inert |
| feed to Suwayomi's `restoreBackup` on a scratch instance | total, and only for a real run |
| a fixture where the two sides disagree | the union is doing something and the audit says how much |

The first three are automatable. The fourth is what makes the merge falsifiable.

## Plan: `.tmb`, for Tachimanga

Only Papyrus can do this, because Suwayomi cannot. The schema is already owned by this project
in `TachimangaSchema`, measured column for column from a real 39MB backup.

### Why it is a different job

A `.tachibk` is a protobuf message and a wrong field is a missing field. A `.tmb` is a zip
holding a zip holding a **SQLite database**, and a wrong column is a constraint violation on
insert. Every NOT NULL, every unique index and the exact row ordering the reader depends on has
to be right, and the only way to know is to write one and feed it to the reader.

### The shape

```
papyrus-export-<timestamp>.tmb        outer zip
  meta.json                           version, counts, when
  contents.zip                        inner zip
    library.db                        SQLite, the reader's own schema
```

### What has to be reproduced

From `TachimangaSchema`, the measured one:

| Requirement | Why it bites |
| --- | --- |
| `in_library = 1` only | the file carries 132,185 manga rows of which 1,277 are the library, and the reader filters on this |
| `source_order` present and unique per chapter | it is the pairing key, and a duplicate breaks resolution |
| mixed timestamp units | `Manga.in_library_at` is seconds, `Category.create_at` is milliseconds, and reading one as the other gives 1970 |
| the history table's own column name | `History.manga_id` where its siblings say `manga` |

Getting the last two wrong produces a file that opens and reads as empty, which is the shape of
failure that survives review.

### Verification, and its honest limit

The project already has a `TachimangaReader` and a fixture builder. So the loop is: write one,
read it back, assert the counts and the read state survive. That is total and automatable.

**And it is not proof.** Our reader reading our writer proves the two agree with each other, not
that Tachimanga agrees with either. A schema can be self-consistently wrong. The real
verification needs Tachimanga itself, and until that happens the honest claim is "written
against the measured schema of a real backup, and round trips through our own reader", not "Tachimanga
compatible".

### Order

`.tachibk` first. It is what the most readers want, its schema is measured and proved, and its
verification is complete. The `.tmb` writer is worth doing afterwards and is the larger of the
two by some distance.

## Open questions

1. **Can Suwayomi return many manga in one query?** Decides request volume and whether progress
   reporting is meaningful.
2. **Has anyone verified a merge on a phone yet?** The rule is sound and the audit makes it
   falsifiable, but the first real check will be a reader importing into Mihon and comparing a
   chapter they read in Papyrus. Worth doing before trusting it with a full library.
3. **What should the export do about the 195 unresolved series?** Reporting them is settled.
   Whether to try harder, for example a shelf-only file, is not.