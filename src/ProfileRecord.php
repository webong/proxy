<?php

declare(strict_types=1);

namespace Webong\WebProxy;

final readonly class ProfileRecord
{
    /** @param array<string, mixed> $configuration */
    public function __construct(
        public string $id,
        public string $endpoint_id,
        public string $name,
        public string $driver,
        public array $configuration,
        public bool $is_active,
    ) {}
}
