#!/usr/bin/env bash
#
# nt-arp-probe.sh — beantwortet die Frage, an der die IP->MAC-Suche haengt:
# gibt DIESER Router/L3-Switch seine ARP-Tabelle (IP->MAC) ueber SNMP heraus,
# und in welcher Wert-Schreibweise kommt die MAC an?
#
# Aufruf:
#   NT_COMMUNITY=<community> tools/nt-arp-probe.sh <ip>
#   tools/nt-arp-probe.sh <ip>            (fragt die Community interaktiv ab)
#
# WARUM DIESES SKRIPT
# -------------------
# Die ARP-Tabelle (IP->MAC) ist die Datenquelle fuer „welche MAC hat diese IP".
# Zusammen mit der FDB (MAC->Port) wird daraus „diese IP haengt an Port Y".
# Ob und wie ein Geraet die Tabelle hergibt, variiert:
#
#   ipNetToMediaPhysAddress  1.3.6.1.2.1.4.22.1.2   klassisch, IPv4. Index =
#                            ifIndex + 4 IP-Oktette, Wert = MAC.
#   ipNetToPhysicalPhysAddr  1.3.6.1.2.1.4.35.1.4   neuer, auch IPv6. Anderer
#                            Index (ifIndex, Adresstyp, Laenge, Adresse).
#
# Entscheidend ist die WERT-Schreibweise der MAC ("Hex-STRING: 00 11 ..",
# "0x0011..", Doppelpunkte) — danach richtet sich der Parser. Deshalb zeigt das
# Skript EINE Beispielzeile, aber MASKIERT.
#
# WAS ES AUSGIBT — UND WAS NICHT
# ------------------------------
# Zahlen (Anzahl Eintraege) und die sysDescr-Zeile. Die eine Beispielzeile ist
# vollstaendig maskiert: jede Hex-Ziffer der MAC und jede Zahl der IP wird zu
# x bzw. N — nur Trenner und Typ-Praefix bleiben, damit man das FORMAT sieht,
# aber WEDER eine echte MAC NOCH eine echte IP. Die Community erscheint nie.
#
# Das Skript sendet NICHTS irgendwohin. Einziger Netzverkehr geht an die IP,
# die der Aufrufer uebergeben hat. Liegt in tools/ und ist damit aus dem
# Modul-Paket ausgeschlossen (deploy.sh, bewacht von check-package.mjs).

set -uo pipefail

readonly OID_SYSDESCR='1.3.6.1.2.1.1.1.0'
readonly OID_ARP_MEDIA='1.3.6.1.2.1.4.22.1.2'   # ipNetToMediaPhysAddress (IPv4)
readonly OID_ARP_PHYS='1.3.6.1.2.1.4.35.1.4'    # ipNetToPhysicalPhysAddress (v4+v6)

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

# Community NICHT als Argument (steht in der History, 'ps'-sichtbar).
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

walk_count() {
    snmpwalk -v2c -c "$COMMUNITY" -t 3 -r 1 -Oq "$IP" "$1" 2>/dev/null \
        | grep -v -e 'No Such' -e 'End of MIB' -e '^$' | grep -c . || true
}
walk_val() {
    snmpwalk -v2c -c "$COMMUNITY" -t 3 -r 1 -Ov -Oq "$IP" "$1" 2>/dev/null \
        | grep -v -e '^No Such' -e '^End of MIB' -e '^$' || true
}
# Eine Beispielzeile, maskiert: Index-Zahlen -> N, MAC-Hex -> x. Zeigt das
# Format (Typ-Praefix, Trenner), aber keine echte IP und keine echte MAC.
sample_masked() {
    snmpwalk -v2c -c "$COMMUNITY" -t 3 -r 1 -On "$IP" "$1" 2>/dev/null \
        | grep -v -e 'No Such' -e 'End of MIB' -e '^$' \
        | awk -F' = ' -v base="$1" 'NR==1{
            idx=$1; sub("^\\."base"\\.","",idx); sub("^"base"\\.","",idx);
            gsub(/[0-9]+/,"N",idx);
            val=$2;
            if (match(val, /^[A-Za-z][A-Za-z0-9 -]*: /)) {
                pre=substr(val,1,RLENGTH); body=substr(val,RLENGTH+1);
            } else { pre=""; body=val; }
            gsub(/[0-9A-Fa-f]/,"x",body);
            printf "index=%s  value=%s%s", idx, pre, body;
        }'
}

echo
echo "${C_DIM}Probing $IP -- read-only. Counts only; the one sample line is masked.${C_RST}"

SYSDESCR="$(walk_val "$OID_SYSDESCR" | head -1)"
if [[ -z "$SYSDESCR" ]]; then
    echo
    echo "  ${C_ERR}x${C_RST} No answer to sysDescr."
    echo "    This is ${C_WARN}not an ARP finding${C_RST}: unreachable, SNMP off, wrong"
    echo "    community, or a firewall blocks UDP/161."
    exit 2
fi
echo "  ${C_OK}v${C_RST} Device answers."

n_media="$(walk_count "$OID_ARP_MEDIA")"
n_phys="$(walk_count "$OID_ARP_PHYS")"

line() { if [[ "$1" -gt 0 ]]; then echo "  $2 $3"; else echo "  $4 $5"; fi; }
line "$n_media" "${C_OK}v${C_RST}" "ipNetToMediaPhysAddress (IPv4 ARP): $n_media entry/entries." \
                "${C_DIM}-${C_RST}" "ipNetToMediaPhysAddress (IPv4 ARP): empty."
line "$n_phys"  "${C_OK}v${C_RST}" "ipNetToPhysicalPhysAddress (v4+v6): $n_phys entry/entries." \
                "${C_DIM}-${C_RST}" "ipNetToPhysicalPhysAddress (v4+v6): empty."

if [[ "$n_media" -gt 0 ]]; then
    echo "  ${C_DIM}sample (masked): $(sample_masked "$OID_ARP_MEDIA")${C_RST}"
elif [[ "$n_phys" -gt 0 ]]; then
    echo "  ${C_DIM}sample (masked): $(sample_masked "$OID_ARP_PHYS")${C_RST}"
fi

echo
if [[ "$n_media" -gt 0 ]]; then
    verdict="IPv4 ARP usable (ipNetToMediaPhysAddress)"
    hint="Create a walk[] item nt.arp = walk[$OID_ARP_MEDIA] on THIS device. The finder reads it, maps IP->MAC, then MAC->port via the FDB. Send me the masked sample so the value parser matches the real format."
elif [[ "$n_phys" -gt 0 ]]; then
    verdict="only ipNetToPhysicalPhysAddress populated (newer MIB)"
    hint="The classic IPv4 table is empty, the newer one is filled. Its index differs (ifIndex, addr-type, addr-len, address). The v1 parser targets ipNetToMediaPhysAddress -- tell me and I add the phys-table index shape."
else
    verdict="no ARP visible"
    hint="The device answers but hands out neither ARP table. Usually a restricted SNMP view (widen it to include 1.3.6.1.2.1.4), or this box is not the L3 gateway for the hosts you search."
fi

echo "  ${C_WARN}Verdict:${C_RST} $verdict"
echo "  ${C_DIM}$hint${C_RST}"
echo
echo "  ${C_DIM}Device: $(echo "$SYSDESCR" | cut -c1-70)${C_RST}"
echo
