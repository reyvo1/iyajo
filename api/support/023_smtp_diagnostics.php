<?php
/** TAMASYA V137 canonical internal support module: diagnostik pengiriman email SMTP.
 *  Dipakai audit nota (queue id) dan panel "Tes Email SMTP" di Pengaturan. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

/** Ambil queue id dari balasan SMTP 250 (Exim id=..., Postfix queued as, Exchange InternalId). */
function tamasyaSmtpExtractQueueId(string $response): string {
    $response = trim($response);
    if ($response === '') return '';
    if (preg_match('/\bid=([A-Za-z0-9][A-Za-z0-9._-]*)/', $response, $m)) return $m[1];
    if (preg_match('/queued as ([A-Za-z0-9][A-Za-z0-9._-]*)/i', $response, $m)) return $m[1];
    if (preg_match('/InternalId=([^\],;]+)/i', $response, $m)) return trim($m[1]);
    return '';
}

/** Ringkas satu balasan server jadi satu baris aman untuk log/audit/UI. */
function tamasyaSmtpSanitizeResponse(string $response, int $max = 300): string {
    $clean = preg_replace('/[\r\n\t]+/', ' ', trim($response)) ?? '';
    $clean = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $clean) ?? '';
    $clean = trim($clean);
    if ($max > 3 && strlen($clean) > $max) $clean = substr($clean, 0, $max - 3) . '...';
    return $clean;
}

/** Temuan konfigurasi SMTP dari sisi server (tanpa pernah membocorkan password).
 *  $decryptedPassword = hasil decryptStoredSecret() dari kolom smtp_password. */
function tamasyaSmtpConfigFindings(array $config, string $decryptedPassword): array {
    $host = trim((string)($config['smtp_host'] ?? $config['smtpHost'] ?? ''));
    $port = (int)($config['smtp_port'] ?? $config['smtpPort'] ?? 587);
    $secure = strtolower(trim((string)($config['smtp_secure'] ?? $config['smtpSecure'] ?? 'tls')));
    $user = trim((string)($config['smtp_user'] ?? $config['smtpUser'] ?? ''));
    $from = trim((string)($config['smtp_from'] ?? $config['smtpFrom'] ?? ''));
    $storedPass = (string)($config['smtp_password'] ?? $config['smtpPassword'] ?? '');
    $findings = [];
    $add = function (string $code, string $severity, string $message) use (&$findings): void {
        $findings[] = ['code' => $code, 'severity' => $severity, 'message' => $message];
    };

    if ($host === '') {
        $add('host_missing', 'fail', 'Host SMTP belum diisi. Email resmi akan memakai cadangan PHP mail() hosting yang status pengirimannya TIDAK terverifikasi.');
        return $findings;
    }
    if (!filter_var($host, FILTER_VALIDATE_IP)
        && !preg_match('/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/', $host)) {
        $add('host_invalid', 'fail', 'Host SMTP tidak valid: ' . tamasyaSmtpSanitizeResponse($host, 120));
        return $findings;
    }
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        $add('from_missing', 'fail', 'Alamat pengirim (From) belum valid/terisi; server SMTP yang ketat akan menolak MAIL FROM.');
    } else {
        $fromDomain = strtolower((string)substr((string)strrchr($from, '@'), 1));
        $hostDomain = strtolower($host);
        if ($fromDomain !== '' && $hostDomain !== '' && $fromDomain !== $hostDomain
            && !str_contains($hostDomain, $fromDomain) && !str_contains($fromDomain, $hostDomain)) {
            $add('from_domain_mismatch', 'warn', 'Domain pengirim (' . $fromDomain . ') berbeda dari host SMTP (' . $hostDomain . '). Pastikan SPF/DKIM domain pengirim mengizinkan server ini, atau email bisa masuk spam/ditolak diam-diam.');
        }
    }
    if ($user !== '' && $decryptedPassword === '') {
        if ($storedPass !== '') {
            $add('password_undecryptable', 'fail', 'Username SMTP terisi tetapi password tersimpan tidak dapat didekripsi (kunci enkripsi aplikasi berubah). Simpan ulang password SMTP di form ini, lalu tes lagi.');
        } else {
            $add('password_missing', 'fail', 'Username SMTP terisi tetapi password kosong. Isi password SMTP lalu tes lagi.');
        }
    }
    if ($user === '') {
        $add('no_auth', 'warn', 'Tanpa username/password SMTP: hanya server relay lokal (PHP mail) yang bisa dipakai, dan pengiriman langsung umumnya ditolak/di-spam.');
    }
    if ($secure === 'ssl' && $port !== 465) {
        $add('pair_ssl', 'warn', 'Mode SSL biasanya memakai port 465, saat ini port ' . $port . '. Periksa pasangan keamanan/port di hosting.');
    } elseif ($secure === 'tls' && !in_array($port, [587, 25, 2525], true)) {
        $add('pair_tls', 'warn', 'Mode STARTTLS biasanya memakai port 587/25/2525, saat ini port ' . $port . '. Periksa pasangan keamanan/port di hosting.');
    } elseif ($secure === 'none') {
        $add('insecure', 'warn', 'Mode keamanan "none" tidak terenkripsi; sebagian besar hosting menolak relay tanpa TLS.');
    }
    if (!$findings) {
        $add('config_ok', 'ok', 'Konfigurasi dasar SMTP terisi lengkap.');
    }
    return $findings;
}

/** Verdict dari langkah-langkah probe: kegagalan pertama yang ditemukan. */
function tamasyaSmtpVerdictFromSteps(array $steps): array {
    $hasWarn = false;
    foreach ($steps as $step) {
        $status = (string)($step['status'] ?? '');
        if ($status === 'fail') {
            $code = (string)($step['code'] ?? '');
            return ['ok' => false, 'verdict' => $code !== '' ? $code : 'failed'];
        }
        if ($status === 'warn') $hasWarn = true;
    }
    return ['ok' => true, 'verdict' => $hasWarn ? 'smtp_ready_with_warnings' : 'smtp_ready'];
}

/** Kalimat ringkas berbahasa Indonesia untuk setiap verdict diagnostik. */
function tamasyaSmtpVerdictMessage(string $verdict): string {
    $messages = [
        'smtp_ready' => 'SMTP siap: server menerima koneksi, autentikasi, dan pesan uji dari node Primary aktif.',
        'smtp_ready_with_warnings' => 'SMTP dapat dipakai, tetapi ada peringatan konfigurasi — baca daftar temuan di bawah.',
        'gate' => 'Diagnostik hanya boleh dijalankan dari node Primary aktif dengan lease valid.',
        'host_missing' => 'Host SMTP belum diisi — email resmi akan jatuh ke cadangan PHP mail() yang statusnya tidak terverifikasi.',
        'host_invalid' => 'Host SMTP tidak valid. Perbaiki di Pengaturan lalu tes lagi.',
        'from_missing' => 'Alamat pengirim (From) belum valid. Isi "Email Pengirim" lalu tes lagi.',
        'password_missing' => 'Password SMTP kosong padahal username terisi. Isi password SMTP lalu tes lagi.',
        'password_undecryptable' => 'Password SMTP tersimpan tidak dapat didekripsi (kunci enkripsi aplikasi berubah). Simpan ulang password SMTP, lalu tes lagi.',
        'no_auth' => 'SMTP tanpa kredensial hanya bisa untuk relay lokal.',
        'dns_failed' => 'Nama host SMTP tidak dapat di-resolve dari server ini.',
        'connect_failed' => 'Tidak dapat terhubung ke host SMTP. Periksa host/port dan pastikan port keluar tidak diblokir firewall hosting.',
        'greeting_failed' => 'Koneksi terbentuk tetapi server tidak mengirim salam SMTP.',
        'tls_failed' => 'TLS/STARTTLS gagal (sertifikat server ditolak atau pasangan mode keamanan/port salah).',
        'auth_failed' => 'Server menolak username/password SMTP. Periksa kredensial email di hosting.',
        'sender_rejected' => 'Server menolak alamat pengirim (MAIL FROM). Pastikan alamat pengirim milik domain yang dikelola server SMTP.',
        'recipient_rejected' => 'Server menolak alamat tujuan uji. Coba alamat lain.',
        'data_rejected' => 'Server menolak pesan uji di tahap DATA (lihat balasan server).',
        'failed' => 'Diagnostik gagal; periksa detail langkah di bawah.',
    ];
    return $messages[$verdict] ?? 'Diagnostik selesai dengan status ' . $verdict . '.';
}

/** Info sertifikat TLS peer (bila tersedia) sebagai langkah hasil probe. */
function tamasyaSmtpPeerCertificateStep($socket): ?array {
    try {
        if (!function_exists('openssl_x509_parse')) return null;
        $params = @stream_context_get_params($socket);
        $cert = is_array($params) ? ($params['options']['ssl']['peer_certificate'] ?? null) : null;
        if (!$cert) return null;
        $parsed = @openssl_x509_parse($cert);
        if (!is_array($parsed)) return null;
        $cn = (string)($parsed['subject']['CN'] ?? '');
        $issuer = (string)($parsed['issuer']['CN'] ?? ($parsed['issuer']['O'] ?? ''));
        $validTo = isset($parsed['validTo_time_t']) ? date('Y-m-d', (int)$parsed['validTo_time_t']) : '';
        $days = isset($parsed['validTo_time_t']) ? (int)floor(((int)$parsed['validTo_time_t'] - time()) / 86400) : null;
        $status = ($days !== null && $days < 14) ? 'warn' : 'ok';
        return [
            'stage' => 'sertifikat_tls',
            'status' => $status,
            'detail' => 'Sertifikat server: CN=' . $cn . ', penerbit=' . $issuer
                . ($validTo !== '' ? ', berlaku sampai ' . $validTo : '')
                . ($days !== null ? ' (' . $days . ' hari lagi)' : ''),
            'response' => '',
            'code' => $status === 'warn' ? 'certificate_expiring' : '',
        ];
    } catch (Throwable $e) {
        return null;
    }
}

/** Hasil akhir probe dengan verdict dari langkah-langkah. */
function tamasyaSmtpProbeResult(array $steps, string $queueId, string $acceptedResponse, float $startedAt): array {
    $mapped = tamasyaSmtpVerdictFromSteps($steps);
    return [
        'ok' => $mapped['ok'],
        'verdict' => $mapped['verdict'],
        'steps' => $steps,
        'queueId' => $queueId,
        'acceptedResponse' => $acceptedResponse,
        'latencyMs' => max(1, (int)round((microtime(true) - $startedAt) * 1000)),
    ];
}

/** Probe SMTP bertahap dengan jejak lengkap; mengirim email uji bila $sendTest
 *  dan $to valid. Tidak pernah menuliskan password ke hasil. */
function tamasyaSmtpProbeExchange(array $config, string $to, bool $sendTest = true): array {
    $startedAt = microtime(true);
    $host = trim((string)($config['smtp_host'] ?? $config['smtpHost'] ?? ''));
    $port = (int)($config['smtp_port'] ?? $config['smtpPort'] ?? 587);
    $secure = strtolower(trim((string)($config['smtp_secure'] ?? $config['smtpSecure'] ?? 'tls')));
    $user = trim((string)($config['smtp_user'] ?? $config['smtpUser'] ?? ''));
    $from = trim((string)($config['smtp_from'] ?? $config['smtpFrom'] ?? ''));
    $storedPass = (string)($config['smtp_password'] ?? $config['smtpPassword'] ?? '');
    $pass = function_exists('decryptStoredSecret') ? (string)decryptStoredSecret($storedPass) : $storedPass;

    $steps = [];
    $push = function (string $stage, string $status, string $detail = '', string $response = '', string $code = '') use (&$steps): void {
        $steps[] = ['stage' => $stage, 'status' => $status, 'detail' => $detail, 'response' => $response, 'code' => $code];
    };

    $findings = tamasyaSmtpConfigFindings($config, $pass);
    $fails = array_values(array_filter($findings, fn ($f) => $f['severity'] === 'fail'));
    foreach ($findings as $finding) {
        $push(
            'konfigurasi',
            $finding['severity'] === 'fail' ? 'fail' : ($finding['severity'] === 'warn' ? 'warn' : 'ok'),
            (string)$finding['message'],
            '',
            $finding['severity'] === 'fail' ? (string)$finding['code'] : ''
        );
    }
    if ($fails) {
        return tamasyaSmtpProbeResult($steps, '', '', $startedAt);
    }

    if (!filter_var($host, FILTER_VALIDATE_IP)) {
        $resolved = gethostbyname($host);
        if (!filter_var($resolved, FILTER_VALIDATE_IP)) {
            $push('dns', 'fail', 'Nama host "' . tamasyaSmtpSanitizeResponse($host, 120) . '" tidak dapat di-resolve dari server ini.', '', 'dns_failed');
            return tamasyaSmtpProbeResult($steps, '', '', $startedAt);
        }
        $push('dns', 'ok', 'Host terselesaikan ke ' . $resolved . '.');
    }

    $tlsContext = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => $host,
            'SNI_enabled' => true,
            'disable_compression' => true,
        ],
    ]);
    $transport = $secure === 'ssl' ? 'tls://' : 'tcp://';
    $connectStarted = microtime(true);
    $socket = @stream_socket_client($transport . $host . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $tlsContext);
    if (!$socket) {
        $push('koneksi', 'fail', 'Koneksi ke ' . $host . ':' . $port . ' gagal: ' . tamasyaSmtpSanitizeResponse((string)$errstr, 160) . ' (' . (int)$errno . ')', '', 'connect_failed');
        return tamasyaSmtpProbeResult($steps, '', '', $startedAt);
    }
    $push('koneksi', 'ok', 'Terhubung ke ' . $host . ':' . $port . ' dalam ' . max(1, (int)round((microtime(true) - $connectStarted) * 1000)) . ' ms.');
    stream_set_timeout($socket, 20);

    $greet = fgets($socket, 515);
    if ($greet === false || trim((string)$greet) === '') {
        $push('salam', 'fail', 'Server tidak mengirim salam SMTP; koneksi mungkin diblokir firewall hosting.', '', 'greeting_failed');
        fclose($socket);
        return tamasyaSmtpProbeResult($steps, '', '', $startedAt);
    }
    $push('salam', 'ok', 'Salam server: ' . tamasyaSmtpSanitizeResponse((string)$greet, 200));

    $ehloHost = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (str_contains($ehloHost, ':')) $ehloHost = explode(':', $ehloHost, 2)[0];
    fwrite($socket, "EHLO " . $ehloHost . "\r\n");
    $ehlo = '';
    while (($line = fgets($socket, 515)) !== false) {
        $ehlo .= $line;
        if (substr($line, 3, 1) === ' ') break;
    }
    $push('ehlo', 'ok', 'Kapabilitas server: ' . tamasyaSmtpSanitizeResponse($ehlo, 400));

    if ($secure === 'tls') {
        fwrite($socket, "STARTTLS\r\n");
        $tlsGreet = fgets($socket, 515);
        if (strpos((string)$tlsGreet, '220') === false) {
            $push('starttls', 'fail', 'STARTTLS ditolak: ' . tamasyaSmtpSanitizeResponse((string)$tlsGreet, 200), tamasyaSmtpSanitizeResponse((string)$tlsGreet, 200), 'tls_failed');
            fclose($socket);
            return tamasyaSmtpProbeResult($steps, '', '', $startedAt);
        }
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            $push('starttls', 'fail', 'Enkripsi TLS gagal diaktifkan (sertifikat server kemungkinan ditolak verifikasi).', '', 'tls_failed');
            fclose($socket);
            return tamasyaSmtpProbeResult($steps, '', '', $startedAt);
        }
        $push('starttls', 'ok', 'STARTTLS aktif.');
        $certStep = tamasyaSmtpPeerCertificateStep($socket);
        if ($certStep) $steps[] = $certStep;
        fwrite($socket, "EHLO " . $ehloHost . "\r\n");
        $ehloTls = '';
        while (($line = fgets($socket, 515)) !== false) {
            $ehloTls .= $line;
            if (substr($line, 3, 1) === ' ') break;
        }
        $push('ehlo_tls', 'ok', 'Kapabilitas setelah TLS: ' . tamasyaSmtpSanitizeResponse($ehloTls, 400));
    } elseif ($secure === 'ssl') {
        $certStep = tamasyaSmtpPeerCertificateStep($socket);
        if ($certStep) $steps[] = $certStep;
    }

    if ($user !== '' || $pass !== '') {
        fwrite($socket, "AUTH LOGIN\r\n");
        fgets($socket, 515);
        fwrite($socket, base64_encode($user) . "\r\n");
        fgets($socket, 515);
        fwrite($socket, base64_encode($pass) . "\r\n");
        $passRes = fgets($socket, 515);
        if (strpos((string)$passRes, '235') === false) {
            $push('otentikasi', 'fail', 'Otentikasi ditolak: ' . tamasyaSmtpSanitizeResponse((string)$passRes, 200) . '. Periksa username/password SMTP (bila password baru diubah di hosting, simpan ulang di form ini).', tamasyaSmtpSanitizeResponse((string)$passRes, 200), 'auth_failed');
            fclose($socket);
            return tamasyaSmtpProbeResult($steps, '', '', $startedAt);
        }
        $push('otentikasi', 'ok', 'Otentikasi berhasil untuk ' . $user . '.');
    } else {
        $push('otentikasi', 'skip', 'Dilewati: tidak ada kredensial SMTP.');
    }

    fwrite($socket, "MAIL FROM: <$from>\r\n");
    $fromRes = fgets($socket, 515);
    if (!preg_match('/^2\d\d/', (string)$fromRes)) {
        $push('pengirim', 'fail', 'Server menolak pengirim <' . $from . '>: ' . tamasyaSmtpSanitizeResponse((string)$fromRes, 200), tamasyaSmtpSanitizeResponse((string)$fromRes, 200), 'sender_rejected');
        fclose($socket);
        return tamasyaSmtpProbeResult($steps, '', '', $startedAt);
    }
    $push('pengirim', 'ok', 'Pengirim diterima: ' . tamasyaSmtpSanitizeResponse((string)$fromRes, 200));

    $queueId = '';
    $acceptedResponse = '';
    if (!$sendTest || $to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $push('penerima', 'skip', 'Email uji tidak dikirim (alamat tujuan kosong/tidak valid).');
        $push('data', 'skip', 'Tahap penerimaan pesan dilewati.');
    } else {
        fwrite($socket, "RCPT TO: <$to>\r\n");
        $rcptRes = fgets($socket, 515);
        if (strpos((string)$rcptRes, '250') === false && strpos((string)$rcptRes, '251') === false) {
            $push('penerima', 'fail', 'Server menolak tujuan <' . $to . '>: ' . tamasyaSmtpSanitizeResponse((string)$rcptRes, 200), tamasyaSmtpSanitizeResponse((string)$rcptRes, 200), 'recipient_rejected');
            fclose($socket);
            return tamasyaSmtpProbeResult($steps, '', '', $startedAt);
        }
        $push('penerima', 'ok', 'Tujuan diterima: ' . tamasyaSmtpSanitizeResponse((string)$rcptRes, 200));

        fwrite($socket, "DATA\r\n");
        $dataInvite = fgets($socket, 515);
        if (strpos((string)$dataInvite, '354') === false) {
            $push('data', 'fail', 'Server tidak siap menerima isi pesan: ' . tamasyaSmtpSanitizeResponse((string)$dataInvite, 200), tamasyaSmtpSanitizeResponse((string)$dataInvite, 200), 'data_rejected');
            fclose($socket);
            return tamasyaSmtpProbeResult($steps, '', '', $startedAt);
        }
        $testSubject = 'Uji SMTP TAMASYA ' . date('Y-m-d H:i:s');
        $body = "Ini email uji dari panel Pengaturan TAMASYA.\r\n"
            . 'Waktu: ' . date('c') . "\r\n"
            . 'Server: ' . $host . ':' . $port . ' (' . $secure . ")\r\n"
            . "Bila email ini Anda terima, jalur SMTP hotel berfungsi dan nota resmi akan terkirim lewat jalur yang sama.\r\n";
        $headers = "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "To: <$to>\r\n"
            . "From: <$from>\r\n"
            . 'Subject: =?UTF-8?B?' . base64_encode($testSubject) . "?=\r\n"
            . 'Date: ' . date('r') . "\r\n"
            . "X-Mailer: Tamasya-SMTP-Diagnostic\r\n";
        fwrite($socket, $headers . "\r\n" . $body . "\r\n.\r\n");
        $dataRes = fgets($socket, 515);
        if (strpos((string)$dataRes, '250') === false) {
            $push('data', 'fail', 'Pesan uji ditolak server: ' . tamasyaSmtpSanitizeResponse((string)$dataRes, 200), tamasyaSmtpSanitizeResponse((string)$dataRes, 200), 'data_rejected');
            fclose($socket);
            return tamasyaSmtpProbeResult($steps, '', '', $startedAt);
        }
        $queueId = tamasyaSmtpExtractQueueId((string)$dataRes);
        $acceptedResponse = tamasyaSmtpSanitizeResponse((string)$dataRes, 300);
        $push('data', 'ok', 'Pesan uji diterima server' . ($queueId !== '' ? ' (antrean ' . $queueId . ')' : '') . '.');
    }

    fwrite($socket, "QUIT\r\n");
    fclose($socket);
    return tamasyaSmtpProbeResult($steps, $queueId, $acceptedResponse, $startedAt);
}
