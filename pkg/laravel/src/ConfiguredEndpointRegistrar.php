<?php

declare(strict_types=1);

namespace Webong\Proxy;

use Closure;
use Webong\Proxy\Contracts\EndpointDriver;
use Webong\Proxy\Contracts\EndpointProvider;
use Webong\Proxy\Contracts\EndpointRegistrar;

final class ConfiguredEndpointRegistrar implements EndpointRegistrar
{
    public function __construct(
        private readonly EndpointDriver $driver,
        private readonly EndpointProvider $provider,
        private readonly EndpointKeyResolver $endpointKeyResolver,
    ) {
    }

    public function define(EndpointDefinition $definition): Endpoint
    {
        $definition = $this->endpointKeyResolver->resolve($definition);

        return $this->provider->define(
            $definition,
            $this->driver->provision($definition),
        );
    }

    public function find(EndpointDefinition $definition): ?Endpoint
    {
        return $this->provider->find($definition);
    }

    public function resolveByKey(string $endpointKey): ?Endpoint
    {
        return $this->provider->resolveByKey($endpointKey);
    }

    public function resolveById(string $id): ?Endpoint
    {
        return $this->provider->resolveById($id);
    }

    public function register(EndpointRecord $record, string $registry): void
    {
        $this->provider->register($record, $registry);
    }

    public function registryForKey(string $endpointKey): ?string
    {
        return $this->provider->registryForKey($endpointKey);
    }

    public function registryForId(string $id): ?string
    {
        return $this->provider->registryForId($id);
    }

    /** @return array<string, mixed> */
    public function capture(): array
    {
        return $this->provider->capture();
    }

    public function run(Closure $closure, array $context = []): mixed
    {
        return $this->provider->run($closure, $context);
    }

    public function provider(): EndpointProvider
    {
        return $this->provider;
    }

}
