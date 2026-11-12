<?php

declare(strict_types=1);

namespace Webong\Proxy\Connection;

use InvalidArgumentException;
use JsonSerializable;
use stdClass;

readonly class SocketProfile implements JsonSerializable
{
    protected const PROXY_SCHEMES = ['socks5', 'socks5h'];

    public function __construct(public array $data = ['version' => 1])
    {
        if (($data['version'] ?? null) !== 1 || array_diff(array_keys($data), ['version', 'proxy', 'connect_timeout_seconds'])) {
            throw new InvalidArgumentException('Invalid Socket profile version or fields.');
        }
        if (array_key_exists('proxy', $data)) {
            if (! is_string($data['proxy'])) {
                throw new InvalidArgumentException('Socket proxy must be a supported URL.');
            }
            $parts = ProxyUrl::parse($data['proxy']);
            if (! in_array($parts['scheme'], static::PROXY_SCHEMES, true)) {
                throw new InvalidArgumentException('Unsupported socket proxy scheme.');
            }
            if (in_array($parts['scheme'], ['http', 'https'], true)) {
                ProxyUrl::validateConnectCredentials($parts);
            }
        }
        if (array_key_exists('connect_timeout_seconds', $data)) {
            $n = $data['connect_timeout_seconds'];
            if ((! is_int($n) && ! is_float($n)) || ! is_finite((float) $n) || $n <= 0 || $n > 300) {
                throw new InvalidArgumentException('Socket timeout must be finite, positive, and at most 300 seconds.');
            }
        }
    }

    public static function fromArray(array $data): static
    {
        return new static($data);
    }

    public static function fromJson(string $json): static
    {
        $data = json_decode($json, flags: JSON_THROW_ON_ERROR);
        if (! $data instanceof stdClass) {
            throw new InvalidArgumentException('Socket profile must be an object.');
        }

        return new static((array) $data);
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function jsonSerialize(): array
    {
        return $this->data;
    }

    public function toJson(): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
