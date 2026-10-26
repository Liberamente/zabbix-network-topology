# Optional Ruckus ZoneDirector Wi-Fi source (Zabbix 7.0)

## Scope and deployment status

This is an **optional data-source template**, not a Cardinale-specific topology.
It is not deployed automatically and must be linked to a *controller* host with a working
SNMP interface. Keep controller SNMP credentials in Zabbix and out of Git.
Only monitored, authorized hosts from the topology-selected group and proxy
may become endpoints. Neither collector nor renderer creates new hosts.

This implementation reads five raw SNMP column walks separately to match
`ZoneDirectorWifiSource::associations()` and the `AutoWifiEdges::appendController()`
integration. There are no generated radio measurements, inferred wires or
automatic cross-proxy links.

## Setup / pilot

1. Back up the existing topology module and export the controller host's templates.
2. Import `templates/nt_wifi_ruckus_zonedirector_template.yaml` in Zabbix 7.0.
3. Link it only to a selected ZoneDirector controller host in a test group.
   Ensure the host uses the intended proxy and SNMP interface. No new credentials
   or client identifying data should be committed to a public repository.
4. Check all five items: `nt.wifi.zd.ap.1`, `nt.wifi.zd.ap.3`,
   `nt.wifi.zd.ap.10`, `nt.wifi.zd.client.2`, `nt.wifi.zd.client.8`.
   All must have a successful, recent lastvalue (<180s old), sampled within 120s.
5. Verify complete MAC-index joins, currently active APs (status = 1),
   and unique AP-IP/host and client-MAC/host resolution.
6. Validate the pilot in a single selected group and through its proxy.
   Keep MikroTik, LLDP/CDP, FDB, manual uplink and multi-port trunk
   regression tests green. Never infer AP-to-switch cables from Wi-Fi data.
7. If the pilot fails, unlink this template and roll back only the separately
   tested module release. No database or customer-data edits are needed.

## Known limits

- Existing `NetworkTopologyData` auto-access-link path requires one selected
  group (`count($groupids) === 1`); **this does not mean one group is hard-coded**.
  Multi-group views need a separate scope-safe design and tests.
- The controller's client IP is only an additional check; MAC identity must be
  uniquely corroborated via scoped ARP, inventory or existing allowed tags.
  Missing MAC evidence means no arc, even if IP matches.
- APs are mapped via MAC and unique active IP; stale/duplicate AP addresses
  produce no edges. Client Wi-Fi links are reported associations, never
  confirmed physical copper connections.
- Host discovery and endpoint monitoring are independent from this template.
  Unmonitored Wi-Fi clients are not silently added to Zabbix.
- Vendor-specific radio counters are deliberately not exposed as dBm until
  semantics have been verified for that controller version.
- Source parser assumes net-snmp-style walk text and must be integration-tested
  against Zabbix's actual SNMP_AGENT walk item lastvalues before release.
