<?php

declare(strict_types=1);

namespace Webong\Proxy;

final readonly class WebhookExecutionContextPayload
{
    /**
     * @param array<string, string> $pathTemplates
     * @param array<string, string> $routeParameters
     */
    public function __construct(
        public ?string $ownerId = null,
        public string $baseUrl = '',
        public array $pathTemplates = [],
        public array $routeParameters = [],
        public ?string $routeContext = null,
    ) {
    }
}
