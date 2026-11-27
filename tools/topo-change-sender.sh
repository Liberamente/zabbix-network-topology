#!/usr/bin/env bash
#
# topo-change-sender.sh — Topologie-Aenderungen + Health-Score als
# Zabbix-Items/-Events.
#
# Pollt die network.topology.data-Action und pusht per zabbix_sender:
#
#   nt.topo.changes        Anzahl added+removed Edges seit letztem Poll
#   nt.topo.changes.text   menschenlesbare Liste ("-" wenn nichts)
#   nt.health.score        Topology-Health-Score, Durchschnitt (0-100)
#   nt.health.score.min    Score der schlechtesten Hostgroup (0-100)
#
# Trigger dazu liefern die Templates:
#   templates/nt_topology_change_template.yaml  (changes > 0 → Event)
#   templates/nt_health_score_template.yaml     (score < 70 → Event)
#
# Hintergrund: Zabbix-Frontend-Modul-Actions brauchen eine Frontend-
# Session (kein API-Token-Support fuer zabbix.php) — deshalb macht dieses
# Skript den Login-Dance per curl statt dass ein HTTP-Agent-Item direkt
# pollt. Als Cron alle 1-5 Minuten laufen lassen, mit einem dedizierten
# Monitoring-User (USER-Rolle reicht, braucht Lese-Zugriff auf die
# Hostgroups).
#
# WICHTIG: eigener Monitoring-User! Die APCu-Topo-Baseline ist user-scoped —
# ein geteilter User wuerde sich die Baseline mit UI-Sessions verrollen.
#
# Verwendung (Cron-Beispiel, alle 2 Minuten):
#   */2 * * * * /opt/nt-tools/topo-change-sender.sh >/dev/null 2>&1
#
# Konfiguration ueber Environment oder die Defaults hier anpassen:
set -euo pipefail
umask 077  # keep all credentials, cookies and response data private

ZBX_URL="${ZBX_URL:-https://zabbix.example.com}"          # Frontend-Basis-URL
ZBX_USER="${ZBX_USER:-nt-topo-monitor}"                    # dedizierter User!
ZBX_PASS="${ZBX_PASS:-changeme}"
GROUPIDS="${GROUPIDS:-}"                                   # z.B. "24,25" — leer = Fehler
SENDER_HOST="${SENDER_HOST:-Zabbix server}"                # Host dem die Trapper-Items gehoeren
SENDER_CONF="${SENDER_CONF:-/etc/zabbix/zabbix_agentd.conf}"
ZBX_CACERT="${ZBX_CACERT:-}"                               # CA-Bundle fuer eine interne CA
ZBX_INSECURE="${ZBX_INSECURE:-0}"                          # 1 = TLS-Pruefung AUS (nur Labor!)

if [[ -z "$GROUPIDS" ]]; then
    echo "GROUPIDS ist leer — kommasepariert setzen (z.B. GROUPIDS=24,25)" >&2
    exit 1
fi

# TLS: standardmaessig VERIFIZIEREN. Ueber diese Verbindung gehen Benutzername,
# Passwort und das Session-Cookie — das fruehere stille "-k" (Zertifikat egal)
# war ein MITM-Risiko (Security-Review). Interne CA per ZBX_CACERT einbinden;
# ZBX_INSECURE=1 schaltet die Pruefung nur fuers Testlabor ab, und laut, nie still.
CURL_TLS=()
if [[ -n "$ZBX_CACERT" ]]; then
    CURL_TLS+=(--cacert "$ZBX_CACERT")
elif [[ "$ZBX_INSECURE" == "1" ]]; then
    CURL_TLS+=(-k)
    echo "WARN: ZBX_INSECURE=1 — TLS-Zertifikat wird NICHT geprueft (nur fuers Labor)." >&2
fi

WORKDIR=$(mktemp -d /tmp/nt_topo.XXXXXX)
trap 'rm -rf -- "$WORKDIR"' EXIT
JAR="$WORKDIR/cookie"
: > "$JAR"
# Passwort NICHT als Prozessargument (es stuende in der curl-Argumentliste und
# damit in /proc fuer andere lokale Prozesse, Security-Review F03). Stattdessen
# in eine 0600-Datei, die curl per "@" liest. Die Antwort landet ebenfalls in
# einer Datei (nicht in einer Shell-Variable, die als Python-Argument ginge und
# bei grossem Graphen ein "Argument list too long" ausloeste, F07). Alles beim
# Exit weg.
PWFILE="$WORKDIR/password"
RESPFILE="$WORKDIR/response"
: > "$RESPFILE"
printf '%s' "$ZBX_PASS" > "$PWFILE"

# 1. Frontend-Login (Session-Cookie in die Jar)
curl -sf "${CURL_TLS[@]}" -c "$JAR" -o /dev/null \
    --data-urlencode "name=$ZBX_USER" \
    --data-urlencode "password@$PWFILE" \
    --data-urlencode "enter=Sign in" \
    "$ZBX_URL/index.php"

# 2. Data-Action pollen (rollt die Topo-Baseline serverseitig weiter)
QS="action=network.topology.data"
IFS=',' read -ra GIDS <<< "$GROUPIDS"
for g in "${GIDS[@]}"; do
    QS="$QS&groupids%5B%5D=$g"
done
curl -sf "${CURL_TLS[@]}" -b "$JAR" -H "X-Requested-With: XMLHttpRequest" \
    -o "$RESPFILE" "$ZBX_URL/zabbix.php?$QS"

# 3. topo_changes + health extrahieren (python3 statt jq — praktisch immer
#    da). Ausgabe zeilenweise: count / health_avg / health_min.
#    Text wird in eine private Sender-Eingabedatei geschrieben. Die Antwort
#    kommt aus der Datei (argv traegt nur den kurzen Dateinamen, F07).
OUT="$(python3 - "$RESPFILE" "$WORKDIR/sender-input" "$SENDER_HOST" <<'PYEOF'
import json, sys
try:
    with open(sys.argv[1], encoding='utf-8') as _f:
        d = json.loads(_f.read())
except (OSError, ValueError):
    sys.exit('ERROR: invalid topology response')
if not isinstance(d, dict) or d.get('error') or not isinstance(d.get('topo_changes'), dict):
    sys.exit('ERROR: missing topology changes payload')
tc = d['topo_changes']
added = tc.get('added')
removed = tc.get('removed')
if not all(isinstance(v, list) and all(isinstance(x, dict) for x in v) for v in (added, removed)):
    sys.exit('ERROR: invalid topology changes lists')
parts  = ['+ %s <-> %s' % (x.get('a','?'), x.get('b','?')) for x in added]
parts += ['- %s <-> %s' % (x.get('a','?'), x.get('b','?')) for x in removed]
text = '; '.join(parts).replace('\n', ' ').replace('\r', ' ').replace('\x00', ' ') or '-'
# Zabbix text storage has a finite size. Preserve the count, explicitly
# mark a shortened description, and never split a UTF-8 character.
raw = text.encode('utf-8')
if len(raw) > 60000:
    text = raw[:59980].decode('utf-8', errors='ignore') + ' [truncated]'
def quote(value):
    return '"' + value.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n').replace('\r', '') + '"'
with open(sys.argv[2], 'w', encoding='utf-8') as stream:
    stream.write(quote(sys.argv[3]) + ' nt.topo.changes.text ' + quote(text) + '\n')
h = d.get('health') or {}
avg = h.get('avg'); mn = h.get('min')
print(len(added) + len(removed))
print('' if avg is None else avg)
print('' if mn is None else mn)
PYEOF
)"
COUNT=$(sed -n 1p <<< "$OUT")
AVG=$(  sed -n 2p <<< "$OUT")
MIN=$(  sed -n 3p <<< "$OUT")

# 4. per zabbix_sender an die Trapper-Items
zabbix_sender -c "$SENDER_CONF" -s "$SENDER_HOST" -k nt.topo.changes      -o "$COUNT" >/dev/null
zabbix_sender -c "$SENDER_CONF" -i "$WORKDIR/sender-input" >/dev/null
# Health-Score nur wenn das Backend ihn liefert (Modul >= 4.27) UND die
# Items existieren — zabbix_sender-Fehler (Template nicht importiert)
# sollen den Topo-Teil nicht mit runterreissen.
if [[ -n "$AVG" ]]; then
    zabbix_sender -c "$SENDER_CONF" -s "$SENDER_HOST" -k nt.health.score     -o "$AVG" >/dev/null || true
fi
if [[ -n "$MIN" ]]; then
    zabbix_sender -c "$SENDER_CONF" -s "$SENDER_HOST" -k nt.health.score.min -o "$MIN" >/dev/null || true
fi

echo "topo-changes: $COUNT · health: ${AVG:--}/${MIN:--}"
