# Cascaded Ethernet devices: verified-uplink FDB suppression (preview)

**Status:** opt-in pass-through refinement, not deployed. Review + replay required.
This change is on a feature branch based on the already validated
VoIP pass-through commit. No per-customer implementations or credentials.

## Topology classes remain distinct

- LLDP/CDP switch-to-switch: physical link evidence; two reporters mean
  *reciprocal* confirmation, not merely two port labels.
- LLDP/CDP switch-to-phone: independent upper-leg evidence.
- Phone-to-PC, phone-to-industrial-controller: inferred association
  (never assert a verified cable). Downstream phone port and endpoint
  physical port remain unknown, as do per-link metrics.
- FDB learned on another switch: possibly a normal transit copy,
  not another cable, and never a reason alone to assert a loop.
- Wi-Fi and manual edges: retain existing source/type and precedence.
- DHCP/IP changes: an identity concern handled by the separate tools
  reconciler; the topology engine consumes canonical Zabbix hostids.

## What was missing

The initial pass-through pilot admitted only a destination whose MAC
had exactly one FDB location in the entire selected network. In a
normal switch cascade, upstream switches also learn the endpoint MAC
on their inter-switch uplinks, so this global cardinality check could
reject a valid shared phone port even though there was one access
location.

## Added conservative check

A second (or later) FDB location may be ignored **only** when every
extra location belongs to a *different* FDB switch, and that precise
local port is proven to be an inter-switch uplink by:

1. A current LLDP/CDP edge between two monitored FDB source switches.
2. Both endpoint hostids in the reporter list and confirmed=true.
3. A known IF-MIB local ifIndex/ifName mapping or unambiguous exact
   interface-name match on both switches.
4. The two switches sharing one nonzero Zabbix proxy.
5. No other unexplained FDB learning for the same MAC.

The selected local phone port must never itself be a verified
inter-switch uplink. The original rules remain:

- An independently observed switch-to-phone LLDP/CDP edge on that port.
- LLDP Telephone + Bridge capability and a well-formed observed CDP
  Device-ID encoding resolving to one monitored phone.
- Exactly two distinct learned MACs on the phone's switch port:
  one for the phone and one for the proposed downstream host.
- Unique fresh ARP identity and host/proxy scope; no duplicate host IP.
- No existing link or manual nt:uplink for the downstream host.
- No fabricated output or input statistics, physical port, or signal.

Unmanaged intermediate switches, port loops, MAC flapping on one
switch, 3+ MACs on the phone port, and unconfirmed/stale LLDP evidence
remain ambiguous and excluded. No graph-completion heuristic.

## How to validate

Run complete PHP tests (new fixture PassThroughCascadeTest.php), UI
tests for inferred-edge styling, frontend bundle drift checks and all
existing CI. Obtain a **read-only replay** using a private scoped
snapshot and compare per-category aggregated counts:

- baseline original edges and existing pass-through proposals;
- number of additional candidates after filtering verified uplinks;
- rejections for insufficient FDB / ambiguous multi-MAC ports;
- preserved physical LLDP and parallel trunk members;
- unchanged Wi-Fi edges and zero cross-group/proxy additions.

Review every *new* candidate with private evidence before any
deployment. Public fixtures use only RFC 5737 example addresses and
synthetic MAC values. Never commit real host names, client identifiers,
MACs, IPs, or Zabbix tokens.

## Rollback

No production change is performed by this PR. To revert a staged
deployment, reinstall the previously backed-up module and restart the
frontend web container, following the validated release procedure.
Do not merge to the release branch or alter active feature tags in
response to this preview.
