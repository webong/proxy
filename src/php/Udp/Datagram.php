<?php

declare(strict_types=1);

namespace Webong\Proxy\Udp;

final readonly class Datagram
{
    public function __construct(public string $payload, public string $host, public int $port) {}
}
