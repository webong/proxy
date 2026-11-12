<?php

declare(strict_types=1);

namespace Webong\Proxy\Connection;

use Webong\Proxy\Tcp\DialException;

/** Verified TLS to the proxy, within the existing monotonic setup deadline. */
final class ProxyTlsSession
{
    public static function connect($stream, string $host, float $deadline, ?string $caFile): void
    {
        if (! extension_loaded('openssl')) {
            throw new DialException('HTTPS proxies require ext-openssl.');
        }
        $options = [
            'verify_peer' => true, 'verify_peer_name' => true,
            'allow_self_signed' => false, 'peer_name' => filter_var($host, FILTER_VALIDATE_IP) !== false ? SocketAddress::normalizeIp($host) : rtrim($host, '.'),
            'SNI_enabled' => filter_var($host, FILTER_VALIDATE_IP) === false,
            'disable_compression' => true, 'alpn_protocols' => 'http/1.1',
        ];
        if ($caFile !== null) {
            $options['cafile'] = $caFile;
        }
        foreach ($options as $name => $value) {
            if (! stream_context_set_option($stream, 'ssl', $name, $value)) {
                throw new DialException('Unable to configure HTTPS proxy TLS.');
            }
        }
        $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        while (true) {
            $remaining = Socks5Session::remaining($deadline);
            $result = @stream_socket_enable_crypto($stream, true, $method);
            if ($result === true) {
                Socks5Session::remaining($deadline);

                return;
            }
            if ($result === false) {
                throw new DialException('HTTPS proxy TLS handshake failed.');
            }
            // PHP does not expose OpenSSL WANT_READ/WANT_WRITE. Wait for input,
            // with a bounded tick to retry a handshake needing write readiness.
            $wait = min($remaining, 0.05);
            $read = [$stream];
            $write = [];
            $except = [];
            if (@stream_select($read, $write, $except, 0, max(1, (int) ceil($wait * 1e6))) === false) {
                throw new DialException('HTTPS proxy TLS stream selection failed.');
            }
        }
    }
}
