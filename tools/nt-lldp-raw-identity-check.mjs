#!/usr/bin/env node
// SPDX-License-Identifier: AGPL-3.0-or-later
// Read-only LOCAL LLDP identity preflight; no network/API calls or file writes.
// Never prints LLDP values, MACs, addresses, device names or file paths.
import {readFileSync} from 'node:fs';

function norm(value) {
    return String(value ?? '').trim().toLowerCase().replace(/\s+/g, ' ');
}
function hash32(value) {
    let h = 2166136261;
    for (let i = 0; i < value.length; i++) {
        h ^= value.charCodeAt(i);
        h = (h + ((h << 1) + (h << 4) + (h << 7) +
            (h << 8) + (h << 24))) >>> 0;
    }
    return String(h >>> 0);
}
const base = '1.0.8802.1.1.2.1.4.1.1.';
// A numeric OID from snmpwalk -On; 2-part and 3-part LLDP indices supported.
// Recognize only textual SNMP output that can be compared to the LLD macro
// normalization. Anything else is INCONCLUSIVE, never a guessed identity.
function parseWalk(content) {
    const records = new Map();
    let ambiguous = 0;
    for (const line of content.split(/\r?\n/)) {
        const m = new RegExp('^\\.?'+base.replaceAll('.', '\\.')+
            '(5|7|9)\\.(\\d+\\.)?(\\d+)\\.(\\d+)\\s+=\\s+([^:]+):\\s*(.*)$').exec(line);
        if (!m) {
            if (line.includes(base)) ambiguous++;
            continue;
        }
        const column = Number(m[1]);
        const tm = m[2] ? Number(m[2].slice(0, -1)) : 0;
        const localPort = m[3], remIndex = m[4];
        const suffix = tm + '.' + localPort + '.' + remIndex;
        const type = m[5].trim().toUpperCase();
        // Zabbix 7.0 SNMP_WALK_TO_JSON (format 0) represents hex octets
        // as upper-case, space-separated bytes. Treat binary as opaque
        // chassis identity, never as a MAC even when it is six bytes long.
        let value = m[6].trim();
        if (type === 'HEX-STRING') {
            const octets = value.split(/\s+/);
            if (!octets.length || !octets.every(o => /^[0-9a-f]{2}$/i.test(o))) {
                ambiguous++;
                continue;
            }
            value = octets.join(' ');
        } else if (['STRING','OCTET STRING'].includes(type)) {
            if (value.startsWith('"') && value.endsWith('"')) {
                value = value.slice(1, -1);
            } else if (value.startsWith('"') || value.endsWith('"')) {
                ambiguous++;
                continue;
            }
        } else {
            ambiguous++;
            continue;
        }
        const obj = records.get(suffix) || {tm, localPort, remIndex};
        obj[column] = norm(value);
        records.set(suffix, obj);
    }
    return {rows:[...records.values()], ambiguous};
}
function expected(rows, localPort, storedIndex) {
    const selected=rows.filter(r=>r.localPort===localPort);
    const group=new Map();
    for(const row of selected) {
        const chassis=row[5]||'', port=row[7]||'', sys=row[9]||'';
        if (!chassis) continue;
        const k=localPort+'|'+chassis;
        if (!group.has(k)) group.set(k,new Set());
        group.get(k).add(port);
    }
    const byCurrent=new Map(), byCandidate=new Map();
    let missing=0;
    const inputIdentity=storedIndex.split('.');
    for(const row of selected) {
        const chassis=row[5]||'', port=row[7]||'', sys=row[9]||'';
        if(!chassis&&!port&&!sys) {missing++;continue;}
        const ident=chassis?'chassis='+chassis:
            port?'port='+port+'|name='+sys:'name='+sys;
        const count=chassis ? (group.get(localPort+'|'+chassis)?.size||0):0;
        const current='0.'+localPort+'.'+hash32(localPort+'|'+ident);
        const proposed='0.'+localPort+'.'+hash32(localPort+'|'+ident+
            (count>1?'|port='+port:''));
        byCurrent.set(current,(byCurrent.get(current)||0)+1);
        byCandidate.set(proposed,(byCandidate.get(proposed)||0)+1);
    }
    return {
        relevantRows:selected.length,
        incompleteIdentityRows:missing,
        differentKeysInInstalledAlgorithm:byCurrent.size,
        differentKeysInCandidateAlgorithm:byCandidate.size,
        storedKeyMatchesInstalled:byCurrent.has(storedIndex),
        storedKeyMatchesCandidate:byCandidate.has(storedIndex),
        ambiguousIdentitySyntax:inputIdentity.length!==3||inputIdentity[0]!=='0'
    };
}
function selftest() {
    const walk=[
      '.1.0.8802.1.1.2.1.4.1.1.5.200.12.7 = STRING: "synthetic-chassis"',
      '.1.0.8802.1.1.2.1.4.1.1.7.200.12.7 = STRING: "eth0"',
      '.1.0.8802.1.1.2.1.4.1.1.9.200.12.7 = STRING: "synthetic-peer"',
      '.1.0.8802.1.1.2.1.4.1.1.5.200.12.8 = STRING: "synthetic-chassis"',
      '.1.0.8802.1.1.2.1.4.1.1.7.200.12.8 = STRING: "eth1"',
      '.1.0.8802.1.1.2.1.4.1.1.9.200.12.8 = STRING: "synthetic-peer"'
    ].join('\n');
    const data=parseWalk(walk);
    if(data.rows.length!==2||data.ambiguous!==0) throw new Error('SNMP numeric walk parser');
    const id='0.12.'+hash32('12|chassis=synthetic-chassis');
    const two=expected(data.rows,'12',id);
    if(two.relevantRows!==2||two.differentKeysInInstalledAlgorithm!==1||
        two.differentKeysInCandidateAlgorithm!==2||!two.storedKeyMatchesInstalled||
        two.storedKeyMatchesCandidate) throw new Error('multiport identity invariants');
    const one=expected(data.rows.slice(0,1),'12',id);
    if(!one.storedKeyMatchesInstalled||!one.storedKeyMatchesCandidate) {
        throw new Error('single port installed compatibility');
    }
    const hexWalk=[
      '.1.0.8802.1.1.2.1.4.1.1.5.20.1.1 = Hex-STRING: 02 00 aa BB 10 7F',
      '.1.0.8802.1.1.2.1.4.1.1.7.20.1.1 = STRING: "port-a"',
      '.1.0.8802.1.1.2.1.4.1.1.9.20.1.1 = STRING: "same-peer"',
      '.1.0.8802.1.1.2.1.4.1.1.5.20.2.1 = Hex-STRING: 02 00 aa BB 20 7F',
      '.1.0.8802.1.1.2.1.4.1.1.7.20.2.1 = STRING: "port-b"',
      '.1.0.8802.1.1.2.1.4.1.1.9.20.2.1 = STRING: "same-peer"'
    ].join('\n');
    const hexParsed=parseWalk(hexWalk);
    if(hexParsed.ambiguous!==0||hexParsed.rows.length!==2) {
        throw new Error('opaque HEX-STRING parsing');
    }
    for(const row of hexParsed.rows) {
        const installed='0.'+row.localPort+'.'+hash32(row.localPort+'|chassis='+row[5]);
        const outcome=expected(hexParsed.rows,row.localPort,installed);
        if(!outcome.storedKeyMatchesInstalled||!outcome.storedKeyMatchesCandidate||
            outcome.relevantRows!==1) {
            throw new Error('binary chassis keeps Zabbix text identity');
        }
    }
    const invalidHex=parseWalk('.1.0.8802.1.1.2.1.4.1.1.5.20.1.1 = Hex-STRING: 02 0Z');
    if(invalidHex.ambiguous!==1) throw new Error('malformed binary must fail closed');
    console.log('LLDP_RAW_IDENTITY_SELF_TEST=PASS');
}
function main(args) {
    if(args.length===1&&args[0]==='--self-test'){selftest();return;}
    if(args.length!==6||args[0]!=='--walk'||args[2]!=='--local-port'||
        args[4]!=='--stored-index') {
        console.error('Usage: node tools/nt-lldp-raw-identity-check.mjs --walk PRIVATE_FILE --local-port LOCAL_NUM --stored-index 0.LOCAL_NUM.STABLE_ID');
        process.exitCode=2;return;
    }
    const [,file,,localPort,,stored]=args;
    if(!/^\d+$/.test(localPort)||!/^0\.\d+\.\d+$/.test(stored)||
        stored.split('.')[1]!==localPort) {
        console.error('LLDP_RAW_IDENTITY_RESULT=INVALID_INDEX');
        process.exitCode=2;return;
    }
    let walk;
    try {walk=readFileSync(file,'utf8');}
    catch {console.error('LLDP_RAW_IDENTITY_RESULT=UNREADABLE_PRIVATE_INPUT');process.exitCode=2;return;}
    const parsed=parseWalk(walk);
    const outcome=expected(parsed.rows,localPort,stored);
    const result={
        result:parsed.ambiguous||outcome.incompleteIdentityRows||
            !outcome.relevantRows?'INCONCLUSIVE':'COMPARED',
        relevantRows:outcome.relevantRows,
        incompleteIdentityRows:outcome.incompleteIdentityRows,
        unsupportedOrAmbiguousOidLines:parsed.ambiguous,
        installedKeyMatches:outcome.storedKeyMatchesInstalled,
        candidateKeyMatches:outcome.storedKeyMatchesCandidate,
        distinctInstalledKeys:outcome.differentKeysInInstalledAlgorithm,
        distinctCandidateKeys:outcome.differentKeysInCandidateAlgorithm
    };
    console.log('LLDP_RAW_IDENTITY_LOCAL_SUMMARY='+JSON.stringify(result));
    if(result.result==='INCONCLUSIVE')process.exitCode=3;
}
main(process.argv.slice(2));
