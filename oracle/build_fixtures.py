"""Build the golden fixtures in spec/ from upstream erdify.

For every case below, this script:

1. parses the model sources from upstream erdify's own repository (checked out
   at UPSTREAM_TAG) with the pinned erdify package,
2. writes the parsed model as ``model.json`` (erdify's ``--format json``),
3. rebuilds the IR from that JSON and renders every format and option variant,
   checking the result is identical to rendering the parsed IR directly (so the
   JSON is a lossless input for the ports),
4. checks the PlantUML against upstream's own ``expected.puml`` where one exists,
5. validates every model against spec/ir.schema.json.

It also writes spec/inject/cases.json from upstream's ``--inject`` helpers.

Usage:
    uv run build_fixtures.py           # (re)write spec/fixtures and spec/inject
    uv run build_fixtures.py --check   # fail if the committed fixtures are stale
"""

from __future__ import annotations

import argparse
import json
import subprocess
import sys
from dataclasses import dataclass, field
from importlib.metadata import version
from pathlib import Path
from typing import Any, Dict, List

import jsonschema
from erdify import parse_models_directory
from erdify.inject import MarkerError, current_region, inject, render_region

from erdify_oracle import FORMATS, model_from_json, render

UPSTREAM_REPO = "https://github.com/devsuit-berlin/erdify.git"
UPSTREAM_TAG = "v0.14.1"

ROOT = Path(__file__).resolve().parent.parent
SPEC = ROOT / "spec"
FIXTURES = SPEC / "fixtures"
INJECT = SPEC / "inject"
SCHEMA = json.loads((SPEC / "ir.schema.json").read_text(encoding="utf-8"))
CACHE = Path(__file__).resolve().parent / ".cache" / f"erdify-{UPSTREAM_TAG}"

#: Option variants. Every case gets "default"; cases may opt into the others.
VARIANTS: Dict[str, Dict[str, bool]] = {
    "default": {"include_enums": True, "include_relationships": True},
    "no-enums": {"include_enums": False, "include_relationships": True},
    "no-relationships": {"include_enums": True, "include_relationships": False},
}


@dataclass
class Case:
    name: str
    #: Path inside the upstream repository, file or directory.
    source: str
    title: str = "Database ERD"
    parse_options: Dict[str, Any] = field(default_factory=dict)
    variants: List[str] = field(default_factory=lambda: ["default"])


CASES: List[Case] = [
    # Upstream golden files (titles match upstream's tests).
    Case(
        "ecommerce",
        "tests/fixtures/ecommerce",
        "E-Commerce ERD",
        variants=["default", "no-enums", "no-relationships"],
    ),
    Case("inheritance", "tests/fixtures/inheritance", "Inheritance ERD"),
    Case("edge_cases", "tests/fixtures/edge_cases", "Edge Cases ERD"),
    Case("m2m_link_model", "tests/fixtures/m2m_link_model"),
    Case(
        "sql_ecommerce",
        "tests/fixtures/sql/ecommerce.sql",
        "E-Commerce ERD",
        parse_options={"sql_dialect": "postgres"},
        variants=["default", "no-enums"],
    ),
    # Per-framework fixtures.
    Case("sqlalchemy", "tests/fixtures/sqlalchemy"),
    Case("django", "tests/fixtures/django"),
    Case("django_raw_types", "tests/fixtures/django", parse_options={"django_raw_types": True}),
    Case("pydantic", "tests/fixtures/pydantic"),
    Case("dataclasses", "tests/fixtures/dataclasses"),
    Case("dataclasses_infer_keys", "tests/fixtures/dataclasses", parse_options={"infer_keys": True}),
    Case("base_classes", "tests/fixtures/base_classes", parse_options={"base_classes": ["Schema"]}),
    Case("link_detection", "tests/fixtures/link_detection"),
    Case("m2m_secondary", "tests/fixtures/m2m_secondary"),
    Case("m2m_secondary_table", "tests/fixtures/m2m_secondary_table"),
    # The "one schema, five frameworks" examples: all five must render the same.
    # Pydantic and dataclasses have no key concept, so upstream's docs render
    # them with --infer-keys.
    Case("example_sqlmodel", "docs/examples/sqlmodel"),
    Case("example_sqlalchemy", "docs/examples/sqlalchemy"),
    Case("example_django", "docs/examples/django"),
    Case("example_pydantic", "docs/examples/pydantic", parse_options={"infer_keys": True}),
    Case("example_dataclass", "docs/examples/dataclass", parse_options={"infer_keys": True}),
]


def ensure_upstream() -> Path:
    """Shallow-clone upstream at UPSTREAM_TAG into the cache, once."""
    if not (CACHE / "src" / "erdify").is_dir():
        CACHE.parent.mkdir(parents=True, exist_ok=True)
        subprocess.run(
            ["git", "-c", "advice.detachedHead=false", "clone", "-q", "--depth", "1", "--branch", UPSTREAM_TAG, UPSTREAM_REPO,
             str(CACHE)],
            check=True,
        )
    return CACHE


def build_case(case: Case, upstream: Path) -> Dict[str, str]:
    """Return {relative file name: content} for one case."""
    entities, enums = parse_models_directory(upstream / case.source, **case.parse_options)
    if not entities:
        raise SystemExit(f"{case.name}: upstream erdify found no entities in {case.source}")

    model_json = render("json", entities, enums, case.title)
    jsonschema.validate(json.loads(model_json), SCHEMA)
    rebuilt, rebuilt_enums, title = model_from_json(json.loads(model_json))

    files: Dict[str, str] = {
        "case.json": json.dumps(
            {
                "upstream_source": case.source,
                "parse_options": case.parse_options,
                "title": case.title,
                "variants": {v: VARIANTS[v] for v in case.variants},
            },
            indent=2,
        ),
        "model.json": model_json,
    }
    for variant in case.variants:
        options = VARIANTS[variant]
        for fmt, (_, ext) in FORMATS.items():
            if variant == "default" and fmt == "json":
                continue  # identical to model.json
            from_parsed = render(fmt, entities, enums, case.title, **options)
            from_json = render(fmt, rebuilt, rebuilt_enums, title, **options)
            if from_parsed != from_json:
                raise SystemExit(
                    f"{case.name}/{variant}{ext}: rendering from model.json differs from "
                    "rendering the parsed models, so the JSON is not a lossless IR"
                )
            files[f"{variant}{ext}"] = from_json

    source = upstream / case.source
    golden = (source.parent if source.is_file() else source) / "expected.puml"
    if golden.is_file():
        expected = golden.read_text(encoding="utf-8").rstrip("\n")
        if files["default.puml"] != expected:
            raise SystemExit(f"{case.name}: oracle output differs from upstream {golden.name}")
    return files


def build_inject(upstream_block_source: str) -> Dict[str, str]:
    """Cases for --inject: Markdown in, Markdown out (or the error message)."""
    region = render_region(upstream_block_source, fence="mermaid")
    inputs = {
        "empty_region": "# Title\n\n<!-- erdify:start -->\n<!-- erdify:end -->\n\nAfter.\n",
        "stale_region": "# T\n<!-- erdify:start -->\n```mermaid\nerDiagram\n```\n<!-- erdify:end -->\n",
        "markers_with_attributes": "<!--erdify:start  v=1 -->old<!--   erdify:end x -->\ntail\n",
        "crlf_outside_region": "line one\r\n<!-- erdify:start -->\r\n<!-- erdify:end -->\r\nline two\r\n",
        "error_missing_start": "<!-- erdify:end -->\n",
        "error_missing_end": "<!-- erdify:start -->\n",
        "error_duplicate_pair": (
            "<!-- erdify:start --><!-- erdify:end -->\n<!-- erdify:start --><!-- erdify:end -->\n"
        ),
        "error_reversed": "<!-- erdify:end -->\n<!-- erdify:start -->\n",
    }
    cases = []
    for name, text in inputs.items():
        entry: Dict[str, Any] = {"name": name, "input": text}
        try:
            entry["output"] = inject(text, region)
            entry["region_before"] = current_region(text)
        except MarkerError as error:
            entry["error"] = str(error)
        cases.append(entry)
    return {
        "cases.json": json.dumps(
            {
                "fence": "mermaid",
                "rendered": upstream_block_source,
                "region": region,
                "cases": cases,
            },
            indent=2,
        )
    }


def collect() -> Dict[Path, str]:
    installed = version("erdify")
    if f"v{installed}" != UPSTREAM_TAG:
        raise SystemExit(f"installed erdify {installed} does not match UPSTREAM_TAG {UPSTREAM_TAG}")
    upstream = ensure_upstream()

    out: Dict[Path, str] = {}
    for case in CASES:
        for name, content in build_case(case, upstream).items():
            out[FIXTURES / case.name / name] = content

    mermaid = out[FIXTURES / "example_sqlmodel" / "default.mmd"]
    for name, content in build_inject(mermaid).items():
        out[INJECT / name] = content
    return out


def existing_files() -> set[Path]:
    return {p for base in (FIXTURES, INJECT) if base.exists() for p in base.rglob("*") if p.is_file()}


def main() -> int:
    parser = argparse.ArgumentParser(description="Build spec/fixtures from upstream erdify.")
    parser.add_argument("--check", action="store_true", help="fail if fixtures are stale")
    args = parser.parse_args()

    wanted = collect()
    present = existing_files()

    if args.check:
        problems = [
            f"stale: {p.relative_to(ROOT)}"
            for p, content in wanted.items()
            if not p.is_file() or p.read_bytes() != content.encode("utf-8")
        ]
        problems += [f"unexpected: {p.relative_to(ROOT)}" for p in sorted(present - set(wanted))]
        for line in problems:
            print(line, file=sys.stderr)
        if problems:
            print("Fixtures are out of date. Run: uv run build_fixtures.py", file=sys.stderr)
            return 1
        print(f"{len(wanted)} fixture files are up to date.", file=sys.stderr)
        return 0

    for stale in present - set(wanted):
        stale.unlink()
    for path, content in wanted.items():
        path.parent.mkdir(parents=True, exist_ok=True)
        # Write bytes so Windows never turns "\n" into "\r\n": the ports compare
        # byte for byte.
        path.write_bytes(content.encode("utf-8"))
    print(f"Wrote {len(wanted)} files for {len(CASES)} cases.", file=sys.stderr)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
