<?php

declare(strict_types=1);

namespace Webong\Proxy;

use Closure;
use Illuminate\Support\Collection;
use Webong\Proxy\Contracts\EndpointProvider;
use Webong\Proxy\Laravel\DefaultRegistryStore;
use Webong\Proxy\Registry\Contracts\DestinationRepository;
use Webong\Proxy\Registry\Contracts\EndpointRepository;
use Webong\Proxy\Registry\Contracts\ProfileRepository;

/** Laravel compatibility facade over storage-neutral repository ports. */
class DatabaseEndpointProvider implements EndpointProvider
{
    private readonly EndpointRepository $endpoints;
    private readonly DestinationRepository $destinations;
    private readonly ProfileRepository $profiles;

    public function __construct(
        DatabaseEndpointStore $endpointStore,
        DatabaseDestinationAttacher $destinationAttacher,
        EndpointUrlGenerator $urlGenerator,
        ?EndpointRepository $endpoints = null,
        ?DestinationRepository $destinations = null,
        ?ProfileRepository $profiles = null,
    ) {
        // Container defaults must honor this constructor's original dependencies.
        // Explicit application repositories retain precedence over those defaults.
        $store = new LaravelRegistryStore($endpointStore, $destinationAttacher, $urlGenerator);
        $this->endpoints = $endpoints instanceof DefaultRegistryStore ? $store : ($endpoints ?? $store);
        $this->destinations = $destinations instanceof DefaultRegistryStore ? $store : ($destinations ?? $store);
        $this->profiles = $profiles instanceof DefaultRegistryStore ? $store : ($profiles ?? $store);
    }

    /** @return array<string, mixed> */
    public function capture(): array
    {
        return [];
    }

    public function run(Closure $closure, array $context = []): mixed
    {
        return $closure();
    }

    public function define(
        EndpointDefinition $definition,
        ProvisionedEndpoint $provisionedEndpoint,
    ): Endpoint
    {
        return new Endpoint($this->endpoints->define($definition, $provisionedEndpoint), $this);
    }

    public function find(EndpointDefinition $definition): ?Endpoint
    {
        $record = $this->endpoints->find($definition);

        return $record === null ? null : new Endpoint($record, $this);
    }

    public function resolveByKey(string $endpointKey): ?Endpoint
    {
        $record = $this->endpoints->resolveByKey($endpointKey);

        return $record === null ? null : new Endpoint($record, $this);
    }

    public function resolveById(string $id): ?Endpoint
    {
        $record = $this->endpoints->resolveById($id);

        return $record === null ? null : new Endpoint($record, $this);
    }

    public function findManaged(string $client, string $externalId): ?EndpointRecord
    {
        return $this->endpoints->findManaged($client, $externalId);
    }

    public function register(EndpointRecord $record, string $registry): void
    {
        $this->endpoints->register($record, $registry);
    }

    public function registryForKey(string $endpointKey): ?string
    {
        return $this->endpoints->registryForKey($endpointKey);
    }

    public function registryForId(string $id): ?string
    {
        return $this->endpoints->registryForId($id);
    }

    /** @param array<string, mixed> $registration */
    public function updateEndpoint(EndpointRecord $endpoint, array $registration): EndpointRecord
    {
        return $this->endpoints->updateEndpoint($endpoint, $registration);
    }

    public function attach(EndpointRecord $endpoint, DestinationDefinition $definition): DestinationRecord
    {
        return $this->destinations->attach($endpoint, $definition);
    }

    /** @return Collection<int, DestinationRecord> */
    public function destinationsFor(EndpointRecord $endpoint, WebhookRoute $route): Collection
    {
        return new Collection($this->destinations->destinationsFor($endpoint, $route));
    }

    public function destinationById(string $id): ?DestinationRecord
    {
        return $this->destinations->destinationById($id);
    }

    /** @param array<string, mixed> $attributes */
    public function updateDestination(DestinationRecord $destination, array $attributes): void
    {
        $this->destinations->updateDestination($destination, $attributes);
    }

    public function deactivateDestinations(
        string $ownerId,
        string $registrationId,
        string $webhookGroup,
    ): int
    {
        return $this->destinations->deactivateDestinations($ownerId, $registrationId, $webhookGroup);
    }

    public function hasActiveDestination(
        string $ownerId,
        string $registrationId,
        string $webhookGroup,
    ): bool
    {
        return $this->destinations->hasActiveDestination($ownerId, $registrationId, $webhookGroup);
    }

    public function registerProfile(EndpointRecord $endpoint, ProfileDefinition $definition): ProfileRecord
    {
        return $this->profiles->registerProfile($endpoint, $definition);
    }

    public function profile(EndpointRecord $endpoint, string $name): ?ProfileRecord
    {
        return $this->profiles->profile($endpoint, $name);
    }
}
