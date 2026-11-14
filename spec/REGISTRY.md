# Proxy registry contracts

`webong/proxy` includes storage-neutral registry contracts alongside its PHP
transport tools. The package requires PHP 8.3+ and Guzzle, with no framework
dependencies; the registry types themselves depend only on PHP.
It provides endpoint, destination and profile definitions/records and three storage
ports under `Webong\Proxy\Registry\Contracts`:

- `EndpointRepository`: define/find/resolve endpoints, managed endpoint lookup,
  registry ownership indexes and endpoint updates.
- `DestinationRepository`: attach/update/select subscriptions and deactivate or
  query them by owner, registration and webhook group.
- `ProfileRepository`: upsert and retrieve named endpoint profiles.

Install with `composer require webong/proxy:^0.4`. This is an unreleased
candidate: publish the package before installing that version from Packagist.
The existing record names remain `Webong\Proxy\EndpointRecord`, etc. The Laravel
adapter requires core ^0.4, preventing duplicate ownership with older releases.

An application implements the ports it needs and owns its schema, migrations,
connection lifecycle, encryption, validation policy and transaction boundaries.
No container, ORM, SQL dialect, Laravel collection, callback URL generator or
migration is provided by this package. Identifiers are opaque strings: UUID/ULID
validation is a policy of the Laravel adapter, not a requirement for custom stores.
Destination selection returns a plain list of records. The route payload and
headers are carried as data; they do not imply a webhook execution dependency.

## Adapter semantics

Implementations must document their validation and exception types. Reads return
null (or an empty destination list) for absent/inactive records. Storage errors
must propagate; they must not be disguised as missing records. Returned records
are snapshots, not live ORM entities. Callers must use update methods to persist
changes and re-read after `updateDestination`, which returns void.

`define` ensures endpoint identity by `(client, externalId)` and merges provisioned
key/metadata/callback information with the definition. It must preserve credential
ownership: redefining an endpoint cannot silently transfer its secrets to a new
owner. `register` records the endpoint's registry ownership; it is separate from
`define`. Index lookups include active registrations only. Updates to a missing
endpoint fail rather than create it. Implementations define their supported update
attributes; the Laravel adapter retains its existing Eloquent fillable/force-fill
behavior for compatibility.

`attach` is an atomic subscription upsert. Its identity consists of endpoint,
owner, registration, webhook group, routing scope/key and target type. Repeating it
updates target/metadata and reactivates the same subscription. Moving a registration
to another route deactivates its previous route subscriptions in the same operation.
Exclusive routes cannot acquire a second owner while remaining exclusive. The
Laravel adapter supports promotion to multiple subscribers by upgrading existing
subscriptions atomically; it rejects a subsequent demotion to exclusive mode. Owner/registration/group comparisons are exact
(case and trailing spaces matter); route matching may follow adapter collation.
Deactivation returns the number of previously active matches changed. Destination
selection includes active matches in stable creation order with a deterministic
tie breaker. Missing destination updates are no-ops, matching the legacy API.

Profile identity is `(endpoint id, name)`. `registerProfile` updates and reactivates
that profile; `profile` returns active profiles only. Configuration is plain PHP
data at this boundary; adapters must protect secrets at rest. Concurrent upserts
and exclusivity checks must be protected with backend constraints/transactions or
an equivalent atomic mechanism. The interfaces deliberately expose domain
operations, not SQL query builders or a universal transaction abstraction.

## Laravel adapter and compatibility

`webong/proxy-laravel` is the Laravel adapter/compatibility package. Its
`LaravelRegistryStore` implements all three ports, retaining Eloquent encryption,
identity hashing, validation, transactions and database-specific safeguards.
It alone owns `database/migrations` and `database/legacy-migrations`, publishes them
through the existing `WebProxyServiceProvider` tags, and does not run them merely
because the neutral registry package is installed. Existing published migration
filenames remain unchanged. The concurrency fix adds one adapter-owned migration,
`2026_11_14_000001_create_subscription_locks_table`: existing Laravel applications
must publish/run it before using this adapter. All subscription writers must be
upgraded to participate in the locking protocol; avoid mixing old and new writers. It adds a configurable
`subscription_locks` table containing only hash keys, not application records or
secrets. Registrations use durable mutex rows so moves between endpoints serialize;
endpoint rows serialize exclusive route checks. Do not remove mutex rows while
writers are running. Rollback drops only the mutex table; stop writers before
rolling back or removing the adapter. Other registry data remains intact.

The provider binds each port to `LaravelRegistryStore` unless the application has
already bound it. The default store is scoped so request/job URL dependencies are
not retained across scope resets. Applications may also replace bindings after provider registration
before the legacy provider is resolved. `DatabaseEndpointProvider` keeps its first
three constructor arguments and accepts optional repository overrides; its existing
`EndpointProvider` contract, `Endpoint` wrappers and Illuminate collection return
remain Laravel compatibility APIs. Explicit application repositories take precedence.
The default implementation uses the provider's original constructor dependencies,
including `makeWith` overrides, contextual bindings and inherited constructors. Framework-free apps use the new ports and plain
records directly, without loading that compatibility interface.

```php
use Webong\Proxy\Registry\Contracts\ProfileRepository;

// $store is an application-owned adapter implementing ProfileRepository.
function saveProfile(ProfileRepository $store, \Webong\Proxy\EndpointRecord $endpoint): void
{
    $store->registerProfile($endpoint, new \Webong\Proxy\ProfileDefinition(
        'api', ['request_timeout_seconds' => 10],
    ));
}
```

The Composer packaging tests install the core package alone and exercise an
application-owned SQLite profile store whose schema is unrelated to the Laravel
schema. No production in-memory or SQL adapter is bundled in the neutral package.
