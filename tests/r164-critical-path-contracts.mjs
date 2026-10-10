/* Additional checks; historical suites remain untouched. These source contracts are NOT physical E2E proof. */
import fs from 'node:fs';
import assert from 'node:assert/strict';
const read=(p)=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');
const wf=read('.github/workflows/tamasya-enterprise-rc1-uat.yml');
const tg=read('api/routes/080_telegram_webhook.php');
const keys=read('api/routes/090_operations_communications.php');
const hybrid=read('api/support/090_security_cluster_smartlock.php');
const sharedKey=read('api/support/091_room_access_issue.php');
const cron=read('maintenance_cron.php');
const manifest=JSON.parse(read('tests/uat-r164-required-commands.json'));
const a=(ok,msg)=>{assert.ok(ok,`FAIL ${msg}`);console.log('PASS',msg)};
for(const cmd of manifest.required){a(wf.includes(cmd),`Historical UAT retained: ${cmd}`)}
a(new Set(manifest.required).size===54,'Historical suite count remains exactly 54');
for (const cmd of ['php tests/durable-history-retention-regression.php','php tests/receipt-compaction-cursor-regression.php','node tests/uat-command-preflight.mjs']) a(wf.includes(cmd),`Historical P1 test retained: ${cmd}`);
a(wf.split('ref: ${{ github.sha }}').length-1===4,'All four checkout jobs pinned to event SHA');
a(wf.split('test "$(git rev-parse HEAD)" = "$GITHUB_SHA"').length-1===4,'All four checkout jobs fail closed on SHA mismatch');
for(const php of ['8.2','8.3','8.4','8.5']) a(wf.includes(`'${php}'`),`Source PHP ${php} remains in matrix`);
for(const stage of ['Full hotel finance, booking, UI and Telegram UAT','MariaDB 10.11','PRD VPS, realtime and SaaS','Two-node','Telegram end-to-end operational','Historical backfill split tax accounting']){
  // Some stage names appear with different title case or in script steps.
  const hay=wf.toLowerCase();
  const needle=stage.toLowerCase();
  a(hay.includes(needle),`Workflow retains critical stage: ${stage}`)
}
a(tg.includes('claimTelegramUpdate($pdo, $telegramUpdateId)')&&tg.includes('tamasyaAcquirePrimaryMutationLock'),'Telegram webhook holds primary mutation/dedupe locks');
a(keys.includes("$command === 'key-issue'")&&keys.includes('tamasyaIssueRoomAccess($pdo,$loggedInStaff,$bookingId')&&tg.includes("tamasyaIssueRoomAccess($pdo,$loggedInStaff,$bookingId"),'Web and Telegram delegate to the same canonical room-access service');
a(sharedKey.includes('tamasyaRequireOpenShiftForRoomAccessIssue')&&sharedKey.includes('require_payment_before_key_issue')&&sharedKey.includes('FOR UPDATE')&&sharedKey.includes('claimTelegramMutation')&&sharedKey.includes('completeTelegramMutation'),'Canonical key-issue retains shift/payment/booking locks plus atomic dedupe');
a(sharedKey.includes("if(($physicalOnly || $source==='telegram') && $mode!=='physical')")&&tg.includes("'key_issue_review:'")&&tg.includes("'key_issue_confirm:'"),'Telegram key handover has two-step physical-only flow; smart PIN remains restricted');
a(tg.includes("'key_status:'")&&tg.includes('validasi pembayaran, shift, dan smart-lock tidak dilewati'),'Telegram key status remains read-only; no unsafe issuance path');
a(hybrid.includes('function claimTelegramUpdate(')&&hybrid.includes('FOR UPDATE'),'Telegram claims serialized by DB row');
a(!/DELETE\s+FROM\s+telegram_update_log\b/i.test(cron),'Telegram update ID cannot age out by cron');
a(!/DELETE\s+FROM\s+(?:sync_operations|request_operation_receipts)\b/i.test(cron),'Hybrid/receipt operation IDs not deleted by cron');
console.log('R16.4 CRITICAL PATH SOURCE CONTRACTS PASS (not live integration signoff)');
