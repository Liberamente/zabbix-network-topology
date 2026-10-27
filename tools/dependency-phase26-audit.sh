#!/bin/sh
# Phase 26: read-only deployment-boundary and backend-contract diagnostics.
# Run on zabbix-server02. Never prints DB env or host/network data.
set -eu
LAB=/opt/liberamente/topology-dependency-lab
PROD=/opt/liberamente/zabbix-server/modules-web/network_topology
STAGING=/opt/liberamente/topology-staging/network_topology
for d in "$LAB" "$PROD" "$STAGING"; do
    test -d "$d/.git" || { echo "FAIL: missing Git metadata"; exit 1; }
done
printf 'LAB commit: '; git -C "$LAB" rev-parse --short HEAD
printf 'PROD commit: '; git -C "$PROD" rev-parse --short HEAD
printf 'STAGING commit: '; git -C "$STAGING" rev-parse --short HEAD
for d in "$LAB" "$PROD" "$STAGING"; do
    changes=$(git -C "$d" status --porcelain)
    if [ -n "$changes" ]; then
        echo "WARNING: working tree not clean in $d (file names omitted)"
    else
        echo "OK: working tree clean in $d"
    fi
done
test -f "$LAB/topology/DependencyReadProjection.php"
test -f "$LAB/tests/DependencyReadProjectionTest.php"
test -f "$LAB/tests/DependencyReadWiringTest.mjs"
grep -q "'dependencies' => \$visible_dependencies" "$LAB/actions/NetworkTopologyData.php"
if grep -q '"network.topology.dependencies"' "$LAB/manifest.json"; then
    echo "FAIL: dependency write route is registered"
    exit 1
fi
echo "PASS: lab read path found; dependency write endpoint unregistered"
echo "NOTE: code inspection only; no live HTTP, DB, browser or permissions test"
