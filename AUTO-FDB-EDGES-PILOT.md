# Auto FDB edges — experimental pilot (Techsigno)

> **Historical pilot instructions.** This file documents the original opt-in
> branch, where production was explicitly unchanged. The later Auto-FDB/ARP
> and Auto Wi-Fi pilot rollout (through compiled bundle commit `f364025`) is
> documented in [AUTO-TOPOLOGY-OPERATIONS.md](AUTO-TOPOLOGY-OPERATIONS.md).
> Do **not** treat the feature-flag or rollout commands below as the current
> production deployment procedure.

**Branch:** `feature/auto-fdb-edges` from `production/v5.5.27`.
**Production remains unchanged.** The feature flag is **OFF** by default.

## Data path

The NetworkTopologyData controller reads *only the already-visible hosts* in
its selected Zabbix group/hop scope. It reads four existing `nt.fdb.*` text
items on these hosts, and optionally `nt.arp` on in-scope routers (no SNMP or
Zabbix writes from this module). `AutoFdbEdges` does the rest:

1. Select recent FDB+baseport+ifName walks (within 15 minutes).
2. Identify an endpoint's MAC using existing `nt:lldp` MAC aliases, Zabbix
   inventory `macaddress_a`/`macaddress_b`, or an in-scope router's `nt.arp`
   item for the endpoint's IPv4 address. The latter is the *tag-free route* for
   unannounced NASs, but requires `nt.arp` collection on the router.
3. Require an exact MAC-to-host identity and the same **nonzero** Zabbix proxy
   for endpoint and candidate switch. Never match by IP across proxies.
4. Build the known uplink ports from existing LLDP switch-to-switch edges and
   LLDP capabilities. Do **not** use a MAC-count heuristic for automatic links.
5. Add an inferred edge only for exactly one classified access port, only when
   the endpoint has **no existing LLDP or `nt:uplink` edge**.
6. Add `src: ['fdb']`, `iface: 'nt:fdb'`, `match: 'fdb'`, `confirmed: false`,
   `confidence: 60`, `last_seen` and `vlan` to the edge. Do not claim it is an
   LLDP-confirmed physical cable.

**Limitations:** Same-proxy matching is intentionally strict: hosts monitored
without a proxy, via different proxies, or devices with no MAC identification
are not automatically connected in this pilot. A bridge or unmanaged switch
behind an access port is another reason an FDB-based candidate may *not* mean
a direct cable. Do not use inferred connections as a cable inventory.

## Deploy safe pilot branch, not production

On the Mac, where the branch `feature/auto-fdb-edges` already exists and is
checked out:

```sh
git status --short
# Assuming your browser placed the downloaded patch in ~/Downloads.
# Adjust this path if you saved it elsewhere.
PATCH="$HOME/Downloads/auto-fdb-edges.patch"
git apply --check "$PATCH"
git apply "$PATCH"
php -l actions/NetworkTopologyData.php
php -l topology/AutoFdbEdges.php
php tests/AutoFdbEdgesTest.php
php tests/FdbParserTest.php
php tests/FdbResolverTest.php
php tests/LldpEdgeBuilderTest.php
git diff --check
```

If all the *full-repository* tests pass, commit on the **feature branch only**:

```sh
git add actions/NetworkTopologyData.php topology/AutoFdbEdges.php \
  tests/AutoFdbEdgesTest.php AUTO-FDB-EDGES-PILOT.md
git commit -m 'Add opt-in scoped automatic FDB topology links'
git push -u origin feature/auto-fdb-edges
```

No production rollout is part of these steps.

## Opt-in test in an isolated staging deployment

The single flag in `actions/NetworkTopologyData.php` is:

```php
private const AUTO_FDB_EDGES = false;
```

Set it to `true` **only in a separate staging/test deployment** after unit
and integration tests. Running Zabbix against a production server is acceptable
for **read-only** map requests, but do not replace the production module yet.

For a Techsigno end-to-end check, the QNAP has a manual `nt:uplink`; its edge
will be preserved and the FDB edge will be suppressed (by design). Check the
generated edge with a staging fixture where that override is absent, or during
a separately authorized reversible test window. Do not remove the live tag as
part of applying this patch.

The Cudy switches report capabilities `14 00`, which do *not* include the LLDP
Bridge bit. The algorithm consequently uses their **existing explicit LLDP
inter-switch edge GE24 <-> GE24** to exclude the transit ports. The same
scenario is covered in the synthetic test suite.

Optional **tag-free** IP-to-MAC identification on Techsigno: create an SNMP
agent **Text** item on the router host (not on the NAS), with key `nt.arp`, OID
`walk[1.3.6.1.2.1.4.22.1.2]`, interval `5m`, history `1d` (if supported by
that router). The API reader restricts ARP matches to the same proxy and to a
uniquely identified monitored IPv4 host. Not necessary for this initial QNAP
pilot because the NAS already has an `nt:lldp` MAC alias.

## Rollback

If applied but not committed:

```sh
git apply -R "$HOME/Downloads/auto-fdb-edges.patch"
```

If committed to the feature branch, roll back by checking out the known
production branch, **not** by deleting the new source files in production:

```sh
git switch production/v5.5.27
```

For a future test deployment, restore its previously backed-up module directory
and release symlink/tag using the existing controlled deployment procedure.
