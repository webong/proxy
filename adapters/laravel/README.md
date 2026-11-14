# Proxy Laravel

`webong/proxy-laravel` supplies Laravel persistence and Spatie webhook integration
for the framework-neutral `webong/proxy` core. An endpoint is a durable network
identity: it can receive and route webhooks through subscriptions, and it can own
named outbound profiles for clients such as Guzzle. The core provides portable
PHP/Go networking; this adapter provides the Laravel store and application APIs.

## Install

```bash
composer require webong/proxy-laravel:^0.4
```

Laravel discovers `Webong\Proxy\WebProxyServiceProvider` automatically.
Publish the package configuration and normal migrations, then migrate:

```bash
php artisan vendor:publish --tag=proxy-config
php artisan vendor:publish --tag=proxy-migrations
php artisan migrate
```

The normal migration set creates:

- `endpoints`
- `subscriptions`
- `endpoint_registrations`
- `profiles`
- `subscription_locks` (Laravel adapter transaction mutexes, unreleased)

All table names are configurable in `config/proxy.php` or through the matching
`PROXY_*_TABLE` environment variables.

## Configuration

The package config is `config/proxy.php` and is read through the `proxy.*`
namespace. Common environment variables are:

```dotenv
PROXY_URL=https://proxy.example.test
PROXY_SECRET=replace-with-an-internal-signing-secret
PROXY_CHANNEL=default
PROXY_REGISTRY=local
PROXY_ENDPOINTS_TABLE=endpoints
PROXY_SUBSCRIPTIONS_TABLE=subscriptions
PROXY_ENDPOINT_REGISTRATIONS_TABLE=endpoint_registrations
PROXY_PROFILES_TABLE=profiles
```

The built-in `local` registry uses the database driver and provider. Custom
registries can supply their own endpoint driver and provider through
`proxy.registries` and `proxy.providers`.

## Endpoints

Create or retrieve an endpoint through `EndpointRegistry`. The endpoint key is
the stable public identity, while `client` selects the webhook router that
handles incoming work.

```php
use Webong\Proxy\EndpointDefinition;
use Webong\Proxy\EndpointRegistry;

$endpoint = app(EndpointRegistry::class)->ensure(new EndpointDefinition(
    client: 'partner-webhooks',
    externalId: 'account-123',
    signingSecret: $partnerSigningSecret,
    verificationToken: $partnerVerificationToken,
    endpointKey: 'partner-account-123',
    credentialOwnerId: 'account-123',
));
```

An endpoint is not limited to webhook subscriptions. It is the registry-owned
parent for all endpoint attachments, including outbound profiles.

## Webhook subscriptions

Attach destinations when an inbound webhook route should dispatch to an HTTP
request, event, or job:

```php
use Webong\Proxy\DestinationDefinition;
use Webong\Proxy\Enums\WebhookProxyTargetType;

$endpoint->attach(new DestinationDefinition(
    ownerId: 'account-123',
    registrationId: 'partner-connection',
    webhookGroup: 'partner',
    routingScope: 'account',
    routingKey: 'account-123',
    target: 'https://receiver.example.test/webhooks/partner',
    targetType: WebhookProxyTargetType::REQUEST,
));
```

The package uses the configured channel and registry to resolve the endpoint,
match an incoming route, and dispatch active subscriptions. Delivery options
such as queue name, retries, backoff, idempotency TTL, and failure logging are
configured under `proxy.*`.

## Registry-backed Guzzle profiles

Profiles belong to an endpoint and are persisted by that endpoint's registry.
They are not global configuration entries. This lets each endpoint have its
own egress identity and keeps profile credentials in the registry store.

```php
use Webong\Proxy\GuzzleClientFactory;
use Webong\Proxy\ProfileDefinition;

$endpoint->registerProfile(new ProfileDefinition(
    name: 'partner-api',
    configuration: [
        'proxy' => [
            'https' => 'http://proxy-user:proxy-password@proxy.example.test:8080',
            'no' => ['.internal.example.test'],
        ],
        'options' => [
            'connect_timeout' => 5,
            'timeout' => 15,
        ],
    ],
));

$client = app(GuzzleClientFactory::class)->make($endpoint, 'partner-api');
$response = $client->get('https://api.partner.example.test/v1/status');
```

`GuzzleClientFactory` accepts Guzzle's usual `proxy` string or per-scheme map,
including the `no` bypass list. Other Guzzle options belong under `options`.
Profile configuration is encrypted at rest by the database provider.

## Migrating from `webong/web-proxy`

New applications should use only the normal migration tag. Existing
installations that already ran the historical prefixed migrations need one
explicit compatibility step before they migrate the renamed package:

```bash
php artisan vendor:publish --tag=proxy-legacy-migrations
php artisan vendor:publish --tag=proxy-migrations
php artisan migrate
```

The opt-in legacy migration renames the historical prefixed tables and updates
Laravel's `migrations` table to the new migration names. It is intentionally
not included in `proxy-migrations`.

## Upgrade from Proxy 0.3

Version 0.4.0 is an unreleased candidate. Change the application's Composer
requirement from `webong/proxy:^0.3` to `webong/proxy-laravel:^0.4`. The adapter
requires `webong/proxy:^0.4`, which now combines transports and registry contracts.
If a direct core requirement is retained, update it to ^0.4 as well. Applications
opting out of discovery must change `extra.laravel.dont-discover` from
`webong/proxy` to `webong/proxy-laravel` (a wildcard opt-out remains effective). Existing PHP
namespaces, provider classes, publish tags, configuration keys and migration
filenames remain unchanged; no aliases or duplicate classes are introduced.

Migration/config resources move from `vendor/webong/proxy` to
`vendor/webong/proxy-laravel`. Update any application code that references vendor
paths directly. Existing application-published files and recorded migration names
remain valid. Publish/run the new
`2026_11_14_000001_create_subscription_locks_table` migration before using the new
adapter, and upgrade subscription writers together. The table stores mutex keys
only; existing registry records are not rewritten. Stop writers before removing
mutex state or rolling back. Set `proxy.tables.subscription_locks` before migrating
if the table name must be customized.

The adapter explicitly requires Laravel Framework ^12 or ^13 because jobs/events
use Foundation traits; its runtime dependencies are complete without Testbench.
It owns its schema and all Laravel/Spatie dependencies. Plain records,
transport code and storage contracts belong to `webong/proxy`; installing the core
alone never registers this provider or loads these migrations. No new webhook
extraction to a different application is included.

## Development

Run bootstrap and tests from the repository root; this package is linked as a
development workspace. No Composer plugin is required by consumers. Public
publication requires exposing the `adapters/laravel` subtree as an independent Composer repository
or artifact package after the core 0.4.0 release is available.
