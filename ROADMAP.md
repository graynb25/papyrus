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
installer, a portable payload, a library of 867 series and 21,447 chapter states on a real install,
daily backups, a diagnostics bundle, a health check that asks real questions, and a Suwayomi version
check.

---

## Now: planned, severe

### 1. Orchestrated restore, both databases, one action

**Severe. Phase 1.**

A reader restores from two different places at two different times. The manga server's own backups
are `.tachibk` archives it manages, and Papyrus's half is a SQLite snapshot. Reconstructing a reader's
library from those means knowing they are related and doing it in the right order. Nothing tells them
either.

One action, both halves, and an honest report of what came back. This is the largest remaining item
and the only one where a reader can lose work.

*Partly there:* `import:run` reads the manga half and carries read state across. The Papyrus-side
snapshot restore is not surfaced anywhere.

### 2. A versioned install with a pointer flip

**Severe. Phase 2.**

`DefaultDirName` is a fixed `Papyrus` folder. Upgrading means replacing the folder a running
application is running out of. A versioned folder plus a pointer means an upgrade lays down
`1.3.0` beside `1.2.0` and flips, and a rollback is flipping back.

*Blocked on:* item 3, because a pointer flip is a small change in isolation and a dangerous one
against a launcher that cannot yet tell two installs apart.

### 3. Name the process holding a taken port

**High. Phase 1.**

`isPortTaken()` returns a boolean. A reader with a port conflict is told "not listening on port 4567"
and nothing else, when the answer is "Papyrus from another Windows account, pid 4212".

Small, self-contained, and the same file as the fix below.

### 4. Two installs reporting each other's health

**High. Not written up as a defect yet.**

Found while writing [PORTABLE.md](docs/PORTABLE.md), and measured rather than theorised: `health`
run from a second copy of the install reported **all four processes of the installed app as its own,
all green**, while failing on its own database. A second Papyrus on one machine is confidently wrong
about whether the first is working.

This is lesson 223 from [ENGINEERING-NOTES.md](docs/ENGINEERING-NOTES.md) one level up. The instance
lock exists and is not consulted by `status` or `health`. The fix is that every process name this
launcher looks for carries a path rooted at *this* install, which is the shape rule `dev-workers.ps1`
already uses for the same reason.

### 5. A portable zip

**Planned. Not started.**

`build/install` is the payload and it runs. What is missing is an archive and a first launch from a
clean folder. Three defects in this project's history were found only by installing from nothing, so
this is expected to find more.

---

## Next: planned, high

### 6. Surface and manage the manga server's backups in the UI

**High. Phase 1.** The backups exist and work. A reader cannot see them, list them, or restore one
without leaving the app.

### 7. SHA256 and a manifest beside the artifacts

**Medium. Phase 2.** Build-time digests are already verified for inputs. Nothing is published. Without
a manifest a reader cannot tell a download from a corrupted one.

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

**Medium. Phase 2.** Tell a reader a newer Papyrus exists. Do not download and install it without
asking. A click that appears to do nothing is worse than a sentence saying what will happen.

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