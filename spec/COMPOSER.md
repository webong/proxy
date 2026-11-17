# Components, packaging and releases (0.4.0)

```text
composer.json                  webong/proxy-workspace: author workspace + catalog
php/composer.json              webong/proxy: PHP + Guzzle, no Laravel
adapters/laravel/composer.json  webong/proxy-laravel: Laravel/Spatie + PHP core
go/go.mod                      github.com/webong/proxy/go: Go 1.23+
```

Transport and storage-neutral registry contracts are combined in the PHP core.
The Laravel adapter owns every Eloquent model, encrypted store, provider, config
file and migration. PHP namespaces remain `Webong\Proxy`; no duplicate classes or
aliases are introduced. Go has its own module root and import prefix ending in
`/go`. There is no root Go module or go.work file.

## Development workspace

```sh
php scripts/bootstrap-composer.php
composer test
go -C go test -race ./...
go -C go vet ./...
python3 scripts/sync_go_testdata.py --check
make packaging
make readiness PYTHON=/path/to/python-with-jsonschema
```

Composer Source Plugin ^0.5 is author/development tooling. Bootstrap seeds the plugin,
then resolves literal `php` and `adapters/laravel` workspaces. Their manifests use
workspace version 0.4.0, so fresh checkouts without Git metadata resolve the same
local graph. The root metapackage contains no library code and neither component
requires it. Its catalog publication is a separate, pending step. Update the
ignored old composer.lock after a layout change with `composer update --prefer-dist`. When upgrading from plugin
0.4, run `php scripts/bootstrap-composer.php --plugin-only` first, then
`composer update --prefer-dist`. Seeding replays a compatible bootstrap lock
unchanged and updates only an incompatible isolated bootstrap plugin lock; it
does not update the full development dependency graph silently.

Neither component requires the plugin at runtime. Current 0.4.0 installs need
no plugin; future catalog consumers use one-time plugin setup or add the index
URL directly. Installed libraries need no bootstrap script or path repositories. Root dev dependencies and scripts are never inherited.

## Isolated distributions

PHP consumers resolve ZIPs whose root is the corresponding component directory.
Both Composer archives and Git subtree archives are tested. Each PHP component's
own .gitattributes and archive.exclude remove development vendor/lock/cache files.
Core archives contain no adapter, Go, configuration or migration directories.

Go module ZIPs contain only `go/` content, with paths relative to that directory.
All Go source, internal packages, contract tests, snapshot tooling, README and
LICENSE live there. Checked-in `go/testdata` mirrors make tests independent of
parent directories. The repository's `spec` and shared TLS fixtures are canonical;
`scripts/sync_go_testdata.py --check` rejects drift. Update canonical fixtures,
then run the sync script. The checker rejects linked files/directories, missing
canonical groups and stale mirrors; sync replaces file links without following
them and refuses linked directories. Isolated regression tests run in schema CI
and readiness. Standalone Go fixture suites also fail on missing/empty groups.
Packaging extracts the isolated Go subtree and runs
race tests and vet. A separate consumer downloads a correctly prefixed module ZIP
through a temporary file GOPROXY with an empty private module cache and no replace
or go.work. This validates Go's real module download/ZIP handling without creating
a public version or polluting the user's module cache. Internal consumer guards
also use GOPROXY=off and local replacement to isolate compile-time API checks.

## Optional PHP projections

After installing the optional Composer Source Plugin, consumers may select the
`php` surface for `webong/proxy` and, when installed, `webong/proxy-laravel`.
Core surfaces retain source, README and license; adapter surfaces also retain
configuration and migrations. Projection prunes files after dependency resolution;
it is not a substitute for choosing the framework-neutral core. Projection tests
use installed archives, not development workspace symlinks.

## PHP upgrade and schema ownership

Existing Laravel consumers replace their direct `webong/proxy:^0.3` requirement
with `webong/proxy-laravel:^0.4`. Update any retained direct core constraint to
^0.4 too. Rename an old `extra.laravel.dont-discover` entry for `webong/proxy` to
`webong/proxy-laravel`; wildcard opt-outs remain effective. The adapter explicitly
requires Laravel Framework ^12 or ^13, including Foundation traits used by jobs.

Provider namespaces, publish tags, config keys, table names and recorded migration
filenames are unchanged. Direct vendor resource paths move from
`vendor/webong/proxy` to `vendor/webong/proxy-laravel`. No data conversion is caused
by moving source directories. Existing app-published migrations remain valid.

The concurrency fix requires publishing/running
`2026_11_14_000001_create_subscription_locks_table` before using the new adapter.
Upgrade subscription writers together. Durable mutex rows store identity hashes;
stop writers before cleanup or rollback. The neutral core owns no schema; custom
adapters own theirs. See [SQL upgrades](SQL.md) and [registry contracts](REGISTRY.md).

## Go upgrade

Update imports from `github.com/webong/proxy/...` to
`github.com/webong/proxy/go/...`, require the new module, then run `go mod tidy`
in the consuming application. Old v0.3.x tags and imports remain immutable and
available. Transport API shapes and JSON profile version 1 remain unchanged.
The Go module uses tags such as **`go/v0.4.0`**. A root `v0.4.0` tag does not
publish the nested Go module. See [the Go guide](../go/README.md).

## Same-repository publication (prepared, not live)

Future PHP releases use Composer Source Plugin 0.5 or newer. It publishes the
existing `php` and `adapters/laravel` workspaces as package-root split branches
and immutable package tags in **this repository**. No additional Git repositories
are needed. The root `webong/proxy-workspace` metapackage advertises a catalog at
`https://raw.githubusercontent.com/webong/proxy/main/composer-packages.json`.
That catalog package is not yet registered/released and the index is not yet
published; do not use the proposed consumer commands until the correction below
is completed.

Author tooling reads **committed** manifests, not local edits. Use a complete
(non-shallow) publishing checkout with all existing package tags and the prior
root index fetched; shallow splits do not reproduce the same source history.
Prepare a new PHP
patch version (proposed 0.4.1) in both component manifests before release. Keep
Go's independent module/tag versions unchanged. With an approved signed source
commit and a configured signing key in the publishing checkout:

```sh
composer source:publish list
composer source:publish release --release-version=0.4.1 --dry-run
composer source:publish split
git tag -s webong/proxy/v0.4.1 dist/webong/proxy -m 'webong/proxy v0.4.1'
git tag -s webong/proxy-laravel/v0.4.1 dist/webong/proxy-laravel -m 'webong/proxy-laravel v0.4.1'
git verify-tag webong/proxy/v0.4.1 webong/proxy-laravel/v0.4.1
composer source:publish release --release-version=0.4.1 --no-metadata-branch --out-dir=.publication/generated
composer source:publish verify --composer
python3 scripts/prepare_composer_catalog.py --generated=.publication/generated/composer-packages.json --output=composer-packages.json
```

The generated refs are `dist/webong/proxy`, `dist/webong/proxy-laravel`,
`webong/proxy/v0.4.1` and `webong/proxy-laravel/v0.4.1`. Source and ZIP URLs point
to exact **split commits**, whose trees have ordinary package roots. The
`composer-packages.json` file belongs on main. Generate into the ignored staging
folder first; never point the publisher at an already published root index.
The merge command includes `spec/composer/history.json` and the existing output
(if present), rejects conflicting stable records, and atomically replaces the
output only after validation. An explicit `--previous=PATH` can supply a prior
published index when the output is new. There is no `dist/composer` branch.
Review/sign/verify refs and index before explicitly pushing; the command does not
push unless `--push` is supplied. Never force branches or replace stable tags.
The explicit signing commands create annotated tags before the publisher sees
them; it accepts existing tags pointing to the exact same split commits. This
avoids relying on its default tag creation for signing. Verify signatures before
any push. The publisher does not sign GitHub archive bytes.

The proposed catalog release is independently versioned as **0.0.1** (root tag
`v0.0.1`), while PHP components use their namespaced `0.4.1` tags. Keep catalog
releases in `0.0.x`, below the legacy root Go module's last `v0.3.1`, so catalog
publication does not advance that module's `@latest`. Do not create a root
`v0.4.1` tag. Existing root tags remain immutable, and Go's new module continues
using only `go/v...` tags. A root catalog tag is not a Go release; leave the Go
GitHub release marked as the main product release. Always specify the catalog
`^0.0` constraint during discovery, excluding legacy root tags whose manifests
do not advertise a catalog.

Once the index and the root catalog 0.0.1 release are publicly reachable and
verified, a consumer can bootstrap discovery once inside its Composer project:

```sh
composer config allow-plugins.webong/composer-source-plugin true
composer require --dev webong/composer-source-plugin:^0.5
composer source:catalog 'webong/proxy-workspace:^0.0'
composer require webong/proxy:^0.4.1
# Or, for Laravel:
composer require webong/proxy-laravel:^0.4.1
```

The plugin must be explicitly trusted before its command can run. Interactive
Composer may ask for that trust during its initial install; automated installs
must preconfigure allow-plugins. Discovery adds a scoped standard Composer
repository for exactly the two component names; it does not install the catalog
or merge package dependencies. After setup, ordinary locked installs work with
`--no-dev --no-plugins`. Runtime projections separately require the plugin in
production `require`. Consumers may also configure the full index URL directly.

Packagist indexes the root catalog, **not** nested manifests. A plain
`composer require webong/proxy-laravel:^0.4.1` using only public Packagist will
not find a monorepo-index-only release. Do not retarget either existing PHP name
to the root metapackage. Existing 0.4.0 registry records still contain deleted
distribution URLs; they are not a working public installation path.

## Safe correction of the 0.4.0 publication

The user requested deletion of `webong/proxy-php` and `webong/proxy-laravel`;
both repositories were deleted on 2026-11-16 after Git/release backups were
verified. Do not recreate separate distribution repositories. Packagist records
and existing locks still point to those deleted URLs, so clean PHP installs and
cold historical-install tests are currently blocked. Local preservation tests
passed before deletion; that evidence does not establish post-deletion availability.

Before publishing this catalog, recover the exact historical component commits
inside the monorepo and review a source/dist metadata migration. The frozen
snapshot remains evidence of the original records, not a ready-to-serve index.
The current merger intentionally refuses rewriting those records; an explicit,
tested migration mechanism is still required. Existing locks that embed deleted
URLs need an explicit dependency/lock update. Catalog registration alone does
not fix them. Do not publish the generated index while it retains dead URLs.

1. Prepare/review a new PHP patch release and the root catalog identity
   `webong/proxy-workspace`. Do not reuse 0.4.0 or change its recorded metadata.
   Generate both package roots and index in the monorepo, verify signed source and
   release tags, then test plugin-free source/ZIP installs and Laravel upgrades.
2. Run the historical merge above before exposing the canonical catalog.
   `spec/composer/history.json` is a reviewed snapshot of all eight existing
   stable Packagist records: seven core releases through 0.4.0 and the adapter's
   0.4.0. Their complete records (including source/dist URLs, refs, dependencies
   and discovery metadata) remain unchanged. The merger rejects a missing
   baseline release, duplicate version aliases/JSON keys, changed stable metadata
   or an unrelated package. It also retains later stable records from the prior
   root index so regeneration cannot remove a version when a tag is unavailable.
   Never refresh the frozen baseline to rewrite a release; add new versions via
   generated records, retaining the prior published index. Inspect and retain
   all previous catalog versions/refs before first publication or any recovery.
   Before deletion, local tests solved old exact versions through a canonical catalog, replayed
   old locks from empty caches, exercised partial updates and performed a real
   0.3.1 data upgrade. Repeat these tests after historical-reference recovery;
   their current public download URLs are unavailable. Public URL availability
   and Packagist catalog registration need verification at the authorized release step.
3. With explicit publication approval, push new split refs before committing and
   exposing the index on main. Check every public archive/reference is reachable.
   Register the root catalog on Packagist and publish its reserved `v0.0.1` root tag only
   after the index is available. Verify catalog discovery from a fresh empty
   Composer home/cache, independent core and Laravel installs, no-plugin locked
   reinstalls, old exact versions, partial updates and real data upgrades.
4. Repair the existing Packagist source configuration through a separately
   reviewed migration; do not point both names at the root metapackage and assume
   nested discovery works. Preserve historical content and immutable tags while
   documenting any necessary URL/lock migration caused by repository deletion.
5. A consumer can restore its previous manifests/locks only if their artifacts
   remain reachable. Old locks referencing deleted repositories cannot currently
   be restored from public sources. Validate the new monorepo installation and
   rollback path before asking consumers to switch; retain all restored/new
   commits and tags in the monorepo.

This adoption publishes no real release refs or root index, Packagist registration or
registry retarget. Go stays at its existing module path and release tag. See the
[plugin's released publication contract](https://github.com/webong/composer-source-plugin/blob/v0.5.0/docs/publication.md).

## Verification

`make packaging` requires Composer, Git, Go, PHP ZIP and PDO SQLite. It creates
real Composer and Git-subtree artifacts for both PHP components and an isolated
Go archive. It tests neutral transport/registry use, no-dev production solves
without Testbench, Laravel 12/13 discovery and opt-outs, encryption and stored
profiles, fresh plugin-free lock clones, projections, cold two-workspace bootstrap
and portable lock recreation. A public v0.3.1 consumer is seeded and upgraded;
raw rows, ciphertexts, IDs and migration history are preserved, with only the
pending mutex migration applied. Exported Go tests retain the frozen API-shape
baseline and include independent-consumer compilation/execution on the new path.

`make publication-check` installs the released plugin from Packagist and runs
the real publisher against a disposable copy of these package roots. It checks
deterministic same-repository refs, full-file catalog registration, independent
dependency graphs, source/ZIP installs, Laravel behavior, cold plugin-free lock replay, old-version
solves/partial updates/data upgrades, historical metadata retention and refusal
to move stable versions. Separate bootstrap scenarios cover old developer locks,
valid seed-lock replay and damaged vendor repairs. No actual Proxy refs or registry state change.
