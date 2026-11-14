<?php

declare(strict_types=1);

namespace Webong\Proxy;

use Webong\Proxy\Contracts\EndpointRegistrar;

final readonly class ResolvedEndpoint
{
    public function __construct(
        public string $registry,
        public EndpointRegistrar $registrar,
        public Endpoint $endpoint,
    ) {
    }
}
