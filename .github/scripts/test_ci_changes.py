#!/usr/bin/env python3
"""Tests for ci-changes.py. Run: python3 .github/scripts/test_ci_changes.py"""
import importlib.util
import pathlib
import unittest

spec = importlib.util.spec_from_file_location('ci_changes', pathlib.Path(__file__).with_name('ci-changes.py'))
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)
ALL = {k: True for k in m.KEYS}


class Classify(unittest.TestCase):
    def test_frontend_only(self):
        r = m.classify(['frontend/src/a.tsx', 'frontend/e2e/x.spec.ts'])
        self.assertEqual(r, {'backend_full': False, 'frontend': True, 'image': False, 'e2e': True})

    def test_backend_only_skips_frontend_and_e2e(self):
        r = m.classify(['backend/app/Models/A.php'])
        self.assertEqual(r, {'backend_full': True, 'frontend': False, 'image': True, 'e2e': False})

    def test_backend_migration_starts_e2e(self):
        self.assertTrue(m.classify(['backend/database/migrations/x.php'])['e2e'])

    def test_docs_only(self):
        r = m.classify(['docs/OWNER_OBSERVED_DEFECTS.md', 'README.md'])
        self.assertEqual(r, {k: False for k in m.KEYS})

    def test_unknown_path_runs_backend(self):
        self.assertTrue(m.classify(['scripts/foo.sh'])['backend_full'])

    def test_ci_change_runs_everything(self):
        self.assertEqual(m.classify(['docs/a.md', '.github/workflows/ci.yml']), ALL)

    def test_full_gate_and_other_events_run_everything(self):
        self.assertEqual(m.classify(['docs/a.md'], full_gate=True), ALL)
        self.assertEqual(m.classify(['docs/a.md'], event='merge_group'), ALL)
        self.assertEqual(m.classify(['docs/a.md'], event='workflow_dispatch'), ALL)

    def test_empty_list_runs_everything(self):
        self.assertEqual(m.classify([]), ALL)

    def test_mixed(self):
        r = m.classify(['frontend/src/a.ts', 'backend/app/B.php'])
        self.assertEqual(r, ALL)  # frontend + backend + e2e + image all needed


if __name__ == '__main__':
    unittest.main()
