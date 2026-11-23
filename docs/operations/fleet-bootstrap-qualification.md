---
title: Fleet backup history and host preparation qualification
description: Executed checks and remaining host qualification for Fleet backup receipts and Linux bootstrap.
---

# Fleet backup history and host preparation qualification

Recorded on 21 November 2026. Staging configuration, services, and data were not changed.

## Completion gates

| Increment | Implementation | Documentation | Automated and runtime qualification | Browser qualification |
| --- | --- | --- | --- | --- |
| Backup history and receipts | Present | Current | Passed; see executed checks | Passed: 140 checks, 40 expected skips |
| Host dependencies and resolver preparation | Present | Current | Package and safety checks passed; full host matrix unavailable | Not applicable to the host scripts |

## Scope and recovery

Fleet reads the latest 25 durable backup receipts through the active control node. It does not infer
backup health from S3 object sizes. A backup command must return a verified snapshot receipt before
Fleet reports success. Failed refreshes retain the previous table and show an error.

The prerequisite script detects the distribution and installs packages using apt, dnf/yum, apk,
pacman, or zypper. It verifies required commands, Compose, and the Docker daemon. Unknown
distributions and unsupported init systems stop explicitly when automatic preparation is required.
Preconfigured hosts with working dependencies and Docker are accepted without reconfiguration or root access.
Installing packages or starting Docker still requires root.
This is a bounded set of installation
recipes, not a claim that every Linux distribution is supported.

On DNS hosts requiring wildcard binds, the generated release contains `prepare-dns.sh` and invokes
it during activation. It releases systemd-resolved's stub while retaining upstream host resolution.
A failed restart or name-resolution check restores the prior drop-in and resolver link. Control and
edge-only hosts, explicit public binds, unrelated DNS listeners, and resolvers managed by other tools
are not automatically changed. The existing port-conflict check rejects unrelated listeners.

The backup password stays private to root and the PHP worker group. Database export runs as a Restic
child command, so a producer failure cannot become a successful snapshot through shell pipeline
exit-status handling. No application migration or persistent database reset is required.

## Executed checks

- Final host safety checks: 15 passed. Final backup regressions: 5 passed; these cover the two tests added after the full suite started.
- Real Restic repository creation, reuse, wrong-password rejection, and failed-export preservation: passed.
- New Fleet history and verified-count reads against staging: passed; four receipts and two verified backups, with no state changes.
- Ubuntu 24.04 package installation: passed, including the installed Compose plugin and rejection of an absent daemon.
- Alpine 3.22 package installation: passed, including the installed Compose plugin and rejection of an absent daemon.
- Full Fleet Python suite: 362 passed in 739.23 seconds.
- Full Fleet browser suite, snapshots, keyboard checks, and accessibility: 140 passed and 40 expected skips across four theme/viewport projects. Skips avoid repeating the theme-independent crawl and phone documentation captures.
- Compose and production override validation: passed; isolated queue contract tests passed, 2 tests and 81 assertions.
- Python compilation, shell syntax, and whitespace checks: passed.
- `make docs-check`: passed, including reference checks, Markdown lint, and the documentation build.
- Full host boot, systemd/OpenRC daemon activation, and installations on the remaining distribution families: not executed.

Package installation qualification runs in disposable containers without host Docker sockets. It
deliberately does not treat a stub init command as proof of service activation. Resolver rollback
tests execute the shell in an isolated filesystem with controlled service responses; they are unit
tests, not qualification of a real host resolver.

The separate panel browser run started before this Fleet change finished with 372 passed, 96 skipped,
and 8 failed checks. Failures cover request-rule snapshots, edge-health assertions, and a settings
assertion. That run does not pass the separate [request-rules qualification](request-rules-qualification-2026-11-21.md)
gate; the Fleet browser results above apply to the Fleet web only.

## UI delivery gate

Design read: extend the existing Fleet page, table, forms, and background job flow. Keep snapshot IDs
readable before secondary table columns on phones; retain the shared neutral shell and blue primary
action. Dials: ENERGY 1 / RHYTHM 1 / MOTION 1. The UI Delivery Gate passed; final screenshots were reviewed in both themes at desktop and phone widths.

| Item | Evidence |
| --- | --- |
| R-01 | PASS: existing flat surfaces; no gradient introduced. |
| R-02 | PASS: new labels and help contain no em dash. |
| R-03 | PASS: phone overflow assertions and final visual review passed. |
| R-04 | PASS: no icon or icon system added. |
| R-05 | PASS: backup history extends the existing Fleet table pattern. |
| R-06 | PASS: shared typography; code styling identifies snapshot hashes. |
| R-07 | PASS: no background pattern added. |
| R-08 | PASS: no decorative arrow added. |
| R-09 | PASS: rows show stored backup state as text. |
| R-10 | PASS: no glass effect added. |
| R-11 | PASS: existing table and form shapes retained. |
| R-12 | PASS: existing shared surface shadows retained. |
| R-13 | PASS: no glow added. |
| R-14 | PASS: receipts use a table, with no feature cards. |
| R-15 | PASS: Refresh backups and Back up now identify their actions. |
| R-16 | PASS: copy describes receipts, snapshots, refresh errors, and credentials. |
| R-17 | PASS: dates, statuses, IDs, and sizes come from scoped control queries. |
| R-18 | PASS: no testimonial or customer claim added. |
| R-19 | PASS: no new motion added. |
| R-20 | PASS: receipts and restore inputs serve Fleet recovery operations. |
| R-21 | PASS: light and dark browser projects and visual comparisons passed. |
| R-22 | PASS: no illustration added. |
| R-23 | PASS: no visual asset or navigation category added. |
| R-24 | PASS: documentation link validation passed for 149 documents. |
| R-25 | PASS: axe checks passed on the changed pages and loaded receipt table. |
| R-26 | PASS: refresh reads the receipt table; existing action and validation browser checks passed. |
| R-27 | PASS: unread, empty, failed refresh, failed backup, and successful backup states are explicit. |
| R-28 | PASS: no FAQ added. |
| R-29 | PASS: shared theme colors retained. |
| R-30 | PASS: the Fleet shell and action patterns retain the product identity. |
| R-31 | PASS: the design read explains column ordering and job reuse. |
| R-32 | PASS: keyboard navigation and focus checks passed in all four projects. |
| R-33 | PASS: changes are in the server-rendered source and shared stylesheet. |
| R-34 | PASS: final screenshots reviewed in both themes at both widths. |
| R-35 | PASS: full Fleet browser run exited successfully, 140 checks passed. |
| R-36 | PASS: package tests explicitly exclude full host activation qualification. |
| R-37 | PASS: direction and dials follow the established Fleet design. |
| R-38 | PASS: browser receipts are marked synthetic fixtures in the test source. |
| R-39 | PASS: named table region, native controls, focus checks, and axe checks passed. |
| R-40 | PASS: no media or unmeasured performance claim added. |
| Liveliness | PASS: heading, primary backup action, neutral receipt table, and grouped restore form implement the declared dials. |
| C-1 | PASS: snapshot IDs precede secondary columns so the main recovery identifier remains visible. |
| C-2 | PASS: final refresh and existing-action browser checks passed. |
| C-3 | PASS: the page contains backup receipts and the existing recovery actions. |
| C-4 | PASS: final theme, viewport, visual, and validation checks passed. |
| C-5 | PASS: executed checks and missing host qualification are distinguished above. |

## Boot recovery qualification, 23 November 2026

Recorded 23 November 2026. Scope: memory-backed boot material, recovery through the shared Fleet engine,
CLI and local web controls, and README domain captures. No application migration or image update.

| Gate | Result |
| --- | --- |
| Implementation | PASS: encrypted boot credential, ordered restore before Docker, safe empty-directory recovery, all-role recovery command and web controls |
| Documentation | PASS: Fleet engine/web guides, generated CLI reference, README tour |
| Automated qualification | PASS: 19 recovery/provisioning/parser, 44 web backend, and 24 action/vault regressions |
| Runtime qualification | PASS: isolated cold restore, invalid-credential dependency failure, and an approved real staging reboot with restored files, 17 healthy containers, DNS, trusted HTTPS, fresh heartbeats, and SSH recovery |
| Browser qualification | PASS: Fleet web 146 passed and 42 expected duplicate-crawl/desktop-capture skips; domain captures 2 passed; rule-table visual/axe checks 4 passed |

The broad Python run returned 366 passed and one failure in the CLI command inventory assertion.
The expected command list was updated for `recover`, and that test passed in the final affected regression run.
The complete broad suite was not rerun after that test correction; the affected suites above were rerun.

Staging: boot recovery was installed using pop2's existing key bytes. The same recovery container-start
command ran on the control host and pop2. Both retained healthy running containers and enabled Docker.
An explicitly approved pop2 reboot is confirmed by its exported boot time. Authoritative SOA replies,
HTTPS with certificate verification (302, verification result 0), and fresh heartbeats returned.
The new boot ID and startup journal confirm material restoration before Docker returned. SSH initially refused connections and returned after the requested five-minute wait; all 17 running containers were healthy, and keys were regular files owned by UID 10101 with mode 0400 (certificate mode 0444). Pop1 remains dependent on recovery from the owner's workstation vault.
IPv6 host reboot qualification was not executed; staging has no configured public IPv6 in this inventory.

### Boot recovery UI delivery gate

Design read: use the existing Fleet Nodes page, host detail, forms, and background action log.
Keep recovery separate from installation and retain the shared shell, typography, surfaces, and primary accent.
Dials: ENERGY 1 / RHYTHM 2 / MOTION 1. README images are genuine seeded captures, including four complete redirect rows.

| Item | Evidence |
| --- | --- |
| R-01 | PASS: no gradient or surface styling added. |
| R-02 | PASS: added UI labels and help contain no em dash. |
| R-03 | PASS: overflow checks and snapshots passed at 390px and 1440px. |
| R-04 | PASS: no icon or icon system added. |
| R-05 | PASS: recovery uses existing Fleet forms and job pages. |
| R-06 | PASS: existing typography retained; no font CSS changes. |
| R-07 | PASS: no background pattern added. |
| R-08 | PASS: no decorative arrow added. |
| R-09 | PASS: failed recovery has a textual job status and explanation. |
| R-10 | PASS: no glass effect added. |
| R-11 | PASS: existing form and button shapes retained. |
| R-12 | PASS: no shadow changes. |
| R-13 | PASS: no glow added. |
| R-14 | PASS: scoped recovery controls are forms, with no feature-card layout. |
| R-15 | PASS: Recover all enabled hosts and Recover this host describe the scope. |
| R-16 | PASS: copy names keys, stopped containers, the installed release, and health checks. |
| R-17 | PASS: README values come from the seeded database, explicitly identified as test data. |
| R-18 | PASS: no testimonials added. |
| R-19 | PASS: no animation added. |
| R-20 | PASS: existing operator shell and host actions retained. |
| R-21 | PASS: both themes tested; no default theme change. |
| R-22 | PASS: no illustration added. |
| R-23 | PASS: owner requested the captures; no navigation category or invented asset added. |
| R-24 | PASS: navigation/link browser checks and documentation link validation passed. |
| R-25 | PASS: axe checks passed on changed pages in all four Fleet projects. |
| R-26 | PASS: web actions call the shared engine in background jobs; success and failure routes tested. |
| R-27 | PASS: existing job flow shows running, completed, and failed states; empty Nodes hides recovery. |
| R-28 | PASS: no FAQ added. |
| R-29 | PASS: existing shared colors retained; no CSS changes. |
| R-30 | PASS: Fleet's existing host list, forms, and action log remain the design. |
| R-31 | PASS: design read and scoped action labels explain ordering and reuse. |
| R-32 | PASS: native controls, keyboard navigation, and visible focus checks passed. |
| R-33 | PASS: changes are in server-rendered source; captures use Playwright without image editing. |
| R-34 | PASS: changed captures reviewed in both themes at desktop and phone widths. |
| R-35 | PASS: full Fleet browser runner exited 0; 146 passed. |
| R-36 | PASS: actual serving recovery and remaining SSH/IPv6 limits are recorded separately. |
| R-37 | PASS: established design direction and dials declared before capture. |
| R-38 | PASS: screenshot source is the disposable seeded browser database and fixture vault. |
| R-39 | PASS: semantic headings, native forms, axe checks, and phone layout checks passed. |
| R-40 | PASS: no media or unmeasured performance claim added. |
| Liveliness | PASS: section headings separate recovery from installation; existing accent identifies the primary install action. |
| C-1 | PASS: fleet and host actions expose their actual scope beside their controls. |
| C-2 | PASS: both actions reach background jobs; backend success and browser failure paths passed. |
| C-3 | PASS: recovery occupies the existing Nodes/host pages, with no additional dashboard or filler section. |
| C-4 | PASS: final snapshots, theme, width, keyboard, validation, and error-state checks passed. |
| C-5 | PASS: seeded data, executed qualification, and remaining limits are identified explicitly. |
