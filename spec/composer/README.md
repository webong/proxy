# Historical Composer release records

`history.json` snapshots the public stable records for `webong/proxy` and
`webong/proxy-laravel` retrieved from their Packagist package JSON endpoints on
2026-11-16. It contains seven core releases (0.1.0 through 0.4.0) and adapter
0.4.0. Development branches are intentionally excluded.

These are original release records, not new releases. Preserve this snapshot as
migration evidence. The distribution repositories were deleted on 2026-11-16 at
the user's request, leaving these source/dist URLs unavailable. The historical
merge is therefore not publication-ready: it faithfully retains dead URLs.

A reviewed migration must restore the exact component commits inside the monorepo
and prepare installable metadata without recreating separate repositories or
moving existing tags. That migration is not implemented here. Existing lockfiles
with deleted URLs need explicit updates; catalog registration alone cannot fix
them. Historical cold-install tests must be rerun after recovery.

Generate new metadata into `.publication/generated/`, then run:

```sh
python3 scripts/prepare_composer_catalog.py --generated=.publication/generated/composer-packages.json --output=composer-packages.json
```

The merger also retains stable records from the existing output (or an explicit
`--previous` index), refuses version/metadata collisions and incomplete baselines,
and replaces the output atomically only after successful validation. Never let
the publisher overwrite the published root index directly. Commit and retain
that root index and all referenced tags/commits with each future release.

The snapshot is not automatically refreshed from mutable registry responses.
Any change requires reviewing the original public release evidence; it is never
a way to republish different bytes under an existing version. The test suite
validates immutable merging and uses these records in real canonical Composer
solves, cold locked replays, partial updates and data upgrades. No public release
or registry configuration is changed by these tests.
