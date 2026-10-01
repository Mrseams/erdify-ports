<?php

declare(strict_types=1);

namespace Mrseams\Erdify\Laravel;

use Mrseams\Erdify\Model\Field;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\NodeFinder;

/**
 * Rebuilds the final schema by replaying the `up()` method of every migration,
 * in the order Laravel runs them (by file name).
 *
 * Understands Schema::create / table / drop / dropIfExists / rename and the
 * Blueprint calls inside them: column types, modifiers (nullable, default,
 * unique, index, primary, constrained, references/on, change) and table-level
 * changes (primary, unique, index, foreign, dropColumn, renameColumn,
 * dropForeign, dropPrimary, dropUnique, dropIndex, drop* helpers).
 */
final class MigrationReplayer
{
    /** Blueprint method => [display type, is auto-increment primary key]. */
    private const COLUMN_TYPES = [
        'id' => ['bigint', true],
        'bigIncrements' => ['bigint', true],
        'increments' => ['integer', true],
        'mediumIncrements' => ['mediumint', true],
        'smallIncrements' => ['smallint', true],
        'tinyIncrements' => ['tinyint', true],
        'bigInteger' => ['bigint', false],
        'unsignedBigInteger' => ['bigint', false],
        'integer' => ['integer', false],
        'unsignedInteger' => ['integer', false],
        'mediumInteger' => ['mediumint', false],
        'unsignedMediumInteger' => ['mediumint', false],
        'smallInteger' => ['smallint', false],
        'unsignedSmallInteger' => ['smallint', false],
        'tinyInteger' => ['tinyint', false],
        'unsignedTinyInteger' => ['tinyint', false],
        'foreignId' => ['bigint', false],
        'foreignUuid' => ['uuid', false],
        'foreignUlid' => ['ulid', false],
        'string' => ['string', false],
        'char' => ['char', false],
        'text' => ['text', false],
        'tinyText' => ['tinytext', false],
        'mediumText' => ['mediumtext', false],
        'longText' => ['longtext', false],
        'boolean' => ['boolean', false],
        'date' => ['date', false],
        'dateTime' => ['datetime', false],
        'dateTimeTz' => ['datetimetz', false],
        'time' => ['time', false],
        'timeTz' => ['timetz', false],
        'timestamp' => ['timestamp', false],
        'timestampTz' => ['timestamptz', false],
        'year' => ['year', false],
        'decimal' => ['decimal', false],
        'unsignedDecimal' => ['decimal', false],
        'double' => ['double', false],
        'float' => ['float', false],
        'json' => ['json', false],
        'jsonb' => ['jsonb', false],
        'binary' => ['binary', false],
        'uuid' => ['uuid', false],
        'ulid' => ['ulid', false],
        'ipAddress' => ['ipaddress', false],
        'macAddress' => ['macaddress', false],
        'enum' => ['enum', false],
        'set' => ['set', false],
        'geometry' => ['geometry', false],
        'geography' => ['geography', false],
        'point' => ['point', false],
        'lineString' => ['linestring', false],
        'polygon' => ['polygon', false],
        'multiPoint' => ['multipoint', false],
        'multiLineString' => ['multilinestring', false],
        'multiPolygon' => ['multipolygon', false],
        'vector' => ['vector', false],
    ];

    /** Methods whose first argument defaults to "id" instead of being required. */
    private const DEFAULT_ID_COLUMN = ['id' => true];

    /** @var array<string, array<string, Field>> table => column => field, in creation order */
    private array $tables = [];

    /** @var list<string> */
    private array $warnings = [];

    /**
     * @param \Closure(string): ?string $modelTable resolves a model class to its table (foreignIdFor)
     */
    public function __construct(private readonly \Closure $modelTable)
    {
    }

    /**
     * @param list<Node\Stmt> $ast a parsed migration file, after NameResolver
     */
    public function replay(array $ast, string $file): void
    {
        $finder = new NodeFinder();
        foreach ($finder->findInstanceOf($ast, Node\Stmt\ClassMethod::class) as $method) {
            if (strtolower($method->name->toString()) !== 'up') {
                continue;
            }
            foreach ($finder->find($method->stmts ?? [], fn (Node $n) => $this->isSchemaCall($n)) as $call) {
                /** @var Expr\StaticCall|MethodCall $call */
                $this->schemaCall($call, $file);
            }
        }
    }

    /** @return array<string, array<string, Field>> */
    public function tables(): array
    {
        return $this->tables;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** Schema::create(...) or Schema::connection('x')->create(...). */
    private function isSchemaCall(Node $node): bool
    {
        if ($node instanceof Expr\StaticCall && $node->class instanceof Node\Name) {
            return Naming::classBasename(Ast::resolvedName($node->class)) === 'Schema'
                && in_array(Ast::methodName($node), ['create', 'table', 'drop', 'dropIfExists', 'rename'], true);
        }
        if ($node instanceof MethodCall && $node->var instanceof Expr\StaticCall && $node->var->class instanceof Node\Name) {
            return Naming::classBasename(Ast::resolvedName($node->var->class)) === 'Schema'
                && Ast::methodName($node->var) === 'connection'
                && in_array(Ast::methodName($node), ['create', 'table', 'drop', 'dropIfExists', 'rename'], true);
        }
        return false;
    }

    private function schemaCall(Expr\StaticCall|MethodCall $call, string $file): void
    {
        $method = Ast::methodName($call);
        $table = Ast::string(Ast::arg($call->args, 0));
        if ($table === null) {
            $this->warnings[] = "$file: skipped Schema::$method() with a table name that is not a string literal";
            return;
        }

        switch ($method) {
            case 'drop':
            case 'dropIfExists':
                unset($this->tables[$table]);
                return;
            case 'rename':
                $to = Ast::string(Ast::arg($call->args, 1));
                if ($to !== null && isset($this->tables[$table])) {
                    $renamed = [];
                    foreach ($this->tables as $name => $fields) {
                        $renamed[$name === $table ? $to : $name] = $fields;
                    }
                    $this->tables = $renamed;
                }
                return;
            case 'create':
                $this->tables[$table] = [];
                break;
            case 'table':
                // A table created outside the scanned migrations (e.g. by a package).
                $this->tables[$table] ??= [];
                break;
        }

        $closure = Ast::arg($call->args, 1);
        if (!$closure instanceof Expr\Closure && !$closure instanceof Expr\ArrowFunction) {
            return;
        }
        $param = $closure->params[0]->var ?? null;
        if (!$param instanceof Expr\Variable || !is_string($param->name)) {
            return;
        }

        $statements = $closure instanceof Expr\ArrowFunction
            ? [new Node\Stmt\Expression($closure->expr)]
            : $closure->stmts;
        foreach ((new NodeFinder())->findInstanceOf($statements, Node\Stmt\Expression::class) as $statement) {
            $chain = Ast::chainOn($statement->expr, $param->name);
            if ($chain !== null) {
                $this->blueprintChain($table, $chain);
            }
        }
    }

    /** @param list<MethodCall> $chain */
    private function blueprintChain(string $table, array $chain): void
    {
        $root = $chain[0];
        $method = Ast::methodName($root);
        $modifiers = array_slice($chain, 1);

        if (isset(self::COLUMN_TYPES[$method])) {
            $default = isset(self::DEFAULT_ID_COLUMN[$method]) ? 'id' : null;
            $column = Ast::string(Ast::arg($root->args, 0, 'column')) ?? $default;
            if ($column !== null) {
                [$type, $autoPk] = self::COLUMN_TYPES[$method];
                $this->column($table, new Field($column, $type, primaryKey: $autoPk), $modifiers);
            }
            return;
        }

        match ($method) {
            'foreignIdFor' => $this->foreignIdFor($table, $root, $modifiers),
            'timestamps', 'nullableTimestamps' => $this->addNullable($table, ['created_at', 'updated_at'], 'timestamp'),
            'timestampsTz' => $this->addNullable($table, ['created_at', 'updated_at'], 'timestamptz'),
            'softDeletes', 'softDeletesDatetime' => $this->addNullable($table, [Ast::string(Ast::arg($root->args, 0, 'column')) ?? 'deleted_at'], 'timestamp'),
            'softDeletesTz' => $this->addNullable($table, [Ast::string(Ast::arg($root->args, 0, 'column')) ?? 'deleted_at'], 'timestamptz'),
            'rememberToken' => $this->addNullable($table, ['remember_token'], 'string'),
            'morphs', 'numericMorphs' => $this->morphs($table, $root, 'bigint', false),
            'nullableMorphs', 'nullableNumericMorphs' => $this->morphs($table, $root, 'bigint', true),
            'uuidMorphs' => $this->morphs($table, $root, 'uuid', false),
            'nullableUuidMorphs' => $this->morphs($table, $root, 'uuid', true),
            'ulidMorphs' => $this->morphs($table, $root, 'ulid', false),
            'nullableUlidMorphs' => $this->morphs($table, $root, 'ulid', true),
            'primary' => $this->setFlag($table, Ast::strings(Ast::arg($root->args, 0)), 'primaryKey', true, singleOnly: false),
            'unique' => $this->setFlag($table, Ast::strings(Ast::arg($root->args, 0)), 'unique', true),
            'index' => $this->setFlag($table, Ast::strings(Ast::arg($root->args, 0)), 'index', true),
            'foreign' => $this->foreign($table, Ast::strings(Ast::arg($root->args, 0)), $modifiers),
            'dropColumn', 'dropColumns' => $this->dropColumns($table, Ast::stringArgs($root->args)),
            'renameColumn' => $this->renameColumn($table, Ast::string(Ast::arg($root->args, 0)), Ast::string(Ast::arg($root->args, 1))),
            'dropForeign' => $this->dropKey($table, $root, 'foreign'),
            'dropUnique' => $this->dropKey($table, $root, 'unique'),
            'dropIndex' => $this->dropKey($table, $root, 'index'),
            'dropPrimary' => $this->dropPrimary($table, $root),
            'dropConstrainedForeignId' => $this->dropColumns($table, Ast::strings(Ast::arg($root->args, 0))),
            'dropForeignIdFor', 'dropConstrainedForeignIdFor' => $this->dropForeignIdFor($table, $root, $method === 'dropConstrainedForeignIdFor'),
            'dropTimestamps', 'dropTimestampsTz' => $this->dropColumns($table, ['created_at', 'updated_at']),
            'dropSoftDeletes', 'dropSoftDeletesTz' => $this->dropColumns($table, [Ast::string(Ast::arg($root->args, 0)) ?? 'deleted_at']),
            'dropRememberToken' => $this->dropColumns($table, ['remember_token']),
            'dropMorphs' => $this->dropMorphs($table, $root),
            default => null,
        };
    }

    /** @param list<MethodCall> $modifiers */
    private function column(string $table, Field $field, array $modifiers): void
    {
        $isChange = false;
        // A table guessed up front (foreignIdFor) is only used by constrained().
        $guessedTable = $field->foreignTable;
        $field->foreignTable = null;
        $refColumn = 'id';
        /** @var string|null $after column to insert after; '' for first() */
        $after = null;
        foreach ($modifiers as $modifier) {
            $args = $modifier->args;
            switch (Ast::methodName($modifier)) {
                case 'nullable':
                    $field->nullable = Ast::bool(Ast::arg($args, 0), true);
                    break;
                case 'default':
                    $value = Ast::arg($args, 0);
                    $field->default = $value !== null ? Ast::defaultText($value) : null;
                    break;
                case 'useCurrent':
                    $field->default = 'CURRENT_TIMESTAMP';
                    break;
                case 'unique':
                    $field->unique = true;
                    break;
                case 'index':
                    $field->index = true;
                    break;
                case 'primary':
                    $field->primaryKey = true;
                    break;
                case 'constrained':
                    $refColumn = Ast::string(Ast::arg($args, 1, 'column')) ?? 'id';
                    $target = Ast::string(Ast::arg($args, 0, 'table'))
                        ?? $guessedTable
                        ?? Naming::constrainedTable($field->name, $refColumn);
                    $field->foreignKey = true;
                    $field->foreignTable = "$target.$refColumn";
                    break;
                case 'references':
                    $refColumn = Ast::strings(Ast::arg($args, 0))[0] ?? 'id';
                    if ($field->foreignTable !== null) {
                        $field->foreignTable = explode('.', $field->foreignTable)[0] . ".$refColumn";
                    }
                    break;
                case 'on':
                    $target = Ast::string(Ast::arg($args, 0));
                    if ($target !== null) {
                        $field->foreignKey = true;
                        $field->foreignTable = "$target.$refColumn";
                    }
                    break;
                case 'change':
                    $isChange = true;
                    break;
                case 'after':
                    $after = Ast::string(Ast::arg($args, 0));
                    break;
                case 'first':
                    $after = '';
                    break;
            }
        }

        $existing = $this->tables[$table][$field->name] ?? null;
        if ($isChange && $existing !== null) {
            // change() restates the column: take its type and modifiers, keep keys.
            $existing->type = $field->type;
            $existing->nullable = $field->nullable;
            $existing->default = $field->default;
            $existing->unique = $existing->unique || $field->unique;
            $existing->index = $existing->index || $field->index;
            if ($field->foreignKey) {
                $existing->foreignKey = true;
                $existing->foreignTable = $field->foreignTable;
            }
            return;
        }
        if ($after === null || ($after !== '' && !isset($this->tables[$table][$after]))) {
            $this->tables[$table][$field->name] = $field;
            return;
        }
        // ->after('email') / ->first(): insert at that position.
        unset($this->tables[$table][$field->name]);
        $reordered = $after === '' ? [$field->name => $field] : [];
        foreach ($this->tables[$table] as $name => $existingField) {
            $reordered[$name] = $existingField;
            if ($name === $after) {
                $reordered[$field->name] = $field;
            }
        }
        $this->tables[$table] = $reordered;
    }

    /** @param list<MethodCall> $modifiers */
    private function foreignIdFor(string $table, MethodCall $root, array $modifiers): void
    {
        $model = Ast::className(Ast::arg($root->args, 0, 'model'));
        if ($model === null) {
            return;
        }
        $column = Ast::string(Ast::arg($root->args, 1, 'column')) ?? Naming::foreignKey($model);
        $field = new Field($column, 'bigint');
        // constrained() with no table uses the model's table (Model::getTable()).
        $field->foreignTable = ($this->modelTable)($model) ?? Naming::modelTable($model);
        $this->column($table, $field, $modifiers);
    }

    /** @param list<string> $columns */
    private function addNullable(string $table, array $columns, string $type): void
    {
        foreach ($columns as $column) {
            $this->tables[$table][$column] = new Field($column, $type, nullable: true);
        }
    }

    private function morphs(string $table, MethodCall $root, string $idType, bool $nullable): void
    {
        $name = Ast::string(Ast::arg($root->args, 0, 'name'));
        if ($name === null) {
            return;
        }
        $this->tables[$table]["{$name}_type"] = new Field("{$name}_type", 'string', nullable: $nullable, index: true);
        $this->tables[$table]["{$name}_id"] = new Field("{$name}_id", $idType, nullable: $nullable, index: true);
    }

    /**
     * Table-level primary/unique/index. Unique and index only mark a column for
     * a single-column key: composite ones are not drawn (as upstream).
     *
     * @param list<string> $columns
     */
    private function setFlag(string $table, array $columns, string $flag, bool $value, bool $singleOnly = true): void
    {
        if ($singleOnly && count($columns) !== 1) {
            return;
        }
        foreach ($columns as $column) {
            if (isset($this->tables[$table][$column])) {
                $this->tables[$table][$column]->{$flag} = $value;
            }
        }
    }

    /**
     * $table->foreign('user_id')->references('id')->on('users'). Composite
     * foreign keys are skipped, as upstream does.
     *
     * @param list<string> $columns
     * @param list<MethodCall> $modifiers
     */
    private function foreign(string $table, array $columns, array $modifiers): void
    {
        if (count($columns) !== 1 || !isset($this->tables[$table][$columns[0]])) {
            return;
        }
        $referenced = 'id';
        $target = null;
        foreach ($modifiers as $modifier) {
            $name = Ast::methodName($modifier);
            if ($name === 'references') {
                $referenced = Ast::strings(Ast::arg($modifier->args, 0))[0] ?? 'id';
            } elseif ($name === 'on') {
                $target = Ast::string(Ast::arg($modifier->args, 0));
            }
        }
        if ($target !== null) {
            $field = $this->tables[$table][$columns[0]];
            $field->foreignKey = true;
            $field->foreignTable = "$target.$referenced";
        }
    }

    /** @param list<string> $columns */
    private function dropColumns(string $table, array $columns): void
    {
        foreach ($columns as $column) {
            unset($this->tables[$table][$column]);
        }
    }

    private function renameColumn(string $table, ?string $from, ?string $to): void
    {
        if ($from === null || $to === null || !isset($this->tables[$table][$from])) {
            return;
        }
        $renamed = [];
        foreach ($this->tables[$table] as $name => $field) {
            if ($name === $from) {
                $field->name = $to;
                $name = $to;
            }
            $renamed[$name] = $field;
        }
        $this->tables[$table] = $renamed;
    }

    /**
     * dropForeign(['user_id']) or dropForeign('posts_user_id_foreign'): Laravel
     * names keys "<table>_<columns>_<type>", so a name maps back to its column.
     */
    private function dropKey(string $table, MethodCall $root, string $type): void
    {
        $arg = Ast::arg($root->args, 0);
        $columns = $arg instanceof Expr\Array_ ? Ast::strings($arg) : [];
        $name = Ast::string($arg);
        if ($name !== null) {
            $prefix = "{$table}_";
            $suffix = "_$type";
            if (str_starts_with($name, $prefix) && str_ends_with($name, $suffix)) {
                $columns = [substr($name, strlen($prefix), -strlen($suffix))];
            }
        }
        foreach ($columns as $column) {
            $field = $this->tables[$table][$column] ?? null;
            if ($field === null) {
                continue;
            }
            if ($type === 'foreign') {
                $field->foreignKey = false;
                $field->foreignTable = null;
            } elseif ($type === 'unique') {
                $field->unique = false;
            } else {
                $field->index = false;
            }
        }
    }

    private function dropPrimary(string $table, MethodCall $root): void
    {
        $arg = Ast::arg($root->args, 0);
        $only = $arg instanceof Expr\Array_ ? Ast::strings($arg) : null;
        foreach ($this->tables[$table] ?? [] as $name => $field) {
            if ($only === null || in_array($name, $only, true)) {
                $field->primaryKey = false;
            }
        }
    }

    private function dropForeignIdFor(string $table, MethodCall $root, bool $dropColumn): void
    {
        $model = Ast::className(Ast::arg($root->args, 0, 'model'));
        if ($model === null) {
            return;
        }
        $column = Ast::string(Ast::arg($root->args, 1, 'column')) ?? Naming::foreignKey($model);
        if ($dropColumn) {
            $this->dropColumns($table, [$column]);
        } elseif (isset($this->tables[$table][$column])) {
            $this->tables[$table][$column]->foreignKey = false;
            $this->tables[$table][$column]->foreignTable = null;
        }
    }

    private function dropMorphs(string $table, MethodCall $root): void
    {
        $name = Ast::string(Ast::arg($root->args, 0));
        if ($name !== null) {
            $this->dropColumns($table, ["{$name}_type", "{$name}_id"]);
        }
    }
}
