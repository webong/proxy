<?php

declare(strict_types=1);

namespace Webong\Proxy;

final readonly class EndpointRecord
{
    public const string REGISTRY_METADATA_KEY = '_registry';

    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $id,
        public string $client,
        public string $external_id,
        public string $endpoint_key,
        public ?string $signing_secret,
        public ?string $verification_token,
        public ?string $credential_owner_id,
        public array $metadata,
        public bool $is_managed,
        public bool $is_active,
        public string $callback_url,
    ) {
    }

    public function withCallbackUrl(string $callbackUrl): self
    {
        return new self(
            id: $this->id,
            client: $this->client,
            external_id: $this->external_id,
            endpoint_key: $this->endpoint_key,
            signing_secret: $this->signing_secret,
            verification_token: $this->verification_token,
            credential_owner_id: $this->credential_owner_id,
            metadata: $this->metadata,
            is_managed: $this->is_managed,
            is_active: $this->is_active,
            callback_url: $callbackUrl,
        );
    }
}
