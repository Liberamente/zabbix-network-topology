# Network Topology portable beta 1 — candidate checklist

Status: **RELEASE CANDIDATE ONLY — NOT APPROVED / NOT DEPLOYED**.
Candidate code baseline: `9cf2c815448595a0c0633d140c7832a9f049e45b` (integrated multi-source topology and conservative cascade pilot).
Branch: `release/topology-beta1-rc1-20261029`. Upstream base: `production/v5.5.27`.
The version displayed in the module manifest remains **5.5.27**; this beta is identified by its exact commit and, after acceptance only, an immutable pre-release tag.

## Intended beta scope

- A **single portable product**, not a customer-specific fork. No customer names, networks, tokens or live inventory in this repository.
- Existing LLDP/CDP edges and user/manual `nt:lldp`, `nt:uplink` overrides preserved.
- FDB/ARP access associations are **inferred**, never automatically promoted to verified physical cables. Maintain freshness, unique attribution, group/proxy scopes and ambiguous-blocked diagnostics.
- Wi-Fi associations are radio edges; report signal only when measured by the source.
- Parallel physical links and trunk members remain individually attributable; never invent logical LAG membership or per-member metrics.
- Ethernet phone / downstream endpoint pass-through remains inferred and uses explicit selected-group / same-proxy opt-in, not an unscoped global default.
- No automatic DHCP host rename/update/merge/delete; identity reconciliation tools remain a separate read-only preview.
- Hide isolated UI and advanced STP loop diagnostics are **not represented as complete features**.

## Explicit exclusions from beta 1

- The separate `fix/lldp-multiport-stable-identity-20261029` template patch / Topology PR #10:
  its single-to-multiple Port-ID transition can change stable LLD keys and must first pass migration/retention tests. Do not import this template automatically into a running Zabbix system.
- Tools PR #10 is a separate tooling candidate, not part of this frontend beta; do not deploy read-only previews as background actions or enable mutating identity workflows.
- No guarantee that multi-advertisement LLDP on the same local port is fully retained by an already customised Zabbix LLD rule.
- Verified trunk adapter evidence and visualisations are subject to their own source/feature gate; do not treat an offline preview as a deployed runtime feature.

## Preflight gates (all mandatory)

1. Capture deployment inventory, image IDs, actual frontend path/mount, installed manifest and bundle hashes. Confirm working deployment is not silently overwritten. Read-only first.
2. Confirm GitHub candidate HEAD and full CI after this RC metadata commit: PHP lint/tests, JS frontend, template-logic, XSS/shellcheck, bundle drift, packaging. No failed or skipped mandatory checks.
3. Confirm Zabbix frontend 7.0 version, module permissions, PHP opcache/reload path, and module administration permissions.
4. Collect private baseline for the selected authorized group and proxy: visible hosts, nodes, LLDP/CDP edges by device pair and per-port, inferred FDB/ARP, Wi-Fi, manual links, trunk members, ambiguous and isolated counts, security scope. Avoid client labels or addresses in public artifacts.
5. Verify manually that each critical physical switch-to-switch LLDP link remains one unique physical pair, existing manual paths retain precedence, no new LLDP ghost loop appears, and all known radio links remain represented.
6. Create a timestamped, checksum-verified backup of the **actual mounted module directory**, frontend mount definition and rollout configuration on storage outside the served web root. Protect backup from web access; no secrets in archive.
7. Dry-run candidate packaging/install against a disposable staging copy of the container or isolated frontend, not the live module. Confirm frontend starts and UI outputs unchanged at the critical scopes.
8. Capture exact deploy and rollback commands based on detected mount and handler; explicitly authorize a maintenance window, on-call contact and rollback triggers.

## Required pre/post beta comparison

- Equivalent host/group/proxy selection and access permissions; no cross-group leakage.
- No missing verified LLDP/CDP edge; no additional physical edge inferred from FDB/ARP alone.
- No duplicate visual representation of one reciprocal physical cable.
- Preserve existing Wi-Fi edges, parallel members, manual overrides, active problems and ping metrics where source data exists.
- Compare feature-gated passthrough, technical map labels, nodes and alerts; inspect the group after a full data refresh.
- Database writes, discovery changes and Zabbix host mutations remain outside this frontend rollout.
- Roll back **immediately** for wrong group/proxy visibility, missing verified links, duplicate physical links, blocked page/500 errors, unexpected write activity, or severe performance regressions.

## Rollback

Do not rely on `git reset` inside a container or on a deployment script that deletes the existing module. Restore the verified saved directory to its detected bind mount / named volume, reapply recorded ownership and permissions, reload the appropriate frontend PHP/web handler or restart the single Zabbix web container if justified. Confirm manifest, bundle checksum, frontend load and previous topology baseline. Keep the beta candidate separately.

## Evidence and status

- Candidate source reviewed against production ref; existing cascade branch CI was successful on `9cf2c815448595a0c0633d140c7832a9f049e45b`.
- The current version on the customer's frontend and the correctness of the target mount: **NOT YET VERIFIED**.
- Staging copy, package checksum, deployment/rollback dry run, visual pre/post: **NOT YET EXECUTED**.
- GitHub draft PR / candidate branch creation are not a release, and do not indicate installation.
- Publication of an immutable prerelease tag and client deployment require a separately recorded GO after checks.

Only synthetic fixtures may be committed. Keep all customer snapshots and diagnostics in private access-controlled storage.

---

## ADR-014 operational rule: Git as the deployment source

**ACCEPTED operator preference, 2026-10-29.** All future new customer installs and updates to proxy-side tooling are **Git-driven**, using \`git@github.com:Liberamente/zabbix-tools.git\` (SSH), with a fixed commit SHA for each rollout. Frontend topology updates likewise derive from \`git@github.com:Liberamente/zabbix-network-topology.git\` on the Zabbix frontend **host**.

- \`zabbix-tools\` resides on the Zabbix **proxies** (where discovery/collector scripts run). Place source in an access-controlled Git working copy and installed copies in \`/opt/zabbix-tools/releases/<full-sha>/\`; use controlled symlinks for executable entrypoints. Preserve \`/etc/zabbix\` runtime configuration, credentials, and permissions outside Git. Roll back by restoring the previous release symlinks, never changing Zabbix server items or hosts as a side effect.
- \`network_topology\` PHP/JS resides in the **central frontend**, not on proxies. For the Docker web frontend, maintain the Git checkout outside its published webroot; identify the actual host-side bind source by inspecting \`zabbix-web\` before touching files. Build and stage a fixed Git commit outside webroot, verify bundle/tests, back up the mounted module outside webroot, then perform a separately approved cutover with a tested rollback. **Never clone a Git working tree under \`/usr/share/zabbix/modules\`.** Never point a symlink inside a Docker bind mount to a host-only path which the container cannot resolve.
- Git commit SHA and deploy journal (host, timestamp, backup path, module bundle hash, pre/post counts, exact scripts) are authoritative. A standalone ZIP is a CI validation artifact, **not the standard installation transport** and not a replacement for the corresponding pinned Git commit.
- No \`git pull\` of a moving branch for running services. Fetch, verify and deploy an immutable known SHA. No modification of customer environments without a recorded GO.
- On the currently monitored Docker frontend, the operator supplied 5 installed-file SHA-256 fingerprints. All 5 were found equal to the known public \`feature/voip-passthrough-evidence-20261027\` commit \`2090203f1b4382ef5597eeb6e4d42e43f483ba75\`. The downstream cascade commit \`9cf2c815448595a0c0633d140c7832a9f049e45b\` matched 4/5; the difference was \`topology/PassThroughEvidence.php\`. This **is not a proof of complete tree identity**. The exact host bind source, backups, and rollback dry-run are not yet confirmed.

### Mandatory Git / mounted frontend preflight (read-only)

\`\`\`bash
docker inspect zabbix-web --format '{{range .Mounts}}{{if eq .Destination "/usr/share/zabbix/modules"}}SOURCE={{.Source}} TYPE={{.Type}} RW={{.RW}}{{end}}{{end}}'
docker inspect zabbix-web --format 'IMAGE_ID={{.Image}} RUNNING={{.State.Running}}'
GIT_SSH_COMMAND='ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=8' \
  git ls-remote git@github.com:Liberamente/zabbix-network-topology.git \
  refs/heads/release/topology-beta1-rc1-20261029
\`\`\`

Run these on the **Docker host**, not on a proxy; if Git SSH auth fails, stop and set up verified SSH keys / known_hosts, without exposing private keys or tokens. Do not launch an installer after these commands; first establish the backup/rollback procedure against the discovered bind source.
