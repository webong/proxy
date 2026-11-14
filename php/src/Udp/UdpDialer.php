<?php

declare(strict_types=1);

namespace Webong\Proxy\Udp;

use Closure;
use InvalidArgumentException;
use Throwable;
use Webong\Proxy\Connection\ProxyUrl;
use Webong\Proxy\Connection\SocketAddress;
use Webong\Proxy\Connection\Socks5Session;

final class UdpDialer
{
    private readonly ?Closure $connector;

    private readonly ?Closure $resolver;

    /** Connector configures the TCP hop only. Resolver accepts (host, remainingSeconds). */
    public function __construct(private readonly UdpProfile $profile, ?callable $connector = null, ?callable $resolver = null)
    {
        $this->connector = $connector === null ? null : Closure::fromCallable($connector);
        $this->resolver = $resolver === null ? null : Closure::fromCallable($resolver);
    }

    public function open(?string $bindHost = null, int $bindPort = 0): UdpAssociation
    {
        if ($bindPort < 0 || $bindPort > 65535 || ($bindHost !== null && filter_var($bindHost, FILTER_VALIDATE_IP) === false)) {
            throw new InvalidArgumentException('UDP binding requires a numeric address and port.');
        }
        if ($bindHost !== null) {
            $bindHost = SocketAddress::normalizeIp($bindHost);
        }
        $data = $this->profile->data;
        $deadline = hrtime(true) / 1e9 + ceil(($data['connect_timeout_seconds'] ?? 10) * 1000) / 1000;
        $control = null;
        $socket = null;
        try {
            if (! isset($data['proxy'])) {
                $socket = @stream_socket_server('udp://'.SocketAddress::text($bindHost ?? '0.0.0.0', $bindPort), $errno, $error, STREAM_SERVER_BIND);
            } else {
                $proxy = ProxyUrl::parse($data['proxy']);
                $hop = trim($proxy['host'], '[]');
                $port = $proxy['port'] ?? 1080;
                $remaining = Socks5Session::remaining($deadline);
                $control = $this->connector !== null ? ($this->connector)($hop, $port, $remaining)
                    : @stream_socket_client('tcp://'.SocketAddress::text($hop, $port), $errno, $error, $remaining);
                if (! is_resource($control)) {
                    throw new UdpException('UDP control connection failed.');
                }
                if (! stream_set_blocking($control, false)) {
                    throw new UdpException('Cannot configure UDP control connection.');
                }
                Socks5Session::authenticate($control, $proxy, $deadline);
                $relay = Socks5Session::request($control, 3, SocketAddress::encode('0.0.0.0', 0, true), $deadline);
                SocketAddress::validate($relay['host'], $relay['port']);
                $peer = SocketAddress::split(stream_socket_get_name($control, true));
                $source = SocketAddress::split(stream_socket_get_name($control, false));
                if (in_array($relay['host'], ['0.0.0.0', '::'], true)) {
                    $relay['host'] = $peer['host'];
                }
                if (filter_var($relay['host'], FILTER_VALIDATE_IP) === false) {
                    $relay['host'] = UdpAssociation::resolve($relay['host'], $deadline, $this->resolver, ! str_contains($source['host'], ':'));
                }
                if (in_array($relay['host'], ['0.0.0.0', '::'], true)) {
                    throw new UdpException('Invalid SOCKS UDP relay.');
                }
                if (str_contains($relay['host'], ':') !== str_contains($source['host'], ':')) {
                    throw new UdpException('SOCKS UDP relay and TCP source address families differ.');
                }
                if ($bindHost === null || in_array($bindHost, ['0.0.0.0', '::'], true)) {
                    $bindHost = $source['host'];
                }
                if (inet_pton($bindHost) !== inet_pton($source['host'])) {
                    throw new UdpException('SOCKS UDP must use the TCP source IP.');
                }
                $context = stream_context_create(['socket' => ['bindto' => SocketAddress::text($bindHost, $bindPort)]]);
                $socket = @stream_socket_client('udp://'.SocketAddress::text($relay['host'], $relay['port']), $errno, $error, Socks5Session::remaining($deadline), STREAM_CLIENT_CONNECT, $context);
            }
            if (! is_resource($socket) || ! stream_set_blocking($socket, false)) {
                throw new UdpException('UDP socket creation failed.');
            }
            Socks5Session::remaining($deadline);

            return new UdpAssociation($socket, $control, $this->profile, $this->resolver);
        } catch (Throwable $exception) {
            if (is_resource($socket)) {
                fclose($socket);
            }
            if (is_resource($control)) {
                fclose($control);
            }
            if ($exception instanceof UdpException) {
                throw $exception;
            }
            throw new UdpException('UDP setup failed: '.$exception->getMessage(), (int) $exception->getCode(), $exception);
        }
    }
}
