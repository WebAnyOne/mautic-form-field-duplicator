#!/usr/bin/env python3
"""Prepare a NEW, disposable Mautic checkout for the plugin's destructive tests."""

import argparse
import shutil
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BUNDLE = "WebAnyOneFormFieldDuplicatorBundle"


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--mautic", type=Path, required=True)
    args = parser.parse_args()
    mautic = args.mautic.resolve()
    local = mautic / "config/local.php"
    destination = mautic / "plugins" / BUNDLE
    if not (mautic / "app/AppTestKernel.php").is_file() or not (mautic / "vendor/autoload.php").is_file():
        parser.error("Expected a Mautic checkout with Composer development dependencies installed")
    if local.exists() or local.is_symlink() or destination.exists() or destination.is_symlink():
        parser.error("Refusing an already configured instance or an existing plugin directory")
    if (mautic / "app/config/local.php").exists():
        parser.error("Refusing an instance with legacy local configuration")

    shutil.copytree(ROOT, destination, ignore=shutil.ignore_patterns(
        ".git", ".github", ".DS_Store", "__pycache__", "*.pyc", "dist", "vendor", "node_modules", "scripts"))
    local.parent.mkdir(parents=True, exist_ok=True)
    # DB_* environment variables in Mautic's config_test.php supply the disposable connection.
    local.write_text("""<?php
// Disposable CI configuration. Never use for a deployed instance.
$parameters = [
    'db_driver' => 'pdo_mysql',
    'db_host' => '127.0.0.1',
    'db_port' => 3306,
    'db_name' => 'wao_form_field_duplicator_ci',
    'db_user' => 'root',
    'db_password' => '',
    'db_table_prefix' => 'test_',
    'site_url' => 'https://localhost',
    'secret_key' => 'disposable-ci-test-only',
    'mailer_dsn' => 'null://null',
    'messenger_dsn_email' => 'in-memory://default',
    'messenger_dsn_hit' => 'sync://',
    'messenger_dsn_failed' => 'in-memory://default',
];
""", encoding="utf-8")
    print("Disposable Mautic test checkout prepared. Set DB_* to a disposable database before PHPUnit.")


if __name__ == "__main__":
    main()
