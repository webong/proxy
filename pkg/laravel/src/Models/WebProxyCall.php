<?php

declare(strict_types=1);

namespace Webong\Proxy\Models;

use Illuminate\Http\Request;
use LogicException;
use Spatie\WebhookClient\Models\WebhookCall;
use Spatie\WebhookClient\WebhookConfig;
use Webong\Proxy\EndpointRecord;
use Webong\Proxy\EndpointWebhookConfig;

class WebProxyCall extends WebhookCall
{
    protected $table = 'webhook_calls';

    private ?EndpointRecord $endpointRecord = null;

    public static function storeWebhook(WebhookConfig $config, Request $request): WebhookCall
    {
        $endpointConfig = $request->attributes->get(EndpointWebhookConfig::REQUEST_ATTRIBUTE);

        if ($endpointConfig instanceof EndpointWebhookConfig) {
            $endpointConfig->name = $config->name;

            if (is_array($endpointConfig->storeHeaders)) {
                $endpointConfig->storeHeaders = array_values(array_unique([
                    ...$endpointConfig->storeHeaders,
                    \Webong\Proxy\Headers::ENDPOINT,
                ]));
            }

            $config = $endpointConfig;
        }

        return parent::storeWebhook($config, $request);
    }

    public function setEndpointRecord(EndpointRecord $endpointRecord): static
    {
        $this->endpointRecord = $endpointRecord;

        return $this;
    }

    public function endpointRecord(): EndpointRecord
    {
        return $this->endpointRecord
            ?? throw new LogicException('The webhook endpoint has not been resolved.');
    }
}
