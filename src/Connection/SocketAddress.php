<?php

declare(strict_types=1);

namespace Webong\Proxy\Connection;

use InvalidArgumentException;

final class SocketAddress
{
    public static function validate(string $host, int $port, bool $allowZero = false): void
    {
        if ($port < ($allowZero ? 0 : 1) || $port > 65535 || $host === ''
            || (filter_var($host, FILTER_VALIDATE_IP) === false
                && (strlen($host) > 253 || preg_match('/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*\.?$/D', $host) !== 1))) {
            throw new InvalidArgumentException('Invalid socket address.');
        }
    }

    public static function encode(string $host, int $port, bool $allowZero = false): string
    {
        self::validate($host, $port, $allowZero);
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $packed = inet_pton($host);
            if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
                $packed = substr($packed, 12);
            }

            return chr(strlen($packed) === 4 ? 1 : 4).$packed.pack('n', $port);
        }

        return chr(3).chr(strlen($host)).$host.pack('n', $port);
    }

    /** @return array{host:string,port:int}|null */
    public static function decode(string $data, int &$offset): ?array
    {
        if (strlen($data) <= $offset) {
            return null;
        }
        $tag = ord($data[$offset++]);
        $length = match ($tag) {
            1 => 4, 4 => 16, 3 => strlen($data) > $offset ? ord($data[$offset++]) : 0, default => 0
        };
        if ($length === 0 || strlen($data) < $offset + $length + 2) {
            return null;
        }
        $host = substr($data, $offset, $length);
        $offset += $length;
        if ($tag !== 3) {
            $host = self::normalizeIp(inet_ntop($host));
        }
        $port = unpack('n', substr($data, $offset, 2))[1];
        $offset += 2;
        try {
            self::validate($host, $port);
        } catch (InvalidArgumentException) {
            return null;
        }

        return ['host' => $host, 'port' => $port];
    }

    /** @return array{host:string,port:int} */
    public static function split(string $address): array
    {
        $pos = strrpos($address, ':');
        if ($pos === false) {
            throw new InvalidArgumentException('Invalid socket endpoint.');
        }

        return ['host' => self::normalizeIp(trim(substr($address, 0, $pos), '[]')), 'port' => (int) substr($address, $pos + 1)];
    }

    public static function normalizeIp(string $host): string
    {
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return $host;
        }
        $packed = inet_pton($host);
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
            $packed = substr($packed, 12);
        }

        return inet_ntop($packed);
    }

    public static function text(string $host, int $port): string
    {
        return (str_contains($host, ':') ? '['.$host.']' : $host).':'.$port;
    }
}
