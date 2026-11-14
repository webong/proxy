<?php

declare(strict_types=1);

namespace Webong\Proxy\Contracts;

use Webong\Proxy\Models\WebProxyCall;
use Webong\Proxy\WebhookRoute;

interface Router
{
    /** @return iterable<WebhookRoute> */
    public function routes(WebProxyCall $webhookCall): iterable;

}
