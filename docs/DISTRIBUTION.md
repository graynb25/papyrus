# Distributing Papyrus

Companion to [`DESKTOP-PACKAGING.md`](DESKTOP-PACKAGING.md), which covers how the thing is
built and supervised. This covers what gets shipped, where it goes, and what a reader is
promised.

Everything here is based on what was measured on this machine, and each finding names the
measurement. Where something is a judgement call rather than a fact it says so.

## Current delivery status, 2026-11-01

The 1.5.1 work is in the rewritten tree. The 1.5.1 release was not published. The unsigned
275,072,638-byte (262.3MB) installer was built from `9dd0918`, and its manual sandbox run
passed on 2026-10-29: per-user install,
registration, Keiyoushi, MangaTaro, an One Piece search and a chapter read. The 1.5.1 installer
payload is proven. Both automated install harnesses also passed in a clean guest on 2026-10-29:
the install harness proved a per-user install, data outside the program folder, `/ready` 200 and a
byte-identical library after uninstall; the zero-write harness proved 12,965 installed files with
0 added, removed or changed. The portable zip was rebuilt from `8ca2028` after the first-launch
storage fix in `2e4a73d`: 321,034,901 bytes (306.2MB), 12,964 entries, SHA-256
`0a430b7a9ad293ba33343cb55950c667a1c82642f5f044ea913ee25d2191efab`, and the sidecar matches. The
rebuilt portable passed clean-guest runs on C: and D: on 2026-10-29. The C: run used
`C:\Papyrus Portable\Papyrus` with a space in the path; the D: run used `D:\Papyrus` on a 4 GB
VHD-backed second volume. Both verified the archive, started through `Papyrus.cmd`, created
`papyrus-data` beside the program folder, registered, trusted Keiyoushi, installed MangaTaro,
searched One Piece, read chapter 1 of 1,203, and passed the layout check with no `%LOCALAPPDATA%`
install, no Start Menu entry and no uninstall entry. The setup and the portable zip are built from
different commits: the install tree ships storage placeholders and did not need the first-launch
fix, and the setup was already proven. No 1.5.1 artifact is published. A local release record
exists at `releases/1.5.1.json`; it records both artifacts, each with the commit it was built from.
The 1.4.0 record remains the latest published release. The 1.5.0 install tree passed the Windows
Sandbox gate on run 19: 86 checks, 0 failures, started through the reader shortcut with `--open`,
first account created, `/sources` rendered, Keiyoushi store and MangaTaro installed, One Piece
fetched, and page 0 of chapter 1 read through the authenticated proxy as a 175,432-byte WebP. The
gate copies `build/install` rather than running the setup executable. macOS and Linux packaging
below remain proposals, not available downloads.

The 1.5.1 build will not be published. 1.5.2 is in preparation from the rewritten tree;
it is not published until the release build and tag are verified. See
[RELEASE-STATUS.md](RELEASE-STATUS.md).

The 1.5.2 installer will be unsigned. The only signing material is a self-signed
development certificate, which does not remove the Windows warning. The release record
will say `signed: false`. Flux remains proprietary; no licence text is staged, and the
in-app licences page names it proprietary.

### History rewrite, 2026-10-30

The repository history was rewritten on 2026-10-30 to remove machine detail. The
artifact source commits were remapped:

| Artifact | Pre-rewrite | Rewritten |
| --- | --- | --- |
| Setup | `7f964a1` | `9dd0918` |
| Portable | `461e54d` | `8ca2028` |

The trees are identical and neither artifact was rebuilt, so the SHA-256 values and
sizes above are unchanged. The `version.txt` inside each artifact still names the
pre-rewrite commit; compare it with this table when checking a binary's stamp.

The hashes in this table predate the public rewrite on 2026-11-01. They are preserved
in the offline archive; the artifacts were not rebuilt.

### Public history rewrite, 2026-11-01

The public origin history was rewritten on 2026-11-01. The force-push replaced
`a821108` with `7df174c`, removed `storage/design-review` from every commit, and
replaced the measured machine-identity classes. The dependabot branch was deleted and
PR #2 closed. The pre-rewrite history is in an owner-held offline archive for 90 days.
GitHub still exposes `refs/pull/2/head` at `f8ca2b9`, which contains the design-review
commit, so this is a reduction in reachability, not an erasure guarantee. The
2026-10-30 note above is the earlier local artifact remap; it is not the public rewrite.

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
2. **Bundle extensions.** Extension packages are Suwayomi's to fetch, at the reader's request,
   from a store they chose.
3. **Ship a database.** A reader database contains their library and history. The build must run
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

## Single user per machine, decided

An earlier draft of this document treated multiple Windows accounts as a case to design for.
It is not, and the reasoning is worth keeping so nobody re-adds it.

`%LOCALAPPDATA%` is per Windows account, so two accounts on one machine would produce two
independent Suwayomi instances, two independent Papyrus instances, and both Suwayomi
instances would want port 4567. The second account's Suwayomi fails to bind and the reader
sees a server-down banner with no visible cause.

That collision is a consequence of a decision that is right on its own: **one reader per
install.** A manga library is personal. A shelf shared across Windows accounts, or worse,
across human beings who happen to log into the same machine, is not something anyone has asked
for, and supporting it properly would mean per-user port allocation, a launcher instance per
account, and an answer to "which of these four processes is mine" on every support call.

So the launcher is per user, ports are fixed and documented, and if the ports are taken the
app says so plainly and names the process holding them.

The one thing worth keeping from the discarded section: **when a port is already bound, say
which process holds it.** A reader who has Suwayomi running from a shortcut, the way this
machine has it running, needs to be told that and not left with a banner.

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
3. **A build log.** If anything is generating regular releases, a public changelog earns the
   update check its click.
4. **Announcement accounts.** Mastodon or Bluesky for releases that matter. Not needed to
   start and not worth automating early.

### The publish checklist

Release day, in order. Each step is there because skipping it has caused a broken release at
some point in every project that has ever shipped one.

1. Full suite green on the **packaged** runtime, not just on the developer's. CI runs the
   checkout suite on PHP 8.4; the release gate runs `vendor/bin/phpunit` with the install's
   `runtime/bin/php.exe` and a resolved copy of its `php.ini`. The template is resolved by
   the launcher into the data directory, so the gate must resolve `{{RUNTIME}}`, `{{LOGS}}`,
   `{{TEMP}}` and `{{TIMEZONE}}` first. `artisan test` cannot be used for this gate: it
   spawns PHPUnit under `PHP_BINARY` without the `-c` ini, so the shipped extensions are not
   loaded in the child. Record the PHP version, test count, assertion count and exit code.
2. PHPStan and Pint clean.
3. No dashes across the tree.
4. Tag `v1.2.3`. Annotated, so the tag carries the message.
5. Build the portable artifact. **Then run it on a clean machine.** Not the developer machine.
6. Compute SHA256 for every artifact.
7. `cosign attest` the artifacts, keyless.
8. Write release notes that lead with what breaks, not what was added. The first line should be
   readable by someone deciding whether to update without reading the rest.
9. Attach artifacts, publish. Keep the previous setup attached for rollback.
10. Verify the Atom feed updated, and that the per-release URL an in-app check would read
    actually resolves. This is the step that catches "released to the wrong tag".

Steps 5 and 10 are the two that catch real problems and the two that get skipped.

An upgrade release also needs the manual sandbox upgrade drill: install the previous release,
upgrade to the new one, force a migration failure, run `papyrus rollback`, then reinstall the
previous setup. That drill exercises the installer's stop and downgrade guards and the recorded
pre-migration snapshot, none of which a checkout test can prove.

### Naming, so an old download is recognisable

```
papyrus-1.2.3-windows-x64-portable.zip
papyrus-1.2.3-windows-x64-setup.exe
papyrus-1.2.3-macos-arm64-portable.tar.gz
papyrus-1.2.3-linux-x86_64.AppImage
```

Platform, arch and shape in the name, version in the name, no "latest". A reader with three
downloads in their folder should be able to tell them apart at a glance and should never
install a file whose name does not say what it is.

### A manifest beside the artifacts

A plain `manifest.json` per release, committed to the repository as well as attached:

```json
{
  "version": "1.2.3",
  "released": "2026-10-10",
  "requires": { "suwayomi": ">=2.3.0", "jvm": ">=21" },
  "artifacts": [
    { "file": "papyrus-1.2.3-windows-x64-portable.zip", "sha256": "...", "size": 0, "commit": "abc1234" }
  ]
}
```

When one release carries artifacts built from different commits, each artifact names its own
`commit`. The top-level `commit` is the installer's, and a portable rebuilt after a fix carries the
commit it actually came from. A reader checking the portable hash can then see which tree produced
it without assuming it matches the installer.

Two jobs. The updater reads it to know what it is looking at, and a reader who distrusts our
page can read it and check the hashes themselves. It also makes the compatibility requirement
machine readable instead of a sentence in a README.

### Compatibility is a real contract, not a README sentence

Papyrus reads Suwayomi through its GraphQL API and that schema moves between Suwayomi versions.
Papyrus already has the machinery: `suwayomi:schema:dump` writes a snapshot and `suwayomi:check`
compares it and reads `aboutServer { version buildTime }`.

So the contract should be stated and enforced:

| Papyrus | Suwayomi | Meaning |
| --- | --- | --- |
| 1.2.x | 2.3.x, 2.4.x | supported, tested together |
| 1.2.x | 2.5.x | probably fine, untested, warn the reader |
| 1.2.x | 2.2.x or older | refuse, and say which version to install |
| 1.2.x | server reports something unparseable | refuse rather than render broken queries |

**The launcher should check this at startup**, because the failure without it is a reader
watching queries fail against a schema that moved. That check belongs next to the readiness
probe, and it is the same `aboutServer` call the launcher already makes for health, so it costs
one field.

### Version numbering

Follow SemVer, and mean it. Papyrus has 34 migrations and a design that these notes call
"Second Pressing", so **minor versions carry real change**, including migrations that need
Suwayomi in a particular state.

Two rules that matter more than the scheme:

- **A migration is a minor version.** Never a patch. A patch that migrates a database is a
  patch that can fail irreversibly in someone's library.
- **A new version never updates itself while it is running.** Extract beside the running
  version, migrate a copy, then flip. That is phase 2 item 2.3 and it is severe for a reason
  that has nothing to do with tidiness.

The number lives in one file, `VERSION` at the repository root, and it is `x.y.z` with nothing else
in it. `build-install.php` copies it into the install's `version.txt` next to the commit hash, and
`build-installer.php` names the installer from it.

Both are recorded, because they answer different questions. The hash is what a build **is**, it
cannot drift, and it is what ends the guessing when a reporter's install disagrees with the
repository. The number is what a reader can **see**, which is why a bug report starts with it and
why refusing to have one costs more than the drift it avoids. A number that disagrees with
`VERSION` cannot be produced by the build, because nothing else writes one.

### Deprecation, and how a release gets noticed

Declare support windows out loud rather than letting them lapse:

- A minor version is supported until two minors later.
- The previous minor gets a warning in the update check, not a refusal. Refusing to start is
  how you lose a reader permanently over something that would have worked.
- When a Suwayomi version goes unsupported, say so in the release that drops it, with the
  version that broke it. Silence here is what makes a support thread unreadable.

### What if a takedown arrives anyway

Not because of anything this project ships, which is code and no content, but because it is
worth having thought about. A release asset can be targeted by a claim, and a URL pasted into
an issue by a third party can be.

- **Never attach anything content-derived to a release.** Which is the current posture, so
  this is a rule to keep rather than a problem to solve.
- **Do not respond in the issue thread beyond acknowledging.** Content policy is not a
  discussion this project wins by arguing.
- **Do keep the source.** If an artefact is contested, the build is reproducible from it and
  that is the answer that outlives the argument.
- **Do keep the manifest and hashes from previous releases.** If an artifact goes away, a
  reader who already downloaded it still has a hash to verify it against.

## What this adds to the phase list

| Item | Sev | Phase |
| --- | --- | --- |
| `VACUUM INTO` snapshot job for the Papyrus database | **S** | 0 |
| Orchestrated restore, both databases, one action | **S** | 1 |
| Versioned install with a pointer flip | **S** | 2 |
| Suwayomi version compatibility check at startup | H | **done.** `packaging/suwayomi.json` is the single source, the build stages and validates it, and `health` compares the running server against it. A server below the floor is a FAIL naming the minimum; a newer one is named without being a fault; an unknown version gets no verdict |
| Surface and manage Suwayomi's existing backups in the UI | H | 1 |
| Name the process holding a taken port | H | 1 |
| macOS notarisation budget decision, $99 a year | **H** | 2, on hold |
| cosign keyless attestations on releases | M | 2 |
| SHA256 and a machine readable manifest beside the artifacts | M | 2 |
| Linux packaging, which is the cheap proof of the pipeline | M | 2 |
| In-app update check that reports rather than installs | M | 2 |