# Getting Suwayomi running (Windows)

Installing and configuring the engine Papyrus sits on. Papyrus itself is set up in
`README.md`.

Docker is not an option here. `VirtualizationFirmwareEnabled` is `False`, so WSL2, Hyper-V and
Docker Desktop are all unavailable. The JAR/bundle path is simpler for a single-user desktop setup anyway.

Your JRE 26.0.2 already satisfies Suwayomi's JRE 21+ requirement, but prefer the **Windows bundle**
over the bare JAR: it ships its own JRE, which isolates you from your system Java.

---

## 1. Download and run

Get a Windows build from <https://github.com/Suwayomi/Suwayomi-Server/releases>.

Look for the Windows bundle/installer. A bare `Suwayomi-Server-vX.Y.Z.jar` also works with
`java -jar Suwayomi-Server-vX.Y.Z.jar`, but you supply the JRE.

> **Run it as a normal process, not a Windows service.** JCEF, the embedded browser Suwayomi uses
> to solve Cloudflare challenges, needs an interactive desktop session. A service runs in Session 0
> with no desktop, and Cloudflare-protected sources fail silently. A console window is fine.

First run opens a web UI at `http://127.0.0.1:4567`.

## 2. Pin the version

Record which build you're on. The schema is generated at runtime from Kotlin classes and is
**unversioned**: there is no published `.graphqls` and no compatibility contract. Two breaking change
families are already visible upstream (`versionCode` Int→Long, boolean `isNsfw`→`ContentWarning`).

`php artisan suwayomi:check` captures the version, and `suwayomi:schema:dump` commits a schema
snapshot so an upgrade that breaks us shows up as a readable diff.

### Upgrading

This box runs **v2.4.2366**, upgraded from v2.3.2243. The sequence that worked, and the order
matters:

1. **Stop the server and back up the data root.** The H2 database, `extensions/`, `settings/`
   and `server.conf` are the irreplaceable parts. Everything else in the root (`bin`, `cache`,
   `webUI`, `logs`) is regenerable and can be large.
2. **Verify the download** against the release's own `Checksums.sha256`. The format is
   `<hash>  <filename>`, so parse it with a regex rather than splitting on whitespace, or a
   release path containing a space will hand you the wrong token.
3. **Keep the old jar next to the new one**, so a rollback is one file move.
4. **Start it and read the migration log.** This upgrade ran H2 schema migrations 59 to 65 and
   logged nothing but success. A migration that half-applies is the failure mode worth watching
   for, not a GraphQL error.
5. **`suwayomi:check`, then `suwayomi:schema:dump`.** The diff is the actual signal. This
   upgrade was 421 insertions and 5 deletions, where every deletion was a timestamp, the version
   string, or a `deprecationReason` that had moved. Nothing Papyrus depends on was removed.
6. **Run the browser gates**, not just the unit tests. A server upgrade can change behaviour
   that no fake would notice.

Worth knowing: v2.4.2366 fixed `fetchSourceManga` with filters containing nested group changes,
which was the exact error MangaDex was returning as `Expected group change at position 4`. A
`status:completed` run had 31 sources failing before the upgrade and none after. Upgrading was
the fix; working around it in the client would have been the wrong one.

Do not run `suwayomi:schema:dump` while a search fan-out is in flight. Both write, and a
contended SQLite write shows up as a job failure rather than as anything recognisably related.

## 3. Bind to loopback only

**Do this before anything else.** Suwayomi's CORS reflects any origin with credentials, and
`AuthMode.NONE` hands `Admin(1)` to every caller. A misconfigured instance on a network is wide open.

In server settings, set the bind address / IP to `127.0.0.1` and keep the port at `4567`.

## 4. Enable UI_LOGIN auth

In server settings:

- Set a **username and password**
- Set the **auth mode** to `UI_LOGIN`

`UI_LOGIN` issues a JWT access token plus a refresh token. Measured on v2.3.2243: the
access token lives **300 seconds**, the refresh token **60 days**, and refreshing does not
rotate the refresh token. `AuthManager` reads the expiry from the token itself rather than
assuming a lifetime, and renews with the refresh token.

Do **not** use `SIMPLE_LOGIN`. The server keeps those sessions in memory with no session
handler and a roughly 30 minute timeout, which is unusable server to server.

One consequence worth knowing: an expired access token on an `@RequireAuth` field comes
back as **HTTP 200** with a Kotlin `UnauthorizedException` buried in a GraphQL error, not
as a 401. `Gateway` matches on that exception class. If you ever see a "unauthorized"
that never recovers, this is why.

Then put the same credentials in the app's `.env`:

```
SUWAYOMI_USERNAME=your-username
SUWAYOMI_PASSWORD=your-password
```

and clear the config cache: `php artisan config:clear`

## 5. Image conversion: measured, and not worth setting

This section used to recommend switching `serveConversions` on to cut bandwidth. That
recommendation was withdrawn on measurement, and the reason is worth keeping.

**The setting exists and is writable.** `serveConversions` is on `Settings`,
`PartialSettingsTypeInput$` and the others in the committed v2.4.2366 snapshot, and
`Mutation.setSettings` takes it. An earlier note here claimed the setting was absent from
the schema, which was wrong.

**Turning it on changed nothing measurable.** With it off, requesting `?format=webp`,
`?format=avif` and `?format=jpg` all returned byte-identical `image/webp`. There is no
bandwidth win available, so leave it alone.

A conclusion reached for the wrong reason is still worth correcting, because the next person
reading "there is no setting here" would otherwise assume it had been tested. This is trap
122.

Note: Suwayomi's `/api/v1/chapter/{id}/download` builds an entire zip in memory and has open
memory issues (#2353, #2236). Papyrus does not expose that endpoint at all: the proxy
allow-list covers only page and thumbnail URLs, so the route is refused rather than size
guarded. The `downloads.cbz_export_max_bytes` key in `config/papyrus.php` is an unused
reminder of the guard that was once planned.

## 6. Add an extension store

**We ship no curated source list, by design.** The user pastes their own repository URLs in the
app (Phase 1), and Suwayomi fetches the catalogue from there.

Keiyoushi, the main community repository, publishes its index in **protobuf** at
`https://github.com/keiyoushi/extensions/raw/repo/index.pb`, gzip-compressed, ~696 KB decompressed,
containing ~1,400 extensions, and growing. The sibling `index.min.json` on the same branch holds only two helper
entries and is a leftover; **`index.pb` is the real catalogue and what Mihon reads.**

We deliberately do *not* parse that file ourselves. Suwayomi models `ExtensionStoreType` with
`indexUrl`, `extensionListUrl` and a `signingKey`, and exposes `addExtensionStore`,
`removeExtensionStore` and a `fetchExtensions` mutation that refreshes every store at once. We hand
over the URL and read the result back through its API, so there is exactly one source of truth.

The repo also publishes a **signing key fingerprint**, which is the trust model: extensions from an
untrusted store cannot be installed until that store is trusted. Phase 1 implements this explicitly
rather than defaulting to trust-on-add.

---

## Verify

```bash
php artisan suwayomi:check
```

Expected:

```
  Suwayomi URL ................................................. http://127.0.0.1:4567
  Server version ............................................... v2.4.2366
  Auth mode .................................................. UI_LOGIN
  Last call latency .......................................... 220ms

  Extension stores ........................................... 1
  Extensions available ....................................... 1396
  Schema snapshot ........................................... captured 2026-10-01T21:49:38+00:00
```

Verbatim from this box. Two things to note. The command prints a captured **timestamp**
for the snapshot and never the words "not captured"; when the file is missing it warns
on its own line instead. And `Extensions available` is `extensions.totalCount` from the
server, so it moves as the store grows: 1,379 when this project first measured it, 1,396
at the last sync. Treat it as a dated figure, not a constant.

A failure names the cause directly: bad credentials, an unreachable server, or a timeout all
produce distinct messages with a hint.

Then commit the baseline:

```bash
php artisan suwayomi:schema:dump
```

Re-run it after any Suwayomi upgrade; it prints a field-level diff of what changed.

---

## Troubleshooting

| Symptom | Cause |
|---|---|
| `SUWAYOMI_USERNAME and SUWAYOMI_PASSWORD are not set` | `.env` not updated, or `config:clear` not run |
| `Suwayomi accepted the request but returned no access token` | Auth mode is not `UI_LOGIN` |
| `non-JSON body` | `SUWAYOMI_URL` points at the server root instead of the GraphQL endpoint |
| Sources return Cloudflare pages | JCEF has no desktop session. Run it as a normal process, not a service. |
| Timeouts on browse/search | The server has a hardcoded 30s GraphQL ceiling (#2240); see C2 in the plan |
