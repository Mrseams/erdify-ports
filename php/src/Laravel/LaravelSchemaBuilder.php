<?php

declare(strict_types=1);

namespace Mrseams\Erdify\Laravel;

use Mrseams\Erdify\Model\Entity;
use Mrseams\Erdify\Model\Schema;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Builds the schema of a Laravel app from its source files:
 *
 * - tables and columns from the migrations, replayed in file-name order;
 * - entity names, relationships and enum casts from the Eloquent models.
 *
 * A table with a model is named after the model class ("User"); a table
 * without one (e.g. a pivot table) keeps its table name, as upstream's SQL
 * frontend does. A model whose table no migration creates is still drawn,
 * without columns, so its relationships stay visible.
 */
final class LaravelSchemaBuilder
{
    /** @var list<string> */
    private array $warnings = [];

    private readonly Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * @param list<string> $files
     * @param list<string> $exclude entity name or table name globs
     */
    public function build(array $files, array $exclude = [], string $title = 'Database ERD'): Schema
    {
        $this->warnings = [];
        $scanner = new ModelScanner();
        $migrations = [];

        foreach ($files as $file) {
            if (str_ends_with($file, '.blade.php') || !str_ends_with(strtolower($file), '.php')) {
                continue;
            }
            $ast = $this->parse($file);
            if ($ast === null) {
                continue;
            }
            $scanner->add($ast);
            if ($this->isMigration($ast)) {
                $migrations[] = [$file, $ast];
            }
        }

        $models = $scanner->models();
        $tableOf = [];
        foreach ($models as $model) {
            $tableOf[$model->class] ??= $model->table;
        }

        // Laravel runs migrations in file-name order, whatever their directory.
        usort($migrations, static fn (array $a, array $b) => strcmp(basename($a[0]), basename($b[0])) ?: strcmp($a[0], $b[0]));
        $replayer = new MigrationReplayer(static fn (string $class): ?string => $tableOf[$class] ?? null);
        foreach ($migrations as [$file, $ast]) {
            $replayer->replay($ast, $file);
        }
        array_push($this->warnings, ...$replayer->warnings());

        $enums = [];
        foreach ($scanner->enums() as $enum) {
            $enums[$enum->name] ??= $enum;
        }

        $schema = new Schema([], $enums, $title);
        $modelByTable = [];
        foreach ($models as $model) {
            $modelByTable[$model->table] ??= $model;
        }

        foreach ($replayer->tables() as $table => $fields) {
            $model = $modelByTable[$table] ?? null;
            $this->addEntity($schema, new Entity(
                name: $model?->name() ?? $table,
                tableName: $table,
                fields: array_values($fields),
                source: $model !== null ? 'eloquent' : 'migration',
            ), $model);
        }
        foreach ($models as $model) {
            if (!isset($replayer->tables()[$model->table]) && ($modelByTable[$model->table] ?? null) === $model) {
                $this->addEntity($schema, new Entity($model->name(), $model->table), $model);
            }
        }

        $this->exclude($schema, $exclude);
        return $schema;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    private function addEntity(Schema $schema, Entity $entity, ?EloquentModel $model): void
    {
        if (isset($schema->entities[$entity->name])) {
            $this->warnings[] = "Skipped table '{$entity->tableName}': the name '{$entity->name}' is already used by table '{$schema->entities[$entity->name]->tableName}'";
            return;
        }
        if ($model !== null) {
            $entity->relationships = $model->relationships;
            foreach ($model->enumCasts as $column => $enumClass) {
                $field = $entity->field($column);
                if ($field !== null) {
                    $field->type = Naming::classBasename($enumClass);
                }
            }
        }
        $entity->isLinkTable = Entity::isStructuralLinkTable($entity->fields);
        $schema->entities[$entity->name] = $entity;
    }

    /**
     * Removes entities whose name or table matches a pattern, and relationships
     * that pointed at them (as upstream's --exclude).
     *
     * @param list<string> $patterns
     */
    private function exclude(Schema $schema, array $patterns): void
    {
        if ($patterns === []) {
            return;
        }
        $removed = [];
        foreach ($schema->entities as $name => $entity) {
            foreach ($patterns as $pattern) {
                if (fnmatch($pattern, $entity->name, FNM_NOESCAPE) || fnmatch($pattern, $entity->tableName, FNM_NOESCAPE)) {
                    $removed[$name] = true;
                    unset($schema->entities[$name]);
                    break;
                }
            }
        }
        foreach ($schema->entities as $entity) {
            $entity->relationships = array_values(array_filter(
                $entity->relationships,
                static fn ($rel) => !isset($removed[$rel->target]),
            ));
        }
    }

    /** @return list<Node\Stmt>|null */
    private function parse(string $file): ?array
    {
        try {
            $ast = $this->parser->parse((string) file_get_contents($file)) ?? [];
        } catch (Error $e) {
            $this->warnings[] = "Skipped $file: " . $e->getMessage();
            return null;
        }
        $traverser = new NodeTraverser(new NameResolver(null, ['replaceNodes' => false]));
        return $traverser->traverse($ast);
    }

    /** @param list<Node\Stmt> $ast */
    private function isMigration(array $ast): bool
    {
        return (new NodeFinder())->findFirst($ast, static function (Node $node): bool {
            return $node instanceof Node\Stmt\Class_
                && $node->extends !== null
                && Naming::classBasename(Ast::resolvedName($node->extends)) === 'Migration';
        }) !== null;
    }
}
