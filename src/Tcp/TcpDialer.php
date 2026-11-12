<?php

declare(strict_types=1);

namespace Webong\Proxy\Tcp;

use Closure;
use InvalidArgumentException;
use Throwable;
use Webong\Proxy\Connection\ProxyUrl;

/** Blocking TCP streams; only setup is deadline-bounded. Callers own subsequent I/O and TLS. */
final class TcpDialer
{
    private readonly ?Closure $connector;
    private readonly ?Closure $resolver;

    /**
     * Optional connector: (string $host, int $port, float $remainingSeconds): resource.
     * Optional resolver: (string $host, float $remainingSeconds): list<string> IP addresses.
     * Blocking PHP DNS and user callbacks cannot be preempted; overruns fail after return.
     */
    public function __construct(private readonly TcpProfile $profile, ?callable $connector = null, ?callable $resolver = null)
    {
        $this->connector = $connector === null ? null : Closure::fromCallable($connector);
        $this->resolver = $resolver === null ? null : Closure::fromCallable($resolver);
    }

    /** @return resource Connected stream owned by the caller. */
    public function dial(string $host, int $port)
    {
        if ($port < 1 || $port > 65535 || ($host === '')
            || (filter_var($host, FILTER_VALIDATE_IP) === false
                && (strlen($host) > 253 || preg_match('/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*\.?$/D', $host) !== 1))) {
            throw new InvalidArgumentException('Invalid TCP destination.');
        }
        $settings = $this->profile->data;
        $timeout = ceil(($settings['connect_timeout_seconds'] ?? 10) * 1000) / 1000;
        $deadline = hrtime(true) / 1e9 + $timeout;
        $proxy = isset($settings['proxy']) ? ProxyUrl::parse($settings['proxy']) : null;
        $target = $proxy === null ? '' : $this->target($host, $port, $proxy['scheme'], $deadline);
        $hop = $proxy === null ? $host : trim($proxy['host'], '[]');
        $hopPort = $proxy === null ? $port : ($proxy['port'] ?? 1080);
        $remaining = $this->remaining($deadline);
        $stream = $this->connector !== null
            ? ($this->connector)($hop, $hopPort, $remaining)
            : @stream_socket_client('tcp://'.(str_contains($hop, ':') ? '['.$hop.']' : $hop).':'.$hopPort, $errno, $error, $remaining);
        if (! is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new DialException('TCP connection failed.');
        }
        try {
            $this->remaining($deadline);
            if ($proxy !== null) {
                if (! stream_set_blocking($stream, false)) {
                    throw new DialException('Unable to configure TCP stream.');
                }
                $this->handshake($stream, $proxy, $target, $deadline);
            }
            $this->remaining($deadline);
            if (! stream_set_blocking($stream, true)) {
                throw new DialException('Unable to configure TCP stream.');
            }

            return $stream;
        } catch (Throwable $exception) {
            fclose($stream);
            throw $exception;
        }
    }

    private function target(string $host, int $port, string $scheme, float $deadline): string
    {
        $ip = filter_var($host, FILTER_VALIDATE_IP) !== false;
        if (! $ip && $scheme === 'socks5') {
            $addresses = $this->resolver !== null ? ($this->resolver)($host, $this->remaining($deadline)) : $this->resolve($host);
            if (! is_array($addresses)) {
                throw new DialException('SOCKS resolver must return an address list.');
            }
            $this->remaining($deadline);
            $host = '';
            foreach ($addresses as $address) {
                if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false) {
                    $host = $address;
                    break;
                }
            }
            if ($host === '') {
                throw new DialException('SOCKS local DNS returned no addresses.');
            }
            $ip = true;
        }
        if ($ip) {
            $packed = inet_pton($host);
            // Match Go's handling of IPv4-mapped IPv6 literals.
            if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
                $packed = substr($packed, 12);
            }
            $address = chr(strlen($packed) === 4 ? 1 : 4).$packed;
        } else {
            $address = chr(3).chr(strlen($host)).$host;
        }

        return $address.pack('n', $port);
    }

    /** @return list<string> */
    private function resolve(string $host): array
    {
        $addresses = @gethostbynamel($host) ?: [];
        if ($addresses !== []) {
            return $addresses;
        }
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return $addresses;
    }

    private function handshake($stream, array $proxy, string $target, float $deadline): void
    {
        $method = isset($proxy['user']) ? 2 : 0;
        $this->write($stream, "\x05\x01".chr($method), $deadline);
        $reply = $this->read($stream, 2, $deadline);
        if (ord($reply[0]) !== 5) {
            throw new DialException('Invalid SOCKS5 response.');
        }
        if (ord($reply[1]) !== $method) {
            throw new DialException('SOCKS5 authentication failed.');
        }
        if ($method === 2) {
            $user = rawurldecode($proxy['user']);
            $password = rawurldecode($proxy['pass']);
            $this->write($stream, "\x01".chr(strlen($user)).$user.chr(strlen($password)).$password, $deadline);
            if ($this->read($stream, 2, $deadline) !== "\x01\x00") {
                throw new DialException('SOCKS5 authentication failed.');
            }
        }
        $this->write($stream, "\x05\x01\x00".$target, $deadline);
        $header = $this->read($stream, 4, $deadline);
        if (ord($header[0]) !== 5 || ord($header[2]) !== 0) {
            throw new DialException('Invalid SOCKS5 response.');
        }
        if (ord($header[1]) !== 0) {
            throw new DialException('SOCKS5 connection rejected.', ord($header[1]));
        }
        $length = match (ord($header[3])) {
            1 => 4,
            4 => 16,
            3 => ord($this->read($stream, 1, $deadline)),
            default => 0,
        };
        if ($length === 0) {
            throw new DialException('Invalid SOCKS5 address response.');
        }
        $this->read($stream, $length + 2, $deadline);
    }

    private function remaining(float $deadline): float
    {
        $remaining = $deadline - hrtime(true) / 1e9;
        if ($remaining <= 0) {
            throw new DialException('TCP connection setup timed out.');
        }

        return $remaining;
    }

    private function wait($stream, bool $writing, float $deadline): void
    {
        $remaining = $this->remaining($deadline);
        $seconds = (int) $remaining;
        $microseconds = min(999999, (int) ceil(($remaining - $seconds) * 1e6));
        $read = $writing ? [] : [$stream];
        $write = $writing ? [$stream] : [];
        $except = [];
        $ready = @stream_select($read, $write, $except, $seconds, $microseconds);
        if ($ready === false) {
            throw new DialException('TCP stream selection failed.');
        }
        if ($ready === 0) {
            throw new DialException('TCP connection setup timed out.');
        }
    }

    private function read($stream, int $length, float $deadline): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $this->wait($stream, false, $deadline);
            $chunk = @fread($stream, $length - strlen($data));
            if ($chunk === false || ($chunk === '' && feof($stream))) {
                throw new DialException('SOCKS5 connection closed during handshake.');
            }
            $data .= $chunk;
        }

        return $data;
    }

    private function write($stream, string $data, float $deadline): void
    {
        while ($data !== '') {
            $this->wait($stream, true, $deadline);
            $written = @fwrite($stream, $data);
            if ($written === false || $written === 0) {
                throw new DialException('SOCKS5 handshake write failed.');
            }
            $data = substr($data, $written);
        }
    }
}
