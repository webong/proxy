#!/usr/bin/env python3
"""Real old-version solves, partial updates and data upgrades through the catalog."""
import argparse
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

SOURCE = Path(__file__).resolve().parents[2]
LAB = Path(tempfile.mkdtemp(prefix="proxy-history-"))
ENV = dict(os.environ, COMPOSER_AUTH="{}", COMPOSER_ALLOW_SUPERUSER="1")
ENV.pop("COMPOSER", None)


def run(project, *args, extra=None):
    env = dict(ENV, COMPOSER_HOME=str(project / "home"), COMPOSER_CACHE_DIR=str(project / "cache"))
    env.update(extra or {})
    result = subprocess.run(args, cwd=project, env=env, text=True,
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if result.returncode:
        raise RuntimeError(f"{args}:\n{result.stdout}{result.stderr}")
    return result.stdout


def write(project, manifest):
    (project / "composer.json").write_text(json.dumps(manifest, indent=2) + "\n")


def project(name, index, require, dev=None):
    root = LAB / name
    root.mkdir()
    manifest = {"name": "fixture/history-" + name, "require": require,
                "config": {"allow-plugins": False},
                "repositories": [{"type": "composer", "url": index.as_uri(), "canonical": True,
                                  "only": ["webong/proxy", "webong/proxy-laravel"]}]}
    if dev:
        manifest["require-dev"] = dev
    write(root, manifest)
    return root, manifest


def locked(project, name):
    packages = json.loads((project / "composer.lock").read_text())["packages"]
    return next(p for p in packages if p["name"] == name)


def install(project):
    run(project, "composer", "install", "--prefer-dist", "--no-plugins", "--no-interaction")


def check_inventory(index, history):
    root, _ = project("inventory", index, {})
    for name, versions in history.items():
        result = json.loads(run(root, "composer", "show", name, "--all", "--format=json"))
        available = {v.removeprefix("v") for v in result["versions"]}
        assert {v.removeprefix("v") for v in versions} <= available
    print("PASS catalog: all eight historical stable records remain discoverable", flush=True)


def check_partial_updates(index):
    core, _ = project("core", index, {"webong/proxy": "0.4.0"})
    install(core)
    print(run(core, "php", str(SOURCE / "tests/packaging/neutral.php")), flush=True)
    root, manifest = project("adapter", index, {
        "webong/proxy": "0.4.0", "webong/proxy-laravel": "0.4.0"})
    install(root)
    original = {name: locked(root, name) for name in manifest["require"]}
    print(run(root, "php", str(SOURCE / "tests/packaging/production.php")), flush=True)
    manifest["require"] = {name: "^0.4" for name in manifest["require"]}
    write(root, manifest)
    run(root, "composer", "update", "guzzlehttp/guzzle", "--with-dependencies", "--no-interaction")
    for name, record in original.items():
        assert locked(root, name) == record, "Unrelated partial update changed a locked Proxy release"
    run(root, "composer", "update", "webong/proxy", "--with-all-dependencies", "--prefer-dist", "--no-interaction")
    assert locked(root, "webong/proxy")["version"].removeprefix("v") == "0.4.1"
    assert locked(root, "webong/proxy-laravel") == original["webong/proxy-laravel"]
    run(root, "composer", "update", "webong/proxy-laravel", "--with-all-dependencies", "--prefer-dist", "--no-interaction")
    assert locked(root, "webong/proxy-laravel")["version"].removeprefix("v") == "0.4.1"
    print(run(root, "php", str(SOURCE / "tests/packaging/production.php")), flush=True)
    print("PASS history: exact 0.4.0 installs, unrelated partial update and explicit component updates", flush=True)


def check_data_upgrade(index):
    major = os.environ.get("PROXY_PACKAGING_LARAVEL", "12")
    root, manifest = project("upgrade", index, {"webong/proxy": "0.3.1"},
                             {"orchestra/testbench": "^10.0" if major == "12" else "^11.0"})
    (root / "upgrade.sqlite").touch()
    install(root)
    old = locked(root, "webong/proxy")
    clone = LAB / "legacy-locked"
    clone.mkdir()
    for filename in ["composer.json", "composer.lock"]:
        shutil.copyfile(root / filename, clone / filename)
    before = (clone / "composer.lock").read_bytes()
    run(clone, "composer", "install", "--prefer-dist", "--no-dev", "--no-plugins", "--no-interaction")
    assert (clone / "composer.lock").read_bytes() == before
    assert locked(clone, "webong/proxy") == old
    run(clone, "php", "-r", 'require "vendor/autoload.php"; $p=(new Webong\\Proxy\\Http\\HttpProfileNormalizer())->normalize([]); if ($p->toArray()["version"] !== 1) throw new RuntimeException("Legacy profile failed");')
    args = ["php", "vendor/bin/phpunit", "--no-configuration", "--bootstrap", "vendor/autoload.php",
            str(SOURCE / "tests/packaging/UpgradeConsumerTest.php")]
    print(run(root, *args, extra={"PROXY_UPGRADE_PHASE": "seed"}), flush=True)
    manifest["require"] = {"webong/proxy-laravel": "0.4.1"}
    write(root, manifest)
    run(root, "composer", "update", "--with-all-dependencies", "--prefer-dist", "--no-plugins", "--no-interaction")
    assert locked(root, "webong/proxy")["version"].removeprefix("v") == "0.4.1"
    print(run(root, *args, extra={"PROXY_UPGRADE_PHASE": "verify"}), flush=True)
    print("PASS history: cold 0.3.1 locked replay and catalog data upgrade preserve records/secrets/migrations", flush=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--index", type=Path, required=True)
    args = parser.parse_args()
    index = args.index.resolve()
    print(f"Historical install fixtures: {LAB}", flush=True)
    history = json.loads((SOURCE / "spec/composer/history.json").read_text())["packages"]
    merged = json.loads(index.read_text())["packages"]
    for name, versions in history.items():
        for version, record in versions.items():
            assert merged[name][version] == record, "Historical metadata was changed"
    check_inventory(index, history)
    check_partial_updates(index)
    check_data_upgrade(index)


if __name__ == "__main__":
    main()
