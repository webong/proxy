<?php

declare(strict_types=1);

use Webong\Proxy\DestinationDefinition;
use Webong\Proxy\DestinationRoute;

it('accepts application-defined destination types without adapter enums', function (): void {
    $destination = new DestinationDefinition('owner', 'registration', 'group', 'scope', 'key', 'target', 'custom-handler');
    expect($destination->targetType)->toBe('custom-handler');
    expect((new DestinationRoute('scope', 'key', []))->key)->toBe('key');
});

it('keeps webhook execution types in the Laravel adapter without old enum aliases', function (): void {
    expect(enum_exists('Webong\\Proxy\\Enums\\WebhookProxyTargetType'))->toBeFalse();
    foreach ([Webong\Proxy\Enums\DestinationTargetType::class, Webong\Proxy\WebhookRoute::class] as $type) {
        expect(str_replace('\\', '/', (new ReflectionClass($type))->getFileName()))->toContain('pkg/laravel/src/');
    }
});
