<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 PlaNet Fox / Alexander Fox
declare(strict_types = 1);

namespace Modules\NetworkTopology\Actions;

use API;

/**
 * NetworkTopologyServiceEdit
 *
 * Verknuepft/trennt eine Dienst-Abhaengigkeit aus dem Dependencies-Tab: macht
 * einen Service zum Elternteil eines anderen (op=link) oder loest die Beziehung
 * (op=unlink). Schreibend — eigener CSRF-Token, POST-Pflicht, ADMIN.
 *
 * API::Service()->update() ERSETZT das parents-Array komplett. Also erst die
 * aktuellen Eltern des Kindes lesen, den Parent hinzufuegen bzw. entfernen,
 * dann updaten. Zabbix' eigene Service-Rolle entscheidet zusaetzlich, ob der
 * User den Service schreiben darf (die API verweigert sonst) — die ADMIN-Stufe
 * hier ist die Modul-Huerde davor, sichtbar nur fuer can_edit-User.
 *
 * Request (POST): op=link|unlink, child=<serviceid>, parent=<serviceid>, nt_csrf
 * Response: { ok: true } oder { error: '...' }
 */
class NetworkTopologyServiceEdit extends NetworkTopologyController {

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->jsonResponse(['error' => 'Method not allowed']);
            return false;
        }
        if (!$this->requireAjax()) {
            return false;
        }
        $ret = $this->validateInput([
            'op'      => 'required|in link,unlink',
            'child'   => 'required|id',
            'parent'  => 'required|id',
            'nt_csrf' => 'string',
        ]);
        if (!$ret) {
            $this->jsonResponse(['error' => 'Invalid input']);
            return false;
        }
        if (!\CCsrfTokenHelper::check((string) $this->getInput('nt_csrf', ''),
                'network.topology.service_edit')) {
            $this->jsonResponse(['error' => 'CSRF token invalid']);
            return false;
        }
        return true;
    }

    protected function checkPermissions(): bool {
        // NUR Super-Admins. Grund steht beim parents-Replace unten: nur ein
        // Super-Admin sieht garantiert ALLE Eltern eines Service (er umgeht die
        // Service-Rollen), und nur dann ist das vollstaendige Ersetzen des
        // parents-Arrays verlustfrei. Ein rollenbeschraenkter Admin koennte
        // sonst einen fuer ihn unsichtbaren Elternteil still mitloeschen.
        return $this->getUserType() === USER_TYPE_SUPER_ADMIN;
    }

    protected function doAction(): void {
        if (!$this->throttle('service_edit', 20, 10)) return;

        $op     = $this->getInput('op');
        $child  = (string) $this->getInput('child');
        $parent = (string) $this->getInput('parent');

        if ($child === $parent) {
            $this->jsonResponse(['error' => 'A service cannot depend on itself']);
            return;
        }

        try {
            // Beide Services holen — zugleich Existenz-/Rechte-Check (ein nicht
            // sichtbarer/erlaubter Service kommt nicht zurueck) und wir brauchen
            // die aktuellen Eltern des Kindes fuers Ersetzen.
            $svc = API::Service()->get([
                'output'        => ['serviceid'],
                'serviceids'    => [$child, $parent],
                'selectParents' => ['serviceid'],
                'preservekeys'  => true,
            ]);
            if (!isset($svc[$child]) || !isset($svc[$parent])) {
                $this->jsonResponse(['error' => 'Service not found or not permitted']);
                return;
            }

            // Aktuelle Eltern des Kindes als Basis fuers Ersetzen. update()
            // ERSETZT das parents-Array, verliert also jeden Elternteil, der hier
            // fehlt. Deshalb ist diese Action auf Super-Admins beschraenkt
            // (checkPermissions): nur sie sehen ueber selectParents garantiert
            // ALLE Eltern (Service-Rollen gelten fuer sie nicht), erst dadurch
            // ist das Ersetzen verlustfrei.
            $parents = [];
            foreach ($svc[$child]['parents'] ?? [] as $p) {
                $parents[(string) $p['serviceid']] = true;
            }

            if ($op === 'link') {
                $parents[$parent] = true;
            }
            else {
                unset($parents[$parent]);
            }

            API::Service()->update([
                'serviceid' => $child,
                'parents'   => array_map(
                    static fn ($sid) => ['serviceid' => (string) $sid],
                    array_keys($parents)
                ),
            ]);

            $this->jsonResponse(['ok' => true]);
        }
        catch (\APIException $e) {
            // Zabbix meldet Zyklen / fehlende Rechte hier — Meldung durchreichen.
            $this->jsonResponse(['error' => $e->getMessage()]);
        }
        catch (\Throwable $e) {
            $this->jsonResponse(['error' => 'Internal error']);
        }
    }
}
