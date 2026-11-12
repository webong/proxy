<?php

declare(strict_types=1);

namespace Webong\Proxy\Udp;

use Closure;
use InvalidArgumentException;
use Webong\Proxy\Connection\SocketAddress;

/** Owns the datagram socket and, when proxied, its live TCP control connection. */
final class UdpAssociation
{
    private bool $closed = false;

    /** @internal Construct through UdpDialer. */
    public function __construct(private $socket, private $control, private readonly UdpProfile $profile, private readonly ?Closure $resolver) {}

    public function __destruct()
    {
        $this->close();
    }

    public function close(): void
    {
        if (! $this->closed) {
            $this->closed = true;
            if (is_resource($this->socket)) {
                fclose($this->socket);
            }
            if (is_resource($this->control)) {
                fclose($this->control);
            }
        }
    }

    public function isClosed(): bool
    {
        if (! $this->closed && $this->control !== null) {
            $read = [$this->control];
            $write = [];
            $except = [];
            $ready = @stream_select($read, $write, $except, 0, 0);
            // No application bytes belong on a SOCKS UDP control connection.
            if ($ready === false || $ready > 0) {
                $this->close();
            }
        }

        return $this->closed;
    }

    /** @return array{host:string,port:int} */
    public function localAddress(): array
    {
        $this->assertOpen();

        return SocketAddress::split(stream_socket_get_name($this->socket, false));
    }

    private function assertOpen(): void
    {
        if ($this->isClosed()) {
            throw new UdpException('UDP association is closed.');
        }
    }

    private static function deadline(float $timeout): float
    {
        if (! is_finite($timeout) || $timeout <= 0 || $timeout > 300) {
            throw new InvalidArgumentException('UDP timeout must be finite, positive, and at most 300 seconds.');
        }

        return hrtime(true) / 1e9 + ceil($timeout * 1000) / 1000;
    }

    /** @internal */
    public static function resolve(string $host, float $deadline, ?Closure $resolver, bool $ipv4): string
    {
        $remaining = $deadline - hrtime(true) / 1e9;
        if ($remaining <= 0) {
            throw new UdpException('UDP operation timed out.');
        }
        if ($resolver !== null) {
            $addresses = $resolver($host, $remaining);
        } else {
            $addresses = @gethostbynamel($host) ?: [];
            if (! $ipv4 || $addresses === []) {
                foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                    if (isset($record['ipv6'])) {
                        $addresses[] = $record['ipv6'];
                    }
                }
            }
        }
        if (! is_array($addresses)) {
            throw new UdpException('UDP resolver must return an address list.');
        }
        foreach ($addresses as $ip) {
            if (is_string($ip)) {
                $ip = SocketAddress::normalizeIp($ip);
            }
            if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false && (! str_contains($ip, ':')) === $ipv4) {
                return $ip;
            }
        }
        throw new UdpException('UDP DNS returned no address in the socket family.');
    }

    private function wait(bool $writing, float $deadline): void
    {
        $this->assertOpen();
        $remaining = $deadline - hrtime(true) / 1e9;
        if ($remaining <= 0) {
            throw new UdpException('UDP operation timed out.');
        }
        $read = $this->control === null ? [] : [$this->control];
        if (! $writing) {
            $read[] = $this->socket;
        }
        $write = $writing ? [$this->socket] : [];
        $except = [];
        $seconds = (int) $remaining;
        $micros = min(999999, (int) ceil(($remaining - $seconds) * 1e6));
        $ready = @stream_select($read, $write, $except, $seconds, $micros);
        if ($ready === false) {
            throw new UdpException('UDP stream selection failed.');
        }
        if ($ready === 0) {
            throw new UdpException('UDP operation timed out.');
        }
        if ($this->control !== null && in_array($this->control, $read, true)) {
            $this->close();
            throw new UdpException('UDP control connection closed.');
        }
    }

    public function sendTo(string $payload, string $host, int $port, float $timeout = 10): int
    {
        $this->assertOpen();
        SocketAddress::validate($host, $port);
        if (strlen($payload) > Socks5Datagram::MAX_WIRE_SIZE) {
            throw new InvalidArgumentException('UDP datagram exceeds portable wire limit.');
        }
        $deadline = self::deadline($timeout);
        if (filter_var($host, FILTER_VALIDATE_IP) === false && ($this->control === null || str_starts_with($this->profile->data['proxy'], 'socks5://'))) {
            // A SOCKS payload can carry either family, independent of its relay hop.
            $family = $this->control === null ? ! str_contains($this->localAddress()['host'], ':') : null;
            if ($family === null) {
                // Try IPv4 then IPv6; an injected resolver's first valid result is authoritative.
                if ($this->resolver !== null) {
                    $addresses = ($this->resolver)($host, $deadline - hrtime(true) / 1e9);
                    $host = '';
                    if (is_array($addresses)) {
                        foreach ($addresses as $ip) {
                            if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                                $host = $ip;
                                break;
                            }
                        }
                    }
                    if ($host === '') {
                        throw new UdpException('SOCKS UDP DNS returned no addresses.');
                    }
                } else {
                    try {
                        $host = self::resolve($host, $deadline, null, true);
                    } catch (UdpException) {
                        $host = self::resolve($host, $deadline, null, false);
                    }
                }
            } else {
                $host = self::resolve($host, $deadline, $this->resolver, $family);
            }
        }
        $data = $this->control === null ? $payload : Socks5Datagram::encode($payload, $host, $port);
        $this->wait(true, $deadline);
        $sent = $this->control === null ? @stream_socket_sendto($this->socket, $data, 0, SocketAddress::text($host, $port)) : @stream_socket_sendto($this->socket, $data);
        if ($sent === false || $sent !== strlen($data)) {
            throw new UdpException('UDP send failed.');
        }

        return strlen($payload);
    }

    public function receive(float $timeout = 10): Datagram
    {
        $deadline = self::deadline($timeout);
        while (true) {
            $this->wait(false, $deadline);
            $data = @stream_socket_recvfrom($this->socket, 65535, 0, $source);
            if ($data === false) {
                continue;
            }
            if (strlen($data) > Socks5Datagram::MAX_WIRE_SIZE) {
                continue;
            }
            if ($this->control !== null) {
                $packet = Socks5Datagram::decode($data);
                if ($packet !== null) {
                    return $packet;
                }
            } else {
                $address = SocketAddress::split($source);

                return new Datagram($data, $address['host'], $address['port']);
            }
        }
    }
}
