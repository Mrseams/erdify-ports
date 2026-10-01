<?php

declare(strict_types=1);

namespace Mrseams\Erdify\Laravel;

use Doctrine\Inflector\Inflector;
use Doctrine\Inflector\InflectorFactory;

/**
 * Laravel's naming conventions, reproduced without depending on Laravel:
 * Str::snake, Str::studly, Str::plural/singular (Pluralizer), pluralStudly,
 * and the table / foreign key / pivot table names Eloquent derives from them.
 */
final class Naming
{
    /** Pluralizer::$uncountable in Laravel, on top of Doctrine's own list. */
    private const UNCOUNTABLE = ['recommended', 'related'];

    private static ?Inflector $inflector = null;

    private static function inflector(): Inflector
    {
        return self::$inflector ??= InflectorFactory::create()->build();
    }

    public static function snake(string $value): string
    {
        if (ctype_lower($value)) {
            return $value;
        }
        $value = (string) preg_replace('/\s+/u', '', ucwords($value));
        return mb_strtolower((string) preg_replace('/(.)(?=[A-Z])/u', '$1_', $value), 'UTF-8');
    }

    public static function studly(string $value): string
    {
        $words = explode(' ', str_replace(['-', '_'], ' ', $value));
        return implode('', array_map(ucfirst(...), $words));
    }

    public static function plural(string $value): string
    {
        if (in_array(strtolower($value), self::UNCOUNTABLE, true)) {
            return $value;
        }
        return self::matchCase(self::inflector()->pluralize($value), $value);
    }

    public static function singular(string $value): string
    {
        if (in_array(strtolower($value), self::UNCOUNTABLE, true)) {
            return $value;
        }
        return self::matchCase(self::inflector()->singularize($value), $value);
    }

    /** Pluralizes the last word of a StudlyCase name: OrderItem -> OrderItems. */
    public static function pluralStudly(string $value): string
    {
        $parts = preg_split('/(.)(?=[A-Z])/u', $value, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$value];
        $last = (string) array_pop($parts);
        return implode('', $parts) . self::plural($last);
    }

    public static function beforeLast(string $subject, string $search): string
    {
        if ($search === '') {
            return $subject;
        }
        $pos = strrpos($subject, $search);
        return $pos === false ? $subject : substr($subject, 0, $pos);
    }

    /** "App\Models\OrderItem" -> "OrderItem" */
    public static function classBasename(string $class): string
    {
        $pos = strrpos($class, '\\');
        return $pos === false ? $class : substr($class, $pos + 1);
    }

    /** Model::getTable() without a $table property. */
    public static function modelTable(string $class, bool $isPivot = false): string
    {
        $base = self::classBasename($class);
        return $isPivot ? self::snake(self::singular($base)) : self::snake(self::pluralStudly($base));
    }

    /** Model::getForeignKey(): "user_id". */
    public static function foreignKey(string $class, string $keyName = 'id'): string
    {
        return self::snake(self::classBasename($class)) . '_' . $keyName;
    }

    /** ForeignKeyDefinition::constrained() without a table: "user_id" -> "users". */
    public static function constrainedTable(string $column, string $referencedColumn = 'id'): string
    {
        return self::plural(self::beforeLast($column, '_' . $referencedColumn));
    }

    /** BelongsToMany::joiningTable() without a table: "role_user". */
    public static function pivotTable(string $classA, string $classB): string
    {
        $segments = [self::snake(self::classBasename($classA)), self::snake(self::classBasename($classB))];
        sort($segments);
        return strtolower(implode('_', $segments));
    }

    private static function matchCase(string $value, string $comparison): string
    {
        foreach (['mb_strtolower', 'mb_strtoupper', 'ucfirst', 'ucwords'] as $function) {
            if ($function($comparison) === $comparison) {
                return $function($value);
            }
        }
        return $value;
    }
}
