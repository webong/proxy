<?php

declare(strict_types=1);

namespace Webong\Proxy\Contracts;

use Webong\Proxy\Endpoint;
use Webong\Proxy\EndpointDefinition;
use Webong\Proxy\EndpointRecord;

interface EndpointRegistrar extends ExecutionContext
{
    /**
     * Register or retrieve an endpoint based on the definition.
     */
    public function define(EndpointDefinition $definition): Endpoint;

    /**
     * Find an existing endpoint matching the definition.
     */
    public function find(EndpointDefinition $definition): ?Endpoint;

    /**
     * Resolve an active endpoint by its public endpoint key.
     */
    public function resolveByKey(string $endpointKey): ?Endpoint;

    /**
     * Resolve an active endpoint by its primary key.
     */
    public function resolveById(string $id): ?Endpoint;

    /** Record which registry owns the endpoint in this provider's index. */
    public function register(EndpointRecord $record, string $registry): void;

    /**
     * Resolve the registry that owns an endpoint, from its public key.
     */
    public function registryForKey(string $endpointKey): ?string;

    /**
     * Resolve the registry that owns an endpoint, from its primary key.
     */
    public function registryForId(string $id): ?string;

    public function provider(): EndpointProvider;
}
