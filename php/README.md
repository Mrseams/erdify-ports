# erdify-php

Generate PlantUML and Mermaid entity relationship diagrams from your **Laravel**
migrations and Eloquent models. It reads your source files and never runs your
code or connects to a database.

A PHP port of [erdify](https://github.com/devsuit-berlin/erdify) (Python). Same
output formats, same options, same `--check` and `--inject`. Not affiliated with
devsuit GmbH.

## Install

```bash
composer require --dev mrseams/erdify-php
```

Laravel discovers the `php artisan erdify` command automatically. Requires PHP
8.2+. Laravel itself is optional: `vendor/bin/erdify` works in any project.

## Use

```bash
php artisan erdify                          # PlantUML to stdout
php artisan erdify --format=mermaid         # Mermaid (renders on GitHub)
php artisan erdify -o docs/erd --format=plantuml --format=mermaid
php artisan erdify --inject=README.md       # write into README between markers
php artisan erdify --inject=README.md --check   # in CI: fail if it is stale
```

`vendor/bin/erdify` takes the same options, with several values after one flag
(`--format plantuml mermaid`), as upstream erdify does. Run
`vendor/bin/erdify --help` for the full list.

To embed the diagram in a Markdown file, add the markers once:

```markdown
<!-- erdify:start -->
<!-- erdify:end -->
```

Only the text between them is rewritten. GitHub renders the Mermaid diagram.

### Settings

Put defaults in `composer.json`, using the same keys as upstream's
`[tool.erdify]`:

```json
{
    "extra": {
        "erdify": {
            "title": "Shop database",
            "output": "docs/erd",
            "format": ["plantuml", "mermaid"],
            "exclude": ["cache*", "jobs", "job_batches", "failed_jobs", "sessions", "password_reset_tokens"]
        }
    }
}
```

An explicit flag wins over the config file. Relative paths resolve from the
folder that holds `composer.json`. Keys: `title`, `output`, `format`, `exclude`,
`exclude_paths`, `include`, `inject`, `no_enums`, `no_relationships`,
`no_default_excludes`, `allow_empty`.

### CI

```yaml
- run: composer install --no-interaction
- run: php artisan erdify --check   # with "output" or "inject" set in composer.json
```

## What it reads

| From | What |
|---|---|
| `database/migrations/*.php` | Tables and columns. Every `up()` is replayed in file-name order, as `php artisan migrate` does: `Schema::create`, `Schema::table`, `drop`, `dropIfExists`, `rename`, and `Schema::connection(...)` variants. |
| `app/**/*.php` | Eloquent models (entity names, `$table`, relationships), PHP enums, and enum `$casts` / `casts()`. |

**Columns:** every Blueprint column type, `id()`, `foreignId()`, `foreignIdFor()`,
`timestamps()`, `softDeletes()`, `rememberToken()`, `morphs()` and its variants.
Modifiers: `nullable()`, `default()`, `useCurrent()`, `unique()`, `index()`,
`primary()`, `after()`, `first()`, `change()`. Changes: `dropColumn()`,
`renameColumn()`, `dropForeign()`, `dropPrimary()`, `dropUnique()`, `dropIndex()`,
`dropConstrainedForeignId()`, `dropMorphs()`, and the other `drop*` helpers.

**Foreign keys:** `->constrained()` (the table is guessed the way Laravel
guesses it), `->references()->on()`, and `$table->foreign()`. A bare
`foreignId('user_id')` is **not** a foreign key, because without a constraint
the database has none either.

**Models:** a class whose parent chain reaches `Model`, `Authenticatable`,
`Pivot` or `MorphPivot`. `$table`, relationships and casts are inherited from
parent classes and traits in the scanned files. Pivot models use Laravel's
singular table name.

**Relationships:** `belongsTo`, `hasOne`, `hasMany`, `belongsToMany`,
`morphOne`, `morphMany`, `morphToMany`, `morphedByMany`. A relationship is drawn
only when no foreign key already connects the two tables, so you see one line
per link. `morphTo` and `has*Through` are not drawn.

A table with a model is named after the class (`User`). A table without one, such
as a pivot table, keeps its table name (`role_user`).

## Limitations

erdify-php reads text and never runs it, so it cannot see:

- tables or columns created with `DB::statement()` or other raw SQL;
- table or column names that are not string literals (`Schema::create($name, ...)`
  is skipped with a warning);
- migrations from packages under `vendor/` (add their folder with `--include`
  if you want them);
- composite foreign keys, and composite `unique()` / `index()` keys (as upstream);
- `enum()` column values: the column shows as type `enum`. Cast it to a PHP enum
  to show its cases.

Squashed migrations (`php artisan schema:dump`) are not read yet. Until they
are, keep the migrations the dump replaced, or point `--include` at them.

## Development

```bash
composer install
composer test
```

Correctness is defined by [`../spec`](../spec/README.md): the renderers must
reproduce upstream erdify's output byte for byte. `tests/Conformance` runs every
upstream fixture, and `oracle/crosscheck.py` renders this package's own Laravel
golden files with the real erdify to confirm they match.
