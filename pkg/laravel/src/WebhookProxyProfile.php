<?php

declare(strict_types=1);

namespace Webong\Proxy;

use Illuminate\Http\Request;
use Spatie\WebhookClient\WebhookProfile\WebhookProfile;
use RuntimeException;

class WebhookProxyProfile implements WebhookProfile
{
    public function shouldProcess(Request $request): bool
    {
        $config = $request->attributes->get(EndpointWebhookConfig::REQUEST_ATTRIBUTE);

        if (! $config instanceof EndpointWebhookConfig) {
            throw new RuntimeException('The endpoint webhook configuration has not been resolved.');
        }

        if ($config->webhookProfile instanceof self) {
            throw new \LogicException('A WebProxy client cannot use the proxy webhook profile.');
        }

        return $config->webhookProfile->shouldProcess($request);
    }
}
