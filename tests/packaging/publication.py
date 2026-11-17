#!/usr/bin/env python3
"""Exercise the released publisher against Proxy's real package roots; never push."""
import json
import hashlib
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

SOURCE = Path(__file__).resolve().parents[2]
LAB = Path(tempfile.mkdtemp(prefix="proxy-publication-"))
print(f"Publication fixtures: {LAB}", flush=True)
ENV = dict(os.environ, COMPOSER_HOME=str(LAB / "home"),
           COMPOSER_CACHE_DIR=str(LAB / "cache"), COMPOSER_AUTH="{}",
           COMPOSER_ALLOW_SUPERUSER="1", GIT_CONFIG_GLOBAL="/dev/null",
           GIT_CONFIG_SYSTEM="/dev/null", GIT_AUTHOR_NAME="Fixture",
           GIT_AUTHOR_EMAIL="fixture@example.test", GIT_COMMITTER_NAME="Fixture",
           GIT_COMMITTER_EMAIL="fixture@example.test")
ENV.pop("COMPOSER", None)


def run(*args, cwd=SOURCE, ok=True, env_extra=None):
    result = subprocess.run(args, cwd=cwd, env=dict(ENV, **(env_extra or {})), text=True,
                            stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    if (result.returncode == 0) != ok:
        raise RuntimeError(f"{args}:\n{result.stdout}")
    return result.stdout


def write(path, data):
    path.write_text(json.dumps(data, indent=2) + "\n")


mono = LAB / "mono"
shutil.copytree(SOURCE, mono, ignore=shutil.ignore_patterns(
    ".git", "vendor", "composer.lock", "composer.bootstrap.lock",
    "composer-source.lock", ".phpunit.cache", "__pycache__"))
# Only this disposable fixture advances versions. Published versions stay immutable.
for relative in ["php/composer.json", "adapters/laravel/composer.json"]:
    path = mono / relative
    manifest = json.loads(path.read_text())
    manifest["version"] = "0.4.1"
    write(path, manifest)
run("git", "init", "--quiet", cwd=mono)
run("git", "add", "--all", cwd=mono)
run("git", "commit", "--quiet", "-m", "Disposable publication fixture", cwd=mono)
index_dir = LAB / "index"
index_dir.mkdir()


def publish(action, *extra, ok=True):
    return run("composer", "source:publish", action, f"--repo={mono}",
               f"--source-url={mono}", *extra, ok=ok)


listing = publish("list")
assert "webong/proxy-laravel" in listing and "webong/proxy" in listing
assert "go/" not in listing
# Production releases pre-create signed annotated tags. CI exercises the same
# annotated-tag shape without relying on a developer's private signing key.
publish("split")
for name in ["webong/proxy", "webong/proxy-laravel"]:
    run("git", "tag", "-a", f"{name}/v0.4.1", f"dist/{name}",
        "-m", "Fixture annotated release", cwd=mono)
publish("release", "--release-version=0.4.1", "--no-metadata-branch",
        f"--out-dir={index_dir}")
generated_index = index_dir / "composer-packages.json"
original = generated_index.read_bytes()
publish("release", "--release-version=0.4.1", "--no-metadata-branch",
        f"--out-dir={index_dir}")
assert generated_index.read_bytes() == original, "Non-deterministic publication metadata"
assert "refs/heads/dist/composer" not in run("git", "show-ref", cwd=mono)
publish("verify", "--composer")
packages = json.loads(original)["packages"]
for name, relative in [("webong/proxy", "php"),
                       ("webong/proxy-laravel", "adapters/laravel")]:
    record = packages[name]["0.4.1"]
    reference = record["source"]["reference"]
    assert reference == run("git", "rev-parse", f"{name}/v0.4.1^{{commit}}", cwd=mono).strip()
    assert run("git", "show", f"{reference}:composer.json", cwd=mono) == (mono / relative / "composer.json").read_text()
    assert record["source"]["url"] == str(mono)
    assert "require-dev" not in record
assert packages["webong/proxy"]["0.4.1"]["require"] == {
    "php": "^8.3", "guzzlehttp/guzzle": "^7.10"}
assert packages["webong/proxy-laravel"]["0.4.1"]["require"]["webong/proxy"] == "^0.4.0"
# Inspect actual GitHub metadata generation separately; synthetic commits are never pushed.
public_dir = LAB / "public-index"
run("composer", "source:publish", "metadata", f"--repo={mono}",
    "--source-url=https://github.com/webong/proxy", "--no-metadata-branch",
    f"--out-dir={public_dir}")
public = json.loads((public_dir / "composer-packages.json").read_text())["packages"]
for name in packages:
    record = public[name]["0.4.1"]
    assert record["source"]["url"].removesuffix(".git") == "https://github.com/webong/proxy"
    assert record["source"]["reference"] in record["dist"]["url"]
    assert "api.github.com/repos/webong/proxy/zipball/" in record["dist"]["url"]

# Merge real retained metadata before a canonical repository is registered.
index = LAB / "composer-packages.json"
run("python3", str(SOURCE / "scripts/prepare_composer_catalog.py"),
    "--generated", str(generated_index), "--output", str(index))
merged = json.loads(index.read_text())["packages"]
history = json.loads((SOURCE / "spec/composer/history.json").read_text())["packages"]
for name, versions in history.items():
    for version, record in versions.items():
        assert merged[name][version] == record
packages = merged

# Use the actual author contract, changing only its private fixture endpoint.
author = json.loads((SOURCE / "composer.json").read_text())
assert author["name"] == "webong/proxy-workspace" and author["type"] == "metapackage"
actual_catalog = author["extra"]["source-plugin"]["catalog"]
assert actual_catalog["url"] == "https://raw.githubusercontent.com/webong/proxy/main/composer-packages.json"
assert set(actual_catalog["packages"]) == set(packages)
assert set(actual_catalog["packages"]) == {
    json.loads((SOURCE / root / "composer.json").read_text())["name"]
    for root in author["extra"]["source-plugin"]["workspaces"]}
catalog_record = {key: value for key, value in author.items()
                  if key not in {"require-dev", "autoload-dev", "scripts", "config"}}
catalog_record["version"] = "0.0.1"
catalog_record["extra"]["source-plugin"]["catalog"]["url"] = index.as_uri()
catalog = LAB / "catalog.json"
write(catalog, {"packages": {author["name"]: {
    "0.0.1": catalog_record,
    # Old root tags can be visible under the catalog identity on a VCS registry.
    "0.3.1": {"name": author["name"], "version": "0.3.1", "type": "metapackage"},
}}})
consumer = LAB / "consumer"
consumer.mkdir()
write(consumer / "composer.json", {
    "name": "fixture/publication-consumer",
    "require": {"webong/composer-source-plugin": "^0.5.0"},
    "config": {"allow-plugins": {"webong/composer-source-plugin": True}},
    "repositories": [{"type": "composer", "url": catalog.as_uri(),
                      "only": ["webong/proxy-workspace"]}]})
run("composer", "install", "--prefer-dist", "--no-interaction", cwd=consumer)
run("composer", "source:catalog", "webong/proxy-workspace:^0.0",
    "--allow-local", cwd=consumer)
registered = (consumer / "composer.json").read_bytes()
repositories = json.loads(registered)["repositories"]
entries = repositories.values() if isinstance(repositories, dict) else repositories
scoped = next(entry for entry in entries if entry.get("url") == index.as_uri())
assert scoped["canonical"] is True and set(scoped["only"]) == set(packages)
assert "webong/proxy-workspace" not in json.loads(registered)["require"]
run("composer", "source:catalog", "webong/proxy-workspace:^0.0",
    "--allow-local", cwd=consumer)
assert registered == (consumer / "composer.json").read_bytes()
run("composer", "require", "webong/proxy:0.4.1", "--prefer-dist",
    "--no-interaction", cwd=consumer)
print(run("php", str(SOURCE / "tests/packaging/neutral.php"), cwd=consumer), flush=True)
run("composer", "require", "webong/proxy-laravel:0.4.1", "--prefer-dist",
    "--no-interaction", cwd=consumer)
# The plugin is bootstrap tooling, not a runtime dependency of either library.
run("composer", "remove", "webong/composer-source-plugin", "--no-interaction", cwd=consumer)
print(run("php", str(SOURCE / "tests/packaging/production.php"), cwd=consumer), flush=True)
clone = LAB / "locked"
clone.mkdir()
for filename in ["composer.json", "composer.lock"]:
    shutil.copyfile(consumer / filename, clone / filename)
lock_bytes = (clone / "composer.lock").read_bytes()
run("composer", "install", "--no-dev", "--no-plugins", "--no-interaction", cwd=clone,
    env_extra={"COMPOSER_HOME": str(LAB / "clone-home"), "COMPOSER_CACHE_DIR": str(LAB / "clone-cache")})
assert (clone / "composer.lock").read_bytes() == lock_bytes
print(run("php", str(SOURCE / "tests/packaging/production.php"), cwd=clone), flush=True)
# GitHub serves package-root split ZIPs. Exercise that extraction shape locally
# without pretending these private fixture commits are publicly reachable.
for name, versions in packages.items():
    reference = versions["0.4.1"]["source"]["reference"]
    archive = LAB / (name.replace("/", "-") + ".zip")
    run("git", "archive", "--format=zip", "--prefix=fixture-package/",
        f"--output={archive}", reference, cwd=mono)
    versions["0.4.1"]["dist"] = {"type": "zip", "url": archive.as_uri(),
        "reference": reference, "shasum": hashlib.sha1(archive.read_bytes()).hexdigest()}
write(index, {"packages": packages})
for kind, name in [("core", "webong/proxy"), ("adapter", "webong/proxy-laravel")]:
    zipped = LAB / (kind + "-zip")
    zipped.mkdir()
    write(zipped / "composer.json", {
        "name": "fixture/zip-consumer", "require": {name: "0.4.1"},
        "repositories": [{"type": "composer", "url": index.as_uri(),
                          "only": list(packages)}],
        "config": {"allow-plugins": False}})
    run("composer", "install", "--prefer-dist", "--no-plugins", "--no-interaction", cwd=zipped)
    lock = json.loads((zipped / "composer.lock").read_text())
    for package in lock["packages"]:
        if package["name"] in packages:
            assert package["dist"]["reference"] == packages[package["name"]]["0.4.1"]["source"]["reference"]
            assert not (zipped / "vendor" / package["name"] / ".git").exists()
    fixture = "neutral.php" if kind == "core" else "production.php"
    print(run("php", str(SOURCE / "tests/packaging" / fixture), cwd=zipped), flush=True)
# Relocate historical downloads only inside this disposable test index. Frozen
# production metadata remains untouched; this is not a public installation test.
run("python3", str(SOURCE / "tests/packaging/materialize_history.py"),
    "--output-dir", str(LAB / "historical"))
historical_fixtures = LAB / "historical/packages.json"
local_history = json.loads(historical_fixtures.read_text())["packages"]
for name, versions in local_history.items():
    for version, record in versions.items():
        packages[name][version] = record
write(index, {"packages": packages})
print(run("python3", str(SOURCE / "tests/packaging/history.py"),
          "--index", str(index), "--historical-fixtures", str(historical_fixtures)), flush=True)
major = os.environ.get("PROXY_PACKAGING_LARAVEL", "12")
run("composer", "require", "--dev", "orchestra/testbench:" + ("^10.0" if major == "12" else "^11.0"),
    "--with-all-dependencies", "--no-interaction", cwd=consumer)
print(run("php", "vendor/bin/phpunit", "--no-configuration", "--bootstrap",
          "vendor/autoload.php", str(SOURCE / "tests/packaging/LaravelConsumerTest.php"),
          cwd=consumer), flush=True)
# Reusing a released version after changing source must fail without moving its tag.
old_tag = run("git", "rev-parse", "webong/proxy/v0.4.1", cwd=mono)
with (mono / "php/README.md").open("a") as stream:
    stream.write("\nDisposable changed content.\n")
run("git", "add", "php/README.md", cwd=mono)
run("git", "commit", "--quiet", "-m", "Changed fixture source", cwd=mono)
publish("release", "--release-version=0.4.1", "--no-metadata-branch",
        f"--out-dir={index_dir}", ok=False)
assert run("git", "rev-parse", "webong/proxy/v0.4.1", cwd=mono) == old_tag
print("PASS monorepo publication: deterministic refs, scoped catalog, neutral/adapter source and ZIP installs, plugin-free locked replay, Laravel runtime and immutable versions", flush=True)
