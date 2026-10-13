# Changelog, in plain language

The same release notes as [CHANGELOG.md](CHANGELOG.md), written for a reader rather than a developer.
No commit hashes, no file names, no jargon.

---

## Version 1.2.0, 12 October 2026

This is the first version of Papyrus you can install on a computer and just use. Before this you had
to run it from a folder with a development setup around it.

### The big thing: there is an installer now

You download one file, run it, and Papyrus is on your computer. It does not ask to be an
administrator. When you uninstall it, **your library is left alone.** We were very careful about that
part: an installer that deletes someone's library on removal would be the worst thing this project
could do, and there is an automated test that checks it, on every build.

### Your downloads were never actually downloading

If you installed Papyrus before this version, your chapters were never being fetched in the
background. There was a fault where the part of the program responsible for automatic downloads had
stopped working, and it had failed 182 separate times without telling you anything. Papyrus still
looked completely fine, which is the part we are least happy about.

**Fixed.** Automatic downloads work again.

### We could not tell you when something was broken

The green "everything is fine" screen checked whether a program was *running*, rather than whether it
was *doing its job*. A program can be running perfectly happily while doing nothing at all, and that
is exactly what happened here: for six minutes Papyrus reported a healthy downloader while it was
doing nothing, and every other line was green too.

**Fixed.** The health screen now checks whether work is actually happening, not just whether a
program exists. It also names the process holding a port when something conflicts.

### Things you chose were being forgotten

Four settings looked saved but reset whenever you reloaded the page: your chapter order for a series,
and your library shelf, sort and search.

**Fixed.** They are remembered now.

### Where your library lives, and why it moved

Papyrus now keeps your library in your Windows user folder rather than inside the program folder.
This is not cosmetic. The program folder is read-only for an ordinary account, which meant your logs,
your cover thumbnails and part of your library could not be written without asking for administrator
rights. If you were running an older Papyrus and your library seemed to have vanished after an
update, it has not been deleted. See [the installing guide](docs/INSTALL.md) for where to find it.

### Sending a bug report is now one file

When something goes wrong, we used to need four different log files from four different folders.
There is now one command that gathers them all into a single archive you can attach to an email.

### You can now see your version

**Settings, then About** shows the Papyrus version you are on and the version of the server program
it is talking to. If the server program is too old for Papyrus to work with, it says so and tells
you what will stop working, rather than leaving you with search quietly returning nothing.

### Backups

Papyrus now takes a daily backup of the half of your library that the server program cannot see: which
chapters you have read, your shelves, your settings. The server program was already backing up the
other half, the actual manga.

### What has not changed

Your library still needs a source to come from. Papyrus deliberately ships no list of manga sites,
and you point it at a list you have chosen to trust. There is a guide to this in
[Finding and using sources](docs/SOURCES.md).

---

## Not in this version yet

- **The portable version.** A folder you can copy to a USB stick and run without installing anything.
  The code supports it and it is being tested now. [docs/PORTABLE.md](docs/PORTABLE.md)
- **A Mac version.** On hold.
- **In-app updates.** Papyrus will tell you a new version exists. It will not download and install
  it for you without asking.

If something in this list matters to you, [the roadmap](ROADMAP.md) says where it stands.