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
