<?php

declare(strict_types=1);

namespace Webong\WebProxy;

use Throwable;

/**
 * Resolves the raw webhook path templates for the configured receivers, using
 * each receiver's registered webhook-client route (or explicit path template).
 *
 * The package exposes raw route templates and remains agnostic of routing
 * scope, ownership, and any placeholder conventions used by a host.
 */
final class WebhookPathTemplates
{
    public function __construct(
        private readonly EndpointUrlGenerator $urlGenerator,
    ) {
    }

    /**
     * Build the raw path templates map keyed by receiver.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        $templates = [];

        foreach ($this->receivers() as $receiver) {
            try {
                $templates[$receiver] = $this->urlGenerator->webhookPathTemplate((string) $receiver);
            } catch (Throwable) {
                continue;
            }
        }

        return $templates;
    }

    /**
     * The webhook receivers registered as webhook-client configs, excluding
     * the proxy channels.
     *
     * @return list<string>
     */
    private function receivers(): array
    {
        $proxyChannelNames = collect(config('proxy.channels', []))
            ->where('driver', 'webhook')
            ->pluck('name')
            ->map('strval')
            ->all();

        return collect(config('webhook-client.configs', []))
            ->pluck('name')
            ->map('strval')
            ->reject(fn (string $name): bool => in_array($name, $proxyChannelNames, true))
            ->unique()
            ->values()
            ->all();
    }
}
