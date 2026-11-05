# Roadmap and proposals

Everything not yet built, why, and how hard. Written so that the order is arguable rather than
asserted.

Two kinds of thing live here and they are kept apart on purpose:

- **Planned** items come from a phase list that already exists in the repository, with a severity
  already assigned. See [DESKTOP-PACKAGING.md](docs/DESKTOP-PACKAGING.md) and
  [DISTRIBUTION.md](docs/DISTRIBUTION.md).
- **Proposed** items are ideas. Nobody has agreed to them. They are here to be argued with.

Severity is the project's existing scale:

| | Meaning |
| --- | --- |
| **S** | severe: silent data loss, or a reader cannot use the app |
| **H** | high: a reader can lose work, or cannot finish a normal task |
| **M** | medium: a real cost, with a workaround |

---

## Shipped

Not a list, because [CHANGELOG.md](CHANGELOG.md) is the list. The shape of what exists: a Windows
installer, a published portable zip, live backups and restores of both databases, daily backups, a
diagnostics bundle, a health check that asks real questions, and a Suwayomi version check. 1.5.6
is the current release; see [docs/RELEASE-STATUS.md](docs/RELEASE-STATUS.md). The portable zip has
been published since 1.5.2, but the latest clean-machine C: and D: runs are still the 1.5.1
archive.

---

## Shipped on 2026-10-13, after this list was last read

Everything below landed on 2026-10-13 and is verified by the suite. It is here because a plan that
does not record what has been done is read as though nothing has, which is the same failure as a stale
blocker in the other direction.

### The listener was not confined to loopback

`packaging/frankenphp/Caddyfile` said `http://127.0.0.1:{{APP_PORT}}`, and the installer guide said
nothing outside the machine could be reached. Measured on a live install:

    Caddyfile said                         http://127.0.0.1:8000
    the listener was actually bound on     ::           the IPv6 wildcard, every interface
    http://192.0.2.1:8000/ready         200
    http://192.0.2.2:8000/ready          200

Naming a loopback host in a Caddy site address does not restrict the listener. An explicit
`bind 127.0.0.1 ::1` does, and is now there, with `LauncherBindTest` failing against the file as it
shipped. This answers phase 2 item 2.5, which asked to be told whether the firewall prompt could be
avoided: it can, now that the bind is real.

### Reader-facing fixes

- **The browser opens itself.** `Papyrus.cmd` passes `--open` and the launcher opens the address it
  bound. The port was never a constant, so documenting it could only ever cover the common case.
- **A reader can make their own account.** `single` mode closed registration, which is right, and that
  also closed the only route to a first account. It now opens sign-up *while nobody has registered* and
  shuts behind the first one, which is a window rather than an opening.
- **That account works.** The first version produced a signed-in reader who was then redirected to
  `/email/verify` from every route. Verified accounts on creation in `single` mode only.
- **The remembered address.** `PAPYRUS_LOCAL_READER` holds the address and pre-fills the login form.
  Not the password, and never will be; see `LocalReaderAccount`.
- **A licences page**, generated from the two lock files the build installs from, plus the two bundled
  pieces no lock file mentions. It reports 448 packages and one that names itself proprietary.
- **A sandbox runner that leaves Papyrus running**, so an install can be looked at rather than only
  gated.

### Phase 1 is overtaken, not outstanding

Phase 1 was "acquiring Suwayomi": eight items about downloading it at first run. The install bundles
it instead, with a pinned JRE staged at build time and a digest asserted. So 1.1 through 1.4 are moot
rather than pending, 1.5 and 1.6 are done and verified against a live install, and only 1.7 (licence
attribution, now largely satisfied by the licences page) and 1.8 (optional MSI, deliberately declined)
remain. **Reading Phase 1 as live work would be planning work that is already moot.**

---

## Open and recently shipped, severe

### 1. Orchestrated restore, both databases, one action: done

**Severe. Phase 1. Done 2026-11-04.**

The Backups page takes a live pair: the reader half through SQLite's `VACUUM INTO` and the manga
half through Suwayomi's own fresh export. Both halves are verified against a manifest before the
pair is named. A restore moves both halves as one operation and rolls back if either fails. The
page lists the pairs, reports the run's stages, and exports a `.tachibk` for another reader.

*What remains:* a clean-machine restore drill is not recorded.

### 2. A versioned install with a pointer flip

**Severe. Phase 2.**

`DefaultDirName` is a fixed `Papyrus` folder. Upgrading means replacing the folder a running
application is running out of. A versioned folder plus a pointer means an upgrade lays down
`1.3.0` beside `1.2.0` and flips, and a rollback is flipping back.

*Blocked on:* nothing, now. This said "blocked on: item 3" and item 3 has been complete for a
  while: the launcher matches its own processes on the data directory plus the role, so a pointer
  flip no longer risks flipping the wrong install. A stale blocker is why this sat still, and a
  plan that reports a resolved dependency as a live one is worse than no plan.

### 3. Name the process holding a taken port: done

**High. Phase 1. Complete.**

`isPortTaken()` returned a boolean and a reader got "not listening on port 4567" and no next step.
The health lines now name the holder, its pid, and enough of its command line to find it in Task
Manager, and say nothing invented when it cannot be found out.

### 4. Two installs reporting each other's health

**Partly fixed.** Found while writing [PORTABLE.md](docs/PORTABLE.md), and measured rather than
theorised.

**Done:** the worker lookups. `health` matched processes on a bare `queue:work` while `stop` matched
them on the data directory plus the role, so a second Papyrus reported the first one's workers as its
own. Both now use the same tokens, and a second copy correctly reports `NOT RUNNING` over a database
that is not there.

**Still open, and deliberately:** the `suwayomi` and `web` lines report green from a second copy. Those
are port probes, not process probes, and it is correct. The launcher explicitly adopts a Suwayomi
already listening on its port rather than starting a second one, so "something is answering on 4567"
is the true and useful answer. The residual risk is `web`, where the readiness probe confirms something
is answering `/ready` with a valid key and database, which is Papyrus-shaped but not proof it is *this*
Papyrus.

**Also done, and the reason this was findable:** a port held by something else now says who holds it.

### 5. A verified portable download: published

**Published 2026-11-05.** The 1.5.6 zip is 299,506,107 bytes with SHA-256
`bc8cd08c383bc8c2c47f6d51fae31c1c4411a99cbaa9b5449e17b28aeb685409` and a matching sidecar.
The 1.5.1 archive remains the latest clean-machine C: and D: evidence; no 1.5.6 clean-machine
run was done. The default-port limit is still real for two copies unless `PAPYRUS_APP_PORT` and
`PAPYRUS_SUWAYOMI_PORT` are set.

---

## Next and recently shipped, high

### 6. Surface and manage the manga server's backups in the UI: done

**High. Phase 1. Done 2026-11-04.** The Backups page lists verified pairs, takes a live backup
without stopping the app, restores a pair, shows the run stages, and exports a `.tachibk`. The
pair's manga half is Suwayomi's own fresh export. The page does not separately list Suwayomi's own
backup folder.

### 7. SHA256 and a manifest beside the artifacts: done for releases

**Medium. Phase 2. Done 2026-11-05.** `releases/<version>.json` carries each artifact's size,
SHA-256 and commit. The portable zip carries a `.sha256` sidecar, and the release notes carry both
hashes. The setup executable has no separate sidecar.

### 8. Keyless signatures over that manifest

**Medium. Phase 2.** `cosign sign-blob --bundle`. No long-lived key, no rotation, and the signature
names the workflow that produced the build.

Native `.deb` and `.rpm` signing is deliberately not planned. It is worth doing only when there is a
repository to serve, and it means running key infrastructure for ever.

### 9. Linux packaging

**Medium. Phase 2.** AppImage or `.tar.gz`. Worth it for one reason and one only: it is the cheap proof
that the build pipeline is not Windows-shaped by accident. The product is Windows-only; the pipeline
should not be.

### 10. An update check that reports rather than installs

**Decided 2026-11-01: manual statement for now.** The About page says updates are manual,
never checks for new versions, and links the releases page. A network check is still not
implemented. If it returns, it must be reporting-only, named on the privacy page, and
switchable off. It must never download or install anything.

---

## On hold

### macOS notarisation

**High, and it is a decision rather than a task: $99 a year.**

Notarisation is not optional on macOS; an unsigned build is refused by Gatekeeper with a dialog a
reader cannot act on. Until the budget is agreed there is no point building for macOS, because the
result would be something that installs and then refuses to run. Agreed to hold.

---

## Proposed: ideas, not decisions

None of these are promised. They are here because they follow from what the code already does.

### Per-series source switching, surfaced

Two sources for the same title is normal, and a failing chapter is usually fixed by switching. The
migration flow exists. What is missing is making it a two-click action on the series page rather than
a deliberate migration flow, because the common case is not a migration, it is one broken chapter.

### A library check that says what it is doing

The library check re-books itself until the work is done, and a reader watching it sees a bar. It
does not say "checking series 340 of 867" or "this series is waiting on a site that is not
answering". The second is the interesting one: a stalled check looks identical to a slow one.

### Series-level failure reporting

When a source fails, the series shows as stale and nothing says why. One sentence per series, saying
which source and what it said, would turn most "it does not work" reports into actionable ones.

### A "what is missing" page

Given a library, the set of sources, and the catalogue, the list of series that exist somewhere and
are not in the library. That is a report, not a download, and it is cheap: everything it needs is
already fetched.

### Cover art Papyrus stores itself

The manga server can only hold a cover it was given, capped at 800px on its long edge. A reader who
wants better covers, or covers for series with none, cannot get them today. Papyrus already has a
cover store.

### A per-series reading mode that survives

Reading mode, direction and spacing are settings today. They are the same kind of setting as chapter
order, which was being forgotten on reload until recently, and per series is what a reader with one
series in Japanese and one in English actually wants.

### Export without Suwayomi running

Export exists and reads through the manga server. A reader with a broken server cannot export their
own library, which is the moment they most want to.

---

## Not going to be built, and why

Kept here so nobody re-litigates it.

| | Why not |
| --- | --- |
| A curated source list | Deciding which projects to trust belongs to whoever runs the server, not to us. See [SOURCES.md](docs/SOURCES.md). |
| Auto-updating extensions | An extension is code that runs on your machine. Papyrus will not swap it underneath you. |
| A mobile app | The reader is a phone browser pointed at a server you run. A native app adds a second thing to keep working and buys a full-screen browser tab. |
| Cloud hosting | Papyrus is self-hosted because your library is yours and your reading is nobody's business. |
| Parsing manga sites itself | That is what the manga server and its extensions do, and doing it twice would mean maintaining scrapers for every site forever. |

---

## How to argue with this list

Open an issue and say which item. The severity scale is the thing most worth challenging: an item
marked **S** means silent data loss or an unusable app, and if a reader cannot lose work without that
item, it is not severe and should say so.