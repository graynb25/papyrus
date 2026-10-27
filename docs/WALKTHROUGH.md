# From download to reading: a visual walkthrough

These are real screenshots from the 2026-10-26 manual installer run in Windows
Sandbox; `docs/ENGINEERING-NOTES.md` section 16.5 records that session. Each
image is cropped to the one window it shows, so nothing from the desktop or the
folders behind the browser is in frame. Two third-party extension-store tabs
that were open in the browser during the run are blurred out of the tab strip:
this guide stays with what Papyrus itself puts on screen, and where a store
comes from is [SOURCES.md](SOURCES.md)'s subject, not this page's.

The screenshots are from version 1.5.0's manual acceptance run. Small details
may differ between versions; the flow does not.

## 1. Install

![The setup wizard's additional tasks page, offering an optional desktop
shortcut](walkthrough/01-install.png)

Run the setup executable. It installs per user, asks for no administrator
rights, and puts the program in your profile and the library somewhere else
entirely, where an update or an uninstall cannot reach it. The page above is
the only choice in the whole wizard: a desktop shortcut, which is optional
either way, because Papyrus also lands in your Start menu.

The installer is unsigned, so Windows may warn you before running it. The
project README explains that warning and the hash check that stands in for a
signature.

## 2. The first run, and your account

![The sign-up page: name, email address, password and its rules, confirm
password, Create account](walkthrough/02-register.png)

Start Papyrus from its shortcut. A console window opens, the first launch takes
15 to 30 seconds while the manga server starts and the database is created, and
then your browser opens at `http://localhost:8000`. Nobody has signed up yet,
so the log-in screen that answers carries a "Sign up" link, and it opens the
form below: a name, an email address, and a password, with the page marking
which password rules you already meet as you type. The first account becomes
this install's owner, and the moment it exists the sign-up form closes itself
behind you.

## 3. The library starts empty

![An empty library: nothing added yet, with buttons to add a source, go to
search, or bring a library from another app](walkthrough/03-library.png)

This is not a fault. Papyrus ships with no sources, on purpose: which sites to
read from is the one decision the application will not make for you.

## 4. Add a source

![The Sources page's available extensions: a grid of source cards with Install
buttons](walkthrough/04-sources.png)

Sources come from an extension store, which you add once on the Sources page
and trust after comparing its signing key with the publisher's. Once a store is
trusted, its extensions appear here under Available, one card per source with
an Install button. Install the one that matches the site you want to read
from, and its source appears in Papyrus.

## 5. Search

![Search results for One Piece: 14 results, deduplicated from 2
sources](walkthrough/05-search.png)

Search runs against every installed source at once and deduplicates the
answers. This run's search for One Piece answered 14 results from 2 sources; a
card's small text names the source a result came from.

## 6. Open the series

![The One Piece series page: cover, tags, an In library button, and the chapter
list](walkthrough/06-series.png)

A result opens into the series page: cover, description, tags, and the chapter
list, 1,202 chapters in this case. **In library** keeps it on your shelf, and
the toolbar above the list holds the bulk actions: download unread, mark all
read, and whether the list starts from the oldest or the newest chapter. The
tick beside Chapter 001 is where this walkthrough ends up.

## 7. Read

![The reader: chapter pages stacked down the window, with a chapter and
progress bar at the bottom](walkthrough/07-reader.png)

Opening a chapter loads its pages, one after another down the window, and the
bar at the bottom names the chapter and tracks your position in it. Pages come
through Papyrus itself, not straight from the source to your browser. **Back to
the series** returns to the chapter list, where the chapter you finished is
now ticked.

---

Where to go next: [INSTALL.md](INSTALL.md) is the whole install story, from
backups to troubleshooting by symptom, and [SOURCES.md](SOURCES.md) covers
sources, stores, and what to do when search returns nothing.
