<?php

declare(strict_types=1);

namespace Webong\Proxy\Registry\Contracts;

use Webong\Proxy\DestinationDefinition;
use Webong\Proxy\DestinationRecord;
use Webong\Proxy\EndpointRecord;
use Webong\Proxy\WebhookRoute;

/**
 * Storage port; implementations own persistence, transactions and schema.
 * See the package README for identity, atomicity and error semantics.
 */
interface DestinationRepository
{
    public function attach(EndpointRecord $endpoint, DestinationDefinition $definition): DestinationRecord;

    /** @return list<DestinationRecord> Active records in stable creation order. */
    public function destinationsFor(EndpointRecord $endpoint, WebhookRoute $route): array;

    public function destinationById(string $id): ?DestinationRecord;

    /** @param array<string, mixed> $attributes */
    public function updateDestination(DestinationRecord $destination, array $attributes): void;

    public function deactivateDestinations(
        string $ownerId,
        string $registrationId,
        string $webhookGroup,
    ): int;

    public function hasActiveDestination(
        string $ownerId,
        string $registrationId,
        string $webhookGroup,
    ): bool;
}
