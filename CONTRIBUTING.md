# Working on Papyrus

The house style, and the traps. [`docs/ENGINEERING-NOTES.md`](docs/ENGINEERING-NOTES.md) has
the full agreement and **138 recorded traps**; this is the short version you need before
your first commit. Section 14 of the notes also lists what the code does not do, which is
worth reading before you trust a claim in any of these documents.

## Before you write anything

**Measure, do not infer.** Every field name, enum value and behaviour comes from the live
server or the committed snapshot in `resources/schemas/suwayomi-introspection.json`. When
research and the running server disagree, the server wins and the research gets corrected.

```bash
php artisan suwayomi:check                  # is it up, which auth mode, any drift
php artisan suwayomi:schema:dump            # introspect and diff against the snapshot
php ../.tools/suwayomi/schema-inspect.php Fetch
php ../.tools/suwayomi/field-args.php Query extensions
```

**Never re-implement a parser in PHP.** Keiyoushi extensions are Kotlin. That is the one
fact the entire architecture rests on. If a source does not exist, the options are a Kotlin
extension, a patch to Suwayomi, or one targeted parser as a genuine last resort.

**Mark inference as inference.** A comment saying "verified" must be true, or say what it
was measured against.

## Writing code

Docblocks carry the *why*, especially where a number looks arbitrary or a workaround looks
unnecessary. Someone reading this in six months needs to know that a 20 second per-source
timeout sits under a 30 second server cap, and that a 0.95 similarity threshold came from a
measurement rather than a preference.

Stay silent when the code already says it. No rhetoric in docblocks, and no comment that
restates the line beneath it.

`php -l` new files. A parse error does not fail the suite when nothing loads the file.

### Scope

- **User-owned models extend `OwnedModel`**, never a trait. PHPStan cannot reason about a
  model instance inside a trait's `creating` closure, which produced 76 errors when tried.
  Global tables extend `Model` directly.
- `user_id` is **never** in `#[Fillable]`. Write rows for someone else with
  `createFor($user, [...])`.
- The tenant scope **fails closed** with no authenticated user. A CLI reading zero rows is
  fixed by naming the user, never by relaxing the scope.
- Mirror writes live in `SourceCatalog`. Catalogue and trust live in `ExtensionManager`.
  Duplicating a method across the two is how they drift apart.

## Writing prose

No em dashes. They are the clearest single tell that a machine wrote something.

The other habits that read as generated: "it's not just X, it's Y" in any form, announcing
what you are about to do before you do it, three adjectives where one would do, bullet lists
of three where a sentence would do, and words like robust, seamless, leverage, delve,
crucial, comprehensive. Bold for emphasis rather than headings, and a neat one-line summary
at the end of every section.

Say the thing, then stop.

Check before you call it done:

```bash
php ../.tools/suwayomi/scan-dashes.php              # docs and source, everything
php ../.tools/suwayomi/list-dash-lines.php <file>   # one file, offending lines and numbers
```

Both are byte scans. An earlier PowerShell version reported zero em dashes in a file
containing thirty, so trust the byte scan and not a regex written in the wrong shell.

They are also outside this directory, at `..\.tools\suwayomi\`, since they cover the docs
in the parent folder as well.

## Tests

Name tests for the behaviour, not the method. `tests/Unit` for pure logic,
`tests/Feature` for anything touching the database.

A test that passes but never runs the browser cannot catch an Alpine error. Three bugs in
this project passed 194 tests and were visible on the first click. So:

**Open the page.** That is the fifth gate.

The suite is currently **1034 tests across 60 files**, and twenty-three Playwright scripts
live in `..\.tools\suwayomi\`. A sixth bug of the same kind is waiting for whoever reads
this next.

`Http::fake()` the GraphQL endpoint; tests must never reach the real server. Use
`Tests\Support\MintsTokens` for tokens, since a fake returning `"token-1"` has no readable
`exp` and silently exercises the wrong path.

`php artisan view:clear` after editing any `.blade.php`, or a stale compiled view will lie
to you.

## Asking

When a decision has real trade-offs, present concrete options, name the trade-off of each,
and say which you recommend. The user decides. Do not make the call and mention it
afterwards.

For a decision that was already made and might be revisited, add a row to the decisions
table in `AGENT.md` with the reasoning. The reasoning matters more than the choice, since
the next person will have a different constraint.
