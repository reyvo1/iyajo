<?php
declare(strict_types=1);
// RFC 5321 line-length regression for the shared SMTP client: no-attachment HTML
// (nota kamar / laporan grup) wajib ter-base64 <=998 oktet per baris wire.
// Menggerakkan sendSmtpMail() asli melawan sink loopback; tanpa DB, tanpa host luar.
// Mode anak (sink) memakai file ini juga: php tests/smtp-line-length-regression.php --sink <capture>
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

if (($argv[1] ?? '') === '--sink') {
    $capture = (string)($argv[2] ?? '');
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!$server) { fwrite(STDERR, "sink bind failed: {$errstr}\n"); exit(2); }
    $name = (string)stream_socket_get_name($server, false);
    file_put_contents($capture.'.ready', (string)substr($name, strrpos($name, ':') + 1));
    $conn = @stream_socket_accept($server, 15);
    if (!$conn) { fclose($server); exit(3); }
    fwrite($conn, "220 sink.localhost ESMTP\r\n");
    $inData = false;
    $data = '';
    while (($line = fgets($conn)) !== false) {
        if ($inData) {
            if (rtrim($line, "\r\n") === '.') { fwrite($conn, "250 OK queued\r\n"); $inData = false; continue; }
            $data .= $line;
            continue;
        }
        $cmd = strtoupper(trim($line));
        if (str_starts_with($cmd, 'EHLO') || str_starts_with($cmd, 'HELO')) fwrite($conn, "250-sink.localhost\r\n250 OK\r\n");
        elseif (str_starts_with($cmd, 'MAIL FROM')) fwrite($conn, "250 OK\r\n");
        elseif (str_starts_with($cmd, 'RCPT TO')) fwrite($conn, "250 OK\r\n");
        elseif ($cmd === 'DATA') { fwrite($conn, "354 End data with <CR><LF>.<CR><LF>\r\n"); $inData = true; }
        elseif ($cmd === 'QUIT') { fwrite($conn, "221 Bye\r\n"); break; }
        else fwrite($conn, "250 OK\r\n");
    }
    fclose($conn);
    fclose($server);
    file_put_contents($capture, $data);
    exit(0);
}

$root = dirname(__DIR__);
$passed = 0;
function checkSmtp(bool $ok, string $name): void {
    global $passed;
    if (!$ok) { fwrite(STDERR, "FAIL {$name}\n"); exit(1); }
    $passed++;
    echo "PASS {$name}\n";
}

$tmp = sys_get_temp_dir().'/tamasya-smtp-line-'.getmypid();
$capture = $tmp.'.eml';
$ready = $capture.'.ready';
@unlink($capture);
@unlink($ready);
$proc = proc_open([PHP_BINARY, __FILE__, '--sink', $capture], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($proc)) { fwrite(STDERR, "FAIL loopback smtp sink could not start\n"); exit(1); }
$deadline = microtime(true) + 8;
while (!is_file($ready) && microtime(true) < $deadline) { usleep(20000); }
if (!is_file($ready)) {
    proc_terminate($proc);
    proc_close($proc);
    fwrite(STDERR, "FAIL loopback smtp sink did not become ready\n");
    exit(1);
}
$port = (int)trim((string)file_get_contents($ready));

define('TAMASYA_API_ENTRY', true);
function tamasyaExternalSideEffectsAllowed(): bool { return true; }
function decryptStoredSecret(string $value): string { return $value; }
require $root.'/api/modules/comms/050_integrations_telegram_mail.php';

$html = '<html><body><table cellpadding="0"><tr><td>'.str_repeat('Baris nota kamar tanpa jeda baris sama sekali. ', 150).'</td></tr></table></body></html>';
$fixtureMax = 0;
foreach (explode("\n", $html) as $fixtureLine) { $fixtureMax = max($fixtureMax, strlen($fixtureLine)); }
checkSmtp($fixtureMax > 998, "fixture HTML is a single {$fixtureMax}-octet line (would breach the pre-fix wire)");

try {
    $sent = sendSmtpMail('tamu@uji.test', 'Uji batas baris RFC 5321', $html, [
        'smtp_host' => '127.0.0.1',
        'smtp_port' => $port,
        'smtp_user' => '',
        'smtp_password' => '',
        'smtp_secure' => 'none',
        'smtp_from' => 'nota@uji.test',
    ]);
} catch (Throwable $e) {
    proc_terminate($proc);
    fwrite(STDERR, 'FAIL sendSmtpMail threw against the loopback sink: '.$e->getMessage()."\n");
    exit(1);
}

$deadline = microtime(true) + 8;
while (!is_file($capture) && microtime(true) < $deadline) { usleep(20000); }
$raw = (string)@file_get_contents($capture);

checkSmtp($sent === true, 'sendSmtpMail reports success against the loopback sink');
checkSmtp($raw !== '', 'loopback sink captured the DATA payload');

$wireMax = 0;
foreach (explode("\r\n", $raw) as $wireLine) { $wireMax = max($wireMax, strlen($wireLine)); }
checkSmtp($wireMax <= 998, "every transmitted line stays within the RFC 5321 998-octet limit (max {$wireMax})");
checkSmtp($wireMax <= 78, 'no-attachment path wraps the wire at 76 base64 columns plus CRLF');

$parts = explode("\r\n\r\n", $raw, 2);
checkSmtp(count($parts) === 2, 'payload separates headers from the encoded body');
checkSmtp(str_contains($parts[0] ?? '', 'Content-Transfer-Encoding: base64'), 'no-attachment path declares base64 transfer encoding');
$decoded = base64_decode((string)preg_replace('/\s+/', '', $parts[1] ?? ''), true);
checkSmtp($decoded === $html, 'body round-trips through base64 without data loss');

proc_terminate($proc);
foreach ($pipes as $pipe) { if (is_resource($pipe)) fclose($pipe); }
proc_close($proc);
@unlink($capture);
@unlink($ready);

echo "SMTP line length: {$passed} passed; 0 failed.\n";
