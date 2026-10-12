<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 PlaNet Fox / Alexander Fox
declare(strict_types = 1);

namespace Modules\NetworkTopology\Topology;

/**
 * ServiceTree
 *
 * Reine Logik: wandelt das Ergebnis von API::Service()->get(...) (mit
 * selectChildren/selectProblemEvents) in den Graphen um, den der
 * "Dependencies"-Tab zeichnet — ein Knoten je Service, GERICHTETE Kanten
 * Eltern→Kind ("haengt ab von"): die Webseite oben, Webserver/DB/PHP darunter.
 *
 * Der API-Kontakt liegt bewusst NICHT hier, sondern in
 * actions/NetworkTopologyServices.php — damit die Umwandlung ohne Zabbix
 * testbar ist (siehe tests/ServiceTreeTest.php). Dasselbe Schnittmuster wie
 * ProblemLoader: der interessante Teil ist eine reine Funktion.
 *
 * Status-Normalisierung: Zabbix' service.status ist die Severity des
 * schlimmsten anliegenden Problems (0..5) oder -1 fuer OK. Wir mappen -1 auf 0
 * und kappen auf 5, damit das Frontend Service-Knoten mit DERSELBEN
 * SEV_COL-Palette einfaerbt wie Host-Knoten (severity.js) — kein zweites
 * Farbschema.
 */
final class ServiceTree {

    /** Schutz vor Riesenbaeumen im Payload/Layout. */
    private const MAX_NODES = 2000;

    /**
     * @param array $services Ergebnis von API::Service()->get([...]) mit
     *   serviceid, name, status und selectChildren (=> 'children' je
     *   [serviceid=>...]) sowie optional selectProblemEvents (=> 'problemevents').
     * @return array{nodes: list<array>, edges: list<array>}
     */
    public static function build(array $services): array {
        // Zwei Durchgaenge, damit Kanten NUR auf tatsaechlich emittierte Knoten
        // zeigen. Vorher wurde gegen $known (ALLE Services, ungekappt) geprueft,
        // die Knoten aber bei MAX_NODES abgeschnitten — ein Elternknoten unter
        // dem Limit mit einem Kind ueber dem Limit erzeugte dann eine Kante auf
        // einen Knoten, den es gar nicht gibt. Cytoscape wirft bei einer Kante
        // ohne Quelle/Ziel → der Tab zeigt statt des (gekappten) Graphen einen
        // Fehler. Also gegen die EMITTIERTEN IDs filtern, nicht gegen alle.

        // Durchgang 1: Knoten bauen (gekappt), emittierte IDs merken.
        $nodes = [];
        $emitted = [];
        $count = 0;
        foreach ($services as $s) {
            if ($count >= self::MAX_NODES) {
                break;
            }
            $sid = (string) ($s['serviceid'] ?? '');
            if ($sid === '') {
                continue;
            }
            $count++;
            $emitted[$sid] = true;

            // -1 (OK) -> 0; sonst auf 0..5 klemmen.
            $status = (int) ($s['status'] ?? -1);
            $severity = $status < 0 ? 0 : ($status > 5 ? 5 : $status);

            $problems = 0;
            if (isset($s['problemevents']) && is_array($s['problemevents'])) {
                $problems = count($s['problemevents']);
            }

            $nodes[] = [
                'id'        => 'svc' . $sid,
                'serviceid' => $sid,
                'label'     => (string) ($s['name'] ?? $sid),
                'severity'  => $severity,
                'problems'  => $problems,
                'isService' => true,
            ];
        }

        // Durchgang 2: Kanten Eltern→Kind, nur wenn BEIDE Enden emittiert sind.
        $edges = [];
        $seen_edge = [];
        foreach ($services as $s) {
            $sid = (string) ($s['serviceid'] ?? '');
            if ($sid === '' || !isset($emitted[$sid])) {
                continue;
            }
            foreach ($s['children'] ?? [] as $child) {
                $cid = (string) ($child['serviceid'] ?? '');
                if ($cid === '' || !isset($emitted[$cid])) {
                    continue;
                }
                $key = $sid . '|' . $cid;
                if (isset($seen_edge[$key])) {
                    continue;
                }
                $seen_edge[$key] = true;
                $edges[] = [
                    'source' => 'svc' . $sid,
                    'target' => 'svc' . $cid,
                    'kind'   => 'depends',
                ];
            }
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * Haengt SLA- und Web-Szenario-Daten an die passenden Knoten (per serviceid).
     * Rein — der API-Teil (sla.getSli / item.get) liegt im Controller.
     *
     * @param list<array> $nodes   Ergebnis von build()['nodes']
     * @param array       $slaMap  serviceid => ['name'=>..,'slo'=>float,'sli'=>float]
     * @param array       $webMap  serviceid => ['ok'=>?bool,'resp_ms'=>?int]
     * @return list<array>
     */
    public static function mergeExtras(array $nodes, array $slaMap, array $webMap): array {
        foreach ($nodes as &$n) {
            $sid = (string) ($n['serviceid'] ?? '');
            if ($sid === '') {
                continue;
            }
            if (isset($slaMap[$sid])) {
                $n['sla'] = $slaMap[$sid];
            }
            if (isset($webMap[$sid])) {
                $n['web'] = $webMap[$sid];
            }
        }
        unset($n);

        return $nodes;
    }

    /**
     * Zerlegt einen Web-Szenario-Item-Key (web.test.<metric>[Szenario,Step,Field]).
     * Rueckgabe null, wenn es kein web.test.*-Key ist.
     *
     * Grenze: Szenario-/Step-Namen mit Komma werden am ersten Komma getrennt —
     * selten und bewusst in Kauf genommen (Zabbix erlaubt sie, aber in der Praxis
     * kaum benutzt).
     *
     * @return array{metric:string,scenario:string,step:string,field:string}|null
     */
    public static function parseWebKey(string $key): ?array {
        if (!preg_match('/^web\.test\.(fail|time|in|rspcode|error)\[(.+)\]$/', $key, $m)) {
            return null;
        }
        $params   = explode(',', $m[2]);
        $scenario = trim($params[0] ?? '');
        if ($scenario === '') {
            return null;
        }

        return [
            'metric'   => $m[1],
            'scenario' => $scenario,
            'step'     => isset($params[1]) ? trim($params[1]) : '',
            'field'    => isset($params[2]) ? trim($params[2]) : '',
        ];
    }

    /**
     * Verdichtet die web.test.*-Items eines oder mehrerer Szenarien zu je
     * {ok, resp_ms}. ok aus web.test.fail (0 = alle Schritte ok), resp_ms als
     * Summe der Schritt-Antwortzeiten (web.test.time[...,resp], Sekunden → ms).
     *
     * @param list<array> $items  item.get-Ergebnis mit key_ + lastvalue
     * @return array<string,array{ok:?bool,resp_ms:?int}>  scenario => ...
     */
    public static function aggregateWebItems(array $items): array {
        $out = [];
        foreach ($items as $it) {
            $p = self::parseWebKey((string) ($it['key_'] ?? ''));
            if ($p === null) {
                continue;
            }
            $sc = $p['scenario'];
            if (!isset($out[$sc])) {
                $out[$sc] = ['ok' => null, 'resp_ms' => null];
            }
            $val = $it['lastvalue'] ?? null;

            if ($p['metric'] === 'fail') {
                // Tri-State: ok bleibt null (unbekannt), solange es keinen
                // numerischen Messwert gibt — ein frisch angelegtes Szenario
                // ohne Daten darf NICHT als "down" (false) erscheinen. Erst bei
                // einem Wert: 0 = alle Schritte ok (true), sonst Fehler (false).
                if ($val !== null && is_numeric($val)) {
                    $out[$sc]['ok'] = ((float) $val == 0.0);
                }
            }
            elseif ($p['metric'] === 'time' && $p['field'] === 'resp') {
                if ($val !== null && is_numeric($val)) {
                    $out[$sc]['resp_ms'] = ($out[$sc]['resp_ms'] ?? 0) + (float) $val * 1000.0;
                }
            }
        }
        foreach ($out as &$o) {
            if ($o['resp_ms'] !== null) {
                $o['resp_ms'] = (int) round($o['resp_ms']);
            }
        }
        unset($o);

        return $out;
    }
}
