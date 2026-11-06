# Contributing

## The short version

```
composer ci:check
python tools/check-machine-detail.py
python tools/test-check-machine-detail.py
```

`composer ci:check` is what CI runs: pint, phpstan, and the suite. The last two are local-only and
are stricter than CI was, so **a local run is stricter than CI**. Run all of it, every time. A change is
not finished until it has been run **against the change**, not against the branch it was based on.

Then commit.

**Push is a separate decision, and it is not yours to make alone.** See *Commits and pushes* below.
Local commits are free. A push spends CI minutes that somebody is paying for.

---

## Before you start

- **[ARCHITECTURE.md](ARCHITECTURE.md)** is the map. Read it first; it will save you an hour.
- **[docs/ENGINEERING-NOTES.md](docs/ENGINEERING-NOTES.md)** is 270-odd numbered lessons. Each one
  is a mistake that was actually made here. Grep it before solving anything that sounds familiar.
- **[docs/DESKTOP-PACKAGING.md](docs/DESKTOP-PACKAGING.md)** if you are touching the launcher, and
  **[docs/DISTRIBUTION.md](docs/DISTRIBUTION.md)** if you are touching shipping.

## How work happens here

This project is developed with an AI agent against a human reviewer, and the standards below come
from that arrangement rather than from taste.

**Plan before you build.** Investigate what already exists first. Read the code, the tests, the gates,
and the notes. Find the existing seam rather than adding a parallel one. Write the plan down, say what
could break, and get agreement before doing anything larger than a one-line fix.

**DRY is a requirement, not a preference.** One seam per responsibility. If a method already owns the
thing, extend it.

**Measure, never assume.** Do not say how something works until you have read it. Do not report a
number you did not measure, and do not present an inference as a measurement: if it is inferred, say
so in the same sentence. A surprising result is a reason to look, not a reason to report.

**Ask before anything risky.** That means pushing, writing to a tracked file that has shipped, writing
to an install or a data directory, starting or stopping an install, launching a sandbox, deleting, or
changing a gate or a baseline. The shape of the ask is the point:

> **Proposed:** what you would do. **Why:** measured, not assumed. **Risk:** what breaks if you are
> wrong and how it is undone. **Cost:** in minutes or money. **Recommended:** what you think should be
> chosen. **Reply with:** `go`.

Ask even when you are confident. A confident wrong answer costs more than a question.

**No know-it-all framing.** Do not write "obviously", "simply", "of course", "as I mentioned", or "I
already told you". Each is a way of signalling that the reviewer does not need to read this, and every
one has been wrong.

**Tests are for behaviour, not for production.** A fixture is not a data set. Do not size one like the
reader's library because those numbers are to hand: a test that inserts 22,332 rows one transaction at
a time spends four minutes proving nothing the small version does not. Use a handful of rows and let a
real install answer questions about scale.

The suite runs on `DB_DATABASE=:memory:` from `phpunit.xml`. A test asserts that setting, so a
test that reaches for `database/database.sqlite` fails before it can read the reader's library.

**Every finding carries six things:** severity (S, H, M, L), phase, risk, why it should be fixed, and
a how that has been verified. Status is one of five marks, and the two that are not obvious are worth
naming, because the difference between them is the whole point:

| | Meaning |
| --- | --- |
| done, verified | the verification is named |
| not done | live risk |
| risky, needs planning | we know and it is dangerous. A decision waiting to be made properly |
| not yet measured | nobody has established what it costs, so it cannot honestly be graded yet |
| deferred | a decision not to do it now, and what would change it |

**A finding stays ungraded until it has been measured.** Assigning a severity from reasoning about
which branch was not looked at is a guess wearing a severity.

## Conventions

**No em dashes and no en dashes.** Not in code, not in comments, not in docs, not in commit messages.
The checker scans every tracked file and it is why several comments here are phrased the long way
round. If it fails you, you will be told which file.

**Comments explain why, and cite something.** A measurement, an upstream issue number, or the specific
failure the comment prevents. A comment restating the code is deleted. This is the single most
distinctive thing about this codebase and the thing most worth preserving.

```
Good:  // Measured: an access token carries exp = issued + 300, so the lifetime is never assumed.
Bad:   // Increment the counter.
Bad:   // See the comment above.
Worse: // This fixes the bug where schedule:run had no ini.
       // (and schedule:run's ini is fixed in three other files, all of which say so)
```

**State a measurement, not an opinion.** "361ms to 1617ms with an 876ms median" tells a reader whether
the claim still holds. "fast" does not.

**Say which side of the line a bug is on.** Most hard problems here are somebody else's API. Papyrus
keeps the library and cannot parse a site; the manga server parses sites and has no concept of your
shelves. Naming which component is responsible is most of the work of fixing anything.

## Adding to the launcher

[`packaging/launcher/`](packaging/launcher) is plain PHP with **no autoloader**. A new class is
invisible to the test suite and fatal at runtime unless you also add it to `papyrus.php`'s require
list, in dependency order. A test enforces that every file in `src` is required, in both directions,
but it cannot tell you the right *order*.

Two more things that are specific to that layer:

- The launcher's own PHP loads **no ini at all**. It cannot touch a database. Anything needing one
  shells out to the install's PHP with the ini named explicitly.
- Anything the launcher spawns needs the ini **and** `PHPRC`, because a flag reaches the process you
  pass it to and an environment variable reaches everything it spawns. Lesson 224.

## Translations

English is the source language. Spanish (`es`), French (`fr`), and Brazilian Portuguese
(`pt_BR`) are translated catalogs and are AI drafts pending native review. Do not call a
catalog reviewed until a native speaker has reviewed it.

To update an existing catalog:

- Add or change the English source sentence where it is used. Do not rename existing
  sentence keys as part of a translation change.
- Add the value to every `lang/{locale}.json` for the translated catalogs. Keep exactly
  the same key and preserve every `:placeholder`, every `|` plural branch and
  `{1}`/`[2,*]` selector, and every HTML fragment or punctuation contract.
- Put framework strings in `lang/{locale}/validation.php`, `auth.php`, `passwords.php`,
  and `pagination.php`, mirroring the English and Spanish key shape.
- Keep proper nouns and technical identifiers as names: Papyrus, Suwayomi, PHP,
  JavaScript, package names, licence names, URLs, file paths, and BCP 47 codes.
- Use UTF-8 with no BOM, LF line endings, and no em dashes or en dashes.

To add a language:

1. Add its code to `supported` in `config/papyrus.php`.
2. Create the sentence catalog and the four framework groups.
3. Add or extend the catalog parity, placeholder, branch, and locale route tests.
4. Add the code to `catalogued` only after the catalogs are complete and the focused
   localization tests pass.
5. Update `lang/README.md`, `README.md`, `ARCHITECTURE.md`, and this file if the process
   changes.

The catalog parity tests compare every translated catalog against `lang/es.json` and the
framework groups against the Spanish shape. They also reject English values that were left
behind. A missing translation, a changed placeholder, or a dropped plural branch fails the
test rather than falling back silently. `SpanishCatalogTest` also reads literal `__()` and
`trans_choice()` calls in the covered reader-facing files, including concatenated strings,
so a sentence added there without a catalog entry fails the test.

Legal pages, the FAQ, the installer, the launcher, and raw source, server, passkey, or
exception text remain English by scope.

## Tests

- **A claim that can be checked should be checked by a test.** "It works" is not a finding.
- **A check that cannot fail is not a check.** If every assertion happens to match a named case, the
  dangerous branch is unreachable and the test proves nothing. When you write a test, consider how it
  could pass without the fix being present, and if the answer is "it could not", find another one.
- **Never write a test that reports what the machine is doing** rather than what the code does.
  Several here once failed because a real worker was running on the machine that ran them. Stub the
  thing rather than observing it.
- **Confirm a new test fails before you keep the fix.** Revert the fix, run the test, watch it fail,
  restore. A test that has never failed has not been shown to test anything.
- **Run a test more than once if it touches time, ports or processes.** One green run of a boundary
  test is not evidence. Three tests here read the clock twice and compared the two readings, which is
  wrong however carefully the arithmetic is done, because the variable is which second it lands in.
  One failed on a real run with `3601 is identical to 3600`. Run it twenty times before you believe it,
  and look for a seam that injects the clock before you widening an assertion. Lesson 265.

### Mutation checks

`..\.tools\suwayomi\mutation-*.php`. They mutate a file, run a slice of the suite, and restore.

```
php ..\.tools\suwayomi\mutation-check-credentials.php
```

Four outcomes, and the distinction matters more than the count:

| Outcome | Meaning |
| --- | --- |
| `killed` | a test failed, so the mutation is covered |
| `SURVIVED` | the tests passed against broken code. A real gap |
| `INERT` | an **ineffective** mutation: it changed nothing, so no test could have caught it. Not a gap |
| `ANCHOR MISSING` | the mutation did not match the source. **It checked nothing and exits 2** |

A run that checked nothing is not a passing run. `ANCHOR MISSING` is the one to take most seriously.

## Things that will waste your time if you do not know them

- **CI runs on `windows-latest`, one job, no matrix, on purpose.** The product is a Windows
  executable. On Linux the process-matching tests find nothing and pass *vacuously*, so a green Linux
  job would report the packaging layer as covered while testing none of it. Do not add a Linux job.
- **Nothing may be running out of `build/install` during a build.** The build rewrites it in place.
- **`Papyrus.cmd` is LF in the repository and CRLF in the build.** The build normalises and then
  fails if any bare LF survives. Do not "fix" this with `.gitattributes`.
- **Two databases, and copying the wrong one succeeds.** The manga server's is H2 inside a running
  JVM and holds the manga. Papyrus's is SQLite and holds what the reader did. A valid SQLite file in
  the wrong directory reads as zero rows with no error.
- **Copy `database.sqlite` with its `-wal`.** The write-ahead log holds rows not yet in the main file.
- **A fresh install is the only place certain defects are visible.** Run
  `.tools/verify-installer.ps1` after touching the launcher, `Papyrus.cmd`, the data location rule or
  the installer.
- **Livewire single-file components are named with U+26A1 (`⚡`)**, not an ASCII question mark. Writing
  one through a shell fails, because PowerShell and .NET both reject `?` in a path.
- **The design is settled.** A redesign was done and is what ships. Change markup freely; do not
  change the design.

## Commits

Write the commit message for someone reading `git log` in a year with no context. What broke, what the
cause was, and what you measured. Not what files changed.

Long messages are fine and preferred when the reasoning is the point. Several in this history run
thirty lines because the thirty lines are why the change is not going to be undone by the next person
who cannot see the problem.

**Use the public identity in a rewritten clone.** The global git identity may be the personal one.
Set `user.name` and `user.email` to the public identity in the clone and verify `%an <%ae>` and
`%cn <%ce>` before pushing. A follow-up commit on 2026-11-01 was authored with the personal
identity and had to be amended before it could be pushed.

## Commits and pushes

These are two permissions and only one of them is usually granted.

**A commit is free. A push is not.** A push starts a CI run, and CI runs on a shared, limited
allowance. **Commit locally until the entire body of work is finished**, not between steps and not
between commits in one task, and ask before pushing.

State the cost before anything that triggers a run. The suite takes about 11 to 12 minutes
locally and about 10 to 14 minutes on a Windows runner. The 46 to 80 minute runs on
2026-10-14 and 2026-10-15 were a full-database mistake, not the baseline. The suite must
use `DB_DATABASE=:memory:` from `phpunit.xml`; a test fails if that setting drifts.

`concurrency` is set with `cancel-in-progress: true`, so a second push cancels the first rather than
stacking with it. That limits the damage of a mistake. It is not permission to push.

Measured, so this is not theoretical: normal runs on 2026-10-13 and 2026-10-14 were
8m24s to 13m19s. The 46 to 80 minute runs were the full-database anomaly. Five pushes in
one session still show why a push is a separate decision, but a normal push costs about
10 to 14 minutes, not an hour. **A standing permission to commit is not a standing
permission to push**, and reading it as one is the single most expensive mistake in this
file. Lesson 264.

## Pull requests

- Say what you changed and what you measured.
- Say what you did **not** do, and what you did not verify. A PR that notes "I have not run this
  against a fresh install" saves the next person an hour.
- Link the issue if there is one.
- One concern per PR. The history here has some long ones and they are all harder to review than the
  work warranted.