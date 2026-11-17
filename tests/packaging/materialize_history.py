#!/usr/bin/env python3
"""Build test-only historical ZIPs from immutable trees in the Proxy monorepo."""
import argparse
import copy
import hashlib
import json
from pathlib import Path
import subprocess

SOURCE = Path(__file__).resolve().parents[2]
RELEASE = "5793b331cee9a2782cf5d7fdc0bcfcce2d035557"
# The removed distribution commits used these exact monorepo subtrees. Pin tree
# IDs so changing the release/path cannot silently turn history into today's code.
COMPONENTS = {
    "64108c8071d41ebbd35b0d88e008d09281a57a97": ("php", "115231e973472465e6c800d5ae6d9b1382014e35"),
    "863916ccdfb8899ee26e47b3bee62a2843f00106": ("adapters/laravel", "9d601ac176ffaf3ce490e2d93f5ab59193f1c06f"),
}


def materialize(output):
    original = json.loads((SOURCE / "spec/composer/history.json").read_text())
    fixtures = copy.deepcopy(original)
    output.mkdir(parents=True, exist_ok=True)
    provenance = {}
    for name, versions in fixtures["packages"].items():
        for version, record in versions.items():
            reference = record["source"]["reference"]
            if reference in COMPONENTS:
                path, expected_tree = COMPONENTS[reference]
                selector = RELEASE + ":" + path
            else:
                selector, expected_tree = reference + "^{tree}", None
            tree = subprocess.check_output(["git", "rev-parse", "--verify", selector], cwd=SOURCE, text=True).strip()
            if expected_tree and tree != expected_tree:
                raise RuntimeError(f"Historical tree mismatch for {name} {version}")
            archive = output / (name.replace("/", "-") + "-" + version + ".zip")
            subprocess.run(["git", "archive", "--format=zip", "--prefix=historical/",
                            "--output=" + str(archive), tree], cwd=SOURCE, check=True)
            record["dist"] = {"type": "zip", "url": archive.as_uri(), "reference": reference,
                              "shasum": hashlib.sha1(archive.read_bytes()).hexdigest()}
            provenance[name + "@" + version] = {"original_reference": reference, "tree": tree, "selector": selector}
    (output / "packages.json").write_text(json.dumps(fixtures, indent=2) + "\n")
    (output / "provenance.json").write_text(json.dumps(provenance, indent=2) + "\n")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output-dir", type=Path, required=True)
    materialize(parser.parse_args().output_dir.resolve())
