<?php

declare(strict_types=1);

use Webong\Proxy\Tcp\TcpDialer;
use Webong\Proxy\Tcp\TcpProfile;
use Webong\Proxy\Tcp\DialException;
use Webong\Proxy\Http\HttpProfile;
use Webong\Proxy\Http\GuzzleOptions;

/** Owns fixture process cleanup even when the client fails. */
function withSocksFixture(array $scenario, callable $test): array
{
    $process = proc_open([PHP_BINARY, __DIR__.'/../../Support/socks-server.php', base64_encode(json_encode($scenario, JSON_THROW_ON_ERROR))], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process)) { throw new RuntimeException('Cannot start socket fixture.'); }
    fclose($pipes[0]);
    stream_set_timeout($pipes[1], 5);
    try {
        $address = trim(fgets($pipes[1]));
        if ($address === '') { throw new RuntimeException('Socket fixture did not start.'); }
        $test($address);
        $output = trim(stream_get_contents($pipes[1]));
        try {
            $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Fixture output: '.$output.'; stderr: '.stream_get_contents($pipes[2]), previous: $exception);
        }
        return $result;
    } finally {
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_terminate($process);
        proc_close($process);
    }
}

it('validates and round-trips shared TCP profiles', function (): void {
    foreach (glob(__DIR__.'/../../../spec/tcp/valid/*.json') as $file) {
        $profile = TcpProfile::fromJson(file_get_contents($file));
        expect(TcpProfile::fromJson($profile->toJson())->toArray())->toBe($profile->toArray());
    }
    foreach (glob(__DIR__.'/../../../spec/tcp/invalid/*.json') as $file) {
        expect(fn () => TcpProfile::fromJson(file_get_contents($file)))->toThrow(InvalidArgumentException::class);
    }
});

it('dials direct TCP streams', function (): void {
    $record = withSocksFixture(['mode' => 'direct'], function (string $address): void {
        [$host, $port] = explode(':', $address);
        $stream = (new TcpDialer(new TcpProfile()))->dial($host, (int) $port);
        try {
            fwrite($stream, 'ping');
            expect(fread($stream, 4))->toBe('ping');
        } finally { fclose($stream); }
    });
    expect($record['echo'])->toBe('ping');
});

$cases = json_decode(file_get_contents(__DIR__.'/../../../spec/socks5-cases.json'), true, flags: JSON_THROW_ON_ERROR);
dataset('SOCKS conformance', array_combine(array_column($cases, 'name'), array_map(fn ($case) => [$case], $cases)));
it('follows the shared SOCKS wire scenarios', function (array $case): void {
    $record = withSocksFixture($case, function (string $address) use ($case): void {
        $credentials = ($case['credentials'] ?? false) ? rawurlencode($case['username'] ?? 'user').':'.rawurlencode($case['password'] ?? 'p@ss').'@' : '';
        $profile = new TcpProfile(['version' => 1, 'proxy' => $case['scheme'].'://'.$credentials.$address, 'connect_timeout_seconds' => $case['timeout'] ?? 2]);
        $resolver = static function (string $host) use ($case): array {
            expect($case['scheme'])->toBe('socks5');
            return [$case['resolved_ip'] ?? '192.0.2.10'];
        };
        $dialer = new TcpDialer($profile, resolver: $resolver);
        if (! $case['success']) {
            expect(fn () => $dialer->dial($case['host'], $case['port']))->toThrow(DialException::class);
            return;
        }
        $stream = $dialer->dial($case['host'], $case['port']);
        try {
            fwrite($stream, 'ping');
            expect(fread($stream, 4))->toBe('ping');
        } finally { fclose($stream); }
    });
    if ($case['success']) {
        expect($record['atyp'])->toBe($case['atyp'])
            ->and($record['host'])->toBe($case['wire_host'])
            ->and($record['port'])->toBe($case['port'])
            ->and($record['command'])->toBe('050100');
        if ($case['credentials'] ?? false) {
            expect($record['username'])->toBe($case['username'] ?? 'user')->and($record['password'])->toBe($case['password'] ?? 'p@ss');
        }
    }
})->with('SOCKS conformance');

it('uses SOCKS through a real portable Guzzle client', function (): void {
    if (! extension_loaded('curl')) { $this->markTestSkipped('SOCKS HTTP requires ext-curl.'); }
    $case = ['mode' => 'http', 'scheme' => 'socks5h', 'host' => 'unresolved.example', 'greeting' => '0500', 'reply' => '05000001000000000000', 'success' => true];
    $record = withSocksFixture($case, function (string $address): void {
        $profile = new HttpProfile(['version' => 1, 'proxy' => ['http' => 'socks5h://'.$address], 'request_timeout_seconds' => 2]);
        $client = new GuzzleHttp\Client(GuzzleOptions::fromProfile($profile));
        expect($client->get('http://unresolved.example/path')->getStatusCode())->toBe(204);
    });
    expect($record['atyp'])->toBe(3)->and($record['host'])->toBe('unresolved.example');
});

it('keeps setup deadlines out of subsequent stream IO', function (): void {
    $case = ['greeting' => '0500', 'reply' => '05000001000000000000', 'success' => true];
    withSocksFixture($case, function (string $address): void {
        $dialer = new TcpDialer(new TcpProfile(['version' => 1, 'proxy' => 'socks5h://'.$address, 'connect_timeout_seconds' => 0.1]));
        $stream = $dialer->dial('example.test', 443);
        try {
            usleep(150000);
            fwrite($stream, 'ping');
            expect(fread($stream, 4))->toBe('ping');
        } finally { fclose($stream); }
    });
});

it('rejects invalid destinations before connecting and detects DNS overruns', function (): void {
    $reached = false;
    $connector = static function () use (&$reached) { $reached = true; throw new RuntimeException('Unexpected dial.'); };
    $dialer = new TcpDialer(new TcpProfile(), connector: $connector);
    foreach ([['bad host', 80], ['localhost', 0], ['localhost', 65536], ['[::1]', 80]] as [$host, $port]) {
        expect(fn () => $dialer->dial($host, $port))->toThrow(InvalidArgumentException::class);
    }
    $dialer = new TcpDialer(new TcpProfile(['version' => 1, 'proxy' => 'socks5://proxy.example', 'connect_timeout_seconds' => 0.001]), connector: $connector, resolver: static function (): array {
        usleep(5000);
        return ['127.0.0.1'];
    });
    expect(fn () => $dialer->dial('example.test', 80))->toThrow(DialException::class, 'timed out')
        ->and($reached)->toBeFalse();
});

it('closes failed SOCKS streams', function (): void {
    $captured = null;
    withSocksFixture(['greeting' => '0400'], function (string $address) use (&$captured): void {
        $connector = static function (string $host, int $port, float $timeout) use (&$captured) {
            $captured = stream_socket_client('tcp://'.$host.':'.$port, $errno, $error, $timeout);
            return $captured;
        };
        $dialer = new TcpDialer(new TcpProfile(['version' => 1, 'proxy' => 'socks5h://'.$address]), connector: $connector);
        expect(fn () => $dialer->dial('example.test', 80))->toThrow(DialException::class)
            ->and(is_resource($captured))->toBeFalse();
    });
});

it('supports local HTTP SOCKS DNS and direct bypass', function (): void {
    if (! extension_loaded('curl')) { $this->markTestSkipped('SOCKS HTTP requires ext-curl.'); }
    $case = ['mode' => 'http', 'greeting' => '0500', 'reply' => '05000001000000000000', 'success' => true];
    $record = withSocksFixture($case, function (string $address): void {
        $profile = new HttpProfile(['version' => 1, 'proxy' => ['http' => 'socks5://'.$address], 'request_timeout_seconds' => 2]);
        $client = new GuzzleHttp\Client(GuzzleOptions::fromProfile($profile));
        expect($client->get('http://localhost:8080/path')->getStatusCode())->toBe(204);
    });
    expect(in_array($record['atyp'], [1, 4], true))->toBeTrue();
    $record = withSocksFixture(['mode' => 'http-direct'], function (string $address): void {
        $profile = new HttpProfile(['version' => 1, 'proxy' => ['http' => 'socks5h://127.0.0.1:1', 'no_proxy' => ['127.0.0.1']], 'request_timeout_seconds' => 2]);
        $client = new GuzzleHttp\Client(GuzzleOptions::fromProfile($profile));
        expect($client->get('http://'.$address.'/direct')->getStatusCode())->toBe(204);
    });
    expect($record['request'])->toBe('GET /direct HTTP/1.1');
});

it('requires authentication rather than allowing SOCKS HTTP downgrade', function (): void {
    $profile = new HttpProfile(['version' => 1, 'proxy' => ['http' => 'socks5h://user:pass@localhost:1080']]);
    if (! extension_loaded('curl') || version_compare(curl_version()['version'], '8.23.0', '<')) {
        expect(fn () => GuzzleOptions::fromProfile($profile))->toThrow(InvalidArgumentException::class, 'prevent authentication downgrade');
        return;
    }
    $case = ['mode' => 'http', 'credentials' => true, 'greeting' => '0502', 'auth_reply' => '0100', 'reply' => '05000001000000000000', 'success' => true];
    $record = withSocksFixture($case, function (string $address): void {
        $profile = new HttpProfile(['version' => 1, 'proxy' => ['http' => 'socks5h://user:p%40ss@'.$address], 'request_timeout_seconds' => 2]);
        $client = new GuzzleHttp\Client(GuzzleOptions::fromProfile($profile));
        expect($client->get('http://unresolved.example/')->getStatusCode())->toBe(204);
    });
    expect($record['username'])->toBe($case['username'] ?? 'user')->and($record['password'])->toBe($case['password'] ?? 'p@ss');
});

it('rejects malicious no-auth selection in authenticated Guzzle SOCKS', function (): void {
    if (! extension_loaded('curl') || version_compare(curl_version()['version'], '8.23.0', '<')) {
        $this->markTestSkipped('Mandatory SOCKS HTTP authentication requires libcurl 8.23+.');
    }
    $scenario = [
        'mode' => 'http', 'greeting' => '0500', 'credentials' => true,
        'allow_downgrade' => true, 'reply' => '05000001000000000000', 'success' => true,
    ];
    $record = withSocksFixture($scenario, function (string $address): void {
        $profile = new HttpProfile(['version' => 1, 'proxy' => ['http' => 'socks5h://user:pass@'.$address], 'request_timeout_seconds' => 2]);
        $client = new GuzzleHttp\Client(GuzzleOptions::fromProfile($profile));
        expect(fn () => $client->get('http://unresolved.example/'))->toThrow(GuzzleHttp\Exception\TransferException::class);
    });
    // The peer remains willing to complete CONNECT and return HTTP 204. A client
    // accepting no-auth would succeed, so a disconnect alone cannot mask a bug.
    expect($record)->not->toHaveKey('command')->not->toHaveKey('request');
    expect($record['greeting'])->toBe('050102');

    // Negative control: deliberately remove CURLAUTH_ONLY at the terminal handler.
    // The same malicious peer must then be able to complete a successful request.
    $control = withSocksFixture($scenario, function (string $address): void {
        $native = new GuzzleHttp\Handler\CurlMultiHandler();
        $relaxed = static function ($request, array $options) use ($native) {
            $options['curl'][CURLOPT_SOCKS5_AUTH] = CURLAUTH_BASIC;
            return $native($request, $options);
        };
        $profile = new HttpProfile(['version' => 1, 'proxy' => ['http' => 'socks5h://user:pass@'.$address], 'request_timeout_seconds' => 2]);
        $client = new GuzzleHttp\Client(GuzzleOptions::fromProfile($profile, $relaxed));
        expect($client->get('http://unresolved.example/')->getStatusCode())->toBe(204);
    });
    expect($control['command'])->toBe('050100')->and($control['request'])->toBe('GET / HTTP/1.1');
});

it('carries HTTPS through the SOCKS tunnel', function (): void {
    if (! extension_loaded('curl') || ! extension_loaded('openssl')) { $this->markTestSkipped('TLS fixture requires curl and openssl.'); }
    $case = ['mode' => 'https', 'greeting' => '0500', 'reply' => '05000001000000000000', 'success' => true];
    $record = withSocksFixture($case, function (string $address): void {
        $profile = new HttpProfile(['version' => 1, 'proxy' => ['https' => 'socks5h://'.$address], 'request_timeout_seconds' => 3]);
        $options = GuzzleOptions::fromProfile($profile);
        $options['verify'] = false; // Loopback fixture generates a one-use self-signed certificate.
        $client = new GuzzleHttp\Client($options);
        expect($client->get('https://unresolved.example/path')->getStatusCode())->toBe(204);
    });
    expect($record['atyp'])->toBe(3)->and($record['host'])->toBe('unresolved.example')
        ->and($record['request'])->toBe('GET /path HTTP/1.1');
});

it('defaults SOCKS to port 1080 without resolving remote destinations', function (): void {
    $dialer = new TcpDialer(new TcpProfile(['version' => 1, 'proxy' => 'socks5h://proxy.example']), connector: static function (string $host, int $port): never {
        expect($host)->toBe('proxy.example')->and($port)->toBe(1080);
        throw new DialException('Fixture connector stopped.');
    }, resolver: static function (): never { throw new RuntimeException('Remote DNS must not run locally.'); });
    expect(fn () => $dialer->dial('unresolved.example', 80))->toThrow(DialException::class, 'Fixture connector stopped');
});
