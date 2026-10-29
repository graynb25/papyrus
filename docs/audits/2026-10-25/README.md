# Audit, 2026-10-25

Status: **walk complete with documented gaps. Security assessment done; Batches A to G accepted
and Batch H recorded and committed locally. 1.4.0 remains the latest published release. The 1.5.0
release is committed in `86e033a`; its artifacts were rebuilt from that commit, and the real setup
installed in the manual sandbox on 2026-10-27 with the reader flow passing, so the 1.5.0 installer
payload is proven. The 1.5.1 release is committed locally, and its unsigned setup and portable
were rebuilt from `7f964a1` on 2026-10-29; no manual install or sandbox run has proven the 1.5.1
payload, so 1.5.1 is not closed. Nothing is pushed.**

This folder records the post-1.4.0 walk, the security and privacy assessment, and the hardening
plan. The work is deliberately split. The walk looks at the app as a reader does. The security
session looks at what crosses a trust boundary. The plan is written only after both have evidence.

Triage on 2026-10-25 ranked the walk findings. The proposed 1.5.0 scope is H1, H2,
M1 to M4, L1 to L4, and P1. L5 waits for the dependency audit. The security assessment
can add or reorder this before `hardening-plan.md` fixes it.

## Scope

- Walk every reader-facing route at desktop and mobile width, including unauthenticated, empty,
  loading and error states.
- Review authentication, tenant scoping, sessions, CSRF, uploads, imports, backups, the image proxy,
  outbound HTTP, OAuth, secrets at rest, logs, diagnostics, headers, cookies and dependency
  advisories.
- Review privacy claims against the outbound requests the code actually makes.
- Review the installer and portable for shipped secrets, reader data, logs and development files.

## Non-goals

- No fixes during the walk or the security assessment. Fixes are approved in
  [`hardening-plan.md`](hardening-plan.md) first.
- No public deployment hardening beyond what the application claims for `single`, `shared` and
  `public` modes.
- No claim that a clean-machine install works from a checkout test.

## Environment

Measured on 2026-10-25:

| | |
| --- | --- |
| Checkout | `http://papyrus.test` |
| Checkout PHP | 8.4.27 NTS |
| Packaged PHP | 8.5.11 ZTS |
| Composer | 2.10.2 |
| Node | 26.7.0 |
| Suwayomi | 2.4.2366 on `127.0.0.1:4567` |
| Workers | queue and scheduler running |
| Readiness | `/ready` 200, key and database true, no pending migrations |

The workers were confirmed from the artifacts they write, not from
`dev-workers.ps1 -Status`. That script matches `Win32_Process.CommandLine`, and the
non-elevated shell used for this audit cannot read the command line of the elevated
worker processes, so it reports both as missing while `build/dev-queue.log` and
`build/dev-scheduler.log` advance every ten seconds and the database WAL is written.
The script is not wrong; its input is invisible from this context. The same caveat
applies to any later status check made from an audit shell.

## Safety

- The walk uses the signed-in checkout and the existing library in read-only mode. It does not mark
  chapters, change settings, add or remove series, or complete imports, backups, downloads or source
  installs.
- The security session may use controlled write probes only on a scratch install or a copied
  database.
- No real name, home path, email address, credential, token, private address or reader title goes
  into a tracked file. Use `C:\Users\<you>` and RFC 5737 addresses.
- Screenshots used as evidence are described in the findings; any real reader data is redacted.

## Documents

| File | Purpose |
| --- | --- |
| `README.md` | This scope, method and environment record |
| `walk.md` | Route coverage, reader-facing findings and evidence |
| `security.md` | Threat model, security and privacy findings, dependency audit |
| `hardening-plan.md` | Approved fixes, tests, acceptance criteria and release target |

## Sessions

| Session | Model | Purpose |
| --- | --- | --- |
| Walk | DeepSeek V4.1 Flash Free, high | Read-only route walk |
| Security | GLM 5.3 Flash Free, max | Threat model, code and dependency review |
| Fixes | DeepSeek V4.1 Flash Free, high; GLM 5.3 max for security-sensitive review | Approved changes |
| Verification | DeepSeek V4.1 Flash Free, high | Gates, re-walk, dependency re-audit |
