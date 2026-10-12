#!/usr/bin/env bash
#
# nt-fdb-probe.sh — beantwortet die eine Frage, an der der MAC/IP-Finder haengt:
# gibt DIESER Switch seine Forwarding-Database (FDB) ueber SNMP heraus — und
# laesst sich der Bridge-Port auf einen ifIndex aufloesen?
#
# Aufruf:
#   NT_COMMUNITY=<community> tools/nt-fdb-probe.sh <ip>
#   tools/nt-fdb-probe.sh <ip>            (fragt die Community interaktiv ab)
#
# WARUM DIESES SKRIPT
# -------------------
# Die FDB ist die Datenquelle fuer „die MAC haengt an Port Y". Ob ein Switch sie
# hergibt, entscheidet, ob das Feature auf ihm ueberhaupt funktioniert — und das
# variiert stark nach Hersteller. Drei Dinge muessen stimmen, und eine leere
# Antwort kann jedes davon heissen:
#
#   BRIDGE-MIB dot1dTpFdbPort     die klassische FDB (MAC -> Bridge-Port).
#   Q-BRIDGE  dot1qTpFdbPort      die VLAN-fähige FDB. Viele moderne Switches
#                                 fuellen NUR diese, nicht die klassische.
#   dot1dBasePortIfIndex          Bridge-Port -> ifIndex. OHNE das ist der
#                                 FDB-Treffer eine Bridge-Port-Nummer, KEIN
#                                 Interface — und Bridge-Port != ifIndex. Dieser
#                                 zweite Schritt wird gern vergessen; genau
#                                 deshalb pruefen wir ihn getrennt.
#
# WAS ES AUSGIBT — UND WAS NICHT
# ------------------------------
# Am Ende steht ein fertiger Markdown-Block fuer eine Rueckmeldung. Er enthaelt
# ausschliesslich ZAHLEN und die sysDescr-Zeile — KEINE MAC-Adressen. Das ist
# hier besonders wichtig: eine FDB IST eine Liste von MACs, also von Geraeten im
# Kundennetz. Sie taucht nirgends in der Ausgabe auf, es wird nur gezaehlt. Die
# Community ebenfalls nicht. Wer den Block weitergibt, muss nichts schwaerzen.
#
# Das Skript sendet NICHTS irgendwohin. Der einzige Netzverkehr geht an die IP,
# die der Aufrufer uebergeben hat.
#
# Liegt in tools/ und ist damit aus dem Modul-Paket ausgeschlossen (deploy.sh,
# bewacht von tools/check-package.mjs): ein Shell-Skript gehoert nicht unter
# den Web-Root.

set -uo pipefail

readonly OID_SYSDESCR='1.3.6.1.2.1.1.1.0'
readonly OID_DOT1D_FDB='1.3.6.1.2.1.17.4.3.1.2'     # dot1dTpFdbPort (MAC -> Bridge-Port)
readonly OID_DOT1D_IFX='1.3.6.1.2.1.17.1.4.1.2'     # dot1dBasePortIfIndex (Bridge-Port -> ifIndex)
readonly OID_DOT1Q_FDB='1.3.6.1.2.1.17.7.1.2.2.1.2' # dot1qTpFdbPort (VLAN+MAC -> Bridge-Port)
readonly OID_IFNAME='1.3.6.1.2.1.31.1.1.1.1'        # ifName (ifIndex -> Portname)

if [[ -t 1 ]]; then C_OK=$'\e[32m'; C_ERR=$'\e[31m'; C_WARN=$'\e[33m'; C_DIM=$'\e[2m'; C_RST=$'\e[0m'
else C_OK=""; C_ERR=""; C_WARN=""; C_DIM=""; C_RST=""; fi

usage() {
    sed -n '3,10p' "$0" | sed 's/^# \{0,1\}//'
    exit "${1:-0}"
}

[[ $# -ge 1 ]] || usage 1
case "$1" in -h|--help|help) usage 0 ;; esac

readonly IP="$1"
command -v snmpwalk >/dev/null 2>&1 || {
    echo "${C_ERR}snmpwalk not found.${C_RST} Install net-snmp:" >&2
    echo "  Debian/Ubuntu: sudo apt install snmp   RHEL: sudo dnf install net-snmp-utils" >&2
    exit 1
}

# Community NICHT als Argument: Argumente stehen in der Shell-History und sind
# fuer jeden sichtbar, der 'ps' aufrufen kann. Deshalb per Umgebungsvariable
# oder interaktiv ohne Echo.
COMMUNITY="${NT_COMMUNITY:-}"
if [[ -z "$COMMUNITY" ]]; then
    if [[ -t 0 ]]; then
        read -rsp "SNMP community for $IP (input stays hidden): " COMMUNITY
        echo
    else
        echo "${C_ERR}No community given.${C_RST} Set NT_COMMUNITY=<community>." >&2
        exit 1
    fi
fi
[[ -n "$COMMUNITY" ]] || { echo "${C_ERR}Community is empty.${C_RST}" >&2; exit 1; }

# Ein Walk. Gibt die Zeilen aus; Rueckgabewert 0 auch bei leerer Antwort, denn
# "leer" ist hier ein Ergebnis und kein Fehler. -Oq unterdrueckt die OID-Namen,
# -Ov nur bei sysDescr (Wert). Fuer die FDB zaehlen wir Zeilen, nicht Werte, um
# KEINE MAC-haltigen Indizes auszugeben.
walk_count() {
    snmpwalk -v2c -c "$COMMUNITY" -t 3 -r 1 -Oq "$IP" "$1" 2>/dev/null \
        | grep -v -e 'No Such' -e 'End of MIB' -e '^$' | grep -c . || true
}
walk_val() {
    snmpwalk -v2c -c "$COMMUNITY" -t 3 -r 1 -Ov -Oq "$IP" "$1" 2>/dev/null \
        | grep -v -e '^No Such' -e '^End of MIB' -e '^$' || true
}

echo
echo "${C_DIM}Probing $IP -- read-only, counts only (no MACs printed).${C_RST}"

SYSDESCR="$(walk_val "$OID_SYSDESCR" | head -1)"
if [[ -z "$SYSDESCR" ]]; then
    echo
    echo "  ${C_ERR}x${C_RST} No answer to sysDescr."
    echo
    echo "  This is ${C_WARN}not an FDB finding${C_RST}: the device is unreachable, SNMP is"
    echo "  off, the community is wrong, or a firewall blocks UDP/161."
    echo "      snmpwalk -v2c -c <community> $IP $OID_SYSDESCR"
    exit 2
fi
echo "  ${C_OK}v${C_RST} Device answers."

n_d1="$(walk_count "$OID_DOT1D_FDB")"
n_dq="$(walk_count "$OID_DOT1Q_FDB")"
n_ix="$(walk_count "$OID_DOT1D_IFX")"
n_if="$(walk_count "$OID_IFNAME")"

# line <count> <ok-glyph> <ok-msg> <bad-glyph> <bad-msg> — echtes if/then/else,
# nicht A && B || C (dort liefe C auch, wenn B fehlschluege; shellcheck SC2015).
line() { if [[ "$1" -gt 0 ]]; then echo "  $2 $3"; else echo "  $4 $5"; fi; }
line "$n_d1" "${C_OK}v${C_RST}" "BRIDGE-MIB FDB (dot1dTpFdbPort): $n_d1 entry/entries." \
             "${C_DIM}-${C_RST}" "BRIDGE-MIB FDB (dot1dTpFdbPort): empty."
line "$n_dq" "${C_OK}v${C_RST}" "Q-BRIDGE FDB (dot1qTpFdbPort): $n_dq entry/entries." \
             "${C_DIM}-${C_RST}" "Q-BRIDGE FDB (dot1qTpFdbPort): empty."
line "$n_ix" "${C_OK}v${C_RST}" "Bridge-Port -> ifIndex (dot1dBasePortIfIndex): $n_ix." \
             "${C_ERR}x${C_RST}" "Bridge-Port -> ifIndex: EMPTY -- FDB would be unresolvable."
line "$n_if" "${C_OK}v${C_RST}" "ifName: $n_if (port names available)." \
             "${C_WARN}!${C_RST}" "ifName: empty -- ports would show as ifIndex numbers."

# ── Verdict ──────────────────────────────────────────────────────────────────
if [[ "$n_d1" -eq 0 && "$n_dq" -eq 0 ]]; then
    verdict="no FDB visible"
    hint="The device answers but hands out neither FDB. Usually a restricted SNMP view: widen it to include the BRIDGE-MIB (1.3.6.1.2.1.17). Some switches expose the FDB only per VLAN context."
elif [[ "$n_ix" -eq 0 ]]; then
    verdict="FDB present, but bridge-port -> ifIndex MISSING"
    hint="dot1dTpFdbPort/dot1qTpFdbPort return BRIDGE-PORT numbers, not ifIndex. Without dot1dBasePortIfIndex they cannot be turned into a real port. Check whether the SNMP view includes 1.3.6.1.2.1.17.1.4."
elif [[ "$n_d1" -gt 0 ]]; then
    verdict="BRIDGE-MIB usable"
    hint="Classic FDB + port mapping are there. This switch can answer 'MAC on port Y' directly."
else
    verdict="Q-BRIDGE usable (VLAN-aware)"
    hint="Only the per-VLAN FDB is populated (common on modern switches). Resolution works, but each hit carries a VLAN and the walk must cover all VLAN contexts."
fi
echo
echo "  ${C_DIM}Verdict:${C_RST} $verdict"

echo
echo "${C_DIM}--- copy from here ------------------------------------------${C_RST}"
echo
cat <<REPORT
### <vendor> <model>, firmware <version>

| Question | Result |
|---|---|
| Device answers SNMP | yes |
| BRIDGE-MIB FDB (dot1dTpFdbPort) | $n_d1 |
| Q-BRIDGE FDB (dot1qTpFdbPort) | $n_dq |
| Bridge-port -> ifIndex (dot1dBasePortIfIndex) | $n_ix |
| ifName (port names) | $n_if |
| Verdict | **$verdict** |

sysDescr (straight from the device -- please read it before posting):

    $SYSDESCR
REPORT
echo
echo "${C_DIM}-------------------------------------------------------------${C_RST}"
echo "  ${C_DIM}Note:${C_RST} $hint"
echo
