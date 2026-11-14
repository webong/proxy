<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase;
use Webong\Proxy\Database\SubscriptionIdentity;
use Webong\Proxy\DestinationDefinition;
use Webong\Proxy\EndpointDefinition;
use Webong\Proxy\EnsureEndpoint;
use Webong\Proxy\GuzzleClientFactory;
use Webong\Proxy\Models\WebProxyDestination;
use Webong\Proxy\ProfileDefinition;
use Webong\Proxy\WebProxyServiceProvider;

final class PackagingRouter implements \Webong\Proxy\Contracts\Router
{
    public function routes(\Webong\Proxy\Models\WebProxyCall $webhookCall): iterable
    {
        return [];
    }
}

final class LaravelConsumerTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        // Discover from installed metadata, rather than hard-coding provider classes.
        $providers = [];
        foreach (['spatie/laravel-webhook-client', 'spatie/laravel-webhook-server', 'webong/proxy-laravel'] as $name) {
            $path = InstalledVersions::getInstallPath($name);
            $manifest = json_decode(file_get_contents($path.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
            foreach ($manifest['extra']['laravel']['providers'] ?? [] as $provider) {
                self::assertTrue(class_exists($provider), 'Missing advertised provider '.$provider);
                $providers[] = $provider;
            }
        }
        $core = json_decode(file_get_contents(InstalledVersions::getInstallPath('webong/proxy').'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('laravel', $core['extra']);
        self::assertContains(WebProxyServiceProvider::class, $providers);
        self::assertSame($providers, array_values(array_unique($providers)));

        return $providers;
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'database.default' => 'sqlite',
            'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'proxy.base_url' => 'https://proxy.example.test',
            'proxy.routers' => ['consumer' => PackagingRouter::class],
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(InstalledVersions::getInstallPath('webong/proxy-laravel').'/database/migrations');
    }

    protected function defineRoutes($router): void
    {
        Route::webhooks('/{scope?}')->proxy();
    }

    public function testInstalledProviderRegistriesProfilesAndMigrations(): void
    {
        self::assertTrue(\Illuminate\Support\Facades\Schema::hasTable('subscription_locks'));
        $endpoint = app(EnsureEndpoint::class)->handle(new EndpointDefinition(
            client: 'consumer', externalId: 'source', signingSecret: 'fixture-secret', verificationToken: null,
            credentialOwnerId: 'owner', managed: false,
        ));
        $destination = $endpoint->attach(new DestinationDefinition(
            ownerId: 'owner', registrationId: 'registration', webhookGroup: 'events',
            routingScope: 'account', routingKey: 'account-1', target: 'https://receiver.example.test/hook',
        ));
        $model = WebProxyDestination::findOrFail($destination->id);
        self::assertSame(SubscriptionIdentity::hash($model->getAttributes()), $model->registration_hash);
        $endpoint->registerProfile(new ProfileDefinition(name: 'api', configuration: ['options' => ['timeout' => 2]]));
        $factory = app(GuzzleClientFactory::class);
        self::assertSame(2, $factory->profile($endpoint, 'api')->toArray()['request_timeout_seconds']);
        self::assertInstanceOf(\GuzzleHttp\ClientInterface::class, $factory->make($endpoint, 'api'));
        self::assertSame('fixture-secret', $endpoint->record->signing_secret);
        self::assertTrue(\Illuminate\Routing\Route::hasMacro('proxy'));
        foreach (['proxy-config', 'proxy-migrations', 'proxy-legacy-migrations'] as $tag) {
            $paths = ServiceProvider::pathsToPublish(WebProxyServiceProvider::class, $tag);
            self::assertNotEmpty($paths);
            foreach (array_keys($paths) as $path) {
                self::assertTrue(file_exists($path), 'Missing published path '.$path);
            }
        }
        $file = (new ReflectionClass(\Webong\Proxy\Http\HttpProfile::class))->getFileName();
        self::assertStringContainsString('vendor/webong/proxy/', $file);
        self::assertFalse(is_link(InstalledVersions::getInstallPath('webong/proxy')));
        foreach ([WebProxyServiceProvider::class, GuzzleClientFactory::class,
            \Webong\Proxy\LaravelRegistryStore::class, \Webong\Proxy\Models\WebProxyEndpoint::class] as $class) {
            self::assertStringContainsString('vendor/webong/proxy-laravel/', (new ReflectionClass($class))->getFileName());
        }
        self::assertFalse(is_link(InstalledVersions::getInstallPath('webong/proxy-laravel')));

        $recordFile = (new ReflectionClass(\Webong\Proxy\EndpointRecord::class))->getFileName();
        self::assertStringContainsString('vendor/webong/proxy/', $recordFile);
        self::assertFalse(is_link(InstalledVersions::getInstallPath('webong/proxy')));
        foreach ([\Webong\Proxy\Registry\Contracts\EndpointRepository::class,
            \Webong\Proxy\Registry\Contracts\DestinationRepository::class,
            \Webong\Proxy\Registry\Contracts\ProfileRepository::class] as $port) {
            self::assertInstanceOf(\Webong\Proxy\LaravelRegistryStore::class, app($port));
        }
    }

    public function testActualDiscoveryAndPackageNameOptOut(): void
    {
        $base = sys_get_temp_dir().'/proxy-discovery-'.bin2hex(random_bytes(6));
        mkdir($base, 0700);
        try {
            foreach ([[], ['webong/proxy'], ['webong/proxy-laravel'], ['*']] as $ignore) {
                file_put_contents($base.'/composer.json', json_encode([
                    'extra' => ['laravel' => ['dont-discover' => $ignore]],
                ], JSON_THROW_ON_ERROR));
                $manifest = new \Illuminate\Foundation\PackageManifest(new \Illuminate\Filesystem\Filesystem(), $base, $base.'/packages.php');
                $manifest->vendorPath = getcwd().'/vendor';
                $manifest->build();
                self::assertSame(! in_array('webong/proxy-laravel', $ignore, true) && ! in_array('*', $ignore, true),
                    in_array(WebProxyServiceProvider::class, $manifest->providers(), true));
            }
        } finally {
            @unlink($base.'/composer.json');
            @unlink($base.'/packages.php');
            rmdir($base);
        }
    }
}
