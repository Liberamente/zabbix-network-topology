# Verified static trunks — isolated Network Topology preview

**Status: Draft feature. Production v5.5.27 and its deployed module remain unchanged.**

This proof-of-concept maps validated offline `zabbix-tools` logical-link evidence
to the actual PHP `nodes` / `edges` data contract used by the
Network Topology v5.5.27 module. It creates an **offline preview only**;
it does not load a customer report inside a live web request, write Zabbix
maps/hosts/items, or insert the output into the production topology.

## Input contract

Use a privately stored schema-2 report produced by
`scripts/nt-arp-fdb-report.py` in the separate `zabbix-tools` repository,
with `logical_links`, `logical_link_summary` and
`logical_link_issues: []`.

The pilot report has 16 reciprocal directional LLDP observations, eight
distinct physical-port pairs and four fully covered, verified HPE static
trunk-to-trunk links. **No customer identifiers, MACs, IPs, report JSON,
DOT/SVG images or private network snapshots are committed to this fork.**
Test fixtures use synthetic IDs and names only.

The adapter rejects:
- wrong scope, missing explicit visible host IDs, missing/mismatched group,
  duplicate links, reused aggregates, unknown or disabled/unverified
  vendor semantics, contradictory counts or recorded link exclusions;
- nonunique physical ports, member count mismatch, nonnumeric native
  LLDP indexes, missing item provenance, incomplete or stale source clocks,
  and more than 120 seconds total dynamic time spread across the two ends;
- any report or individual link with `publication_allowed` different
  from the explicit boolean `false`.

All four required logical arcs are exported once, with their entire
two-member physical evidence under `nt_members`. The edge fields map to
the module's existing contract:

| Network Topology field | Evidence mapping |
|---|---|
| `id`, `from`, `to` | Stable, canonical aggregate-pair ID and Zabbix host IDs |
| `ports[hostid]` | Logical HPE interface label, e.g. `Trk1` |
| `port_idx[hostid]` | *Logical* IF-MIB ifIndex (not LLDP port number) |
| `confirmed`, `reporters` | Both physical ends reciprocally verified |
| `nt_members` | List of physical port labels, ifIndexes, native LLDP suffixes and timestamps |
| `port_metrics` | Empty: no traffic/per-link throughput claim |
| `nt_offline_preview` | Boolean true; never a live/published edge |

Node data contains only identifiers, labels, switch type and explicit
`null` for unknown ping, severity and problem counts. A *future* live
integration will use the module's own NodeBuilder/ProblemLoader/MetricExtractor
rather than pretend an offline snapshot is current.

## Running a private test

The example IDs/group below are **fictional**. Substitute the IDs of
the actual scoped hosts and the group ID when testing privately.

```sh
php -v
php tests/VerifiedTrunkAdapterTest.php
php tests/VerifiedTrunkPreviewCliTest.php
umask 077

php tools/nt-verified-trunk-preview.php \
  --input /path/to/private-logical-links-report.json \
  --output /path/to/private-module-payload.json \
  --dot-output /path/to/private-module-preview.dot \
  --group-id 900 \
  --hostids 1001,1002,1003,1004,1005 \
  --expect-links 4

# Optional, if Graphviz is installed:
dot -Tsvg /path/to/private-module-preview.dot \
  -o /path/to/private-module-preview.svg
```

The end-to-end CLI test uses `tests/fixtures/verified-trunks-synthetic.json` with fictional `SW-1001`.. `SW-1005`; it checks four arcs, eight physical pairs, owner-only permissions, refusal to overwrite and refusal to cross host-group boundaries. It never opens a network connection or invokes Zabbix.

The script creates its output using exclusive creation with mode 0600.
It will not overwrite an existing report/preview. Keep all generated
customer-specific data out of git, screenshots and shared presentations.

Expected counts from the *tested* pilot: five preview nodes, four logical
trunk edges and eight nested physical member pairs. This expectation must
be checked again against the real private report; synthetic tests do not
prove that a later live network snapshot is unchanged.

## Integration boundary and rollback

This branch adds a pure adapter and an offline CLI, not an active
`NetworkTopologyData` controller hook. Existing LLDP/CDP discovery can
already produce one edge per physical cable. Blindly appending four
logical arcs to those would duplicate links and corrupt topology counts.
Before any staging hook, implement reviewed reconciliation with the
existing LLDP member edges, scope/permission controls, correct current
host status, metrics and client-visible member details.

The current frontend's `buildEdgeElements()` copies standard port fields
but **does not yet copy** the added `nt_members` into Cytoscape edge
data. Accordingly, the two physical member pairs are visible in the
offline JSON and DOT tooltip, not yet in the live edge-detail panel.
Changing that frontend requires its own test and bundle build.

Rollback today requires only leaving or deleting the isolated feature
branch. No runtime settings, Zabbix API permissions, discovery rules,
templates or production files were altered.
