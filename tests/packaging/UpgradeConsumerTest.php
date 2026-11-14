<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Webong\Proxy\DestinationDefinition;
use Webong\Proxy\EndpointDefinition;
use Webong\Proxy\EnsureEndpoint;
use Webong\Proxy\GuzzleClientFactory;
use Webong\Proxy\ProfileDefinition;

final class UpgradeRouter implements \Webong\Proxy\Contracts\Router
{
    public function routes(\Webong\Proxy\Models\WebProxyCall $webhookCall): iterable
    {
        return [];
    }
}

/** Real installed 0.3.1 state survives the package-name transition and migration. */
final class UpgradeConsumerTest extends TestCase
{
    private array $previousMigrations = [];

    protected function getPackageProviders($app): array
    {
        $owner = InstalledVersions::isInstalled('webong/proxy-laravel') ? 'webong/proxy-laravel' : 'webong/proxy';
        $providers = [];
        foreach (['spatie/laravel-webhook-client', 'spatie/laravel-webhook-server', $owner] as $name) {
            $manifest = json_decode(file_get_contents(InstalledVersions::getInstallPath($name).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
            $providers = [...$providers, ...($manifest['extra']['laravel']['providers'] ?? [])];
        }

        return $providers;
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'database.default' => 'sqlite',
            'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => getcwd().'/upgrade.sqlite', 'prefix' => ''],
            'proxy.base_url' => 'https://proxy.example.test',
            'proxy.routers' => ['upgrade' => UpgradeRouter::class],
        ]);
    }

    protected function defineRoutes($router): void
    {
        Route::webhooks('/{scope?}')->proxy();
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->previousMigrations = Schema::hasTable('migrations') ? $this->rows('migrations') : [];
        $owner = InstalledVersions::isInstalled('webong/proxy-laravel') ? 'webong/proxy-laravel' : 'webong/proxy';
        $published = getcwd().'/published-migrations';
        if (! is_dir($published)) {
            mkdir($published);
        }
        foreach (glob(InstalledVersions::getInstallPath($owner).'/database/migrations/*.php') as $file) {
            // Existing app-owned copies remain; only the new file is added.
            if (! is_file($published.'/'.basename($file))) {
                copy($file, $published.'/'.basename($file));
            }
        }
        $this->artisan('migrate', ['--path' => $published, '--realpath' => true, '--force' => true])->assertSuccessful();
    }

    private function rows(string $table): array
    {
        return DB::table($table)->orderBy('id')->get()->map(static fn ($row): array => (array) $row)->all();
    }

    public function testPersistedRegistrySurvivesPackageTransition(): void
    {
        if (getenv('PROXY_UPGRADE_PHASE') === 'seed') {
            self::assertSame('0.3.1', ltrim(InstalledVersions::getPrettyVersion('webong/proxy'), 'v'));
            $endpoint = app(EnsureEndpoint::class)->handle(new EndpointDefinition(
                'upgrade', 'source', 'persisted-secret', 'persisted-token', credentialOwnerId: 'owner',
            ));
            $destination = $endpoint->attach(new DestinationDefinition('owner', 'registration', 'events', 'account', 'one', 'https://receiver.test'));
            $profile = $endpoint->registerProfile(new ProfileDefinition('api', ['options' => ['timeout' => 2]]));
            $state = ['endpoint' => $endpoint->record->id, 'destination' => $destination->id, 'profile' => $profile->id];
            foreach (['endpoints', 'subscriptions', 'endpoint_registrations', 'profiles', 'migrations'] as $table) {
                $state[$table] = $this->rows($table);
            }
            file_put_contents(getcwd().'/state.json', json_encode($state, JSON_THROW_ON_ERROR));
            self::assertNotSame('persisted-secret', $state['endpoints'][0]['signing_secret']);
            self::assertFalse(Schema::hasTable('subscription_locks'));

            return;
        }

        $state = json_decode(file_get_contents(getcwd().'/state.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($state['migrations'], $this->previousMigrations);
        foreach (['endpoints', 'subscriptions', 'endpoint_registrations', 'profiles'] as $table) {
            self::assertSame($state[$table], $this->rows($table), 'Existing '.$table.' data changed.');
        }
        $history = $this->rows('migrations');
        self::assertCount(count($state['migrations']) + 1, $history);
        self::assertSame('2026_11_14_000001_create_subscription_locks_table', end($history)['migration']);
        self::assertTrue(Schema::hasTable('subscription_locks'));
        $endpoint = app(\Webong\Proxy\DatabaseEndpointProvider::class)->resolveById($state['endpoint']);
        self::assertSame('persisted-secret', $endpoint?->record->signing_secret);
        self::assertSame('persisted-token', $endpoint?->record->verification_token);
        self::assertSame($state['profile'], $endpoint->profile('api')?->id);
        self::assertSame(2, app(GuzzleClientFactory::class)->profile($endpoint, 'api')->toArray()['request_timeout_seconds']);
        self::assertSame($state['destination'], app(\Webong\Proxy\DatabaseEndpointProvider::class)->destinationById($state['destination'])?->id);
        self::assertStringContainsString('vendor/webong/proxy-laravel/', (new ReflectionClass(\Webong\Proxy\WebProxyServiceProvider::class))->getFileName());
    }
}
