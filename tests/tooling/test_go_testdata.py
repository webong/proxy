"""Exercise fixture synchronization in isolated repositories, never the real mirrors."""
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest

SOURCE = Path(__file__).resolve().parents[2]


class GoTestdataSyncTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        (self.root / 'scripts').mkdir()
        shutil.copyfile(SOURCE / 'scripts/sync_go_testdata.py', self.root / 'scripts/sync_go_testdata.py')
        shutil.copytree(SOURCE / 'spec', self.root / 'spec')
        shutil.copytree(SOURCE / 'tests/fixtures/tls', self.root / 'tests/fixtures/tls')
        self.run_sync()

    def run_sync(self, check=False, success=True):
        result = subprocess.run([sys.executable, str(self.root / 'scripts/sync_go_testdata.py')]
                                + (['--check'] if check else []), capture_output=True, text=True)
        self.assertEqual(result.returncode == 0, success, result.stdout + result.stderr)
        return result

    def test_drift_missing_and_extra_files_are_repaired(self):
        target = self.root / 'go/testdata/spec/socks5-cases.json'
        target.write_text('{}')
        (self.root / 'go/testdata/tls/proxy.pem').unlink()
        extra = self.root / 'go/testdata/spec/stale.json'
        extra.write_text('{}')
        self.run_sync(check=True, success=False)
        self.run_sync()
        self.run_sync(check=True)
        self.assertFalse(extra.exists())
        self.assertEqual(target.read_bytes(), (self.root / 'spec/socks5-cases.json').read_bytes())

    def test_file_link_is_rejected_then_materialized_without_writing_through(self):
        target = self.root / 'go/testdata/tls/ca.pem'
        outside = self.root / 'outside.pem'
        original = target.read_bytes()
        outside.write_bytes(original)
        target.unlink()
        target.symlink_to(outside)
        self.run_sync(check=True, success=False)
        self.run_sync()
        self.assertFalse(target.is_symlink())
        self.assertEqual(outside.read_bytes(), original)
        self.run_sync(check=True)

    def test_linked_fixture_directory_is_rejected_without_touching_canonical_data(self):
        target = self.root / 'go/testdata/tls'
        source = self.root / 'tests/fixtures/tls'
        original = (source / 'ca.pem').read_bytes()
        shutil.rmtree(target)
        target.symlink_to(source, target_is_directory=True)
        self.run_sync(check=True, success=False)
        self.run_sync(success=False)
        self.assertEqual((source / 'ca.pem').read_bytes(), original)

    def test_missing_canonical_group_fails_without_deleting_mirrors(self):
        target = self.root / 'go/testdata/spec/tcp/valid'
        before = sorted(path.name for path in target.iterdir())
        shutil.rmtree(self.root / 'spec/tcp/valid')
        self.run_sync(check=True, success=False)
        self.run_sync(success=False)
        self.assertEqual(sorted(path.name for path in target.iterdir()), before)

    def test_missing_canonical_baseline_fails_without_deleting_mirror(self):
        (self.root / 'spec/api/go-v0.3.json').unlink()
        self.run_sync(check=True, success=False)
        self.run_sync(success=False)
        self.assertTrue((self.root / 'go/testdata/spec/api/go-v0.3.json').is_file())

    def test_extra_directory_link_is_removed_without_removing_its_target(self):
        outside = self.root / 'outside'
        outside.mkdir()
        sentinel = outside / 'sentinel'
        sentinel.write_text('keep')
        link = self.root / 'go/testdata/spec/stale'
        link.symlink_to(outside, target_is_directory=True)
        self.run_sync(check=True, success=False)
        self.run_sync()
        self.assertFalse(link.is_symlink())
        self.assertEqual(sentinel.read_text(), 'keep')


if __name__ == '__main__':
    unittest.main()
