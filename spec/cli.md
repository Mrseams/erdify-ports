# CLI contract

Every port (`erdify-php`, `erdify-java`) behaves like upstream erdify v0.14.1
for the options below. Parsing options are language-specific; everything after
parsing (rendering, writing, `--check`, `--inject`) is shared and is pinned down
by the fixtures in `spec/fixtures/` and `spec/inject/`.

## Shared options

| Option | Config key | Meaning |
|---|---|---|
| `input` (positional) | — | Directory or file to read. Ports may default it (see below). |
| `-o, --output PATH` | `output` | Output path. The extension is replaced by the format's (see *Output naming*). |
| `--title TEXT` | `title` | Diagram title. Default `Database ERD`. |
| `--format FMT...` | `format` (string or list) | `plantuml`, `mermaid`, `json`, `html`. Default `plantuml`, or `mermaid` when injecting. Unknown names print `Warning: unknown format '<x>', ignoring` and are dropped; if none remain, the default is used. |
| `--exclude PATTERN...` | `exclude` | Case-sensitive globs on entity name **or** table name. |
| `--exclude-paths PATTERN...` | `exclude_paths` | Globs on file paths (relative path or any path segment), applied before parsing. |
| `--include PATTERN...` | `include` | Files to scan. Each port defines its own default. |
| `--no-enums` | `no_enums` | Do not render enums. |
| `--no-relationships` | `no_relationships` | See *Known upstream quirks*. |
| `--inject FILE` | `inject` | Write the diagram into a Markdown file between markers. |
| `--allow-empty` | `allow_empty` | "No tables found" becomes a warning and exits 0. |
| `--check` | — | Write nothing; exit 1 if any target is missing or stale. |
| `-v, --version` | — | Print the version. |

**Which setting wins:** explicit CLI flag > config file > default. Boolean flags
are merged with OR: a flag that is on in either the CLI or the config is on.
Relative `output` and `inject` paths from the config resolve against the
directory that holds the config file. Paths given on the CLI resolve against
the current directory.

**Config file per port:** the same key names as upstream's `[tool.erdify]`.
Unknown keys print a warning (`Warning: unknown erdify config key '<key>' in
<file>`) and are ignored. A non-string `title` or `output` is an error (exit 1).

| Port | Where |
|---|---|
| erdify-php | `composer.json` → `"extra": { "erdify": { ... } }`, found by searching upward from `input` |
| erdify-java | Maven `<configuration>` / Gradle `erdify { }` block; the standalone CLI reads `erdify.json` searched upward from `input` |

## Output naming

- The extension of `--output` is **replaced** with the format's: `.puml`,
  `.mmd`, `.json`, `.html`. `-o docs/erd`, `-o docs/erd.puml` and
  `-o docs/erd.txt` all write `docs/erd.puml` for PlantUML. (Upstream uses
  Python's `Path.with_suffix`: only the last suffix is replaced, so `erd.v2.txt`
  becomes `erd.v2.puml`.)
- One format and no `--output` → printed to stdout, **followed by one `\n`**.
- Files contain the rendered text **exactly**, with **no trailing newline**
  added. This is what makes `--check` compatible across all three tools.
- Missing parent directories are created.
- Files are written as UTF-8 with `\n` line endings on every OS.

## Exit codes and errors

| Situation | Exit | Message (stderr) |
|---|---|---|
| Success | 0 | `Generated <fmt> ERD: <path>` per file, then `  Found <n> entities and <m> enums` when `--output` is set |
| Input path missing | 1 | `Error: Input path does not exist: <path>` |
| Parse failure | 1 | `Error parsing models: <reason>` |
| No entities, without `--allow-empty` | 1 | `Error: No tables found in <input>` plus a hint |
| No entities, with `--allow-empty` | 0 | Same text with `Warning:`; the empty diagram is still written |
| `--check` without `--output`/`--inject` | 2 | `Error: --check requires --output or --inject (or 'output'/'inject' in [tool.erdify])` (ports name their own config file) |
| Several formats, no `--output` | 2 | `Error: multiple --format values require --output` |
| `--inject` with several formats | 2 | `Error: --inject needs a single --format (got multiple)` |
| `--inject` with `html` | 2 | `Error: --inject cannot embed the html format` |
| `--check`, everything current | 0 | `<fmt> ERD is up to date: <path>` / `Injected ERD is up to date: <path>` |
| `--check`, stale or missing | 1 | `Error: generated <fmt> ERD differs from <path>. Re-run erdify to update it.` (`is missing at` when absent) / `Error: injected ERD in <path> is stale. Re-run erdify to update it.` |
| `--inject` target missing | 1 | `Error: --inject target not found: <path>` |
| Marker problem | 1 | `Error: <marker error> in <path>` |

Ports replace `Re-run erdify` with their own command (`php artisan erdify`,
`mvn erdify:generate`, ...), and the config file named in the `--check` error
with their own. Everything else stays the same.

Ports write files with `\n` line endings on every OS (upstream writes `\r\n`
on Windows). `--check` reads the existing file with `\r\n` turned into `\n`,
as upstream does, so a file written by either tool on any OS passes.

## `--inject`

Defined by `spec/inject/cases.json`, generated from upstream's own functions.

- Markers: `<!-- erdify:start -->` / `<!-- erdify:end -->`. Matching is
  lenient: regex `<!--\s*erdify:start\b[^>]*-->` and
  `<!--\s*erdify:end\b[^>]*-->`.
- Exactly one start and one end marker, with the start first. Otherwise the
  error is one of:
  `no '<!-- erdify:start -->' marker found`,
  `no '<!-- erdify:end -->' marker found`,
  `multiple erdify marker pairs found; expected exactly one`,
  `erdify:end marker appears before erdify:start`.
- The region written between the markers is
  `"\n```" + fence + "\n" + rendered.rstrip("\n") + "\n```\n"`, where `fence`
  is the format name (`mermaid`, `plantuml`, `json`).
- Every byte outside the region is kept, including `\r\n` line endings.
- `--check` compares the current region with the new one, byte for byte.

## Known upstream quirks (matched on purpose)

- **`--no-relationships` only affects `json`.** In v0.14.1, `generate_plantuml`,
  `generate_mermaid` and `generate_html` accept `include_relationships` but
  ignore it, so relationship lines are still drawn. The fixture
  `spec/fixtures/ecommerce/no-relationships.*` records this. Ports reproduce it
  so `--check` agrees with upstream. If upstream fixes it, bump the oracle pin
  and the fixtures will show the change.

## Port-specific defaults

| | erdify-php | erdify-java |
|---|---|---|
| `input` when omitted | The current directory (`php artisan erdify`: the app root) | `src/main/java`, plus `src/main/resources/db/migration` if present |
| `--include` default | In a Laravel app (an `artisan` file is present): `app/**/*.php`, `database/migrations/*.php`. Elsewhere: `*.php` | `**/*.java`, plus `db/migration/V*__*.sql` with the SQL module |
| Extra options | None yet. Planned: reading `schema:dump` files | `--naming spring\|hibernate` (default `spring`) |
