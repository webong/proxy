<?php

declare(strict_types=1);

namespace Webong\Proxy\Registry\Contracts;

use Webong\Proxy\EndpointDefinition;
use Webong\Proxy\EndpointRecord;
use Webong\Proxy\ProvisionedEndpoint;

/**
 * Storage port; implementations own persistence, transactions and schema.
 * See the package README for identity, atomicity and error semantics.
 */
interface EndpointRepository
{
    public function define(
        EndpointDefinition $definition,
        ProvisionedEndpoint $provisionedEndpoint,
    ): EndpointRecord;

    public function find(EndpointDefinition $definition): ?EndpointRecord;

    public function resolveByKey(string $endpointKey): ?EndpointRecord;

    public function resolveById(string $id): ?EndpointRecord;

    public function findManaged(string $client, string $externalId): ?EndpointRecord;

    public function register(EndpointRecord $record, string $registry): void;

    public function registryForKey(string $endpointKey): ?string;

    public function registryForId(string $id): ?string;

    /** @param array<string, mixed> $registration */
    public function updateEndpoint(EndpointRecord $endpoint, array $registration): EndpointRecord;
}
