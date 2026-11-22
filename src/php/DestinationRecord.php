<?php

declare(strict_types=1);

namespace Webong\Proxy;

final readonly class DestinationRecord
{
    public const string EXECUTION_CONTEXT_METADATA_KEY = '_execution_context';

    public const string MULTIPLE_SUBSCRIBERS_METADATA_KEY = '_allows_multiple_subscribers';

    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $id,
        public string $endpoint_id,
        public string $owner_id,
        public string $registration_id,
        public string $webhook_group,
        public string $routing_scope,
        public string $routing_key,
        public string $target_type,
        public string $target,
        public array $metadata,
        public bool $is_active,
    ) {
    }
}
