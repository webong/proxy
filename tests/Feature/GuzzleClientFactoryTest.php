<?php

declare(strict_types=1);

use GuzzleHttp\ClientInterface;
use Webong\Proxy\GuzzleClientFactory;
use Webong\Proxy\ProfileDefinition;
use Webong\Proxy\Tests\Support\WebhookFixtures;

it('creates a Guzzle client from a named endpoint profile', function (): void {
    $endpoint = WebhookFixtures::endpoint();
    $profile = $endpoint->registerProfile(new ProfileDefinition(
        name: 'partner-api',
        configuration: [
            'proxy' => [
                'https' => 'http://proxy.example.test:8080',
                'no' => ['.internal.example.test', 'metadata.example.test'],
            ],
            'options' => [
                'connect_timeout' => 5,
                'timeout' => 15,
            ],
        ],
    ));

    $factory = app(GuzzleClientFactory::class);
    $client = $factory->make($endpoint, 'partner-api');

    expect($profile->endpoint_id)->toBe($endpoint->record->id)
        ->and($client)->toBeInstanceOf(ClientInterface::class)
        ->and($factory->options($endpoint, 'partner-api'))->toBe([
            'connect_timeout' => 5,
            'timeout' => 15,
            'proxy' => [
                'https' => 'http://proxy.example.test:8080',
                'no' => ['.internal.example.test', 'metadata.example.test'],
            ],
        ]);
});

it('rejects missing, malformed, and incompatible endpoint profiles', function (): void {
    $endpoint = WebhookFixtures::endpoint();
    $factory = app(GuzzleClientFactory::class);

    expect(fn (): array => $factory->options($endpoint, 'missing'))
        ->toThrow(InvalidArgumentException::class, 'not registered');

    $endpoint->registerProfile(new ProfileDefinition(
        name: 'invalid',
        configuration: [
            'proxy' => ['no' => 'internal.example.test'],
        ],
    ));

    expect(fn (): array => $factory->options($endpoint, 'invalid'))
        ->toThrow(InvalidArgumentException::class, 'no-proxy hosts');

    $endpoint->registerProfile(new ProfileDefinition(
        name: 'not-guzzle',
        configuration: [],
        driver: 'curl',
    ));

    expect(fn (): array => $factory->options($endpoint, 'not-guzzle'))
        ->toThrow(InvalidArgumentException::class, 'does not use the Guzzle driver');
});

it('preserves Guzzle-specific options while normalizing connection settings', function (): void {
    $endpoint = WebhookFixtures::endpoint();
    $endpoint->registerProfile(new ProfileDefinition(
        name: 'custom',
        configuration: ['options' => [
            'proxy' => 'http://proxy.example.test:8080',
            'timeout' => 2.5,
            'verify' => '/custom/ca.pem',
            'headers' => ['Authorization' => 'Bearer private'],
        ]],
    ));

    $options = app(GuzzleClientFactory::class)->options($endpoint, 'custom');
    expect($options['verify'])->toBe('/custom/ca.pem')
        ->and($options['headers'])->toBe(['Authorization' => 'Bearer private'])
        ->and($options['timeout'])->toBe(2.5)
        ->and($options['proxy'])->toBe('http://proxy.example.test:8080');

    expect(app(GuzzleClientFactory::class)->profile($endpoint, 'custom')->toArray()['proxy'])->toBe([
        'http' => 'http://proxy.example.test:8080',
        'https' => 'http://proxy.example.test:8080',
    ]);
});

it('keeps nonportable Guzzle profiles usable while rejecting their portable export', function (): void {
    $endpoint = WebhookFixtures::endpoint();
    $endpoint->registerProfile(new ProfileDefinition(
        name: 'legacy',
        configuration: [
            'proxy' => ['https' => 'socks5://localhost:1080', 'no' => ['10.0.0.0/8', '[::1]', 'host:8080']],
            'options' => ['timeout' => 0],
        ],
    ));
    $factory = app(GuzzleClientFactory::class);
    expect($factory->options($endpoint, 'legacy')['timeout'])->toBe(0);
    expect(fn () => $factory->profile($endpoint, 'legacy'))->toThrow(InvalidArgumentException::class);
});
