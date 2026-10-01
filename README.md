# erdify-ports

PHP and Java ports of [erdify](https://github.com/devsuit-berlin/erdify): generate
PlantUML and Mermaid entity relationship diagrams from your **Laravel** and
**Spring Boot** models. Like erdify, they read your source files and never run
your code or connect to a database.

> Unofficial port. Not affiliated with or endorsed by devsuit GmbH, the authors
> of erdify. Diagrams, options and `--check` / `--inject` behave the same as
> upstream erdify, and a shared test suite generated with the real erdify
> checks that.

| Package | For | Status |
|---|---|---|
| [`erdify-php`](php/) (`mrseams/erdify-php`) | Laravel: migrations + Eloquent models | **0.1.0**, not yet on Packagist |
| [`erdify-java`](java/) (`io.github.mrseams:erdify-*`) | Spring Boot: JPA entities, Spring Data JDBC, Flyway SQL | Planned |
| [`spec/`](spec/) | The shared contract and golden fixtures | **0.1.0**, from erdify 0.14.1 |

## How it fits together

```
          Laravel migrations,        JPA entities,
          Eloquent models            Flyway SQL
                │                        │
        erdify-php parser        erdify-java parser
                └──────────┬─────────────┘
                           ▼
              model (spec/ir.schema.json)        ◄── same format as erdify --format json
                           ▼
         PlantUML · Mermaid · JSON · HTML        ◄── checked against spec/fixtures/
         --output · --check · --inject               (rendered by upstream erdify)
```

Each language needs its own parser. Everything after parsing has to match
upstream exactly, so [`oracle/`](oracle/) runs the real Python erdify to
generate the expected output, and each port's tests compare against it byte for
byte.

## Repository layout

| Path | What |
|---|---|
| `spec/` | Model schema, CLI contract, golden fixtures. See [spec/README.md](spec/README.md). |
| `oracle/` | Python scripts that generate `spec/` with the pinned upstream erdify. |
| `php/` | erdify-php (Composer package, Laravel integration). Mirrored to `Mrseams/erdify-php` for Packagist. |
| `java/` | erdify-java (core, CLI, Maven plugin, Gradle plugin). |

## Working on the spec

You need [uv](https://docs.astral.sh/uv/).

```bash
cd oracle
uv run build_fixtures.py          # regenerate spec/fixtures and spec/inject
uv run build_fixtures.py --check  # what CI runs
```

Render any model JSON with upstream erdify:

```bash
uv run erdify_oracle.py ../spec/fixtures/ecommerce/model.json --format mermaid
```

Check a port's own golden files against upstream erdify:

```bash
uv run crosscheck.py ../php/tests/fixtures
```

## Credits and license

All diagram formats and CLI behaviour come from
[erdify](https://github.com/devsuit-berlin/erdify) by devsuit GmbH. The golden
fixtures are generated from erdify's own test models and examples
(`tests/fixtures/`, `docs/examples/`), each case recording where it came from in
`case.json`.

[MIT](LICENSE). The license keeps devsuit GmbH's copyright alongside this
project's.
