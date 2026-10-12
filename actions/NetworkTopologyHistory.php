<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 PlaNet Fox / Alexander Fox
declare(strict_types = 1);

namespace Modules\NetworkTopology\Actions;

use API;

/**
 * NetworkTopologyHistory
 *
 * Liefert eine Severity-Timeline für alle Hosts der ausgewählten Hostgroups
 * über ein Zeitfenster. Wird für den History-Slider benutzt.
 *
 * Request (GET/POST):
 *   groupids[] = 1,2,...        (mind. 1 Hostgroup)
 *   from       = 1710000000     (Unix-Timestamp Start)
 *   to         = 1710086400     (Unix-Timestamp Ende)
 *
 * Response JSON:
 *   {
 *     "from": 1710000000,
 *     "to":   1710086400,
 *     "events": {
 *       "10656": [           // hostid → Liste von Severity-Wechseln
 *         { "ts": 1710001234, "sev": 4, "name": "ICMP unreachable" },
 *         { "ts": 1710002000, "sev": 0, "name": "ICMP unreachable" }
 *       ],
 *       ...
 *     }
 *   }
 *
 * Das Frontend baut daraus pro Host eine Timeline: zur Zeit T ist die
 * höchste Severity aller PROBLEM-Events (sev>0) die bei T noch nicht
 * RESOLVED (sev=0 dasselbe Trigger-Name) waren.
 *
 * Auflösung wird vom Frontend bestimmt (1h / 24h / 7d) — Backend liefert
 * einfach alle Events im Range.
 *
 * Performance: Bei großen Setups (>100 Hosts × 7d) kann event.get viele
 * Events liefern. Wir limitieren auf max 50000 Events; falls truncated,
 * gibt das Backend ein "truncated":true Flag zurück.
 */
class NetworkTopologyHistory extends NetworkTopologyController {

    private const MAX_EVENTS = 50000;
    private const MAX_RANGE_SECONDS = 31 * 86400 + 3600;   // 31 Tage + 1h Toleranz
                                                            // (war 7d, dann fuer Stats-Tab erhoeht)

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        if (!$this->requireAjax()) return false;
        $ret = $this->validateInput([
            'groupids' => 'array_id',
            'from'     => 'int32',
            'to'       => 'int32',
        ]);
        if (!$ret) {
            $this->jsonResponse(['error' => 'Invalid input']);
        }
        return $ret;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
    }

    protected function doAction(): void {
        if (!$this->throttle('history')) return;
        $_t0 = microtime(true);
        $groupids = $this->getInput('groupids', []);
        $from     = (int) $this->getInput('from', 0);
        $to       = (int) $this->getInput('to', 0);

        // Range-Validierung
        if ($from <= 0 || $to <= 0 || $to <= $from) {
            $this->jsonResponse(['error' => 'Invalid time range']);
            return;
        }
        if (($to - $from) > self::MAX_RANGE_SECONDS) {
            $this->jsonResponse(['error' => 'Time range exceeds max (' . (int)(self::MAX_RANGE_SECONDS / 86400) . ' days)']);
            return;
        }
        if (empty($groupids)) {
            $this->jsonResponse(['error' => 'No groupids']);
            return;
        }

        // Permissions: nur Hostgroups die der User sehen darf
        $allowed_groups = API::HostGroup()->get([
            'output'   => ['groupid'],
            'groupids' => $groupids,
            'preservekeys' => true,
        ]);
        $allowed_ids = array_keys($allowed_groups);
        if (empty($allowed_ids)) {
            $this->jsonResponse(['error' => 'No accessible hostgroups']);
            return;
        }

        // Hosts der Gruppen sammeln
        $hosts = API::Host()->get([
            'output'   => ['hostid'],
            'groupids' => $allowed_ids,
            'preservekeys' => true,
        ]);
        $hostids = array_keys($hosts);
        if (empty($hostids)) {
            $this->jsonResponse([
                'from' => $from, 'to' => $to, 'events' => new \stdClass()
            ]);
            return;
        }

        // In-Range-Events (PROBLEM + RECOVERY) — die Zustandswechsel im Fenster.
        // r_eventid (die Recovery-eventid eines Problems) brauchen wir unten, um
        // ein RECOVERY-Event GENAU dem Problem zuzuordnen, das es schliesst.
        $events = API::Event()->get([
            'output'      => ['eventid', 'objectid', 'clock', 'value', 'severity', 'name', 'r_eventid'],
            'source'      => EVENT_SOURCE_TRIGGERS,
            'object'      => EVENT_OBJECT_TRIGGER,
            'hostids'     => $hostids,
            'time_from'   => $from,
            'time_till'   => $to,
            'sortfield'   => ['clock', 'eventid'],
            'sortorder'   => 'ASC',
            'limit'       => self::MAX_EVENTS + 1,    // +1 um Truncation zu erkennen
            'selectHosts' => ['hostid'],
        ]);
        if (!is_array($events)) $events = [];
        $truncated = false;
        if (count($events) > self::MAX_EVENTS) {
            $truncated = true;
            $events = array_slice($events, 0, self::MAX_EVENTS);
        }

        // Welche Probleme waren GENAU zum Range-Start offen?
        //
        // Frueher: problem.get mit time_till=from. Das lieferte nur die HEUTE
        // noch offenen Probleme und verlor jedes, das vor `from` aufmachte und
        // seither behoben wurde — der Host sah dann zwischen Range-Start und
        // Recovery faelschlich unauffaellig aus (Review-Befund #7). Zudem war
        // selectHosts auf problem.get auf keiner Version gueltig.
        //
        // Richtig: event.get mit problem_time_from = problem_time_till = from
        // gibt die Probleme, die ZUM ZEITPUNKT `from` im Problem-Zustand waren —
        // auch die danach behobenen. selectHosts ist auf event.get (anders als
        // problem.get) auf allen Versionen gueltig.
        $open_at_start = API::Event()->get([
            'output'            => ['eventid', 'objectid', 'clock', 'severity', 'name', 'r_eventid'],
            'source'            => EVENT_SOURCE_TRIGGERS,
            'object'            => EVENT_OBJECT_TRIGGER,
            'value'             => TRIGGER_VALUE_TRUE,     // nur PROBLEM-Events
            'hostids'           => $hostids,
            'problem_time_from' => $from,
            'problem_time_till' => $from,
            'selectHosts'       => ['hostid'],
        ]);
        if (!is_array($open_at_start)) $open_at_start = [];

        // Recovery -> Problem: welche PROBLEM-eventid schliesst ein RECOVERY?
        // Der Weg geht ueber r_eventid der PROBLEM-Events (deren eigene
        // Recovery-eventid). Aus BEIDEN Abfragen, damit auch ein vor `from`
        // aufgemachtes und im Fenster behobenes Problem zugeordnet wird — sein
        // PROBLEM-Event liegt nicht im Fenster, steckt aber in open_at_start.
        // Loest #8: vorher schloss das Frontend ueber den Trigger-NAMEN, womit
        // sich zwei gleichnamige Trigger (oder mehrere Probleme desselben
        // Triggers) gegenseitig ueberschrieben.
        $recovery_to_problem = [];   // recovery-eventid => problem-eventid
        foreach (array_merge($events, $open_at_start) as $ev) {
            if ((int) ($ev['value'] ?? 1) === 1
                    && !empty($ev['r_eventid']) && (string) $ev['r_eventid'] !== '0') {
                $recovery_to_problem[(string) $ev['r_eventid']] = (string) $ev['eventid'];
            }
        }

        // Pro Host eine nach Zeit sortierte Liste von Zustandswechseln:
        //   PROBLEM  (val=1): { ts, sev, val:1, id:<problem-eventid>, name }
        //   RECOVERY (val=0): { ts, val:0, pid:<geschlossene problem-eventid|null> }
        $by_host = [];
        foreach ($events as $ev) {
            foreach ($ev['hosts'] ?? [] as $h) {
                $hid = $h['hostid'];
                if (!isset($by_host[$hid])) $by_host[$hid] = [];
                if ((int) $ev['value'] === 1) {
                    $by_host[$hid][] = [
                        'ts'   => (int) $ev['clock'],
                        'sev'  => (int) $ev['severity'],
                        'val'  => 1,
                        'id'   => (string) $ev['eventid'],
                        'name' => $ev['name'],
                    ];
                }
                else {
                    $by_host[$hid][] = [
                        'ts'  => (int) $ev['clock'],
                        'val' => 0,
                        'pid' => $recovery_to_problem[(string) $ev['eventid']] ?? null,
                    ];
                }
            }
        }
        // Zum Range-Start offene Probleme als "pre"-PROBLEM bei ts=from — mit
        // ihrer ECHTEN eventid, damit ihr spaeteres Recovery im Fenster sie
        // ueber pid schliesst.
        foreach ($open_at_start as $pr) {
            foreach ($pr['hosts'] ?? [] as $h) {
                $hid = $h['hostid'];
                if (!isset($by_host[$hid])) $by_host[$hid] = [];
                $by_host[$hid][] = [
                    'ts'   => $from,
                    'sev'  => (int) $pr['severity'],
                    'val'  => 1,
                    'id'   => (string) $pr['eventid'],
                    'name' => $pr['name'],
                    'pre'  => true,
                ];
            }
        }
        // Nach Zeit sortieren (pre-Eintraege ts=from und In-Range-Events sind
        // gemischt). Ein PROBLEM und sein RECOVERY haben nie denselben ts, die
        // Reihenfolge bei gleichem ts ist fuer die offene Menge also egal.
        foreach ($by_host as &$_list) {
            usort($_list, static fn ($a, $b) => $a['ts'] <=> $b['ts']);
        }
        unset($_list);

        $_payload = [
            'from'      => $from,
            'to'        => $to,
            'events'    => $by_host ?: new \stdClass(),
            'truncated' => $truncated,
        ];
        NetworkTopologyDiag::record([
            'action'     => 'history',
            'elapsed_ms' => round((microtime(true) - $_t0) * 1000, 1),
            'bytes'      => strlen($this->encodeJson($_payload)),
            'cache_hit'  => false,
            'counts'     => ['hosts' => is_array($by_host) ? count($by_host) : 0, 'truncated' => $truncated],
        ]);
        $this->jsonResponse($_payload);
    }
}
