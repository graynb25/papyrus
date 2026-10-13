# Contributing

## The short version

```
php -d memory_limit=2048M artisan test --without-tty
php -d memory_limit=2G vendor/bin/phpstan analyse --no-progress --memory-limit=2G
php -d memory_limit=2048M vendor/bin/pint --test app/ tests/ packaging/
python .tools/suwayomi/check-dashes.py
```

All four, every time. A change is not finished until all four have been run **against the change**,
not against the branch it was based on.

Then commit and push. Both are expected without asking.

---

## Before you start

- **[ARCHITECTURE.md](ARCHITECTURE.md)** is the map. Read it first; it will save you an hour.
- **[docs/ENGINEERING-NOTES.md](docs/ENGINEERING-NOTES.md)** is 226 numbered lessons. Each one is a
  mistake that was actually made here. Grep it before solving anything that sounds familiar.
- **[docs/DESKTOP-PACKAGING.md](docs/DESKTOP-PACKAGING.md)** if you are touching the launcher, and
  **[docs/DISTRIBUTION.md](docs/DISTRIBUTION.md)** if you are touching shipping.

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

### Mutation checks

`.tools/suwayomi/mutation-*.php`. They mutate a file, run a slice of the suite, and restore.

```
php .tools/suwayomi/mutation-check-credentials.php
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

Write the commit message for someone reading `git log` in a year with no context. What broke, what
the cause was, and what you measured. Not what files changed.

Long messages are fine and preferred when the reasoning is the point. Several in this history run
thirty lines because the thirty lines are why the change is not going to be undone by the next
person who cannot see the problem.

## Pull requests

- Say what you changed and what you measured.
- Say what you did **not** do, and what you did not verify. A PR that notes "I have not run this
  against a fresh install" saves the next person an hour.
- Link the issue if there is one.
- One concern per PR. The history here has some long ones and they are all harder to review than the
  work warranted.