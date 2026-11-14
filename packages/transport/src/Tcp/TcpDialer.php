<?php

declare(strict_types=1);

namespace Webong\Proxy\Tcp;

use Closure;
use InvalidArgumentException;
use Throwable;
use Webong\Proxy\Connection\HttpConnectSession;
use Webong\Proxy\Connection\ProxyTlsSession;
use Webong\Proxy\Connection\ProxyUrl;
use Webong\Proxy\Connection\SocketAddress;
use Webong\Proxy\Connection\Socks5Session;

/** Blocking TCP streams; only setup is deadline-bounded. Callers own subsequent I/O and TLS. */
final class TcpDialer
{
    private readonly ?Closure $connector;

    private readonly ?Closure $resolver;

    /**
     * HTTPS proxy connectors return plain TCP; proxyCaFile supplies private trust roots.
     * Optional connector: (string $host, int $port, float $remainingSeconds): resource.
     * Optional resolver: (string $host, float $remainingSeconds): list<string> IP addresses.
     * Blocking PHP DNS and user callbacks cannot be preempted; overruns fail after return.
     */
    public function __construct(private readonly TcpProfile $profile, ?callable $connector = null, ?callable $resolver = null, private readonly ?string $proxyCaFile = null)
    {
        if ($proxyCaFile === '') {
            throw new InvalidArgumentException('Proxy CA file must not be empty.');
        }
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
        $target = $proxy === null || in_array($proxy['scheme'], ['http', 'https'], true) ? '' : $this->target($host, $port, $proxy['scheme'], $deadline);
        $hop = $proxy === null ? $host : trim($proxy['host'], '[]');
        $hopPort = $proxy === null ? $port : ($proxy['port'] ?? match ($proxy['scheme']) {
            'http' => 80, 'https' => 443, default => 1080
        });
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
                if ($proxy['scheme'] === 'https') {
                    ProxyTlsSession::connect($stream, $hop, $deadline, $this->proxyCaFile);
                }
                if (in_array($proxy['scheme'], ['http', 'https'], true)) {
                    HttpConnectSession::connect($stream, $proxy, $host, $port, $deadline);
                } else {
                    $this->handshake($stream, $proxy, $target, $deadline);
                }
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

        return SocketAddress::encode($host, $port);
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
        Socks5Session::authenticate($stream, $proxy, $deadline);
        Socks5Session::request($stream, 1, $target, $deadline);
    }

    private function remaining(float $deadline): float
    {
        $remaining = $deadline - hrtime(true) / 1e9;
        if ($remaining <= 0) {
            throw new DialException('TCP connection setup timed out.');
        }

        return $remaining;
    }
}
