<?php

declare(strict_types=1);

use Webong\Proxy\Tcp\DialException;
use Webong\Proxy\Tcp\TcpDialer;
use Webong\Proxy\Tcp\TcpProfile;

$connectCases = json_decode(file_get_contents(__DIR__.'/../../../spec/http-connect-cases.json'), true, flags: JSON_THROW_ON_ERROR);
$datasets = [];
foreach (['http', 'https'] as $scheme) {
    foreach ($connectCases as $case) {
        $case['proxy_scheme'] = $scheme;
        $datasets[$scheme.'/'.$case['name']] = [$case];
    }
}
dataset('HTTP CONNECT', $datasets);
it('conforms to shared HTTP CONNECT scenarios', function (array $case): void {
    $process = proc_open([PHP_BINARY, __DIR__.'/../../Support/connect-server.php', base64_encode(json_encode($case, JSON_THROW_ON_ERROR))], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    stream_set_timeout($pipes[1], 5);
    try {
        $address = trim(fgets($pipes[1]));
        $credentials = isset($case['credentials']) ? $case['credentials'].'@' : '';
        $profile = new TcpProfile(['version' => 1, 'proxy' => $case['proxy_scheme'].'://'.$credentials.$address, 'connect_timeout_seconds' => ($case['stall'] ?? false) ? .03 : (($case['deadline_cleared'] ?? false) ? .25 : 1)]);
        $dialer = new TcpDialer($profile, resolver: static function (): never {
            throw new RuntimeException('CONNECT must not resolve the target locally.');
        }, proxyCaFile: __DIR__.'/../../fixtures/tls/ca.pem');
        if ($case['success']) {
            $stream = $dialer->dial($case['host'], $case['port']);
            try {
                stream_set_timeout($stream, 1);
                $banner = '';
                while (strlen($banner) < 7 && ! feof($stream)) {
                    $banner .= fread($stream, 7 - strlen($banner));
                }
                expect($banner)->toBe("banner\0");
                if ($case['deadline_cleared'] ?? false) {
                    usleep(300000);
                }
                fwrite($stream, 'ping');
                expect(fread($stream, 4))->toBe('ping');
            } finally {
                fclose($stream);
            }
        } else {
            expect(fn () => $dialer->dial($case['host'], $case['port']))->toThrow(DialException::class);
        }
        $record = json_decode(trim(stream_get_contents($pipes[1])), true, flags: JSON_THROW_ON_ERROR);
        $authority = (str_contains($case['host'], ':') ? '['.$case['host'].']' : $case['host']).':'.$case['port'];
        $expected = 'CONNECT '.$authority." HTTP/1.1\r\nHost: ".$authority."\r\n";
        if (isset($case['credentials'])) {
            $expected .= 'Proxy-Authorization: Basic '.base64_encode(rawurldecode($case['credentials']))."\r\n";
        }
        expect($record['request'])->toBe($expected."\r\n");
    } finally {
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_terminate($process);
        proc_close($process);
    }
})->with('HTTP CONNECT');

it('defaults HTTP CONNECT to port 80', function (): void {
    $dialer = new TcpDialer(new TcpProfile(['version' => 1, 'proxy' => 'http://proxy.example']), connector: static function (string $host, int $port): never {
        expect($host)->toBe('proxy.example')->and($port)->toBe(80);
        throw new DialException('Fixture stopped.');
    });
    expect(fn () => $dialer->dial('target.example', 443))->toThrow(DialException::class, 'Fixture stopped');
});

it('closes CONNECT streams on setup failures', function (): void {
    foreach (["HTTP/1.1 407 Denied\r\n\r\n", null] as $response) {
        [$stream, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        try {
            if ($response !== null) {
                fwrite($peer, $response);
            }
            $dialer = new TcpDialer(new TcpProfile(['version' => 1, 'proxy' => 'http://proxy.example', 'connect_timeout_seconds' => .03]), connector: static fn () => $stream);
            expect(fn () => $dialer->dial('target.example', 443))->toThrow(DialException::class);
            expect(is_resource($stream))->toBeFalse();
        } finally {
            fclose($peer);
        }
    }
});

it('reports a safe CONNECT rejection status', function (): void {
    [$stream, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    try {
        fwrite($peer, "HTTP/1.1 407 secret-password\r\n\r\n");
        $dialer = new TcpDialer(new TcpProfile(['version' => 1, 'proxy' => 'http://secret:password@proxy.example']), connector: static fn () => $stream);
        try {
            $dialer->dial('target.example', 443);
            $this->fail('Rejected CONNECT succeeded.');
        } catch (DialException $exception) {
            expect($exception->getCode())->toBe(407)
                ->and($exception->getMessage())->not->toContain('secret', 'password');
        }
    } finally {
        fclose($peer);
    }
});
