<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
require __DIR__.'/../node_sync_support.php';
$n=0;
function checkCursor(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException('FAIL '.$label);$n++;echo 'PASS '.$label.PHP_EOL;}
foreach ([['a','b'],['', 'x'],['room-12','property-A'],['漢字','abc']] as $pair){
    $encoded=tamasyaNodeEncodeCursor($pair);
    checkCursor(tamasyaNodeDecodeCursor($encoded,2)===$pair,'canonical composite cursor roundtrip');
}
checkCursor(tamasyaNodeDecodeCursor('room-12',1)===['room-12'],'single-column cursor unchanged');
foreach([['@bad',2],['eyJ3IjoiMTEifQ',2],[str_repeat('x',2049),1],["bad\nvalue",1],[tamasyaNodeEncodeCursor(['a','b']),3]] as [$token,$cols]){
    $blocked=false;try{tamasyaNodeDecodeCursor($token,$cols);}catch(InvalidArgumentException $e){$blocked=true;}
    checkCursor($blocked,'invalid or unbounded snapshot cursor rejected');
}
checkCursor(tamasyaNodeDecodeCursor('',2)===[],'empty initial cursor compatible');
echo 'HYBRID CURSOR PASS '.$n.PHP_EOL;
