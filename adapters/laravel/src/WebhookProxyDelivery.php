<?php

declare(strict_types=1);

namespace Webong\Proxy;

use Illuminate\Support\Str;

final readonly class WebhookProxyDelivery
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $context
     */
    public function __construct(
        public string $id,
        public string $destinationId,
        public string $endpointId,
        public string $ownerId,
        public string $registrationId,
        public string $webhookGroup,
        public string $routingScope,
        public string $routingKey,
        public string $sourceUrl,
        public array $payload,
        public array $headers,
        public array $metadata,
        public array $context,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    public static function fromDestination(
        DestinationRecord $destination,
        string $sourceUrl,
        array $payload,
        array $headers = [],
        ?string $deliveryId = null,
    ): self {
        $metadata = $destination->metadata;
        $context = $metadata[DestinationRecord::EXECUTION_CONTEXT_METADATA_KEY] ?? [];
        unset(
            $metadata[DestinationRecord::EXECUTION_CONTEXT_METADATA_KEY],
            $metadata[DestinationRecord::MULTIPLE_SUBSCRIBERS_METADATA_KEY],
        );

        return new self(
            id: $deliveryId ?? (string) Str::uuid(),
            destinationId: $destination->id,
            endpointId: $destination->endpoint_id,
            ownerId: $destination->owner_id,
            registrationId: $destination->registration_id,
            webhookGroup: $destination->webhook_group,
            routingScope: $destination->routing_scope,
            routingKey: $destination->routing_key,
            sourceUrl: $sourceUrl,
            payload: $payload,
            headers: $headers,
            metadata: $metadata,
            context: is_array($context) ? $context : [],
        );
    }
}
