<?php

declare(strict_types=1);

namespace Webong\WebProxy;

use RuntimeException;
use Spatie\WebhookServer\WebhookCall;

final readonly class WebhookForwarder
{
    public function forward(WebhookProxyDelivery $delivery, DestinationRecord $destination)
    {
        if (filter_var($destination->target, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('The webhook proxy target is invalid.');
        }

        $headers = $this->headers($delivery);

        return WebhookCall::create()
                        ->url($destination->target)
                        ->payload($delivery->payload)
                        ->withHeaders($headers)
                        ->doNotSign()
                        ->timeoutInSeconds((int) config('proxy.timeout', 15))
                        ->maximumTries((int) config('proxy.tries', 3))
                        ->dispatch();
    }

    /** @return array<string, string> */
    private function headers(WebhookProxyDelivery $delivery): array
    {
        $body = json_encode($delivery->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->getTimestamp();
        $secret = (string) config('proxy.secret');

        $signature = null;

        if ($secret !== '') {
            $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
        }

        $headers = array_filter(
            $delivery->headers,
            static fn (string $value, string $name): bool => $name !== '',
            ARRAY_FILTER_USE_BOTH,
        );

        return array_filter([
            ...$headers,
            'Content-Type' => 'application/json',
            Headers::ID => $delivery->id,
            Headers::TIMESTAMP => $timestamp,
            Headers::SOURCE => $delivery->sourceUrl,
            Headers::SIGNATURE => $signature,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
