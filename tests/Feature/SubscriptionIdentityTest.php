<?php

declare(strict_types=1);

use Webong\Proxy\Database\SubscriptionIdentity;
use Webong\Proxy\DatabaseEndpointProvider;
use Webong\Proxy\DestinationRegistry;
use Webong\Proxy\Models\WebProxyDestination;
use Webong\Proxy\Tests\Support\WebhookFixtures;

it('maintains identity for quiet writes and partial model updates', function (): void {
    $record = WebhookFixtures::destination(WebhookFixtures::endpoint());
    $original = WebProxyDestination::findOrFail($record->id);
    $partial = WebProxyDestination::query()->select('id')->findOrFail($record->id);
    $partial->update(['last_error' => 'delivery failed']);
    expect($original->fresh()->registration_hash)->toBe($original->registration_hash);
    $partial->routing_key = 'updated';
    $partial->saveQuietly();
    $updated = $original->fresh();
    expect($updated->registration_hash)->toBe(SubscriptionIdentity::hash($updated->getAttributes()));
    $copy = $updated->replicate();
    $copy->registration_id = 'quiet-copy';
    $copy->saveQuietly();
    expect($copy->fresh()->registration_hash)->toBe(SubscriptionIdentity::hash($copy->getAttributes()));
});

it('maintains identity through the public provider update API', function (): void {
    $record = WebhookFixtures::destination(WebhookFixtures::endpoint());
    app(DatabaseEndpointProvider::class)->updateDestination($record, ['routing_key' => 'updated']);
    $model = WebProxyDestination::findOrFail($record->id);
    expect($model->registration_hash)->toBe(SubscriptionIdentity::hash($model->getAttributes()));
});

it('keeps exclusive registration replacement consistent with exact identity', function (): void {
    $endpoint = WebhookFixtures::endpoint();
    $first = WebhookFixtures::destination($endpoint, registrationId: 'registration');
    $second = WebhookFixtures::destination($endpoint, registrationId: 'REGISTRATION');
    expect(WebProxyDestination::findOrFail($first->id)->is_active)->toBeFalse()
        ->and(WebProxyDestination::findOrFail($second->id)->is_active)->toBeTrue()
        ->and(WebProxyDestination::where('is_active', true)->count())->toBe(1);
});

it('isolates identity lookup and deactivation across collated identifiers', function (): void {
    $endpoint = WebhookFixtures::endpoint();
    WebhookFixtures::destination($endpoint, registrationId: 'Case', allowsMultipleSubscribers: true);
    WebhookFixtures::destination($endpoint, registrationId: 'case', allowsMultipleSubscribers: true);
    $registry = app(DestinationRegistry::class);
    expect($registry->deactivate('owner-1', 'Case', 'example-events'))->toBe(1)
        ->and($registry->hasActive('owner-1', 'case', 'example-events'))->toBeTrue();
});

it('canonicalizes UUID endpoint identity across persistence and refresh', function (): void {
    $record = WebhookFixtures::destination(WebhookFixtures::endpoint());
    $model = WebProxyDestination::findOrFail($record->id);
    $copy = $model->replicate();
    $copy->registration_id = 'upper-uuid';
    $copy->endpoint_id = strtoupper($copy->endpoint_id);
    $copy->save();
    expect($copy->registration_hash)->toBe(SubscriptionIdentity::hash($copy->fresh()->getAttributes()));
    $duplicate = $copy->replicate();
    $duplicate->endpoint_id = strtolower($copy->endpoint_id);
    expect(fn () => $duplicate->save())->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
});

it('distinguishes numeric-string owners when enforcing route exclusivity', function (): void {
    $endpoint = WebhookFixtures::endpoint();
    WebhookFixtures::destination($endpoint, ownerId: '01', registrationId: 'first');
    expect(fn () => WebhookFixtures::destination($endpoint, ownerId: '1', registrationId: 'second'))
        ->toThrow(RuntimeException::class, 'already registered to another owner');
});

it('deactivates the previous registration when only route identity casing changes', function (): void {
    $endpoint = WebhookFixtures::endpoint();
    $old = WebhookFixtures::destination($endpoint, registrationId: 'same', routingKey: 'Key');
    $new = WebhookFixtures::destination($endpoint, registrationId: 'same', routingKey: 'key');
    expect(WebProxyDestination::findOrFail($old->id)->is_active)->toBeFalse()
        ->and(WebProxyDestination::findOrFail($new->id)->is_active)->toBeTrue();
});

it('hashes the persisted identity when independently loaded models update different fields', function (): void {
    $record = WebhookFixtures::destination(WebhookFixtures::endpoint());
    $first = WebProxyDestination::findOrFail($record->id);
    $stale = WebProxyDestination::findOrFail($record->id);
    $first->update(['routing_key' => 'new-route']);
    $stale->update(['owner_id' => 'new-owner']);
    $stored = $first->fresh();
    expect($stored->routing_key)->toBe('new-route')
        ->and($stored->owner_id)->toBe('new-owner')
        ->and($stored->registration_hash)->toBe(SubscriptionIdentity::hash($stored->getAttributes()));
});

it('preserves numeric model identifiers as SQL strings and canonical endpoint UUIDs', function (): void {
    $record = WebhookFixtures::destination(WebhookFixtures::endpoint());
    $model = WebProxyDestination::findOrFail($record->id)->replicate();
    $model->owner_id = 123;
    $model->registration_id = 456;
    $model->endpoint_id = strtoupper($model->endpoint_id);
    $model->saveQuietly();
    $stored = $model->fresh();
    expect($stored->owner_id)->toBe('123')
        ->and($stored->endpoint_id)->toBe(strtolower($model->endpoint_id))
        ->and($stored->registration_hash)->toBe(SubscriptionIdentity::hash($stored->getAttributes()))
        ->and($stored->endpoint)->not->toBeNull();
});
