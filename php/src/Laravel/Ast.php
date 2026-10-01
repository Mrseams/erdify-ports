<?php

declare(strict_types=1);

namespace Mrseams\Erdify\Laravel;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\PrettyPrinter\Standard;

/**
 * Reads literal values out of the syntax tree. Nothing is ever evaluated:
 * anything that is not a literal comes back as null (or as source text, for
 * default values).
 */
final class Ast
{
    private static ?Standard $printer = null;

    /**
     * A call's argument, by position or by PHP 8 named argument.
     *
     * @param array<Arg|Node\VariadicPlaceholder> $args
     */
    public static function arg(array $args, int $position, ?string $name = null): ?Expr
    {
        foreach ($args as $arg) {
            if ($arg instanceof Arg && $name !== null && $arg->name?->toString() === $name) {
                return $arg->value;
            }
        }
        $positional = array_values(array_filter($args, static fn ($a) => $a instanceof Arg && $a->name === null));
        return isset($positional[$position]) ? $positional[$position]->value : null;
    }

    public static function string(?Expr $expr): ?string
    {
        return $expr instanceof Node\Scalar\String_ ? $expr->value : null;
    }

    public static function bool(?Expr $expr, bool $default): bool
    {
        if ($expr instanceof Expr\ConstFetch) {
            $name = strtolower($expr->name->toString());
            if ($name === 'true' || $name === 'false') {
                return $name === 'true';
            }
        }
        return $default;
    }

    /**
     * A string, or a list of strings from an array literal.
     *
     * @return list<string>
     */
    public static function strings(?Expr $expr): array
    {
        if ($expr instanceof Node\Scalar\String_) {
            return [$expr->value];
        }
        $out = [];
        if ($expr instanceof Expr\Array_) {
            foreach ($expr->items as $item) {
                $value = self::string($item?->value);
                if ($value !== null) {
                    $out[] = $value;
                }
            }
        }
        return $out;
    }

    /**
     * Every string argument of a variadic call such as dropColumn('a', 'b') or
     * dropColumn(['a', 'b']).
     *
     * @param array<Arg|Node\VariadicPlaceholder> $args
     * @return list<string>
     */
    public static function stringArgs(array $args): array
    {
        $out = [];
        foreach ($args as $arg) {
            if ($arg instanceof Arg) {
                array_push($out, ...self::strings($arg->value));
            }
        }
        return $out;
    }

    /** "Foo::class" or 'App\Models\Foo' -> the fully qualified class name. */
    public static function className(?Expr $expr): ?string
    {
        if (
            $expr instanceof Expr\ClassConstFetch
            && $expr->class instanceof Node\Name
            && $expr->name instanceof Node\Identifier
            && strtolower($expr->name->toString()) === 'class'
        ) {
            return ltrim(self::resolvedName($expr->class), '\\');
        }
        $string = self::string($expr);
        return $string !== null ? ltrim($string, '\\') : null;
    }

    /** The name after NameResolver ran, falling back to the name as written. */
    public static function resolvedName(Node\Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');
        return $resolved instanceof Node\Name ? $resolved->toString() : $name->toString();
    }

    /**
     * A default value as display text, in the convention the renderers expect:
     * strings keep their quotes, enum cases use a dot ("Status.Draft", shown as
     * "Draft", like erdify's "OrderStatus.PENDING"), raw SQL is unwrapped.
     */
    public static function defaultText(Expr $expr): string
    {
        // Status::Draft->value
        if ($expr instanceof Expr\PropertyFetch && $expr->var instanceof Expr\ClassConstFetch) {
            $expr = $expr->var;
        }
        if ($expr instanceof Expr\ClassConstFetch && $expr->class instanceof Node\Name && $expr->name instanceof Node\Identifier) {
            return Naming::classBasename(self::resolvedName($expr->class)) . '.' . $expr->name->toString();
        }
        if ($expr instanceof Expr\ConstFetch) {
            return strtolower($expr->name->toString());
        }
        // DB::raw('CURRENT_TIMESTAMP') and new Expression('...')
        if (
            ($expr instanceof Expr\StaticCall && $expr->name instanceof Node\Identifier && $expr->name->toString() === 'raw')
            || ($expr instanceof Expr\New_ && $expr->class instanceof Node\Name && Naming::classBasename($expr->class->toString()) === 'Expression')
        ) {
            $raw = self::string(self::arg($expr->args, 0));
            if ($raw !== null) {
                return $raw;
            }
        }
        return (self::$printer ??= new Standard())->prettyPrintExpr($expr);
    }

    /**
     * A method chain as a list of calls, root first:
     * `$table->string('x')->nullable()` -> [string('x'), nullable()].
     * Returns null unless the chain starts at the variable `$root`.
     *
     * @return list<Expr\MethodCall>|null
     */
    public static function chainOn(Expr $expr, string $root): ?array
    {
        $calls = [];
        while ($expr instanceof Expr\MethodCall || $expr instanceof Expr\NullsafeMethodCall) {
            if (!$expr instanceof Expr\MethodCall || !$expr->name instanceof Node\Identifier) {
                return null;
            }
            array_unshift($calls, $expr);
            $expr = $expr->var;
        }
        if ($calls === [] || !$expr instanceof Expr\Variable || $expr->name !== $root) {
            return null;
        }
        return $calls;
    }

    public static function methodName(Expr\MethodCall|Expr\StaticCall $call): string
    {
        return $call->name instanceof Node\Identifier ? $call->name->toString() : '';
    }
}
