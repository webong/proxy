<?php

declare(strict_types=1);

namespace Webong\Proxy;

use Webong\Proxy\Contracts\EndpointRegistrar;
use Closure;

class EndpointRegistry
{
    public function __construct(
        private readonly WebProxyRegistryManager $registryManager,
    ) {
    }

    /** @param array<string, mixed> $context */
    public function ensure(EndpointDefinition $definition, array $context = []): Endpoint
    {
        return $this->withEndpoint(
            $definition,
            fn (Endpoint $endpoint): Endpoint => $endpoint,
            $context,
        );
    }

    /** @param array<string, mixed> $context */
    public function find(EndpointDefinition $definition, array $context = []): ?Endpoint
    {
        return $this->registrar($definition, $context)->find($definition);
    }

    /**
     * @template TResult
     * @param  Closure(Endpoint): TResult  $callback
     * @param  array<string, mixed>  $context
     * @return TResult
     */
    public function withEndpoint(
        EndpointDefinition $definition,
        Closure $callback,
        array $context = [],
    ): mixed {
        $registry = $this->resolveRegistryName($definition, $context);
        $registrar = $this->registryManager->registry($registry);

        $endpoint = $registrar->define($definition);

        $registrar->run(
            fn () => $this->stampRegistry($endpoint, $definition, $context),
        );

        $this->registerRegistrations($endpoint, $registry);

        return $registrar->run(
            fn (): mixed => $callback($endpoint),
        );
    }

    /**
     * Index the endpoint in both the caller's default provider and the owning
     * registry's provider so ingress can locate its storage context.
     */
    private function registerRegistrations(Endpoint $endpoint, string $registry): void
    {
        $this->registryManager->registry()->register($endpoint->record, $registry);

        $default = $this->registryManager->getDefaultDriver();

        if ($registry !== $default) {
            $this->registryManager->registry($registry)->register($endpoint->record, $registry);
        }
    }

    /**
     * Resolve the endpoint registrar for the operation.
     *
     * Registry selection order: explicit context override, then the
     * definition's registry, then the configured default.
     *
     * @param  array<string, mixed>  $context
     */
    private function registrar(EndpointDefinition $definition, array $context): EndpointRegistrar
    {
        return $this->registryManager->registry($this->resolveRegistryName($definition, $context));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function resolveRegistryName(EndpointDefinition $definition, array $context): string
    {
        $explicit = $context['registry'] ?? null;

        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        if (is_string($definition->registry) && $definition->registry !== '') {
            return $definition->registry;
        }

        return $this->registryManager->getDefaultDriver();
    }

    /**
     * Mark non-default registries on the endpoint so its origin is
     * self-describing to later readers.
     *
     * @param  array<string, mixed>  $context
     */
    private function stampRegistry(
        Endpoint $endpoint,
        EndpointDefinition $definition,
        array $context,
    ): void {
        $registry = $this->resolveRegistryName($definition, $context);

        if ($registry === $this->registryManager->getDefaultDriver()) {
            return;
        }

        $metadata = $endpoint->record->metadata ?? [];

        if (($metadata[EndpointRecord::REGISTRY_METADATA_KEY] ?? null) === $registry) {
            return;
        }

        $endpoint->update([
            'metadata' => [
                ...$metadata,
                EndpointRecord::REGISTRY_METADATA_KEY => $registry,
            ],
        ]);
    }
}
