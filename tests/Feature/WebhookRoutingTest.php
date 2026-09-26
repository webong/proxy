<?php

declare(strict_types=1);

use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Webong\WebProxy\Contracts\WebhookEvent;
use Webong\WebProxy\Contracts\WebhookJob;
use Webong\WebProxy\DatabaseEndpointProvider;
use Webong\WebProxy\DestinationDefinition;
use Webong\WebProxy\DispatchWebhookProxyDestination;
use Webong\WebProxy\Enums\WebhookProxyTargetType;
use Webong\WebProxy\Jobs\ForwardWebhookJob;
use Webong\WebProxy\Models\WebProxyDestination;
use Webong\WebProxy\Tests\Support\WebhookFixtures;
use Webong\WebProxy\WebhookProxyDelivery;
use Webong\WebProxy\WebhookRoute;
use Webong\WebProxy\WebProxy;

it('routes to every destination matching the route', function (): void {
    $endpoint = WebhookFixtures::endpoint(externalId: 'multiple-destinations');

    foreach (['registration-1', 'registration-2'] as $registrationId) {
        WebhookFixtures::destination(
            endpoint: $endpoint,
            registrationId: $registrationId,
            webhookGroup: 'waba',
            routingScope: 'whatsapp_business_account',
            routingKey: 'waba-1',
            target: "https://{$registrationId}.example.test/webhooks/meta",
            allowsMultipleSubscribers: true,
        );
    }

    Bus::fake([ForwardWebhookJob::class]);

    $delivered = app(WebProxy::class)->route(
        $endpoint->record,
        new WebhookRoute(
            scope: 'whatsapp_business_account',
            key: 'waba-1',
            payload: ['event' => 'account-update'],
        ),
        'https://proxy.example.test/webhooks/source',
    );
    $duplicate = app(WebProxy::class)->route(
        $endpoint->record,
        new WebhookRoute(
            scope: 'whatsapp_business_account',
            key: 'waba-1',
            payload: ['event' => 'account-update'],
        ),
        'https://proxy.example.test/webhooks/source',
    );

    expect($delivered)->toBe(2)
        ->and($duplicate)->toBe(0);
    Bus::assertDispatchedTimes(ForwardWebhookJob::class, 2);
});

it('filters destinations by opaque metadata', function (): void {
    $endpoint = WebhookFixtures::endpoint(externalId: 'metadata-route');
    $firstDestination = WebhookFixtures::destination(
        endpoint: $endpoint,
        registrationId: 'phone-1-connection',
        webhookGroup: 'waba',
        routingScope: 'whatsapp_business_account',
        routingKey: 'waba-1',
        target: 'https://first.example.test/webhooks/meta',
        metadata: ['phone_number_id' => 'phone-1'],
        allowsMultipleSubscribers: true,
    );
    $secondDestination = WebhookFixtures::destination(
        endpoint: $endpoint,
        registrationId: 'phone-2-connection',
        webhookGroup: 'waba',
        routingScope: 'whatsapp_business_account',
        routingKey: 'waba-1',
        target: 'https://second.example.test/webhooks/meta',
        metadata: ['phone_number_id' => 'phone-2'],
        allowsMultipleSubscribers: true,
    );

    Bus::fake([ForwardWebhookJob::class]);

    $delivered = app(WebProxy::class)->route(
        $endpoint->record,
        new WebhookRoute(
            scope: 'whatsapp_business_account',
            key: 'waba-1',
            payload: ['event' => 'phone-message'],
            destinationMetadata: ['phone_number_id' => 'phone-2'],
        ),
        'https://proxy.example.test/webhooks/source',
    );

    expect($delivered)->toBe(1);
    Bus::assertNotDispatched(
        ForwardWebhookJob::class,
        fn (ForwardWebhookJob $job): bool => $job->destinationId === $firstDestination->id,
    );
    Bus::assertDispatched(
        ForwardWebhookJob::class,
        fn (ForwardWebhookJob $job): bool => $job->destinationId === $secondDestination->id,
    );
});

it('dispatches event and job destinations', function (): void {
    config()->set('proxy.targets', [
        'event' => [PackageWebhookEvent::class => PackageWebhookEvent::class],
        'job' => [PackageWebhookJob::class => PackageWebhookJob::class],
    ]);

    $endpoint = WebhookFixtures::endpoint(externalId: 'event-job-targets');
    $eventDestination = $endpoint->attach(new DestinationDefinition(
        ownerId: 'owner-1',
        registrationId: 'event-registration',
        webhookGroup: 'example-events',
        routingScope: 'account',
        routingKey: 'account-1',
        target: PackageWebhookEvent::class,
        targetType: WebhookProxyTargetType::EVENT,
    ));
    $jobDestination = $endpoint->attach(new DestinationDefinition(
        ownerId: 'owner-1',
        registrationId: 'job-registration',
        webhookGroup: 'example-events',
        routingScope: 'account',
        routingKey: 'account-1',
        target: PackageWebhookJob::class,
        targetType: WebhookProxyTargetType::JOB,
    ));

    Event::fake([PackageWebhookEvent::class]);
    Bus::fake([PackageWebhookJob::class]);

    foreach ([$eventDestination, $jobDestination] as $destination) {
        app(DispatchWebhookProxyDestination::class)->handle(
            provider: app(DatabaseEndpointProvider::class),
            destination: $destination,
            sourceUrl: 'https://proxy.example.test/webhooks/source',
            payload: ['object' => 'account'],
        );
    }

    Event::assertDispatched(
        PackageWebhookEvent::class,
        fn (PackageWebhookEvent $event): bool => $event->delivery->destinationId === $eventDestination->id,
    );
    Bus::assertDispatched(
        PackageWebhookJob::class,
        fn (PackageWebhookJob $job): bool => $job->delivery->destinationId === $jobDestination->id,
    );

    expect(WebProxyDestination::query()->findOrFail($eventDestination->id)->last_delivered_at)
        ->not->toBeNull()
        ->and(WebProxyDestination::query()->findOrFail($jobDestination->id)->last_delivered_at)
        ->not->toBeNull();
});

final readonly class PackageWebhookEvent implements WebhookEvent
{
    public function __construct(public WebhookProxyDelivery $delivery)
    {
    }

    public static function fromWebhookProxy(WebhookProxyDelivery $delivery): static
    {
        return new self($delivery);
    }
}

final class PackageWebhookJob implements WebhookJob
{
    use Queueable;

    public function __construct(public readonly WebhookProxyDelivery $delivery)
    {
    }

    public static function fromWebhookProxy(WebhookProxyDelivery $delivery): static
    {
        return new self($delivery);
    }

    public function handle(): void
    {
    }
}
