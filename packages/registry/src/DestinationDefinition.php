<?php

declare(strict_types=1);

namespace Webong\Proxy;

use Webong\Proxy\Enums\WebhookProxyTargetType;

final readonly class DestinationDefinition
{
    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $context
     */
    public function __construct(
        public string $ownerId,
        public string $registrationId,
        public string $webhookGroup,
        public string $routingScope,
        public string $routingKey,
        public string $target,
        public WebhookProxyTargetType $targetType = WebhookProxyTargetType::REQUEST,
        public array $metadata = [],
        public array $context = [],
        public bool $allowsMultipleSubscribers = false,
    ) {
    }

    /** @param array<string, mixed> $context */
    public function withContext(array $context): self
    {
        return new self(
            ownerId: $this->ownerId,
            registrationId: $this->registrationId,
            webhookGroup: $this->webhookGroup,
            routingScope: $this->routingScope,
            routingKey: $this->routingKey,
            target: $this->target,
            targetType: $this->targetType,
            metadata: $this->metadata,
            context: $context,
            allowsMultipleSubscribers: $this->allowsMultipleSubscribers,
        );
    }
}
