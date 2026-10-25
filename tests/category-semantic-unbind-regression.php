<?php
/** Regression: category semantic unbind (lepas pemakaian) is supported without changing business logic. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$backend = file_get_contents(__DIR__.'/../api/routes/100_catalog_downloads.php');
$frontend = file_get_contents(__DIR__.'/../assets/master-data-workspace.js');
function assertAudit(bool $condition, string $name): void {
    if (!$condition) throw new RuntimeException('FAIL: '.$name);
    echo "PASS ".$name."\n";
}
assertAudit(str_contains($backend, '$unbind = ($systemKey === \'\');'), 'backend recognizes empty systemKey as unbind');
assertAudit(str_contains($backend, 'Melepas pemakaian otomatis dari kategori'), 'backend unbind path exists');
assertAudit(str_contains($backend, "UPDATE categories SET system_key=NULL,is_system=0 WHERE id=?"), 'backend clears system_key and is_system');
assertAudit(str_contains($frontend, 'Tidak ada pemakaian (lepas)'), 'UI offers unbind option');
assertAudit(str_contains($frontend, 'systemKey===undefined||systemKey===null'), 'UI allows empty selection (unbind)');
echo "TOTAL: 5 PASS; 0 FAIL\n";
