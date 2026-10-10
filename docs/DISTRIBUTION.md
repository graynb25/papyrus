# Distributing Papyrus

Companion to [`DESKTOP-PACKAGING.md`](DESKTOP-PACKAGING.md), which covers how the thing is
built and supervised. This covers what gets shipped, where it goes, and what a reader is
promised.

Everything here is based on what was measured on this machine, and each finding names the
measurement. Where something is a judgement call rather than a fact it says so.

## What we ship, and what we refuse to ship

The rule is simple and it is already how the application behaves: **Papyrus ships code, never
content.**

Verified on this repository:

| Thing | In the repo? | Note |
| --- | --- | --- |
| Extension store URL as a default | **No** | `extension_stores` in the live database is the user's own row, added by them |
| Keiyoushi referenced in code | Comments only, 5 places | naming a public project is not distributing it |
| Scraped metadata, fixtures, backups | **No** | no `.tmb`, `.tachibk` or `.cbz` anywhere |
| Suwayomi's GraphQL schema | Yes, `resources/schemas/suwayomi-introspection.json` | an interface definition, not content |

So the store is whatever the reader points it at. `sources:sync --add-store=` takes a URL and
nothing else, and the Sources page cannot function until the reader supplies one. That is the
same posture Mihon has survived years of pressure on, and it is the reason to keep it.

### Three things the installer must not do

Each of these would convert a code distributor into a content distributor:

1. **Seed a store at install time.** Do not write an `extension_stores` row. Not as a default,
   not as a suggestion in a setup wizard, not as a commented-out example in a generated config.
2. **Bundle extensions.** The 1,379 Kotlin APK-shaped jars are Suwayomi's to fetch, at the
   reader's request, from a store they chose.
3. **Ship a database.** Yours is a reader's library with 867 series in it. The build must run
   `migrate` on a fresh file, never export one.

Documentation naming Keiyoushi is fine and should stay. What creates exposure is a shipped
default that fetches on first run, not a sentence in a README.

## Two distribution shapes

### Portable, everywhere

A folder or archive that runs where it lands. No installer, no registry, no admin rights, no
code signing requirement, nothing written outside its own directory except the data folder.

- **Windows**: `.zip`. Extract and run.
- **macOS**: `.app` bundle inside a `.dmg`, or a `.tar.gz`. Gatekeeper still applies, see below.
- **Linux**: AppImage, or a `.tar.gz`.

This is the shape that gets built first and tested hardest, because it is the one that makes
every other shape honest. If the portable build works on a clean machine, an installer is
packaging on top. If it does not, an installer hides the problem rather than fixing it.

### Installed, as an option

Layered on the same artifact, per platform:

| Platform | Tool | Silent flags |
| --- | --- | --- |
| Windows | Inno Setup, one exe | `/VERYSILENT /SUPPRESSMSGBOXES /NORESTART /LOG` |
| macOS | `pkgdmg` or a signed `.dmg` | `installer -pkg X -target CurrentUserHomeDirectory` |
| Linux | `deb`, `rpm`, Flatpak | debconf `DEBIAN_FRONTEND=noninteractive` |

The installer is **never** the thing that fetches Suwayomi. It lays down a versioned folder
and a launcher. Phase 1 owns the Suwayomi question.

## Code signing and antivirus, honestly

### The request was to avoid signing, and here is what that costs

| Platform | Can you ship unsigned without hurting users? |
| --- | --- |
| Linux | **Yes.** No signing wall. AppImage, deb, Flatpak all fine unsigned. |
| macOS | **No.** Not without a real cost to the reader. |
| Windows | **Partly.** You can avoid a code signing certificate, not the warning. |

### Windows

Windows applies the **Mark of the Web** to files downloaded through a browser, and
SmartScreen warns on unsigned executables from a publisher with no reputation. There is no
setting to change and no certificate-free bypass. What can be done:

**Free, and genuinely effective:**

1. **Publish SHA256 for every artifact**, on a page, in the release notes. A cautious reader
   can verify without trusting us.
2. **Sign the release metadata, not the binary.** `cosign keyless` via GitHub Artifact
   Attestations gives a verifiable identity over what was built, for free, and works precisely
   because the binary is not signed. This is the honest answer to "how do I know this is real"
   for an unsigned project.
3. **Keep the unsigned surface small.** A portable zip whose only executable is a small
   launcher is a very different proposition from a 200MB installer.
4. **Make the build reproducible and publish the recipe**, so a determined reader can rebuild
   and compare.
5. **Never auto-update silently.** Show what changed and let the reader accept it. Silent
   updates on an unsigned binary is the combination that generates real fear.

**Worth knowing:** an unsigned `.exe` that a reader downloaded once usually runs with no
warning at all. The reputation block only starts after a threshold of users reporting it. So
for an initial rollout the practical experience is often fine and degrades with success.

**Worth knowing, and it is a real constraint:** an archive does not launder the executable.
Extracting a zip propagates the Mark of the Web to what is inside it. "Ship a zip not an
installer" reduces the warning's wording, not its existence.

**And the one to avoid:** adding Microsoft Store distribution to dodge this. It means
Microsoft's signing and a review process, and this is a self-hosted, single-reader app that
does not fit the Store's shape. It would be more work and a worse product.

**Electron would make this dramatically worse.** A 200MB unsigned executable is the single
most antivirus-flagged shape that exists. This is a second, independent reason the packaging
plan does not use it, alongside the 200MB of Chromium serving a page that is already local.

### macOS

macOS is where signing stops being optional. An unnotarized app bundle is blocked by
Gatekeeper, and the workaround is to tell the reader to open System Settings, then Privacy and
Security, then find the blocked app and click Open Anyway. For a non-technical reader that is
the end of the install.

Apple notarization requires a paid Apple Developer Program membership, currently $99 a year.
There is no free tier and no enterprise alternative.

So macOS is the one platform where the honest answer is: **budget the $99, or do not ship for
macOS at all.** Shipping an unnotarized `.app` that bundles a JVM and silently fails to open
is worse than not shipping.

There is a middle path worth naming: **Ship macOS as a documented manual install** (a `.tar.gz`
the reader unpacks and runs from a terminal) and be explicit that Gatekeeper will object. That
is honest and it works for an audience who has already installed things by hand. It does not
work for the audience a desktop app is for.

### Linux

The easy one, and worth doing first to prove the pipeline. AppImage for the portable shape,
Flatpak if the sandbox appeal is worth the packaging cost, deb and rpm for distribution.

Flatpak deserves a caution rather than a recommendation: it sandboxes the app, which would
mean Suwayomi and the PHP process run under restrictions that interact badly with a local
database and a localhost port. Verify before committing.

## Multi-user Windows accounts

This is not a hypothetical, it is a consequence of the data location decision.

`%LOCALAPPDATA%` is per Windows account. So does `%LOCALAPPDATA%\Tachidesk`. Two accounts on
one machine therefore produce **two independent Suwayomi instances and two independent Papyrus
instances, and both Suwayomi instances want port 4567 and both Papyrus instances want the app
port.**

Concretely: the second account's Suwayomi fails to bind, Papyrus shows its server-down banner,
and the reader has no way to tell that the cause is the other account.

It is also the correct default. A manga library is personal, and a shared one across Windows
accounts is not obviously what anyone wants.

Three things follow, and they belong in the design rather than in a fixup:

1. **Per-user ports, chosen at first run and stored in per-user config.** Not "find the next
   free port" at every start, because `SUWAYOMI_URL` is already written into that user's `.env`
   and a port that silently changes orphans it. Choose once, persist, and fail loudly if it is
   taken.
2. **Bind to loopback only**, always. This also avoids the Windows Firewall prompt, which is a
   bad first impression and is avoidable.
3. **The installer is per machine, the data is per user.** Install to Program Files once, and
   each account gets its own data folder and its own ports.

This raises the severity of the port conflict item in phase 1 from medium to high.

## Backup, and the thing that is wrong about the obvious plan

The obvious plan is "back up `data/`". That is wrong, and the error is worth writing down
because the wrong plan looks obviously right.

### What is actually where

Measured on this machine:

| | Lives in | Size |
| --- | --- | --- |
| Series | Suwayomi `database.mv.db` | 35MB |
| Chapters | Suwayomi `database.mv.db` | |
| Read state, per series | Papyrus `database.sqlite`, `series_states` | 867 rows |
| Read state, per chapter | Papyrus `database.sqlite`, `chapter_states` | **21,447 rows** |
| Shelves, settings, AniList link, import ledger, source preferences | Papyrus `database.sqlite` | |

Read state exists in **both**. Papyrus is not a stateless view over Suwayomi; it holds its own
copy keyed by `suwayomi_manga_id`. Neither database alone reconstructs the install, and
restoring only one produces a reader who has their settings and no shelf, or their shelf and
no read state.

### The database cannot be copied while Suwayomi runs

`database.mv.db` is **H2**, not SQLite, despite the file living next to SQLite-looking
siblings. Attempting to open it read only while Suwayomi is up gives:

```
The process cannot access the file because it is being used by another process
```

An exclusive lock, held for the lifetime of the JVM. **A file copy of the library is not
possible while the app is running**, which rules out the simplest backup design entirely.

### Suwayomi already does this, properly

Its `server.conf` has a full backup subsystem, measured:

```
server.backupPath                    ""
server.backupTime                    "00:00"      daily backup time
server.backupInterval                1            days, 0 disables
server.backupTTL                     14           days of retention
server.autoBackupIncludeManga        true
server.autoBackupIncludeCategories   true
server.autoBackupIncludeChapters     true
server.autoBackupIncludeTracking     true
server.autoBackupIncludeHistory      true
server.autoBackupIncludeClientData   true
server.autoBackupIncludeServerSettings true
```

It writes `org.suwayomi.tachidesk.auto_<timestamp>.tachibk` files daily and keeps 14 days.
**Those are `.tachibk` files, which this project can now read.** `TachibkReader` was built for
the Mihon import path and reads these.

So the design is not "we implement library backup". It is:

| What | Who backs it up | How |
| --- | --- | --- |
| Library, chapters, Suwayomi read state | **Suwayomi**, already, daily, 14 day TTL | set `backupPath` to a folder Papyrus manages, and surface the files |
| Papyrus read state, shelves, settings, import ledger | **Papyrus** | our own scheduled job |
| Both together, restorable in one action | **Papyrus** | an orchestrator that triggers a Suwayomi backup then snapshots ours |

### How Papyrus snapshots its own database

It cannot use a file copy either, for the same reason: SQLite is being written while the app
runs, and a copy taken mid-write is a corrupt copy.

SQLite has the right tool for this, and it works on a live database:

```sql
VACUUM INTO 'backup/database-snapshot.sqlite'
```

It takes a consistent, compacted snapshot of an open database in one step, with no lock taken
that would block a reader, and no need to stop anything. **That is the whole backup mechanism
for the Papyrus side, and it is a few lines.**

### What to promise a reader

Honest wording matters more here than anywhere else, because a backup that does not contain
what they think contains is worse than none:

> Papyrus keeps a rolling backup of your settings, shelves and reading progress, and takes a
> daily full snapshot of your library through Suwayomi. Restoring puts both back.

Never: "your library is safe", if that means only `data/`.

## Publishing

### Where to host

| Host | Verdict |
| --- | --- |
| **GitHub Releases** | The default choice, and for this project clearly right. The repo is there, so reputation accumulates in one place, releases get an Atom feed for free, and issue tracking and discussions sit next to the binaries. |
| itch.io | Reasonable for an indie game or asset pack, wrong for a self-hosted server that people need to update for years. |
| A bucket | No reputation, no feed, and a domain that looks like a phishing target. Worse in every dimension. |

GitHub Releases it is, and the practical details:

- Tag `v1.2.3`, attach the artifacts, write notes that name what changed and what it breaks.
- `https://github.com/OWNER/REPO/releases.atom` is a working release feed. Free, no service.
- Releases give you the per-release download URL the in-app updater checks. No update server
  to run, host, secure, or pay for.

### How updates get announced

Four channels, in ascending order of effort. Do the first two.

1. **The releases page and its Atom feed.** Costs nothing and it is what a GitHub user already
   has a reader for.
2. **An in-app check that reports and does not install.** A button that says "version 1.2.3 is
   available, here is what changed" and links out. It must not download and run anything
   without the reader accepting it, for the reason in the signing section: on an unsigned
   project, a silent update is indistinguishable from malware.

   The check is one unauthenticated call to the GitHub API, which allows 60 requests an hour per
   address. One reader polling once a day is nowhere near it.
3. **A build log.** If anything is generating regular releases, a public changelog earns the
   update check its click.
4. **Announcement accounts.** Mastodon or Bluesky for releases that matter. Not needed to
   start and not worth automating early.

### Version numbering

Follow SemVer, and mean it. Papyrus has 34 migrations and a user-visible design that has been
called "Second Pressing" in these notes, so **minor versions carry real change**, including
migrations that need Suwayomi in a particular state.

The one rule that matters for an auto-updating install: **a new version never updates itself
while it is running.** Extract beside the running version, migrate a copy, then flip. That is
phase 2 item 2.3 and it is a severe item for a reason that has nothing to do with tidiness.

## What this adds to the phase list

| Item | Sev | Phase |
| --- | --- | --- |
| `VACUUM INTO` snapshot job for the Papyrus database | **S** | 0 |
| Orchestrated restore, both databases, one action | **S** | 1 |
| Surface and manage Suwayomi's existing backups in the UI | H | 1 |
| Per-user port allocation on Windows | **H** | 1 |
| macOS notarisation budget decision, $99 a year | **H** | 2 |
| cosign keyless attestations on releases | M | 2 |
| SHA256 published for every artifact | M | 2 |
| Linux packaging, which is the cheap proof of the pipeline | M | 2 |
| Versioned install with a pointer flip | **S** | 2 |
| In-app update check that reports rather than installs | M | 2 |