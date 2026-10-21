# Native ARP + FDB endpoint associations (optional)

This feature lives **inside Network Topology**. It does not require an
out-of-band collector, custom proxy script, JSON import, scheduled task,
new daemon, direct SNMP from the web frontend or Zabbix writes.

## What it resolves

The existing LLDP/CDP builder already knows physical switch-to-switch cables,
including multiple members of an LAG/trunk. It cannot attach monitored devices
that do not report LLDP/CDP, even though the switch knows their MAC addresses.

With the following **ordinary Zabbix items already collected** on network
devices, the frontend can infer where a MAC was last learned:

| Item key | Device | Standard SNMP OID |
|---|---|---|
| `nt.arp` | Layer-3 gateway/router | `1.3.6.1.2.1.4.22.1.2` (IPv4 ARP / `ipNetToMediaPhysAddress`) |
| `nt.fdb.dot1q` | Switch | `1.3.6.1.2.1.17.7.1.2.2.1.2` (Q-BRIDGE-MIB VLAN/MAC → bridge port) |
| `nt.fdb.baseport` | Switch | `1.3.6.1.2.1.17.1.4.1.2` (BRIDGE-MIB bridge port → ifIndex) |
| `nt.fdb.ifname` | Switch | `1.3.6.1.2.1.31.1.1.1.1` (IF-MIB ifIndex → ifName) |

Use Zabbix native SNMP walk items, not custom scripts. These keys are
**optional**: without them the module continues with LLDP/CDP exactly as
before. The key names are currently the existing pilot convention; any
upstream proposal should document or template them rather than rely on an
undocumented customer-specific setup.

## Mapping and safety rules

```text
Zabbix monitored host IPv4
        |
       ARP  (IPv4 -> unique MAC)
        |
       FDB  (MAC -> unique switch physical access port)
        |
  "MAC LEARNED ON SWITCH PORT"  [INFERRED, not a cable claim]
```

The matcher only adds an edge for an **otherwise unconnected host**; existing
LLDP/CDP, declared uplink and hosting/parent links win and are preserved.
It requires an exact selected hostgroup shared by the ARP source, FDB
switch and destination host. An IP that maps to multiple visible Zabbix
hosts, a MAC assigned to multiple monitored hosts, a MAC seen on multiple
physical ports, a trunk/LAG/VLAN interface, missing bridge-port mappings,
untrusted/malformed sample or age >900 seconds never produces a new edge.
The ARP/FDB dynamic samples must be within 600 seconds of one another.

The new edge is **amber and dotted**, with source `ARP+FDB`. Hovering or
clicking shows the observed switch and learned port. No traffic, peer port,
availability or LLDP confirmation is invented. If several MACs map to the
same access port, every association is flagged **shared port**: an IP phone
with passthrough, a bridge or a downstream unmanaged switch may exist.

These associations are not added to the module's LLDP topology-change
notification baseline: MAC learning is dynamic and is not proof a physical
cable was moved. This feature never creates Zabbix trigger dependencies.

## Initial validation

Run in a disposable checkout of
`feature/native-arp-fdb-topology`, without replacing the installed module:

```sh
php -l topology/FdbEdgeBuilder.php
php -l actions/NetworkTopologyData.php
php tests/FdbEdgeBuilderTest.php
npm ci
npm run build
npm run ci:test
npm run ci:frontend
npm run ci:eslint
```

The test uses fictional RFC 5737 IPs, synthetic MACs, two devices sharing
an access port, one excluded remote tenant, an already-present LLDP cable
and one uplink trunk. It also checks ambiguous, stale, mismatched, or
partially missing evidence. Do not commit private raw ARP/FDB/LLDP samples.

After the tests pass, deploy the main module ONLY to an explicitly selected
frontend after backup. Its normal Zabbix API is read-only; the existing
proxy and Zabbix monitoring remain untouched. Restoring the previous
`network_topology` module build rolls back the feature.

## Current limits

- Only **IPv4 ARP** in the standard `Hex-STRING` form and **Q-BRIDGE-MIB
  dot1q** forwarding tables; no inference from IPv6 NDP or dot1d-only FDB.
- Requires an observed IP→MAC→access-port path; the MAC alone does not
  identify which Zabbix IP host owns it.
- Virtual-machine or downstream-switch MACs may also be learned on a
  physical access port. Such an edge is **inferred attachment**, not
  a confirmed direct physical cable. The UI states this explicitly.
- Other topology mechanisms continue unchanged.
