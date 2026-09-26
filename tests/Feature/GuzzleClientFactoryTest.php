<?php

declare(strict_types=1);

use GuzzleHttp\ClientInterface;
use Webong\WebProxy\GuzzleClientFactory;
use Webong\WebProxy\ProfileDefinition;
use Webong\WebProxy\Tests\Support\WebhookFixtures;

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
