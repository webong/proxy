<?php

declare(strict_types=1);

namespace Webong\WebProxy;

use Webong\WebProxy\Contracts\EndpointProvider;

final class Endpoint
{
    public function __construct(
        public EndpointRecord $record,
        private readonly EndpointProvider $provider,
    ) {
    }

    public function attach(DestinationDefinition $definition): DestinationRecord
    {
        return $this->provider->attach(
            $this->record,
            $definition->withContext(array_replace(
                $this->provider->capture(),
                $definition->context,
            )),
        );
    }

    public function registerProfile(ProfileDefinition $definition): ProfileRecord
    {
        return $this->provider->registerProfile($this->record, $definition);
    }

    public function profile(string $name): ?ProfileRecord
    {
        return $this->provider->profile($this->record, $name);
    }

    public function callbackUrl(): string
    {
        return $this->record->callback_url;
    }

    public function updateRegistration(array $registration): self
    {
        $this->record = $this->provider->updateEndpoint($this->record, [
            'endpoint_key' => $registration['endpoint_key'] ?? $this->record->endpoint_key,
            'signing_secret' => $registration['signing_secret'] ?? $this->record->signing_secret,
            'verification_token' => $registration['verification_token'] ?? $this->record->verification_token,
            'credential_owner_id' => $registration['credential_owner_id'] ?? $this->record->credential_owner_id,
            'metadata' => array_merge($this->record->metadata, $registration['metadata'] ?? []),
            'is_managed' => $registration['is_managed'] ?? $this->record->is_managed,
            'is_active' => true,
        ]);

        return $this;
    }

    /** @param array<string, mixed> $attributes */
    public function update(array $attributes): self
    {
        $this->record = $this->provider->updateEndpoint($this->record, $attributes);

        return $this;
    }
}
