<?php

declare(strict_types=1);

namespace Mrseams\Erdify\Laravel;

use Mrseams\Erdify\Model\EnumType;
use Mrseams\Erdify\Model\Relationship;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Finds Eloquent models and PHP enums in parsed files.
 *
 * A class is a model when its parent chain reaches Eloquent's Model (directly,
 * through Authenticatable/Pivot, or through a parent defined in the scanned
 * files). Relationship methods, `$table` and `$casts` are inherited from
 * parents and from traits defined in the scanned files.
 */
final class ModelScanner
{
    private const MODEL_BASES = [
        'Illuminate\Database\Eloquent\Model' => false,
        'Illuminate\Foundation\Auth\User' => false,
        'Illuminate\Database\Eloquent\Relations\Pivot' => true,
        'Illuminate\Database\Eloquent\Relations\MorphPivot' => true,
    ];

    /** Short names accepted when the parent's import cannot be resolved. */
    private const MODEL_BASE_NAMES = ['Model' => false, 'Authenticatable' => false, 'Pivot' => true, 'MorphPivot' => true];

    /** Eloquent method => relationship type. Through and morphTo relations are not drawn. */
    private const RELATIONS = [
        'belongsTo' => Relationship::ONE,
        'hasOne' => Relationship::ONE_TO_ONE,
        'morphOne' => Relationship::ONE_TO_ONE,
        'hasMany' => Relationship::MANY,
        'morphMany' => Relationship::MANY,
        'belongsToMany' => Relationship::MANY_TO_MANY,
        'morphToMany' => Relationship::MANY_TO_MANY,
        'morphedByMany' => Relationship::MANY_TO_MANY,
    ];

    /** @var array<string, Stmt\Class_> */
    private array $classes = [];

    /** @var array<string, Stmt\Trait_> */
    private array $traits = [];

    /** @var array<string, EnumType> fully qualified name => enum */
    private array $enums = [];

    /** @param list<Stmt> $ast a parsed file, after NameResolver */
    public function add(array $ast): void
    {
        $finder = new NodeFinder();
        foreach ($finder->find($ast, static fn (Node $n) => $n instanceof Stmt\ClassLike && $n->name !== null) as $node) {
            /** @var Stmt\ClassLike $node */
            $fqcn = $node->namespacedName?->toString() ?? $node->name->toString();
            if ($node instanceof Stmt\Class_) {
                $this->classes[$fqcn] ??= $node;
            } elseif ($node instanceof Stmt\Trait_) {
                $this->traits[$fqcn] ??= $node;
            } elseif ($node instanceof Stmt\Enum_) {
                $cases = [];
                foreach ($node->stmts as $stmt) {
                    if ($stmt instanceof Stmt\EnumCase) {
                        $cases[] = $stmt->name->toString();
                    }
                }
                $this->enums[$fqcn] ??= new EnumType($node->name->toString(), $cases);
            }
        }
    }

    /** @return array<string, EnumType> keyed by fully qualified name, in discovery order */
    public function enums(): array
    {
        return $this->enums;
    }

    /** @return list<EloquentModel> concrete models, in discovery order */
    public function models(): array
    {
        $models = [];
        foreach ($this->classes as $fqcn => $class) {
            $isPivot = $this->modelKind($fqcn);
            if ($isPivot === null || $class->isAbstract()) {
                continue;
            }
            $chain = $this->chain($fqcn);
            $table = $this->stringProperty($chain, 'table') ?? Naming::modelTable($fqcn, $isPivot);
            $models[] = new EloquentModel($fqcn, $table, $this->relationships($chain), $this->enumCasts($chain));
        }
        return $models;
    }

    /** null if not a model, otherwise whether it is a pivot model. */
    private function modelKind(string $fqcn): ?bool
    {
        $seen = [];
        while (isset($this->classes[$fqcn]) && !isset($seen[$fqcn])) {
            $seen[$fqcn] = true;
            $parent = $this->classes[$fqcn]->extends;
            if ($parent === null) {
                return null;
            }
            $fqcn = ltrim(Ast::resolvedName($parent), '\\');
        }
        if (array_key_exists($fqcn, self::MODEL_BASES)) {
            return self::MODEL_BASES[$fqcn];
        }
        return self::MODEL_BASE_NAMES[Naming::classBasename($fqcn)] ?? null;
    }

    /**
     * The class and its scanned ancestors and traits, most derived last, so
     * later entries override earlier ones.
     *
     * @return list<Stmt\ClassLike>
     */
    private function chain(string $fqcn): array
    {
        $classes = [];
        $seen = [];
        while (isset($this->classes[$fqcn]) && !isset($seen[$fqcn])) {
            $seen[$fqcn] = true;
            array_unshift($classes, $this->classes[$fqcn]);
            $parent = $this->classes[$fqcn]->extends;
            if ($parent === null) {
                break;
            }
            $fqcn = ltrim(Ast::resolvedName($parent), '\\');
        }

        $chain = [];
        foreach ($classes as $class) {
            // Traits first, so the class's own members override them.
            array_push($chain, ...$this->traitsOf($class, []));
            $chain[] = $class;
        }
        return $chain;
    }

    /**
     * @param array<string, true> $seen
     * @return list<Stmt\Trait_>
     */
    private function traitsOf(Stmt\ClassLike $class, array $seen): array
    {
        $out = [];
        foreach ($class->getTraitUses() as $use) {
            foreach ($use->traits as $name) {
                $fqcn = ltrim(Ast::resolvedName($name), '\\');
                if (isset($this->traits[$fqcn]) && !isset($seen[$fqcn])) {
                    $seen[$fqcn] = true;
                    array_push($out, ...$this->traitsOf($this->traits[$fqcn], $seen));
                    $out[] = $this->traits[$fqcn];
                }
            }
        }
        return $out;
    }

    /** @param list<Stmt\ClassLike> $chain */
    private function stringProperty(array $chain, string $name): ?string
    {
        $value = null;
        foreach ($chain as $class) {
            $property = $class->getProperty($name);
            $default = $property?->props[0]->default;
            if ($default !== null && Ast::string($default) !== null) {
                $value = Ast::string($default);
            }
        }
        return $value;
    }

    /**
     * @param list<Stmt\ClassLike> $chain
     * @return list<Relationship>
     */
    private function relationships(array $chain): array
    {
        /** @var array<string, Relationship> $byMethod */
        $byMethod = [];
        foreach ($chain as $class) {
            foreach ($class->getMethods() as $method) {
                $name = $method->name->toString();
                $relationship = $this->relationshipFrom($method);
                if ($relationship !== null) {
                    $byMethod[$name] = $relationship;
                } else {
                    unset($byMethod[$name]); // overridden by a non-relationship method
                }
            }
        }
        return array_values($byMethod);
    }

    private function relationshipFrom(Stmt\ClassMethod $method): ?Relationship
    {
        if ($method->isStatic() || $method->stmts === null) {
            return null;
        }
        foreach ((new NodeFinder())->findInstanceOf($method->stmts, Stmt\Return_::class) as $return) {
            $chain = $return->expr !== null ? Ast::chainOn($return->expr, 'this') : null;
            $call = $chain[0] ?? null;
            if ($call === null) {
                continue;
            }
            $type = self::RELATIONS[Ast::methodName($call)] ?? null;
            $target = Ast::className(Ast::arg($call->args, 0, 'related'));
            if ($type !== null && $target !== null) {
                return new Relationship(Naming::classBasename($target), $type, $method->name->toString());
            }
        }
        return null;
    }

    /**
     * Columns cast to an enum defined in the scanned files, from `$casts` or
     * from a `casts()` method (Laravel 11+).
     *
     * @param list<Stmt\ClassLike> $chain
     * @return array<string, string>
     */
    private function enumCasts(array $chain): array
    {
        $casts = [];
        foreach ($chain as $class) {
            $arrays = [];
            $property = $class->getProperty('casts')?->props[0]->default;
            if ($property instanceof Expr\Array_) {
                $arrays[] = $property;
            }
            $method = $class->getMethod('casts');
            if ($method !== null && $method->stmts !== null) {
                foreach ((new NodeFinder())->findInstanceOf($method->stmts, Stmt\Return_::class) as $return) {
                    if ($return->expr instanceof Expr\Array_) {
                        $arrays[] = $return->expr;
                    }
                }
            }
            foreach ($arrays as $array) {
                foreach ($array->items as $item) {
                    $column = Ast::string($item?->key);
                    $enum = $item?->value instanceof Expr\ClassConstFetch ? Ast::className($item->value) : null;
                    if ($column !== null && $enum !== null && isset($this->enums[$enum])) {
                        $casts[$column] = $enum;
                    }
                }
            }
        }
        return $casts;
    }
}
