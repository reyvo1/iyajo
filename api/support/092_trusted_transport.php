<?php
/**
 * Protected maintenance endpoints: only the server's TLS state or an explicitly
 * trusted reverse proxy may attest to HTTPS. An arbitrary client can set
 * X-Forwarded-Proto; by itself that header MUST NOT grant transport trust.
 *
 * TAMASYA_TRUSTED_PROXY_IPS is an explicit comma-separated list of IPs, not a
 * wildcard or a CIDR inferred from untrusted forwarded headers.
 */
if (!function_exists('tamasyaProtectedEndpointUsesHttps')) {
    function tamasyaProtectedEndpointUsesHttps(array $server, string $trustedProxyIps = ''): bool {
        $native = strtolower(trim((string)($server['HTTPS'] ?? '')));
        if ($native !== '' && $native !== 'off' && $native !== '0') return true;
        if ((string)($server['SERVER_PORT'] ?? '') === '443') return true;

        $remote = trim((string)($server['REMOTE_ADDR'] ?? ''));
        if ($remote === '' || !filter_var($remote, FILTER_VALIDATE_IP)) return false;
        $trusted = array_filter(array_map('trim', explode(',', $trustedProxyIps)),
            static fn(string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false);
        if (!in_array($remote, $trusted, true)) return false;
        // Multiple values ('https,http') indicate a proxy chain whose trust
        // has not been established. Reject rather than choosing an entry.
        return strtolower(trim((string)($server['HTTP_X_FORWARDED_PROTO'] ?? ''))) === 'https';
    }
}
