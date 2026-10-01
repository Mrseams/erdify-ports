"""Cross-check a port's own golden files against upstream erdify.

A port's parser tests keep golden files next to their fixtures:
``expected.json`` (the parsed model) plus ``expected.puml`` / ``expected.mmd``
/ ``expected.html``. This script renders each ``expected.json`` with the real
erdify and checks the port's rendered files are byte-identical, so the port's
renderers are proven on its own framework's data too, not only on upstream's
fixtures.

Usage:
    uv run crosscheck.py ../php/tests/fixtures ../java/...   # dirs to search
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

import jsonschema

from erdify_oracle import FORMATS, model_from_json, render

SCHEMA = json.loads((Path(__file__).resolve().parent.parent / "spec" / "ir.schema.json").read_text(encoding="utf-8"))


def main(roots: list[str]) -> int:
    checked = 0
    failures = []
    for root in roots:
        for model_file in sorted(Path(root).rglob("expected.json")):
            model = json.loads(model_file.read_text(encoding="utf-8"))
            try:
                jsonschema.validate(model, SCHEMA)
            except jsonschema.ValidationError as error:
                failures.append(f"{model_file}: not valid against ir.schema.json: {error.message}")
                continue
            entities, enums, title = model_from_json(model)
            for fmt, (_, ext) in FORMATS.items():
                target = model_file.with_suffix(ext)
                if not target.is_file():
                    continue
                checked += 1
                if render(fmt, entities, enums, title).encode("utf-8") != target.read_bytes():
                    failures.append(f"{target}: differs from upstream erdify's rendering of {model_file.name}")

    for failure in failures:
        print(failure, file=sys.stderr)
    if failures:
        return 1
    if checked == 0:
        print("No expected.json files found.", file=sys.stderr)
        return 1
    print(f"{checked} rendered files match upstream erdify.", file=sys.stderr)
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
