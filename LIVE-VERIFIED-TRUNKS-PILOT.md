# Live Zabbix frontend — read-only verified HPE trunk pilot

**Experimental Draft feature.** Not released, merged or installed in a
production frontend. The Zabbix server can monitor production customers:
this pilot must not affect monitoring, discovery or ordinary maps.

## Activation and isolation

- Only a logged-in **Super Admin** with **exactly one selected hostgroup**
  sees the `TEST TRUNK · Apri JSON locale` control in the Technical tab.
  The host+hop mode and wallboard are excluded.
- The user selects the previously validated **private** module-payload
  `schema=1` JSON from the local computer with the browser file picker.
  The file is not uploaded and never stored by Zabbix or by localStorage.
- Strict validator `assets/js/nt-verified-trunk-model.js` requires
  `preview_only=true`, `publication_allowed=false`, and
  `live_node_status_available=false`. The view also requires an exact
  hostgroup ID, a maximum **15-minute-old** capture, five authorized and
  currently visible monitored hosts, four logical static trunks and eight
  reciprocal physical-port pairs.
- Clicking the control switches ONLY the **Technical** tab to an isolated
  five-switch subgraph with four logical trunk edges. It replaces the ordinary
  LLDP-edge graph **only in this browser view**. It never appends logical trunks
  to pre-existing physical cables, so no eight-member-arc duplicates arise.
  Other tabs retain the original backend API nodes and edges.
- **LIVE NODE STATE** is read from the unmodified, permission-filtered
  Zabbix `NetworkTopologyData` response and refreshed normally: ping,
  severity, active problems, maintenance, availability and CPU/RAM.
  Static-trunk membership and topology evidence come from the local snapshot.
  No cable status, throughput or interface error rate is guessed: blue arcs
  mean reciprocal LLDP/topological proof, **not** healthy/up.
- Clicking a logical arc opens the usual edge-detail panel specialized for
  all physical member pairs, their port numbers, ifIndex, native LLDP indices
  and temporal evidence.
- Pilot graph nodes cannot be dragged; layout positions are not saved during
  the pilot or its transitions. It ignores other manual links, ghosts,
  host-aggregation, leaf-collapse and unauthorized edit menus.
  The feature does not make Zabbix API writes.
- After 15 minutes, a scope switch or loss of host visibility, the next
  refresh cancels the pilot and renders the ordinary unchanged graph.
  Press `Disattiva` or reload the page to leave the pilot immediately.
  **No change to Zabbix host/item/trigger/discovery/configuration.**

## Build / tests (disposable feature checkout)

Use branch `feature/nt-verified-trunk-adapter-preview` in an isolated
directory. **Do not run deployment as part of this step.**

```sh
git fetch origin feature/nt-verified-trunk-adapter-preview
git switch --detach FETCH_HEAD
php -l views/network.topology.view.php
php tests/VerifiedTrunkAdapterTest.php
php tests/VerifiedTrunkPreviewCliTest.php
node --test tests/VerifiedTrunkCytoscapeTest.cjs
node --test tests/VerifiedTrunkLivePilotTest.cjs
npm ci
npm run build
```

The main module serves a prebundled frontend script, so **`npm run build`
is mandatory after JS source changes**. The validator is an independent
static asset under `assets/js/`, which is included by the normal module
packaging (the `tools/` directory is deliberately excluded).

No customer names, real host IDs or network addresses are committed.
The tests use fictional five-switch / four-link synthetic fixtures.
If `npm ci` is unavailable in a protected test environment, build in
a separate development checkout, **not** on the live Zabbix frontend.

## End-to-end evidence freshness

The already generated adapter payload will expire after 15 minutes.
Immediately before testing the frontend, recollect new read-only LLDP/FDB
evidence with `--align-history` in the authorized group; generate a
new private `logical_links` JSON and adapter payload using the documented
`--group-id` and `--hostids` constraints. All real data stays off GitHub.

Copy the private adapter payload to the operator's Mac by an authorized
encrypted SSH connection. Open the Zabbix frontend normally as Super Admin,
select **one group**, then use **TEST TRUNK → Apri JSON locale**.

Expected for the validated pilot: exactly **5 switch icons**, **4 curved
blue logical edges**, **8 nested physical port pairs**, a visible
`COLLAUDO TRUNK` banner, live node severity and ping where measured,
and no Zabbix write or publication.

## Deployment boundary / rollback

**Do not merge this Draft PR.** Verify PHP/Node tests and the generated
bundle, then back up the installed `network_topology` frontend module
before a controlled replacement on the designated testing frontend.
Never use `deploy.sh` blindly: it copies a module onto a remote frontend
and reloads its web PHP handler. Follow the operator's existing
deployment/rollback method and test only the main module.

Leaving the pilot in browser: press `Disattiva` or reload. Reverting the
code: restore the known-good frontend module directory/bundle from the
pre-test backup or redeploy the previous production-tagged commit.
The original `NetworkTopologyData`, `LldpEdgeBuilder`, database,
SNMP discovery, and Zabbix service state remain unchanged.

Known limitations: this is a **client-side, file-selected pilot**, not
automatic continuous edge collection. Automatic publication will require
a separately reviewed server-side evidence data path with authenticated
read-only Zabbix retrieval, freshness/cross-tenant safeguards, upstream
LLDP-edge reconciliation, and scoped feature rollout.
