# Common Topology baseline and scoped network exceptions — decision 2026-11-11

## Goal and topology delivery model

There is **one portable common Topology core** in
`Liberamente/zabbix-network-topology`, integrated with discovery tools from
`zabbix-tools`. The operator designates SOA, Techsigno, and Cardinale as
production-instance **laboratory groups**, currently without customer access
to the custom Topology module. No separately isolated Zabbix DB exists.
Use SOA for the first controlled production-instance pilot, then apply the
same qualified common release to Techsigno and Cardinale, and only then
consider wider group rollout.

The RC1 LLDP template test and the frontend module version are **separate
deliverables**: passing the template's import tests neither installs a new
frontend module nor proves all edge types. Record the installed module and
candidate commit separately.

## Common topology capabilities and required semantics

| Requirement | Common core behavior / policy | Gate |
| --- | --- | --- |
| Central managed switches / FDB aggregation | Configurable switch roles and FDB source scope; topology may use MAC learning to form **inferred** association, never assume direct physical cabling | Inventory/prioritize SOA pilot; verify actual implementation |
| Excluded local IP aliases | Maintain a scoped exclusion list for secondary/tertiary management and router interfaces belonging to an already known node; preserve host identity/evidence and avoid duplicate nodes | Test per host, group and proxy |
| Unmanaged fan-out switch | Permit an unknown transparent intermediate device behind a single managed port with several endpoints; represent shared-uplink or unresolved structure without inventing direct cables, ports or a specific unseen switch model | UI proof with synthetic fixtures plus lab observation |
| Transparent bridge / VoIP phone → PC | Preserve upstream physical link evidence and downstream pass-through **inferred** association, with opt-in policy and independent confidence/source | Regression and real lab observations |
| Multiple managed switches and cascades | Keep reciprocal LLDP/CDP verified only when matching independent evidence exists; port mapping and chains must be evidence-driven | Full topology visual replay |
| Parallel ports / trunk / LAG | Preserve each member port, distinguish logical aggregate from its physical members, and never infer member count from duplicate LLDP announcements or MAC learning | Verified trunk UI and item replay |
| Wi-Fi client / AP | Wi-Fi is a distinct edge type, not a physical Ethernet cable | UI and visibility regression |
| Multi-proxy / customer-group isolation | Group/user visibility and selected proxy bound every query and edge; no cross-scope endpoint or evidence disclosure | Authorization and route scope checks |
| DHCP and changing addresses | Prefer robust device identity and time-scoped observations; a reused address is not evidence of a persistent physical connection | Cross-epoch synthetic regression |

The table records intended common capabilities, **not** proof they are all
fully implemented in the published RC1. Group-specific deployment settings
may exclude or label observations but must preserve raw provenance and
original discovery evidence for troubleshooting.

## Incomplete inventory and intermittent reachability (SOA lab finding, 2026-11-11)

The inventory is **not exhaustive**. There may be unmanaged or unmonitored
switches, intermediate transparent equipment and powered-off endpoints. A
discovered endpoint with no edge is not automatically a topology defect; an
unmatched LLDP neighbor can correspond to a real, not-yet-inventoried device.
Do not invent a host, an unseen switch, a port, or a verified physical cable
merely to make the map appear connected.

State distinctions to preserve in all future group deployments:
- **Observed verified**: trustworthy LLDP/CDP with appropriate independently
  scoped identity/port evidence, with any reciprocal confirmation explicitly
  distinguished from a one-sided report.
- **Observed inferred**: FDB/ARP/shared-port, DHCP, Wi-Fi or pass-through
  association with clear provenance, age and confidence, never silently promoted
  to physical.
- **Unknown physical intermediate**: multiple MACs on a managed access port may
  indicate a transparent fan-out switch, bridge or virtualization, not prove
  any one device type or direct cable.
- **Not observed/offline**: hosts not responding to recent ICMP or not reporting
  LLDP/FDB can remain unconnected, be filtered by an explicitly selected UI
  view, or receive a diagnostic reason; do not delete them just for presentation.
- **Ambiguous identity**: generic LLDP identifiers such as `localhost`, rotating
  locally assigned Chassis-IDs and conflicting aliases remain unmatched until
  independently attributable; show quality evidence instead of guessing.

Read-only SOA baseline on 2026-11-11: 94 group hosts, of which 91 enabled.
One most-recent ICMP item per host identified 87 hosts with a reading within
10 minutes (71 success, 16 failure), three stale readings and four hosts
without a matching ICMP item. The graph at the same checkpoint displayed
91 hosts, 36 edges and three ghost nodes. LLDP-Q displayed 62 matched and
six unmatched observations (91% match), including generic `localhost`.
Six switches had 24 recent FDB items, 23 with nonempty raw content. These
numbers are **not** proof of physical topology completeness or valid MAC rows.

For acceptance, report coverage and uncertainty separately from algorithmic
correctness: number of inventoried hosts, reachable/unreachable/unmeasured,
sources reporting LLDP/FDB, verified and inferred edges, unresolved shared
ports, ghosts, stale observations and excluded addresses. Never mark all
disconnected hosts as failed, and never require 100% connectedness as a
release gate.

## SOA FDB interface-type verification (2026-11-11)

Read-only Zabbix IF-MIB observations from the two SOA MikroTik reporters
confirmed that `client-bond3`, `client-bond4`, `client-bond5` and
`peer` currently expose `ifType=161` (IEEE 802.3ad LAG). On those devices
`ether1` exposes `ifType=6` (Ethernet) and the default bridge is
`ifType=209` (transparent bridge). The GS1920 interface `swp00` exposes
`ifType=6`. The source FDB walk has multiple learned MAC identities on
some of these ports, which does **not** prove an unmanaged switch or cable.

The common FDB parser now explicitly recognizes the observed
`client-bondN` naming pattern as a logical aggregate in its existing
conservative name-based exclusion, with synthetic regression coverage.
This is a narrowly scoped compatibility improvement, **not** a complete
`ifType`-aware interface classifier. Generic names such as `peer` cannot
safely be excluded by name alone across all vendors: `peer` on SOA is known
as an aggregation from the SNMP type, but that is not universal. A generic
`ifType=161` and `ifType=209` classification pipeline must be assessed
separately with permission/group/proxy scoping and explicit precedence over
name-only fallbacks. Until then, do not promote an FDB observation on such
ports into a verified physical cable or invent a missing intermediate device.

SOA FDB read-only snapshot: six managed switches, 24 fresh FDB walk items;
HPE logical `Bridge-Aggregation1` had 68 distinct learned unicast MACs,
HP `Trk1/Trk2` 17/47 and GS1900 `LAG5/LAG6` 35/26. The non-aggregate
labels `swp00` (GS1920) and `ether1` (MikroTik) held respectively
54 and 18–19 learned unicast MACs. Those counts express forwarding-table
learning, not physical topology or endpoint inventory.

## IF-MIB type-aware inferred-FDB filter (candidate; not yet deployed)

The common backend now queries `net.if.type[ifType.N]` **only** for the
already-in-scope FDB-source hosts of the selected group. The lookup adds an
optional, read-only input to `AutoFdbEdges::append()`. Existing call sites
and snapshots without interface-type items continue to use the earlier
conservative name-based aggregate filter. No Zabbix item or template is
created and no additional unrestricted host queries are permitted.

The filter maps an exact `ifType.N` item to the same host's FDB
`ifName[N]` and suppresses FDB-derived access edges for:

- `161` — IEEE 802.3ad LAG / logical aggregate;
- `209` — transparent bridge / logical bridging interface.

Type `6` (Ethernet) is **not** excluded simply for learning multiple MACs:
it may be a valid access, uplink or connection to an unmonitored fan-out
device. The existing LLDP/CDP evidence, port-membership and automatic
FDB safeguards remain separate. Missing, invalid, foreign-host, disabled
or older-than-48-hour type items do not override the previous behavior.
Unknown interface names are never automatically asserted to be physical
cables.

A test fixture validates `peer` as LAG via its SNMP ifType rather than its
generic interface name, ensures Ethernet type 6 is not filtered and preserves
the FDB edge's inferred `confirmed=false` and `src=['fdb']` metadata.
Automated CI and live visualization are independent gates; **no module
deployment or template import is implied by this source change**.

Before deployment on the production Zabbix instance, retain the frontend
backup, verify SHA of the exact candidate source, compare its rendered module
files with those installed, verify role visibility and the single SOA group
scope, and record counts of existing/inferred edges. A regression requires
restoring the prior frontend file(s); do not modify global template items as
part of this FDB classifier rollout. Perform a visual regression on SOA,
then Techsigno and Cardinale using the same common candidate. Canonical
private state/release/backlog files remain separately pending.

## SOA RC3 deployment checkpoint (2026-11-11, 15:03 Europe/Rome)

**Deployment completed on the shared Zabbix frontend**, by the operator
on `zabbix-server02`, for exactly two PHP paths:
`actions/NetworkTopologyData.php` and `topology/AutoFdbEdges.php`.
Source commit `e40fdad685a8bfc828b13d820afb79cded9d105a` from the
frozen `release/topology-soa-iftype-rc3-20261111` branch. Operator output:
`SOURCE_PREFLIGHT=PASS`, `BACKUP=PASS`,
`DEPLOYMENT_RC3=PASS`, both PHP lints PASS on the host and in the
`zabbix-web` container. Rollback snapshot:
`/opt/liberamente/topology-deploy-backups/soa-iftype-rc3-20261111-150320`.
No DB mutation or template import. Deployment is **not** a merge to
`production/v5.5.27` nor a new full-package release; the manifest version
has not been changed.

Read-only Zabbix post-install check: SOA group 205 has 94 hosts, 91
enabled. Six scoped FDB switches have 200 `net.if.type[ifType.N]` items,
all updated within 48h. Observed value breakdown: 18 LAG (161),
four transparent bridge (209), 170 Ethernet (6) and eight other types.
The new classifier has eligible telemetry. The effect on the rendered
topology **has not yet been validated**. Baseline visual screenshot from
before deployment: 91 displayed hosts, 36 edges and three ghost nodes.
To close acceptance, compare current group-scoped SOA visualization and
LLDP-Q/FDB edge categories, inspect PHP/container logs, verify no false
FDB access edges on LAG/bridge ports, and ensure LLDP/CDP/manual/Wi-Fi
and role/group isolation are preserved. No permission to claim those
visual and runtime checks PASS until independently observed.

To roll back, stop further edits, verify saved backup checksums, restore
the two original PHP files from the snapshot, lint them on the host and
in `zabbix-web`, then repeat the scoped topology inspection. The
release/RC2 branches remain frozen; feature PR #23 is still Draft.
Canonical private project state/release/backlog/checkpoint documents
require a separate update when accessible.

## SOA shared-port LLDP/CDP diagnostic (2026-11-11; read only)

A private post-RC3 GraphML export showed eight **reporter-port groups**
with multiple graph neighbors, including one previously omitted when
counting the seven original groups. Counts on the eight groups were
4, 3, 3, 2, 2, 2, 2 and 2 graph destinations. The GraphML export
contains source/target port labels and protocol annotations, but not
the full underlying LLDP/CDP observation timestamps, identity subtype,
provenance chain, confidence or proof of a direct physical cable.

A fresh, group-scoped and read-only API check found simultaneous recent
LLDP and CDP rows on all five shared ports of the core reporter:
two LLDP and two CDP observations on each of four ports except one port
with four LLDP and four CDP. Their last item updates were about 55 minutes
(LLDP) and 23 minutes (CDP) before the check, respectively. These values
disprove the blanket explanation that every graph adjacency is merely
from an obsolete historical item, but **do not prove independent reciprocal
cable confirmation**, because both protocols may be observed by the
same reporting device. On the core reporter's shared port with **three graph destinations**, only two CDP rows were fresh at the checkpoint; a third CDP item had last been updated about 22 hours previously and repeated the identity of another CDP row. Likewise, only two LLDP Port-ID items were updated within the hour on that port. This is a specific **freshness/reconciliation discrepancy to test**, not sufficient evidence to identify which graphical edge is wrong. The separate access-switch shared port had three
recent LLDP Port-ID rows, without matching CDP observations. Two related
router reporter ports each had one recent LLDP Port-ID value while the
graph rendered two adjacencies; the missing second Port-ID must be
investigated at the full row identity/quality layer.

Candidate explanations to test without presumption: unmonitored fan-out
switch or transparent bridge, LLDP/CDP pass-through, multiple devices
on a shared broadcast segment, LAG members and port-index/name mapping,
or incomplete/stale rows. Never synthesize an intermediate node, claim
one cable per learned neighbor, or promote an inferred FDB edge into
physical confirmation. Do not globally discard repeated LLDP/CDP rows:
they may encode real and useful multiple neighbors.

NEXT TEST GATE: obtain same-epoch complete LLDP rows (local port, neighbor
Chassis-ID with subtype, neighbor Port-ID with subtype, identity and
lastclock) plus CDP rows (reporter ifIndex, device identity, port and
lastclock), compare reciprocal observations when reporters are managed,
and group by reporting physical interface and observed neighbor identity.
A group with multiple destinations is a **shared-port evidence finding**
until stronger topology proof is available. Preserve per-edge provenance
and multiple parallel links, and keep group/proxy isolation. No code
change or Zabbix mutation follows from this diagnostic alone.

Private customer labels, host identifiers, MACs, IPs and the uploaded
GraphML MUST NOT be committed to this public repository.

## Historic LLDP/CDP item timestamp: synthetic characterization (2026-11-11)

The common `MetricExtractor::extract()` currently forwards LLDP/CDP
neighbor identity rows to `LldpEdgeBuilder::build()` with
`hostid`, `key_`, `lastvalue` and `src` only, **not** the
`lastclock`. In the main controller, clocks fetched from Zabbix history
are currently used for general per-host freshness but not as an
evidence-level maximum age on neighbor rows. Therefore a valid-looking,
non-empty, historic CDP neighbor identity can still be considered by
the builder if it survives the source-item query.

A new synthetic characterization test
`tests/LldpStaleNeighborCharacterizationTest.php` records both outcomes:
three **distinct** identities on one port, one of them 22+ hours old,
can generate three one-sided graph edges when all reach the builder;
two current identities plus an old **duplicate identity** still make
only two unique host-pair edges. A hypothetical upstream six-hour
prefilter illustrates how to retain both current identities, but is
not deployed and is **not yet an approved universal TTL**. It must be
evaluated against actual LLDP/CDP polling intervals, proxy delays,
discovery loss, existing monitored devices and stale historical
lastvalues before any production change.

A private SOA discrepancy is still open: a core port displayed three
graph destinations but had only two last-hour LLDP Port-ID and CDP
identity rows, with an additional historic duplicate CDP identity.
That observation **does not establish** the older item as the cause of
the third edge. A missing SysName, a different source, reconciliation,
duplicate keys or the current graph snapshot must be ruled out first.

Acceptance requirements before fixing: a replay with aligned
same-epoch `lastclock` for each identity and metadata row, a clear
missing-clock policy for older versions, separate TTL per protocol if
required, false-positive/false-negative counts from SOA plus Techsigno
and Cardinale, no lost verified trunk/parallel edges, stable group/proxy
scope, and an explicit rollback. The synthetic test uses no real
customer identifiers or network data.

## SOA shared core port: third LLDP-only edge verified (2026-11-11)

Read-only reconciliation of the privately supplied post-RC3 GraphML with
Zabbix item.get identified the three exported neighbor-pair edges for one
core switch local port. Two graph edges carry both LLDP and CDP source
labels; the third carries **LLDP only**. At the follow-up API sampling,
three distinct indexed `lldpRemPortId` observations were active and
about seven minutes old; the two active `cdpCacheDeviceId` records
were about 35 minutes old. An additional duplicate CDP identity was
disabled/unsupported and roughly 22 hours old. The LLDP-only third
adjacency is therefore **not explained by the historic CDP duplicate**.

This supersedes the earlier provisional suspicion that the three-edge
graph might be caused by an obsolete CDP row. The previous "two recent
LLDP" count was captured during a different time window; observations
and LLD membership evolve. Do not conclude that the third graph edge
is false or remove it using an arbitrarily short TTL.

The remaining open issue is **physical meaning**: multiple fresh
neighbors advertised on one reporting interface establish recorded
protocol adjacency, but do not prove three separate direct cables.
Possible transparent intermediates or pass-through arrangements require
independent evidence. Keep distinct host identities, original ports,
protocol source and confidence; never invent an unmanaged switch or
promote a shared-port observation to reciprocal physical confirmation.

Source-only synthetic characterization test and documentation changes
are on feature PR #23. Production SOA RC3 remains on the frozen
`e40fdad685a8bfc828b13d820afb79cded9d105a` two-file deployment;
there is no new LLDP patch or deployment in this checkpoint. Do not
commit the private GraphML, customer names, MACs or IP addresses.

## Group-specific exceptional behavior

After a common release is tested in all three lab groups, exceptional customer
cases may require **versioned, explicit group-scoped configurations or optional
adapters**, provided their API and graph semantics remain compatible with the
common core. Every exception must state scope, reason, precedence, tests,
observed vs inferred behavior, rollout and rollback. Do not implement a
separate long-lived fork per customer. Do not weaken physical evidence rules
globally to satisfy a single group's incomplete inventory.

## Authorized same-database pilot with controlled blast radius

A pilot can run in the live Zabbix installation and production DB. This
changes the meaning of 'staging' from a separate DB to a **restricted lab
scope**, not the need for backups and reversibility:

1. Confirm module visibility rules; recheck the assertion of no customer
   access, and save before screenshots only in private storage.
2. Pin repo/branch/commit of frontend module, templates, and discovery tools.
   Export/hash current template and inventory all inherited host/item links.
3. Take a verified production DB backup/recovery checkpoint and preserve
   on-disk frontend module backup; record the rollback owner and commands.
4. Pilot with a separately named template and **test host that does not
   inherit the original conflicting keys**. If direct common-template import
   would touch hundreds of hosts, stop and obtain separate explicit
   approval after the migration impact report.
5. Validate SOA for individual edge types, provenance, port data,
   inferred/verified separation, group/proxy visibility, item IDs, and LLD
   lost-resource lifecycle before moving to Techsigno/Cardinale.
6. Stop on a false physical edge, loss of source evidence, unreviewed key
   migration, user/proxy isolation leak or inability to roll back. Restore
   only the resource changed; do not presume re-import recovers item history.
7. Delete temporary pilot/diagnostic items and confirm unchanged inherited
   master/discovery state. Record a timestamped private checkpoint.

## Current known blockers / diagnostics

- An LLDP raw-walk probe with locally assigned subtype-7 Chassis-ID showed
  distinct identities within seconds at the raw SNMP source. This is not
  attributable solely to frontend Topology or Zabbix preprocessing; the
  source/agent mechanism remains unresolved. Do not merge distinct
  Chassis-IDs merely because Port-ID and SysName are equal.
- The prior aggregate read-only inventory showed 245 LLDP inherited rules.
  Recount with privileged access before changing any globally linked
  template.
- The diagnostic temporary item used for SOA data capture must be removed
  before closing the diagnostics checkpoint. The inherited master must retain
  its original history setting.
- The available API identity has limited `template.get` and `module.get`
  visibility. Do not bypass permissions, invent deployed SHAs or claim a
  pilot import until independently verified.
- The private canonical state, decision, Git/release and backlog files must
  be updated at their actual locations once accessible. This public design
  note does not replace them.

**Publication policy:** pre-release GitHub tag, merge to production branch,
template import and frontend-module rollout are separate controlled actions.
