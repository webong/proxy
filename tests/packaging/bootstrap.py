#!/usr/bin/env python3
"""Released-plugin bootstrap upgrades and damaged-vendor replay."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

SOURCE = Path(__file__).resolve().parents[2]
LAB = Path(tempfile.mkdtemp(prefix='proxy-bootstrap-'))
ENV = dict(os.environ, COMPOSER_HOME=str(LAB/'home'), COMPOSER_CACHE_DIR=str(LAB/'cache'), COMPOSER_AUTH='{}')
ENV.pop('COMPOSER', None)
ROOT = LAB/'workspace'
shutil.copytree(SOURCE, ROOT, ignore=shutil.ignore_patterns('.git', 'vendor', '*.lock', '.phpunit.cache', '__pycache__'))
print(f'Bootstrap fixtures: {LAB}', flush=True)


def run(*arguments, manifest=None, success=True, extra=None):
    env = dict(ENV, **(extra or {}))
    if manifest:
        env['COMPOSER'] = str(ROOT/manifest)
    result = subprocess.run(arguments, cwd=ROOT, env=env, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    if (result.returncode == 0) != success:
        raise RuntimeError(f'{arguments}:\n{result.stdout}')
    return result.stdout


def write(path, value):
    path.write_text(json.dumps(value, indent=2)+'\n')


# A brand-new checkout resolves the root core and optional adapter without a
# vendor copy of the core or any preinstalled plugin.
run('php', 'scripts/bootstrap-composer.php')
assert not (ROOT/'vendor/webong/proxy').exists()
assert (ROOT/'vendor/webong/proxy-laravel').resolve() == (ROOT/'adapters/laravel').resolve()
run('php', '-r', "require 'vendor/autoload.php'; if (Composer\\InstalledVersions::getRootPackage()['name'] !== 'webong/proxy' || !class_exists(Webong\\Proxy\\Http\\HttpProfileNormalizer::class)) exit(1);")
print('PASS cold checkout: root core and optional adapter install', flush=True)
shutil.rmtree(ROOT/'vendor')
for name in ['composer.lock', 'composer.bootstrap.lock']:
    (ROOT/name).unlink()

# Historical manifests captured from committed workspace layout 5613ed63cd1b48e1f0878fbd0e53306a404641f7.
# Embed the snapshot so shallow CI clones do not need historical Git objects.
previous = {
    'composer.json': json.loads(r'''{
    "name": "webong/proxy-workspace",
    "description": "Development workspace and Composer publication catalog for Proxy components.",
    "type": "metapackage",
    "license": "MIT",
    "require": {
        "php": "^8.3"
    },
    "require-dev": {
        "orchestra/testbench": "^10.0 || ^11.0",
        "pestphp/pest": "^4.0",
        "pestphp/pest-plugin-laravel": "^4.0",
        "webong/composer-source-plugin": "^0.5.0",
        "webong/proxy": "^0.4.0",
        "webong/proxy-laravel": "^0.4.0"
    },
    "autoload-dev": {
        "psr-4": {
            "Webong\\Proxy\\Tests\\": "tests/"
        }
    },
    "scripts": {
        "test": [
            "@php scripts/check_php_api.php",
            "@php scripts/check_php_api.php --registry",
            "pest"
        ]
    },
    "extra": {
        "source-plugin": {
            "workspaces": [
                "php",
                "adapters/laravel"
            ],
            "catalog": {
                "url": "https://raw.githubusercontent.com/webong/proxy/main/composer-packages.json",
                "packages": [
                    "webong/proxy",
                    "webong/proxy-laravel"
                ]
            }
        }
    },
    "minimum-stability": "dev",
    "prefer-stable": true,
    "config": {
        "allow-plugins": {
            "pestphp/pest-plugin": true,
            "webong/composer-source-plugin": true
        },
        "sort-packages": true
    }
}
'''),
    'composer.bootstrap.json': json.loads(r'''{
    "name": "webong/proxy-workspace-bootstrap",
    "description": "Development bootstrap for the Proxy Composer workspace.",
    "type": "project",
    "license": "MIT",
    "require": {
        "webong/composer-source-plugin": "^0.5.0"
    },
    "config": {
        "allow-plugins": {
            "webong/composer-source-plugin": true,
            "pestphp/pest-plugin": true
        }
    }
}
'''),
    'php/composer.json': json.loads(r'''{
    "name": "webong/proxy",
    "description": "Framework-neutral HTTP profiles, TCP/UDP proxy transports and storage-neutral registry contracts.",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": "^8.3",
        "guzzlehttp/guzzle": "^7.10"
    },
    "autoload": {
        "psr-4": {
            "Webong\\Proxy\\": "src/"
        }
    },
    "extra": {
        "source-plugin": {
            "surfaces": {
                "php": {
                    "include": [
                        "src/**",
                        "README.md",
                        "LICENSE"
                    ]
                }
            }
        }
    },
    "archive": {
        "exclude": [
            "/vendor",
            "/tests",
            "/.phpunit.cache",
            "/composer.lock",
            "/composer-source.lock"
        ]
    },
    "version": "0.4.0",
    "conflict": {
        "webong/proxy-transport": "*",
        "webong/proxy-registry": "*"
    }
}
'''),
}
original = {name: (ROOT/name).read_bytes() if (ROOT/name).exists() else None
            for name in previous}
for name, value in previous.items():
    if name in ['composer.json', 'composer.bootstrap.json']:
        section = 'require-dev' if name == 'composer.json' else 'require'
        value[section]['webong/composer-source-plugin'] = '^0.4.0'
    write(ROOT/name, value)
run('composer', 'install', '--prefer-dist', '--no-interaction', manifest='composer.bootstrap.json')
run('composer', 'install', '--prefer-dist', '--no-interaction')
root_lock = (ROOT/'composer.lock').read_bytes()
for name, value in original.items():
    if value is None:
        (ROOT/name).unlink()
    else:
        (ROOT/name).write_bytes(value)
run('php', 'scripts/bootstrap-composer.php', '--plugin-only')
assert (ROOT/'composer.lock').read_bytes() == root_lock
# The normal entry point must not silently rewrite the incompatible root lock.
run('php', 'scripts/bootstrap-composer.php', success=False)
assert (ROOT/'composer.lock').read_bytes() == root_lock
run('composer', 'update', '--prefer-dist', '--no-interaction')
assert not (ROOT/'vendor/webong/proxy').exists(), 'Core must be supplied by the root package'
assert (ROOT/'vendor/webong/proxy-laravel').resolve() == (ROOT/'adapters/laravel').resolve()
run('php', '-r', "require 'vendor/autoload.php'; if (Composer\\InstalledVersions::getRootPackage()['name'] !== 'webong/proxy' || !class_exists(Webong\\Proxy\\Http\\HttpProfileNormalizer::class)) exit(1);")
seed_lock = (ROOT/'composer.bootstrap.lock').read_bytes()
root_lock = (ROOT/'composer.lock').read_bytes()
run('php', 'scripts/bootstrap-composer.php')
assert (ROOT/'composer.lock').read_bytes() == root_lock
print('PASS old seed/root migration, explicit root update, root-owned core, linked adapter and idempotence', flush=True)

# Observe actual Composer arguments; a no-op update at the latest version must
# not be mistaken for lock-preserving install behavior.
spy = LAB/'composer-spy'
invocations = LAB/'composer-invocations.jsonl'
spy.write_text('#!/usr/bin/env python3\nimport json, os, sys\n'
               + 'with open('+repr(str(invocations))+', "a") as stream: stream.write(json.dumps(sys.argv[1:])+"\\n")\n'
               + 'os.execv('+repr(shutil.which('composer'))+', ["composer", *sys.argv[1:]])\n')
spy.chmod(0o755)
# Cold replay of a valid bootstrap lock must preserve its bytes, offline.

shutil.rmtree(ROOT/'vendor')
run('php', 'scripts/bootstrap-composer.php', '--plugin-only', extra={
    'PROXY_COMPOSER_BINARY': str(spy), 'COMPOSER_DISABLE_NETWORK': '1'})
assert [json.loads(line)[0] for line in invocations.read_text().splitlines()] == ['install']
assert (ROOT/'composer.bootstrap.lock').read_bytes() == seed_lock

# Installed metadata survives damage: repair files without advancing seed versions.
for relative in ['vendor/webong/composer-source-plugin/src/ComposerSourcePlugin.php',
                 'vendor/webong/composer-source-plugin/src/bootstrap.php',
                 'vendor/autoload.php',
                 'vendor/composer/autoload_static.php',
                 'vendor/composer/autoload_real.php',
                 'vendor/composer/ClassLoader.php']:
    target = ROOT/relative
    target.unlink()
    run('php', 'scripts/bootstrap-composer.php', '--plugin-only')
    assert target.is_file(), relative
    assert (ROOT/'composer.bootstrap.lock').read_bytes() == seed_lock
    assert (ROOT/'composer.lock').read_bytes() == root_lock
print('PASS compatible seed lock cold replay and missing plugin/autoload repair', flush=True)

# Reject prerelease/dev metadata even though it shares the numeric minor prefix.
probe = LAB/'version-probe'
(probe/'scripts').mkdir(parents=True)
shutil.copyfile(SOURCE/'scripts/bootstrap-composer.php', probe/'scripts/bootstrap-composer.php')
for relative in ['vendor/autoload.php', 'vendor/composer/autoload_real.php',
                 'vendor/composer/autoload_static.php',
                 'vendor/composer/ClassLoader.php',
                 'vendor/webong/composer-source-plugin/src/ComposerSourcePlugin.php',
                 'vendor/webong/composer-source-plugin/src/bootstrap.php']:
    target = probe/relative
    target.parent.mkdir(parents=True, exist_ok=True)
    target.touch()
record = LAB/'version-probe-actions'
fake = LAB/'composer-probe'
fake.write_text('#!/usr/bin/env python3\nimport sys\n'
                + 'with open('+repr(str(record))+', "a") as stream: stream.write(sys.argv[1]+"\\n")\n')
fake.chmod(0o755)
for version in ['0.5.dev', '0.5.0-beta', 'v0.5.1-RC1', '0.5.0.1']:
    package = {'name': 'webong/composer-source-plugin', 'version': version}
    write(probe/'vendor/composer/installed.json', {'packages': [package]})
    write(probe/'composer.bootstrap.lock', {'packages': [package]})
    record.write_text('')
    subprocess.run(['php', 'scripts/bootstrap-composer.php', '--plugin-only'], cwd=probe,
                   env=dict(ENV, PROXY_COMPOSER_BINARY=str(fake)), check=True)
    assert record.read_text() == 'update\n', version
print('PASS stable seed compatibility rejects dev/prerelease lookalikes', flush=True)
