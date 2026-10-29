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
