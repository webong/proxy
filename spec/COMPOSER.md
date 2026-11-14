# Composer package layout (unreleased)

This checkout prepares the next package generation; it does not change the
published v0.3.1 artifacts. `webong/proxy-transport` and `webong/proxy-registry` 0.4.0 are unpublished
candidates. Archive-based consumer tests use a temporary Composer repository,
not a claim that Packagist already serves the new package.

## Ownership and dependency installation

```text
webong/proxy (existing Laravel integration and compatibility entry point)
  ├── webong/proxy-transport (portable PHP code, Guzzle; no Illuminate/Spatie)
  ├── webong/proxy-registry (plain records and repository ports; PHP only)
  ├── Illuminate registry adapter
  └── existing Spatie webhook integration
```

The registry contracts and plain data types live in `webong/proxy-registry`.
The Laravel registry implementations, subscriptions, named profile storage, ownership
indexes, models, persistence, configuration and migrations remain in `webong/proxy`. Its provider and every existing public namespace remain valid.
The packaging change itself needs no data conversion. The accompanying Laravel
concurrency fix adds an adapter-owned subscription-locks migration; publish/run it
before using the candidate adapter. Existing SQL upgrade requirements still apply.

The neutral package owns `Webong\Proxy\Http`, `Connection`, `Tcp` and `Udp`.
These namespaces and signatures are unchanged; no aliases/rebases or duplicate
class definitions are introduced. Existing users keep the full installation by
requiring the next compatible `webong/proxy` release. New transport-only users
will require `webong/proxy-transport:^0.4` after it is published. It only requires
PHP and Guzzle/PSR HTTP dependencies, and advertises no Laravel provider.

Old Proxy releases already contain those transport classes, so the new neutral
package conflicts with `webong/proxy:<0.4.0`. Upgrade the full package together
rather than installing both copies of the classes. The root remains the Laravel
integration package; a second registry package would add migration/provider
churn without improving the independent transport install.

Spatie extraction is separate work. This change deliberately preserves the
current integration and does not remove or move registry/webhook functionality.
The Go module, imports, profiles and source layout do not change.

## Development workspaces

The root requires `webong/composer-source-plugin:^0.4.0` in require-dev and
explicitly permits it under allow-plugins. Version 0.4.0 is published and supports
the configuration used here; README examples using ^1.0 must not be copied.
`extra.source-plugin.workspaces` names `packages/transport` and `packages/registry` literally so a typo
fails rather than silently skipping a glob.

On a fresh clone (no vendor or local lock):

```sh
php scripts/bootstrap-composer.php
composer source:workspaces --json
composer test
```

The bootstrap installs only the trusted plugin from `composer.bootstrap.json`
into the checkout's vendor directory, then installs the real manifest. This is
necessary because a plugin cannot inject an unpublished workspace on the run
that installs the plugin itself. Do not use the seed manifest as a runtime
consumer. A custom Composer executable path can be supplied using
PROXY_COMPOSER_BINARY.

For an existing development checkout with an old ignored lock, resolve the new
manifest explicitly after seeding the plugin:

```sh
php scripts/bootstrap-composer.php --plugin-only
composer update --prefer-dist
```

Seeding may replace development vendor contents; the following normal install
restores the complete dependency graph. It does not edit the real manifest.
Both lockfiles are local development state, as before. Workspace locks record
relative path dists; fresh installations from those locks can use --no-plugins
for the already-recorded set. Resolution of workspace changes requires the
plugin. Library consumers do not inherit require-dev or root workspace settings.

The workspace manifest declares version 0.4.0 so the plugin can validate the
root's ^0.4.0 requirement. Bump this candidate version and requirement together
when planning a later release. Never use a path dependency as published
consumer installation metadata.

## Optional consumer projection

Ordinary consumer installs do not need the plugin. Both PHP packages advertise
one conservative `php` surface retaining all runtime source, discovery metadata,
configuration/migrations where applicable, README and MIT license. No advertised
provider, class or runtime resource is pruned.

A consumer that chooses projection may use the following after the candidate
packages have been published:

```json
{
  "require": {
    "webong/proxy": "^0.4",
    "webong/composer-source-plugin": "^0.4"
  },
  "config": {"allow-plugins": {"webong/composer-source-plugin": true}},
  "extra": {
    "source-plugin": {
      "projections": {
        "webong/proxy": {"surfaces": ["php"]},
        "webong/proxy-transport": {"surfaces": ["php"]}
      }
    }
  }
}
```

For a neutral consumer, require only proxy-transport and project only that
package. Install the plugin before relying on projection: the first update
installs it/full packages, the second update applies and records projection.
Commit composer.lock and composer-source.lock. A fresh locked clone likewise
needs the initial plugin install followed by a second install to reconcile the
recorded projection. Keep the plugin as a regular requirement if production
--no-dev installs must perform projection; it remains optional for runtime use.

Do not project a package also selected by a workspace/loader or mirror. In this
checkout proxy-transport is a workspace, so projection tests run in separate
archive consumers. Projection only prunes installed files after dependency
resolution; projecting webong/proxy does not remove Illuminate or Spatie.
Choose the neutral package for independent dependency installation.

## Release order and verification

1. Publish the packages/transport and packages/registry subtrees as independent
   Composer packages webong/proxy-transport and webong/proxy-registry, each with
   its own root manifest and matching 0.4.0 tag.
   Packagist does not discover a second package from a repository subdirectory;
   a split repository or equivalent artifact registry must be configured.
2. Verify a clean install from the intended public registry.
3. Only then publish the parent release that requires ^0.4.0. Do not merge/release
   an unsatisfiable default-registry installation before both dependencies exist.

No new repository, tag or external registry is created by this implementation.
Local ZIP fixtures demonstrate the dependency graph and runtime behavior, while
actual public publication is a separate release gate.

```sh
make packaging
make readiness PYTHON=/path/to/python-with-jsonschema
```

Packaging checks exercise Composer archive resolution without path repositories,
fresh lock clones without plugins, no-dev production consumers, optional
projection and fresh projected clones, real Laravel provider discovery and
registry/profile/migration behavior, legacy class-owner conflicts, and a fresh
workspace bootstrap. Neutral consumers make real loopback HTTP/TCP/UDP requests
and check that no Illuminate, Laravel or Spatie dependency was resolved. The
existing frozen interface and PHP/Go regression gates remain unchanged.

## Storage-neutral registry package

The workspace also contains `packages/registry` (`webong/proxy-registry`, candidate
0.4.0). It owns the existing plain endpoint/destination/profile definitions and
records, the target-type enum and route data, plus the three repository ports.
It requires only PHP. See the `webong/proxy-registry` README
for identity, atomicity, error and schema ownership requirements.

The parent `webong/proxy` package remains the Laravel adapter. It owns the Eloquent
models, `LaravelRegistryStore`, legacy provider facade, encryption, config and all
migrations. Its existing API and migration filenames are preserved. Moving the
plain types to the required registry dependency does not require a database
migration or application namespace change. Separately, the adapter's concurrency
fix requires publishing/running `2026_11_14_000001_create_subscription_locks_table`;
its rollback affects only mutex state. See the registry README for deployment order
and custom table configuration. Framework-free applications install
only `webong/proxy-registry` and supply the ports they need; they own their schemas.

Publish and verify **both** child packages (`proxy-transport` and `proxy-registry`)
at 0.4.0 before releasing the parent requiring them. The parent remains unreleased
until both dependencies are available. The optional `php` projection for the
registry package retains its source, README and license. Fresh ZIP install,
projection and lock-clone tests cover registry-only and full Laravel consumers.
