<?php

declare(strict_types=1);

namespace Webong\Proxy\Connection;

use Webong\Proxy\Tcp\DialException;

/** HTTP/1.x CONNECT. Reads exactly through headers, preserving tunnel bytes. */
final class HttpConnectSession
{
    public static function connect($stream, array $proxy, string $host, int $port, float $deadline): void
    {
        $authority = (str_contains($host, ':') ? '['.$host.']' : $host).':'.$port;
        $request = 'CONNECT '.$authority." HTTP/1.1\r\nHost: ".$authority."\r\n";
        if (isset($proxy['user'])) {
            $request .= 'Proxy-Authorization: Basic '.base64_encode(rawurldecode($proxy['user']).':'.rawurldecode($proxy['pass']))."\r\n";
        }
        Socks5Session::write($stream, $request."\r\n", $deadline);
        $budget = 32768;
        for ($interim = 0; ; $interim++) {
            $line = self::line($stream, $deadline, $budget);
            if (preg_match('/^HTTP\/1\.[01] ([1-5][0-9]{2}) [\t\x20-\x7e\x80-\xff]*$/D', $line, $match) !== 1) {
                throw new DialException('Invalid HTTP CONNECT status.');
            }
            $status = (int) $match[1];
            while (($line = self::line($stream, $deadline, $budget)) !== '') {
                if (preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+:[\\t\\x20-\\x7e\\x80-\\xff]*$/D", $line) !== 1) {
                    throw new DialException('Invalid HTTP CONNECT header.');
                }
            }
            if ($status >= 100 && $status < 200 && $status !== 101 && $interim < 8) {
                continue;
            }
            if ($status >= 200 && $status < 300) {
                return;
            }
            throw new DialException('HTTP CONNECT rejected (status '.$status.').', $status);
        }
    }

    private static function line($stream, float $deadline, int &$budget): string
    {
        $line = '';
        while ($budget-- > 0) {
            $byte = Socks5Session::read($stream, 1, $deadline);
            $line .= $byte;
            if ($byte === "\n") {
                if (! str_ends_with($line, "\r\n")) {
                    throw new DialException('Invalid HTTP CONNECT line ending.');
                }

                return substr($line, 0, -2);
            }
        }
        throw new DialException('HTTP CONNECT headers exceed 32768 bytes.');
    }
}
