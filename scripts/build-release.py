#!/usr/bin/env python3
"""Build a reproducible ZIP with the Mautic bundle as its only root directory."""

import argparse
import hashlib
import re
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BUNDLE = "WebAnyOneFormFieldDuplicatorBundle"
DIRECTORIES = {
    "Assets": {".js"}, "Config": {".php"}, "Controller": {".php"},
    "DependencyInjection": {".php"}, "EventListener": {".php"},
    "Exception": {".php"}, "Service": {".php"}, "Integration": {".php"},
    "Resources": {".twig"}, "Translations": {".ini"},
    "Tests": {".php"}, "docs": {".md", ".txt", ".png", ".jpg"},
}
FILES = (BUNDLE + ".php", "composer.json", "README.md", "LICENSE", "CHANGELOG.md",
         "CONTRIBUTING.md", "SECURITY.md", "phpunit.xml.dist")
VERSION = r"(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)"


def collect(root):
    paths = [root / filename for filename in FILES]
    for directory in DIRECTORIES:
        base = root / directory
        if base.is_symlink() or not base.is_dir():
            raise ValueError("Missing or linked directory: " + directory)
        paths.extend(base.rglob("*"))
    selected = []
    for path in paths:
        relative = path.relative_to(root)
        if path.is_symlink():
            raise ValueError("Symlinks are not allowed: " + str(relative))
        if any(part.startswith(".") or part == "__pycache__" for part in relative.parts):
            continue
        if path.is_dir():
            continue
        if not path.is_file():
            raise ValueError("Missing file: " + str(relative))
        if len(relative.parts) > 1:
            if path.name == "local.php" or path.suffix not in DIRECTORIES[relative.parts[0]]:
                continue
        selected.append(path)
    return sorted(selected)


def build(root, output_dir, version=None):
    selected = collect(root)
    config = (root / "Config/config.php").read_text(encoding="utf-8")
    declared = re.search(r"'version'\s*=>\s*'([^']+)'", config)
    if not declared:
        raise ValueError("Missing version in Config/config.php")
    version = declared.group(1) if version is None else version
    if not re.fullmatch(VERSION, version):
        raise ValueError("Expected a stable version such as 1.0.0")
    if version != declared.group(1):
        raise ValueError("Version must match Config/config.php")
    notes = root / "docs/releases" / (version + ".md")
    if notes not in selected or not notes.read_text(encoding="utf-8").strip():
        raise ValueError("Missing release notes: docs/releases/" + version + ".md")

    output_dir.mkdir(parents=True, exist_ok=True)
    archive = output_dir / (BUNDLE + "-" + version + ".zip")
    with zipfile.ZipFile(archive, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as output:
        for path in selected:
            info = zipfile.ZipInfo(BUNDLE + "/" + path.relative_to(root).as_posix(),
                                   date_time=(2026, 10, 11, 0, 0, 0))
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            output.writestr(info, path.read_bytes(), compresslevel=9)
    with zipfile.ZipFile(archive) as result:
        if result.testzip() is not None:
            raise RuntimeError("Corrupt archive")
    digest = hashlib.sha256(archive.read_bytes()).hexdigest()
    archive.with_suffix(".zip.sha256").write_text(
        digest + "  " + archive.name + "\n", encoding="utf-8")
    return archive, digest, len(selected)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--version", help="Must match Config/config.php; defaults to that version")
    parser.add_argument("--output-dir", type=Path, default=ROOT / "dist")
    args = parser.parse_args()
    try:
        archive, digest, count = build(ROOT, args.output_dir, args.version)
    except ValueError as error:
        parser.error(str(error))
    print(f"{archive.name}: {count} files, {archive.stat().st_size} bytes")
    print("SHA-256: " + digest)


if __name__ == "__main__":
    main()
