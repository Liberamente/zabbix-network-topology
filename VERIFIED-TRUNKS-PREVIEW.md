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

## Interactive Cytoscape preview on the Mac (offline and private)

This branch also includes:

- `tools/verified-trunk-preview.html`: a standalone, visually faithful
  technical network view based on the module's **vendored Cytoscape.js**,
  without importing or changing the production module's bundled frontend.
- `tools/verified-trunk-preview-model.js`: deterministic node/edge layout and
  fail-closed validation of the **adapter output schema 1** (not the earlier
  zabbix-tools raw report schema 2). Four logical arcs; member pairs remain
  nested in the edge details.
- `tools/verified-trunk-preview-app.js`: no fetch, uploads, external services,
  API or local storage; the operator chooses the private payload through a
  local browser file input. Clicking an edge displays each physical port pair,
  real ifIndex, native LLDP index and source timing. Each switch is represented
  by a switch icon and a neutral unknown-live-state border.
- `tests/VerifiedTrunkCytoscapeTest.cjs`: synthetic Node 22+ tests for
  graph counts, stable positioning, exclusivity, lack of endpoint-candidate
  promotion, scope, false live statuses and invalid/incomplete members.

The viewer displays **structure only**: four verified static trunks, five
switches, eight physical port pairs. No invented link performance, ping,
port-operational status, current alarms, throughput or user coordinates.
The blue link color means LLDP reciprocal/topological proof, not "port up"
or "health green".

### From the proxy to the Mac

**On the proxy** (`ubuntu-cardinale`), use the existing checkout; do not
re-clone or touch the deployed module:

```sh
cd ~/nt-verified-module-preview
git status --short
git fetch origin feature/nt-verified-trunk-adapter-preview
git switch --detach FETCH_HEAD
php tests/VerifiedTrunkAdapterTest.php
php tests/VerifiedTrunkPreviewCliTest.php

# Only if Node is installed:
node --test tests/VerifiedTrunkCytoscapeTest.cjs
```

**On the Mac**, use a new disposable directory, NOT the local
`production/v5.5.27` worktree:

```sh
git clone --depth 1 --single-branch \
  --branch feature/nt-verified-trunk-adapter-preview \
  https://github.com/Liberamente/zabbix-network-topology.git \
  "$HOME/nt-verified-cytoscape-preview"

umask 077
mkdir -p "$HOME/nt-preview-private"

scp zabbix@ubuntu-cardinale:/home/zabbix/nt-preview/cardinale-module-payload.json \
  "$HOME/nt-preview-private/"

open "$HOME/nt-verified-cytoscape-preview/tools/verified-trunk-preview.html"
```

If `ubuntu-cardinale` is not resolvable from the Mac, use the usual
SSH-resolvable proxy hostname/IP. This is only an encrypted local copy from
the operator-controlled host to the Mac; do not share the JSON publicly.

In the browser, click **Apri JSON della preview** and select:

`~/nt-preview-private/cardinale-module-payload.json`

Click any arc to view the **two physical port pairs**. Zoom/pan or press
**Centra topologia**. The browser loads the local file directly, the local
`assets/js/cytoscape.min.js`, and the two local preview scripts. No local
web server and no internet service are needed.

The HTML is a **separate pilot viewer** using the same Cytoscape library,
not yet the main Network Topology production renderer. For production
integration we must still reconcile old physical LLDP edges against the
new four logical trunks, propagate `nt_members` through the existing
`buildEdgeElements` and edge detail panel, and use live NodeBuilder data.

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
