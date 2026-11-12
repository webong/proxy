<?php

declare(strict_types=1);

namespace Webong\Proxy\Http;

use InvalidArgumentException;
use JsonSerializable;
use stdClass;
use Webong\Proxy\Connection\ProxyUrl;

/** Framework-independent, versioned HTTP connection settings. */
final readonly class HttpProfile implements JsonSerializable
{
    /** @param array<string, mixed> $data */
    public function __construct(public array $data = ['version' => 1])
    {
        self::validate($data);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /** Parse JSON without losing the distinction between objects and lists. */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, flags: JSON_THROW_ON_ERROR);
        if (! $data instanceof stdClass) {
            throw new InvalidArgumentException('HTTP profile must be an object.');
        }
        if (property_exists($data, 'proxy')) {
            if (! $data->proxy instanceof stdClass) {
                throw new InvalidArgumentException('HTTP profile proxy must be an object.');
            }
            $data->proxy = (array) $data->proxy;
        }

        return new self((array) $data);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    public function toJson(): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function jsonSerialize(): array
    {
        $data = $this->data;
        if (isset($data['proxy']) && $data['proxy'] === []) {
            $data['proxy'] = new stdClass();
        }

        return $data;
    }

    /** Select a proxy without relying on Guzzle/cURL version-specific bypass rules. */
    public function proxyFor(string $scheme, string $host): ?string
    {
        $proxy = $this->data['proxy'] ?? [];
        $host = strtolower($host);
        $targetIsIp = filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false;
        if (! $targetIsIp) {
            $host = str_ends_with($host, '.') ? substr($host, 0, -1) : $host;
        }
        foreach ($proxy['no_proxy'] ?? [] as $entry) {
            $entry = strtolower($entry);
            if ($entry === '*') {
                return null;
            }
            $ruleIsIp = filter_var($entry, FILTER_VALIDATE_IP) !== false;
            if ($targetIsIp || $ruleIsIp) {
                if ($targetIsIp && $ruleIsIp && $host === $entry) {
                    return null;
                }
                continue;
            }
            if (str_starts_with($entry, '.')) {
                if (str_ends_with($host, $entry)) {
                    return null;
                }
            } elseif ($host === $entry || str_ends_with($host, '.'.$entry)) {
                return null;
            }
        }

        return $proxy[$scheme] ?? null;
    }

    private static function validate(array $data): void
    {
        if (($data['version'] ?? null) !== 1
            || array_diff(array_keys($data), ['version', 'proxy', 'connect_timeout_seconds', 'request_timeout_seconds'])) {
            throw new InvalidArgumentException('Invalid HTTP profile version or fields.');
        }

        foreach (['connect_timeout_seconds', 'request_timeout_seconds'] as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $seconds = $data[$key];
            if ((! is_int($seconds) && ! is_float($seconds))
                || ! is_finite((float) $seconds) || $seconds <= 0 || $seconds > 300) {
                throw new InvalidArgumentException('HTTP profile timeouts must be finite, positive, and at most 300 seconds.');
            }
        }

        if (! array_key_exists('proxy', $data)) {
            return;
        }
        $proxy = $data['proxy'];
        if (! is_array($proxy) || array_diff(array_keys($proxy), ['http', 'https', 'no_proxy'])) {
            throw new InvalidArgumentException('Invalid HTTP profile proxy fields.');
        }
        foreach (['http', 'https'] as $scheme) {
            if (! array_key_exists($scheme, $proxy)) {
                continue;
            }
            $url = $proxy[$scheme];
            if (! is_string($url)) {
                throw new InvalidArgumentException('Proxy URL must be a string.');
            }
            ProxyUrl::parse($url);
        }

        $hosts = array_key_exists('no_proxy', $proxy) ? $proxy['no_proxy'] : [];
        if (! is_array($hosts) || ! array_is_list($hosts)) {
            throw new InvalidArgumentException('no-proxy hosts must be a list.');
        }
        foreach ($hosts as $host) {
            if (! is_string($host)
                || ($host !== '*' && (preg_match('/^\.?[a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_-]+)*$/D', $host) !== 1
                    || (str_starts_with($host, '.') && filter_var(substr($host, 1), FILTER_VALIDATE_IP) !== false)))) {
                throw new InvalidArgumentException('no-proxy hosts must be hostnames, IPv4 addresses, leading-dot domains, or *.');
            }
        }
    }
}
