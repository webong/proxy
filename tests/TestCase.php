<?php

declare(strict_types=1);

namespace Webong\Proxy\Tests;

use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\WebhookClient\WebhookClientServiceProvider;
use Spatie\WebhookClient\WebhookProfile\ProcessEverythingWebhookProfile;
use Spatie\WebhookServer\WebhookServerServiceProvider;
use Webong\Proxy\Headers;
use Webong\Proxy\Jobs\ProcessWebhookJob;
use Webong\Proxy\Models\WebProxyCall;
use Webong\Proxy\Tests\Support\TestRouter;
use Webong\Proxy\Tests\Support\TestSignatureValidator;
use Webong\Proxy\Tests\Support\TestWebhookJob;
use Webong\Proxy\Tests\Support\TestWebhookResponse;
use Webong\Proxy\WebProxy;
use Webong\Proxy\WebhookProxyRouteDefinition;
use Webong\Proxy\WebProxyServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        app(WebProxy::class)->register('test-router', TestRouter::class);
    }

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            WebhookClientServiceProvider::class,
            WebhookServerServiceProvider::class,
            WebProxyServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'proxy.base_url' => 'https://proxy.example.test',
            'proxy.secret' => 'internal-forwarding-secret',
            'webhook-client.configs' => [[
                'name' => 'test-router',
                'signing_secret' => '',
                'signature_header_name' => '',
                'signature_validator' => TestSignatureValidator::class,
                'webhook_profile' => ProcessEverythingWebhookProfile::class,
                'webhook_response' => TestWebhookResponse::class,
                'webhook_model' => WebProxyCall::class,
                'store_headers' => ['X-Test-Header', Headers::ENDPOINT],
                'store_attachments' => false,
                'process_webhook_job' => ProcessWebhookJob::class,
            ]],
            'proxy.targets' => [
                'event' => [],
                'job' => [
                    'test-webhook-job' => TestWebhookJob::class,
                ],
            ],
        ]);

        if (($driver = getenv('PROXY_TEST_DB')) && $driver !== 'sqlite') {
            $app['config']->set('database.default', $driver);
            $app['config']->set('database.connections.'.$driver, [
                'driver' => $driver,
                'host' => getenv('PROXY_TEST_DB_HOST') ?: '127.0.0.1',
                'port' => getenv('PROXY_TEST_DB_PORT') ?: ($driver === 'pgsql' ? '5432' : '3306'),
                'database' => getenv('PROXY_TEST_DB_DATABASE') ?: 'proxy_test',
                'username' => getenv('PROXY_TEST_DB_USERNAME') ?: ($driver === 'pgsql' ? 'postgres' : 'root'),
                'password' => getenv('PROXY_TEST_DB_PASSWORD') ?: '',
                'charset' => $driver === 'pgsql' ? 'utf8' : 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
            ]);
        }

        $app->bind(
            WebhookProxyRouteDefinition::class,
            static fn (): WebhookProxyRouteDefinition => new class implements WebhookProxyRouteDefinition {
                public function uri(): string
                {
                    return '/webhooks/proxy/{endpointKey}/{scope?}';
                }
            },
        );
    }

    protected function defineRoutes($router): void
    {
        Route::get('/sentinel', static fn (): string => 'sentinel');
        Route::webhooks('/{scope?}')->proxy();
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->app->make('migrator')->path(__DIR__.'/../database/migrations');
    }
}
