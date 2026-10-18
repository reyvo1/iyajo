<?php
/** Legenda status kamar (titik warna): setiap dot wajib (a) dirender di rooms.js
 *  DAN (b) punya rule CSS nyata di app-core.css. Bug 18 Okt 2026: class
 *  bg-cyan-400 dipakai JS tetapi rule-nya tidak pernah di-generate Tailwind,
 *  sehingga titik Housekeeping/QC tak terlihat di semua deployment. */
if (PHP_SAPI !== 'cli') exit(1);
$root=dirname(__DIR__);
$rooms=file_get_contents($root.'/assets/chunks/rooms.js');
$css=file_get_contents($root.'/assets/app-core.css');
$start=strpos($rooms,'flex gap-2 text-xs flex-wrap');
$end=$start===false?false:strpos($rooms,'children:"Servis/Blocker"',$start);
if($start===false||$end===false)throw new RuntimeException('FAIL container legenda status kamar tidak ditemukan di rooms.js');
$legend=substr($rooms,$start,$end-$start+40);
$checks=[
    'Legenda memuat titik Tersedia (bg-emerald-500)'=>str_contains($legend,'bg-emerald-500'),
    'Legenda memuat titik Terisi (bg-indigo-500)'=>str_contains($legend,'bg-indigo-500'),
    'Legenda memuat titik Housekeeping/QC (bg-cyan-400)'=>str_contains($legend,'bg-cyan-400'),
    'Legenda memuat titik Servis/Blocker (bg-rose-500)'=>str_contains($legend,'bg-rose-500'),
    'Rule .bg-emerald-500 ada di app-core.css'=>str_contains($css,'.bg-emerald-500{'),
    'Rule .bg-indigo-500 ada di app-core.css'=>str_contains($css,'.bg-indigo-500{'),
    'Rule .bg-cyan-400 ada di app-core.css'=>str_contains($css,'.bg-cyan-400{'),
    'Rule .bg-rose-500 ada di app-core.css'=>str_contains($css,'.bg-rose-500{'),
];
$passed=0;
foreach($checks as $name=>$ok){if(!$ok)throw new RuntimeException('FAIL '.$name);echo 'PASS '.$name.PHP_EOL;$passed++;}
echo 'Cosmetic legend dots: '.$passed.' PASS, 0 FAIL'.PHP_EOL;
