# Auto-topology operation guide (local fork, 2026-10-19)

> **Scope:** the `Liberamente/zabbix-network-topology` fork of Network Topology
> for Zabbix 7.0. This is a local operational supplement, **not** an upstream
> 5.5.27 release note. No commands in this document modify Zabbix configuration
> unless explicitly described as a separate administrative action.
>
> The initial end-to-end rollout and observations were limited to the Techsigno
> host group. Do not infer that other clients have been tested or enabled.

## 1. What is implemented

The topology combines several independently collected sources. The Zabbix
frontend module uses existing Zabbix items: it does **not** run SNMP polls
against remote devices and it does **not** write host tags.

| Source | Zabbix item | Purpose | Interpretation |
|---|---|---|---|
| LLDP / CDP | Neighbor-discovery item prototypes | Physical neighbors and ports | Preferred for physically reported links |
| FDB | `nt.fdb.dot1d`, `nt.fdb.dot1q`, `nt.fdb.baseport`, `nt.fdb.ifname` | MAC-to-switch-port mapping | Inferred access link, not LLDP confirmation |
| ARP | `nt.arp` | IPv4-to-MAC lookup on a router | Assists endpoint identity and FDB resolution |
| Wi-Fi | `nt.wifi.clients` | MikroTik registered clients and RSSI | Wireless association, **not** an Ethernet cable |

Auto FDB needs unambiguous identity and filters on the same nonzero Zabbix
proxy; a missing or ambiguous MAC/port is not a valid basis for an edge.
LLDP and explicitly configured links have priority where applicable. The
Wi-Fi path provides distinct AP-to-client associations and must not be rendered
as FDB cables or as aggregate Ethernet traffic.

In the tested pilot, a NAS without usable LLDP neighbor data was resolved
through router ARP plus switch FDB and attached to the switch's access port.
The Wi-Fi path used AP registration data and displayed received-signal strength
(RSSI) in dBm. Client counts naturally vary as clients associate or disconnect.

### Collection items and templates

The following exported templates are included in this fork under `templates/`.
Their UUIDs are retained from the supplied Zabbix 7.0 export; verify the
existing installation before importing. An import is a configuration change,
not part of a source-code deployment.

| Template file | Key | SNMP OID | Pilot interval |
|---|---|---|---|
| `nt_mikrotik_arp_template.yaml` | `nt.arp` | `walk[1.3.6.1.2.1.4.22.1.2]` | `5m` |
| `nt_mikrotik_wifi_clients_template.yaml` | `nt.wifi.clients` | `walk[1.3.6.1.4.1.14988.1.1.1.2.1.3]` | `1m` |
| `nt_fdb_snmp_template.yaml` | Four `nt.fdb.*` keys | BRIDGE/Q-BRIDGE/ifName OIDs | `5m` |
| `nt_fdb_watch_agent_active_template.yaml` | `fdb.watch.*` | Active agent file JSON | Requires separate exporter |

Item type is **SNMP agent** and information type **Text** for the SNMP walks.
For the Wi-Fi item, the MikroTik private OID returns registered-client MACs,
radio `ifIndex` and RSSI; a matching monitored host must be uniquely identified
before the association appears on the map.

**Important exported-template correction.** The original `network.yaml`
contained the key `nt.arp` under a template whose title mentioned WiFi, and
`nt.wifi.clients` under a template titled ARP. The two repository templates
carry **corrected display names** and preserve their original UUIDs. Compare
with the current Zabbix template names/links before importing; never import
these files blindly over a production installation.

## 2. Tags: identity, device role, exceptions

- `nt:lldp=<alias>`: alternative LLDP/CDP neighbor name or chassis identifier
  for a monitored host; repeat only for actual on-wire aliases.
- `nt:icon=<type>`: manually override the displayed device role when automatic
  detection from name/template/LLDP capabilities is misleading.
- `nt:uplink=<host>:<port>`: explicit physical attachment for an endpoint where
  automatic neighbor data is unavailable. Preserve manual intent.
- `nt:parent=<host>`: logical hosting relation; **not** a physical cable.

`nt:icon` is **not** an arbitrary free-form icon label. The allowed tokens
recognized by the fork's `topology/HostTagParser.php` are:

`firewall`, `router`, `switch`, `wireless`, `internet`, `server`,
`hypervisor`, `linux`, `windows`, `macos`, `webserver`, `mailserver`,
`container`, `monitoring`, `storage`, `camera`, `printer`, `ups`, `homeauto`.

The parser is the final source of truth for any future version. In the pilot,
Cudy Ethernet switches required `nt:icon=switch`: they had been classified as
Wireless in the Management tab. The correction is a **Zabbix host-tag change**,
not a modification to the Wi-Fi edge collector.

## 3. JavaScript build is mandatory

**Critical deployment lesson (2026-10-19):** The browser loads
`assets/js/dist/nt-bundle.js`, **not** the individual ES modules such as
`build-elements.js` or `traffic.js`. A Git checkout that changes only those
source files can make `git log` report the new commit while the browser still
runs the previous bundled code.

The project build command, from `package.json`, is:

```sh
npm ci --no-audit --no-fund
npm run build
node --test tests/AutoWifiUiTest.mjs
node --check assets/js/dist/nt-bundle.js
```

`npm run build` first synchronizes shared widget files, then invokes esbuild.
**Check `git status --short` after compiling:** `sync-widget-shared.mjs` can
modify additional tracked files. Commit the compiled `nt-bundle.js` with the
source changes, or in a dedicated build commit, **before** deploying.

The PHP view computes a cache-busting `?v=` from the greatest modification
time of the JS bundle, CSS and manifest. Confirm that the script URL changed
when deploying a rebuilt bundle. On the browser, use Network / Disable cache
and hard refresh for validation.

For a server with Docker but no local Node.js, build in a separate staging
Git worktree (writable), not in the production module directory:

```sh
BASE=/opt/liberamente/zabbix-server
STAGE="$BASE/staging/nt-auto-wifi-ui-fix"
docker run --rm -v "$STAGE:/work" -w /work node:22-alpine sh -ec '
  npm ci --no-audit --no-fund
  npm run build
  node --test tests/AutoWifiUiTest.mjs
  node --check assets/js/dist/nt-bundle.js
'
git --no-pager -C "$STAGE" diff --check
git -C "$STAGE" status --short
```

The current tested chain is:

| Commit | Meaning |
|---|---|
| `dd31147` | Auto-FDB pilot integration |
| `e65fabc` | Auto Wi-Fi backend edges |
| `16423a1` | Initial Wi-Fi UI styling |
| `5967634` | Exclude Wi-Fi from Ethernet hover/panel/heatmap semantics |
| `f364025` | Rebuilt frontend bundle containing the new UI |

This chain is the **local fork**, not official upstream release numbering.
The Wi-Fi UI only rendered as expected after `f364025` was deployed.

## 4. Validation and observability

1. Verify Zabbix SNMP items are supported, enabled and recent. Missing data is
   not equivalent to zero or healthy operation.
2. Inspect the backend network data: LLDP, FDB and Wi-Fi edge counts can vary;
   check for duplicate AP-client associations and preserved wired links.
3. For Wi-Fi associations, `src` includes `wifi`, `match` is `wifi`, and
   `signal_dbm` contains the reported RSSI; the frontend transforms it to
   `signalDbm` and labels the edge in dBm.
4. In Technical view, Wi-Fi edges should be blue and dashed; hover and detail
   panel should **not** show inferred Ethernet rates, cable capacity, port
   traffic sparklines or interface counters for those associations.
5. In Management view, classify network devices independently of their
   relationship type; use `nt:icon=switch` for switches misclassified as APs.
6. Check that LLDP, FDB, manual edges and port-parallel links still work,
   including any known NAS-on-switch inferred FDB link.
7. Run the JS UI regression (`tests/AutoWifiUiTest.mjs`) and appropriate PHP
   tests (`AutoWifiEdgesTest.php`, `AutoFdbEdgesTest.php`, `FdbParserTest.php`,
   `FdbResolverTest.php`, `LldpEdgeBuilderTest.php`) in staging.

## 5. Deployment and rollback

Do not switch production directly to an uncommitted staging bundle.

1. Check `git status --porcelain` is empty for both worktrees, that the current
   production commit is the expected one and the candidate descends from it.
2. Verify the intended file diff, JS/PHP tests and checksum of the compiled JS.
3. Back up the complete module directory and validate the archive with
   `tar -tzf` **before** switching commits.
4. Deploy a pinned commit with `git switch --detach <commit>` in the production
   module worktree, then verify `git log -1` and the JS bundle checksum.
5. Validate in the browser and record results. No container restart is normally
   needed just for a changed static JavaScript bundle.

If the compiled-bundle release `f364025` is active and regression occurs,
restore the prior pinned commit `5967634` with a clean worktree, then
refresh the browser. That rollback restores the **old UI behavior**, including
its known lack of the regenerated Wi-Fi JS. Retain the dated pre-deploy tar
backup as an independent rollback option.

## 6. External scripts (`zabbix-tools`) — source review completed

The five supplied Python files were reviewed on 2026-10-19. They belong to
**a different repository** and were **not** installed, restarted or changed by
this review. Their actual deployment versions, schedules and discovery action
configuration still need to be verified on the respective Zabbix proxy.

| Script | Purpose | Proposed treatment |
|---|---|---|
| `zabbix-nt-lldp-tag.py` | Enriches host `nt:lldp` aliases during discovery; writes via `host.update` unless `--dry-run` | Retain as an optional discovery helper; harden independently |
| `zabbix-nt-lldp-tag-fdb.py` | Variant that also **diagnoses** bridge/Q-bridge FDB position | Do not switch discovery to this variant by default; the FDB fallback does not create topology edges or tags |
| `zabbix-nt-fdb-watch.py` | Polls FDB tables over SNMP, preserves samples/port-move events | Retain when MAC-moving/flapping history is required |
| `fdb_flapping.py` | Computes reciprocal port-movement sequences from event logs | Keep as a dependency of the FDB exporter |
| `zabbix-nt-fdb-export.py` | Publishes the FDB Watch JSON consumed by Zabbix Agent 2 | Retain when the FDB Watch template is used |

Auto FDB and Auto Wi-Fi remove many needs for *link* tags, but do not fully
replace LLDP identity enrichment. Conversely, FDB Watch measures **historical
movement**, unlike the map's current snapshot. There is no justified basis
to retire the historical collector solely because the map works.

**Do not automatically activate the longer `*-fdb.py` discovery variant.** It
may walk FDB on many managed SNMP reporters per discovered host. Its additional
fallback is read-only and does not set `nt:lldp` tags. A controlled runtime,
load and value assessment must precede any change to the discovery action.

Source-review findings to address in the separate `zabbix-tools` repository:

- The two LLDP variants print the chosen **SNMP community value** to their
  output. Remove this disclosure before sharing logs or broader deployment.
- Both variants use `host.update` with an entire tags array after `host.get`;
  concurrent tag changes could be overwritten. Their default mode can write.
  `--dry-run` avoids that write and should be the first step in testing.
- They use a Zabbix API token and SNMP communities from
  `/etc/zabbix/nt-lldp-tag.json`; keep it out of Git, protect file permissions,
  and keep TLS verification enabled.
- Existing `nt:lldp` aliases and collision checks must be respected. Keep
  alias changes opt-in and make an explicit pre-change host-tag snapshot for
  rollback before changing discovery actions.

See [ZABBIX-TOOLS-INTEGRATION.md](ZABBIX-TOOLS-INTEGRATION.md) for the detailed
source comparison, collection pipeline, compatibility plan and safe migration
checklist. This guide does not authorize changes to the running scripts.

## 7. Scope and security

These are notes about a tested **single customer pilot**; remove customer
identifiers and real host IPs from public documentation and test fixtures.
Do not commit SNMP community strings, user credentials, tokens or environment
secrets. Separate Zabbix UI configuration changes (tags/template import) from
source deployment (Git worktrees), with separate rollback plans.
