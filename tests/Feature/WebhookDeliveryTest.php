<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Spatie\WebhookServer\CallWebhookJob;
use Webong\WebProxy\Events\WebhookDeliveryFailed;
use Webong\WebProxy\Events\WebhookDeliverySucceeded;
use Webong\WebProxy\Headers;
use Webong\WebProxy\Jobs\ForwardWebhookJob;
use Webong\WebProxy\Models\WebProxyDestination;
use Webong\WebProxy\Tests\Support\WebhookFixtures;
use Webong\WebProxy\WebProxy;
use Webong\WebProxy\WebProxyRegistryManager;

it('signs request deliveries and reports success', function (): void {
    $endpoint = WebhookFixtures::endpoint(externalId: 'signed-delivery');
    $destination = WebhookFixtures::destination($endpoint);

    Event::fake([WebhookDeliverySucceeded::class]);
    Bus::fake([CallWebhookJob::class]);

    $job = new ForwardWebhookJob(
        destinationId: $destination->id,
        sourceUrl: 'https://proxy.example.test/webhooks/source',
        payload: ['event' => 'created'],
        deliveryId: 'delivery-1',
    );
    $job->handle(app(WebProxy::class), app(WebProxyRegistryManager::class));

    expect($job->timeout)->toBe(60)
        ->and(WebProxyDestination::query()->findOrFail($destination->id)->last_delivered_at)
        ->not->toBeNull();

    Bus::assertDispatched(
        CallWebhookJob::class,
        function (CallWebhookJob $callJob) use ($destination): bool {
            $timestamp = (string) data_get($callJob->headers, Headers::TIMESTAMP);
            $body = json_encode($callJob->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $expectedSignature = 'sha256='.hash_hmac(
                'sha256',
                $timestamp.'.'.$body,
                'internal-forwarding-secret',
            );

            expect($callJob->webhookUrl)->toBe($destination->target)
                ->and($callJob->headers[Headers::ID])->toBe('delivery-1')
                ->and($callJob->headers[Headers::SOURCE])
                ->toBe('https://proxy.example.test/webhooks/source')
                ->and($callJob->headers[Headers::SIGNATURE])->toBe($expectedSignature);

            return true;
        },
    );

    Event::assertDispatched(
        WebhookDeliverySucceeded::class,
        fn (WebhookDeliverySucceeded $event): bool => $event->delivery->id === 'delivery-1'
            && $event->delivery->destinationId === $destination->id
            && $event->responseStatus === 202,
    );
});

it('forwards without a proxy signature when no secret is configured', function (): void {
    config()->set('proxy.secret', '');

    $endpoint = WebhookFixtures::endpoint(externalId: 'unsigned-delivery');
    $destination = WebhookFixtures::destination($endpoint);

    Event::fake([WebhookDeliverySucceeded::class]);
    Bus::fake([CallWebhookJob::class]);

    $job = new ForwardWebhookJob(
        destinationId: $destination->id,
        sourceUrl: 'https://proxy.example.test/webhooks/source',
        payload: ['event' => 'created'],
        deliveryId: 'delivery-2',
    );
    $job->handle(app(WebProxy::class), app(WebProxyRegistryManager::class));

    Bus::assertDispatched(
        CallWebhookJob::class,
        function (CallWebhookJob $callJob) use ($destination): bool {
            expect($callJob->webhookUrl)->toBe($destination->target)
                ->and($callJob->headers[Headers::ID])->toBe('delivery-2')
                ->and($callJob->headers[Headers::SOURCE])
                ->toBe('https://proxy.example.test/webhooks/source')
                ->and(array_key_exists(Headers::SIGNATURE, $callJob->headers))->toBeFalse();

            return true;
        },
    );
});

it('records and reports terminal request delivery failures', function (): void {
    config()->set('proxy.log_failures', false);

    $endpoint = WebhookFixtures::endpoint(externalId: 'failed-delivery');
    $destination = WebhookFixtures::destination($endpoint);

    Event::fake([WebhookDeliveryFailed::class]);

    $job = new ForwardWebhookJob(
        destinationId: $destination->id,
        sourceUrl: 'https://proxy.example.test/webhooks/source',
        payload: ['event' => 'created'],
        deliveryId: 'failed-delivery-1',
    );
    $job->failed(new RuntimeException('Destination unavailable'));

    $failedDestination = WebProxyDestination::query()->findOrFail($destination->id);

    expect($failedDestination->last_failed_at)->not->toBeNull()
        ->and($failedDestination->last_error)->toBe('Destination unavailable');

    Event::assertDispatched(
        WebhookDeliveryFailed::class,
        fn (WebhookDeliveryFailed $event): bool => $event->delivery->id === 'failed-delivery-1'
            && $event->delivery->destinationId === $destination->id
            && $event->exceptionClass === RuntimeException::class
            && $event->error === 'Destination unavailable',
    );
});
