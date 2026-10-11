"""Exercise the shipped archive's install path, exclusions and release gates."""

import hashlib
import importlib.util
import os
import shutil
import tempfile
import unittest
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("build_release", ROOT / "scripts/build-release.py")
RELEASE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(RELEASE)


class ReleaseTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.base = Path(self.temporary.name)
        self.root = self.base / "source"
        shutil.copytree(ROOT, self.root, ignore=shutil.ignore_patterns(
            ".git", "dist", "__pycache__", ".DS_Store"))

    def build(self, version="1.0.0"):
        return RELEASE.build(self.root, self.base / "dist", version)

    def test_install_path_contents_and_checksum(self):
        archive, digest, _ = self.build()
        with zipfile.ZipFile(archive) as zipped:
            self.assertIsNone(zipped.testzip())
            self.assertTrue(all(name.startswith(RELEASE.BUNDLE + "/") for name in zipped.namelist()))
            for name in (RELEASE.BUNDLE + ".php", "Assets/library/js/duplicator.js",
                         "Resources/views/editor.html.twig", "Config/config.php", "LICENSE"):
                self.assertIn(RELEASE.BUNDLE + "/" + name, zipped.namelist())
            self.assertFalse(any("/.github/" in name or "/scripts/" in name for name in zipped.namelist()))
        self.assertEqual(hashlib.sha256(archive.read_bytes()).hexdigest(), digest)
        self.assertEqual(archive.with_suffix(".zip.sha256").read_text(), digest + "  " + archive.name + "\n")

    def test_build_is_reproducible_and_default_version_matches(self):
        archive, digest, _ = self.build()
        for path in self.root.rglob("*"):
            if path.is_file():
                os.utime(path, (1000000000, 1000000000))
        other, other_digest, _ = RELEASE.build(self.root, self.base / "other")
        self.assertEqual(digest, other_digest)
        self.assertEqual(archive.read_bytes(), other.read_bytes())

    def test_environment_keys_dumps_and_local_configs_are_excluded(self):
        for filename in ("docs/.env", "docs/private.key", "docs/private.pem", "docs/database.sql",
                         "docs/debug.log", "Config/local.php", "Tests/cache.pyc", "docs/.DS_Store"):
            (self.root / filename).write_text("DO NOT SHIP")
        archive, _, _ = self.build()
        with zipfile.ZipFile(archive) as zipped:
            self.assertFalse(any(b"DO NOT SHIP" in zipped.read(name) for name in zipped.namelist()))

    def test_version_mismatch_is_rejected_before_archive_creation(self):
        with self.assertRaisesRegex(ValueError, "must match"):
            self.build("1.0.1")
        self.assertFalse((self.base / "dist").exists())

    def test_invalid_version_is_rejected(self):
        for version in ("../1.0.0", "v1.0.0", "01.0.0", "1.0", "1.0.0-rc1"):
            with self.subTest(version=version), self.assertRaises(ValueError):
                self.build(version)

    def test_release_notes_are_required(self):
        (self.root / "docs/releases/1.0.0.md").unlink()
        with self.assertRaisesRegex(ValueError, "release notes"):
            self.build()

    def test_symlinked_file_or_directory_is_rejected(self):
        for filename in ("docs/linked.txt", "docs/linked-directory"):
            target = self.root / filename
            target.symlink_to(self.root / "README.md" if filename.endswith(".txt") else self.root / "Config")
            with self.assertRaisesRegex(ValueError, "Symlinks"):
                self.build()
            target.unlink()

    def test_missing_required_file_is_rejected(self):
        (self.root / "LICENSE").unlink()
        with self.assertRaisesRegex(ValueError, "Missing file"):
            self.build()


if __name__ == "__main__":
    unittest.main()
