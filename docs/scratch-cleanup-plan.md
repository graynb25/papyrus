# Phase 9 item 3: scratch cleanup of the review account

Planned, measured, and rehearsed only. Nothing is deleted in this sitting. No application code is changed.

## Steps

- [x] 1. Take the fallback backup (VACUUM INTO) and record its hash and row counts.
- [x] 2. Re-run the rehearsal on a fresh copy and confirm pass criteria.
- [x] 3. Obtain owner go for the live delete and the row-2 decision (Option A recommended).
- [x] 4. Execute the deletion transaction against the live database.
- [x] 5. Verify: user 5 gone, user 1 counts unchanged, integrity ok, FK check 0, row-2 decision applied.
- [x] 6. Confirm the standing stack still answers.
- [x] 7. Record the cleanup in the findings register and AGENTS.md.

## 1. Problem statement

The checkout's own development database (`database/database.sqlite`) carries a throwaway reader account created for the walk work: user 5, `review-agent-20261016@localhost.test`, "Review Agent (scratch 2026-10-16)". It owns 17 series states (including Eleceed at manga 10), 8 chapter states, 7 reading sessions, 19 bulk migrations with 97 items (78 of them pending), 91 migration searches, 7 series migrations, 26 library checks, 25 search runs with 1,346 results, 9 user sources, 1 user extension store, 1 user setting (`adult_content = false`), and one refused live backup run (row 2). This is the F11 family: a throwaway account in the dev database that must not ship.

Two things make the cleanup matter:

1. The account's rows are walk noise, not reader data. Leaving them in place means the standing numbers (884 series states, 21,456 chapter states, ledger 1,277, 14 import runs, 7 reading sessions, 19 bulk runs) are inflated by throwaway rows and cannot be read as the standing seat's own.
2. `live_backup_runs` row 2 is a refused run owned by user 5. Its `started_by_user_id` foreign key is `ON DELETE SET NULL`, so deleting the user leaves an ownerless row. The decision whether to keep it as a null-owner audit record or remove it is part of this cleanup and is recorded here.

The standing seat is user 1 (`papyrus@example.test`). Its data (867 series states, 21,448 chapter states, 1,277 imported series, 14 import runs, 1 AniList account) must be untouched by this cleanup. The Suwayomi server on 127.0.0.1:4567 is not touched.

## 2. Measured inventory

Measured from the checkout's own database through a read-only PDO open (`sqlite:file:...?mode=ro`). No write connection was opened for measurement. Counts are for the database as of 2026-10-22.

### 2.1 Tables referencing users

Every table with a `user_id` column or a foreign key into `users`. `started_by_user_id` is the only non-`user_id` reference (on the three `live_*_runs` tables).

| Table | Total rows | User 5 rows | User 1 rows | Null/ownerless | FK on-delete |
|---|---:|---:|---:|---:|---|
| users | 2 | 1 (id 5) | 1 (id 1) | 0 | (parent) |
| series_states | 884 | 17 | 867 | 0 | CASCADE |
| chapter_states | 21,456 | 8 | 21,448 | 0 | CASCADE |
| reading_sessions | 7 | 7 | 0 | 0 | CASCADE |
| bulk_migrations | 19 | 19 | 0 | 0 | CASCADE |
| bulk_migration_items | 97 | 97 | 0 | 0 | CASCADE |
| bulk_migration_targets | 25 | 25 | 0 | 0 | CASCADE |
| migration_searches | 91 | 91 | 0 | 0 | CASCADE |
| series_migrations | 7 | 7 | 0 | 0 | CASCADE |
| library_checks | 698 | 26 | 672 | 0 | CASCADE |
| search_runs | 26 | 25 | 1 | 0 | CASCADE |
| search_results | 1,595 | 1,346 | 249 | 0 | CASCADE |
| search_source_results | 205 | 145 | 60 | 0 | CASCADE |
| user_sources | 9 | 9 | 0 | 0 | CASCADE |
| user_extension_stores | 2 | 1 | 1 | 0 | CASCADE |
| user_settings | 1 | 1 | 0 | 0 | CASCADE |
| anilist_accounts | 1 | 0 | 1 | 0 | CASCADE |
| import_runs | 14 | 0 | 14 | 0 | CASCADE |
| imported_series | 1,277 | 0 | 1,277 | 0 | CASCADE |
| imported_sources | 520 | 0 | 520 | 0 | CASCADE |
| categories | 0 | 0 | 0 | 0 | CASCADE |
| category_series | 0 | 0 | 0 | 0 | CASCADE |
| downloads | 0 | 0 | 0 | 0 | CASCADE |
| notes | 0 | 0 | 0 | 0 | CASCADE |
| passkeys | 0 | 0 | 0 | 0 | CASCADE |
| reader_profiles | 0 | 0 | 0 | 0 | CASCADE |
| series_covers | 0 | 0 | 0 | 0 | CASCADE |
| licence_acknowledgements | 0 | 0 | 0 | 0 | CASCADE |
| live_backup_runs | 2 | 1 (row 2) | 1 (row 1) | 0 | SET NULL |
| live_restore_runs | 0 | 0 | 0 | 0 | SET NULL |
| live_export_runs | 0 | 0 | 0 | 0 | SET NULL |
| sessions | 15 | 0 | 0 (2 rows for users 11, 12) | 13 | none (no FK) |

### 2.2 Foreign keys and on-delete actions

All `user_id` foreign keys are `ON DELETE CASCADE`. The three `live_*_runs` tables use `started_by_user_id -> users.id ON DELETE SET NULL`. The `sessions` table has no foreign key (nullable `user_id`). `imported_series.series_state_id -> series_states.id` is `ON DELETE SET NULL`. `category_series` has additional cascades to `categories` and `series_states`.

`PRAGMA foreign_keys` defaults to off (0) on this database. Cascades only run when a connection sets `PRAGMA foreign_keys = ON`. The deletion plan enables it.

### 2.3 User 5 walk artifacts (to be removed with the account)

- Eleceed at series_state id 894, manga 10, in_library = 1, created 2026-10-19 17:40:53.
- reading_sessions rows 1 to 7, all on manga 10 (Eleceed), row 2 at 60 seconds, row 6 at 68 seconds and 1 page.
- bulk_migrations 1 to 19, with 97 items: 6 moved, 78 pending, 6 refused, 7 skipped. Run 7 carries seven pending offers at item ids 25 to 31 (One Piece, Dandadan, SPY x FAMILY, Chainsaw Man, Sousou no Frieren, Naruto, Jujutsu Kaisen).
- 25 bulk_migration_targets, 91 migration_searches, 7 series_migrations.
- 26 library_checks, 25 search_runs (1,346 search_results, 145 search_source_results).
- 9 user_sources, 1 user_extension_stores, 1 user_settings (`adult_content = false`).

### 2.4 live_backup_runs row 2 (the refused run)

| Column | Value |
|---|---|
| id | 2 |
| started_by_user_id | 5 |
| token | NULL |
| state | refused |
| include_downloads | 0 |
| progress_note | NULL |
| refusal_reason | "The downloaded manga half is a real backup of a library that holds nothing: the server answered with a file that carries its own shelves and no series at all, so there is no manga half to pair with the reader half. The run was refused with nothing taken. That file is not a Mihon or Suwayomi backup. It has to be the .tachibk that one of those apps writes from its own backup screen." |
| suwayomi_version | NULL |
| downloads_bytes | NULL |
| series_states | NULL |
| chapter_states | NULL |
| manga_summary | NULL |
| started_at | 2026-10-22 17:33:11 |
| finished_at | 2026-10-22 17:33:12 |
| created_at | 2026-10-22 17:33:11 |
| updated_at | 2026-10-22 17:33:12 |

Row 1 (the standing seat's completed backup) is untouched: id 1, started_by_user_id 1, state complete, 884 series states and 21,456 chapter states.

### 2.5 Standing numbers that must survive (user 1)

users 1, series_states 867, chapter_states 21,448, imported_series 1,277, import_runs 14, anilist_accounts 1, live_backup_runs 1 (row 1), reading_sessions 0 (user 1 never had any), bulk_migrations 0, licence_acknowledgements 0, categories 0, category_series 0.

## 3. Risk assessment

Severity: S data loss or credentials; H feature does not work or gate cannot detect failure; M correct but costly or hard to verify; L housekeeping.

### 3.1 Per-table risk

| Table | Data-loss risk | Cascade risk | Orphan risk | Walk-artifact risk | Severity |
|---|---|---|---|---|---|
| users (id 5) | S if wrong user deleted | parent | none | none | S |
| series_states (user 5, 17) | M (intended removal) | CASCADE ok | none | Eleceed manga 10 | M |
| chapter_states (user 5, 8) | M | CASCADE ok | none | read state | M |
| reading_sessions (user 5, 7) | M (walk artifact) | CASCADE ok | none | rows 1-7 | M |
| bulk_migrations + items + targets (user 5) | M (walk artifact) | CASCADE ok | none | runs 1-19, 78 pending, 7 offers at 25-31 | M |
| migration_searches (91) | L | CASCADE ok | none | none | L |
| series_migrations (7) | L | CASCADE ok | none | none | L |
| search_runs/results/source_results (user 5) | M (volume) | CASCADE ok | none | none | M |
| library_checks (26) | L | CASCADE ok | none | none | L |
| user_sources (9) | L | CASCADE ok | none | none | L |
| user_extension_stores (1) | L | CASCADE ok | none | none | L |
| user_settings (1, adult_content) | L | CASCADE ok | none | none | L |
| live_backup_runs row 2 | H (audit trail of refused run) | SET NULL (not cascade) | becomes ownerless | refused run evidence | H |
| live_backup_runs row 1 | S if touched | n/a (user 1) | n/a | none | S |
| imported_series / import_runs (user 1) | S if touched | n/a | n/a | ledger 1,277, 14 runs | S |
| sessions | L (user 5 has none) | no FK | orphaned rows for deleted users 11, 12 remain | none | L |

### 3.2 Per-step risk

| Step | Risk | Severity | Notes |
|---|---|---|---|
| Fallback backup (VACUUM INTO) | data loss if backup is corrupt or incomplete | M | Verified by integrity_check, FK check, row counts, hash |
| Rehearsal on temp copy | none to live data | L | copy only |
| Live delete transaction | irreversible if wrong user or wrong rows | S | gated by rehearsal proof and read-back verification |
| live_backup_runs row 2 decision | audit-trail loss if removed; ownerless row if kept | H | decision recorded in section 6.3 |
| Verification | false confidence if check is wrong | M | read back from disk, counts compared |

### 3.3 What must not be touched

- User 1 (`papyrus@example.test`) and all its rows: 867 series states, 21,448 chapter states, 1,277 imported series, 14 import runs, 1 AniList account, live_backup_runs row 1.
- The Suwayomi server data (H2, port 4567).
- The screenshot files under `.openchamber/screenshots/`.
- The standing stack (Herd nginx on 80, php on 8017, java on 4567).

## 4. Rehearsal plan

Goal: prove the deletion SQL on a copy before any live change.

### 4.1 Setup

- Temp folder: `C:\Users\graynb25\AppData\Local\Temp\opencode\scratch-cleanup-rehearsal\`.
- Copy the checkout's database to the temp folder as a single clean file using SQLite's `VACUUM INTO` (atomic, no sidecar files, no WAL dependency). This is the repository's own snapshot style (the app uses `VACUUM INTO` for reader-half snapshots).
- Record the copy's SHA-256 hash before the delete.
- Record before-counts for every table in section 2.1 plus the walk artifacts (Eleceed series state, reading_sessions 1-7, bulk items 25-31 pending, 78 pending items).

### 4.2 Delete (on the copy only)

Run the exact SQL from section 6 inside one transaction with `PRAGMA foreign_keys = ON`. Children before parents. See section 6.2 for the ordered statements. The live_backup_runs row 2 decision is applied per section 6.3.

### 4.3 Verify the copy

- `PRAGMA integrity_check` returns `ok`.
- `PRAGMA foreign_key_check` returns 0 rows.
- User 5 is gone (`users` count 1, no row with id 5).
- Every user 5 row count is 0 in every table in section 2.1.
- User 1 counts are unchanged: series_states 867, chapter_states 21,448, imported_series 1,277, import_runs 14, anilist_accounts 1, live_backup_runs row 1 present and complete.
- Walk artifacts that belong to user 1 (867 series states, 21,448 chapter states, 1,277 ledger rows, 14 import runs) read back identical.
- The live_backup_runs row 2 decision is applied (started_by_user_id NULL if kept; row absent if removed).
- Record after-counts and after-hash. The hash must differ from the before-hash (proving the delete ran) and the row counts must match section 5's expected values.

### 4.4 Rehearsal pass criteria

All of the following must hold for the rehearsal to pass:

1. The delete transaction commits (no rollback).
2. `integrity_check` = `ok` after.
3. `foreign_key_check` = 0 rows after.
4. User 1 counts unchanged to the row.
5. User 5 counts are 0 in every table.
6. The row-2 decision is applied and visible.
7. The before/after counts and hashes are written to disk in the temp folder and read back, not asserted from memory.

### 4.5 Rehearsal result (already run)

Rehearsed on 2026-10-22 against a VACUUM INTO copy. Before hash: `c21a4ae187e8aa1ea0e032304042f2b6e7fb94d8fa1706868546614aebd9242f`. After hash (keep-as-null-owner): `0fe0703ecf5a948a3b51d50731207f194831fa1601bb9f6e1ab26eb2ff15edeb`. Integrity ok, FK check 0, user 1 counts unchanged, user 5 gone. The alternative (remove row 2) was also rehearsed and passes (after hash `d70b76f2fd96ba96b77970fce011b33be0bab85e4af91e15252bbfa949d39193`, live_backup_runs count 1).

## 5. Fallback plan

A verified backup of the checkout's own database taken immediately before the delete.

### 5.1 Backup

- Create the backup with SQLite's `VACUUM INTO` (the app's own snapshot style) into a dated file in `database/reader-snapshots/` or a temp folder, e.g. `database/reader-snapshots/pre-scratch-cleanup-<timestamp>.sqlite`. `VACUUM INTO` produces a single self-consistent file with no WAL sidecar, so it is safe to copy and restore.
- Record the SHA-256 hash of the backup file.
- Record the row counts from section 2.1 against the backup.

### 5.2 Restore path

If the delete goes wrong:

1. Stop any writer to the checkout database (the dev workers and Herd).
2. Replace `database/database.sqlite` (and remove any `.sqlite-wal` / `.sqlite-shm`) with the backup file.
3. Restart the workers.

### 5.3 Fallback verification

- `PRAGMA integrity_check` returns `ok` on the backup.
- `PRAGMA foreign_key_check` returns 0 rows.
- Row counts on the backup match section 2.1 exactly (users 2, series_states 884, chapter_states 21,456, imported_series 1,277, import_runs 14, live_backup_runs 2, etc.).
- SHA-256 of the backup file matches the recorded hash.

## 6. Deletion plan

### 6.1 Transaction boundaries

- Open one connection with `PRAGMA foreign_keys = ON`.
- `BEGIN IMMEDIATE`.
- Run all deletes in section 6.2 in order.
- Apply the row-2 decision from section 6.3.
- `COMMIT`.
- On any exception, `ROLLBACK` and abort; nothing is committed.

### 6.2 Ordered deletes (children before parents)

All statements are `WHERE user_id = 5` unless noted. Run inside the transaction above.

1. `DELETE FROM bulk_migration_items WHERE user_id = 5;`
2. `DELETE FROM bulk_migration_targets WHERE user_id = 5;`
3. `DELETE FROM bulk_migrations WHERE user_id = 5;`
4. `DELETE FROM migration_searches WHERE user_id = 5;`
5. `DELETE FROM series_migrations WHERE user_id = 5;`
6. `DELETE FROM search_results WHERE user_id = 5;`
7. `DELETE FROM search_source_results WHERE user_id = 5;`
8. `DELETE FROM search_runs WHERE user_id = 5;`
9. `DELETE FROM library_checks WHERE user_id = 5;`
10. `DELETE FROM reading_sessions WHERE user_id = 5;`
11. `DELETE FROM chapter_states WHERE user_id = 5;`
12. `DELETE FROM series_states WHERE user_id = 5;`
13. `DELETE FROM category_series WHERE user_id = 5;`
14. `DELETE FROM categories WHERE user_id = 5;`
15. `DELETE FROM user_sources WHERE user_id = 5;`
16. `DELETE FROM user_extension_stores WHERE user_id = 5;`
17. `DELETE FROM user_settings WHERE user_id = 5;`
18. `DELETE FROM downloads WHERE user_id = 5;`
19. `DELETE FROM anilist_accounts WHERE user_id = 5;`
20. `DELETE FROM notes WHERE user_id = 5;`
21. `DELETE FROM passkeys WHERE user_id = 5;`
22. `DELETE FROM reader_profiles WHERE user_id = 5;`
23. `DELETE FROM series_covers WHERE user_id = 5;`
24. `DELETE FROM licence_acknowledgements WHERE user_id = 5;`
25. Apply the row-2 decision (section 6.3).
26. `DELETE FROM users WHERE id = 5;`

The `live_*_runs` rows for user 5 other than row 2 do not exist (live_restore_runs and live_export_runs are empty). `sessions` has no row for user 5, so no delete is needed there.

### 6.3 live_backup_runs row 2 decision

Option A (recommended): keep row 2 as a null-owner record. Do nothing extra; deleting user 5 fires the `ON DELETE SET NULL` foreign key and `started_by_user_id` becomes NULL. The refused run's reason, timestamps, and state survive as an audit record of the refused backup attempt. This preserves the evidence the import-progress and backups work depends on.

Option B: remove row 2 explicitly with `DELETE FROM live_backup_runs WHERE id = 2;` before deleting the user. This leaves `live_backup_runs` with only row 1 and no ownerless row, at the cost of losing the refusal record.

Recommendation: Option A. The foreign key is `SET NULL` by design, which signals that ownerless run records are expected to persist. The refusal reason is a measured fact worth keeping. Option B is rehearsed and passes if the owner prefers a clean table.

### 6.4 What the standing seat's data is NOT touched by

User 1's rows are not in any of the `WHERE user_id = 5` statements. The only statement that touches `users` deletes by `id = 5`. The live_backup_runs statements target row 2 only (or none, under Option A). No statement touches imported_series, import_runs, anilist_accounts (user 1's), or live_backup_runs row 1.

## 7. Verification after the delete

1. `SELECT COUNT(*) FROM users` = 1, and no row with id 5 or email `review-agent-20261016@localhost.test`.
2. Every table in section 2.1 reads 0 for user 5.
3. User 1 counts unchanged: series_states 867, chapter_states 21,448, imported_series 1,277, import_runs 14, anilist_accounts 1, library_checks 672, search_runs 1, search_results 249, search_source_results 60, user_extension_stores 1, live_backup_runs row 1 present and complete.
4. `PRAGMA integrity_check` = `ok`.
5. `PRAGMA foreign_key_check` = 0 rows.
6. Row-2 decision applied: started_by_user_id is NULL (Option A) or row 2 absent (Option B).
7. The standing stack still answers: java on 127.0.0.1:4567, php on 127.0.0.1:8017, Herd nginx on 80.
8. Walk artifacts that belong to the standing seat read back identical (867 series states, 21,448 chapter states, 1,277 ledger rows, 14 import runs, Eleceed is not a standing artifact and is removed with user 5).

## 8. What this plan does not cover

- The sandbox gate (parked on the owner's word, eleven deaths to 0x80370106).
- The push (owner's decision, nothing pushed).
- The UI/UX pass (already complete as Phase 9 units 1 and 2).
- Any change to application code.
- Re-minting a review account for future walks (a separate task).

## 9. House rules that bind

- No em or en dashes anywhere in this document or in any commit message about it.
- DRY: one deletion transaction, one seam.
- Fail loudly: the delete aborts and rolls back on any exception; verification reads from disk.
- No know-it-all framing.
- No push.
- Measure everything from the database, not from memory. Every count in section 2 was read from the checkout's own database through a read-only connection.
