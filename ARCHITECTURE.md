# Architecture

How Papyrus is put together, and why it is put together that way. Written for someone about to change
it, not someone about to use it.

For how to run it, see [README.md](README.md). For what is planned, see [ROADMAP.md](ROADMAP.md).

---

## The one sentence

> Suwayomi parses. Papyrus presents.

Everything architectural follows from that division. **Suwayomi** is a Kotlin server that talks to
manga sites, understands their HTML, and can download chapters. **Papyrus** is a Laravel application
that keeps a library, remembers what you have read, and shows you pages. Neither can do the other's
job: Suwayomi has no concept of your shelves or your reading progress, and Papyrus cannot parse a
site even if you asked it to.

The practical consequence is that **most of Papyrus's hard problems are someone else's API**. A
chapter goes missing because a site changed its markup and Suwayomi's extension for it has not been
updated. That is not a bug in Papyrus and Papyrus cannot fix it. This shapes how errors are
reported: when something goes wrong, the question is usually "which side of the line", and answering
that wrong wastes days.

---

## The shape

```
                    ┌─────────────────────────────────────────┐
   browser ────────▶│ FrankenPHP  (PHP 8.5 + Caddy)           │
                    │   serves the Laravel app on :8000       │
                    └───────────────┬─────────────────────────┘
                                    │
                    ┌───────────────▼─────────────────────────┐
                    │ Papyrus  (Laravel 13, Livewire 4, Flux) │
                    │   library, read state, shelves, reader  │
                    │   GraphQL client ─────────────┐          │
                    └───────────────┬───────────────┼──────────┘
                                    │               │
              ┌─────────────────────┘               │
              │                                     │
  ┌───────────▼──────────────┐         ┌────────────▼─────────────────────┐
  │ php artisan queue:work   │         │ Suwayomi-Server (Kotlin, JVM)   │
  │ php artisan schedule:work│         │   on :4567, GraphQL at          │
  │                         │────────▶│   /api/graphql, H2 database     │
  └──────────────────────────┘  reads  └──────────────────────────────────┘
                                     and writes
```

Four processes, started in dependency order: Suwayomi first because it is slowest and everything else
needs it, then migrations, then the web server and the two workers.

### The four processes

| Process | What it is | What it does |
| --- | --- | --- |
| `suwayomi` | `java -jar Suwayomi-Server-*.jar` | Talks to manga sites. Owns the manga database. |
| `web` | `frankenphp run` | Serves Papyrus. Embeds PHP 8.5. |
| `queue` | `php artisan queue:work` | Downloads chapters, runs library checks. |
| `scheduler` | `php artisan schedule:work` | Runs the schedule. Shells out to `schedule:run`. |

All four are launched by one supervisor. Nothing else may start them, because the supervisor is the
only thing that knows the correct order, the environment and the ini path.

---

## Two databases, and this matters

There are two databases and they are not interchangeable.

| | Suwayomi's | Papyrus's |
| --- | --- | --- |
| Engine | **H2**, inside a running JVM | **SQLite**, one file |
| Owns | manga, chapters, downloads, extensions | series, read state, shelves, settings |
| Locked while running | yes, exclusively | no, but do not copy it casually |
| Reachable as | `database.mv.db` | `database.sqlite` |

**Papyrus stores no manga.** Every page image is fetched through Suwayomi at read time. Papyrus's own
database holds only what a reader did, which is roughly 867 rows of series and 21,447 chapter states
on a real library.

Two consequences that have bitten this project and will bite you:

1. **You cannot copy Papyrus's `database.sqlite` while the app is running.** It has a write-ahead log
   beside it holding rows not yet in the main file. Copying the `.sqlite` on its own appears to work
   and quietly loses them. Copy both, or stop the app first. Suwayomi's H2 file has no such problem
   because it is a single file, but it *cannot be copied at all* while the JVM holds it, which is why
   Papyrus takes its own backup rather than trying.
2. **Copying the wrong half succeeds and reads zero.** This happened here. A `database.sqlite` in the
   wrong directory is a valid SQLite file containing no rows, so every count reads 0 and no error is
   raised.

---

## Where things live

### The program and the data are deliberately separate

| | Installed | Portable |
| --- | --- | --- |
| Program | `%LOCALAPPDATA%\Programs\Papyrus` | wherever the reader put it |
| Data | `%LOCALAPPDATA%\Papyrus` | `papyrus-data` beside it |

The rule is in [`packaging/launcher/src/DataLocation.php`](packaging/launcher/src/DataLocation.php)
and it exists because of two facts: a folder under Program Files is read-only for an ordinary
account, and a portable install means "it goes wherever I put it". So data goes beside the program
when that is possible, and under `%LOCALAPPDATA%` when it is not.

**Uninstalling deletes only the program.** The installer has no `[UninstallDelete]` section, and there
is an automated check on every build. An installer that deletes a reader's library on removal is the
worst thing this project could ship.

### The install layout

```
build/install/                  what the installer packages
  Papyrus.cmd                   the entry point, CRLF in the build
  artisan, composer.json        enough to run, not to develop
  version.txt                   version, commit, build time
  suwayomi.json                 the Suwayomi release this build expects
  runtime/
    bin/php.exe                 PHP 8.5 with pdo_sqlite, mbstring, curl, gd, intl
    bin/frankenphp.exe          the web server
    php/php.ini                 the template, with {{RUNTIME}} to substitute
    cacert.pem                  Windows PHP has no default CA bundle
  suwayomi/
    Suwayomi-Server-*.jar       174.5 MB
    runtime/                    a staged JRE
  app/, config/, database/, public/, resources/, routes/, vendor/
```

The build is five scripts, run in this order:

```
stage-frankenphp.php  fetch and verify the FrankenPHP archive
stage-runtime.php     the pinned JRE and the CA bundle
build-install.php     assemble build/install, and verify 28 required paths
build-installer.php   compile papyrus.iss with ISCC into build/dist
build-portable.php    package build/install as the portable zip
```

`REQUIRED_AFTER_BUILD` in `build-install.php` is the list; it holds 28 entries on 2026-11-02.

`build-install.php` **deletes and rebuilds `build/install` in place**, so nothing may be running out
of it during a build. It ends by loading the launcher from the tree it just built and booting it,
which is how a build that would fatal on first run is caught before it ships.

---

## The launcher is not Laravel

[`packaging/launcher/src/`](packaging/launcher/src) is 46 plain PHP classes with **no autoloader and no
dependencies**. `papyrus.php` requires them in dependency order by hand.

This is deliberate and it is the most important structural fact about the packaging layer. The
launcher has to work on a machine where nothing is installed, before PHP extensions are configured,
before the database exists, and before Laravel can boot. Anything it could get wrong would be a
launcher that cannot report why it is broken.

**Adding a launcher class means adding it to `papyrus.php`'s require list.** A test enforces that
every file in `src` is required, in both directions, because a class that exists but is not required
is invisible to the suite and fatal at runtime.

The launcher's own PHP **loads no ini at all**. It is invoked with no `-c` and no `PHPRC`. That is why
it cannot touch a database itself and has to shell out to PHP for anything that needs one, and it is
why every process it starts is given the ini explicitly. See lesson 224 in
[ENGINEERING-NOTES.md](docs/ENGINEERING-NOTES.md).

---

## Talking to Suwayomi

One client: [`app/Suwayomi/Gateway.php`](app/Suwayomi/Gateway.php). Everything else goes through it.

- Endpoint is **`/api/graphql`**, not `/graphql`. `/graphql` returns 404 and looks exactly like
  "Suwayomi is unreachable".
- Login is a **GraphQL mutation**, `mutation Login($input: LoginInput!)`. There is no REST login
  endpoint.
- Access tokens last **300 seconds**. The expiry is read off the token, never assumed.
- An expired token returns **HTTP 200 with a GraphQL error** rather than a 401, so an auth failure
  that is not detected looks like a schema error.

Three traps that have each cost an evening here, all of them the same shape: **the check passed over
a Suwayomi that refused everything.**

- `aboutServer` is **not** `@RequireAuth`. It answers 200 on a server that rejects every real query.
  The query that is behind the login is `{ sources { totalCount } }`.
- Suwayomi's schema is generated at runtime and is unversioned. A committed introspection snapshot is
  the only way to notice drift.
- Suwayomi has no migration primitive at all: no GraphQL field across 32 queries, 78 mutations and
  4 subscriptions can move a series between sources. The `?migrate` and `?migrate-sources` pages are
  built on the fact that you cannot.

---

## The app

Laravel 13, Livewire 4, Flux 2, Tailwind 4, Alpine. PHP `^8.4` in source; the packaged runtime is
8.5.

### UI conventions worth knowing before you touch it

- **Livewire single-file components** are `resources/views/pages/**/*.blade.php`, and a file whose
  name starts with **U+26A1** (`⚡`) holds an anonymous component. This is Flux's convention, not an
  accident, and the character is not an ASCII question mark. Writing one through a shell will fail,
  because PowerShell and .NET both refuse a path containing `?` and the real name is not `?`.
- The navlist for Settings lives in
  [`resources/views/pages/settings/layout.blade.php`](resources/views/pages/settings/layout.blade.php)
  and every settings page renders through `x-pages::settings.layout`.
- **The design is settled.** A redesign was done and is what ships. Changing markup is fine; changing
  the design is not.

### Where the code is

| Directory | What lives there |
| --- | --- |
| `app/Suwayomi/` | the gateway, gateways per area, error kinds |
| `app/Models/` | 30 models, mostly read state and import ledger |
| `app/Import/` | 19 files: reading another reader's backup |
| `app/Search/` | fan-out, because Suwayomi has no global search |
| `app/Downloads/`, `app/Covers/` | Suwayomi's download queue, and art it cannot store |
| `app/Jobs/` | `SyncDownloads`, `CheckLibrary` and friends |
| `app/Console/Commands/` | 9 commands, including `suwayomi:check` and `papyrus:snapshot` |
| `config/papyrus.php` | 20 KB of documented, measured Suwayomi constraints |

**`config/papyrus.php` is the place to look first.** Every comment in it cites a measurement or a
Suwayomi issue number. It is the accumulated knowledge of what this API can and cannot do, and it is
why several seemingly odd decisions in the code are not accidents.

---

## Failure reporting

One command, [`papyrus diagnostics`](packaging/launcher/papyrus.php), collects the logs of all four
processes into one archive.

The design rule, and it is a rule with tests: **no log line is ever discarded.** A parser either
recognises a line or returns a continuation so the line stays attached to the record above it. There
is no third answer. A parser that drops what it does not recognise destroys the evidence at exactly
the moment it becomes useful, and it does so silently, because the bundle still looks complete.

Nine parsers, one per native log format, and a normalising layer above them so the archive has one
shape regardless of which process wrote the line.

---

## Testing

The suite count moves with every commit, so measure the tree you are on with
`php -d memory_limit=2048M artisan test`. Measured on 2026-11-02 at `1346199`: 2,795 tests,
8,549 assertions, one skipped.

```
php -d memory_limit=2048M artisan test
php -d memory_limit=2G vendor/bin/phpstan analyse --no-progress --memory-limit=2G
php -d memory_limit=2048M vendor/bin/pint --test
python ..\.tools\suwayomi\check-dashes.py
python tools\check-machine-detail.py
python tools\test-check-machine-detail.py
```

Six gates, all required. Notes that are not obvious:

- **CI runs on `windows-latest`, one job, no matrix.** The product is a Windows executable. On Linux
  the process-matching tests find nothing and pass vacuously, so a green Linux job would report the
  packaging layer as covered while testing none of it.
- **PHPStan level 7 analyses `packaging/` as well as `app/`.** Adding that found four defects in one commit.
  It does not analyse `tests/`, so this is not test type checking.
- **Pint covers the whole repository.** A narrower `app/ tests/ packaging/` scope missed
  `database/` and `routes/` and let a red CI run through.
- **The installed PHPUnit rejects `--without-tty`.** The command above is the one that runs.
- **Zero em and en dashes** anywhere, in code, comments, docs and commit messages. The checker scans
  every tracked file.
- **Mutation checks** live in `.tools/suwayomi/`. A run distinguishes `killed` (covered), `SURVIVED`
  (a coverage gap), `INERT` (an ineffective mutation, which is not a gap at all) and `ANCHOR MISSING`
  (the check did not run, which is the one to take seriously).

### Two things the suite is bad at, and what is done about it

- **Anything about this machine.** `HealthTest` records three tests that once failed because a real
  worker was running, and `DevWorkersTest` has an `-Explain` mode for the same reason.
- **Fresh installs.** Three defects have been found only by installing from nothing, and every one
  was invisible from a working install. `.tools/verify-installer.ps1` runs install, start, uninstall,
  and hashes the library before and after.

---

## Conventions

- **No em or en dashes.** Not in code, not in comments, not in docs, not in commit messages.
- **Every comment explains why, and cites something.** A measurement, an issue number, or the failure
  it prevents. A comment restating the code is deleted.
- **A claim that can be checked should be checked by a test.** "It works" is not a finding; the line
  number of the thing that proves it is.
- **A check that cannot fail is not a check.** Several tests here exist to assert that other tests
  cannot pass vacuously.