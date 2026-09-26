<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Base URL
    |--------------------------------------------------------------------------
    */
    'base_url' => env('PROXY_URL'),

    'secret' => env('PROXY_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Database Tables
    |--------------------------------------------------------------------------
    |
    | Override these when the registry shares a database with another
    | application. The defaults intentionally remain generic, so a standard
    | installation creates `endpoints` and `subscriptions`.
    |
    */
    'tables' => [
        'endpoints' => env('PROXY_ENDPOINTS_TABLE', 'endpoints'),
        'subscriptions' => env('PROXY_SUBSCRIPTIONS_TABLE', 'subscriptions'),
        'endpoint_registrations' => env('PROXY_ENDPOINT_REGISTRATIONS_TABLE', 'endpoint_registrations'),
        'profiles' => env('PROXY_PROFILES_TABLE', 'profiles'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Context Provider
    |--------------------------------------------------------------------------
    |
    | Integrators may provide a context provider for routing scope, authority, or
    | request-specific webhook routing. The package remains context-agnostic.
    |
    */
    'context_provider' => null,

    /*
    |--------------------------------------------------------------------------
    | Routers
    |--------------------------------------------------------------------------
    |
    | Maps webhook-client names to router classes. Integrators may generate
    | this section from #[WebhookRouter] attributes with the discovery command.
    |
    */
    'routers' => [],


    /*
    |--------------------------------------------------------------------------
    | Targets
    |--------------------------------------------------------------------------
    |
    | Maps target types and keys to target classes. Integrators may generate
    | this section from #[WebhookTarget] attributes with the discovery command.
    |
    */

    'targets' => [
        'job' => [

        ],
        'event' => [

        ],
    ],

    'discovery' => [
        'scan_on_boot' => (bool) env('PROXY_DISCOVER_ON_BOOT', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    |
    | The default channel (protocol / transport engine) and registry
    | (storage / endpoint routing) selected when none is explicitly given.
    |
    */
    'defaults' => [
        'channel' => env('PROXY_CHANNEL', 'default'),
        'registry' => env('PROXY_REGISTRY', 'local'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Channel Plane (Protocol / Transport Engine)
    |--------------------------------------------------------------------------
    |
    | A channel is the protocol over which events are received and
    | dispatched — e.g. a "webhook" channel (built on spatie
    | webhook-client) or a future "websocket" channel.
    |
    */
    'channels' => [
        [
            'name' => 'default',
            'driver' => 'webhook',
            'path' => '/',
            'methods' => ['GET', 'POST'],
            'client' => [
                'store_headers' => '*',
            ],
        ],

        /*
         | Additional channels can be registered here, e.g.:
         |
         | [
         |     'name' => 'socket',
         |     'driver' => 'websocket',
         |     'path' => 'socket/',
         |     'methods' => ['GET', 'POST'],
         | ],
        */
    ],

    /*
    |--------------------------------------------------------------------------
    | Registry Plane (Storage / Endpoint Routing)
    |--------------------------------------------------------------------------
    |
    | A registry composes an ingress driver with a storage provider. The driver
    | provisions the public endpoint; the provider owns endpoint bindings and
    | destination lookup.
    |
    | The default "local" registry uses the built-in "database" driver
    | (EnsureEndpoint storage). Other registries may reuse the same driver
    | with different options, or use another driver entirely.
    |
    */
    'registries' => [
        'local' => [
            'driver' => 'database',
            'provider' => 'database',
        ],

        /*
         | The built-in database driver provisions a package-managed endpoint.
         | Integrators may provide another EndpointDriver class.
        */
    ],

    'providers' => [
        'database' => [
            'driver' => 'database',
        ],
    ],

    'signature_tolerance' => (int) env('PROXY_SIGNATURE_TOLERANCE', 300),

    'connect_timeout' => (int) env('PROXY_CONNECT_TIMEOUT', 5),

    'timeout' => (int) env('PROXY_TIMEOUT', 15),

    'job_timeout' => (int) env('PROXY_JOB_TIMEOUT', 60),

    'idempotency_ttl' => (int) env('PROXY_IDEMPOTENCY_TTL', 86400),

    'queue_name' => env('PROXY_QUEUE'),

    'tries' => (int) env('PROXY_TRIES', 3),

    'backoff' => array_values(
        array_filter(
            array_map('intval', explode(',', (string) env('PROXY_BACKOFF_SECONDS', ''))),
            static fn (int $seconds): bool => $seconds > 0,
        ),
    ),

    'log_failures' => (bool) env('PROXY_LOG_FAILURES', true),

];
