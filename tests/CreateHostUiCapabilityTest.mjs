// SPDX-License-Identifier: AGPL-3.0-or-later
// Fail-closed source and compiled-bundle check for ghost creation capability.
// No Zabbix API calls, network requests or customer data.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const load = p => readFileSync(new URL('../' + p, import.meta.url), 'utf8');
const controller = load('actions/NetworkTopologyView.php');
const action = load('actions/NetworkTopologyCreateHost.php');
const view = load('views/network.topology.view.php');
const menu = load('assets/js/modules/context-menu.js');
const bundle = load('assets/js/dist/nt-bundle.js');

assert.match(controller, /'can_create_host'\s*=>[\s\S]*?UI_CONFIGURATION_HOSTS/);
assert.match(action, /function checkPermissions\(\)[\s\S]*?UI_CONFIGURATION_HOSTS/);
assert.match(view, /'can_create_host'\s*=>[\s\S]*?\['can_create_host'\]/);
assert.match(menu, /window\.NT_CONFIG\.can_create_host\s*===\s*true/);
assert.ok(!/window\.NT_CONFIG\s*&&\s*window\.NT_CONFIG\.can_edit\)\s*\{\s*_ctx\.appendChild\(_ctxRow\(t\('ghost\.create\.menu'/.test(menu),
    'create-host menu must not rely on generic can_edit permission');
assert.ok(bundle.includes('can_create_host'),
    'compiled JS bundle is stale: run npm run build and commit the rebuilt bundle');
console.log('CreateHost UI capability source/bundle checks PASS');
