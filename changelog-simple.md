# Changelog, in plain language

The same release notes as [CHANGELOG.md](CHANGELOG.md), written for a reader rather than a developer.
No commit hashes, no file names, no jargon.

---

## Version 1.5.1, 28 October 2026

This release closes the remaining security and privacy findings from the October audit and the
copy and accessibility defects found by walking the app as a reader. It also makes the library
check, import history and source migration pages say what is actually true.

### An import asks before it installs extensions

An import that carries the "install the extensions this backup needs" choice now surveys the
backup first and stops at a review grouped by extension store. Nothing installs until you tick a
store and confirm, and the boxes start unchecked. The uploaded file is deleted on confirm,
Import without extensions, Discard, and expiry.

### Backups carry less, and the AniList token is encrypted

A backup no longer carries live sessions or cached manga-server tokens. The backup keeps the
empty placeholders so a restore still works, and your live sessions and tokens are left where
they are. Your AniList connection token is now encrypted in the database and in a backup.
Disconnecting removes it from Papyrus, but AniList has no way to revoke a token, so it stays
valid there until it expires; the connections page says so.

### Privacy names every connection and cookie

Privacy now lists the five connections Papyrus can make: AniList title matching, AniList
tracking, your manga source, an extension repository, and mail on a shared or public install. It
describes the account, library and session data kept in the local database instead of claiming
Papyrus collects nothing, and a Cookies section names the session cookie, the cross-site request
cookie and the optional remember-me cookie with the flags your browser receives.

### The login page stops publishing library titles

The login page used to show real library titles to anyone who reached it. It now shows only the
manga id and cover, and no longer asks your manga server for a title list.

### Browser responses carry security headers

Every page now refuses to be framed by another site, does not send the address of the page you
are reading to other sites, turns off camera, microphone, location and other device features
Papyrus never uses, and stops browsers guessing a file's type. Error pages carry the same rules,
and browsers are told to use HTTPS for a year, but only when the page was already loaded over
HTTPS.

### The library and import pages say what is true

The library's check line now separates series that are due, series never fetched, series checked
recently and series with no source, and the button says Checking... while a pass runs and Nothing
to check when nothing is due. A series filed from a backup has no source, so it is named instead
of being counted as up to date. Each import in the history shows when it ran and what it did, so
two imports of the same file can be told apart. The destination picker for moving series can be
filtered by name, writes a language once, and names every checkbox. Browser tabs name the source
or series instead of just Browse or Series. The backups page says the folder is named backups
beside the database instead of printing its full path, and its Files column is now Health. The
password confirmation page says why it needs the password again and offers a way back to the
library, and the import guide points at the file picker below it. A search link no longer carries
the default content filter unless you change it.

### Keyboard and screen-reader fixes

The page now has a main landmark and a skip link, so keyboard and screen-reader readers can jump
past the navigation. Cover links in the library and search results say which series they open,
the cover upload and tag inputs are named, and a scanlator whose name is only whitespace or an
invisible character is treated as no scanlator rather than an unnamed group.

### Smaller security fixes

The readiness page now explains that another web server on the same machine can make remote
requests look local, and the security page says not to expose it that way. The tools used to
build Papyrus have no open security advisories and are no longer part of a release, and the
Node.js install used for builds moved to the October security release.

## Version 1.5.0, 26 October 2026

This is the security release after 1.4.0. Actions that affect the whole install now belong to
the first account, imports and extension stores refuse the cases the audit measured, and the
bundled runtimes move to the October security releases. The two High findings from the walk are
fixed.

### One account owns the install

The first account, whether created in the browser or on the command line, becomes the install
owner. Backups, a restore, a library export, and adding or removing an extension store now
belong to that account. Every other reader keeps the rest of the app, and the Backups link is no
longer offered to an account that cannot use it.

### Imports and stores are safer

The server-path import field is owner only, uploads stay inside the import folder, and a staged
archive is capped at 256MB per entry and 384MB in total, with a 16MB cap per field inside a
`.tachibk` file. An extension store address must be http or https and cannot point at the machine,
the local network or a disguised address for the machine itself. Adding or removing a store is
owner only.

### The bundled runtime is current

The PHP, web server and Java runtimes bundled with the Windows install move to the October
security releases, and four compromised certificate authorities are removed from the Java trust
store. An update to a package Papyrus uses also closes an authorization bypass, though Papyrus
did not use the affected feature.

### A backup is a secret

The Backups page now says plainly that a backup contains the whole application database,
including live session rows, cached manga-server tokens, an AniList token and password hashes,
and that the folder must be treated as one. The 1.5.1 release removes sessions and cached tokens
from the backup.

### Imports can install extensions

An import can still install the source extensions the backup names so its series can resolve.
The import page says so before the run.

### Smaller fixes

Search now says that it checks up to 40 sources, how many are runnable and how many the cap
leaves out, instead of claiming it searched every installed source. Download unread now sees a
chapter you read on any page, not just the 50 chapters on screen. Double-clicking the reader's
shortcut no longer leaves a blank window before the launcher prints anything.

## Version 1.4.0, 24 October 2026

This release makes a real 41MB backup importable, keeps the reading time a Tachimanga backup
carries, lets a library check be stopped without losing what it finished, and writes times in
words. Sources and Source health now have a way back, Privacy says exactly what Papyrus sends, and
the last UI/UX audit findings are closed.

### Big backups now get through

If you tried to import a real Tachimanga or Tachiyomi backup, the file could be refused for its
size even though the page offered an upload. Papyrus now accepts a 256MB upload from the installed
app, reads a large `.tachibk` without loading the whole file at once, and names the same limit the
server enforces. A `.tachibk` still brings your read marks, but it does not carry reading time.

### Your reading time follows your backup

A Tachimanga backup records how long you read each day. Papyrus now keeps that time separately,
adds it to Insights, and labels it as imported. Importing the same file again replaces its own days
rather than doubling them, and time you read in Papyrus is never touched. If two exports overlap,
the larger figure for that series and day is used.

### A library check can be stopped

A check used to be able to sit on one unresponsive source with no way out. Each series now gets a
short limit, Stop takes effect between series, and a reload shows the run again. Stopping keeps
every series already checked and reports how many were done and how many new chapters were found.

### Times are written in words

Insights and the import and backup panels now say "1 minute 30 seconds", not a raw number. Each
saved backup shows the date and time it was taken beside how long ago it was. Durations keep at most
three units, so they stay short and readable.

### Smaller fixes

Sources offers Back to your library and Source health offers Back to sources. Privacy now has a
section called Mail, and when it is sent, and says a default install sends nothing; the welcome mail
is gone. The last UI/UX audit findings are closed: a backup refusal names an empty server, the
Healthy pill is moss, a source's flags open a health drill-down, the health probe is cached for 60
seconds with site filters, and the library keeps five continue-reading cards with unread, new and
stale placed first. The reading path and error messages are clearer, and manga images use
responsive sizes.

## Version 1.3.0, 19 October 2026

This release adds guided moves between sources, source pins for search, reading insights, and a
licences page. You can also import a library from another reader app and back up or restore your
Papyrus data while the app is running.

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

## Still being verified

- **A portable download.** The archive builder exists, but no package is published and a
  clean-machine first launch that reads from a source is not proven. [docs/PORTABLE.md](docs/PORTABLE.md)
- **A Mac version.** On hold.
- **In-app updates.** Papyrus will tell you a new version exists. It will not download and install
  it for you without asking.

If something in this list matters to you, [the roadmap](ROADMAP.md) says where it stands.