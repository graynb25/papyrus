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
source management, health, the series page and the reader all work.

| Screen | Route | What it does |
|---|---|---|
| Search | `/search` | Fans one query out across every eligible source, deduplicates, shows per-source outcomes |
| Sources | `/sources` | Extension stores, signing keys, trust decisions, and the 1,379 extension catalogue |
| Health | `/health` | Per-site health, grouped because 82 sources are really 15 sites |
| Library | `/library` | Everything you added, filed into shelves, with unread counts |
| Series | `/series/{id}` | Chapters with dates, scanlator filtering, per-series reading mode |
| Reader | `/read/{manga}/{chapter}` | Continuous scroll or paged, progress saved per user |

`/dashboard` redirects to `/library`.

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
SUWAYOMI_USERNAME=your-suwayomi-user
SUWAYOMI_PASSWORD=a-password-only-you-know
```

Generate that password rather than reusing one. Suwayomi's REST endpoints answer `401`
without a token, and it holds the credentials for every manga account you configure in
it, so it is worth treating as a secret even on a loopback address. See
[`docs/SECURITY.md`](docs/SECURITY.md) for the deployment notes.

Register a store and mirror the catalogue:

```bash
php artisan suwayomi:check
php artisan sources:sync --add-store=https://github.com/keiyoushi/extensions/raw/repo/index.pb
php artisan sources:sync --refresh
```

Two long-running processes, and the app is quietly broken without both:

```bash
npm run dev                # otherwise new Tailwind classes are missing from the CSS
php artisan queue:work     # otherwise a search never finishes
```

Then open the site. `composer dev` starts all three at once if you would rather not keep
separate terminals.

## How the code is laid out

```
app/Suwayomi/       Everything that talks to Suwayomi
app/Search/         Fan-out, dedupe, health
app/Models/         Side tables, all user-scoped or all global
app/Console/        Artisan commands
resources/views/pages/   Livewire pages, one file each
config/papyrus.php  Timeouts, concurrency, and the measured thresholds
```

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
| `SuwayomiException` | Typed failures with the kind, so callers can branch instead of matching strings. |

`SourceCatalog` and `ExtensionManager` split on purpose: one owns what the server says is
registered, the other owns the catalogue and what this user decided to do about it.

### app/Search

| Class | Role |
|---|---|
| `SearchFanout` | Picks eligible sources and dispatches the per-source jobs. |
| `Deduplicator` | Collapses the same series found on several sources into one entry. |
| `HealthRecorder` | Folds one outcome into the ledger, separating failed from reachable-but-silent. |
| `HealthDashboard` | Reads the ledger back, grouped by site. |

### app/Support

| Class | Role |
|---|---|
| `ReadingState` | Every read of and write to a series or chapter state. The only path to those rows. |
| `ChapterStateFactory` | Builds the display model for a chapter list from state plus source data. |
| `ReaderSettings` | Resolves and clamps the reader's settings: defaults, profile, series. |
| `Library` | The library page's data: entries, shelves, filing, unread, staleness. |
| `ImageUrl` | Builds proxy URLs. Nothing else may build one. |
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
| `single` | disabled | none | none |
| `shared` | enabled | per-user | none |
| `public` | enabled | per-user | moderation surfaces |

Set `PAPYRUS_DEPLOYMENT_MODE` in `.env`. It is read through `App\Support\Deployment`, so
branch on `isSingle()` and friends rather than reading the env var.

## Commands

```bash
php artisan suwayomi:check        # connectivity, auth mode, version, drift against the snapshot
php artisan suwayomi:schema:dump  # re-introspect and commit, with a field diff
php artisan sources:sync          # mirror stores and the source catalogue
php artisan search:fanout "berserk" --lang=en --limit=6
```

`search:fanout` runs on the sync queue driver on purpose. It is a diagnostic, and a job
sitting silently in a queue tells you nothing.

## Tests

```bash
php artisan test
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
```

`composer test` runs all three. PHPStan needs the memory limit, since the default 128M
crashes its parallel workers.

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
| [`docs/SECURITY.md`](docs/SECURITY.md) | What this deployment does and does not protect you from, and the two secrets in it |
| [`docs/ENGINEERING-NOTES.md`](docs/ENGINEERING-NOTES.md) | The working agreement. Every measurement taken, every trap found, every decision and its reasoning |
| [`docs/READER-PLAN.md`](docs/READER-PLAN.md) | The original reader design and what changed from it |
| [`CONTRIBUTING.md`](CONTRIBUTING.md) | Local setup and the gate sequence |
