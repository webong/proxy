<?php

declare(strict_types=1);

namespace Webong\Proxy\Contracts;

use Webong\Proxy\WebhookProxyDelivery;

interface WebhookEvent
{
    public static function fromWebhookProxy(WebhookProxyDelivery $delivery): static;
}
