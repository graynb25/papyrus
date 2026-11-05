# Release status

Updated 2026-11-04.

- **1.5.5 is published.** It was built from `13452d2` and released as `v1.5.5`.
  The installer is 257,605,072 bytes, SHA-256
  `79c4e37b7148e810c9188b383d41cb0fc9f95f0f33c6dc927f887f516d5ab748`; the portable is
  299,505,989 bytes, SHA-256
  `c9d3ab78e16ee2b6e9eda80b9b5622d3c336640263c1df5cd39bdfbf1cca4bf9`. The full suite
  passed 3,001 tests with 9,491 assertions, and `composer audit` is clean after the
  Symfony 8.1.11 update. A host portable walk on 2026-11-04 passed steps 1 to 9 on the
  `e49dbdf` build: empty library, store, trust, Available with Minimize, MangaTaro
  install, search One Piece, source chooser, series, Add to library, a real chapter 1
  page, Done, and no tour after reload; the database recorded `tour_completed_at`.
  Replay from Settings > FAQ restarted at step 2 and Skip completed it again. A manual
  installer run on `bd9f8e2` verified the installer hash, installed per user, launched
  from the setup wizard, and registered the first account. At a 940x1030 viewport the
  step 1 and step 2 cards were fully visible with Next and Skip, which confirms the
  short-window fix. The final `13452d2` installer was smoke-tested too: hash verified,
  installed per user, launched from the wizard, and rendered the login page. The full
  nine-step tour flow was not repeated end to end in the guest because synthetic mouse
  input on the card's own buttons became unreliable after a DevTools prompt; the host
  walk covers the flow. The manual runs are manual evidence, not a gate pass. The
  release is https://github.com/graynb25/papyrus/releases/tag/v1.5.5.
- **1.5.4 is published.** It was built from `730225f`, recorded and tagged at
  `b37a6de` as `v1.5.4`, and published on 2026-11-04. The installer is 257,582,579
  bytes, SHA-256
  `e5bf56bbf6a6e67bc2626d6175c866206f0fab08d31678b4dc6eae8901386f27`; the portable is
  299,480,441 bytes, SHA-256
  `e53b5c859f649db1aca8c4057a5fa12cf7f4c48e10c7836c4b751f5fd2877748`. Both name
  `730225f`, and [`releases/1.5.4.json`](../releases/1.5.4.json) carries both. The
  release is https://github.com/graynb25/papyrus/releases/tag/v1.5.4 and CI run
  `41895466635` passed in 15m5s.
- **The 1.5.4 final-build manual sandbox passed on 2026-11-04.** The guest verified the
  installer at 257,582,579 bytes, installed per user, registered, added a store, trusted
  its signing key, installed MangaTaro, searched One Piece (14 results from 2 of 2
  runnable sources), opened the source chooser, read chapter 1 of 1,203 with a real page,
  and saw Chapter 001 marked read. Session
  `build/manual-sandbox-results/session-20261104-133727`. The ten second watcher frame at
  the reader caught the page while it was loading; the owner confirmed the real page
  rendered and the series frame records the read state.
- **PR-03 shipped-runtime gate, 2026-11-04.** The full suite passed on the final
  install's PHP 8.5.12 with a resolved copy of its `php.ini`: 2,978 tests, 2,978 passed,
  9,368 assertions, exit 0. The gate ran `vendor/bin/phpunit`, not `artisan test`.
- **The 1.5.4 copy scrub** removed the store name and URL from the app, docs and
  tracked harnesses. The Sources page now says `Sources, on your terms.` and the
  add-store helper names only index file shapes. The sandbox takes the URL from the
  operator through `-StoreUrl` or `PAPYRUS_TEST_STORE_URL`.
- **1.5.3 is published.** Built from `5ae2794`, tagged `v1.5.3`, CI run
  `41385058266` passed in 15m35s. Its artifacts are in
  [`releases/1.5.3.json`](../releases/1.5.3.json).
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
- **Still open for 1.5.4.** The automated Windows Sandbox gate on this build, the
  upgrade and rollback drills, the portable and uninstall drills, and the ahead-schema
  refusal. Publication is done.

