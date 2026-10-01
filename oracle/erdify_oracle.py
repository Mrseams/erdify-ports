"""Render erdify's output formats from a model JSON file, using upstream erdify.

The model JSON is exactly what ``erdify --format json`` writes (see
spec/ir.schema.json). Rebuilding erdify's own IR from it and calling erdify's
public generators gives the reference output every port must reproduce.

Usage:
    uv run erdify_oracle.py MODEL.json [--format plantuml mermaid json html]
                            [--title T] [--no-enums] [--no-relationships]
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path
from typing import Any, Callable, Dict, Tuple

from erdify import (
    EntityInfo,
    EnumInfo,
    FieldInfo,
    generate_html,
    generate_json,
    generate_mermaid,
    generate_plantuml,
)

Generator = Callable[..., str]

#: Format name -> (generator, file extension). Mirrors erdify.cli.FORMATS.
FORMATS: Dict[str, Tuple[Generator, str]] = {
    "plantuml": (generate_plantuml, ".puml"),
    "mermaid": (generate_mermaid, ".mmd"),
    "json": (generate_json, ".json"),
    "html": (generate_html, ".html"),
}


def model_from_json(
    model: Dict[str, Any],
) -> Tuple[Dict[str, EntityInfo], Dict[str, EnumInfo], str]:
    """Rebuild erdify's IR from a model JSON document.

    Order matters: entities and enums keep the order of the JSON arrays, because
    the generators iterate the dicts in insertion order.
    """
    entities: Dict[str, EntityInfo] = {}
    for e in model["entities"]:
        entities[e["name"]] = EntityInfo(
            name=e["name"],
            table_name=e["table_name"],
            source=e["source"],
            is_link_table=e["is_link_table"],
            fields=[
                FieldInfo(
                    name=f["name"],
                    type_str=f["type"],
                    is_primary_key=f["primary_key"],
                    is_foreign_key=f["foreign_key"],
                    is_nullable=f["nullable"],
                    foreign_table=f["foreign_table"],
                    index=f["index"],
                    default_value=f["default"],
                    is_unique=f["unique"],
                )
                for f in e["fields"]
            ],
            relationships=[(r["target"], r["type"], r["attribute"]) for r in e["relationships"]],
        )
    enums = {e["name"]: EnumInfo(name=e["name"], values=list(e["values"])) for e in model["enums"]}
    return entities, enums, model["title"]


def render(
    fmt: str,
    entities: Dict[str, EntityInfo],
    enums: Dict[str, EnumInfo],
    title: str,
    include_enums: bool = True,
    include_relationships: bool = True,
) -> str:
    """Render one format exactly as the erdify CLI does (before any newline is added)."""
    generate, _ = FORMATS[fmt]
    return generate(
        entities=entities,
        enums=enums,
        title=title,
        include_enums=include_enums,
        include_relationships=include_relationships,
    )


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    parser.add_argument("model", type=Path, help="model JSON (erdify --format json output)")
    parser.add_argument("--format", nargs="+", default=["plantuml"], choices=sorted(FORMATS))
    parser.add_argument("--title", help="override the title stored in the model")
    parser.add_argument("--no-enums", action="store_true")
    parser.add_argument("--no-relationships", action="store_true")
    args = parser.parse_args()

    entities, enums, title = model_from_json(json.loads(args.model.read_text(encoding="utf-8")))
    for fmt in args.format:
        sys.stdout.write(
            render(
                fmt,
                entities,
                enums,
                args.title or title,
                include_enums=not args.no_enums,
                include_relationships=not args.no_relationships,
            )
            + "\n"
        )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
