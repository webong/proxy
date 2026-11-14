<?php

declare(strict_types=1);

namespace Webong\Proxy;

final readonly class WebhookRoute
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $destinationMetadata
     */
    public function __construct(
        public string $scope,
        public string $key,
        public array $payload,
        public array $headers = [],
        public array $destinationMetadata = [],
    ) {
    }
}
