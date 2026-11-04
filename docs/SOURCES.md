# Finding and using sources

**This is the page you cannot skip.** Papyrus ships with no manga sites. Out of the box, search
returns nothing and that is not a fault. This page explains what a source is, how to add one, and how
to tell whether to trust it.

---

## What a "source" actually is

Papyrus cannot read a manga site. Neither can the manga server it talks to. What reads them is a
**source**, sometimes called an extension: a small program, written in Kotlin, that knows how one
particular website is laid out. Where the pages are. What a chapter is. How "next chapter" works.

So the chain is:

```
you search  ──▶  Papyrus  ──▶  Suwayomi  ──▶  a source extension  ──▶  a manga site
                    (presents)   (coordinates)     (knows the site)
```

When a chapter will not load, a source is broken or a site changed its layout and the extension has
not been updated. Papyrus cannot fix that and neither can Suwayomi. Knowing which of the three is at
fault is most of the work of reporting a bug, and this is why the question gets asked first.

---

## Extension stores

Sources are not downloaded individually from the internet. They come from an **extension store**: a
repository that publishes an index of extensions, and signs what it publishes.

An extension store has:

| Field | What it is |
| --- | --- |
| **Index URL** | where the list of extensions lives |
| **Signing key** | a public key the store signs releases with |
| Name, contact | who is publishing this |
| **Badge** | whether the store calls itself official, community, or legacy |

### Where to find one

Papyrus does not link or recommend a store, and it ships no URL. That is deliberate. Which
extension repository to trust is your decision, and a project that ships a name is making that
decision for you.

- **Suwayomi publishes an official store.** Its own documentation names the current index URL. It
  carries the smallest set of sources the Suwayomi project maintains.
- **Community stores exist**, run by volunteers rather than by the Suwayomi project. They usually
  carry far more sources, with uneven quality. Find one through its own project page, and read who
  publishes it before you paste anything.

Both work the same way. There is no wrong answer, only a trade: official is fewer sources and more
likely maintained; community is many more sources with uneven quality. What matters is that you
found the repository yourself and can check its signing key against what its publisher says.

---

## Adding a store

1. Open **Sources** in the main navigation.
2. Find the **Add a store** form beneath the registered stores.
3. Paste the store's **index URL**.
4. Papyrus fetches the store and shows its name and badge.
5. **Read the signing key before trusting it.** It is shown, not hidden. That is deliberate.
6. Trust it.

Trusting a store means: install extensions from it, and accept that they run code that fetches
websites on your behalf. That is the whole of the risk, and it is why the key is shown rather than
clicked past.

### Checking the store worked

**Sources** in the main navigation lists what is available. **Settings, then About** tells you the
manga server is answering. If Sources is empty, the store did not load, which is almost always a URL
transcribed with a typo rather than a network problem.

---

## Using sources once you have some

- **Search** searches across every installed source at once.
- **Browse** lets you look at a source directly.
- **Sources** groups installed variants by site. Each flag opens that language variant directly;
  the card also shows the source count, health and search pin.
- **Extensions**, on the Sources page, is where you install or uninstall extension packages.

### Choosing between sources for the same series

The same title is usually on several. The differences that matter:

- **Freshness.** Some sources mirror faster than others.
- **Image quality.** Sources vary, and one site's scans are better than another's.
- **Reliability.** A source that times out when its site is busy will make a download stall.

Papyrus remembers which source a series came from, per series, and keeps using it. If a chapter
fails, switching that one series to another source is usually the fix, and it is why the choice is
per series rather than global.

### A series moved

Sites reorganise. A series can move from one listing to another, or the site can renumber it. Papyrus
offers a migration flow for this: it can look for the series' new home and carry your read state
across rather than making you re-add it. It is deliberate rather than automatic, because guessing
wrong would attach your progress to the wrong series.

---

## Removing a source

**Sources**, then remove. Removing a source does **not** delete the series you already added from it,
or your read state. It removes the ability to find and fetch new chapters from it.

If you remove a store, the extensions it supplied remain installed until you remove them too.

---

## Troubleshooting

### Search returns nothing

1. **Do you have any sources?** Sources page, empty means none. This is the most common reason by a
   long way, and Papyrus starts with none on purpose.
2. **Is the manga server answering?** `Papyrus.cmd health`, or **Settings, then About**. A line
   saying `TOO OLD` means search will quietly return nothing rather than explaining why.
3. **Is it a credential problem?** Some sources need a login for their site. If the store shows
   errors on import, the source's own site may have changed.

### A source is listed but finds nothing

The extension is out of date. Sites change, extensions lag, and this is the most common source of
"it used to work".

- Update the extension: remove it and install it again from the store, which re-downloads the latest.
- If the latest still fails, the site has probably changed and the extension's maintainer needs to
  know. Reporting it there, not to Papyrus, is the useful thing to do.

### Adding a store fails

- **Typos in the URL.** The most common cause by a wide margin. Copy it.
- **The server cannot reach it.** A store on a host your machine cannot see will fail. Papyrus runs
  on your machine, so it has your machine's network, including anything blocking it.
- **The URL is a list rather than an index.** Legacy stores used a different shape. Papyrus records
  the difference, and a legacy store is marked as one.

### A chapter will not download

In order of likelihood:

1. The site is rate-limiting you. Wait.
2. The source is out of date, as above.
3. The site requires a login and the source's cookies have expired.
4. Something is genuinely wrong with Papyrus. Now it is worth a bug report, with both versions from
   **Settings, then About**.

---

## What Papyrus deliberately does not do

- **Ship a source list.** Deciding which projects to trust is the reader's call.
- **Auto-update extensions.** An extension is code that runs on your machine. Papyrus will not swap it
  underneath you.
- **Verify extensions itself.** The signing key belongs to the store, and Papyrus shows you what the
  store claims. It does not tell you the store is honest, because it cannot know that.

That last one is the honest position rather than a gap. Anyone who tells you a store is safe is
either wrong or selling something.