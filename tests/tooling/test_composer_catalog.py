"""Retained release records are a frozen compatibility boundary, not regenerated metadata."""
import copy
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts/prepare_composer_catalog.py"
spec = importlib.util.spec_from_file_location("catalog", SCRIPT)
catalog = importlib.util.module_from_spec(spec)
spec.loader.exec_module(catalog)


class ComposerCatalogTest(unittest.TestCase):
    def setUp(self):
        self.history = catalog.load(ROOT / "spec/composer/history.json")
        self.generated = {"packages": {name: {} for name in catalog.NAMES}}
        for name in catalog.NAMES:
            row = copy.deepcopy(self.history["packages"][name]["0.4.0"])
            row["version"] = "0.4.1"
            row["source"]["reference"] = "a" * 40
            row["dist"]["reference"] = "a" * 40
            self.generated["packages"][name]["0.4.1"] = row

    def test_all_public_records_survive_byte_equivalent_metadata(self):
        result = catalog.merge(self.generated, self.history)
        for name, versions in self.history["packages"].items():
            for version, row in versions.items():
                self.assertEqual(row, result["packages"][name][version])
        self.assertEqual("8362bbe98fcff9c549c062cd0088d38da9a32bbd",
                         result["packages"]["webong/proxy"]["v0.3.1"]["source"]["reference"])
        self.assertEqual("64108c8071d41ebbd35b0d88e008d09281a57a97",
                         result["packages"]["webong/proxy"]["0.4.0"]["source"]["reference"])
        self.assertEqual("863916ccdfb8899ee26e47b3bee62a2843f00106",
                         result["packages"]["webong/proxy-laravel"]["0.4.0"]["source"]["reference"])

    def test_previous_releases_survive_regeneration_without_their_tags(self):
        previous = catalog.merge(self.generated, self.history)
        newer = copy.deepcopy(self.generated)
        for versions in newer["packages"].values():
            row = versions.pop("0.4.1")
            row["version"] = "0.4.2"
            versions["0.4.2"] = row
        result = catalog.merge(newer, self.history, previous)
        for name in catalog.NAMES:
            self.assertIn("0.4.1", result["packages"][name])
            self.assertIn("0.4.2", result["packages"][name])

    def test_aliases_cannot_replace_frozen_versions(self):
        for field in ["source", "dist", "require", "autoload", "extra"]:
            with self.subTest(field=field):
                generated = copy.deepcopy(self.generated)
                row = copy.deepcopy(self.history["packages"]["webong/proxy"]["v0.3.1"])
                row["version"] = "0.3.1"
                row[field] = {"changed": True}
                generated["packages"]["webong/proxy"]["0.3.1"] = row
                with self.assertRaises(ValueError):
                    catalog.merge(generated, self.history)

    def test_prior_new_release_metadata_cannot_mutate(self):
        previous = catalog.merge(self.generated, self.history)
        self.generated["packages"]["webong/proxy"]["0.4.1"]["require"]["php"] = "^8.4"
        with self.assertRaisesRegex(ValueError, "Immutable release conflict"):
            catalog.merge(self.generated, self.history, previous)

    def test_incomplete_history_or_package_scope_is_rejected(self):
        history = copy.deepcopy(self.history)
        del history["packages"]["webong/proxy"]["v0.1.0"]
        with self.assertRaisesRegex(ValueError, "incomplete stable baseline"):
            catalog.merge(self.generated, history)
        for name in ["webong/proxy", "unrelated/package"]:
            malformed = copy.deepcopy(self.generated)
            malformed["packages"].pop("webong/proxy")
            if name != "webong/proxy":
                malformed["packages"][name] = {}
            with self.assertRaises(ValueError):
                catalog.merge(malformed, self.history)

    def test_retains_all_old_records_and_prior_catalog_releases(self):
        previous = catalog.merge(self.generated, self.history)
        next_release = copy.deepcopy(self.generated)
        for name, versions in next_release["packages"].items():
            record = versions.pop("0.4.1")
            record["version"] = "0.4.2"
            record["source"]["reference"] = "b" * 40
            record["dist"]["reference"] = "b" * 40
            versions["0.4.2"] = record
        merged = catalog.merge(next_release, self.history, previous)
        for name, versions in previous["packages"].items():
            for version, record in versions.items():
                self.assertEqual(record, merged["packages"][name][version])
            self.assertIn("0.4.2", merged["packages"][name])
        # Inputs and outputs must not share mutable release dictionaries.
        merged["packages"]["webong/proxy"]["0.4.1"]["description"] = "local change"
        self.assertNotEqual(merged, previous)
        self.assertEqual(self.generated["packages"]["webong/proxy"]["0.4.1"],
                         previous["packages"]["webong/proxy"]["0.4.1"])

    def test_duplicate_version_aliases_in_one_input_are_rejected(self):
        record = copy.deepcopy(self.generated["packages"]["webong/proxy"]["0.4.1"])
        record["version"] = "v0.4.1"
        self.generated["packages"]["webong/proxy"]["v0.4.1"] = record
        with self.assertRaisesRegex(ValueError, "duplicate version aliases"):
            catalog.merge(self.generated, self.history)

    def test_duplicate_json_keys_are_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "bad.json"
            path.write_text('{"packages": {}, "packages": {}}')
            with self.assertRaisesRegex(ValueError, "Duplicate JSON key"):
                catalog.load(path)

    def test_leading_zero_aliases_cannot_bypass_frozen_versions(self):
        for version in ["0.04.0", "00.4.0", "0.4.00", "v0.04.0"]:
            with self.subTest(version=version):
                generated = copy.deepcopy(self.generated)
                row = generated["packages"]["webong/proxy"].pop("0.4.1")
                row["version"] = version
                generated["packages"]["webong/proxy"][version] = row
                with self.assertRaisesRegex(ValueError, "unsupported version"):
                    catalog.merge(generated, self.history)

    def test_explicit_previous_cannot_discard_existing_output_releases(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            generated, output, previous = [root / p for p in ["generated.json", "output.json", "previous.json"]]
            generated.write_text(json.dumps(self.generated))
            previous.write_text(json.dumps(self.history))
            output.write_text(json.dumps(catalog.merge(self.generated, self.history)))
            original = output.read_bytes()
            command = [sys.executable, str(SCRIPT), "--generated", str(generated),
                       "--output", str(output), "--previous", str(previous)]
            result = subprocess.run(command, capture_output=True)
            self.assertNotEqual(0, result.returncode)
            self.assertIn(b"existing output is always retained", result.stderr)
            self.assertEqual(original, output.read_bytes())
            # The explicit previous index is supported when creating a new output.
            output.unlink()
            subprocess.run(command, check=True, capture_output=True)
            self.assertIn("0.4.1", json.loads(output.read_text())["packages"]["webong/proxy"])

    def test_failed_merge_never_overwrites_previous_index_or_history(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            generated, output = root / "generated.json", root / "index.json"
            generated.write_text(json.dumps(self.generated))
            command = [sys.executable, str(SCRIPT), "--generated", str(generated), "--output", str(output)]
            subprocess.run(command, check=True, capture_output=True)
            original = output.read_bytes()
            subprocess.run(command, check=True, capture_output=True)
            self.assertEqual(original, output.read_bytes())
            self.generated["packages"]["webong/proxy"]["0.4.1"]["description"] = "mutated"
            generated.write_text(json.dumps(self.generated))
            self.assertNotEqual(0, subprocess.run(command, capture_output=True).returncode)
            self.assertEqual(original, output.read_bytes())
            self.assertNotEqual(0, subprocess.run([*command[:-1], str(generated)], capture_output=True).returncode)
            history = ROOT / "spec/composer/history.json"
            frozen = history.read_bytes()
            self.assertNotEqual(0, subprocess.run([*command[:-1], str(history)], capture_output=True).returncode)
            self.assertEqual(frozen, history.read_bytes())


if __name__ == "__main__":
    unittest.main()
