<?php

declare(strict_types=1);

namespace Webong\Proxy;

use Spatie\WebhookClient\WebhookConfig;

final class EndpointWebhookConfig extends WebhookConfig
{
    public const string REQUEST_ATTRIBUTE = 'webhook_proxy_endpoint_config';

    /** @param array<string, mixed> $properties */
    public function __construct(
        array $properties,
        public readonly EndpointRecord $endpoint,
    ) {
        parent::__construct($properties);
    }
}
