# Running Papyrus without installing it

The portable shape: a folder you copy anywhere and run. No installer, no registry, no administrator
rights.

---

## Status: locally built, clean-machine read not verified

The portable builder rebuilt the 1.5.1 zip and matching SHA256 sidecar on 2026-10-29 from commit
`7f964a1`. The archive has 12,964 entries, includes the launcher and bundled runtimes, and
contains no `.env` or reader databases. It is not published, and it has not been launched on a
clean machine. The 1.5.0 install tree passed the Windows Sandbox gate on run 19 (86 checks, 0
failures, including a real source page read), but the portable shape was not the thing tested,
and the gate copies `build/install` rather than running the setup executable. The separate manual
sandbox ran the 1.5.0 installer on 2026-10-26: it installed 1.5.0 per-user and the reader flow
passed, but the shortcut preflight hung; the fix is committed in `86e033a` and the 1.5.0 archive
was rebuilt from it. Those runs prove 1.5.0. The 1.5.1 setup passed the manual sandbox on
2026-10-29, but the 1.5.1 portable archive itself has still not been launched. See
[What is not proven yet](#what-is-not-proven-yet).

```text
sha256  c30acfe0e4ec811e3a2d5a4609080923bf4cac77b1018da1dc3b45bcf7ae8d33
```

What is true today:

| | |
| --- | --- |
| The data location rule | implemented and tested |
| The archive builder | built and content/hash verified on 2026-10-29 |
| An archive anyone can download | **no** |
| A clean first launch with source reading | **not proven** |

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

You can assemble one from a build. This is a developer route, not a reader route. Run the steps in
order; the install build deletes and recreates its output directory.

```
php packaging/runtime/stage-frankenphp.php
php packaging/runtime/stage-runtime.php
php packaging/runtime/build-install.php ^
    --frankenphp=<frankenphp-windows-x86_64.zip> ^
    --jar=<Suwayomi-Server-v2.4.2366.jar>
php packaging/installer/build-portable.php
```

That produces `build/install`, the common install tree used by the installer and portable builder.
It is not itself a verified portable download. The portable builder writes a zip and hash sidecar to
`build/dist`. For a safe validation build, pass an isolated absolute `--into` directory to
`build-install.php`, then pass that same directory as `--install` to both packaging scripts and use a
separate `--out` directory. Never use the active installation or a reader data directory as a build
target.

The launcher is designed to create `papyrus-data` beside the folder, generate its own `.env`, apply
migrations and start. The clean-machine path still needs proof that it can browse a source and read
through to manga pages.

---

## What is not proven yet

Three things, in the order they would bite.

**1. A portable first launch from a clean folder has not been proven.** The validation archive was
inspected but not launched in a clean guest. Three defects have been found only by installing from
nothing, and every one was invisible from a working install. A portable first launch is another
such path.

**2. Fixed ports still limit side-by-side copies.** The queue and scheduler lookups now match the
data directory and role, so one copy no longer claims another copy's workers. The web and Suwayomi
health lines are port probes: a second copy can adopt the Suwayomi already on 4567 and a responding
web server on 8000. Those lines do not prove the responding server belongs to that copy. See the
current distinction in [ROADMAP.md](../ROADMAP.md) and the investigation in
[ENGINEERING-NOTES.md](ENGINEERING-NOTES.md).

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