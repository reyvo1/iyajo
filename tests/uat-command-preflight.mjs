import assert from 'node:assert/strict';
import {readFileSync,existsSync,statSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
import {resolve,dirname} from 'node:path';
const root=resolve(dirname(fileURLToPath(import.meta.url)),'..');
const read=(path)=>readFileSync(resolve(root,path),'utf8');
const workflow=read('.github/workflows/tamasya-enterprise-rc1-uat.yml');
const manifest=JSON.parse(read('tests/uat-r164-required-commands.json'));
assert.equal(manifest.sourceCommit,'01cdb22c9f8da0fc92569e4f3bb2196a56bd4adc');
assert.equal(manifest.required.length,54,'Do not silently shrink frozen R16.4 source suites');
assert.equal(new Set(manifest.required).size,manifest.required.length,'Do not duplicate suite entries');
const sourceStep=workflow.split('      - name: Source regression suites')[1]?.split('      - name: Security and RC1 identity invariants')[0];
assert.ok(sourceStep,'Required strict source regression step must exist');
const lines=new Set(sourceStep.split('\n').map(line=>line.trim()));
let checks=0;
for(const cmd of manifest.required){
  assert.ok(lines.has(cmd),`STOP: required UAT command missing from workflow: ${cmd}`);
  const match=cmd.match(/(?:^|\s)(tests\/[A-Za-z0-9_./-]+\.(?:mjs|php|js|py|sh))$/);
  assert.ok(match,`STOP: invalid UAT command contract: ${cmd}`);
  const target=resolve(root,match[1]);
  assert.ok(target.startsWith(resolve(root,'tests')+'/'),`STOP: test escapes source tree: ${cmd}`);
  assert.ok(existsSync(target)&&statSync(target).isFile(),`STOP: workflow refers to MISSING regression: ${match[1]}`);
  checks++;
}
assert.match(workflow,/php: \[\s*'8\.2',\s*'8\.3',\s*'8\.4',\s*'8\.5'\s*\]/,'Four-PHP source gate still required');
assert.match(workflow,/php: \[\s*'8\.4',\s*'8\.5'\s*\]/,'Full dual PHP UAT still required');
assert.match(workflow,/mariadb-1011-compatibility:/,'MariaDB-specific UAT required');
for(const gate of ['mysql-comprehensive-uat:','source-gate:','prd-runtime','mariadb:10.11','tests/uat_rc1/run_two_node_complete.sh']){
  assert.ok(workflow.includes(gate),`STOP: missing critical workflow contract: ${gate}`);
}
assert.ok(sourceStep.includes('set -euo pipefail'),'Strict shell gate must fail fast');
assert.ok(!/continue-on-error:\s*true/.test(sourceStep),'Regression step must not allow failure');
console.log(`R16.4 UAT PREFLIGHT PASS: ${checks}/${manifest.required.length} preserved suites, all files present; 4 PHP source jobs, 2 full UAT jobs, MariaDB and Hybrid/VPS gate present.`);
