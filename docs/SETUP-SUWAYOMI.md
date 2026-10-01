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

## 5. Configure image conversion (optional but recommended)

Set `serveConversions` to transcode to WebP with a `default` entry. Manga pages are large and this
cuts bandwidth substantially. The reader requests `?format=webp`, which the proxy forwards.

Note: Suwayomi's `/api/v1/chapter/{id}/download` builds an entire zip in memory and has open
memory issues (#2353, #2236). Phase 4 will size-guard it and zip from the on-disk layout instead.

## 6. Add an extension store

**We ship no curated source list, by design.** The user pastes their own repository URLs in the
app (Phase 1), and Suwayomi fetches the catalogue from there.

Keiyoushi, the main community repository, publishes its index in **protobuf** at
`https://github.com/keiyoushi/extensions/raw/repo/index.pb`, gzip-compressed, ~696 KB decompressed,
containing ~1,380 extensions. The sibling `index.min.json` on the same branch holds only two helper
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
  Suwayomi URL ....................................... http://127.0.0.1:4567
  Server version ..................................... 2.3.x
  Auth mode .......................................... UI_LOGIN
  Last call latency ................................. NNms
  Extension stores .................................. N
  Extensions available .............................. NNN
  Schema snapshot ................................... not captured
```

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
