# Papyrus

A self-hosted web manga reader. Laravel 13, Livewire 4, Flux 2, Tailwind 4, Alpine, on top
of **Suwayomi-Server**, which is what actually talks to manga sites.

> Suwayomi parses. Papyrus presents.

Papyrus ships no content and no source list. You point it at an extension store, read the
publisher's signing key, and decide whether to trust it. That is the whole onboarding
flow.

## About

This started as an attempt to find out how much of a manga reader could be rebuilt on top
of Suwayomi without touching it. That turned out to be most of it.

Keiyoushi extensions are Kotlin Android apps. PHP cannot run one, and rewriting 1,379 of
them is not a project. So the parsing problem is delegated entirely to Suwayomi, and
everything a reader actually touches is rebuilt: the library, the shelves, the read state,
the progress, the notes, the settings, the search, the whole reading experience.

The consequence worth knowing is that Suwayomi is single-tenant. Every request it serves
resolves to one hard-coded administrator, so it cannot hold per-user state at all. Papyrus
therefore owns **100%** of user state, from the first migration, in a data model that is
multi-tenant regardless of how it is deployed. Suwayomi is called for three things:
`fetchSourceManga`, `fetchMangaAndChapters`, and `fetchChapterPages`.

Two rules came out of that and shaped everything else:

**Do not trust a measurement you have not taken.** Several decisions here began as confident
recommendations and reversed after probing the server. Fan-out to one source per site was
going to save 61 requests and turned out to lose 35% of all titles. A search filter syntax
was going to have four terms and ended with three, because the fourth worked on 18% of
sources and there was no field on a returned row to check it against afterwards. A dedupe
threshold of 0.92 felt right and 0.95 is what the data supports.

**A number the reader cannot see is a number the reader cannot trust.** Search reports how
many sources a filter term excluded, because a filter that quietly emptied a third of the
sources looks identical to one that found nothing. Unread counts mark themselves stale when
a source renumbers chapters, rather than asserting a figure that is no longer true.

It is a portfolio and a learning project, not a product. There is no changelog cadence and
no support promise. Tachiyomi, which this architecture descends from, was taken down. That is
not immunity, and this is not something to monetise.

## Status

Discovery and the read path are both done, verified against a live Suwayomi. Search,
source management, health, downloads, migration between sources, the series page, the
reader and settings all work.

| Screen | Route | What it does |
|---|---|---|
| Search | `/search` | Fans one query out across every eligible source, deduplicates, shows per-source outcomes. Results carry a library toggle, so keeping a series is one click from the card |
| Sources | `/sources` | Extension stores, signing keys, trust decisions, and the extension catalogue, 1,396 at the last sync. The Updates view offers per-extension and update-all actions |
| Health | `/health` | Per-site health, grouped because 82 sources are really 15 sites (counted 2026-10-01) |
| Library | `/library` | Everything you added, filed into shelves, with unread counts |
| Downloads | `/downloads` | What is queued, downloading or finished, reconciled in two calls a pass |
| Move to another source | `/migrate/{manga}` | Searches every other source for the same series, ranks the candidates, transfers on confirmation |
| Series | `/series/{manga}` | Chapters with dates, scanlator filtering, per-series reading mode. Each chapter's read state is a toggle, with mark-all-read and mark-all-unread beside it |
| Reader | `/read/{manga}/{chapter}` | Continuous scroll or paged, progress saved per user |
| Import | `/settings/import` | Brings a library in from another reader app. Inspects the file before it writes anything, and reports what it did |
| Settings | `/settings/profile`, `/settings/connections`, `/settings/appearance`, `/settings/security` | Profile, AniList tracking, reader appearance, and password, two-factor and passkeys |
| About | `/settings/about` | The two version numbers a bug report starts with, and whether the Suwayomi in front of it is one this build can work with |
| Questions | `/faq` | Public. What this install collects, where it lives, and the three things that do reach the internet, each with who started it |
| Licences | `/settings/licences` | Every package this install is made of, generated from the lock files the build ships from, so it cannot claim coverage it does not have. 448 of them as of writing, and exactly one that names itself proprietary: `livewire/flux`, which is the component library every page here is built from |

`/` and `/dashboard` redirect to `/library` when you are signed in, and `/` lands on the login screen when you are not.

### The library

Shelves are many to many with series, the same as Mihon and everything derived from it,
because the cases that come up are all overlaps: something is both "Reading soon" and a
webtoon, and a single category forces you to choose and then lose the other one. Every
series also has one home shelf, which is what "Unfiled" means when it is empty.

Unread is computed here rather than read from Suwayomi. The server has an `unreadCount`
field and it belongs to `Admin(1)`, so it reported 41 unread on a series where the reader
had read thirteen chapters. Ours is `chapters.totalCount` minus the chapter rows this
reader has read. When a source renumbers chapters that subtraction quietly stops being
true, so the chapter list is fingerprinted when we see it and a mismatch marks the card
stale instead of asserting a number we cannot stand behind.

### Search syntax

The search box takes Mihon's `label:value` syntax, with three terms:

```
status:completed genre:"slice of life" berserker
```

Every one of those three was measured against all 82 installed sources before it shipped.
`genre` reaches 77 of them, `status` 74 and `sort` 69.

A term is **pushed** to a source that has a matching filter and the source is **skipped**
where it does not, with the count shown on the page. That is the whole design, and it came
from a measurement that killed the more ambitious version: post-filtering the returned rows
was meant to cover the gap, but across 704 search rows from 76 sources `author` is missing
on 90% of them and `status` reads `UNKNOWN` on 99%. There was nothing to check against, so a
term is either pushed or it is not offered, and `author:` is not offered at all.

A label the app does not recognise is searched as text rather than dropped, so a title
containing a colon still works. The page says when it has done that.

### Tracking on AniList

Per reader, and inert until you connect. Reading AniList's public API needs no key, so
the search-page dedupe is a keyless lookup that stays off until you switch it on at
`/search`. Writing progress is a different thing: it writes to your list under your
account, so it needs an OAuth token only you can grant.

**Connecting is the consent, not the search-page switch.** A reader who connects and
never touches that switch still has their library series' titles sent to AniList, because
the push and the import both resolve a title directly and neither asks
`App\AniList\Preference` first. `App\AniList\Tracking` gives that as the reason where it
makes the lookup.

Four things use AniList now: search dedupe, progress pushed when a chapter finishes,
progress pulled back by an import, and the connect and disconnect buttons.

Connect and disconnect live at `/settings/connections`. To offer them, this deployment
needs its own application registered at anilist.co/settings/developer, with the id and
secret in `ANILIST_OAUTH_CLIENT_ID` and `ANILIST_OAUTH_CLIENT_SECRET`, and the redirect
URI registered there set to `/anilist/callback` on your `APP_URL`, byte for byte. Until
both are set, the page says what is missing instead of drawing a button that cannot work.
That route is behind `auth` and `verified`, so you must still be signed in when AniList
redirects back or the state check fails and the connect is thrown away.

Once connected, a chapter finishing (read to the end, or marked read by hand) pushes
that series' progress to AniList as a queued job. The largest chapter number you have
read is the number pushed, marked CURRENT, or COMPLETED when AniList's own chapter count
says you reached the end.

The import runs the other way and rewrites your local read state from AniList's. It only
touches series already in your library, nothing is ever marked unread, and it never adds
a series. A series AniList has no entry for is left exactly as it is and listed as
unmatched when the run finishes. A series AniList knows but that is not on your list counts
as matched with nothing to pull, so a finished run can legitimately report zero updated.
If your library is empty the page says so rather than reporting a tally of nothing.

AniList issues no refresh token, so when the year-old token dies the page shows you
disconnected and you connect again.

### The reader

Two modes in one document: continuous scroll, which is the default and the one built
for, and paged. They share the same image URLs, so switching costs no request.

The reader is deliberately a plain document rather than a Livewire page. `fetchChapterPages`
takes 2.0s for a 22 page chapter and is the only call that yields a real page count, so it
runs once, server side. Everything after that is a scroll or a transform, and no framework
sits between a finger and a page turn. Its script is plain JavaScript with no framework at
all, including no Alpine.

Reader settings are per series, resolved server side from defaults, then your default
profile, then the series itself. A panel in the corner covers mode, column width, gap,
zoom, background, fit-to-height and long strips.

### Importing a backup

`/settings/import`, reached from `Settings` then `Import`, brings a library in from another
reader app. Before this page the only route was `php artisan import:run` typed into a shell,
which [`docs/IMPORT-ANALYSIS.md`](docs/IMPORT-ANALYSIS.md) documents along with the format
itself.

Two formats are read. A Tachimanga `.tmb`, a zip holding a zip holding SQLite, and a `.tachibk`,
which is a protobuf message inside a gzip and is what Mihon, Tachiyomi and Suwayomi write.
They share nothing below the parsing, which is why each is one class behind one interface, so
which one you give it is decided from the bytes and not from the file's name.

It sits in the `auth` middleware group alone, not `auth,verified`, and that is deliberate. Every
other settings page changes an account; this one reads a file and writes a library. A reader who
has just signed up and cannot yet receive mail is exactly the reader migrating off another app,
and there is nothing to escalate for, because the importer is handed the signed in user rather
than one named in the request.

**The file arrives by upload or by server path**, and it has to be both. A real Tachimanga
backup measured 39.36MB, and this server has `post_max_size` 8M with `upload_max_filesize` 2M,
so `post_max_size` binds first. Both are `PHP_INI_PERDIR`, which means neither can be raised at
runtime. So the page takes a path to a file already on the server as well as an upload, and it
tells the reader the server's own limit before they choose rather than letting them find out by
watching a request fail.

**It needs a queue worker, and it checks.** `import` dispatches `App\Jobs\RunBackupImport`. On
`QUEUE_CONNECTION=sync`, which is what a fresh Laravel install ships with, the whole run happens
inside the browser request, so an eleven minute import hangs the page and probably finishes
anyway. The page says so before you press anything, naming `QUEUE_CONNECTION=database` and
`php artisan queue:work`. With a worker running but the queue stalled it says plainly that
nothing is processing the queue and names the command again, because a dispatch to an unserved
queue leaves a run reading `running` for ever. See
[The queue worker is not optional](#the-queue-worker-is-not-optional).

**Inspect is the default.** The first thing a reader does with an unfamiliar app is point it at
their real library and hope, and reading the file answers "what is in this and what would
happen" in seconds without writing anything. Turning it off is one click.

A second run is a delta rather than a repeat. `imported_series` fingerprints each series, so a
second run applies only what changed, which is also what makes an interrupted import safe to
press again.

## Installing it

There are two ways, and they are the same application.

**The installer.** Run the `.exe`, and it puts Papyrus in your user profile with a launcher on your
desktop. It is the ordinary route and it is the one most people want.

**The portable zip.** Unpack it anywhere and run `Papyrus.cmd`. No installer, no registry, no admin
rights, no uninstaller. It is for trying Papyrus without committing to it, or running it from a USB
stick. Your library lives in a `Papyrus` folder *beside* wherever you unpack it, not inside it.

Uninstalling the installer deliberately leaves your library alone, because deleting somebody's library
by accident is not a recoverable mistake. Removing the portable folder does not touch it either.

### The Windows warning, and what to do about it

Papyrus is signed with a **self-signed certificate**, and Windows will warn you about that. This is
expected and it is not a sign the file is unsafe.

Measured on both sides, so this is not a reassurance:

| Certificate | What Windows reports |
| --- | --- |
| Not installed on your machine | `UnknownError`, and a "Windows protected your PC" prompt |
| Installed on your machine | `Valid`, no prompt |

A self-signed certificate terminates in a root that Windows has never been told to trust, so the
signature proves the file has not been tampered with but cannot prove *who* signed it. Only a
certificate from a recognised authority can do that, and Papyrus does not have one.

If you would rather not see the warning on this machine, trust the certificate yourself. You are then
deciding to trust whoever made it, which is a decision you are entitled to make but should make
knowingly:

```powershell
Import-Certificate -FilePath papyrus.crt -CertStoreLocation Cert:\CurrentUser\Root
```

The `.crt` is published beside the download, and its SHA-1 thumbprint is in the build manifest so you
can check that you have the file somebody published rather than one you were handed.

For the portable zip, check the SHA-256 instead. A zip cannot carry this kind of signature at all, so
the published digest is the only verification available:

```powershell
Get-FileHash .\Papyrus-1.2.0-portable.zip -Algorithm SHA256
```

and compare it with the `.sha256` file published beside the download.
## Running it

Suwayomi first. It needs to be a normal process, not a Windows Service, because its
embedded browser requires an interactive session. [`docs/SETUP-SUWAYOMI.md`](docs/SETUP-SUWAYOMI.md)
covers install, the SHA256, and the configuration.

Then, from this directory:

```bash
composer install
npm install
cp .env.example .env          # Windows: copy .env.example .env
php artisan key:generate
php artisan migrate
```

Point `.env` at your server:

```dotenv
SUWAYOMI_URL=http://127.0.0.1:4567
SUWAYOMI_USERNAME=whatever-you-registered-with-suwayomi
SUWAYOMI_PASSWORD=a-password-only-you-know
```

`SUWAYOMI_USERNAME` is named for Suwayomi's API field, not for Papyrus's own login. Papyrus
is email only: it has no username column and Fortify is set to `'username' => 'email'`.
Suwayomi calls it a username in both its GraphQL schema and its web UI, and it is still
`username` on v2.4.2366 as it was on v2.3.2243. If you registered Suwayomi with an email
address, that address goes here.

Generate the password rather than reusing one. Suwayomi's REST endpoints answer `401`
without a token, and it holds the credentials for every manga account you configure in it,
so it is worth treating as a secret even on a loopback address. See
[`docs/SECURITY.md`](docs/SECURITY.md) for the deployment notes.

AniList tracking needs `ANILIST_OAUTH_CLIENT_ID` and `ANILIST_OAUTH_CLIENT_SECRET` in
`.env`, plus a redirect URI of `/anilist/callback` registered at
anilist.co/settings/developer, and `ANILIST_OAUTH_REDIRECT_URI` when that is not the
address on `APP_URL`. Without them `/settings/connections` says what is missing. See
[Tracking on AniList](#tracking-on-anilist).

Register a store and mirror the catalogue:

```bash
php artisan suwayomi:check
php artisan sources:sync --add-store=https://github.com/keiyoushi/extensions/raw/repo/index.pb
php artisan sources:sync --refresh
```

Two long-running processes, and the app is quietly broken without both:

```bash
npm run dev                # otherwise new Tailwind classes are missing from the CSS
php artisan queue:work     # otherwise search, series checks, downloads and import do nothing
```

Then open the site. `composer dev` starts all three at once if you would rather not keep
separate terminals.

### There are two logins, and they are unrelated

This trips people up, so it is worth being blunt about it.

| | What it is | Where it lives |
|---|---|---|
| **Suwayomi** | Credentials for the *server* that fetches manga. The app uses them; you never type them. | `SUWAYOMI_USERNAME` / `SUWAYOMI_PASSWORD` in `.env` |
| **Papyrus** | Credentials for *your* account here. An email address and a password. | A row in the `users` table, made by `php artisan users:create` |

They share no storage and neither one will work on the other. Typing your Suwayomi
username into the Papyrus login gets `Please include an '@' in the email address`, which
is the correct answer to a question you were not asking.

### Suwayomi's credentials, in a packaged install

The launcher writes `SUWAYOMI_URL` and knows the port, because it starts Suwayomi itself. It cannot
know a password, so it does not invent one, and it does not ask for one either: PHP on Windows
cannot mask console input without a dependency, so a prompt would put the password on screen and in
your scrollback.

So they go in the `.env` in your **data directory**, next to your library:

```
SUWAYOMI_USERNAME=papyrus
SUWAYOMI_PASSWORD=the-password-you-registered
```

For an unattended install, set `SUWAYOMI_USERNAME` and `SUWAYOMI_PASSWORD` in the environment
instead and the launcher picks them up from there and passes them to the application.

If Suwayomi is running in a mode that requires signing in and neither is set, **the launcher refuses
to start** and names the file to edit. That is deliberate, and it is worth knowing why:

> Without this check the install starts, serves your library, and every series reads "Not on the
> server". The launcher reports it is running, `/ready` answers 200, and `papyrus health` prints
> six green lines, because every check that exists asks Suwayomi `aboutServer`, which is not behind
> a login. An install that looks healthy and silently refuses every query is worse than one that
> says what is wrong.

Your library is never at risk from this. It lives outside the install, and the check runs before
anything is migrated.

### Making your first account

The supported way into the app is the **Sign up** link on the login page.

`single` mode is the default and it does **not** leave registration open, which is the point: it
is a one-reader install, and a permanently open sign-up form is a hole in one. What it does instead
is open sign-up *while nobody has signed up yet*, and close it the moment the first account exists.
A brand new install therefore has a door, and it has exactly one.

So the flow is:

1. Open Papyrus. The login page shows **Sign up** while the install is unclaimed.
2. Create your account. There is no email verification, it is all local.

Nothing is reported anywhere: no telemetry, no analytics, no crash reporting, no phone-home check.
There is no server behind Papyrus, so there is nowhere for any of it to go. Everything it knows about
you is in a SQLite file inside your user profile, which you can copy, back up or delete yourself.
Uninstalling removes the program and deliberately leaves that file alone.

Three things do reach the internet, and all three are things you started: connecting an AniList
account, downloading a chapter (which means asking the manga source for it), and installing a source
extension. `/faq` says what each one sends, and it is linked from the sign-in screen rather than
merely asserted there.
3. Sign-up closes itself. From now on `/register` answers 404 and the link is gone, so nobody else
   can join your install.

Your address is recorded as `PAPYRUS_LOCAL_READER` in your `.env` and offered back on the login
form, so you only ever type a password. **Your password is not recorded anywhere.** Papyrus keeps
a hash and no copy that could sign you in, so use **Forgot your password?** if you lose it rather
than expecting the installer to know it.

**The address is checked for shape, not for existence**, and no email is sent: a self-hosted
install on your own machine has nowhere to send it. So `you@example.com` is a perfectly good login,
and your account is marked verified immediately rather than being parked on a verification page
that can never complete. If you later switch `PAPYRUS_DEPLOYMENT_MODE` to `shared` or `public` so
other people can reach your install, re-verify your own address from settings first, because an
account made in single mode was never proven to control its address.

Lost the account entirely, or scripting a second install? The command still exists and is still
the right tool for an unattended one:

```bash
php artisan users:create --email=you@example.com --name="Your Name"
```

It generates a password, prints it **once**, and marks the address verified. Add `--admin`
for an administrator, and `--no-email` on a box with no mail transport. Use
`--password=` only for an unattended install, and note that it lands in your shell history;
the command says so when you do.

The password is not recoverable from the app. If you lose it, make another account and
delete the old row.

If you want open registration, set `PAPYRUS_DEPLOYMENT_MODE=shared` instead.

### The queue worker is not optional

```bash
php artisan queue:work
```

It has to be running alongside the site, and nothing in the app tells you when it is not. Three
features dispatch to the queue and do nothing at all without a worker serving it: **Check
series** on the library page, download syncing, and backup import. Each dispatches a job, the
job waits in the `jobs` table, and nothing happens, so the button reports success and the page
carries on showing the state it was in before you pressed it. A dispatch to a queue nobody is
serving leaves a run reading `running` for ever.

`--timeout=300` is worth passing, because the default of `60` kills a migration search measured
at 27.6s and a job killed at its timeout leaves no record at all. `composer dev` does not honour
it: it runs `queue:listen --tries=1 --timeout=0`, so start the worker by hand.

Start both, which is the supported way, and is what `packaging/dev-workers.ps1` does:

```powershell
powershell -File packaging/dev-workers.ps1          # start them
powershell -File packaging/dev-workers.ps1 -Status  # what is up, changing nothing
powershell -File packaging/dev-workers.ps1 -Stop    # stop them
```

**`schedule:work` is needed for two things and only two**, which is why it was easy to leave out:
the daily `VACUUM INTO` database snapshot at 03:17, and the sweep that dispatches bulk library
checks to readers who did not press the button. It is not needed for the work that books its own
next pass through the queue; `docs/ENGINEERING-NOTES.md` records what broke when that was left to
the scheduler alone.

### How to tell the queue is not being served

This has been the most expensive hour in the project's history, so it is worth being precise about
why it is invisible.

**A `queue:work` process existing tells you nothing.** A packaged install runs one, it drains
*its* database, and from outside it is indistinguishable from one that would have drained yours. A
bulk library check then sits in `jobs` with `reserved_at` null and the page reads `Checked 0 of 670
series` for ever. Every other part of the system looks fine.

```bash
php artisan queue:work --timeout=300   # in a terminal, if you want to see why
```

A packaged install's `papyrus health` reads the queue table rather than the process list, and says
so in words:

```
FAIL  queue      NOT DRAINING, 1 queued and the oldest has been due for 70 minutes with no
                 worker taking it. A queue:work process may be running for a different install.
```

`packaging/dev-workers.ps1 -Status` reports the same thing for this checkout.

**One trap on a Herd machine.** `php` on `PATH` is `herd\bin\php.bat`, a batch wrapper, and a worker
started through it comes up, appears in the process list, and drains nothing. Six jobs sat unreserved
for twenty-five minutes beside a perfectly healthy-looking `queue:work`, and the database had no
write-ahead log beside it, which is what showed that nothing had it open at all. The script resolves
`herd\bin\php84\php.exe` instead, and refuses with instructions rather than starting through a
wrapper. If you start a worker by hand, use the interpreter rather than whatever `php` resolves to.

**Do not set `QUEUE_CONNECTION=sync` to avoid this.** It is the one-line fix and it is a trap.
`CheckLibrary` guards its self-re-booking chain with a static depth, so under `sync` the chain stops
after about two slices: a full library check checks roughly a hundred series, stops, and reports
that nothing more is due without ever having looked. That is worse than a stalled bar, because it
looks like success.

## How the code is laid out

```
app/Suwayomi/       Everything that talks to Suwayomi
app/Search/         Fan-out, dedupe, health, and the filter vocabulary
app/Library/        Shelve filing, background checks, continue reading, the chapter navigator
app/Migration/      Finding a series on another source and moving state to it
app/AniList/        Title matching, the OAuth flow, progress push and import, per reader
app/Covers/         Reader-supplied cover art, which Suwayomi cannot store
app/Downloads/      The per-reader ledger against Suwayomi's shared queue
app/Local/          The local folder source Suwayomi picks up from disk
app/Jobs/           Ten queued jobs, five of which re-book themselves
app/Http/           The reader, the cover route, and the image proxy
app/Models/         Side tables, all user-scoped or all global
app/Console/        Artisan commands
resources/views/pages/   Livewire pages, one file each
config/papyrus.php  Timeouts, thresholds, and the measured numbers
```

Four of the 41 keys in `config/papyrus.php` are read by nothing. They are listed, with the
rest, in [`docs/ENGINEERING-NOTES.md`](docs/ENGINEERING-NOTES.md) section 14, along with
the public methods nothing calls and the claims in these documents that the code does not
back up.

### app/Suwayomi

| Class | Role |
|---|---|
| `Transport` | The HTTP call. Owns timeouts. |
| `Gateway` | The single door. One re-auth on a rejected token, then a typed exception. |
| `AuthManager` | Logs in, renews with the refresh token, never guesses a token's lifetime. |
| `Jwt` | Reads `exp` out of a token so the lifetime does not have to be configured. |
| `SourceCatalog` | Mirrors stores and sources out of the server. Every write to those tables. |
| `ExtensionManager` | The extension catalogue, install and uninstall, and the per-user trust decision. |
| `SourceFilterTranslator` | Tachiyomi's positional filter format into something nameable. |
| `SeriesGateway` | One series, its chapter list, and the batch forms of both. |
| `DownloadGateway` | Suwayomi's shared download queue: read it, enqueue, dequeue, delete. |
| `SuwayomiException` | Typed failures with the kind, so callers can branch instead of matching strings. |
| `FailureKind` | The enum those failures carry: timeout, unauthenticated, unavailable, query, unknown. |

`SourceCatalog` and `ExtensionManager` split on purpose: one owns what the server says is
registered, the other owns the catalogue and what this user decided to do about it.

### app/Search

| Class | Role |
|---|---|
| `SearchFanout` | Picks eligible sources and dispatches the per-source jobs. |
| `SourceSearcher` | Runs one source's search and classifies the outcome. |
| `QueryParser` | Splits `label:value` out of a typed query. An unknown label stays text. |
| `FilterVocabulary` | The three terms that ship, the pattern that finds each source's own name, and whether it is a filter or an order. |
| `FilterPlan` | Per source: what to push, what it cannot express, what it cannot sort by. |
| `FilterValue` | Maps our value onto that source's option, which is a different shape per filter kind. |
| `TitleNormalizer` | Folds titles so two spellings of one series meet. |
| `Deduplicator` | Collapses the same series found on several sources into one entry. |
| `DedupeGroup` | One series and every source it turned up on. |
| `HealthRecorder` | Folds one outcome into the ledger, separating failed from reachable-but-silent. |
| `HealthDashboard` | Reads the ledger back, grouped by site. |

### app/Support

| Class | Role |
|---|---|
| `ReadingState` | Every read of and write to a series or chapter state. The only path to those rows. |
| `ReaderSettings` | Resolves and clamps the reader's settings across five layers. |
| `SessionOverride` | The topmost layer: colour filters that last for the session and no longer. |
| `ChapterNotes` | Per-chapter notes, one per chapter, over a polymorphic table that predates the feature. |
| `Library` | The library page's data: entries, shelves, filing, unread, staleness. |
| `ImageUrl` | Builds proxy URLs. Nothing else may build one. |
| `LanguageName` | Human names for source language codes, which arrive as `pt-BR` and `es-419`. |
| `Deployment` | Reads the deployment mode. Only its registration rule is wired up. |
| `Suwayomi\Timestamps` | Reads Suwayomi's epoch values, which are not all in the same unit. |

## Three things worth knowing before you touch it

**Suwayomi is the source of truth for parsing, and it is single-tenant.** It resolves every
request to `Admin(1)` and has no multi-user mode. So Laravel owns 100% of user state:
library, categories, read state, progress, notes, settings. Suwayomi is called for
`fetchSourceManga`, `fetchMangaAndChapters`, and `fetchChapterPages`, and for nothing else.

**Source ids exceed 2^53.** They arrive as strings and must stay server side. Round-tripping
one through JavaScript corrupts it. `Timestamps` and the models handle the conversion.

**This Flux build ships no tabs.** Use `flux:button.group`. And `@js()` does not compile
inside a component tag's attribute, which leaves literal text in the DOM for Alpine to choke
on. Use `{{ Js::from($x) }}`.

**A plain document is not a Livewire page, so nothing is automatic.** Alpine happens to load
here because `partials.head` pulls in Flux and Livewire, but the reader does not use it, and
`resources/js/app.js` is empty. Anything the reader needs it wires up itself, and the one
call it must not make is waiting on `window.load`: a source that stalls on a single page
stalls the reader, and the reader starts measuring immediately instead.

The full list, with the reasoning and the measurements behind each decision, is in
[`docs/ENGINEERING-NOTES.md`](docs/ENGINEERING-NOTES.md). Read it before changing anything
in `app/Suwayomi`.

## Deployment modes

The data model is multi-tenant from the first migration regardless of mode. `single` is
simply the case where there is one user, so nothing has to reconcile.

| Mode | Registration | Quotas | Abuse controls |
|---|---|---|---|
| `single` | 404 on `/register` | none | none |
| `shared` | enabled | not implemented | none |
| `public` | enabled | not implemented | not implemented |

Set `PAPYRUS_DEPLOYMENT_MODE` in `.env`. It is read through `App\Support\Deployment`, so
branch on `isSingle()` and friends rather than reading the env var.

**The registration rule is the only part of this table the code enforces.** `enforcesQuotas()`
and `quotaFor()` exist and are covered by unit tests, and no application code calls either,
so `storage_quota_bytes` is written by nothing. `isShared()` and `isPublic()` are likewise
uncalled: `shared` and `public` are indistinguishable at runtime and both differ from
`single` in exactly one respect. The quota and moderation columns are aspirations, not
behaviour, and section 14 of the engineering notes says so where it cannot be missed.

Registration is enforced by `RegistrationMustBeAllowed`, which answers 404 rather than 403
so a closed mode does not advertise the endpoint. It took a while to notice this was
missing: `allowsRegistration()` existed and was unit tested, and nothing called it, so
`single` behaved exactly like `shared` while this table claimed otherwise.

## Commands

### The packaged launcher

A packaged install is driven by `packaging/launcher/papyrus.php`, which is plain PHP and depends
on nothing but PHP itself, so it keeps working when the application does not. From a checkout you
can run it directly:

```bash
php packaging/launcher/papyrus.php start        # bring all four processes up, then hold
php packaging/launcher/papyrus.php stop         # bring them all down
php packaging/launcher/papyrus.php status       # what this session started
php packaging/launcher/papyrus.php health       # the short answer to "is it working"
php packaging/launcher/papyrus.php diagnostics  # gather everything into one bundle
```

`start` holds the terminal open on purpose. The children are not daemons and that process is what
keeps them alive, so returning would be a stop dressed as an exit. Ctrl+C is the ordinary way down.

**`stop` works from a different terminal than the one that ran `start`,** which is what makes it
usable from a tray icon or a script. It finds the install's processes through the operating system
rather than through handles it does not have, and it names each one it could not stop.

It always exits 0, including when a process refuses to stop, because a stop that brought three of
four down is not a total failure and a script should not treat it as one. It says which process
survived and prints why it thinks that happened.

`health` exits non-zero when something is wrong, so a script can branch on it rather than parse the
table. `diagnostics` exits **zero whenever a bundle was written**, even for an install that is
completely broken, because "did the command work" and "is the install healthy" are different
questions. Pass `--zip` for one file to attach rather than eight.

A packaged install is configured entirely through environment variables, and the launcher is the
single source of truth for all of them:

| | |
| --- | --- |
| `PAPYRUS_ROOT` | the install directory |
| `PAPYRUS_DATA` | where the reader's library, database and logs live |
| `PAPYRUS_SUWAYOMI_PORT` | defaults to 4567 |
| `PAPYRUS_APP_PORT` | defaults to 8000 |

`PAPYRUS_DATA` must be outside the install, so an uninstall cannot take the library with it.
`InstallLayout` refuses a data directory inside the install rather than allowing it. Everything the
launcher writes goes there, including the resolved `php.ini` and Caddyfile, so the install directory
itself needs no write access and can live under Program Files. See
[`docs/DESKTOP-PACKAGING.md`](docs/DESKTOP-PACKAGING.md).

### The Windows installer

A packaged install can be built into one `.exe`:

```bash
php packaging/runtime/build-install.php --frankenphp=<zip> --jar=<jar>   # the install itself
php packaging/installer/build-installer.php                             # the installer for it
```

The result lands in `build/dist/Papyrus-<version>-setup.exe`. Version numbers come from `VERSION` in
the repository root, which is the only place one is written by hand; the build copies it into
`version.txt` beside the commit hash, and the installer is named from it.

It installs **per user**, to `%LOCALAPPDATA%\Programs\Papyrus`, with no elevation prompt. Silent
install is `/VERYSILENT /SUPPRESSMSGBOXES /NORESTART /SP-`.

**Your library is not inside the install.** Data goes to `%LOCALAPPDATA%\Papyrus`, so uninstalling
removes the program and leaves what you follow. Uninstalling also stops Papyrus first, through
`papyrus.php stop`, so it does not fail halfway through on files the JVM is holding. It exits 0
whether or not the stop worked: refusing to uninstall would be worse than a running process.

The Start Menu entry points at `Papyrus.cmd`, which opens a console window and keeps it. `start`
holds the terminal because the children are not daemons, so closing the window stops the app. That
is what the launcher does today; the tray icon is what replaces it.

Build inputs are fetched rather than kept. `stage-runtime.php` downloads the pinned JRE and CA
bundle and verifies both against published digests, and `stage-frankenphp.php` does the same for
the FrankenPHP archive, against a digest this project measured and committed because FrankenPHP
publishes none for that asset.

### Application commands

```bash
php artisan suwayomi:check        # connectivity, auth mode, version, drift against the snapshot
php artisan suwayomi:schema:dump  # re-introspect and commit, with a field diff
php artisan sources:sync          # mirror stores and the source catalogue
php artisan users:create --email=you@example.com
php artisan search:fanout "berserk" --lang=en --limit=6
php artisan import:run <path>     # same work as /settings/import, from a shell
```

`search:fanout` runs on the sync queue driver on purpose. It is a diagnostic, and a job
sitting silently in a queue tells you nothing.

## Tests

```bash
composer ci:check          # pint, phpstan and the suite: what CI runs
python tools/check-machine-detail.py
python tools/test-check-machine-detail.py
```

The last two are local-only and stricter than CI was, so **a local run is stricter than a green tick**.
PHPStan needs the memory limit, since the default 128M crashes its parallel workers.

## Working on this

Before you change anything: **[CONTRIBUTING.md](CONTRIBUTING.md)** holds the standards this codebase
is held to, and **[docs/ENGINEERING-NOTES.md](docs/ENGINEERING-NOTES.md)** holds 266 numbered lessons,
each one a mistake that was actually made here. Grep the notes before solving anything that sounds
familiar; several of them are about verification that reported a confident wrong answer.

Three of those lessons are worth knowing without reading the rest, because they have cost this project
real time and will cost the next person the same:

- **A fixture is not a data set.** Tests here once built a database with the reader's real library
  numbers, inserted row by row outside a transaction: 224 seconds a run, to prove nothing a small
  version does not.
- **A test that reads a clock twice is wrong however carefully the arithmetic is done**, because the
  variable is which second it lands in. Three of them did. Run a boundary test twenty times before you
  believe it, and look for a seam that injects the clock before you widening an assertion.
- **A check that reads filenames cannot find content.** The build reported "no developer data across
  12,672 files" while a real network address sat in a comment. Effort is not coverage.

Commits are local and pushes are a separate, asked-for decision, because CI minutes are somebody's
budget. See *Commits and pushes* in CONTRIBUTING.md.

### The sandbox gate

A fourth gate runs a packaged install inside Windows Sandbox, on a machine with no PHP, no Java
and an empty user profile. It is the only check here that runs where a reader runs, and it has
found four defects that the suite, the clean-room probe and the gate itself all reported as green.

```bash
# from a checkout, after building an install into build/install
mkdir build/sandbox-results
& "$env:WINDIR\System32\WindowsSandbox.exe" build\sandbox\papyrus.wsb
```

Results land in `build/sandbox-results/sandbox-report.txt`. Windows Sandbox runs one guest at a
time, so close the window and check for `vmmemWindowsSandbox` before launching again rather than
trusting the clock; three ways of running it wrong fail with no output at all, and each is recorded
in [`build/sandbox/papyrus.wsb`](build/sandbox/papyrus.wsb).
[`docs/DESKTOP-PACKAGING.md`](docs/DESKTOP-PACKAGING.md) lists what it asserts and what it found.

There is a fifth gate and it is not a command: open the page. Three bugs in this project
passed the whole suite and showed up on the first click.

Tests must not issue a Suwayomi call. `Http::fake()` covers the GraphQL endpoint. Use
`Tests\Support\MintsTokens` for tokens: the real server issues JWTs, and `AuthManager`
reads the expiry from the token, so a fake returning the string `"token-1"` exercises the
wrong path entirely.

## Licence and posture

MIT, see [`LICENSE`](LICENSE).

Ship no content, no bundled source list, no hosted index. Users supply their own store URLs.

Tachiyomi was taken down despite this architecture. That is not immunity, and this is not
something to monetise.

## Documentation

| Document | What is in it |
|---|---|
| [`docs/SETUP-SUWAYOMI.md`](docs/SETUP-SUWAYOMI.md) | Installing and configuring Suwayomi, including why it runs as a JAR rather than a Windows Service |
| **[docs/INSTALL.md](docs/INSTALL.md)** | **Installing on Windows, for a reader. Where the library goes, backups, and troubleshooting by symptom** |
| **[docs/SOURCES.md](docs/SOURCES.md)** | **Finding and using sources. The one page you cannot skip, because Papyrus ships none** |
| **[ARCHITECTURE.md](ARCHITECTURE.md)** | **How it is put together and why. Two databases, four processes, the launcher that is not Laravel** |
| **[ROADMAP.md](ROADMAP.md)** | **What is not built yet, by severity, and the ideas that are not agreed** |
| **[CHANGELOG.md](CHANGELOG.md)** | Every user-visible change, with the commit that made it |
| [changelog-simple.md](changelog-simple.md) | The same release notes for a reader rather than a developer |
| [docs/PORTABLE.md](docs/PORTABLE.md) | Running without installing. Not shipped yet, and this says honestly what is unproven |
| [`docs/SECURITY.md`](docs/SECURITY.md) | What this deployment does and does not protect you from, and the three secrets in it |
| [`docs/DISTRIBUTION.md`](docs/DISTRIBUTION.md) | What we ship and refuse to ship, signing, backup, publishing |
| [`docs/DESKTOP-PACKAGING.md`](docs/DESKTOP-PACKAGING.md) | The Windows packaging work, phase by phase, and the defect table |
| [`docs/ENGINEERING-NOTES.md`](docs/ENGINEERING-NOTES.md) | The working agreement. Every measurement taken, every trap found, every decision and its reasoning |
| [`docs/READER-PLAN.md`](docs/READER-PLAN.md) | The original reader design and what changed from it |
| [`CONTRIBUTING.md`](CONTRIBUTING.md) | The gates, the conventions, and the traps that will waste your time |
