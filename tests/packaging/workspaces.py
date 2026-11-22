#!/usr/bin/env python3
"""A separate application consumes Proxy and its optional adapter from one checkout."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

SOURCE = Path(__file__).resolve().parents[2]
LAB = Path(tempfile.mkdtemp(prefix="proxy-workspaces-"))
print(f"Workspace consumers: {LAB}", flush=True)


def copy_source(destination):
    shutil.copytree(SOURCE, destination, ignore=shutil.ignore_patterns(
        ".git", "vendor", "*.lock", ".phpunit.cache", "__pycache__", ".publication"))


def write(root, manifest):
    (root / "composer.json").write_text(json.dumps(manifest, indent=2) + "\n")


def run(root, *arguments):
    env = dict(os.environ, COMPOSER_HOME=str(root / "home"),
               COMPOSER_CACHE_DIR=str(root / "cache"), COMPOSER_AUTH="{}")
    env.pop("COMPOSER", None)
    result = subprocess.run(arguments, cwd=root, env=env, text=True,
                            stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    if result.returncode:
        raise RuntimeError(f"{arguments}:\n{result.stdout}")
    return result.stdout


def scenario(kind):
    root = LAB / kind
    root.mkdir()
    copy_source(root / "ext/proxy")
    manifest = {"name": "fixture/" + kind, "require-dev": {
        "webong/composer-source-plugin": "^0.5.1"},
        "config": {"allow-plugins": {"webong/composer-source-plugin": True}}}
    write(root, manifest)
    # The plugin must be installed before the solve that uses workspaces.
    run(root, "composer", "install", "--prefer-dist", "--no-interaction")
    include = ["ext/proxy"]
    if kind == "laravel":
        include.append("ext/proxy/pkg/laravel")
    manifest["extra"] = {"source-plugin": {"workspaces": {
        "include": include, "symlink": kind == "core"}}}
    manifest["require"] = {"webong/proxy-laravel" if kind == "laravel" else "webong/proxy": "^0.4"}
    write(root, manifest)
    run(root, "composer", "update", "--prefer-dist", "--no-interaction")
    lock = json.loads((root / "composer.lock").read_text())
    packages = {p["name"]: p for p in lock["packages"]}
    assert packages["webong/proxy"]["dist"]["url"] == "ext/proxy"
    assert packages["webong/proxy"]["dist"]["type"] == "path"
    assert (root / "ext/proxy/src/php/Http/HttpProfile.php").is_file()
    assert (root / "ext/proxy/src/go/go.mod").is_file()
    assert (root / "ext/proxy/pkg/laravel/composer.json").is_file()
    assert not (root / "ext/proxy/src/php/composer.json").exists()
    for old in ["php", "go", "adapters"]:
        assert not (root / "ext/proxy" / old).exists()
    assert "repositories" not in json.loads((root / "composer.json").read_text())
    assert "webong/proxy-workspace" not in packages
    assert "webong/proxy-workspace" not in manifest["require"]
    if kind == "laravel":
        assert packages["webong/proxy-laravel"]["dist"]["url"] == "ext/proxy/pkg/laravel"
        assert not (root / "vendor/webong/proxy").is_symlink()
        assert not (root / "vendor/webong/proxy-laravel").is_symlink()
    else:
        assert not any(p.startswith(("illuminate/", "laravel/", "spatie/")) for p in packages)
        assert (root / "vendor/webong/proxy").is_symlink()
        print(run(root, "php", str(SOURCE / "tests/packaging/neutral.php")), flush=True)
    # The checkout is the sole source and must be provisioned before a cold install.
    clone = LAB / (kind + "-locked")
    clone.mkdir()
    copy_source(clone / "ext/proxy")
    for filename in ["composer.json", "composer.lock"]:
        shutil.copyfile(root / filename, clone / filename)
    before = (clone / "composer.lock").read_bytes()
    run(clone, "composer", "install", "--no-dev", "--no-plugins", "--no-interaction")
    assert (clone / "composer.lock").read_bytes() == before
    smoke = "production.php" if kind == "laravel" else "neutral.php"
    print(run(clone, "php", str(SOURCE / "tests/packaging" / smoke)), flush=True)
    if kind == "laravel":
        major = os.environ.get("PROXY_PACKAGING_LARAVEL", "12")
        run(root, "composer", "require", "--dev", "orchestra/testbench:" + ("^10.0" if major == "12" else "^11.0"),
            "--with-all-dependencies", "--no-interaction")
        print(run(root, "php", "vendor/bin/phpunit", "--no-configuration", "--bootstrap", "vendor/autoload.php",
                  str(SOURCE / "tests/packaging/LaravelConsumerTest.php")), flush=True)
    print(f"PASS {kind}: one source checkout, independent requirements and cold no-plugin lock replay", flush=True)


for kind in ["core", "laravel"]:
    scenario(kind)
