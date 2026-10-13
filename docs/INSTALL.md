# Installing Papyrus on Windows

For the portable shape, see [PORTABLE.md](PORTABLE.md). For what the program is, see
[README.md](../README.md).

---

## What you need

- **Windows 10 or 11**, 64 bit.
- **About 1 GB of free space** for the install, plus room for whatever you download.
- **Nothing else.** No PHP, no Java, no Node, no database server. The installer ships all of it.

Antivirus is the one thing that can interfere. See [Antivirus](#antivirus-quarantines-part-of-the-install)
below, because it is the single most common reason a first launch does nothing.

---

## Installing

1. Download **`Papyrus-1.2.0-setup.exe`** (261 MB).
2. Run it. You do **not** need administrator rights and it will not ask for any: Papyrus installs
   per user, into your own Windows profile.
3. Accept the defaults. Papyrus goes to:

   ```
   C:\Users\<you>\AppData\Local\Programs\Papyrus
   ```

   Your **library** goes somewhere else entirely:

   ```
   C:\Users\<you>\AppData\Local\Papyrus
   ```

   This is deliberate. The program folder belongs to Windows and can be replaced on an update; your
   library must not be. See [Where my library is](#where-is-my-library).
4. Choose whether to put a shortcut on your desktop. Either is fine.
5. Finish. Papyrus starts on its own.

When it is ready, Papyrus opens your browser for you at the address it bound to, which is usually
**`http://127.0.0.1:8000`**. The address is also printed in the window, and it is worth reading rather
than guessing: the port is not fixed, because the clean-room gate uses 8402 and you can set your own.

That address is local to your machine. Papyrus is a server you run yourself, and by default nothing
outside your computer can reach it. Do not port-forward it unless you understand what you are
exposing.

---

## First run

The first launch takes 15 to 30 seconds, longer than later ones. It is doing four things: starting
the manga server, creating the database, generating a key, and applying migrations. If it seems stuck,
wait for it rather than closing the window.

Then:

1. **Create an account.** This is local, on your machine. There is no email verification.
2. **Point Papyrus at a source.** It ships with no list of manga sites, on purpose. Without one,
   search returns nothing and that is not a fault. See **[SOURCES.md](SOURCES.md)**, which is the
   one page you cannot skip.

That is the whole onboarding. After that, **Search**, add a series, and read.

---

## Everyday use

There is no service to install and nothing to keep running in a console window. A desktop shortcut
starts everything; the tray or the window it opens is how you stop it.

From a terminal, inside the install folder:

```
Papyrus.cmd              start everything and hold the window open
Papyrus.cmd stop         stop everything
Papyrus.cmd status       what is running
Papyrus.cmd health       is it actually working (see below)
Papyrus.cmd diagnostics  collect every log into one archive
```

`Papyrus.cmd health` is the one worth knowing. It is a short report, and every line that is not green
says what you lose:

```
    Papyrus health

    ok    suwayomi   running  v2.4.2366  port 4567  the version Papyrus ships and tests against
    ok    web        listening  port 8000  /ready 200  key ok  database ok
    ok    queue      running  queue:work
    ok    scheduler  running  schedule:work
    ok    queue      idle  nothing queued
    ok    schedule   dispatching, last wrote 6s ago
    ok    database   ok  database.sqlite  5.3 MB
    ok    logging    ok, and a diagnostics bundle can be written on request

    overall     ok
```

Run it first when something is wrong. It usually turns a bug report into a diagnosis.

---

## Where is my library

```
C:\Users\<you>\AppData\Local\Papyrus\
  database.sqlite     your series, read state, shelves, settings
  php\php.ini         written on first run
  logs\               one log per process
  suwayomi\           the manga database, downloads and extensions
```

Two databases live there and they are different things. `database.sqlite` is Papyrus's, and it holds
what **you** did. `suwayomi\database.mv.db` is the manga server's, and it holds the **manga**. Both
are yours and both are backed up daily; see [Backups](#backups).

### Moving or backing up by hand

**Stop Papyrus first.** Then copy the whole `Papyrus` folder. Copying `database.sqlite` while the app
is running loses rows: it has a write-ahead log beside it, `-wal`, holding changes not yet written
into the main file. Copying the `.sqlite` on its own looks like it worked and quietly drops them.

---

## Migrating from a checkout

Only relevant if you were running Papyrus from a folder with a development setup around it before
1.2.0. **Nothing is lost and nothing is copied automatically.** Read this before you install, because
the library you have and the library the installer will look for are in different places.

### Where your library actually is

A checkout wrote your reader records to **the checkout's own `database\database.sqlite`**, because
`papyrus.test` serves the app straight out of the working tree. An installer looks in
`%LOCALAPPDATA%\Papyrus`. Those are different files, and the installer's is empty until you put
something there.

The manga half is separate again: the manga server's database, in its own folder.

### The migration

1. **Stop everything.** Both the checkout and any installed copy.
2. **Copy, do not move.** Leave the checkout alone until you are satisfied.
3. Copy these into the installed data folder, creating it if needed:

   ```
   <checkout>\database\database.sqlite   ->  %LOCALAPPDATA%\Papyrus\database.sqlite
   <checkout>\database\database.sqlite-wal  ->  %LOCALAPPDATA%\Papyrus\database.sqlite-wal
   <manga server data folder>             ->  %LOCALAPPDATA%\Papyrus\suwayomi\
   ```

   **Copy the `-wal` file as well as the database.** It holds rows not yet written into the main
   file. Copying `database.sqlite` on its own appears to work and quietly loses them. This is the
   single most important line on this page.

4. Start Papyrus and check the library page. If your series are there, it worked.
5. Only then delete the checkout's copy.

### If the series are all gone

That is the copy-the-wrong-half failure, and it is quiet: a valid SQLite file in the wrong place reads
as zero rows with no error. Check that the installed `database.sqlite` is the size you expect and that
the `-wal` came with it.

### What does not need migrating

Your **accounts** do not, if you are installing on the same machine and creating the same account
again. Your **covers** are regenerated on demand.

---

## Uninstalling

**Control Panel, Apps, Papyrus, Uninstall.**

**Your library is not deleted.** There is no delete step and no confirmation, because an installer
that removes a reader's library is the worst thing this project could ship. There is an automated
check on every build that this is true.

Your library stays in `%LOCALAPPDATA%\Papyrus` if you want to reinstall later, and stays there if you
never reinstall. Delete that folder yourself when you are sure.

---

## Backups

Two backups, both daily, and neither of them is the other's job.

| What | Who takes it | When |
| --- | --- | --- |
| The manga, chapters, extensions | the manga server | daily, 14 days retained |
| Read state, shelves, settings | Papyrus | daily at 03:17 |

Papyrus's snapshot is the half the manga server cannot see. Without it you would have your library
and none of your progress.

To restore, stop Papyrus and put the snapshot back. For the manga half, the manga server can restore
its own backups; for Papyrus's, drop the file back as `database.sqlite` and delete the `-wal` beside
it.

---

## Upgrading

Run the new installer over the old one. The program folder is replaced, your library is not touched,
and there is nothing to uninstall first.

If a new Papyrus needs a newer manga server, it ships one. Papyrus tells you when the two do not match:
**Settings, then About**.

---

## Troubleshooting

Work down this list. `Papyrus.cmd health` answers most of it.

### The browser shows nothing, or the page never opens

Run `Papyrus.cmd health` and read the `web` line.

- **`web not listening on port 8000`** and `suwayomi running`: the manga server came up and the web
  server did not. Look at `%LOCALAPPDATA%\Papyrus\logs\web.log`, which names the file it could not
  read.
- **Both not running**: nothing started. Run `Papyrus.cmd` in a terminal and read what it prints
  rather than starting it from a shortcut.

### "Papyrus did not start. The lines above say why."

**Antivirus.** This is the most common first-launch failure and it is not Papyrus's fault. Security
software scans a freshly written 623 MB folder, and for that window `cmd.exe` refuses to execute
anything in it and says only `Access is denied.`

Papyrus now probes its own interpreter before starting, so it will name the file it could not run. If
that is what you are seeing: wait a minute and start it again, or add the install folder to your
antivirus's exclusions. No configuration file is wrong.

### `health` says the scheduler is NOT DISPATCHING

The scheduler process is alive and not doing its job, which is a distinction worth making because it
looks fine from the outside. Downloads and library checks silently stop happening.

```
Papyrus.cmd stop
Papyrus.cmd
```

If it comes back wedged the same way, send a diagnostics bundle. That is a real fault and we want it.

### `health` says NOT DRAINING

The queue is not being emptied: a job has been due for some minutes and nothing has taken it. The
message says how long. Restart Papyrus. If it persists, the queue may be stuck on a job to a site
that is not answering, and `logs\queue.log` names it.

### Search returns nothing

**Almost always because no source has been added.** Papyrus ships no list of manga sites on purpose.
See **[SOURCES.md](SOURCES.md)**.

If you have added sources and search still returns nothing, run `Papyrus.cmd health` and check the
`suwayomi` line. A `TOO OLD` there means the manga server is too old for Papyrus and search will
quietly return nothing rather than saying why. Settings, then About, says the same thing with more
words.

### Two Papyrus windows, or "port already in use"

Data is per Windows account and the ports are fixed, so **two different Windows users on one machine
will collide**: both want port 4567 and 8000. This is a known limitation, not a fault in your setup.

Within one account, two instances usually means a stop that did not finish. `Papyrus.cmd stop`, wait
ten seconds, then start again.

### The first launch takes minutes

Antivirus, mostly, and a slow disk. Papyrus writes 12,639 files on first launch. It is faster every
time after.

### Moving Papyrus to another computer

Do not copy the program folder. Install on the new machine, stop Papyrus on the old one, copy
`%LOCALAPPDATA%\Papyrus` across, and start it. The program folder is version-specific and the data
folder is not.

### It is slow, or the disk is busy

Papyrus keeps chapter images in the manga server's folder rather than embedding them, so a first read
of a chapter downloads it. Later reads of the same chapter are cached.

---

## Getting help

1. Run **`Papyrus.cmd diagnostics`**. It collects every log from all four processes into one archive.
2. Write it down with: your Papyrus version and your manga server version. Both are on
   **Settings, then About**, and both are the first thing asked for.
3. Say what you did and what happened instead.

That is enough for most reports. The alternative, sending four log files from four folders, is how
bugs go unreported for months.