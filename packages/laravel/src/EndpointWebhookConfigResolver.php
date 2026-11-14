<?php

declare(strict_types=1);

namespace Webong\Proxy;

use RuntimeException;

final class EndpointWebhookConfigResolver
{
    public function resolve(EndpointRecord $endpoint): EndpointWebhookConfig
    {
        $configs = config('webhook-client.configs', []);

        if (! is_array($configs)) {
            throw new RuntimeException('The webhook client configuration is invalid.');
        }

        $properties = collect($configs)->first(
            static fn (mixed $config): bool => is_array($config)
                && ($config['name'] ?? null) === $endpoint->client,
        );

        if (! is_array($properties)) {
            throw new RuntimeException(
                "Webhook client configuration for WebProxy client [{$endpoint->client}] is not registered.",
            );
        }

        $properties['signing_secret'] = $endpoint->signing_secret
            ?? (string) ($properties['signing_secret'] ?? '');

        return new EndpointWebhookConfig($properties, $endpoint);
    }
}
