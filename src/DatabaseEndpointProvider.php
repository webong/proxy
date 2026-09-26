<?php

declare(strict_types=1);

namespace Webong\WebProxy;

use Webong\WebProxy\Contracts\EndpointProvider;
use Webong\WebProxy\Models\WebProxyEndpoint;
use Webong\WebProxy\Models\WebProxyEndpointRegistration;
use Webong\WebProxy\Models\WebProxyDestination;
use Webong\WebProxy\Models\ProxyProfile;
use Closure;
use Throwable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DatabaseEndpointProvider implements EndpointProvider
{
    public function __construct(
        private readonly DatabaseEndpointStore $endpointStore,
        private readonly DatabaseDestinationAttacher $destinationAttacher,
        private readonly EndpointUrlGenerator $urlGenerator,
    ) {
    }

    public function define(
        EndpointDefinition $definition,
        ProvisionedEndpoint $provisionedEndpoint,
    ): Endpoint {
        $record = $this->endpointStore->handle(new EndpointDefinition(
            client: $definition->client,
            externalId: $definition->externalId,
            signingSecret: $definition->signingSecret,
            verificationToken: $definition->verificationToken,
            endpointKey: $provisionedEndpoint->endpointKey ?? $definition->endpointKey,
            credentialOwnerId: $definition->credentialOwnerId,
            managed: $definition->managed,
            callbackUrl: $definition->callbackUrl,
            registry: $definition->registry,
            metadata: array_replace(
                $definition->metadata,
                $provisionedEndpoint->metadata,
                array_filter([
                    'callback_url' => $provisionedEndpoint->callbackUrl,
                ], static fn (mixed $value): bool => is_string($value) && $value !== ''),
            ),
        ));

        return new Endpoint($this->endpointRecord($record), $this);
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

    public function find(EndpointDefinition $definition): ?Endpoint
    {
        $record = WebProxyEndpoint::query()
            ->where('client', $definition->client)
            ->where('external_id', $definition->externalId)
            ->where('is_active', true)
            ->first();

        return $this->endpoint($record);
    }

    public function resolveByKey(string $endpointKey): ?Endpoint
    {
        if ($endpointKey === '') {
            return null;
        }

        $record = WebProxyEndpoint::query()
            ->where('endpoint_key', $endpointKey)
            ->where('is_active', true)
            ->first();

        return $this->endpoint($record);
    }

    public function resolveById(string $id): ?Endpoint
    {
        if ($id === '' || (! Str::isUuid($id) && ! Str::isUlid($id))) {
            return null;
        }

        $record = WebProxyEndpoint::query()
            ->whereKey($id)
            ->where('is_active', true)
            ->first();

        return $this->endpoint($record);
    }

    public function findManaged(string $client, string $externalId): ?EndpointRecord
    {
        $record = WebProxyEndpoint::query()
            ->where('client', $client)
            ->where('external_id', $externalId)
            ->where('is_managed', true)
            ->where('is_active', true)
            ->first();

        return $record instanceof WebProxyEndpoint
            ? $this->endpointRecord($record)
            : null;
    }

    public function register(EndpointRecord $record, string $registry): void
    {
        WebProxyEndpointRegistration::query()->updateOrCreate(
            [
                'owner_id' => (string) ($record->credential_owner_id ?? ''),
                'endpoint_id' => $record->id,
            ],
            [
                'endpoint_key' => $record->endpoint_key,
                'client' => $record->client,
                'registry' => $registry,
                'callback_url' => $record->callback_url,
                'is_active' => $record->is_active,
            ],
        );
    }

    public function registryForKey(string $endpointKey): ?string
    {
        if ($endpointKey === '') {
            return null;
        }

        $registry = WebProxyEndpointRegistration::query()
            ->where('endpoint_key', $endpointKey)
            ->where('is_active', true)
            ->value('registry');

        return is_string($registry) && $registry !== '' ? $registry : null;
    }

    public function registryForId(string $id): ?string
    {
        if ($id === '' || (! Str::isUuid($id) && ! Str::isUlid($id))) {
            return null;
        }

        $registry = WebProxyEndpointRegistration::query()
            ->where('endpoint_id', $id)
            ->where('is_active', true)
            ->value('registry');

        return is_string($registry) && $registry !== '' ? $registry : null;
    }

    public function attach(EndpointRecord $endpoint, DestinationDefinition $definition): DestinationRecord
    {
        $model = WebProxyEndpoint::query()->findOrFail($endpoint->id);

        return $this->destinationRecord(
            $this->destinationAttacher->handle($model, $definition),
        );
    }

    public function registerProfile(EndpointRecord $endpoint, ProfileDefinition $definition): ProfileRecord
    {
        $profile = ProxyProfile::query()->updateOrCreate(
            [
                'endpoint_id' => $endpoint->id,
                'name' => $definition->name,
            ],
            [
                'driver' => $definition->driver,
                'configuration' => $definition->configuration,
                'is_active' => true,
            ],
        );

        return $this->profileRecord($profile);
    }

    public function profile(EndpointRecord $endpoint, string $name): ?ProfileRecord
    {
        if ($name === '') {
            return null;
        }

        $profile = ProxyProfile::query()
            ->where('endpoint_id', $endpoint->id)
            ->where('name', $name)
            ->where('is_active', true)
            ->first();

        return $profile instanceof ProxyProfile ? $this->profileRecord($profile) : null;
    }

    /** @return Collection<int, DestinationRecord> */
    public function destinationsFor(EndpointRecord $endpoint, WebhookRoute $route): Collection
    {
        return WebProxyDestination::query()
            ->where('endpoint_id', $endpoint->id)
            ->where('routing_scope', $route->scope)
            ->where('routing_key', $route->key)
            ->where('is_active', true)
            ->oldest('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (WebProxyDestination $destination): DestinationRecord => $this->destinationRecord($destination));
    }

    public function destinationById(string $id): ?DestinationRecord
    {
        if ($id === '' || (! Str::isUuid($id) && ! Str::isUlid($id))) {
            return null;
        }

        $destination = WebProxyDestination::query()
            ->whereKey($id)
            ->where('is_active', true)
            ->first();

        return $destination instanceof WebProxyDestination
            ? $this->destinationRecord($destination)
            : null;
    }

    public function updateDestination(DestinationRecord $destination, array $attributes): void
    {
        WebProxyDestination::query()
            ->whereKey($destination->id)
            ->update($attributes);
    }

    public function updateEndpoint(EndpointRecord $endpoint, array $registration): EndpointRecord
    {
        $record = WebProxyEndpoint::query()->findOrFail($endpoint->id);
        $record->update($registration);
        $record->refresh();

        return $this->endpointRecord($record);
    }

    public function deactivateDestinations(
        string $ownerId,
        string $registrationId,
        string $webhookGroup,
    ): int {
        return WebProxyDestination::query()
            ->where('owner_id', $ownerId)
            ->where('registration_id', $registrationId)
            ->where('webhook_group', $webhookGroup)
            ->where('is_active', true)
            ->update(['is_active' => false]);
    }

    public function hasActiveDestination(
        string $ownerId,
        string $registrationId,
        string $webhookGroup,
    ): bool {
        return WebProxyDestination::query()
            ->where('owner_id', $ownerId)
            ->where('registration_id', $registrationId)
            ->where('webhook_group', $webhookGroup)
            ->where('is_active', true)
            ->exists();
    }

    private function endpoint(?WebProxyEndpoint $record): ?Endpoint
    {
        return $record instanceof WebProxyEndpoint
            ? new Endpoint($this->endpointRecord($record), $this)
            : null;
    }

    private function endpointRecord(WebProxyEndpoint $record): EndpointRecord
    {
        $endpoint = new EndpointRecord(
            id: (string) $record->getKey(),
            client: (string) $record->client,
            external_id: (string) $record->external_id,
            endpoint_key: (string) $record->endpoint_key,
            signing_secret: is_string($record->signing_secret) ? $record->signing_secret : null,
            verification_token: is_string($record->verification_token) ? $record->verification_token : null,
            credential_owner_id: is_string($record->credential_owner_id) ? $record->credential_owner_id : null,
            metadata: is_array($record->metadata) ? $record->metadata : [],
            is_managed: (bool) $record->is_managed,
            is_active: (bool) $record->is_active,
            callback_url: '',
        );

        try {
            return $endpoint->withCallbackUrl($this->urlGenerator->for($endpoint));
        } catch (Throwable) {
            return $endpoint;
        }
    }

    private function destinationRecord(WebProxyDestination $destination): DestinationRecord
    {
        return new DestinationRecord(
            id: (string) $destination->getKey(),
            endpoint_id: (string) $destination->endpoint_id,
            owner_id: (string) $destination->owner_id,
            registration_id: (string) $destination->registration_id,
            webhook_group: (string) $destination->webhook_group,
            routing_scope: (string) $destination->routing_scope,
            routing_key: (string) $destination->routing_key,
            target_type: $destination->target_type,
            target: (string) $destination->target,
            metadata: is_array($destination->metadata) ? $destination->metadata : [],
            is_active: (bool) $destination->is_active,
        );
    }

    private function profileRecord(ProxyProfile $profile): ProfileRecord
    {
        return new ProfileRecord(
            id: (string) $profile->getKey(),
            endpoint_id: (string) $profile->endpoint_id,
            name: (string) $profile->name,
            driver: (string) $profile->driver,
            configuration: is_array($profile->configuration) ? $profile->configuration : [],
            is_active: (bool) $profile->is_active,
        );
    }
}
