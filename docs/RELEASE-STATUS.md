# Release status

Updated 2026-11-01.

- **1.5.3 is built and tagged locally.** It fixes the fresh-install failure in 1.5.2, carries
  the security work finished since that release, and adds the update contract. It is built from
  `ff5d607` and tagged `v1.5.3`. Publication waits for the fresh-install manual sandbox.
- **1.5.3 artifacts.** The installer is 245.6 MB, SHA-256
  `3cd8f348bfa0852364fa70faae623141e9a5f9f47f78a2b798077ad3f34fb93a`; the portable is
  285.6 MB, SHA-256
  `5b1875999ac01153ad570630c90d5c361437ca14d8f063ef90e83cd2d30d63bd`. Both name
  `ff5d607`, and [`releases/1.5.3.json`](../releases/1.5.3.json) carries both.
- **1.5.2 has a fresh-install regression.** A new install of 1.5.2 stops before the first
  migration because it tries to snapshot a database that does not exist yet. Existing 1.5.2
  installs can upgrade to 1.5.3. The 1.5.2 release page carries a warning.
- **The repository is public.** The old private repository is
  `graynb25/papyrus-private`; its pull-request ref stays private and is not part of the
  public repository. Private vulnerability reporting is enabled on the public one.
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
  release record says `signed: false`.
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
- **1.5.2 build and tag, 2026-11-01.** Built from `090c096` and tagged `v1.5.2`. The
  installer is 245.6 MB, SHA-256
  `7e59891e17567a265a3a7f8e0dc263ef678dfda17fa4b211107804764f90e858`; the portable
  is 285.5 MB, SHA-256
  `dcfa1f015cb5386fd98cff51951056ac369c21689e469745cb41ef97da846161`. Both name
  `090c096`, and [`releases/1.5.2.json`](../releases/1.5.2.json) carries both. The
  release page carries the installer, the portable and its sidecar; GitHub reports the
  same SHA-256 digests.
- **Update contract, 2026-11-01.** The launcher records the pre-migration snapshot and adds
  `papyrus rollback`; the installer refuses a downgrade unless it is run with
  `/FORCEDOWNGRADE`, and it aborts if Papyrus is still running after `stop`. 1.5.3 carries
  it. The About page links the release Atom feed.
- **Still open.** The fresh-install manual sandbox for 1.5.3. D10 and the WP2-S4 runtime
  drills. DEP-01, DEP-04, DEP-05, DEP-06, DEP-09, the COR batch and the CQ batch remain for
  1.5.4. The WP2-S7 sandbox install is the manual run in progress.
