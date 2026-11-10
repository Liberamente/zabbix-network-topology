# Liberamente Network Topology 5.5.27-p4 RC1 — Pre-release

**This is a portable release candidate, not an automatic deployment.** The Zabbix module manifest remains 5.5.27. Do not install globally without exporting the existing templates and testing rollback.

## English

**Consolidated existing work rather than replacing it:**
- Portability-safe FDB protection for Comware `Bridge-Aggregation` links. FDB/ARP edges remain inferred, not physical evidence.
- Optional, unambiguous LLDP Chassis-ID aliases, scoped to the same proxy and visible hosts; no inferred physical links from MAC learning alone.
- LLDP template retains the already-deployed `0.LocalPort.StableId` scheme and existing six `{#SNMPINDEX}` item keys/UUIDs, rather than applying the separate `{#NT_NBR}` migration.
- Distinct Port-ID LLDP observations sharing a Chassis-ID and local port are retained. The module does **not** assert extra direct physical cables from multiple observations.
- Two optional read-only evidence collector templates are bundled separately, alongside the official LLDP template. Never link both the legacy and evidence local-port templates to the same host: their item keys overlap.
- Public synthetic tests and all GitHub CI checks are mandatory for this exact commit.

**Upgrade warning:** when a device changes between one and multiple Port-ID observations for a single Chassis-ID, the affected discovered keys can change. Take an export and observe the resulting item history before a global import. Template-group UUIDs may differ across Zabbix instances, even under the same group name. Check the import preview. No Zabbix host, template or frontend is modified by publishing this RC.

## Italiano

**Consolidamento del lavoro già realizzato, senza sostituirlo:**
- Protezione FDB per le porte aggregate Comware `Bridge-Aggregation`. Gli archi FDB/ARP rimangono inferiti, non fisicamente verificati.
- Risoluzione LLDP tramite Chassis-ID dichiarato e univoco, limitata a host visibili e allo stesso proxy; nessun collegamento fisico certo dal solo apprendimento MAC.
- Il template LLDP conserva lo schema già installato `0.LocalPort.StableId` e le sei chiavi/UUID `{#SNMPINDEX}`, evitando la migrazione separata a `{#NT_NBR}`.
- Conservazione delle osservazioni LLDP con diversi Port-ID sullo stesso chassis/porta locale, senza inventare cavi fisici aggiuntivi.
- Due template aggiuntivi di sola raccolta evidenze, distribuiti separatamente dal modulo web. Non collegare sullo stesso host il template locale legacy e quello di evidenza: le chiavi item coincidono.
- Fixture sintetiche pubbliche e controlli GitHub obbligatori sul commit effettivamente pubblicato.

**Attenzione all'aggiornamento:** la transizione fra uno e più Port-ID per lo stesso Chassis-ID può far cambiare le chiavi degli item scoperti. Esportare prima la configurazione esistente e verificare l'anteprima d'importazione, inclusi gli UUID dei gruppi template. La pubblicazione della RC **non aggiorna** Zabbix e non costituisce un collaudo finale in tutti gli ambienti.

## Files / File allegati

- `network_topology-liberamente-v5.5.27-p4-rc1.zip`: frontend web module
- `network_topology-liberamente-v5.5.27-p4-rc1.zip.sha256`: integrity checksum
- `network_topology-templates-liberamente-v5.5.27-p4-rc1.zip`: official LLDP plus two evidence-only templates and notes
- `network_topology-templates-liberamente-v5.5.27-p4-rc1.zip.sha256`: integrity checksum

Rollback: restore the backed-up PHP/module files and previously exported Zabbix templates after checking the deployed scope. Re-importing a template does not automatically restore historical item values. See `CONSOLIDATED-RELEASE-CANDIDATE.md` in the source branch for the complete gate.
