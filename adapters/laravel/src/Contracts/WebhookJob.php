<?php

declare(strict_types=1);

namespace Webong\Proxy\Contracts;

use Illuminate\Contracts\Queue\ShouldQueue;
use Webong\Proxy\WebhookProxyDelivery;

interface WebhookJob extends ShouldQueue
{
    public static function fromWebhookProxy(WebhookProxyDelivery $delivery): static;
}
