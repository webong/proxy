<?php

declare(strict_types=1);

namespace Webong\WebProxy\Tests;

use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\WebhookClient\WebhookClientServiceProvider;
use Spatie\WebhookClient\WebhookProfile\ProcessEverythingWebhookProfile;
use Spatie\WebhookServer\WebhookServerServiceProvider;
use Webong\WebProxy\Headers;
use Webong\WebProxy\Jobs\ProcessWebhookJob;
use Webong\WebProxy\Models\WebProxyCall;
use Webong\WebProxy\Tests\Support\TestRouter;
use Webong\WebProxy\Tests\Support\TestSignatureValidator;
use Webong\WebProxy\Tests\Support\TestWebhookJob;
use Webong\WebProxy\Tests\Support\TestWebhookResponse;
use Webong\WebProxy\WebProxy;
use Webong\WebProxy\WebhookProxyRouteDefinition;
use Webong\WebProxy\WebProxyServiceProvider;

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
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
