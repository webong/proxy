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


# Construct the real prior developer graph with both old seed and root locks.
original = {}
for name, section in [('composer.json', 'require-dev'), ('composer.bootstrap.json', 'require')]:
    path = ROOT/name
    original[name] = json.loads(path.read_text())
    old = json.loads(path.read_text())
    old[section]['webong/composer-source-plugin'] = '^0.4.0'
    write(path, old)
run('composer', 'install', '--prefer-dist', '--no-interaction', manifest='composer.bootstrap.json')
run('composer', 'install', '--prefer-dist', '--no-interaction')
root_lock = (ROOT/'composer.lock').read_bytes()
for name, value in original.items():
    write(ROOT/name, value)
run('php', 'scripts/bootstrap-composer.php', '--plugin-only')
assert (ROOT/'composer.lock').read_bytes() == root_lock
# The normal entry point must not silently rewrite the incompatible root lock.
run('php', 'scripts/bootstrap-composer.php', success=False)
assert (ROOT/'composer.lock').read_bytes() == root_lock
run('composer', 'update', '--prefer-dist', '--no-interaction')
assert (ROOT/'vendor/webong/proxy').resolve() == (ROOT/'php').resolve()
seed_lock = (ROOT/'composer.bootstrap.lock').read_bytes()
root_lock = (ROOT/'composer.lock').read_bytes()
run('php', 'scripts/bootstrap-composer.php')
assert (ROOT/'composer.lock').read_bytes() == root_lock
print('PASS old seed/root migration, explicit root update, linked workspaces and idempotence', flush=True)

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
