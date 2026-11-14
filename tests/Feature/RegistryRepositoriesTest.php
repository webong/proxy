<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Webong\Proxy\DatabaseEndpointProvider;
use Webong\Proxy\DestinationDefinition;
use Webong\Proxy\Endpoint;
use Webong\Proxy\EndpointDefinition;
use Webong\Proxy\EndpointRecord;
use Webong\Proxy\LaravelRegistryStore;
use Webong\Proxy\ProfileDefinition;
use Webong\Proxy\ProvisionedEndpoint;
use Webong\Proxy\Registry\Contracts\DestinationRepository;
use Webong\Proxy\Registry\Contracts\EndpointRepository;
use Webong\Proxy\Registry\Contracts\ProfileRepository;
use Webong\Proxy\Tests\Support\WebhookFixtures;
use Webong\Proxy\WebhookRoute;

it('exposes the Laravel store through neutral ports and retains legacy wrappers', function (): void {
    $store = app(LaravelRegistryStore::class);
    foreach ([EndpointRepository::class, DestinationRepository::class, ProfileRepository::class] as $port) {
        expect(app($port))->toBe($store);
    }
    $definition = new EndpointDefinition('test-router', 'ports', 'secret', null, credentialOwnerId: 'owner');
    $record = app(EndpointRepository::class)->define($definition, new ProvisionedEndpoint(endpointKey: 'ports'));
    expect($record)->toBeInstanceOf(EndpointRecord::class)
        ->and($store->find($definition)?->id)->toBe($record->id)
        ->and($store->resolveById($record->id)?->id)->toBe($record->id)
        ->and($store->resolveByKey($record->endpoint_key)?->id)->toBe($record->id);
    $store->register($record, 'primary');
    expect($store->registryForId($record->id))->toBe('primary')
        ->and($store->registryForKey($record->endpoint_key))->toBe('primary');
    $destination = new DestinationDefinition('owner', 'registration', 'events', 'account', 'one', 'https://receiver.test');
    $first = $store->attach($record, $destination);
    expect($store->attach($record, $destination)->id)->toBe($first->id);
    $route = new WebhookRoute('account', 'one', []);
    expect($store->destinationsFor($record, $route))->toBeArray()->toHaveCount(1);
    $legacy = app(DatabaseEndpointProvider::class);
    expect($legacy->find($definition))->toBeInstanceOf(Endpoint::class)
        ->and($legacy->destinationsFor($record, $route))->toBeInstanceOf(Collection::class)
        ->and($legacy->destinationsFor($record, $route)->first()->id)->toBe($first->id);
    $profile = $store->registerProfile($record, new ProfileDefinition('api', ['secret' => 'first']));
    expect($store->registerProfile($record, new ProfileDefinition('api', ['secret' => 'second']))->id)->toBe($profile->id)
        ->and($legacy->profile($record, 'api')?->configuration)->toBe(['secret' => 'second']);
    expect($store->deactivateDestinations('owner', 'registration', 'events'))->toBe(1)
        ->and($store->deactivateDestinations('owner', 'registration', 'events'))->toBe(0)
        ->and($store->hasActiveDestination('owner', 'registration', 'events'))->toBeFalse()
        ->and($store->destinationById($first->id))->toBeNull()
        ->and($store->destinationsFor($record, $route))->toBe([]);
});

it('lets an application replace profile storage behind the existing endpoint API', function (): void {
    require_once __DIR__.'/../packaging/SqliteProfileStore.php';
    $db = new PDO('sqlite::memory:');
    SqliteProfileStore::createSchema($db);
    $custom = new SqliteProfileStore($db);
    app()->instance(ProfileRepository::class, $custom);
    $endpoint = WebhookFixtures::endpoint(externalId: 'custom-profile-store');
    $profile = $endpoint->registerProfile(new ProfileDefinition('custom', ['app' => true], 'app-driver'));
    expect($endpoint->profile('custom')?->id)->toBe($profile->id)
        ->and($profile->driver)->toBe('app-driver')
        ->and($db->query('SELECT COUNT(*) FROM app_profiles')->fetchColumn())->toBe(1)
        ->and(\Webong\Proxy\Models\ProxyProfile::query()->count())->toBe(0);
});

it('preserves manually constructed three-argument providers', function (): void {
    $provider = new DatabaseEndpointProvider(
        app(\Webong\Proxy\DatabaseEndpointStore::class),
        app(\Webong\Proxy\DatabaseDestinationAttacher::class),
        app(\Webong\Proxy\EndpointUrlGenerator::class),
    );
    $endpoint = $provider->define(
        new EndpointDefinition('test-router', 'legacy-constructor', null, null, credentialOwnerId: 'owner'),
        new ProvisionedEndpoint(endpointKey: 'legacy-constructor'),
    );
    expect($endpoint)->toBeInstanceOf(Endpoint::class)
        ->and($provider->resolveById($endpoint->record->id)?->record->id)->toBe($endpoint->record->id);
});

it('honors legacy container constructor overrides', function (): void {
    $url = Mockery::mock(\Webong\Proxy\EndpointUrlGenerator::class);
    $url->shouldReceive('for')->once()->andReturn('https://custom.test/callback');
    $provider = app()->makeWith(DatabaseEndpointProvider::class, ['urlGenerator' => $url]);
    $endpoint = $provider->define(
        new EndpointDefinition('test-router', 'custom-constructor', null, null, credentialOwnerId: 'owner'),
        new ProvisionedEndpoint(endpointKey: 'custom-constructor'),
    );
    expect($endpoint->callbackUrl())->toBe('https://custom.test/callback');
});

class RegistryScopeUrlGenerator extends \Webong\Proxy\EndpointUrlGenerator
{
    public function for(EndpointRecord $endpoint): string
    {
        return $this->path('test-router');
    }
}

it('resolves fresh URL hooks after a Laravel scope reset', function (): void {
    app()->bind(\Webong\Proxy\EndpointUrlGenerator::class, RegistryScopeUrlGenerator::class);
    config()->set('webhook-client.configs.0.path_template', '/hooks/test-router');
    $endpoint = WebhookFixtures::endpoint(externalId: 'scope-lifecycle');
    $first = app(EndpointRepository::class);
    app(\Webong\Proxy\EndpointUrlTemplateHooks::class)->register(fn (string $path): string => '/first'.$path);
    expect($first->resolveById($endpoint->record->id)?->callback_url)->toContain('/first/');
    app()->forgetScopedInstances();
    app(\Webong\Proxy\EndpointUrlTemplateHooks::class)->register(fn (string $path): string => '/second'.$path);
    $second = app(EndpointRepository::class);
    expect($second)->not->toBe($first)
        ->and($second->resolveById($endpoint->record->id)?->callback_url)->toContain('/second/')->not->toContain('/first/');
});

it('preserves contextual constructor bindings on the legacy provider', function (): void {
    $url = Mockery::mock(\Webong\Proxy\EndpointUrlGenerator::class);
    $url->shouldReceive('for')->once()->andReturn('https://contextual.test/callback');
    app()->when(DatabaseEndpointProvider::class)->needs(\Webong\Proxy\EndpointUrlGenerator::class)->give(fn () => $url);
    $endpoint = app(DatabaseEndpointProvider::class)->define(
        new EndpointDefinition('test-router', 'contextual-constructor', null, null, credentialOwnerId: 'owner'),
        new ProvisionedEndpoint(endpointKey: 'contextual-constructor'),
    );
    expect($endpoint->callbackUrl())->toBe('https://contextual.test/callback');
});

it('supports application-owned endpoint and destination identities through the legacy API', function (): void {
    $record = new EndpointRecord('app:endpoint', 'app', 'external', 'key', null, null, null, [], true, true, 'https://app.test');
    $destination = new \Webong\Proxy\DestinationRecord(
        'app:destination', $record->id, 'owner', 'registration', 'events', 'account', 'one',
        \Webong\Proxy\Enums\WebhookProxyTargetType::REQUEST, 'https://receiver.test', [], true,
    );
    $endpoints = Mockery::mock(EndpointRepository::class);
    $endpoints->shouldReceive('resolveById')->once()->with('app:endpoint')->andReturn($record);
    $destinations = Mockery::mock(DestinationRepository::class);
    $destinations->shouldReceive('attach')->once()->with($record, Mockery::type(DestinationDefinition::class))->andReturn($destination);
    $destinations->shouldReceive('destinationsFor')->once()->with($record, Mockery::type(WebhookRoute::class))->andReturn([$destination]);
    app()->instance(EndpointRepository::class, $endpoints);
    app()->instance(DestinationRepository::class, $destinations);
    require_once __DIR__.'/../packaging/SqliteProfileStore.php';
    $db = new PDO('sqlite::memory:');
    SqliteProfileStore::createSchema($db);
    app()->instance(ProfileRepository::class, new SqliteProfileStore($db));

    $provider = app(DatabaseEndpointProvider::class);
    $endpoint = $provider->resolveById('app:endpoint');
    expect($endpoint?->record)->toBe($record)
        ->and($endpoint->attach(new DestinationDefinition('owner', 'registration', 'events', 'account', 'one', 'https://receiver.test')))->toBe($destination)
        ->and($provider->destinationsFor($record, new WebhookRoute('account', 'one', []))->all())->toBe([$destination]);
    $endpoint->registerProfile(new ProfileDefinition('api', ['custom' => true]));
    expect($endpoint->profile('api')?->configuration)->toBe(['custom' => true])
        ->and(\Webong\Proxy\Models\WebProxyEndpoint::query()->count())->toBe(0)
        ->and(\Webong\Proxy\Models\WebProxyDestination::query()->count())->toBe(0)
        ->and(\Webong\Proxy\Models\ProxyProfile::query()->count())->toBe(0);
});

it('keeps registration mutex identities exact and unambiguous across SQL collations', function (): void {
    foreach ([['owner', 'reg', 'events'], ['Owner', 'reg', 'events'], ['owner ', 'reg', 'events'],
        ['a|b', 'c', 'events'], ['a', 'b|c', 'events'], ['owner', 'reg', 'events']] as [$owner, $registration, $group]) {
        \Illuminate\Support\Facades\DB::transaction(fn () => \Webong\Proxy\Database\SubscriptionLock::acquire(new DestinationDefinition(
            $owner, $registration, $group, 'scope', 'key', 'https://receiver.test',
        )));
    }
    expect(\Illuminate\Support\Facades\DB::table('subscription_locks')->count())->toBe(5);
});
