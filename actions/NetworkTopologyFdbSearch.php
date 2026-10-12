<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 PlaNet Fox / Alexander Fox
declare(strict_types = 1);

namespace Modules\NetworkTopology\Actions;

use API;
use Modules\NetworkTopology\Topology\ArpParser;
use Modules\NetworkTopology\Topology\FdbParser;
use Modules\NetworkTopology\Topology\FdbResolver;
use Modules\NetworkTopology\Topology\LldpEdgeBuilder;

/**
 * NetworkTopologyFdbSearch — „an welchem Port haengt diese MAC?"
 *
 * Eingabe: eine MAC. Antwort: der Zugangsswitch + Port (der FDB-Treffer, der
 * KEIN Uplink ist), ehrlich ueber Mehrdeutigkeit und Alter.
 *
 * WARUM API-ONLY (kein direktes SNMP)
 * -----------------------------------
 * Die FDB kommt aus Zabbix-`walk[]`-Master-Items je Switch (feste Keys
 * nt.fdb.*): der Zabbix-Proxy sammelt sie, diese Action liest NUR deren
 * lastvalue ueber die API und reicht den Text an den puren FdbParser. Damit
 * funktioniert sie auch fuer Switches hinter einem Proxy, die das Frontend
 * selbst gar nicht per SNMP erreicht — und das Modul bleibt API-only.
 *
 * Request:  mac (Pflicht)
 * Response: FdbResolver::locate()-Ergebnis + host_names + method.
 */
class NetworkTopologyFdbSearch extends NetworkTopologyController {

    /** walk[]-Item-Keys -> Rolle. Diese Keys legt der Nutzer am Switch an. */
    private const FDB_KEYS = [
        'nt.fdb.dot1d'    => 'dot1d',     // walk[1.3.6.1.2.1.17.4.3.1.2]
        'nt.fdb.dot1q'    => 'dot1q',     // walk[1.3.6.1.2.1.17.7.1.2.2.1.2]
        'nt.fdb.baseport' => 'baseport',  // walk[1.3.6.1.2.1.17.1.4.1.2]
        'nt.fdb.ifname'   => 'ifname',    // walk[1.3.6.1.2.1.31.1.1.1.1]
    ];

    /** walk[]-Item-Key fuer die ARP-Tabelle (IPv4->MAC) am Router/L3-Switch. */
    private const ARP_KEY = 'nt.arp';         // walk[1.3.6.1.2.1.4.22.1.2]

    /** walk[]-Item-Key fuer die ND-/Neighbor-Tabelle (IPv6->MAC), Nicht-EUI-64. */
    private const ND_KEY = 'nt.nd';           // walk[1.3.6.1.2.1.4.35.1.4]

    /** Ab so vielen verschiedenen MACs gilt ein Port als Uplink (Heuristik). */
    private const MIN_UPLINK_MACS = 5;

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        if (!$this->requireAjax()) {
            return false;
        }
        $ret = $this->validateInput(['mac' => 'string']);
        if (!$ret) {
            $this->jsonResponse(['error' => 'Invalid input']);
        }
        return $ret;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
    }

    protected function doAction(): void {
        if (!$this->throttle('fdb_search')) {
            return;
        }
        // Die Eingabe ist eine MAC, eine IPv4 ODER eine IPv6. Eine IP wird zuerst
        // auf eine MAC aufgeloest (IPv4: ARP; IPv6: EUI-64 oder ND-Tabelle), dann
        // laeuft die normale FDB-Suche.
        $query     = trim((string) $this->getInput('mac', ''));
        $queryKind = 'mac';
        $arpInfo   = null;
        $mac       = $query;

        if (FdbResolver::normalizeMac($query) === null) {
            $isV4 = filter_var($query, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
            $isV6 = filter_var(preg_replace('/%.*$/', '', $query), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
            if ($isV4 || $isV6) {
                // IP -> MAC. Keine Antwort = eigener Status, nicht stillschweigend
                // „MAC nicht gefunden".
                $queryKind = 'ip';
                $arpInfo   = $isV4 ? $this->resolveArp($query) : $this->resolveV6($query);
                if ($arpInfo['mac'] === null) {
                    $this->jsonResponse(['status' => 'arp_not_found', 'mac' => null,
                        'query' => $query, 'query_kind' => 'ip',
                        'access' => [], 'all' => [], 'ts_newest' => 0,
                        'host_names' => (object) [], 'method' => 'fdb-count']);
                    return;
                }
                $mac = $arpInfo['mac'];
            }
            else {
                // Weder MAC noch IP: gar nicht erst gegen die API.
                $this->jsonResponse(['status' => 'invalid', 'mac' => null,
                    'query' => $query, 'query_kind' => 'invalid',
                    'access' => [], 'all' => [], 'ts_newest' => 0,
                    'host_names' => (object) [], 'method' => 'fdb-count']);
                return;
            }
        }

        // Die walk[]-Items aller Switches holen. API ehrt die Leserechte.
        $items = API::Item()->get([
            'output'      => ['itemid', 'hostid', 'key_', 'lastvalue', 'lastclock'],
            'search'      => ['key_' => array_keys(self::FDB_KEYS)],
            'searchByAny' => true,
            'monitored'   => true,
        ]);
        // false = API-Fehler (der Frontend-Wrapper gibt dann false zurueck,
        // Review #2) — als "keine Daten" behandeln, nicht als leeres Ergebnis
        // mit falscher Sicherheit.
        if (!is_array($items)) {
            $items = [];
        }

        // Je Host die vier Texte + den juengsten lastclock sammeln.
        $byHost = [];
        foreach ($items as $it) {
            $kind = self::FDB_KEYS[$it['key_']] ?? null;
            if ($kind === null) {
                continue;
            }
            $hid = (string) $it['hostid'];
            $byHost[$hid][$kind] = (string) ($it['lastvalue'] ?? '');
            $byHost[$hid]['ts']  = max((int) ($byHost[$hid]['ts'] ?? 0),
                                       (int) ($it['lastclock'] ?? 0));
        }

        // Parsen -> hits. parseHostMap castet den (von PHP zu int normalisierten)
        // Host-Schluessel an der Grenze zurueck auf string — siehe dort.
        $hits = FdbParser::parseHostMap($byHost);

        // Uplink-Ports: LLDP wo ein Switch Caps hat (Nachbar meldet Bridge/
        // Router), sonst die MAC-Zahl-Heuristik. Die Entscheidung faellt PRO
        // SWITCH, nicht pro Port: hat ein Switch ueberhaupt LLDP-Caps, gilt sein
        // LLDP-Uplink-Satz als vollstaendig. Bewusste Grenze — ein echter Uplink
        // auf so einem Switch OHNE Caps-Zeile (z.B. zu einem unmanaged Switch,
        // der kein LLDP spricht) gilt dann als Zugang. Die Alternative, LLDP mit
        // der Heuristik zu vereinen, waere schlimmer: sie holte den
        // Hypervisor-Port (viele VM-MACs, kein Bridge-Nachbar) als falschen
        // Uplink zurueck — genau das, was LLDP beheben soll. Ehrlich bleibt der
        // Finder ueber den 'ambiguous'-Status, der alle Kandidaten zeigt.
        //
        // ifName je Host EINMAL bauen (dieselbe Quelle wie die FDB-Treffer,
        // damit die Uplink-Portnamen zeichengleich zu den Treffer-Portnamen sind).
        $ifNamesByHost = [];
        foreach ($byHost as $hid => $w) {
            $ifNamesByHost[(string) $hid] = FdbParser::ifNames($w['ifname'] ?? '');
        }
        // Nur die Switches MIT Treffern interessieren fuer die Caps — die
        // lldpRemSysCapEnabled-Items also auf diese Hostids einschraenken, nicht
        // installweit ziehen (sonst auf grossen Netzen tausende Port-Items je
        // Suche, von denen uplinkPortsByCaps nur die Treffer-Hosts nutzt).
        $hitHostids = [];
        foreach ($hits as $h) {
            $hitHostids[(string) $h['hostid']] = true;
        }
        $capsRows = [];
        if ($hitHostids) {
            $capItems = API::Item()->get([
                'output'    => ['hostid', 'key_', 'lastvalue'],
                'hostids'   => array_keys($hitHostids),
                'search'    => ['key_' => 'lldpRemSysCapEnabled'],
                'monitored' => true,
            ]);
            if (is_array($capItems)) {
                foreach ($capItems as $it) {
                    $capsRows[] = [
                        'hostid' => (string) $it['hostid'],
                        'key_'   => (string) $it['key_'],
                        'value'  => (string) ($it['lastvalue'] ?? ''),
                    ];
                }
            }
        }
        $lldp    = LldpEdgeBuilder::uplinkPortsByCaps($capsRows, $ifNamesByHost);
        $byCount = FdbResolver::uplinkPortsByCount($hits, self::MIN_UPLINK_MACS);

        // Je Host: hat er LLDP-Caps -> der LLDP-Satz (leer = alle Ports Zugang);
        // sonst die Heuristik. lldpKnown immer true (Hybrid: stets klassifizieren,
        // nie 'no_lldp' zurueckfallen).
        $switchPorts = [];
        $lldpKnown   = [];
        foreach ($hits as $h) {
            $hid = (string) $h['hostid'];
            $lldpKnown[$hid] = true;
            if (!isset($switchPorts[$hid])) {
                $switchPorts[$hid] = isset($lldp['known'][$hid])
                    ? ($lldp['ports'][$hid] ?? [])
                    : ($byCount[$hid] ?? []);
            }
        }
        $res = FdbResolver::locate($mac, $hits, $switchPorts, $lldpKnown);

        // Hat der tatsaechliche Access-Treffer auf LLDP beruht? Nur dann faellt
        // die Heuristik-Warnung weg — ein Treffer, dessen Switch nur per MAC-Zahl
        // klassifiziert wurde, bekommt sie weiter, auch wenn ein ANDERER
        // beteiligter Switch LLDP hatte.
        $usedLldp = !empty($res['access']);
        foreach ($res['access'] as $a) {
            if (!isset($lldp['known'][(string) $a['hostid']])) {
                $usedLldp = false;
                break;
            }
        }

        // Hostnamen fuer die beteiligten Switches nachladen (die Anzeige will
        // Namen, nicht IDs). Nur die hostids aus dem Ergebnis.
        $hostids = [];
        foreach ($res['all'] as $h) {
            $hostids[$h['hostid']] = true;
        }
        $names = (object) [];
        if ($hostids) {
            $hs = API::Host()->get([
                'output'       => ['hostid', 'name'],
                'hostids'      => array_keys($hostids),
                'preservekeys' => true,
            ]);
            if (is_array($hs)) {
                $map = [];
                foreach ($hs as $hid => $h) {
                    $map[(string) $hid] = (string) ($h['name'] ?? $hid);
                }
                $names = $map ?: (object) [];
            }
        }

        $res['host_names'] = $names;
        // Welcher Weg die Uplinks bestimmt hat — fuers UI, das die Schwaeche der
        // Heuristik benennen koennen soll. 'fdb-lldp', sobald fuer einen
        // beteiligten Switch LLDP-Caps vorlagen (verlaesslicher), sonst die
        // reine MAC-Zahl-Heuristik.
        $res['method'] = $usedLldp ? 'fdb-lldp' : 'fdb-count';

        // Was gesucht wurde und — bei einer IP — wie sie aufgeloest wurde.
        $res['query']      = $query;
        $res['query_kind'] = $queryKind;
        if ($arpInfo !== null) {
            $res['resolved_mac'] = $arpInfo['mac'];
            $res['arp_host']     = $arpInfo['host_name'];
            $res['arp_ts']       = $arpInfo['ts'];
            $res['arp_src']      = $arpInfo['src'];   // 'arp' | 'nd' | 'eui64'
        }
        $this->jsonResponse($res);
    }

    /**
     * Eine IPv4 ueber die ARP-Tabellen aller Router/L3-Switches auf eine MAC
     * aufloesen. Liest die nt.arp-walk[]-Items (API-only, proxy-tauglich) und
     * nimmt bei mehreren Treffern den juengsten (lastclock). Gibt mac=null,
     * wenn keine ARP-Tabelle die IP kennt.
     *
     * @return array{mac:?string,host:?string,host_name:string,ts:int}
     */
    private function resolveArp(string $ip): array {
        $none = ['mac' => null, 'host' => null, 'host_name' => '', 'ts' => 0, 'src' => 'arp'];
        $items = API::Item()->get([
            'output'    => ['hostid', 'key_', 'lastvalue', 'lastclock'],
            'search'    => ['key_' => self::ARP_KEY],
            'monitored' => true,
        ]);
        if (!is_array($items)) {
            return $none;
        }
        $bestMac  = null;
        $bestHost = null;
        $bestTs   = -1;
        foreach ($items as $it) {
            $map = ArpParser::parse((string) ($it['lastvalue'] ?? ''));
            if (!isset($map[$ip])) {
                continue;
            }
            $ts = (int) ($it['lastclock'] ?? 0);
            if ($ts > $bestTs) {
                $bestTs   = $ts;
                $bestMac  = $map[$ip];
                $bestHost = (string) $it['hostid'];
            }
        }
        if ($bestMac === null) {
            return $none;
        }
        return ['mac' => $bestMac, 'host' => $bestHost,
                'host_name' => $this->hostName($bestHost), 'ts' => max(0, $bestTs),
                'src' => 'arp'];
    }

    /**
     * Eine IPv6 auf eine MAC aufloesen. Zuerst EUI-64 (die MAC steckt in der
     * Adresse, ohne SNMP) — das deckt SLAAC und Link-Local; sonst die ND-Tabelle
     * (nt.nd, ipNetToPhysicalPhysAddress) fuer Privacy-/DHCPv6-Adressen.
     *
     * @return array{mac:?string,host:?string,host_name:string,ts:int,src:string}
     */
    private function resolveV6(string $ip): array {
        $eui = ArpParser::macFromEui64($ip);
        if ($eui !== null) {
            return ['mac' => $eui, 'host' => null, 'host_name' => '', 'ts' => 0,
                    'src' => 'eui64'];
        }
        $none  = ['mac' => null, 'host' => null, 'host_name' => '', 'ts' => 0, 'src' => 'nd'];
        $canon = ArpParser::canonicalV6($ip);
        if ($canon === null) {
            return $none;
        }
        $items = API::Item()->get([
            'output'    => ['hostid', 'key_', 'lastvalue', 'lastclock'],
            'search'    => ['key_' => self::ND_KEY],
            'monitored' => true,
        ]);
        if (!is_array($items)) {
            return $none;
        }
        $bestMac  = null;
        $bestHost = null;
        $bestTs   = -1;
        foreach ($items as $it) {
            $map = ArpParser::parseNd((string) ($it['lastvalue'] ?? ''));
            if (!isset($map[$canon])) {
                continue;
            }
            $ts = (int) ($it['lastclock'] ?? 0);
            if ($ts > $bestTs) {
                $bestTs   = $ts;
                $bestMac  = $map[$canon];
                $bestHost = (string) $it['hostid'];
            }
        }
        if ($bestMac === null) {
            return $none;
        }
        return ['mac' => $bestMac, 'host' => $bestHost,
                'host_name' => $this->hostName($bestHost), 'ts' => max(0, $bestTs),
                'src' => 'nd'];
    }

    /** hostid -> Anzeigename (ehrt die Leserechte); Fallback auf die id. */
    private function hostName(string $hostid): string {
        $hs = API::Host()->get([
            'output'       => ['hostid', 'name'],
            'hostids'      => [$hostid],
            'preservekeys' => true,
        ]);
        if (is_array($hs) && isset($hs[$hostid])) {
            return (string) ($hs[$hostid]['name'] ?? $hostid);
        }
        return $hostid;
    }
}
