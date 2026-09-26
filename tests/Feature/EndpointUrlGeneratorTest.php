<?php

declare(strict_types=1);

use Webong\WebProxy\EndpointUrlGenerator;
use Webong\WebProxy\Tests\Support\TestWebhookContextProvider;
use Webong\WebProxy\WebhookContext;

it('uses a provided path template to build a callback URL', function (): void {
    config()->set('proxy.base_url', 'https://service.example.test');

    $url = app(EndpointUrlGenerator::class)->path('telegram', [
        'webhook_base_url' => 'https://service.example.test',
        'webhook_path_templates' => [
            'telegram' => '/webhooks/telegram/scope-123',
        ],
    ]);

    expect($url)->toBe('https://service.example.test/webhooks/telegram/scope-123');
});

it('uses the host context provider when resolving its execution payload', function (): void {
    config()->set('proxy.context_provider', TestWebhookContextProvider::class);

    $payload = app(WebhookContext::class)->payload();

    expect($payload->ownerId)->toBe('owner-123')
        ->and($payload->pathTemplates)->toBe([
            'test-router' => '/webhooks/test-router/owner-123',
        ])
        ->and($payload->routeParameters)->toBe(['scope' => 'owner-123']);
});

it('does not infer a scope segment from unrelated context', function (): void {
    config()->set('proxy.base_url', 'https://service.example.test');

    $url = app(EndpointUrlGenerator::class)->path('telegram', [
        'scope_id' => 'scope-123',
        'webhook_base_url' => 'https://service.example.test',
        'webhook_path_templates' => [
            'telegram' => '/webhooks/telegram',
        ],
    ]);

    expect($url)->toBe('https://service.example.test/webhooks/telegram');
});

it('prefers an explicit callback base URL over package configuration', function (): void {
    config()->set('proxy.base_url', 'https://proxy.example.test');

    $url = app(EndpointUrlGenerator::class)->path(
        'telegram',
        [
            'webhook_path_templates' => [
                'telegram' => '/webhooks/telegram/scope-123',
            ],
        ],
        'https://custom.example.test/',
    );

    expect($url)->toBe('https://custom.example.test/webhooks/telegram/scope-123');
});

it('does not fall back to an unrelated service URL', function (): void {
    config()->set([
        'proxy.base_url' => null,
        'app.service_url' => 'https://service.example.test',
    ]);

    expect(fn () => app(EndpointUrlGenerator::class)->path('telegram', [
        'webhook_base_url' => '',
        'webhook_path_templates' => [
            'telegram' => '/webhooks/telegram/scope-123',
        ],
    ]))->toThrow(RuntimeException::class, 'Unable to resolve webhook base URL.');
});

it('throws when no webhook path template can be resolved', function (): void {
    config()->set('webhook-client.configs', [
        [
            'name' => 'ghost-receiver',
            'signing_secret' => 'test',
        ],
    ]);

    expect(fn () => app(EndpointUrlGenerator::class)->webhookPathTemplate('unregistered'))
        ->toThrow(RuntimeException::class, 'Webhook callback path template is not configured.');
});

it('resolves an explicit path template from webhook client configuration', function (): void {
    config()->set('webhook-client.configs', [
        [
            'name' => 'custom-receiver',
            'signing_secret' => 'test',
            'path_template' => '/custom/receiver-path',
        ],
    ]);

    $url = app(EndpointUrlGenerator::class)->path(
        'custom-receiver',
        baseUrl: 'https://service.example.test',
    );

    expect($url)->toBe('https://service.example.test/custom/receiver-path');
});

it('strips optional parameters from an explicit path template', function (): void {
    config()->set('webhook-client.configs', [
        [
            'name' => 'custom-receiver',
            'signing_secret' => 'test',
            'path_template' => '/webhooks/custom/{scope?}',
        ],
    ]);

    $url = app(EndpointUrlGenerator::class)->path(
        'custom-receiver',
        baseUrl: 'https://service.example.test',
    );

    expect($url)->toBe('https://service.example.test/webhooks/custom');
});
