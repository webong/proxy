<?php

declare(strict_types=1);

use Webong\WebProxy\DestinationRecord;
use Webong\WebProxy\DestinationRegistry;
use Webong\WebProxy\EndpointDefinition;
use Webong\WebProxy\EndpointRecord;
use Webong\WebProxy\EnsureEndpoint;
use Webong\WebProxy\Models\WebProxyDestination;
use Webong\WebProxy\Models\WebProxyEndpoint;
use Webong\WebProxy\Models\WebProxyEndpointRegistration;
use Webong\WebProxy\Models\ProxyProfile;
use Webong\WebProxy\Tests\Support\WebhookFixtures;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('uses unprefixed configurable registry table names', function (): void {
    expect(Schema::hasTable('endpoints'))->toBeTrue()
        ->and(Schema::hasTable('subscriptions'))->toBeTrue()
        ->and(Schema::hasTable('endpoint_registrations'))->toBeTrue()
        ->and(Schema::hasTable('profiles'))->toBeTrue()
        ->and(Schema::hasTable('web_proxy_endpoints'))->toBeFalse()
        ->and(Schema::hasTable('web_proxy_destinations'))->toBeFalse();

    config()->set('proxy.tables', [
        'endpoints' => 'tenant_endpoints',
        'subscriptions' => 'tenant_subscriptions',
        'endpoint_registrations' => 'tenant_endpoint_registrations',
        'profiles' => 'tenant_profiles',
    ]);

    expect((new WebProxyEndpoint)->getTable())->toBe('tenant_endpoints')
        ->and((new WebProxyDestination)->getTable())->toBe('tenant_subscriptions')
        ->and((new WebProxyEndpointRegistration)->getTable())->toBe('tenant_endpoint_registrations')
        ->and((new ProxyProfile)->getTable())->toBe('tenant_profiles');
});

it('renames legacy prefixed registry tables during an upgrade', function (): void {
    Schema::rename('endpoints', 'web_proxy_endpoints');
    Schema::rename('subscriptions', 'web_proxy_destinations');
    Schema::rename('endpoint_registrations', 'web_proxy_endpoint_registrations');

    (require __DIR__.'/../../database/legacy-migrations/2026_07_31_135456_adopt_legacy_registry_migrations.php')->up();

    expect(Schema::hasTable('endpoints'))->toBeTrue()
        ->and(Schema::hasTable('subscriptions'))->toBeTrue()
        ->and(Schema::hasTable('endpoint_registrations'))->toBeTrue()
        ->and(Schema::hasTable('web_proxy_endpoints'))->toBeFalse()
        ->and(Schema::hasTable('web_proxy_destinations'))->toBeFalse()
        ->and(Schema::hasTable('web_proxy_endpoint_registrations'))->toBeFalse();
});

it('adopts legacy migration history before renamed migrations run', function (): void {
    $legacy = '2026_07_31_135457_create_web_proxy_endpoints_table';
    $renamed = '2026_07_31_135457_create_endpoints_table';

    DB::table('migrations')->where('migration', $renamed)->delete();
    DB::table('migrations')->insert([
        'migration' => $legacy,
        'batch' => 1,
    ]);

    (require __DIR__.'/../../database/legacy-migrations/2026_07_31_135456_adopt_legacy_registry_migrations.php')->up();

    expect(DB::table('migrations')->where('migration', $legacy)->exists())->toBeFalse()
        ->and(DB::table('migrations')->where('migration', $renamed)->exists())->toBeTrue();
});

it('makes an unmanaged endpoint key deterministic for its external identity', function (): void {
    $endpoint = WebhookFixtures::endpoint(
        externalId: 'fixed-key-source',
        endpointKey: '/meta-platform',
    );

    $endpointKey = 'meta-platform-'.mb_substr(hash('sha256', 'fixed-key-source'), 0, 16);

    expect($endpoint->record->endpoint_key)->toBe($endpointKey)
        ->and($endpoint->callbackUrl())
        ->toBe("https://proxy.example.test/webhooks/proxy/{$endpointKey}");
});

it('persists location-neutral endpoint and destination records', function (): void {
    $endpoint = WebhookFixtures::endpoint();
    $destination = WebhookFixtures::destination(
        endpoint: $endpoint,
        routingKey: 'account-1',
    );

    expect($endpoint->record)
        ->toBeInstanceOf(EndpointRecord::class)
        ->client->toBe('test-router')
        ->and($destination)
        ->toBeInstanceOf(DestinationRecord::class)
        ->routing_scope->toBe('account')
        ->and($destination->target)
        ->toBe('https://receiver.example.test/webhooks/example');
});

it('uses the storage provider as the destination registration source of truth', function (): void {
    $endpoint = WebhookFixtures::endpoint();
    $destination = WebhookFixtures::destination(
        endpoint: $endpoint,
        ownerId: 'owner-1',
        registrationId: 'connection-1',
        webhookGroup: 'waba',
    );
    $registry = app(DestinationRegistry::class);

    expect($registry->hasActive('owner-1', 'connection-1', 'waba'))->toBeTrue();

    $registry->deactivate('owner-1', 'connection-1', 'waba');

    expect($registry->hasActive('owner-1', 'connection-1', 'waba'))->toBeFalse();

    $reactivated = WebhookFixtures::destination(
        endpoint: $endpoint,
        ownerId: 'owner-1',
        registrationId: 'connection-1',
        webhookGroup: 'waba',
    );

    expect($reactivated->id)->toBe($destination->id)
        ->and($registry->hasActive('owner-1', 'connection-1', 'waba'))->toBeTrue();
});

it('rejects a route already owned by another credential owner', function (): void {
    $endpoint = WebhookFixtures::endpoint(ownerId: 'owner-1');
    $firstDestination = WebhookFixtures::destination(
        endpoint: $endpoint,
        ownerId: 'owner-1',
        webhookGroup: 'messenger',
        routingScope: 'page',
        routingKey: 'page-1',
    );

    expect(fn () => WebhookFixtures::destination(
        endpoint: $endpoint,
        ownerId: 'owner-2',
        webhookGroup: 'another-group',
        routingScope: 'page',
        routingKey: 'page-1',
    ))->toThrow(RuntimeException::class, 'already registered to another owner')
        ->and(WebProxyDestination::query()->findOrFail($firstDestination->id)->is_active)
        ->toBeTrue();
});

it('keeps routes single-subscriber unless multiple subscribers are explicit', function (): void {
    $endpoint = WebhookFixtures::endpoint();
    $firstDestination = WebhookFixtures::destination(
        endpoint: $endpoint,
        registrationId: 'registration-1',
        webhookGroup: 'messenger',
        routingScope: 'page',
        routingKey: 'page-1',
        target: 'https://first.example.test/webhooks/meta',
    );
    $secondDestination = WebhookFixtures::destination(
        endpoint: $endpoint,
        registrationId: 'registration-2',
        webhookGroup: 'messenger',
        routingScope: 'page',
        routingKey: 'page-1',
        target: 'https://second.example.test/webhooks/meta',
    );

    expect(WebProxyDestination::query()->findOrFail($firstDestination->id)->is_active)->toBeFalse()
        ->and(WebProxyDestination::query()->findOrFail($secondDestination->id)->is_active)->toBeTrue();
});

it('does not replace a multiple-subscriber route with a single subscriber', function (): void {
    $endpoint = WebhookFixtures::endpoint();

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

    expect(fn () => WebhookFixtures::destination(
        endpoint: $endpoint,
        registrationId: 'replacement',
        webhookGroup: 'waba',
        routingScope: 'whatsapp_business_account',
        routingKey: 'waba-1',
        target: 'https://replacement.example.test/webhooks/meta',
    ))->toThrow(RuntimeException::class, 'different subscriber mode');
});

it('promotes an existing route when multiple subscribers become explicit', function (): void {
    $endpoint = WebhookFixtures::endpoint();
    $firstDestination = WebhookFixtures::destination(
        endpoint: $endpoint,
        registrationId: 'registration-1',
        webhookGroup: 'messenger',
        routingScope: 'page',
        routingKey: 'page-1',
    );

    $secondDestination = WebhookFixtures::destination(
        endpoint: $endpoint,
        ownerId: 'owner-2',
        registrationId: 'registration-2',
        webhookGroup: 'messenger',
        routingScope: 'page',
        routingKey: 'page-1',
        allowsMultipleSubscribers: true,
    );

    expect(WebProxyDestination::findOrFail($firstDestination->id)->metadata[WebProxyDestination::MULTIPLE_SUBSCRIBERS_METADATA_KEY])
        ->toBeTrue()
        ->and(WebProxyDestination::findOrFail($secondDestination->id)->metadata[WebProxyDestination::MULTIPLE_SUBSCRIBERS_METADATA_KEY])
        ->toBeTrue();
});

it('allows only the credential owner to rotate unmanaged credentials', function (): void {
    $ensureEndpoint = app(EnsureEndpoint::class);
    $endpoint = WebhookFixtures::endpoint(
        externalId: 'owned-source',
        ownerId: 'owner-1',
    );

    $ensureEndpoint->handle(new EndpointDefinition(
        client: 'test-router',
        externalId: 'owned-source',
        signingSecret: 'rotated-secret',
        verificationToken: 'verify-token',
        credentialOwnerId: 'owner-1',
        managed: false,
    ));

    expect(WebProxyEndpoint::query()->findOrFail($endpoint->record->id)->signing_secret)
        ->toBe('rotated-secret');

    $reusedEndpoint = $ensureEndpoint->handle(new EndpointDefinition(
        client: 'test-router',
        externalId: 'owned-source',
        signingSecret: 'rotated-secret',
        verificationToken: 'other-verify-token',
        credentialOwnerId: 'owner-2',
        managed: false,
    ));

    expect($reusedEndpoint->record->id)->toBe($endpoint->record->id)
        ->and($reusedEndpoint->record->credential_owner_id)->toBe('owner-1')
        ->and($reusedEndpoint->record->verification_token)->toBe('verify-token');

    expect(fn () => $ensureEndpoint->handle(new EndpointDefinition(
        client: 'test-router',
        externalId: 'owned-source',
        signingSecret: 'foreign-secret',
        verificationToken: 'verify-token',
        credentialOwnerId: 'owner-2',
        managed: false,
    )))->toThrow(RuntimeException::class, 'different credentials');
});

it('promotes managed credentials without allowing a later demotion', function (): void {
    $ensureEndpoint = app(EnsureEndpoint::class);
    $endpoint = WebhookFixtures::endpoint(
        externalId: 'promoted-source',
        ownerId: 'owner-1',
    );

    $ensureEndpoint->handle(new EndpointDefinition(
        client: 'test-router',
        externalId: 'promoted-source',
        signingSecret: 'source-secret',
        verificationToken: 'managed-verify-token',
        credentialOwnerId: null,
        managed: true,
    ));
    $ensureEndpoint->handle(new EndpointDefinition(
        client: 'test-router',
        externalId: 'promoted-source',
        signingSecret: 'source-secret',
        verificationToken: 'foreign-verify-token',
        credentialOwnerId: 'owner-2',
        managed: false,
    ));

    $managedEndpoint = WebProxyEndpoint::query()->findOrFail($endpoint->record->id);

    expect($managedEndpoint->is_managed)->toBeTrue()
        ->and($managedEndpoint->credential_owner_id)->toBeNull()
        ->and($managedEndpoint->verification_token)->toBe('managed-verify-token');
});
