<?php

declare(strict_types=1);

namespace Webong\Proxy\Connection;

use Webong\Proxy\Tcp\DialException;

/** @internal Transport implementation helper; not a supported consumer API. */
final class Socks5Session
{
    public static function authenticate($stream, array $proxy, float $deadline): void
    {
        $method = isset($proxy['user']) ? 2 : 0;
        self::write($stream, "\x05\x01".chr($method), $deadline);
        $reply = self::read($stream, 2, $deadline);
        if (ord($reply[0]) !== 5) {
            throw new DialException('Invalid SOCKS5 response.');
        }
        if (ord($reply[1]) !== $method) {
            throw new DialException('SOCKS5 authentication failed.');
        }
        if ($method === 2) {
            $user = rawurldecode($proxy['user']);
            $password = rawurldecode($proxy['pass']);
            self::write($stream, "\x01".chr(strlen($user)).$user.chr(strlen($password)).$password, $deadline);
            if (self::read($stream, 2, $deadline) !== "\x01\x00") {
                throw new DialException('SOCKS5 authentication failed.');
            }
        }
    }

    /** @return array{host:string,port:int} */
    public static function request($stream, int $command, string $address, float $deadline): array
    {
        self::write($stream, "\x05".chr($command)."\x00".$address, $deadline);
        $header = self::read($stream, 3, $deadline);
        if (ord($header[0]) !== 5 || ord($header[2]) !== 0) {
            throw new DialException('Invalid SOCKS5 response.');
        }
        if (ord($header[1]) !== 0) {
            throw new DialException('SOCKS5 request rejected.', ord($header[1]));
        }
        $tag = ord(self::read($stream, 1, $deadline));
        $length = match ($tag) {
            1 => 4, 4 => 16, 3 => ord(self::read($stream, 1, $deadline)), default => 0
        };
        if ($length === 0) {
            throw new DialException('Invalid SOCKS5 address response.');
        }
        $host = self::read($stream, $length, $deadline);

        return ['host' => $tag === 3 ? $host : SocketAddress::normalizeIp(inet_ntop($host)), 'port' => unpack('n', self::read($stream, 2, $deadline))[1]];
    }

    public static function remaining(float $deadline): float
    {
        $remaining = $deadline - hrtime(true) / 1e9;
        if ($remaining <= 0) {
            throw new DialException('Socket connection setup timed out.');
        }

        return $remaining;
    }

    public static function wait($stream, bool $writing, float $deadline): void
    {
        $remaining = self::remaining($deadline);
        $seconds = (int) $remaining;
        $microseconds = min(999999, (int) ceil(($remaining - $seconds) * 1e6));
        $read = $writing ? [] : [$stream];
        $write = $writing ? [$stream] : [];
        $except = [];
        $ready = @stream_select($read, $write, $except, $seconds, $microseconds);
        if ($ready === false) {
            throw new DialException('Socket stream selection failed.');
        }
        if ($ready === 0) {
            throw new DialException('Socket connection setup timed out.');
        }
    }

    public static function read($stream, int $length, float $deadline): string
    {
        $data = '';
        while (strlen($data) < $length) {
            self::wait($stream, false, $deadline);
            $chunk = @fread($stream, $length - strlen($data));
            if ($chunk === false || ($chunk === '' && feof($stream))) {
                throw new DialException('Socket connection closed during handshake.');
            }
            $data .= $chunk;
        }

        return $data;
    }

    public static function write($stream, string $data, float $deadline): void
    {
        while ($data !== '') {
            self::wait($stream, true, $deadline);
            $written = @fwrite($stream, $data);
            if ($written === false || $written === 0) {
                throw new DialException('Socket handshake write failed.');
            }
            $data = substr($data, $written);
        }
    }
}
