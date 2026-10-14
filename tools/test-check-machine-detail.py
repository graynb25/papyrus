#!/usr/bin/env python3
"""Does check-machine-detail.py catch what it claims to catch?

The gate had no tests, which is how it came to carry a docstring arguing that RFC1918 addresses
"are not personally identifying on their own" while catching nothing else. A gate with no test is a
gate whose behaviour is whatever its author last believed.

Every case below is a literal the gate must refuse or must permit. The refusals are the point: the
old gate caught three private ranges and nothing else, so a public address walked straight past it.
"""

from __future__ import annotations

import importlib.util
import sys
from pathlib import Path

GATE = Path(__file__).with_name("check-machine-detail.py")

spec = importlib.util.spec_from_file_location("gate", GATE)
assert spec and spec.loader, "could not load the gate"
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)

# The two names the gate exempts, taken from the gate itself rather than written out here.
#
# The point is that the skip cases below cannot fall out of step with the rule. If the gate is renamed,
# these follow it. If they were spelled out here they would go on passing long after the gate had
# stopped exempting itself, which is a check that cannot fail wearing the costume of one that can.
_GATE_NAME = GATE.name
_TEST_NAME = f"test-{_GATE_NAME}"

assert set(gate.SELF_NAMES) == {_GATE_NAME, _TEST_NAME}, (
    f"the gate exempts {sorted(gate.SELF_NAMES)}, which is not this file and its test. "
    f"Either the gate changed its rule or this file was renamed without the gate following."
)

# (label, text, should_be_refused)
CASES = [
    # The case that motivated this rewrite. A public address pins a machine to a household.
    ("a public address", "the server answered on http://198.18.0.7:8080/", True),
    ("a public address, bare", "reachable at 8.8.4.4 if you have the route", True),
    ("a cloud public address", "metadata at 169.254.169.254/latest/meta-data/", False),

    # Private space, which the old gate caught and the reader was told was fine. It is not fine.
    ("RFC1918 ten", "the gateway is 10.0.0.1 on this network", True),
    ("RFC1918 192.168", "it answered on 192.168.4.20, which it should not have", True),
    ("RFC1918 172.16", "the second hop is 172.16.5.4, which is private", True),
    ("RFC1918 172.31, the top", "172.31.255.254 is the last of the private range", True),
    ("172.32, outside it", "172.32.0.1 is routable, not private", True),

    # Reserved space, which must stay permitted or the bind fix becomes unrepresentable.
    ("loopback", "the Caddyfile must say http://127.0.0.1:8000 with a bind directive", False),
    ("loopback IPv6", "bind 127.0.0.1 ::1 so only the loopback answers", False),
    ("unspecified", "the listener was bound on 0.0.0.0, every interface", False),
    ("RFC5737 v4", "http://192.0.2.1:8000/ready returned 200", False),
    ("RFC5737 second block", "198.51.100.7 is also documentation space", False),
    ("RFC5737 third block", "203.0.113.9 appears in the proxy test fixture", False),
    ("broadcast", "255.255.255.255 is the broadcast address", False),

    # Link-local. fe80 is NOT exempt, because the interface identifier is derived from the MAC.
    ("link-local v4", "169.254.10.1 is link-local and assigned per host", True),
    ("link-local v6 longhand", "the adapter holds fe80:1c2d:3e4f:5a6b:7c8d", True),
    ("link-local v6 bracketed", "bound to [fe80::1] for the local link", True),
    ("IPv6 full form", "the peer address is 2001:4860:4860:8888:8888:8888:8888:8888", True),
    ("IPv6 documentation space", "2001:db8::1 is RFC3849 documentation space", False),
    ("IPv6 loopback", "::1 is loopback exactly like 127.0.0.1", False),

    # Versions, which are address-shaped and must not fire on the project's own build constants.
    #
    # These two were the only literals in the real tree that are address-shaped and are not
    # addresses, and both were caught by the gate on the first run. `25.0.4.1` and `1.2.0.0` are
    # matched here at all only because scan_file is called directly: in the repository the JRE
    # constant sits on a line naming itself and the file version sits beside the word "version".
    ("JRE patch level", "const JRE_VERSION = '25.0.4.1+1';", False),
    ("Windows file version", "VersionInfoVersion 1.2.0.0 the file version resource", False),
    ("a version explained nearby", "VersionInfoVersion 1.2.0.0 the file version resource", False),
    ("a semver constraint", '"jvm": ">=25.0.4.1"', False),
    ("an SVG path coordinate", 'd="M16.862 4.487l2.685.8-2.685a1.875"', False),
    ("four octets, version-shaped", "1.54.0.1 is a dependency version, not a host", False),

    # The conservative direction, stated as a case rather than left implied.
    #
    # Small octets with no version word nearby are REFUSED. It is almost certainly a version and a
    # person will have to say so in the line, which costs a sentence. Refusing the alternative means
    # an address written in plain prose stays in the repository history for ever, and the history is
    # the thing that cannot be fixed later.
    ("small octets in prose, no version word", "the listener answered on 1.2.3.4 as well", True),
    ("small octets that are an address", "it was bound to 10.1.2.3 by mistake", True),
]

# Shape of the failure matters as much as the verdict, so each refusal states what it is.
REASONS = {
    "a public address": "locates a machine",
    "a public address, bare": "locates a machine",
    "RFC1918 ten": "maps this network",
    "RFC1918 192.168": "maps this network",
    "RFC1918 172.16": "maps this network",
    "RFC1918 172.31, the top": "maps this network",
    "172.32, outside it": "locates a machine",
    "small octets in prose, no version word": "locates a machine",
    "small octets that are an address": "maps this network",
    "link-local v4": "per host",
    "link-local v6 longhand": "network card",
    "link-local v6 bracketed": "network card",
    "IPv6 full form": "IPv6 address",
}


# Which paths get read at all.
#
# This is its own group because it is a different question from "what does the rule decide", and it
# is where the gate actually failed. `build/install-stale` was created while comparing two builds and
# produced 480 hits, all of them ASN.1 object identifiers in vendored code. The skip list named
# `build/install` and `build/dist` and nothing else, so a fourth build directory escaped it.
SKIP_CASES = [
    ("build/install/app/Support/Licences.php", True),
    ("build/dist/Papyrus-1.2.0-setup.exe", True),
    ("build/install-stale/vendor/spki/X509.php", True),
    ("build/sandbox-results/run.log", True),
    ("build/health-data/probe.json", True),

    # `build/sandbox` is hand-written source that lives under build/, and must still be read.
    ("build/sandbox/resolve-wsb.ps1", False),
    ("build/sandbox/run-in-sandbox.ps1", False),

    ("vendor/laravel/framework/src/Foo.php", True),
    ("node_modules/vite/dist/node.js", True),
    ("storage/framework/views/abc.php", True),
    ("storage/logs/laravel.log", True),
    ("app/Support/Licences.php", False),
    ("docs/ENGINEERING-NOTES.md", False),
    ("packaging/runtime/build-install.php", False),

    # The gate and its test, exempt because the test has to hold the literals the gate looks for.
    #
    # Both names are derived from the gate's own filename rather than written out, so these cases are
    # built from the same source. A written-out path would pass today and go stale on the first rename,
    # and the failure would read as a leak rather than as a rule that stopped matching.
    (f"tools/{_GATE_NAME}", True),
    (f"tools/{_TEST_NAME}", True),

    # The exemption is by *name*, so it also covers a file with that name somewhere else. That is
    # accepted deliberately: the alternative is threading the repository root through `skipped()` to
    # narrow it, and a vendored copy is already skipped by component anyway. It is asserted here so the
    # day it stops being acceptable it is a failing test rather than a surprise.
    ("vendor/some-vendor/check-machine-detail.py", True),
    ("docs/check-machine-detail.py", True),

    # And nothing else that merely looks similar.
    ("docs/check-machine-detail.md", False),
    ("docs/check-machine-details.py", False),
]


def main() -> int:
    failures: list[str] = []

    for label, text, should_refuse in CASES:
        hits = gate.scan_file("fixture", text)
        refused = bool(hits)

        if refused != should_refuse:
            verdict = "refused" if refused else "permitted"
            wanted = "refused" if should_refuse else "permitted"
            failures.append(f"{label}: {verdict}, expected {wanted}  [{text!r}]")
            print(f"  FAIL  {label}: {verdict}, expected {wanted}")
            continue

        if should_refuse:
            reason = hits[0][2]
            expected_fragment = REASONS.get(label)

            if expected_fragment and expected_fragment not in reason:
                failures.append(f"{label}: reason {reason!r} lacks {expected_fragment!r}")
                print(f"  FAIL  {label}: reason {reason!r} lacks {expected_fragment!r}")
                continue

            print(f"  ok    {label}: refused, {reason}")
        else:
            print(f"  ok    {label}: permitted")

    print("")
    print(f"  {len(CASES)} verdict cases, {len(CASES) - sum(1 for f in failures)} correct")

    skip_failures = 0

    for rel, should_skip in SKIP_CASES:
        did_skip = gate.skipped(rel)

        if did_skip != should_skip:
            skip_failures += 1
            wanted = "skipped" if should_skip else "read"
            got = "skipped" if did_skip else "read"
            failures.append(f"skip {rel}: {got}, expected {wanted}")
            print(f"  FAIL  skip {rel}: {got}, expected {wanted}")

    print(f"  {len(SKIP_CASES)} skip cases, {len(SKIP_CASES) - skip_failures} correct")

    if failures:
        print("")
        for failure in failures:
            print(f"    {failure}")
        return 1

    return 0


if __name__ == "__main__":
    sys.exit(main())