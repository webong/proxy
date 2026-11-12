<?php

declare(strict_types=1);

use Webong\Proxy\Tcp\DialException;
use Webong\Proxy\Tcp\TcpDialer;
use Webong\Proxy\Tcp\TcpProfile;

function withHttpsProxyPeer(array $scenario, callable $test): array
{
    $scenario += ['proxy_scheme' => 'https', 'response_hex' => bin2hex("HTTP/1.1 200 OK\r\n\r\n")];
    $process = proc_open([PHP_BINARY, __DIR__.'/../../Support/connect-server.php', base64_encode(json_encode($scenario, JSON_THROW_ON_ERROR))], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start HTTPS proxy fixture.');
    }
    fclose($pipes[0]);
    stream_set_timeout($pipes[1], 5);
    try {
        $address = trim(fgets($pipes[1]));
        if ($address === '') {
            throw new RuntimeException('HTTPS proxy fixture did not start.');
        }
        $test($address);
        $output = trim(stream_get_contents($pipes[1]));

        return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    } finally {
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_terminate($process);
        proc_close($process);
    }
}

it('verifies HTTPS proxy trust identity and expiry before CONNECT', function (string $case): void {
    $success = in_array($case, ['trusted-dns', 'trusted-root-dot', 'trusted-tls12', 'trusted-ip', 'trusted-ipv6', 'trusted-mapped-ip'], true);
    $host = match ($case) {
        'wrong-host' => 'wrong.test', 'trusted-root-dot' => 'proxy.test.', 'trusted-ip' => '127.0.0.1', 'trusted-ipv6' => '::1', 'trusted-mapped-ip' => '::ffff:127.0.0.1', default => 'proxy.test'
    };
    $scenario = ['tls12' => $case === 'trusted-tls12', 'success' => $success, 'tls_leaf' => $case === 'expired' ? 'expired' : 'proxy'];
    if ($case === 'trusted-ipv6') {
        $scenario['listen_host'] = '::1';
    }
    $record = withHttpsProxyPeer($scenario, function (string $address) use ($case, $host, $success): void {
        $caFile = $case === 'untrusted' ? null : ($case === 'missing-ca' ? __DIR__.'/missing-ca.pem' : __DIR__.'/../../fixtures/tls/ca.pem');
        $numeric = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $connector = $numeric ? null : static function (string $hop, int $port, float $remaining) use ($address, $host) {
            expect($hop)->toBe($host)->and($port)->toBe(443);
            // Deliberately unsafe connector settings must be overridden by the dialer.
            $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true, 'peer_name' => 'wrong.test']]);

            return stream_socket_client('tcp://'.$address, $errno, $error, $remaining, STREAM_CLIENT_CONNECT, $context);
        };
        $proxy = $numeric ? (str_contains($host, ':') ? '['.$host.']' : $host).':'.parse_url('tcp://'.$address, PHP_URL_PORT) : $host;
        $dialer = new TcpDialer(new TcpProfile(['version' => 1, 'proxy' => 'https://user:pass@'.$proxy, 'connect_timeout_seconds' => 1]), connector: $connector, resolver: static function (): never {
            throw new RuntimeException('Target DNS invoked.');
        }, proxyCaFile: $caFile);
        if (! $success) {
            expect(fn () => $dialer->dial('target.test', 9000))->toThrow(DialException::class, 'HTTPS proxy TLS handshake failed');

            return;
        }
        $stream = $dialer->dial('target.test', 9000);
        try {
            $metadata = stream_get_meta_data($stream);
            expect($metadata['crypto']['protocol'])->toBeIn(['TLSv1.2', 'TLSv1.3']);
            $options = stream_context_get_options($stream)['ssl'];
            expect($options['peer_name'])->toBe($case === 'trusted-mapped-ip' ? '127.0.0.1' : rtrim($host, '.'))->and($options['verify_peer'])->toBeTrue()->and($options['verify_peer_name'])->toBeTrue()->and($options['allow_self_signed'])->toBeFalse();
            expect(stream_get_contents($stream, 7))->toBe("banner\0");
            fwrite($stream, 'ping');
            expect(stream_get_contents($stream, 4))->toBe('ping');
        } finally {
            fclose($stream);
        }
    });
    if ($success) {
        expect($record['request'])->toContain("CONNECT target.test:9000 HTTP/1.1\r\n", "Proxy-Authorization: Basic dXNlcjpwYXNz\r\n");
    } else {
        expect($record['request'])->toBe('');
    }
})->with(['trusted-dns', 'trusted-root-dot', 'trusted-tls12', 'trusted-ip', 'trusted-ipv6', 'trusted-mapped-ip', 'untrusted', 'wrong-host', 'expired', 'missing-ca']);

it('bounds a stalled TLS handshake and closes its stream', function (): void {
    $stream = null;
    $record = withHttpsProxyPeer(['success' => false, 'tls_stall' => true], function (string $address) use (&$stream): void {
        $connector = static function (string $host, int $port, float $remaining) use ($address, &$stream) {
            return $stream = stream_socket_client('tcp://'.$address, $errno, $error, $remaining);
        };
        $dialer = new TcpDialer(new TcpProfile(['version' => 1, 'proxy' => 'https://user:pass@proxy.test', 'connect_timeout_seconds' => .03]), connector: $connector, proxyCaFile: __DIR__.'/../../fixtures/tls/ca.pem');
        $start = microtime(true);
        expect(fn () => $dialer->dial('target.test', 9000))->toThrow(DialException::class, 'timed out');
        expect(microtime(true) - $start)->toBeLessThan(1)->and(is_resource($stream))->toBeFalse();
    });
    expect($record['request'])->toBe('');
});

it('rejects an empty proxy CA file option', function (): void {
    expect(fn () => new TcpDialer(new TcpProfile, proxyCaFile: ''))->toThrow(InvalidArgumentException::class);
});

it('exposes the native PHP single TLS layer limitation', function (): void {
    $record = withHttpsProxyPeer(['success' => true], function (string $address): void {
        $stream = (new TcpDialer(new TcpProfile(['version' => 1, 'proxy' => 'https://'.$address]), proxyCaFile: __DIR__.'/../../fixtures/tls/ca.pem'))->dial('target.test', 9000);
        try {
            expect(stream_get_contents($stream, 7))->toBe("banner\0");
            // Re-enabling native crypto cannot create TLS to the target inside
            // an already encrypted proxy stream. The original layer remains.
            expect(@stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT))->toBeFalse();
            fwrite($stream, 'ping');
            expect(stream_get_contents($stream, 4))->toBe('ping');
        } finally {
            fclose($stream);
        }
    });
    expect($record['request'])->toContain('CONNECT target.test:9000 HTTP/1.1');
});

it('does not inherit connector trust sources', function (string $mode): void {
    $ca = __DIR__.'/../../fixtures/tls/ca.pem';
    $directory = sys_get_temp_dir().'/proxy-ca-context-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $hash = openssl_x509_parse(file_get_contents($ca))['hash'];
    copy($ca, $directory.'/'.$hash.'.0');
    try {
        $record = withHttpsProxyPeer(['success' => false], function (string $address) use ($mode, $ca, $directory): void {
            $stream = null;
            $connector = static function (string $host, int $port, float $remaining) use ($mode, $address, $ca, $directory, &$stream) {
                $context = stream_context_create(['ssl' => match ($mode) {
                    'cafile' => ['cafile' => $ca],
                    'cafile-zero' => ['cafile' => '0'],
                    'capath-zero' => ['capath' => '0'],
                    default => ['capath' => $directory],
                }]);

                return $stream = stream_socket_client('tcp://'.$address, $errno, $error, $remaining, STREAM_CLIENT_CONNECT, $context);
            };
            $explicitCa = $mode === 'explicit-file-and-capath' ? __DIR__.'/../../fixtures/tls/expired.pem' : null;
            $dialer = new TcpDialer(new TcpProfile(['version' => 1, 'proxy' => 'https://user:pass@proxy.test']), connector: $connector, proxyCaFile: $explicitCa);
            expect(fn () => $dialer->dial('target.test', 9000))->toThrow(DialException::class, $explicitCa === null ? 'trust must be configured through proxyCaFile' : 'TLS handshake failed');
            expect(is_resource($stream))->toBeFalse();
        });
        expect($record['request'])->toBe('');
    } finally {
        unlink($directory.'/'.$hash.'.0');
        rmdir($directory);
    }
})->with(['cafile', 'capath', 'cafile-zero', 'capath-zero', 'explicit-file-and-capath']);

it('uses explicit proxy CA trust instead of a connector CA file', function (): void {
    $record = withHttpsProxyPeer(['success' => true], function (string $address): void {
        $connector = static function (string $host, int $port, float $remaining) use ($address) {
            $context = stream_context_create(['ssl' => ['cafile' => __DIR__.'/../../fixtures/tls/expired.pem', 'capath' => __DIR__.'/missing-ca-directory']]);

            return stream_socket_client('tcp://'.$address, $errno, $error, $remaining, STREAM_CLIENT_CONNECT, $context);
        };
        $dialer = new TcpDialer(new TcpProfile(['version' => 1, 'proxy' => 'https://proxy.test']), connector: $connector, proxyCaFile: __DIR__.'/../../fixtures/tls/ca.pem');
        $stream = $dialer->dial('target.test', 9000);
        try {
            expect(stream_get_contents($stream, 7))->toBe("banner\0");
            fwrite($stream, 'ping');
            expect(stream_get_contents($stream, 4))->toBe('ping');
        } finally {
            fclose($stream);
        }
    });
    expect($record['request'])->toContain('CONNECT target.test:9000 HTTP/1.1');
});
