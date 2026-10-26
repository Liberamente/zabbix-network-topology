<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 PlaNet Fox / Alexander Fox
declare(strict_types = 1);

namespace Modules\NetworkTopology\Topology;

use API;

/**
 * SharedConfig — EINEN Schluessel der geteilten module.config schreiben, ohne
 * die Aenderungen der anderen Schluessel zu verlieren.
 *
 * DAS PROBLEM (Review #4)
 * -----------------------
 * Links, Positionen und Farbskalen liegen alle in module.config, aber unter
 * verschiedenen Schluesseln. Jeder Schreiber nahm bisher die BOOTSTRAP-Kopie der
 * Config (die der ModuleManager beim Request-Start aus der DB holt), setzte
 * seinen Schluessel und schrieb die GANZE Config zurueck. Zwei gleichzeitige
 * Speichervorgaenge verschiedener Schluessel schrieben sich damit die jeweils
 * andere Aenderung zurueck: A speichert neue Links, B speichert Positionen und
 * legt dabei A's alte Links wieder hin. Die Revisionspruefung in der Action
 * faengt das nicht — sie prueft nur den eigenen Schluessel.
 *
 * DIE LOESUNG
 * -----------
 * Unmittelbar vor dem Schreiben die Config FRISCH aus der DB lesen
 * (API::Module()->get, nicht die Bootstrap-Kopie) und nur den eigenen Schluessel
 * hineinmergen. So sieht B die inzwischen von A geschriebenen Links und erhaelt
 * sie. Das Fenster schrumpft von der ganzen Request-Dauer auf die paar Zeilen
 * zwischen Lesen und Schreiben; den verbleibenden Gleich-Schluessel-Fall deckt
 * die Revisionspruefung der Action ab.
 *
 * Deshalb liest das NUR der Schreibpfad frisch — der Lesepfad (loadShared) bleibt
 * bei der Bootstrap-Kopie, die bei jedem Seitenaufbau ohnehin schon da ist.
 */
final class SharedConfig {

    /**
     * $key in der geteilten Config setzen ($value), oder entfernen
     * ($value === null — z.B. der Farbskalen-Reset). Wirft \APIException, wenn
     * der Schreibvorgang fehlschlaegt: der Frontend-API-Wrapper gibt dann false
     * zurueck und registriert die Meldung, statt zu werfen (Review #2) — ohne
     * die Pruefung liefe setConfig() trotzdem und der Aufrufer meldete Erfolg,
     * waehrend nichts gespeichert wurde.
     *
     * @param object $module  ModuleManager-Modulobjekt
     * @param mixed  $value    neuer Wert, oder null zum Entfernen des Schluessels
     */
    public static function write($module, string $key, $value): void {
        $config = self::fresh($module);

        if ($value === null) {
            unset($config[$key]);
        }
        else {
            $config[$key] = $value;
        }

        if (API::Module()->update([[
            'moduleid' => $module->getModuleId(),
            'config'   => $config,
        ]]) === false) {
            throw new \APIException(ZBX_API_ERROR_PARAMETERS,
                implode('; ', array_column(get_and_clear_messages(), 'message'))
                    ?: _('Saving the shared configuration failed.'));
        }

        $module->setConfig($config);
    }

    /**
     * Strict, freshness-checked update reserved for dependency drafts.
     * Fail closed when Module.get is unavailable. This guards stale requests,
     * but is NOT atomic across simultaneous PHP workers. Do not expose a
     * write endpoint until a transactional/serialized design is validated.
     */
    public static function writeChecked($module, string $key, array $value,
            string $expectedRevision): void {
        $id = $module->getModuleId();
        $res = API::Module()->get([
            'output' => ['config'],
            'moduleids' => [$id],
            'preservekeys' => true
        ]);
        if (!is_array($res) || !isset($res[$id]['config'])
                || !is_array($res[$id]['config'])) {
            throw new \RuntimeException('Could not read current module config');
        }
        $config = $res[$id]['config'];
        $current = is_array($config[$key] ?? null) ? $config[$key] : [];
        if ($expectedRevision === ''
                || !hash_equals(Revision::of($current), $expectedRevision)) {
            throw new \RuntimeException('Dependency revision conflict');
        }
        $config[$key] = $value;
        if (API::Module()->update([[
            'moduleid' => $id,
            'config' => $config
        ]]) === false) {
            throw new \RuntimeException('Could not update module config');
        }
        $module->setConfig($config);
    }

    /** Config FRISCH aus der DB; faellt auf die Bootstrap-Kopie zurueck. */
    private static function fresh($module): array {
        $id  = $module->getModuleId();
        $res = API::Module()->get([
            'output'       => ['config'],
            'moduleids'    => [$id],
            'preservekeys' => true,
        ]);
        if (is_array($res) && isset($res[$id]['config']) && is_array($res[$id]['config'])) {
            return $res[$id]['config'];
        }
        $boot = $module->getConfig();
        return is_array($boot) ? $boot : [];
    }
}
