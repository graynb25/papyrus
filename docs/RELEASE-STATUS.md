# Release status

Updated 2026-11-01.

- **1.5.2 is in preparation from the rewritten tree.** `VERSION` is 1.5.2. It is not
  published until the release build, signing and tag decisions are complete.
- **1.5.1 was built and proven locally, but it was not published.** Do not publish it.
  The installer and portable runs passed manual sandbox acceptance on 2026-10-29. The
  record at [`releases/1.5.1.json`](../releases/1.5.1.json) is a local artifact record,
  not a published release.
- **The public history was rewritten on 2026-11-01.** The rewrite removed the
  `storage/design-review` screenshots and the measured machine-identity classes. The
  pre-rewrite history is in an owner-held offline archive for 90 days. GitHub still
  exposes the closed PR #2 head ref, which contains the design-review commit; the
  rewrite reduces reachability, it is not an erasure guarantee. The earlier local
  artifact remap on 2026-10-30 is separate; both are recorded in
  [`DISTRIBUTION.md`](DISTRIBUTION.md).
- **CI baseline.** Normal `tests` runs on 2026-10-13 and 2026-10-14 were 8m24s to
  13m19s. The 46m28s to 1h20m24s runs on 2026-10-14 and 2026-10-15 were a
  full-database mistake, not the baseline. The suite uses `DB_DATABASE=:memory:` and
  a test enforces it.
- **D4 signing, 2026-11-01.** Build 1.5.2 unsigned. The only signing material is a
  self-signed development certificate, which does not remove the Windows warning. The
  release record will say `signed: false`.
- **D5 Flux licence, 2026-11-01.** Keep `livewire/flux`. No redistributable licence text
  is staged; the in-app licences page names it proprietary.
- **PR-03 shipped-runtime gate, 2026-11-01.** The full suite passed on the install's
  PHP 8.5.12 with a resolved copy of its `php.ini`: 2,678 tests, 2,677 passed, 1 skip,
  7,939 assertions, exit 0. The gate runs `vendor/bin/phpunit`, not `artisan test`,
  because the latter starts PHPUnit without the `-c` ini.
- **PR-04 rollback guard, 2026-11-01.** The launcher snapshots the database before
  `migrate --force` and refuses to continue when the snapshot fails. `/ready` reports
  `schema_ahead` for migrations the build does not know, and the supervisor refuses to
  start against that database. The checkout suite passed 2,678 tests with 7,939
  assertions.
- **Still open.** The 1.5.2 release build and tag, and D6 to D10. Phase 2's DEP-02,
  DEP-03 and DEP-08 are also open; this release does not claim them.
