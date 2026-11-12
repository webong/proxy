<?php

declare(strict_types=1);

namespace Webong\Proxy\Tcp;

use InvalidArgumentException;
use JsonSerializable;
use stdClass;
use Webong\Proxy\Connection\ProxyUrl;

final readonly class TcpProfile implements JsonSerializable
{
    public function __construct(public array $data = ['version' => 1])
    {
        if (($data['version'] ?? null) !== 1 || array_diff(array_keys($data), ['version', 'proxy', 'connect_timeout_seconds'])) {
            throw new InvalidArgumentException('Invalid TCP profile version or fields.');
        }
        if (array_key_exists('proxy', $data)) {
            if (! is_string($data['proxy'])) {
                throw new InvalidArgumentException('TCP proxy must be a SOCKS URL.');
            }
            $parts = ProxyUrl::parse($data['proxy']);
            if (! in_array($parts['scheme'], ['socks5', 'socks5h'], true)) {
                throw new InvalidArgumentException('TCP proxy must use socks5 or socks5h.');
            }
        }
        if (array_key_exists('connect_timeout_seconds', $data)) {
            $n = $data['connect_timeout_seconds'];
            if ((! is_int($n) && ! is_float($n)) || ! is_finite((float) $n) || $n <= 0 || $n > 300) {
                throw new InvalidArgumentException('TCP timeout must be finite, positive, and at most 300 seconds.');
            }
        }
    }

    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, flags: JSON_THROW_ON_ERROR);
        if (! $data instanceof stdClass) {
            throw new InvalidArgumentException('TCP profile must be an object.');
        }

        return new self((array) $data);
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
