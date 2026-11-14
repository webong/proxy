<?php

declare(strict_types=1);

namespace Webong\Proxy;

final readonly class EndpointDefinition
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $client,
        public string $externalId,
        public ?string $signingSecret,
        public ?string $verificationToken,
        public ?string $endpointKey = null,
        public ?string $credentialOwnerId = null,
        public bool $managed = false,
        public ?string $callbackUrl = null,
        public ?string $registry = null,
        public array $metadata = [],
    ) {
    }

    public function withEndpointKey(?string $endpointKey): self
    {
        return new self(
            client: $this->client,
            externalId: $this->externalId,
            signingSecret: $this->signingSecret,
            verificationToken: $this->verificationToken,
            endpointKey: $endpointKey,
            credentialOwnerId: $this->credentialOwnerId,
            managed: $this->managed,
            callbackUrl: $this->callbackUrl,
            registry: $this->registry,
            metadata: $this->metadata,
        );
    }
}
