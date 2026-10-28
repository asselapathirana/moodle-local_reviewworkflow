#!/usr/bin/env python3
# Copyright (c) 2026 Assela Pathirana
# SPDX-License-Identifier: GPL-3.0-or-later
"""Build a plugin-only ZIP; exclude browser state, dev tooling and specification files."""
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED

root = Path(__file__).resolve().parents[1]
output = root / "build" / "reviewworkflow.zip"
output.parent.mkdir(exist_ok=True)
files = [root / name for name in ["version.php", "lib.php", "index.php", "configure.php",
    "reviewers.php", "review.php", "README.md", "LICENSE"]]
for directory in ["classes", "db", "lang", "tests"]:
    files.extend(p for p in (root / directory).rglob("*") if p.is_file())
with ZipFile(output, "w", ZIP_DEFLATED) as archive:
    for path in sorted(files):
        archive.write(path, "reviewworkflow/" + path.relative_to(root).as_posix())
with ZipFile(output) as archive:
    assert archive.testzip() is None
    assert all(n.startswith("reviewworkflow/") for n in archive.namelist())
print(f"Built {output} ({len(files)} files)")
