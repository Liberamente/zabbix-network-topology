# Native automatic endpoint associations — Network Topology

This feature is part of the **Network Topology PHP module and its normal
bundled frontend**, based on v5.5.27. No collector service, scheduled
script, private JSON file or shell utility is required in customer sites.

## Three complementary topology evidence sources

1. **LLDP/CDP (verified cable)** — the existing module remains unchanged:
   reciprocal discovery, physical switch ports, parallel members/trunks
   (`×2` compact or two separate arcs), live port metrics if present.
2. **Wireless association (MikroTik SNMP)** — optional existing item
   `nt.wifi.clients` on the AP; host IP-to-MAC from Zabbix `nt.arp`,
   inventory MAC or `nt:lldp` alias. Creates a cyan dashed AP-to-client
   association with RSSI in dBm, not an Ethernet cable. A future adapter
   can interpret other vendors' client tables.
3. **MAC learned on an access port (ARP + FDB)** — optional Zabbix items:
   - gateway/router: `nt.arp`, standard `ipNetToMediaPhysAddress`
     `walk[1.3.6.1.2.1.4.22.1.2]`;
   - switch: `nt.fdb.dot1q` / `nt.fdb.dot1d` forwarding tables,
     `nt.fdb.baseport` (BRIDGE-MIB) and `nt.fdb.ifname` (IF-MIB);
   - existing `lldpRemSysCapEnabled` discovery, for distinguishing
     network uplinks from downstream access ports.
   - Ruckus ZoneDirector AP identity items `nt.wifi.zd.ap.1` (AP MAC),
     `nt.wifi.zd.ap.3` (active state), and `nt.wifi.zd.ap.10` (AP IPv4), when
     collected on a visible host in the same proxy. These identify active AP
     endpoints for the existing FDB path; they do not create Wi-Fi client links.

   The module uses the **already implemented** `FdbParser`,
   `FdbResolver`, `LldpEdgeBuilder` and `ArpParser`. The existing
   interactive MAC finder still works unchanged. Automatic associations
   are amber/dotted and explicitly **inferred**, never confirmed cables.

These are all ordinary **Zabbix SNMP walk or discovery items**. Customers
may use Zabbix native templates to collect them; no standalone
application, agent executable, cronjob or SNMP request from the frontend
is added. Without the optional items, the original topology remains.

## Scope and safety

- Runs only when **exactly one host group** is selected, for the hosts
  visible to the current Zabbix user. No hard-coded customer group IDs.
- ARP reporter, access switch, and endpoint must share the same nonzero
  Zabbix proxy. Different customer proxies are never bridged.
- Data must be recent (ARP/FDB no older than 15 minutes; Wi-Fi no older
  than three minutes; LLDP-capability discovery no older than four hours).
  Unsupported items, duplicate MAC owners, ambiguous port evidence,
  missing switch LLDP classification or unrecognized MACs fail closed.
- HPE/Aruba `TrkN` and other logical LAG/port-channel ifNames are
  excluded as access ports even when LLDP physical-port labels differ
  from the switch FDB's aggregate ifName.
- Wi-Fi precedes FDB so a wireless client is never mislabeled as a
  physical Ethernet cable. LLDP/CDP, explicitly declared uplinks and
  host/parent relationships take precedence over both inferences.
- When multiple MACs, monitored or not, are learned on one switch access port,
  mark every association **shared port**, because an intervening phone,
  bridge, hypervisor or unmanaged switch might exist.
- An FDB match does not establish individual link traffic, port state,
  errors or direct cabling. Do not borrow switch-port or host aggregate
  counters; amber never means UP/healthy.
- The map's LLDP/CDP physical-change baseline and Root Cause / What-if
  hard reachability exclude inferred FDB evidence. It creates **no
  Zabbix trigger dependencies** or write requests.

## Portable frontend test

Use a disposable Git checkout of `feature/native-auto-links-all-groups`
and the standard Zabbix module release/build workflow. Run:

```sh
php -l topology/AutoFdbEdges.php
php -l topology/AutoWifiEdges.php
php -l actions/NetworkTopologyData.php
php tests/AutoFdbEdgesTest.php
php tests/AutoWifiEdgesTest.php
npm ci
npm run build
npm run ci:test
npm run ci:frontend
npm run ci:eslint
npm run ci:package
```

Tests use documentation-only IP addresses, anonymous switch names and
locally administered synthetic MACs. Generated customer data must never
be committed to the public repository.

**Do not deploy before all tests and a frontend backup.** The bundled
`assets/js/dist/nt-bundle.js` must be rebuilt from this branch's JS
source before loading the updated module in a browser. A checkout of the
previous `production/v5.5.27` release and restore of its installed
module directory is the rollback.

## Vendor coverage and known limits

- Standard FDB/ARP works for vendors that expose BRIDGE-MIB,
  Q-BRIDGE-MIB and IF-MIB via Zabbix items. It is an observation of MAC
  forwarding, not proof of the physical topology behind a downstream
  device.
- Current Wi-Fi edge parser interprets **MikroTik**
  `1.3.6.1.4.1.14988.1.1.1.2.1.3` registration RSSI entries.
  **RUCKUS / SmartZone is not yet supported** by this vendor-specific
  reader. Ruckus ZoneDirector AP MAC/IP rows can identify AP nodes for FDB
  inference, but Ruckus wireless-client associations and RSSI are not
  interpreted here.
- ARP-only hosts lacking any MAC-to-port evidence stay disconnected.
  Multiple active ZoneDirector AP MACs mapped to one IP remain ambiguous and
  are skipped; inactive AP rows do not create endpoint identities.
  Absence of discovery must never be treated as a healthy or direct link.

## RC2 incomplete-capability regression checkpoint

See [2026-10-31 checkpoint](RC2-FDB-CHECKPOINT-20261031.md) for the five-switch
synthetic regression, per-port LLDP/CDP transit correction, offline exclusion
counters, exact code/rollback commits and remaining release gates. This is a
source-only correction; it has not been deployed or validated on customer data.
