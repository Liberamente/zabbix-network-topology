<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 PlaNet Fox / Alexander Fox
declare(strict_types = 1);

namespace Modules\NetworkTopology\Actions;

use API;
use Modules\NetworkTopology\Topology\ServiceTree;

/**
 * NetworkTopologyServices
 *
 * Liefert den Zabbix-Services-Baum (Business Services) als gerichteten Graphen
 * fuer den "Dependencies"-Tab: ein Knoten je Service, Kanten Eltern→Kind
 * ("haengt ab von"). Read-only, keine Parameter.
 *
 * Datenquelle ist Zabbix' EIGENER Services-Baum (API::Service()->get mit
 * selectChildren). Dessen Permissions — die Service-Rolle des Users — greifen
 * automatisch: ein User ohne Service-Zugriff bekommt einen leeren Baum, und das
 * Frontend zeigt dann den Leer-Zustand. Deshalb reicht die normale Lese-Stufe
 * (>= ZABBIX_USER); eine eigene Rechtepruefung waere doppelt und koennte nur
 * strenger als Zabbix selbst sein.
 *
 * Request:  (keine Parameter)
 * Response: { nodes: [...], edges: [...], api_version, generated_at }
 */
class NetworkTopologyServices extends NetworkTopologyController {

    /** getSli laeuft pro SLA — Obergrenze gegen Fan-out bei vielen SLAs. */
    private const MAX_SLAS = 200;

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        if (!$this->requireAjax()) return false;
        // Keine Pflichtparameter — eine leere Regelmenge validiert immer, hält
        // aber den einheitlichen validateInput()-Pfad (konsistent zu den
        // anderen Read-Actions).
        $ret = $this->validateInput([]);
        if (!$ret) {
            $this->jsonResponse(['error' => 'Invalid input']);
        }
        return $ret;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
    }

    protected function doAction(): void {
        if (!$this->throttle('services')) return;

        $services = API::Service()->get([
            'output'              => ['serviceid', 'name', 'status'],
            'selectChildren'      => ['serviceid'],
            'selectProblemEvents' => ['eventid'],
            'selectTags'          => ['tag', 'value'],
        ]);
        if (!is_array($services)) {
            $services = [];
        }

        $graph = ServiceTree::build($services);
        $graph['nodes'] = ServiceTree::mergeExtras(
            $graph['nodes'],
            $this->loadSlaMap(),
            $this->loadWebMap($services)
        );

        $this->jsonResponse([
            'nodes'        => $graph['nodes'],
            'edges'        => $graph['edges'],
            'api_version'  => self::API_VERSION,
            'generated_at' => time(),
        ]);
    }

    /**
     * SLA-% je Service (serviceid => {name, slo, sli}).
     *
     * Kein Tag-Matching von Hand: sla.getSli loest selbst auf, welche Services
     * eine SLA abdeckt, und liefert serviceids + sli (juengste Periode). Faellt
     * leer aus, wenn es keine SLAs gibt oder die Service-Rolle sie ausblendet.
     * In try/catch gekapselt, damit ein SLA-Fehler nie den Baum verhindert.
     */
    private function loadSlaMap(): array {
        $map = [];
        try {
            $slas = API::Sla()->get(['output' => ['slaid', 'name', 'slo']]);
            // getSli ist ein API-Call PRO SLA — die Zahl deckeln, damit eine
            // Instanz mit sehr vielen SLAs den Endpoint nicht in einen Fan-out
            // aus hunderten Round-Trips treibt (throttle begrenzt die Frequenz,
            // nicht die Arbeit je Aufruf).
            if (count($slas) > self::MAX_SLAS) {
                $slas = array_slice($slas, 0, self::MAX_SLAS);
            }
            foreach ($slas as $sla) {
                $sli = API::Sla()->getSli(['slaid' => $sla['slaid'], 'periods' => 1]);
                $sids = $sli['serviceids'] ?? [];
                $row  = $sli['sli'][0] ?? [];     // Periode 0 = die juengste
                foreach ($sids as $idx => $sid) {
                    $v = $row[$idx]['sli'] ?? null;
                    if ($v === null) {
                        continue;
                    }
                    // Pro Service gewinnt die erste gefundene SLA (MVP).
                    if (!isset($map[(string) $sid])) {
                        $map[(string) $sid] = [
                            'name' => (string) ($sla['name'] ?? ''),
                            'slo'  => (float) ($sla['slo'] ?? 0),
                            'sli'  => round((float) $v, 4),
                        ];
                    }
                }
            }
        }
        catch (\Throwable $e) {
            $map = [];
        }

        return $map;
    }

    /**
     * Antwortzeit/Erreichbarkeit je Service aus einem verknuepften Web-Szenario
     * (serviceid => {ok, resp_ms}). Konvention: ein Service traegt den Tag
     * `nt:web` = Name des Web-Szenarios.
     *
     * In try/catch gekapselt — fehlt die Web-Monitoring-Berechtigung oder das
     * Szenario, bleibt der Knoten einfach ohne Web-Zeile.
     */
    private function loadWebMap(array $services): array {
        $map = [];
        try {
            $svc_scenario = [];   // serviceid => scenario-name
            $names = [];
            foreach ($services as $s) {
                foreach ($s['tags'] ?? [] as $tag) {
                    if (($tag['tag'] ?? '') === 'nt:web' && ($tag['value'] ?? '') !== '') {
                        $svc_scenario[(string) $s['serviceid']] = (string) $tag['value'];
                        $names[(string) $tag['value']] = true;
                    }
                }
            }
            if (!$names) {
                return [];
            }

            // Abgleich NUR ueber den Szenario-Namen (nt:web=<name>). Grenze der
            // Konvention: tragen zwei Hosts ein Szenario GLEICHEN Namens, laufen
            // ihre Items unter einem Schluessel zusammen (resp_ms summiert, ok
            // last-writer). Fuer eindeutige Szenario-Namen — der Normalfall —
            // ist das kein Thema; ein Host-Teil im Tag waere die Erweiterung.
            $tests = API::HttpTest()->get([
                'output' => ['httptestid', 'name', 'hostid'],
                'filter' => ['name' => array_keys($names)],
            ]);
            $hostids = [];
            foreach ($tests as $t) {
                $hostids[(string) $t['hostid']] = true;
            }
            if (!$hostids) {
                return [];
            }

            $items = API::Item()->get([
                'output'      => ['key_', 'lastvalue'],
                'hostids'     => array_keys($hostids),
                'search'      => ['key_' => 'web.test.'],
                'startSearch' => true,
                // Web-Monitoring-Items liefert item.get sonst NICHT zurueck
                // (Default webitems=false) — ohne dieses Flag faende die Suche
                // oben nie ein web.test.*-Item.
                'webitems'    => true,
            ]);
            $agg = ServiceTree::aggregateWebItems($items);

            foreach ($svc_scenario as $sid => $name) {
                if (isset($agg[$name])) {
                    $map[$sid] = $agg[$name];
                }
            }
        }
        catch (\Throwable $e) {
            $map = [];
        }

        return $map;
    }
}
