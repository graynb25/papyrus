# Suwayomi integration: why a clean install cannot start

Research and plan. Nothing here is implemented yet.

## The symptom

A freshly built install fails to start:

```
PROBLEM  did not start: Suwayomi did not answer on port 4588 within three minutes.
```

The launcher waited on port 4588. Suwayomi listened on 4567.

## What was measured, not inferred

Every claim below is a quote from this machine. Where something has not been established, it says
so.

### Suwayomi started, and answered, on the wrong port

From `logs/suwayomi.log` of that run:

```
18:57:13.942 [main] INFO io.javalin.Javalin -- Listening on http://0.0.0.0:4567/
18:57:13.971 [main] INFO suwayomi.tachidesk.server.JavalinSetup -- Server is available at:
  http://127.0.0.1:4567
```

And the launcher, for the same run:

```
PROBLEM  did not start: Suwayomi did not answer on port 4588 within three minutes.
```

The GraphQL requests the launcher made were answered. The log shows `ERROR ... GraphQL execution
failed` and `WARN ... Received invalid token`, which is Suwayomi replying to the launcher's probes.
It was reachable the whole time, on 4567.

### The launcher never tells Suwayomi which port to use

`Supervisor` starts Suwayomi with `-Duser.timezone`, `-jar`, and one environment variable:

```php
arguments: ['-Duser.timezone='.$this->machineTimezone(), '-jar', $jar],
environment: ['TACHIDESK_DIR' => $this->dataDirectory],
```

`suwayomiPort` appears in the launcher only where the launcher itself listens for it.

### Suwayomi reads the port from a file

Reading the string constants out of the jar:

```
suwayomi.tachidesk.server.JavalinSetup
  getServerConfig, getIp, setHost, getPort, setPort
xyz.nulldev.ts.config.ConfigManager
  server.conf, server-reference.conf, userConfigFile, updateUserConfig
suwayomi.tachidesk.server.ServerSetupKt
  "Exception while creating initial server.conf"
```

`JavalinSetup` reads `ServerConfig.getPort()` and calls `setPort` on a Jetty `ServerConnector`.
`ConfigManager` reads `server.conf` from the application root.

The format, from a real Suwayomi-written `server.conf` on this machine:

```hocon
# Network
server.ip = "0.0.0.0" # default: "0.0.0.0"
server.port = 4567 # default: 4567 ; range: [1, 65535]
```

Flat HOCON with dotted keys. Suwayomi creates this file on first start, with the default port, and
the launcher never writes one.

## The second defect, which is larger

**The launcher's `TACHIDESK_DIR` does nothing. It is not a Suwayomi environment variable.**

Searching every class in the `suwayomi/` packages for an environment variable name of that shape
returns nothing. The class that decides where Suwayomi keeps everything,
`xyz.nulldev.ts.config.ApplicationRootDirKt`, does this:

```
System.getProperty("suwayomi.tachidesk.config.server.rootDir", AppDirs.getUserDataDir("Tachidesk"))
```

It is a **JVM system property**, and the fallback is `ca.gosyer.appdirs`, which on Windows is
`%LOCALAPPDATA%\<appName>`.

Measured consequence. After a verification run with `TACHIDESK_DIR` pointing at an empty data
directory:

| where | what is there |
| --- | --- |
| `<data>/database` | 0 files |
| `<data>/exports` | 0 files |
| `%LOCALAPPDATA%\Tachidesk\database.mv.db` | 52,117,504 bytes, written at the time of the run |
| `%LOCALAPPDATA%\Tachidesk\server.conf` | 8,111 bytes, written at the time of the run |

`InstallLayout` creates `database/` and `exports/` in the data directory. Suwayomi never touched
them.

## What this means for a reader

- **The install is not self contained.** A reader's library, downloads, backups, extensions,
  settings and a 52 MB database live in their profile, not beside the install.
- **Uninstalling leaves all of it behind**, because nothing Papyrus owns is out there.
- **Two installs share one Suwayomi**, so a second install's migrations run against the first
  install's data.
- **The data directory reports success.** It has the right directories and nothing in them, which is
  the "half installed state rather than a clean refusal" the launcher elsewhere refuses to produce.

## Why this was never caught

**Every probe that ever worked used the default port.** All of them pass `suwayomiPort: 4567`:

```
probe-cleanroom.php:137   suwayomiPort: 4567
probe-supervisor.php:152  suwayomiPort: 4567
e2e.php, probe.php, probe2.php, probe3.php, test-store.php
  $endpoint = 'http://127.0.0.1:4567/api/graphql'
```

The defect is invisible at the default and total at any other value. A test that always passes 4567
is not a weaker version of the right test; it cannot fail.

This is the same shape as the timezone defect fixed in `8632ad0`, and as `TACHIDESK_DIR` itself: an
assumption written down, never checked, that every later test inherited without testing.

## What is not wrong, so a fix does not break it

- `Prober::suwayomiAnswers()` and `suwayomiVersion()` speak the right GraphQL. They were answered.
- The wait loop works. It waited three minutes and reported honestly.
- `SummaryLog` reported the failure correctly, in one line, naming the port and the log.
- `Health` correctly reported `FAIL suwayomi not answering on port 4588`.
- The launcher's stop path worked, terminating Suwayomi cleanly.

None of these should change.

## The plan, and why it is in this order

The two defects are coupled. `server.conf` has to be written into whatever root Suwayomi uses, so
the root has to be right first. Doing the port alone would write a config into the reader's
profile and still leave the data in the wrong place.

### Step 1, pin the root directory

Pass `-Dsuwayomi.tachidesk.config.server.rootDir=<data>/suwayomi` and remove `TACHIDESK_DIR`,
which does nothing and looks like it does.

The name is confirmed from the class's constant pool, quoted above. **What has not been established
is that it works.** The plan does not proceed on the strength of a string in a class file; step 1
is completed by running Suwayomi with it and observing the root move, not by the assertion here.

### Step 2, write `server.conf`

Write `<root>/server.conf` with `server.port` before starting Suwayomi, if it does not already
exist or does not already set the port.

Care needed, and the reason this is step 2 rather than part of step 1:

- **Do not overwrite an existing file wholesale.** `ConfigManager` migrates and annotates it. A file
  with `server.port = 4567 # default: 4567` rewritten without its comments would be replaced on the
  next start by Suwayomi's own "user config is out of date" path. The write must change the port
  value in place and leave every other line alone.
- **Re-check the port on every start**, not once at install. A reader who changes the port in
  Suwayomi's own UI should not be fought by the launcher.

### Step 3, make the launcher verify where data went

After start, assert that the database file exists where the launcher expects it. This is the check
whose absence let a half installed state report success, and it is cheap.

### Step 4, the migration decision, which is not a code change

Existing installs have real reader data in `%LOCALAPPDATA%\Tachidesk`. Step 1 moves the root, which
orphans it.

**This needs a decision from the maintainer before step 1 ships.** The options are: leave existing
readers where they are and only pin the root for new installs, which means two code paths; migrate
on first start, which touches a reader's library and can fail halfway; or pin the root everywhere and
document that existing readers must move their data, which loses them.

I have not chosen, because the right answer depends on how many installs exist outside this machine.

## Regression risks, per step

| step | what could break | how it is checked |
| --- | --- | --- |
| 1 | a reader's existing library stops being found | full suite, plus a run against a directory holding a real database |
| 1 | Suwayomi refuses the property and falls back silently | assert the database appears under `<data>`, not the profile |
| 2 | a rewritten `server.conf` makes Suwayomi reset its config | read the file before and after, assert comments and unrelated keys survive |
| 2 | the port is written but Suwayomi keeps 4567 | read `Listening on` out of the log, do not trust the write |
| 3 | a check that fails on a legitimate empty database | only assert after Suwayomi has reported ready |
| all | a change that only works at port 4567 | the suite must include a non default port, which no test does today |

The last row is the one that matters most. **The test suite currently cannot catch this class of
defect at all**, because every test passes 4567. Whatever the fix, a test that starts the real thing
on a port nobody has ever used has to exist, or the same defect returns the next time a default
changes.

## Deliberately not in scope

- Rewriting anything in Suwayomi. It is a jar we do not control, and the design says so.
- Changing the port default. 4567 is Suwayomi's and Papyrus should agree with it by default.
- Touching the GraphQL queries, which work.