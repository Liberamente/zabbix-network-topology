# Private pass-through replay (read-only)

This pilot validates the portable \`PassThroughEvidence::propose()\` engine
without installing the module or changing Zabbix. The runtime gate is
OFF by default until one existing monitored FDB switch in the selected
group has a matching `nt:pass_through` host tag. Evidence from CDP/LLDP/ARP/FDB remains **inferred**, never a verified
cable or independently measured per-link traffic.

## Prerequisites

- PHP 8.2+ in a clean development worktree containing the feature.
- One visible, selected Zabbix host group. The selected graph must show
  **all monitored hosts in that group**; the replay will stop on scope drift.
- A Zabbix API token with **read-only** access to the selected host group,
  hosts/interfaces/tags/inventory and item values. Do not write this token
  into a command, file, repository, screenshot or support chat.
- A secure HTTPS \`api_jsonrpc.php\` URL. HTTP is accepted only on the local
  loopback address.
- A private working directory **outside the Git repository**, owned by the
  operator, with mode \`0700\`.

## 1. Export backend graph from the authenticated topology browser

Open the selected group topology, then run in the browser console:

\`\`\`javascript
(() => {
  const d = window._ntLastData || {};
  const nodes = Array.isArray(d.nodes) ? d.nodes : [];
  const edges = Array.isArray(d.edges) ? d.edges : [];

  if (!nodes.length || !edges.length ||
      nodes.some(n => !/^\d+$/.test(String(n.id)))) {
    console.log("STOP: incomplete backend graph");
    return;
  }

  const allowed = new Set(nodes.map(n => String(n.id)));
  if (edges.some(e =>
      !allowed.has(String(e.from)) || !allowed.has(String(e.to)))) {
    console.log("STOP: graph contains a non-visible edge endpoint");
    return;
  }

  const data = {
    observed_at: Math.floor(Date.now() / 1000),
    nodes: nodes.map(n => ({id: String(n.id)})),
    edges: edges.map(e => ({
      id: e.id, from: e.from, to: e.to, _type: e._type,
      src: e.src, match: e.match, confirmed: e.confirmed,
      ports: e.ports, port_idx: e.port_idx
    }))
  };
  const blob = new Blob([JSON.stringify(data)], {type: "application/json"});
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement("a");
  anchor.href = url;
  anchor.download = "nt-pass-through-graph-PRIVATE.json";
  anchor.click();
  URL.revokeObjectURL(url);
  console.log({
    exported_host_count: data.nodes.length,
    exported_edge_count: data.edges.length
  });
})();
\`\`\`

**Do not upload the graph to a public issue or PR.** The export contains
host IDs and switch-port labels. It does not contain SNMP credentials or
API tokens, but is still confidential network metadata.

## 2. Transfer privately to the validation server

Use your normal secured SSH/SCP route to place the exported graph under
a directory such as \`/opt/private-topology-replay\`, not in the worktree.
For example (use your own trusted SSH alias):

\`\`\`sh
sudo install -d -m 0700 /opt/private-topology-replay
# transfer the exported file to the private directory using scp/SFTP
sudo chmod 0600 /opt/private-topology-replay/graph.json
\`\`\`

Run all following commands from the **development** worktree. Never copy
the replay files into a public repository.

## 3. Query Zabbix and execute the actual PHP proposal

\`\`\`sh
# Your API token must already be available as an environment variable.
# An interactive input can avoid shell-history disclosure:
read -r -s -p "Zabbix read-only API token: " ZABBIX_API_TOKEN
printf "\n"
export ZABBIX_API_TOKEN

php tools/pass-through-replay.php \
  --graph /opt/private-topology-replay/graph.json \
  --group "Example/DEV" \
  --api-url "https://zabbix.example/api_jsonrpc.php"

unset ZABBIX_API_TOKEN
\`\`\`

Use your actual **single group** name and API URL locally. The token is
not emitted, even on API error. The API is queried read-only in the
scope of that group; no other groups or proxies are searched.

The tool checks graph age (max 10 minutes), exact visible host equality,
freshness of all source items, unique ARP identities, two learned MACs on
the same switch port, bridge+telephone capabilities and CDP identity,
same nonzero proxy, existing switch-to-phone LLDP/CDP edge on that port,
manual overrides and already-linked endpoints. It outputs only aggregate
counts and an anonymous host-set fingerprint.

Expected report keys include \`hosts\`, \`original_edges\`,
\`isolated_before\`, \`new_inferred_candidates\`,
\`edges_theoretical_after\`, \`isolated_theoretical_after\`,
\`zabbix_mutations=0\` and \`module_deployed=false\`.

Theoretical counts do NOT mean that anything was deployed. They are
valid only for the graph and item timestamps acquired during this run.

## 4. Quick opt-in per group (staging only)

After installing a validated common module build once on the Zabbix server,
enable another customer group **without editing code or discovery scripts**:

| Zabbix host setting | Value |
| --- | --- |
| Host | One monitored FDB switch in the chosen group |
| Tag | `nt:pass_through` |
| Tag value | The EXACT numeric group ID (example: `600`) |

Use Data collection > Hosts > selected FDB switch > Tags. No endpoint,
phone, SNMP template or per-switch configuration is necessary. One
tag enables all FDB switches and downstream hosts **on that switch's
same nonzero proxy and in the selected group**. For a group with two
proxies, add one tag to a switch on each desired proxy.

Matching is fail-closed: an untagged group, a different group ID,
multiple selected groups, host/hops views, an unmonitored endpoint tag
or a switch with proxy=0 cannot enable this feature. A host that is
in several groups does not inherit an opt-in to other groups because
the tag value must equal the selected group ID, not just `1`.

The scope gate uses tags already returned by the existing host.get;
it issues NO extra API request for disabled groups, and the extra CDP
item read occurs only for an explicitly enabled group/proxy.

To turn it off, remove the matching switch tag and allow the current
60-second Zabbix topology data cache to expire. Tag removal does not
require a container restart. Production tag changes and deployment
still require explicit authorization and a validated rollback plan.

The private replay is intentionally independent of this opt-in tag:
it remains read-only, even for a disabled group. Its `diagnostics`
field includes aggregate reason counts, with no host IDs, IPs, MACs,
client names or switch ports.
## 5. Gates and cleanup

\`\`\`sh
php -l tools/pass-through-replay.php
php tests/PassThroughEvidenceTest.php
php tests/PassThroughReplayCliTest.php
php tests/PassThroughHttpContractTest.php
php tests/PassThroughGroupScopeTest.php
\`\`\`

Review only aggregate console outputs. Securely delete the graph export
from the client and private server when the test is complete, subject to
your organization's evidence-retention policy. Do not add production
host tags or deploy the module without explicit authorization.

## Rollback

This tool is read-only. No production rollback is needed after a replay.
To discard the experiment, delete the private input and keep the production
module untouched. Code rollback: return the **development** worktree to a
reviewed baseline commit in a fresh worktree; do not reset or deploy
over the production branch.
