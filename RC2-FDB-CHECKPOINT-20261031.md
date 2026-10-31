# RC2 incomplete LLDP/FDB checkpoint — 2026-10-31

Status: source correction tested; NOT deployed and NOT release-approved.

- Repository: `Liberamente/zabbix-network-topology`.
- Exact RC2 / rollback base: `9ace02d5424e2097770e9291500c373b14076b42`.
- Code commit: `a8de74f3161a1964929c25cf5a63e9ffab2778ce`.
- Branch: `fix/rc2-incomplete-lldp-fdb`.
- Canonical references consulted: `BETA-RELEASE-CANDIDATE.md` (including ADR-014),
  `AUTO-FDB-EDGES-PILOT.md`, `CASCADE-EVIDENCE-20261028.md`, `FDB-SETUP.md`,
  `README.md`, `CONTRIBUTING.md`, `ROADMAP.md`.

## Reproduced defect and minimal correction

RC2 checks switch-wide LLDP capability availability before using an explicitly
known transit port. In a five-switch cascade, this rejects the final access
association when upstream capability rows are absent, even though existing
LLDP/CDP edges identify the exact upstream FDB ports.

`AutoFdbEdges` now removes a documented transit observation before checking the
remaining observation's switch classification. This does NOT set `known` for
the rest of that switch. Existing edge-based transit evidence must have an
LLDP/CDP source, two in-scope FDB switches and the same nonzero proxy; manual,
containment, Wi-Fi and FDB edges cannot classify transit. The interactive
`FdbResolver` and `LldpEdgeBuilder` algorithms are unchanged.

The synthetic test preserves four original links byte-for-byte and adds one
inferred association at switch 5. Two endpoint MACs learned across all five
switches produce two shared-port inferences, with `confirmed=false` and empty
individual port metrics. Missing transit evidence, missing final-switch
capabilities, excluded hosts and cross-proxy evidence fail closed. No MAC-count
uplink heuristic, new physical cable, topology-completion rule or LAG membership
is introduced. Existing LLDP/CDP, manual, Wi-Fi and parent precedence remains.

## Diagnostics

Optional final by-reference argument to `AutoFdbEdges::append` returns aggregate
`added`, `transit_copies_ignored` and `rejected` reason counters. Reasons include
unclassified switch, ambiguous endpoint, multiple access candidates, no eligible
FDB hit, no fresh complete FDB, no parsed hit, aggregate port, proxy mismatch,
duplicate MAC identity, no MAC identity, existing/manual link and builder refusal.
Counters count decisions/observations, not mutually exclusive endpoint totals;
for example `no_mac_identity` also includes infrastructure without MAC identity.

`tools/fdb-replay.php` exposes these counters offline. It does not query Zabbix,
collect data, deploy, or write to the input. The live controller/UI has NOT been
changed to expose these diagnostics. Use an already available private snapshot
containing the exact inputs at the pre-AutoFdbEdges pipeline boundary:

```json
{"hosts":{},"walk_items":[],"cap_items":[],"edges":[],"manual_uplinks":{},"metrics":{},"now":1792354000}
```

`hosts` must already be scoped to one authorized selected group and visible
hosts, keyed by hostid; `now` is the snapshot Unix timestamp, not today's time.
Keep actual snapshots outside Git. Replay prints counts, not host/MAC/IP values.

```sh
php tools/fdb-replay.php /private/path/scoped-snapshot.json
```

## Test results

Local runtime: PHP 8.3.35, Node 26.12.0. Repository CI uses PHP 8.2 and Node 22;
those runtime versions and remote CI have not been claimed as tested here.

| Check | Result |
|---|---|
| Final cascade tests against original RC2 AutoFdbEdges | FAIL as expected: 8 assertions, including absent diagnostics |
| Final AutoFdbEdges regression | PASS |
| All 43 PHP test programs, including LLDP/CDP/FDB/Wi-Fi/trunk/pass-through and scope | PASS |
| Frontend suite, including trunk and inferred-edge UI | PASS |
| PHP lint, XSS, ESLint, bundle drift, parity, SNMP fixtures, templates | PASS |
| Packaging, layers, ghost, quality, pipeline parity, PHP imports, assignment guard | PASS |
| Shellcheck | PASS |
| i18n standard command in local directory with spaces | FAIL: existing checker uses URL pathname without decoding `%20` |
| Same unmodified i18n checker via path without spaces | PASS: 762 translation keys, 154 files |
| Dependency audit | FAIL: 5 moderate development dependency findings, minimatch advisory GHSA-g3hv-75wg-m249 |
| Offline replay of synthetic cascade | PASS: 4 input edges, 5 output edges, 4 ignored transit copies, 1 addition |
| Production/private snapshot comparison | NOT RUN |

The audit dependency chain and i18n checker are unchanged from RC2. No dependency
upgrade or unrelated checker fix is included. Release gates are not all green.
The source correction does not authorize deployment or assert that any real
isolated host has been resolved.

## Follow-up: textual IPv4 CDP identity for phone pass-through

After the Cardinale group/proxy opt-in was enabled, the Moxa at
`172.17.3.84` remained isolated. Read-only Zabbix evidence showed a fresh
CDP Device ID of `172.17.2.84` on the same switch port where LLDP reports
Bridge+Telephone capabilities and the FDB learns exactly the phone and Moxa
MACs. The ARP table maps both addresses to unique MACs on the same proxy.

The pass-through parser only accepted an observed byte-encoded CDP IPv4 form
(`01` plus four octets) or a six-octet MAC. It rejected a valid dotted IPv4
Device ID, so it could not identify the phone and produced no candidate.
`PassThroughEvidence::cdpIPv4()` now accepts strictly validated dotted IPv4
text as well, then applies the existing unique-IP, same-proxy checks. Evidence
distinguishes `cdp_text_ipv4` from the byte-encoded form. Invalid IPv4, opaque
CDP IDs, cross-proxy matches, and non-unique IPs still fail closed.

Regression coverage: `tests/PassThroughEvidenceTest.php` passes for the
dotted IPv4 case and rejects invalid dotted IPv4. The synthetic proposal
remains inferred/shared, with no endpoint ports or fabricated metrics. The
full five-switch FDB regression is unchanged. This is still source-only until
the updated module is deployed; the stricter reciprocal-uplink and trunk
checks may independently exclude the live endpoint, so the real graph result
must be checked after that deployment.

## Remaining limits and next step

The legacy inference of access on a capability-known switch remains: one LLDP row
is NOT proof of full LLDP coverage. This patch fixes the documented-port transit
case without claiming to solve every incomplete-discovery case. Unobserved
transit ports on a partly discovered switch can still make access classification
ambiguous or incomplete; FDB output remains inferred, never verified cabling.
No automatic repair can establish a missing physical link from FDB alone.

No customer snapshot was available in this checkout. The previously discussed
ambiguous hosts, unique-candidate exclusions and hosts without ARP therefore
remain unverified against real data. Next: replay an existing authorized private
snapshot and review each newly inferred association. No new lab or staging is
required or created. Production has not been accessed or changed.

## Resume and rollback

Use an existing development checkout outside the frontend webroot:

```sh
git fetch origin fix/rc2-incomplete-lldp-fdb
git switch fix/rc2-incomplete-lldp-fdb
git rev-parse HEAD
git log -2 --oneline
php tests/AutoFdbEdgesTest.php
npm ci
npm run ci:test
npm run ci:frontend
npm run ci:lint-php
npm run ci:bundle-drift
php tools/fdb-replay.php /private/path/scoped-snapshot.json
```

For an immutable code-only reproduction, after checking for local changes:

```sh
git switch --detach a8de74f3161a1964929c25cf5a63e9ffab2778ce
```

To undo only this source correction on a clean development branch:

```sh
git revert --no-edit a8de74f3161a1964929c25cf5a63e9ffab2778ce
npm run ci:test
```

Alternatively inspect the exact original source with:

```sh
git switch --detach 9ace02d5424e2097770e9291500c373b14076b42
```

These are source operations, not production rollback. No deployment occurred,
so no production rollback is needed. Any future separately authorized rollout
must first capture the actual frontend mount, backup/checksums and PHP handler
as required by `BETA-RELEASE-CANDIDATE.md`; restoration must use that verified
backup. Do not assume RC2 is the currently installed production version.

## Follow-up: Ruckus ZoneDirector AP identities

Live Cardinale inspection found fresh `nt.wifi.zd.ap.1` (AP MAC) and
`nt.wifi.zd.ap.10` (AP IPv4) tables on the visible ZoneDirector host. The
standard FDB path previously used host tags, inventory, or same-proxy ARP to
identify endpoint MACs, so Ruckus APs missing from ARP remained unmatchable.

`AutoFdbEdges` now accepts the scoped ZoneDirector AP MAC, active-state and IPv4
items as endpoint identity evidence. The MAC and IP rows must agree, the AP must
be active, and the mapped IP must resolve to exactly one visible host on the
same proxy; normal FDB freshness, port classification, duplicate-owner and
aggregate-port checks still apply.
This does not create Wi-Fi client associations or claim a direct cable. Multiple
active AP MACs mapped to one IP remain ambiguous. Live Cardinale `172.16.1.205`
has two MAC/IP rows, but one is inactive and the active MAC matches the current
firewall ARP entry; the inactive row can therefore be excluded safely.

The query remains restricted to visible host IDs and the selected group. The
existing FDB edge styling marks the result as inferred. No live UI diagnostic
panel was added in this correction; the offline rejection counters remain in
`tools/fdb-replay.php`.
