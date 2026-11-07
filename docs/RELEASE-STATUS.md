# Release status

Updated 2026-11-07.

- **1.5.9 is published.** It adds the immediate
  extension-update refresh and the `Update all` control on the Installed sources view. The
  full suite on the packaged PHP 8.5.12 passed 3,193 tests with 57,459 assertions, and the
  six gates passed. The automated Windows Sandbox gate passed every check on 2026-11-07,
  including registration, the sources page, MangaTaro, One Piece, a real chapter page,
  diagnostics, HTTPS, and a clean stop. A live browser walk against a controlled Suwayomi
  GraphQL server showed two Update buttons and `Update all 2` before; one Update button and
  `Update all 1` after updating one source; and no Update buttons and no bulk control after
  Update all. The installer is 257,929,710 bytes, SHA-256
  `87bf37f597a354b46f9069949de7c33652c9bbcc97f014e5f686c0336b7a00b5`; the portable is
  299,849,028 bytes, SHA-256
  `01ef92717952332cbc3fce70386ffc586b0394a65a212fe2d17553973331b3ca`. Both are recorded
  in [`releases/1.5.9.json`](../releases/1.5.9.json). It is tagged `v1.5.9` at `83750b7`
  and published at https://github.com/graynb25/papyrus/releases/tag/v1.5.9. The uploaded
  setup, portable, and sidecar were downloaded again and their SHA-256 values match. The
  setup executable was not toured manually on a clean guest; the automated gate ran the
  packaged 1.5.9 install tree.
- **1.5.8 is published.** The UI pass merged as `8ee1c7c` on top of the
  1.5.7 tree: landing wall, library, series, reader, search, browse, sources, health,
  insights, and settings summaries, plus 24 translated sentences in Spanish, French, and
  Brazilian Portuguese. No migrations. The six gates passed on the release tree: full suite
  3,190 tests with 3,190 passing, Pint, PHPStan, dash, machine-detail and the checker
  self-test. The installer is 257,928,912 bytes, SHA-256
  `9e96713f5be7dde0ecdba289f15d7869569a702fc5e2521399320ea18173492e`; the portable
  is 299,848,123 bytes with 10,388 entries, SHA-256
  `14a2ac6e25c928f07495e42f562faeb6e4a3211a8f7eb9d38fe585dc4aff4ecb`. Both are recorded
  in [`releases/1.5.8.json`](../releases/1.5.8.json); the installer is unsigned. The automated
  Windows Sandbox gate passed 89 checks with 0 failures: sources page 200 with no failure
  callout, MangaTaro installed and listed, One Piece fetched, and page 0 of chapter 1 read
  through the authenticated proxy at 200 `image/webp` and the reference 175,432 bytes. The
  manual installer tour ran the real setup in Spanish on a clean guest through all nine tour
  steps: install, register, store, trust, Available grid, MangaTaro install, One Piece search,
  series with 1,204 chapters, and real chapter 1 reader pages, in session
  `session-20261106-215135` under the 1.5.8 release worktree with all 36 loop frames reviewed.
  CI run `42333756410` passed in 13m11s on the release tree.
  The portable zip has not been repeated on a clean guest. `VERSION` reads `1.5.8`. Release:
  https://github.com/graynb25/papyrus/releases/tag/v1.5.8.
- **1.5.7 is published.** Spanish, French, and Brazilian Portuguese are available
  as interface languages, and all three non-English catalogs are AI drafts that have had an
  independent AI language review. Native-speaker review has not been performed. Visual QA
  is complete at desktop and mobile widths for the shell, auth, library, search, sources,
  downloads, insights, health, settings appearance and backups, and the 404 page in all
  three locales. The reader page and the later tour steps were later exercised in the manual
  sandbox with a live source. The QA pass found the search filter hint
  and ComicInfo labels still in English, and the French source action buttons clipped on a
  narrow screen; all three are fixed. The Windows installer now offers the same four languages
  and the packaged launcher follows `PAPYRUS_LOCALE`, then the Windows UI language, then
  English. English remains the source language. Legal pages, the FAQ, raw source or server
  error text, and the diagnostic bundle's internal text files remain English.
  It was built from `633eb85` and released as `v1.5.7`. The installer is 257,924,136 bytes,
  SHA-256 `bf69e2f7c9a00b54cd2ac04762a45719cf164f406149d145d24c991dbc484c0e`; the portable
  is 299,842,534 bytes, SHA-256
  `3f4c34d92896aff61bc1d7322b374dc530986e56ab81909ad448a97279076a9a`. Both are recorded
  in [`releases/1.5.7.json`](../releases/1.5.7.json). The release is
  https://github.com/graynb25/papyrus/releases/tag/v1.5.7. The full suite on the packaged
  PHP 8.5.12 passed 3,177 tests with 56,542 assertions, and Pint, PHPStan, dash,
  machine-detail and the checker self-test pass. The portable zip passed a local scratch
  walk with a minted account: the login and library in Spanish, the shell, search, sources,
  downloads, insights, health, appearance and backups in French and Brazilian Portuguese,
  and the 404 page in Portuguese. No horizontal overflow appeared at 1440px. The old 1.4.0
  install was removed, preserving its data, and the 1.5.7 setup was then installed into a
  scratch directory, registered with a minted account, walked in Spanish, French and
  Portuguese, stopped, and uninstalled. The uninstall removed the program and kept the data.
  The packaged walk found and fixed the start window reporting an intentional stop as a
  failed start. The build also fixed `build-installer.php` so `--out` reaches Inno Setup as
  `/O` instead of compiling into `build/dist` and failing its own artifact check.
  The rebuilt installer was then exercised with `/LANG=spanish` in a scratch directory. It
  wrote `installer-locale.txt` with `es`, the launcher printed Spanish, the login page stayed
  `<html lang="es">` even when the request carried `Accept-Language: en-US`, and the generated
  `.env` held `APP_LOCALE=es` and `PAPYRUS_LOCALE_NEGOTIATION=false`. The uninstall removed
  the program and the marker and kept the data directory.
  The automated Windows Sandbox gate passed every check on 2026-11-06, first on the stop-fix
  build and again on the final `633eb85` build. It
  started the packaged tree through `Papyrus.cmd` on `8402`, registered the first account,
  rendered the sources page, added the extension store, installed MangaTaro, listed One
  Piece with 1,204 chapters, read chapter 1 page 0 through the app proxy (200,
  `image/webp`, 175,432 bytes, SHA-256
  `9996ffbb9d1fadac23357e56c611f93b1c07e980737592baa0658bf567574e6e`), and stopped every
  process with both ports released. Suwayomi stayed on loopback. The first manual installer
  run reached tour step 8 and a real reader page. It found three fixes now in `d94ec76`:
  the installer language is carried into the first run, the tour card no longer leaves the
  screen after a series is added, and the extension step targets the Available grid rather
  than its tab so the install controls are not dimmed under the overlay. The artifacts were
  rebuilt from `633eb85`, and the automated gate passed again on that build. The manual tour
  on that build selected Spanish in setup; the launcher and login page opened in Spanish,
  the Available grid was lit, the add-to-library step kept the tour card on screen, and the
  reader opened a real page. The session is
  `build/manual-sandbox-results/session-20261106-155555`; the ten second loop wrote 35
  frames, and no named captures were used. The final portable zip was also unpacked and
  walked locally in Portuguese with a minted account, and its stop printed the localized
  sentence; the clean-machine C: and D: portable runs remain the 1.5.6 evidence, and the
  1.5.7 zip has not been repeated on a clean guest.
- **1.5.6 is published.** It was built from `8a398ab` and released as `v1.5.6`.
  The installer is 257,605,124 bytes, SHA-256
  `138465bd176d2fb4c56737b40cb65b70c70f27f4a6b79d34658981671ba686d3`; the portable is
  299,506,107 bytes, SHA-256
  `bc8cd08c383bc8c2c47f6d51fae31c1c4411a99cbaa9b5449e17b28aeb685409`. The full suite
  on the packaged PHP 8.5.12 passed 3,001 tests with 9,484 assertions and one skipped
  test. The automated Windows
  Sandbox gate passed every check: the app URL matched the bound port 8402, the browser
  title was `Log in - Papyrus`, registration returned 200, and a real source page was read
  through the app proxy (175,432 bytes, `image/webp`). The manual installer tour on
  2026-11-05 passed all nine steps on the final build: install, register, store, trust,
  Available with Minimize, MangaTaro install, search One Piece, source chooser, series,
  a real chapter 1 page, Done, in session
  `build/manual-sandbox-results/session-20261105-112810`. The upgrade drill installed
  1.5.4, upgraded to 1.5.6, applied the tour migration and kept the data marker. The
  rollback drill ran the real launcher CLI
  (`php.exe packaging\launcher\papyrus.php rollback --yes`), exited zero, restored the
  pre-migration snapshot, removed the tour migration, kept the data marker, and the
  restart reapplied the migration. The uninstall drill removed the install and kept the
  data, database and marker. The portable zip is published and its content and hash were
  verified, and it passed clean-machine C: and D: runs on 2026-11-05. The release is
  https://github.com/graynb25/papyrus/releases/tag/v1.5.6.
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
- **The 1.5.4 follow-up items were completed on the 1.5.6 build.** The automated Windows
  Sandbox gate passed on 2026-11-05, and the upgrade (1.5.4 to 1.5.6), rollback, uninstall
  and ahead-schema drills passed. The 1.5.6 portable zip is published, its content and hash
  were verified, and it passed clean-machine C: and D: runs on 2026-11-05. The C: session is
  `build/portable-sandbox-results/session-20261105-145005-c`; the D: session is
  `build/portable-sandbox-results/session-20261105-145450-d`.

