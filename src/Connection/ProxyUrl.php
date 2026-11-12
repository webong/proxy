<?php

declare(strict_types=1);

namespace Webong\Proxy\Connection;

use InvalidArgumentException;

/**
 * Shared URL validation; error messages never contain credential values.
 *
 * @internal Transport implementation helper; not a supported consumer API.
 */
final class ProxyUrl
{
    /** @return array<string, mixed> */
    public static function parse(string $value): array
    {
        $parts = parse_url($value);
        if (! is_array($parts)
            || preg_match("~^(?:https?|socks5h?)://(?:[A-Za-z0-9._\~!\$&'()*+,;=:%-]*@)?(?:\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*\.?)(?::[0-9]{1,5})?/?\$~D", $value) !== 1
            || preg_match('/%(?:[^0-9A-Fa-f]|[0-9A-Fa-f](?:[^0-9A-Fa-f]|$)|$)/', $value)
            || ($parts['host'] ?? '') === ''
            || (str_starts_with($parts['host'], '[') && filter_var(trim($parts['host'], '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false)
            || (isset($parts['port']) && $parts['port'] < 1)
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Invalid proxy URL.');
        }
        if (str_starts_with($parts['scheme'], 'socks5') && isset($parts['user'])) {
            $user = rawurldecode($parts['user']);
            $password = rawurldecode($parts['pass'] ?? '');
            if (strlen($user) < 1 || strlen($user) > 255 || strlen($password) < 1 || strlen($password) > 255) {
                throw new InvalidArgumentException('SOCKS credentials must each contain 1 to 255 bytes.');
            }
        }

        return $parts;
    }

    /** Portable HTTP must reject credential bytes cURL's URL parser cannot represent. */
    public static function parseHttp(string $value): array
    {
        $parts = self::parse($value);
        if (isset($parts['user'])) {
            foreach ([$parts['user'], $parts['pass'] ?? ''] as $field) {
                if (preg_match('/[\x00-\x1f]/', rawurldecode($field))) {
                    throw new InvalidArgumentException('Portable HTTP proxy credentials cannot contain C0 control bytes.');
                }
            }
        }

        return $parts;
    }

    public static function validateConnectCredentials(array $parts): void
    {
        if (! isset($parts['user'])) {
            return;
        }
        $user = rawurldecode($parts['user']);
        $password = rawurldecode($parts['pass'] ?? '');
        if (! array_key_exists('pass', $parts) || strlen($user) < 1 || strlen($user) > 255 || strlen($password) > 255
            || str_contains($user, ':') || preg_match('/[\x00-\x1f\x7f]/', $user.$password)) {
            throw new InvalidArgumentException('Invalid CONNECT credentials.');
        }
    }
}
