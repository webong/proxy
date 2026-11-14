<?php

declare(strict_types=1);

namespace Webong\Proxy\Udp;

use InvalidArgumentException;
use Webong\Proxy\Connection\SocketAddress;

final class Socks5Datagram
{
    public const MAX_WIRE_SIZE = 65507;

    public static function encode(string $payload, string $host, int $port): string
    {
        $header = "\0\0\0".SocketAddress::encode($host, $port);
        if (strlen($payload) > self::MAX_WIRE_SIZE - strlen($header)) {
            throw new InvalidArgumentException('UDP datagram exceeds portable wire limit.');
        }

        return $header.$payload;
    }

    public static function maxPayloadSize(string $host, int $port): int
    {
        return self::MAX_WIRE_SIZE - 3 - strlen(SocketAddress::encode($host, $port));
    }

    public static function decode(string $data): ?Datagram
    {
        if (strlen($data) < 4 || strlen($data) > self::MAX_WIRE_SIZE || substr($data, 0, 3) !== "\0\0\0") {
            return null;
        }
        $offset = 3;
        $address = SocketAddress::decode($data, $offset);

        return $address === null ? null : new Datagram(substr($data, $offset), $address['host'], $address['port']);
    }
}
