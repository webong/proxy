<?php

declare(strict_types=1);

use Webong\Proxy\Connection\SocketAddress;
use Webong\Proxy\Udp\Socks5Datagram;
use Webong\Proxy\Udp\UdpDialer;
use Webong\Proxy\Udp\UdpException;
use Webong\Proxy\Udp\UdpProfile;

function withUdpFixture(array $config, callable $test): array
{
    $process = proc_open([PHP_BINARY, __DIR__.'/../../Support/udp-server.php', base64_encode(json_encode($config, JSON_THROW_ON_ERROR))], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    stream_set_timeout($pipes[1], 5);
    try {
        $address = trim(fgets($pipes[1]));
        if ($address === 'UNAVAILABLE' || $address === '') {
            throw new RuntimeException('UDP fixture unavailable.');
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

it('validates the raw profile corpus for UDP', function (): void {
    foreach (glob(__DIR__.'/../../../spec/udp/valid/*.json') as $file) {
        $p = UdpProfile::fromJson(file_get_contents($file));
        expect($p)->toBeInstanceOf(UdpProfile::class)->and(UdpProfile::fromJson($p->toJson())->toArray())->toBe($p->toArray());
    }
    foreach (glob(__DIR__.'/../../../spec/udp/invalid/*.json') as $file) {
        expect(fn () => UdpProfile::fromJson(file_get_contents($file)))->toThrow(InvalidArgumentException::class);
    }
});

it('matches shared UDP wire frames', function (): void {
    $cases = json_decode(file_get_contents(__DIR__.'/../../../spec/udp-datagrams.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach ($cases as $case) {
        $packet = Socks5Datagram::decode(hex2bin($case['wire']));
        if (! $case['valid']) {
            expect($packet)->toBeNull();

            continue;
        }
        expect($packet->payload)->toBe(hex2bin($case['payload']))->and($packet->host)->toBe($case['decoded_host'])->and($packet->port)->toBe($case['port']);
        if (! ($case['decode_only'] ?? false)) {
            expect(bin2hex(Socks5Datagram::encode(hex2bin($case['payload']), $case['host'], $case['port'])))->toBe($case['wire']);
        }
    }
    foreach (['192.0.2.1', '2001:db8::1', 'unresolved.example'] as $host) {
        $header = strlen(Socks5Datagram::encode('', $host, 53));
        $maximum = Socks5Datagram::MAX_WIRE_SIZE - $header;
        expect(strlen(Socks5Datagram::encode(str_repeat('x', $maximum), $host, 53)))->toBe(Socks5Datagram::MAX_WIRE_SIZE);
        expect(fn () => Socks5Datagram::encode(str_repeat('x', $maximum + 1), $host, 53))->toThrow(InvalidArgumentException::class);
    }
});

it('preserves direct UDP empty and binary datagrams', function (): void {
    $record = withUdpFixture(['direct' => true, 'count' => 3], function (string $address): void {
        $target = SocketAddress::split($address);
        $session = (new UdpDialer(new UdpProfile))->open('127.0.0.1');
        try {
            foreach (['', "\0\xffbinary", 'third'] as $payload) {
                expect($session->sendTo($payload, $target['host'], $target['port']))->toBe(strlen($payload));
                $reply = $session->receive(1);
                expect($reply->payload)->toBe($payload)->and($reply->port)->toBe($target['port']);
            }
        } finally {
            $session->close();
            $session->close();
        }
        expect($session->isClosed())->toBeTrue();
    });
    expect(count($record['packets']))->toBe(3);
});

it('associates UDP with strict authentication, DNS modes, and relay filtering', function (string $scheme, string $relay): void {
    $record = withUdpFixture(['auth' => true, 'relay' => $relay, 'inject' => true, 'fragment_reply' => true], function (string $address) use ($scheme): void {
        $lookups = [];
        $resolver = static function (string $host) use (&$lookups): array {
            $lookups[] = $host;

            return ['127.0.0.1'];
        };
        $session = (new UdpDialer(new UdpProfile(['version' => 1, 'proxy' => $scheme.'://user:p%40ss@'.$address]), resolver: $resolver))->open();
        try {
            foreach (['', "\0\xffbinary", 'third'] as $payload) {
                $session->sendTo($payload, 'unresolved.example', 5353);
                expect($session->receive(1)->payload)->toBe($payload);
            }
            expect(in_array('unresolved.example', $lookups, true))->toBe($scheme === 'socks5');
        } finally {
            $session->close();
        }
    });
    expect($record['command'])->toBe('050300')->and($record['request']['host'])->toBe('0.0.0.0')->and($record['request']['port'])->toBe(0)
        ->and($record['user'])->toBe('user')->and($record['password'])->toBe('p@ss')->and($record['control_closed'])->toBeTrue();
    foreach ($record['packets'] as $packet) {
        expect($packet['atyp'])->toBe($scheme === 'socks5' ? 1 : 3);
    }
})->with([['socks5h', 'literal'], ['socks5', 'wildcard'], ['socks5h', 'domain']]);

it('terminates when the control peer closes and honors receive timeouts', function (bool $close): void {
    withUdpFixture($close ? ['close_on_packet' => true] : ['drop' => true], function (string $address) use ($close): void {
        $session = (new UdpDialer(new UdpProfile(['version' => 1, 'proxy' => 'socks5h://'.$address])))->open();
        try {
            $session->sendTo('ping', 'unresolved.example', 53);
            expect(fn () => $session->receive(0.05))->toThrow(UdpException::class);
            if ($close) {
                expect($session->isClosed())->toBeTrue();
                expect(fn () => $session->sendTo('x', '127.0.0.1', 53))->toThrow(UdpException::class);
            } else {
                expect($session->isClosed())->toBeFalse();
            }
        } finally {
            $session->close();
        }
    });
})->with([true, false]);

it('closes setup on proxy rejection, invalid relay, bad auth, and timeout', function (array $config): void {
    withUdpFixture($config, function (string $address) use ($config): void {
        $proxy = (($config['auth'] ?? false) ? 'socks5h://user:pass@' : 'socks5h://').$address;
        $dialer = new UdpDialer(new UdpProfile(['version' => 1, 'proxy' => $proxy, 'connect_timeout_seconds' => 0.1]));
        expect(fn () => $dialer->open())->toThrow(RuntimeException::class);
    });
})->with([[['status' => 7]], [['zero_port' => true]], [['auth' => true, 'bad_auth' => true]], [['stall' => true]]]);

it('supports direct IPv6 UDP and IPv6 SOCKS relays', function (bool $proxied): void {
    $record = withUdpFixture(['ipv6' => true, 'direct' => ! $proxied], function (string $address) use ($proxied): void {
        $profile = new UdpProfile($proxied ? ['version' => 1, 'proxy' => 'socks5h://'.$address] : ['version' => 1]);
        $session = (new UdpDialer($profile))->open($proxied ? null : '::1');
        $target = $proxied ? ['host' => '2001:db8::1', 'port' => 9000] : SocketAddress::split($address);
        try {
            $session->sendTo('v6', $target['host'], $target['port']);
            expect($session->receive(1)->payload)->toBe('v6');
        } finally {
            $session->close();
        }
    });
    if ($proxied) {
        expect($record['packets'][0]['atyp'])->toBe(4);
    }
})->with([false, true]);

it('reports payload limits and rejects invalid destinations before sending', function (): void {
    $session = (new UdpDialer(new UdpProfile))->open('127.0.0.1');
    try {
        expect(fn () => $session->sendTo(str_repeat('x', 65508), '127.0.0.1', 53))->toThrow(InvalidArgumentException::class);
        foreach ([['bad host', 53], ['::1%zone', 53], ['127.0.0.1', 0]] as [$host, $port]) {
            expect(fn () => $session->sendTo('x', $host, $port))->toThrow(InvalidArgumentException::class);
        }
    } finally {
        $session->close();
    }
    foreach (['192.0.2.1', '2001:db8::1', 'unresolved.example'] as $host) {
        expect(strlen(Socks5Datagram::encode(str_repeat('x', Socks5Datagram::maxPayloadSize($host, 53)), $host, 53)))->toBe(65507);
    }
    foreach ([-1, 65536] as $port) {
        expect(fn () => (new UdpDialer(new UdpProfile))->open('127.0.0.1', $port))->toThrow(InvalidArgumentException::class);
    }
});

it('defaults direct UDP to IPv4 and resolves IPv4-only destinations', function (): void {
    withUdpFixture(['direct' => true], function (string $address): void {
        $target = SocketAddress::split($address);
        $resolver = static fn (): array => ['127.0.0.1'];
        $session = (new UdpDialer(new UdpProfile, resolver: $resolver))->open();
        try {
            expect($session->localAddress()['host'])->toBe('0.0.0.0');
            $session->sendTo('default', 'ipv4-only.example', $target['port']);
            expect($session->receive(1)->payload)->toBe('default');
        } finally {
            $session->close();
        }
    });
});
