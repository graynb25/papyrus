# Running Papyrus without installing it

The portable shape: a folder you copy anywhere and run. No installer, no registry, no administrator
rights.

---

## Status: not shipped yet

**There is no portable zip.** The code supports the shape and the layout logic is tested, but no
archive is produced and, as of this writing, the shape has **not** been run end to end on a real
machine. See [What is not proven yet](#what-is-not-proven-yet), which is honest about why.

What is true today:

| | |
| --- | --- |
| The data location rule | implemented and tested |
| The folder runs | yes, if you assemble it yourself |
| An archive anyone can download | **no** |
| A first launch from a clean folder | **never run** |

Do not promise a reader this works yet.

---

## What portable means here

Two shapes, and they differ only in where things go.

| | Installed | Portable |
| --- | --- | --- |
| Program | `%LOCALAPPDATA%\Programs\Papyrus` | anywhere you like |
| Data | `%LOCALAPPDATA%\Papyrus` | `papyrus-data`, **beside the program folder** |
| Registry | none | none |
| Administrator rights | not needed | not needed |
| Uninstall | Control Panel | delete the folder |

The rule is one line in
[`packaging/launcher/src/DataLocation.php`](../packaging/launcher/src/DataLocation.php):

```php
$portable = dirname($root).DIRECTORY_SEPARATOR.'papyrus-data';
```

**Beside the folder, not inside it.** Put Papyrus in `D:\Apps\Papyrus` and your library goes in
`D:\Apps\papyrus-data`. That is deliberate: a reader who copies the program to a USB stick gets one
thing to copy, and one thing to be careful not to delete.

An installed copy goes to `%LOCALAPPDATA%` instead, because that is where the installer put the
program and the reader expects their data in one predictable place.

---

## Running the portable shape today

You can assemble one from a build. This is a developer route, not a reader route.

```
php packaging/runtime/build-install.php ^
    --frankenphp=<frankenphp-windows-x86_64.zip> ^
    --jar=<Suwayomi-Server-v2.4.2366.jar>
```

That produces `build/install`, which is the portable payload: 12,641 files, 623 MB, and a
`Papyrus.cmd` in the root. Copy it anywhere and run `Papyrus.cmd`.

On first run it creates `papyrus-data` beside the folder, generates its own `.env`, applies
migrations and starts. It is a complete install, not a subset.

---

## What is not proven yet

Three things, in the order they would bite.

**1. A first launch from a clean folder has never been run.** Three defects have been found only by
installing from nothing, and every one was invisible from a working install. A portable first launch
is a fourth such path.

**2. A second Papyrus on one machine reports the first one's processes as its own.** Measured, not
theorised. Running `status` and `health` from a copy of the install in a different folder reported
**all four of the installed app's processes as healthy and running**, while failing on its own
database. The two share fixed ports, so only one can actually serve, and the loser reports the
winner's health. That is a real defect and it is not written up as one yet. It is the same mistake as
lesson 223 in [ENGINEERING-NOTES.md](ENGINEERING-NOTES.md), one level up: a process that exists is
not a process doing this install's work.

**3. The ports are fixed.** Port 8000 for the app and 4567 for the manga server, with no way to
change them. Two Windows accounts on one machine collide, and a portable copy alongside an installed
one collides. The documented behaviour for two copies is: stop the other one. The honest behaviour
would be to detect it and say so, which is item 2.

---

## Troubleshooting

Most of this assumes you have assembled the folder yourself.

### Inside the sandbox, Papyrus is not at `papyrus.test`

**This one is not a fault and cannot be fixed.** `papyrus.test` is a *host* name: a line in this
machine's hosts file pointing at `127.0.0.1`, served by Herd's nginx on port 80 of this machine. A
Windows Sandbox guest is a separate machine with its own hosts file and its own network stack, and it
has no route to this machine's port 80. A browser inside the guest asking for `papyrus.test` gets
`ERR_CONNECTION_REFUSED`, which is the correct answer.

Inside the guest, with the gate's configuration:

| | |
| --- | --- |
| Papyrus | `http://127.0.0.1:8402` |
| Suwayomi's own WebUI | `http://127.0.0.1:4571` |

Both are loopback *inside the guest* and both work. Worth stating plainly because the failure is
confusing: **Suwayomi's WebUI being fine tells you nothing about whether Papyrus is up.** They are
two different processes on two different ports, and a reader who opens the Suwayomi tab, sees it
working, and concludes the install is fine has checked the wrong one. Open the app's port.

`build/sandbox/run-in-sandbox.ps1` says the same thing in its own header, for anyone who opens the
gate rather than this document.

### "Papyrus is not installed under this launcher"

From `status`, when nothing this copy started is running. It is not an error, and it is not the
program missing: it means no process belongs to *this* folder. Either nothing is running, or
something else is, which is [the confusing case](#two-copies-report-each-others-health).

### `health` says everything is fine but the page will not load

Check `health`'s `database` line first. If it names a path you do not recognise, `health` is
describing a different install. See below.

### `FAIL database not created yet at <path>`

The data directory has not been made. It is created on first **start**, not on `status` or `health`,
which are read-only. If a start has been attempted and this persists, the launcher could not write
there: check the path exists and is writable, and that it is beside the program folder rather than
inside it.

### Two copies report each other's health

The known defect above. To tell which is which, look at the `database` line: each copy names its own
path, and they differ. Or stop everything and start only the one you want.

### A space in the folder path

Works, and is tested, and is the default for every Windows profile. Several defects in this project's
history were specifically about quoted paths, so if a portable launch fails on a path with a space in
it that does not fail anywhere else, that is worth reporting rather than working around.

### Antivirus on a freshly copied folder

The same failure as the installer, for the same reason: security software scans a newly written
623 MB folder and for that window refuses to execute anything in it. Papyrus probes its own
interpreter before starting and will name the file it could not run. Wait a minute, or exclude the
folder. See
[INSTALL.md](INSTALL.md#papyrus-did-not-start-the-lines-above-say-why).

### Moving the folder after first run

Don't, without moving `papyrus-data` with it. The database path is recorded in the generated `.env`
as an absolute path, so a moved program will look in the old location and find nothing. Moving both
folders together and editing `DB_DATABASE` in `papyrus-data\.env` is the route.

---

## Related

- [INSTALL.md](INSTALL.md), the installer, which is what a reader should use today
- [DISTRIBUTION.md](DISTRIBUTION.md), which argues portable should be built first
- [ROADMAP.md](../ROADMAP.md), where the zip stands