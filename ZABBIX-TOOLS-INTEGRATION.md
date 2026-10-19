# `zabbix-tools` and Network Topology: responsibilities and migration review

> Source review dated **2026-10-19**. These notes concern five Python scripts
> supplied from a **separate** `zabbix-tools` repository; they are not part of
> the Network Topology PHP frontend module. Their installed versions and the
> discovery action invoking them have **not yet been verified**. No code was
> deployed and no Zabbix configuration was changed during the review.
>
> This is local-fork documentation, not a claim of upstream compatibility.

## 1. What stays separate

| Script | Inputs | Effects | Relation to the topology module |
|---|---|---|---|
| `zabbix-nt-lldp-tag.py` | One IP, Zabbix group, SNMP sysName/LLDP/local MACs, Zabbix LLDP/CDP items, optional on-link ARP and NetBIOS | Adds host `nt:lldp` aliases with Zabbix `host.update` (unless `--dry-run`) | Optional **identity enrichment**, not a link/edge producer |
| `zabbix-nt-lldp-tag-fdb.py` | Same inputs plus SNMP FDB polling on group reporters | Same LLDP tagging if suitable aliases exist; **FDB fallback only prints topology diagnostics** | Additional FDB walk usually overlaps now-implemented Auto FDB snapshot |
| `zabbix-nt-fdb-watch.py` | Switch list, local SNMP credentials, Q-BRIDGE/BRIDGE tables, saved state | Local `fdb_*.json`, `samples.jsonl`, `events.jsonl`; **no Zabbix API writes** | Historical movement/change analysis not available from a current-only map |
| `fdb_flapping.py` | FDB Watch `port_move` and gap events | Read-only sequence/reciprocal-move summary | Helper used by exporter; not a standalone polling service |
| `zabbix-nt-fdb-export.py` | FDB Watch configuration and logs | Atomically writes JSON export consumed via Zabbix Agent 2 | Makes diagnostics and alert metrics available to Zabbix |

The topology module now infers current connections from Zabbix items:
LLDP/CDP neighbor items, `nt.fdb.dot1d`, `nt.fdb.dot1q`, `nt.fdb.baseport`,
`nt.fdb.ifname`, MikroTik `nt.arp`, and `nt.wifi.clients`. The mapping of a
name/chassis-id to the correct monitored host still benefits from reliable
aliases, but Auto FDB and Auto Wi-Fi do **not** require per-client `nt:lldp`
tags. Keep tags that represent actual LLDP identities or deliberate overrides;
do not add fake aliases to compensate for an absent Wi-Fi/FDB entry.

## 2. LLDP discovery helper: exact behavior

Both uploaded LLDP variants read `/etc/zabbix/nt-lldp-tag.json`, requiring
`api_url`, `api_token` and `group`. They accept `--ip`, optional `--group`, and
`--dry-run`. The script **only considers hosts with the exact IP and the
configured Zabbix group**; ambiguous IP matches are rejected.

For identity, the script tries SNMP `sysName`, local LLDP name, bridge MAC and
interface physical addresses. If SNMP identity is unavailable or lacks MACs,
it can try **on-link-only** `ip neigh` ARP, and optional NetBIOS for Windows.
It matches candidate MAC/name aliases against **observed** LLDP/CDP items,
checks whether an alias is already assigned to another host in that group,
and limits the addition to four `nt:lldp` aliases per host. **Caveat:**
`find_observed_aliases()` also accepts the device's *local* LLDP name as an
alias without first requiring corroboration from a neighbor's report. This is
less strong than an observed cross-device MAC match, and deserves a guard in
the next hardening change.

**Write semantics:** outside dry-run it obtains the host's tags, appends
candidates and submits `host.update` with the **full tags array**. It does not
remove old `nt:lldp` aliases, but a read/modify/write race may overwrite an
unrelated concurrent tag edit. Neither version has an automatic rollback or
transactional compare-and-swap operation.

**Difference between variants:** `zabbix-nt-lldp-tag-fdb.py` adds
`fdb_fallback` (default `true`) and walks BRIDGE/Q-BRIDGE FDB tables on SNMP
hosts in the group if LLDP alias lookup fails. It reports direct, shared,
wireless, transient and ambiguous observations. The FDB path is explicitly
**read-only**: if results are found the program logs them and exits, without
writing `nt:lldp` and without persisting any new topology edges. On large
networks this may add significant SNMP traffic at discovery time.

**Critical log/security issue:** both versions log the successful/unsuccessful
**SNMP community string itself**. Treat existing discovery logs as potentially
containing secrets; change the logging to a redacted community identifier in
`zabbix-tools` before expanding rollout. Do not copy log snippets containing
communities or API tokens into tickets or public repositories. The config
option `verify_tls=false` should not be used in production.

## 3. FDB Watch: keep the historical collection when needed

`zabbix-nt-fdb-watch.py` is a standalone SNMP read-only collector, with
explicit SNMP v1/v2c selection and Q-BRIDGE-to-BRIDGE fallback. It maintains
samples and port move events across polling intervals. The event type
`port_change_after_gap` is not treated as a confirmed continuous port move.
`fdb_flapping.py` recognizes sequences of reciprocal changes A-to-B-to-A,
and `zabbix-nt-fdb-export.py` summarizes the last 10/60 minutes into JSON
for an active Zabbix Agent item. This provides a historical diagnostic view
separate from the topology's current MAC-to-port placement.

The exported `Network FDB Watch by Zabbix agent active` template uses
`vfs.file.contents[/var/lib/zabbix-tools/export/fdb-watch.json]` and the
`fdb.watch.*` family of items. Its `{$FDB.WATCH.ALERTS}` macro defaults to `0`
(disabled alerts) in the supplied Zabbix export; enabling alerts and checking
sample freshness are separate, controlled operations. The exact scheduler
(systemd timer, cron, etc.) **has not been verified** from these source files.

## 4. Recommended architecture (not yet implemented)

1. Keep the **Network Topology fork** read-only with respect to Zabbix
   configuration: display current LLDP/CDP/FDB/ARP/Wi-Fi edges from monitored
   item data; do not embed `host.update` in the topology data endpoint.
2. Keep `zabbix-nt-lldp-tag.py` in `zabbix-tools` for **optional provisioning**
   during discovery only when an observed, unique alias cannot be resolved
   automatically. Prefer to remove unnecessary automatic writes eventually,
   based on a staging comparison of maps with/without helper-generated aliases.
3. Do not move the heavy FDB diagnostic fallback into every discovery action.
   Do not replace the running helper with the `*-fdb.py` file until its
   actual use, load, value and side effects have been established.
4. Maintain FDB Watch only where port movement history/alerts are useful.
   Its collector is not obsolete just because the map now has inferred links.
5. Share documented **data formats and tests** between repositories rather
   than merging operational credentials, execution permissions and release
   processes. Keep production rollback for each repository independent.

## 5. Safe migration checklist before touching production discovery

- In Zabbix, inspect the discovery **action and operation** that invokes the
  script: exact executable path, command-line parameters, host/proxy on which
  it runs, and any additional permission or security wrapper. Check its
  scheduler if invoked by systemd/cron instead. Do not assume which of the
  two supplied variants is currently deployed.
- On that machine, compare the installed script **checksum** with the file
  currently tracked in `zabbix-tools`; check the config file path and
  permissions without displaying the token or community values.
- Export a snapshot of existing host tags and record the relevant affected
  group/hosts. Preserve all tags and user-managed aliases, not only
  `nt:lldp`, as the rollback baseline.
- Apply code hardening first on a feature branch: redact community logging,
  add tests for collision/race/ambiguity behavior, measure API/SNMP requests,
  and make automatic writes explicitly controlled. Preserve `--dry-run`.
- Test in a **non-production** discovery run with `--dry-run --ip <IP> --group
  <GROUP>` (replace placeholders), compare outputs with actual LLDP/FDB
  links; do not infer anything from an empty table or stale item.
- Deploy a pinned revision with file backup and known rollback steps; leave
  the production discovery action unchanged until an observed value is
  demonstrated. Any later tag changes require an explicit approval step.

## 6. Repository boundaries

Document these decisions in **both** repositories: this file in
`Liberamente/zabbix-network-topology` and a corresponding operational note in
`zabbix-tools` when that repository is available. Do not copy the scripts into
this frontend module merely to simplify installation: different permissions
and schedules are a useful security boundary.
