# Tools

Developer tooling that belongs to this repository rather than to one machine.

## `check-machine-detail.py`

The fifth gate. It reads the account name and the parent directory **at run time** rather than storing
them, and refuses any tracked or untracked file containing either, or an IP address of any kind.

```bash
python tools/check-machine-detail.py            # the repository root is the default
python tools/test-check-machine-detail.py       # its own cases: 29 verdicts, 20 skips
```

**Why it lives here rather than beside the other tools.** It used to live outside the repository, in the
shared `.tools` folder next to `check-dashes.py`. A gate that only exists on one machine is not a gate:
a fresh clone cannot run it and CI cannot enforce it. This one has caught five separate leaks across
two days, so being runnable is the entire point of it.

The alternative was to keep it outside and accept that. That was the status quo, and it is the reason
the question got asked.

### The gate does not audit itself

`test-check-machine-detail.py` has to hold a literal for every shape the gate must catch, so it
necessarily contains private, routable, link-local and IPv6 addresses. The gate therefore exempts itself
and that test.

This is a deliberate exemption with two ways out, and one of them is worse:

- exempt the two files, which is what happens here, or
- rewrite the fixtures so the gate cannot see them, so the gate is never tested against the addresses it
  exists to catch.

The exempt names are derived from `Path(__file__).name` rather than written out, so renaming or moving
the gate carries its exemption with it. A written-out path would go stale on the first rename, and the
failure would read as a leak rather than as a rule that stopped matching. `SKIP_BUILD_SUBTREES` is the
same lesson already applied once, and the reason it is a shape rather than a list of directories.

The exemption is by name, so it also covers a file with that name elsewhere in the tree. That is
accepted: the alternative is threading the repository root through `skipped()` to narrow it, and a
vendored copy is already skipped by component. The test asserts it, so the day it stops being acceptable
it is a failing test rather than a surprise.

### An IP address is PII whatever kind of it is

The docstring argues this at length and the reasoning is worth keeping: private ranges are handed out
per region, so a `10.x` address still describes the network it sits in, and a link-local IPv6 address
ends in the network card's own hardware identifier. Use RFC 5737 documentation space, `192.0.2.x`, for
any example of a LAN interface.

## Not here

`check-dashes.py` still lives outside the repository, in the same shared folder. It was not part of the
decision to move this gate and the argument for moving it applies to it equally, so the inconsistency is
deliberate and short-lived rather than accidental. Moving it is a one-line change to the two places that
name it.
