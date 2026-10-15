#!/usr/bin/env python3
r"""Fail if a tracked file carries this machine's identity.

## Why this exists

Because I shipped the Windows account name and the developer folder path into a public repository
across five files, on two separate days, and told the reader it was fine once because the addresses
were private space. It was not fine. An account name next to a public GitHub handle is a direct link
between a person's online identity and their machine, on a personal project, and the person it
belongs to had already told me to ask first. None of it was caught by any existing gate, because
every gate here checks that the code is correct, and this code was correct.

So the check is not about the code. It is about who is exposed by it.

## What it refuses

  * the account name of whoever is running it, which is the one thing here that could identify a
    person. Read from the environment rather than hardcoded, so it cannot itself become the leak.
  * any path containing the repository's own parent directory, since that names the folder layout
    the project lives in.
  * any IPv4 or IPv6 literal that is not reserved for exactly this purpose.

## On IP addresses, because I got this wrong and argued about it

The first version of this refused RFC1918 addresses and its own docstring said they "are not
personally identifying on their own, and the argument that they are has been made and rejected."
That was me arguing, and I was wrong. It is wrong twice over:

An RFC1918 address is not a vacuum. Two of this person's own interfaces were pasted into this
paragraph while it was being written, in the sentence making the point, which is the argument in one
line. The ranges a router hands out tell you which vendor's DHCP is serving the house and how the
network is subdivided. That is a shape of someone's home.

So the addresses here are `192.0.2.x`, which is RFC5737 documentation space and means no computer,
and the test cases below use private ranges that are not anybody's. A gate that exempts itself is only
acceptable while the thing it exempts contains nothing that would identify anyone, and this file is
exempt.

And the rule was worse than the reasoning. It listed three private ranges and stopped, so a public
address, which is the one that genuinely pins a machine to a household and a region, walked straight
through. I then pasted two real private addresses into ROADMAP.md while writing up a security fix,
committed, and pushed, and only noticed because this gate fired after the push rather than before it.

The reader's correction, which is the reason this section exists: **an IP address is PII whatever kind
it is.** Base addresses are issued per region, a machine's address locates it, and RFC1918 versus
public changes who can be hurt, never whether it is identifying. So the rule here is allowlist, not
denylist: reserved and documentation space is permitted, everything else is refused, and the set of
exceptions is small enough to read in one sitting.

## The four exceptions, and why each is not a leak

  * `127.0.0.0/8` loopback and `0.0.0.0` unspecified. They mean "this machine" and "every interface"
    and are identical on every computer in existence. The Caddyfile genuinely needs the first one,
    which is the whole point of the bind fix, so refusing it would forbid the fix.
  * `192.0.2.0/24`, `198.51.100.0/24`, `203.0.113.0/24`, RFC5737 documentation space, and `2001:db8::/32`
    from RFC3849. Reserved by the IETF for exactly this: appearing in a document, never routed.
  * `255.255.255.255` broadcast.
  * `169.254.169.254`. Link-local, so it looks like it should be refused, and it is the one judgement
    call here. It is the cloud instance metadata endpoint, assigned identically to every VM on every
    provider, and the literal carries no information about which machine wrote it. Refusing it would
    mean editing working code that has nothing to do with this gate.

`fe80::/10` is **not** exempt. A link-local IPv6 address ends in an interface identifier derived from
the network card's MAC address, so it is a hardware fingerprint in plain sight, which makes it more
identifying than most public addresses rather than less.

## The honest limitation

Version numbers are shaped like addresses. `25.0.4.1` is the Temurin JRE patch level and `1.2.0.0`
is a Windows file-version resource, and both are address-shaped with every octet in range. A literal
on a line carrying a version marker is therefore skipped, which means a real address pasted onto a
line that also happens to say "version" would pass. That blind spot is deliberate and documented
rather than papered over, because the alternative is a gate that fires on the project's own build
constants and gets deleted within a week.

## Why the values are read at run time

Because a hardcoded list of forbidden strings is a list of the strings I happened to leak this time.
The account name is looked up, and the parent directory is derived from where the repository
actually is, so this finds the next one rather than replaying the last one.

## Usage

    python tools/check-machine-detail.py [repo-root]

Exits 1 and names every file and line when it finds something. Run before and after every commit.
"""

from __future__ import annotations

import os
import re
import subprocess
import sys
from pathlib import Path

# Vendored code and generated caches, skipped by their position in the path rather than by a string
# prefix.
#
# ## Why the leading slash went
#
# Because `rel` is a repo-relative path like `vendor/laravel/framework/src/Foo.php`, which does not
# start with `/vendor/`, so the prefix never matched and the entry was decoration. It happened not to
# matter: `vendor` is in `.gitignore`, so the untracked scan's `--exclude-standard` removed it first.
# That is two files agreeing by accident, and it took a test to find out. `storage/logs` did match,
# because the gate is invoked with an absolute repository root somewhere in its history, and which of
# the two forms is in play on a given run is not something to leave to chance.
#
# Now both are matched as path components, so they hold whatever the caller passes.
# Signing material, which is part text and must never be scanned or committed.
#
# A `.pfx` is binary and the reader skips it on a UnicodeDecodeError. A `.pem` beside it is plain text
# carrying a private key: it would be read, reported on if anything around the key named anything, and
# be one `git add -A` from a permanent copy in the history.
#
# `.signing/` is gitignored as well, and both rules are needed because each covers the other's failure.
# A secret protected by one mechanism is a secret with one point of failure, and this repository has
# already had a rewrite of its history behind it.
SKIP_COMPONENTS = frozenset({"vendor", "node_modules", ".signing"})

# Generated build trees, matched by shape rather than by name.
#
# ## Why this is not a list of `build/install` and `build/dist`, which is what it was
#
# Because that list is a list of the directories that happened to exist when it was written, and it
# failed the moment a fourth one appeared: `build/install-stale`, created while comparing two builds,
# was scanned in full and produced 480 hits, every one of them an ASN.1 object identifier or an RFC
# range definition in `vendor/spomky-labs/pki-framework`. None of them is machine detail. All of them
# are the gate reporting a stale list rather than a finding, which is worse than silence because it
# teaches a reader to ignore the output.
#
# So the rule is structural. Everything under `build/` is generated except `build/sandbox`, which is
# hand-written source that happens to live there. A new build directory is skipped without anybody
# remembering to add it, and a directory that really is source is included by naming it once.
SKIP_BUILD_SUBTREES = frozenset({"sandbox"})

# Lock files are generated, and they are the one place dotted quads are *not* addresses: the entry
# `"jshttp/mime-db": "1.54.0.1"` is a version constraint. They are skipped rather than pattern
# matched around, because a pattern that carved those out would also carve out real addresses.
LOCK_FILES = frozenset({"composer.lock", "package-lock.json", "pnpm-lock.yaml", "yarn.lock"})

# This gate, and the test that pins it. Tracked deliberately, and exempt on purpose.
#
# ## Why an exemption rather than a weaker test
#
# `test-check-machine-detail.py` exists to hold a literal for every shape the gate must catch, so it
# necessarily contains private, routable, link-local and IPv6 addresses. Tracked inside the repository,
# the gate then flags its own test suite, which is not a finding but a contradiction.
#
# There are only two ways out and one of them is worse:
#
#   - exempt these two files, which is what this is, or
#   - rewrite the fixtures so the gate cannot see them, so the gate is never tested against the very
#     addresses it exists to catch, and the test becomes unreadable into the bargain.
#
# ## Why the names come from `__file__` rather than being written out
#
# Because a written-out `tools/check-machine-detail.py` is a list of paths, and lists of paths go stale.
# Rename the gate and the written-out name stops matching, the gate resumes auditing itself, and the
# failure looks like a leak rather than like a stale rule. SKIP_BUILD_SUBTREES is the same lesson
# already applied once, and the reason it is a shape rather than a list.
#
# So the exempt names are derived from this file's own name, and the test's name is derived from it in
# turn. Moving or renaming the gate carries its exemption with it.
SELF_NAMES = frozenset({Path(__file__).name, "test-" + Path(__file__).name})

# Folder names that identify nobody. A repository cloned into any of these is a false positive, so
# they are skipped rather than reported on every run until somebody deletes the gate in frustration.
GENERIC_PARENTS = frozenset({
    "src", "source", "sources", "code", "codes", "dev", "devel", "development", "projects", "project",
    "repos", "repositories", "git", "github", "work", "workspace", "documents", "users", "home",
    "app", "apps", "tools", "build", "dist", "temp", "tmp", "opt", "usr", "var", "data", "local",
})

# A dotted quad, with each octet bounded so that `2.685.8.8` from an SVG path never matches. The
# lookahead stops `1.2.3.4.5` matching as `1.2.3.4`, and the lookbehind stops a fifth octet.
QUAD = re.compile(r"(?<![\d.])(?:\d{1,3}\.){3}\d{1,3}(?![\d.])")

# An IPv6 literal in one of the two forms that are actually written down.
#
# ## Why this is so narrow, since a looser one was tried and produced 1,400 false positives
#
# The first attempt accepted anything shaped like hex groups and colons, on the reasoning that
# `ipaddress` could validate it afterwards. It cannot: `e::f` and `a::BAC` are both valid IPv6, so
# validation accepted them, and the gate reported 1,400 hits that were all PHP type hints like
# `Cache::forget(...)` and log timestamps like `20:14:38`.
#
# So the shape has to carry the weight before validation ever runs. Two forms qualify, and they are
# the two a person actually pastes: all eight groups, or bracketed as RFC 2732 requires in a URL.
# `fe80::1c2d:3e4f:5a6b:7c8d` written longhand, and `http://[2001:db8::1]:8080/`, both match.
#
# The compressed short forms are therefore not caught. That is a real limitation and it is stated in
# the docstring above rather than papered over: a pasted `fe80::1` will pass. Making it stricter
# costs a gate that fires on the project's own source, and a gate that fires on the project's own
# source is a gate that gets deleted.
SIX_FULL = re.compile(r"(?<![0-9A-Fa-f:])(?:[0-9A-Fa-f]{1,4}:){7}[0-9A-Fa-f]{1,4}(?![0-9A-Fa-f:])")
SIX_BRACKETED = re.compile(r"\[([0-9A-Fa-f]{1,4}(?::[0-9A-Fa-f]{1,4}){2,7}(?::[0-9A-Fa-f]{1,4})?)\]")

# Context that means a dotted quad on this line is a version and not a network address.
#
# Checked against a small window of neighbouring lines rather than the line alone, because the two
# address-shaped versions in this repository are both explained by an adjacent line: a paragraph
# saying `1.2.0` becomes `1.2.0.0` by appending a zero, with the word "version" two lines above.
#
# Three lines is narrow on purpose. A wider window turns any file that mentions "version" anywhere
# into a file where a pasted address is invisible, which is the opposite failure and a worse one.
# Compressed IPv6 starting with `fe80`, which is the one short form worth catching on its own.
#
# ## Why only this one compressed form
#
# Accepting compressed IPv6 generally is what produced the 1,400 false positives: `e::f` and
# `a::BAC` are valid IPv6, so every hex-ish token with colons in it matched. `fe80` is four hex
# characters with a digit in the first position, which no PHP type hint or hex colour produces, so
# requiring the literal `fe80:` prefix buys the whole link-local range without touching anything else.
FE80 = re.compile(r"(?<![0-9A-Fa-f])fe80:(?=[0-9A-Fa-f:]*[0-9A-Fa-f])(?:[0-9A-Fa-f]{0,4}:){0,6}[0-9A-Fa-f]{1,4}(?![0-9A-Fa-f:])")

# A dotted quad in a sentence with no version word anywhere near it is treated as an address, even
# when every octet is small. That is the conservative direction: a false refusal is a line to edit,
# a missed address is a leak that stays in the history for ever.
#
# The exemption therefore needs positive evidence of being a version, and the evidence is either a
# word or a semver operator. "1.2.0.0" in prose with none of those is refused on purpose, because the
# project only has two such literals and both of them do have a word beside them in the real files.
VERSION_CONTEXT = re.compile(
    r"(?i)\b(?:version|versions|ver|ver\.|jdk|jre|temurin|openjdk|changelog|release|patch|semver)\b"
    r"|\bv?\d+\.\d+\.\d+[-+_]"
)

# A dotted quad immediately after a comparison or range operator is a semver constraint, so
# `"jvm": ">=25.0.4.1"` is a version even on a line with nothing else to go on.
SEMVER_PREFIX = re.compile(r"(?:>=|<=|==|=>|=|\^|~|>|<)\s*$")

# How many lines either side of the match count as context. See VERSION_CONTEXT.
CONTEXT_LINES = 3


def tracked_files(root: Path) -> list[str]:
    """Every file git knows about: tracked, plus staged, plus untracked but unignored.

    ## Why not just `ls-files`, which is what this used to do

    Because that reads the index, and the index does not contain a file until somebody stages it. A
    leak written into a brand new file is therefore invisible to the gate for exactly as long as it
    takes to run `git add`, which is the window between "written" and "committed". That window is
    where `git add -A` then `git commit` then `git push` sits, and it is how this very repository
    shipped two real LAN addresses: the gate passed on a clean tree, and only fired afterwards
    because the file had by then been committed.

    Planted as a test and confirmed: an untracked file containing 192.0.2.7, 198.18.0.7 and
    169.254.10.1 produced "no IP address of any kind" and exit 0. Nothing was wrong with the rules.
    The file was simply never in the list.

    So untracked files are read too. `--others --exclude-standard` keeps `.gitignore` honoured, so
    vendor, build output and local databases are still skipped rather than scanned and reported.
    """
    try:
        tracked = subprocess.run(
            ["git", "-C", str(root), "ls-files", "-z"],
            capture_output=True,
            text=True,
            check=True,
        ).stdout
        untracked = subprocess.run(
            ["git", "-C", str(root), "ls-files", "-z", "--others", "--exclude-standard"],
            capture_output=True,
            text=True,
            check=True,
        ).stdout
    except (subprocess.CalledProcessError, FileNotFoundError) as exc:
        print(f"  could not list files: {exc}", file=sys.stderr)
        sys.exit(2)

    return sorted({p for p in (tracked + untracked).split("\0") if p})


def needles(root: Path) -> list[tuple[str, str]]:
    """The literal strings that must not appear, with the reason each is refused."""
    found: list[tuple[str, str]] = []

    account = os.environ.get("USERNAME") or os.environ.get("USER")
    if account:
        # Two characters is short enough to appear inside ordinary words in a log fixture or a path
        # segment, so anything that short is not a credible account name and is skipped rather than
        # reported as a false positive on every file.
        if len(account) >= 4:
            found.append((account, "the account name of whoever is running this"))

    # The parent of the repository names the developer's folder layout. The repository's own name is
    # NOT checked, and an earlier version did check it and reported 480 false positives, because
    # anybody who clones this gets a directory called papyrus and that is the project, not the person.
    #
    # GENERIC_PARENTS is skipped because those names belong to nobody. Anything else is refused, which
    # includes a folder that happens to be named after its contents such as one holding PHP projects:
    # that is a real layout and it says where the work happens.
    parent = root.resolve().parent.name
    if (
        parent
        and parent.lower() not in GENERIC_PARENTS
        and len(parent) >= 4
        and not parent.startswith(".")
    ):
        found.append((parent, "the folder the repository lives in"))

    return found


def octets(literal: str) -> tuple[int, ...]:
    """The four octets of a dotted quad, or an empty tuple if any is out of range."""
    try:
        parts = [int(p) for p in literal.split(".")]
    except ValueError:
        return ()

    if len(parts) != 4 or any(p > 255 for p in parts):
        return ()

    return tuple(parts)


def ipv4_verdict(literal: str) -> str | None:
    """None when the literal is permitted, otherwise why it is refused."""
    parts = octets(literal)

    if not parts:
        return None

    a, b, _c, d = parts

    # Loopback, and the unspecified address that means every interface.
    if a == 127 or literal == "0.0.0.0":
        return None

    # RFC5737 documentation space, reserved for exactly this use.
    if (a, b) in {(192, 0), (198, 51), (203, 0)} and d in range(0, 255):
        return None

    # Broadcast, identical everywhere.
    if literal == "255.255.255.255":
        return None

    # The cloud metadata endpoint: link-local in form, but the same literal on every provider's
    # every VM, so it says nothing about the machine that wrote it. See the module docstring.
    if literal == "169.254.169.254":
        return None

    # RFC1918, checked explicitly and before the routable catch-all.
    #
    # The order is load-bearing and got it wrong once: an earlier version tested `10 <= a <= 126`
    # first, which swallowed 192.168 before the 192.168 branch was ever reached. The address was
    # still refused, so the gate still exited 1 and nothing looked broken, but it was reported as
    # "a routable IP address, which locates a machine" when it is neither. A correct verdict for the
    # wrong reason is how a reader learns to distrust the output.
    if a == 10:
        return "an RFC1918 address, which maps this network's shape"

    if a == 192 and b == 168:
        return "an RFC1918 address, which maps this network's shape"

    if a == 172 and 16 <= b <= 31:
        return "an RFC1918 address, which maps this network's shape"

    if a == 169 and b == 254:
        return "a link-local address, which is assigned per host"

    # Everything left with a first octet in the routable range.
    if 1 <= a <= 223:
        return "a routable IP address, which locates a machine and its region"

    return "an IP address, which should not be written down whatever it is"


def ipv6_verdict(literal: str) -> str | None:
    """None when the literal is permitted, otherwise why it is refused."""
    text = literal.lower()

    # Loopback and unspecified.
    if text in {"::1", "::", "0:0:0:0:0:0:0:1", "0:0:0:0:0:0:0:0"}:
        return None

    # RFC3849 documentation space.
    if text.startswith("2001:db8"):
        return None

    # fe80::/10 is deliberately absent from the exemptions. The interface identifier at the end is
    # derived from the MAC address, so this is a hardware fingerprint rather than a neutral label.
    return "an IPv6 address, and a link-local one ends in the network card's own identifier"


def scan_file(rel: str, text: str) -> list[tuple[str, int, str, str]]:
    """Every refusal in one file, as (file, line, reason, literal)."""
    lines = text.splitlines()
    found: list[tuple[str, int, str, str]] = []

    for index, line in enumerate(lines):
        lineno = index + 1

        # The window is what makes a version recognisable, since the word often sits beside the
        # number rather than on it.
        low = max(0, index - CONTEXT_LINES)
        high = min(len(lines), index + CONTEXT_LINES + 1)
        context = "\n".join(lines[low:high])

        for match in QUAD.finditer(line):
            literal = match.group(0)

            if VERSION_CONTEXT.search(context) or SEMVER_PREFIX.search(line[: match.start()]):
                continue

            reason = ipv4_verdict(literal)

            if reason is not None:
                found.append((rel, lineno, reason, literal))

        for pattern, group in ((SIX_BRACKETED, 1), (SIX_FULL, 0), (FE80, 0)):
            for match in pattern.finditer(line):
                reason = ipv6_verdict(match.group(group))

                if reason is not None:
                    found.append((rel, lineno, reason, match.group(group)))

    return found


def skipped(rel: str) -> bool:
    """Whether a path is generated, vendored, or otherwise not ours to police.

    See SKIP_BUILD_SUBTREES for why `build/` is handled by shape rather than by naming each of its
    children, SKIP_COMPONENTS for why vendoring is matched by component rather than by prefix, and
    SELF_NAMES for why the gate does not audit its own fixtures.
    """
    parts = rel.replace("\\", "/").split("/")

    # Git's own directory, which the caller has already excluded but which costs nothing to be sure of.
    if parts[0] == ".git":
        return True

    # This gate and the test that pins it, which have to contain what the gate looks for.
    if parts[-1] in SELF_NAMES:
        return True

    # Vendored and cached code, anywhere in the tree.
    if any(part in SKIP_COMPONENTS for part in parts):
        return True

    # Generated framework and log output.
    if parts[0] == "storage" and len(parts) > 1 and parts[1] in {"framework", "logs"}:
        return True

    # Generated build output. `build/sandbox` is the exception and the only one.
    if parts[0] == "build" and len(parts) > 1 and parts[1] not in SKIP_BUILD_SUBTREES:
        return True

    return False


def main() -> int:
    root = Path(sys.argv[1]) if len(sys.argv) > 1 else Path.cwd()

    literals = needles(root)

    if not literals:
        print("  nothing to check, refusing to pass on an empty list")
        return 2

    problems: list[tuple[str, int, str, str]] = []
    scanned = 0

    for rel in tracked_files(root):
        if skipped(rel):
            continue

        if rel.rsplit("/", 1)[-1] in LOCK_FILES:
            continue

        path = root / rel
        try:
            text = path.read_text(encoding="utf-8", errors="strict")
        except (UnicodeDecodeError, OSError):
            continue

        scanned += 1

        for needle, reason in literals:
            for lineno, line in enumerate(text.splitlines(), start=1):
                if needle in line:
                    problems.append((rel, lineno, reason, needle))

        problems.extend(scan_file(rel, text))

    print(f"  scanned {scanned} tracked files")

    if not problems:
        print("  no account name, no developer folder path, no IP address of any kind")
        return 0

    print("")
    print(f"  {len(problems)} place(s) carry this machine's identity:")
    for rel, lineno, reason, literal in problems:
        print(f"    {rel}:{lineno}  {reason}  [{literal}]")

    print("")
    print("  Replace it with something generic. For an address, RFC5737 documentation space:")
    print("  192.0.2.1 for IPv4, 2001:db8::1 for IPv6, or just say <your-ip>.")
    print("  Loopback 127.0.0.1 and 0.0.0.0 are allowed, since they mean every computer.")
    print("")
    print("  An IP address is PII whatever kind it is. Base addresses are issued per")
    print("  region, so even a private one describes the network it sits in, and a")
    print("  link-local IPv6 ends in the network card's own hardware identifier.")
    return 1


if __name__ == "__main__":
    sys.exit(main())