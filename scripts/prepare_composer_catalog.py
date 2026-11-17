#!/usr/bin/env python3
"""Merge generated Proxy packages with immutable public/previous release records."""
import argparse
import copy
import json
from pathlib import Path
import re
import tempfile

ROOT = Path(__file__).resolve().parents[1]
NAMES = {"webong/proxy", "webong/proxy-laravel"}
STABLE = re.compile(r"v?(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\Z")
REQUIRED_HISTORY = {
    "webong/proxy": {"0.1.0", "0.1.1", "0.2.0", "0.2.1", "0.3.0", "0.3.1", "0.4.0"},
    "webong/proxy-laravel": {"0.4.0"},
}


def load(path):
    def unique_pairs(pairs):
        result = {}
        for key, value in pairs:
            if key in result:
                raise ValueError(f"Duplicate JSON key: {key}")
            result[key] = value
        return result
    return json.loads(Path(path).read_text(), object_pairs_hook=unique_pairs)


def records(document, label):
    if not isinstance(document, dict) or set(document) != {"packages"}:
        raise ValueError(f"{label}: expected a standalone packages index")
    packages = document["packages"]
    if not isinstance(packages, dict) or set(packages) != NAMES:
        raise ValueError(f"{label}: expected exactly the two Proxy package names")
    for name, versions in packages.items():
        if not isinstance(versions, dict) or not versions:
            raise ValueError(f"{label}: missing versions for {name}")
        seen = set()
        for version, package in versions.items():
            if not STABLE.fullmatch(version) and version != "dev-main":
                raise ValueError(f"{label}: unsupported version {version}")
            normalized = version.removeprefix("v")
            if normalized in seen:
                raise ValueError(f"{label}: duplicate version aliases for {name} {normalized}")
            seen.add(normalized)
            if not isinstance(package, dict) or package.get("name") != name or package.get("version") != version:
                raise ValueError(f"{label}: inconsistent identity for {name} {version}")
            source = package.get("source", {})
            if (source.get("type") != "git" or not isinstance(source.get("url"), str)
                    or not re.fullmatch(r"[0-9a-f]{40}", source.get("reference", ""))):
                raise ValueError(f"{label}: missing immutable Git source for {name} {version}")
            if "dist" in package and package["dist"].get("reference") != source["reference"]:
                raise ValueError(f"{label}: source/dist references disagree for {name} {version}")
            yield name, normalized, version, package


def merge(generated, history, previous=None):
    historical = list(records(history, "history"))
    for name, required in REQUIRED_HISTORY.items():
        available = {version for package, version, _, _ in historical if package == name}
        if not required <= available or "dev-main" in available:
            raise ValueError(f"history: incomplete stable baseline for {name}")
    retained = {}
    # Existing stable rows are frozen in their entirety, not just their Git SHA.
    for label, document in [("history", history), ("previous", previous), ("generated", generated)]:
        if document is None:
            continue
        for name, normalized, version, package in records(document, label):
            if version == "dev-main" and label != "generated":
                continue
            key = (name, normalized)
            if key in retained and retained[key][1] != package:
                raise ValueError(f"Immutable release conflict: {name} {normalized} ({label})")
            retained[key] = (version, package)
    result = {name: {} for name in sorted(NAMES)}
    def ordering(item):
        name, version = item[0]
        return name, version == "dev-main", tuple(map(int, STABLE.fullmatch(version).groups())) if version != "dev-main" else ()
    for (name, _), (version, package) in sorted(retained.items(), key=ordering, reverse=True):
        result[name][version] = copy.deepcopy(package)
    return {"packages": result}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--generated", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--history", type=Path, default=ROOT / "spec/composer/history.json")
    parser.add_argument("--previous", type=Path, help="Prior published index when the output is new")
    args = parser.parse_args()
    try:
        if args.generated.resolve() == args.output.resolve():
            raise ValueError("Generate into a staging directory; input must not overwrite the retained output")
        if (args.previous and args.output.exists()
                and args.previous.resolve() != args.output.resolve()):
            raise ValueError("An existing output is always retained; --previous is only for a new output")
        previous = args.previous or (args.output if args.output.exists() else None)
        if args.output.resolve() == args.history.resolve():
            raise ValueError("The historical baseline cannot be overwritten")
        document = merge(load(args.generated), load(args.history), load(previous) if previous else None)
        payload = json.dumps(document, indent=2, ensure_ascii=False) + "\n"
        args.output.parent.mkdir(parents=True, exist_ok=True)
        temporary = None
        try:
            with tempfile.NamedTemporaryFile("w", dir=args.output.parent, delete=False) as stream:
                temporary = Path(stream.name)
                stream.write(payload)
            temporary.replace(args.output)
        finally:
            if temporary and temporary.exists():
                temporary.unlink()
    except (ValueError, OSError, TypeError, AttributeError) as error:
        parser.exit(1, f"catalog: {error}\n")


if __name__ == "__main__":
    main()
