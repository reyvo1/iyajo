<?php
/**
 * TAMASYA schema drift doctor (READ-ONLY).
 *
 * Fresh installs and upgrades are provisioned differently: a fresh install imports
 * database_setup.sql through the configurator, while an upgrade needs migrations/*.sql.
 * Those migration runners had no callers, so a property database could silently miss
 * columns until the feature that needed them failed at the moment of use. On shared
 * hosting there is no terminal to inspect the schema, so nothing ever reported it.
 *
 * This module never writes. It compares the live database against the canonical
 * database_setup.sql and reports drift, so the operator can decide when to apply the
 * matching migration with a backup, instead of discovering it from a failed save.
 */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

/** Parse CREATE TABLE statements from the canonical schema file into table => columns.
 *
 * Column order is preserved because the configurator compares the canonical column
 * list against information_schema when it validates a commissioned property.
 */
function tamasyaSchemaDoctorParseCanonical(string $sql): array {
    $tables = [];
    $length = strlen($sql);
    $offset = 0;
    while (($start = stripos($sql, 'CREATE TABLE', $offset)) !== false) {
        // Locate the opening parenthesis of the table body. A non-greedy regex cannot
        // do this: the first ")" after the opening paren usually belongs to a column
        // type such as varchar(500), which silently truncated the body and dropped
        // every column defined after it.
        $open = strpos($sql, '(', $start);
        if ($open === false) break;
        $depth = 0;
        $close = -1;
        for ($i = $open; $i < $length; $i++) {
            $char = $sql[$i];
            if ($char === '(') $depth++;
            elseif ($char === ')') {
                $depth--;
                if ($depth === 0) { $close = $i; break; }
            }
        }
        if ($close === -1) break;

        if (preg_match('/CREATE\s+TABLE\s+`?([A-Za-z0-9_]+)`?\s*$/i', substr($sql, $start, $open - $start), $nameMatch)) {
            $body = substr($sql, $open + 1, $close - $open - 1);
            $names = [];
            foreach (tamasyaSchemaDoctorSplitTopLevel($body) as $definition) {
                if (preg_match('/^\s*`([A-Za-z0-9_]+)`\s+/', $definition, $columnMatch)) {
                    $names[] = $columnMatch[1];
                }
            }
            if ($names) $tables[$nameMatch[1]] = $names;
        }
        $offset = $close + 1;
    }
    return $tables;
}

/** Split a CREATE TABLE body on commas that sit at parenthesis depth zero. */
function tamasyaSchemaDoctorSplitTopLevel(string $body): array {
    $parts = [];
    $depth = 0;
    $current = '';
    $length = strlen($body);
    for ($i = 0; $i < $length; $i++) {
        $char = $body[$i];
        if ($char === '(') $depth++;
        elseif ($char === ')') $depth--;
        if ($char === ',' && $depth === 0) {
            $parts[] = $current;
            $current = '';
            continue;
        }
        $current .= $char;
    }
    if (trim($current) !== '') $parts[] = $current;
    return $parts;
}

/** Compare the live schema against the canonical schema file.
 *
 * Reports only. Extra columns and extra tables are reported separately from missing
 * ones because extra objects are harmless for a doctor run while missing ones break
 * features at the moment they are used.
 */
function tamasyaSchemaDoctorInspect(PDO $pdo): array {
    $sqlPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'database_setup.sql';
    $result = [
        'ok' => false,
        'canonicalReadable' => false,
        'canonicalSha256' => null,
        'tablesExpected' => 0,
        'tablesPresent' => 0,
        'missingTables' => [],
        'missingColumns' => [],
        'extraTables' => [],
        'notes' => [],
    ];
    if (!is_readable($sqlPath)) {
        $result['notes'][] = 'database_setup.sql tidak dapat dibaca pada server ini; perbandingan skema dilewati.';
        return $result;
    }
    $sql = file_get_contents($sqlPath);
    if (!is_string($sql) || $sql === '') {
        $result['notes'][] = 'database_setup.sql kosong atau tidak terbaca.';
        return $result;
    }
    $result['canonicalReadable'] = true;
    $result['canonicalSha256'] = hash('sha256', $sql);

    $expected = tamasyaSchemaDoctorParseCanonical($sql);
    if (!$expected) {
        $result['notes'][] = 'Tidak ada CREATE TABLE yang dapat diurai dari database_setup.sql.';
        return $result;
    }
    $result['tablesExpected'] = count($expected);

    $liveRows = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()")
        ->fetchAll(PDO::FETCH_NUM) ?: [];
    $live = [];
    foreach ($liveRows as $row) $live[(string)$row[0]][] = (string)$row[1];

    foreach ($expected as $table => $columns) {
        if (!isset($live[$table])) {
            $result['missingTables'][] = $table;
            continue;
        }
        $result['tablesPresent']++;
        $present = array_flip($live[$table]);
        $absent = [];
        foreach ($columns as $column) {
            if (!isset($present[$column])) $absent[] = $table . '.' . $column;
        }
        if ($absent) $result['missingColumns'] = array_merge($result['missingColumns'], $absent);
    }
    foreach (array_keys($live) as $table) {
        if (!isset($expected[$table])) $result['extraTables'][] = $table;
    }
    sort($result['extraTables']);

    $drift = count($result['missingTables']) + count($result['missingColumns']);
    $result['ok'] = $drift === 0;
    if ($drift === 0) {
        $result['notes'][] = 'Skema property sesuai dengan database_setup.sql. Tidak ada migrasi yang tertunda.';
    } else {
        $result['notes'][] = 'Ada ' . $drift . ' objek skema yang belum ada. Terapkan berkas migrasi yang sesuai setelah backup, satu per satu. Jangan menjalankan DDL tanpa backup.';
    }
    // Extra objects are informational only: they never break a feature on their own.
    if ($result['extraTables']) {
        $result['notes'][] = count($result['extraTables']) . ' tabel tambahan tidak ada di database_setup.sql. Ini tidak otomatis berarti masalah.';
    }
    return $result;
}