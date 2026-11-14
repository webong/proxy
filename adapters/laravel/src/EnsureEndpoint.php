<?php

declare(strict_types=1);

namespace Webong\Proxy;

final class EnsureEndpoint
{
    public function __construct(
        private readonly DatabaseEndpointProvider $provider,
        private readonly DatabaseEndpointDriver $driver,
        private readonly EndpointKeyResolver $endpointKeyResolver,
    ) {
    }

    public function handle(EndpointDefinition $definition): Endpoint
    {
        $definition = $this->endpointKeyResolver->resolve($definition);

        return $this->provider->define(
            $definition,
            $this->driver->provision($definition),
        );
    }
}
