<?php

declare(strict_types=1);

namespace Mrseams\Erdify\Render;

use Mrseams\Erdify\Model\Entity;
use Mrseams\Erdify\Model\EnumType;
use Mrseams\Erdify\Model\Field;
use Mrseams\Erdify\Model\Relationship;
use Mrseams\Erdify\Model\Schema;

/**
 * Relationship lines and shared helpers, ported from erdify's _ERGenerator.
 *
 * The crow's-foot tokens (`}o--||`, `||--o{`, `||--||`, `}o--o{`) are valid in
 * both PlantUML and Mermaid, so both renderers share this. Keep the order and
 * the de-duplication rules identical to upstream: `--check` compares bytes.
 */
final class Relationships
{
    /** Text after the last ".", like Python's `s.split(".")[-1]`. */
    public static function lastSegment(string $text): string
    {
        $dot = strrpos($text, '.');
        return $dot === false ? $text : substr($text, $dot + 1);
    }

    /** Text before the first ".", like Python's `s.split(".")[0]`. */
    public static function firstSegment(string $text): string
    {
        $dot = strpos($text, '.');
        return $dot === false ? $text : substr($text, 0, $dot);
    }

    /**
     * Enums referenced by a field type, sorted by name (code point order).
     *
     * @param array<string, EnumType> $enums
     * @return list<string>
     */
    public static function usedEnums(Schema $schema, array $enums): array
    {
        $used = [];
        foreach ($schema->entities as $entity) {
            foreach ($entity->fields as $field) {
                $name = self::lastSegment($field->type);
                if (array_key_exists($name, $enums)) {
                    $used[$name] = true;
                }
            }
        }
        $names = array_map('strval', array_keys($used));
        usort($names, 'strcmp');
        return $names;
    }

    /** @return list<string> */
    public static function lines(Schema $schema): array
    {
        $linkTableMap = self::linkTableMap($schema);
        $lines = [];
        $seen = [];

        foreach ($schema->entities as $entity) {
            if (!$entity->isLinkTable) {
                foreach (self::directLines($schema, $entity) as $line) {
                    if (!isset($seen[$line])) {
                        $lines[] = $line;
                        $seen[$line] = true;
                    }
                }
            }
        }

        foreach ($schema->entities as $linkEntity) {
            if ($linkEntity->isLinkTable) {
                foreach (self::linkTableLines($schema, $linkEntity) as $line) {
                    if (!isset($seen[$line])) {
                        $lines[] = $line;
                        $seen[$line] = true;
                    }
                }
            }
        }

        $connected = self::connectedPairs($schema);
        foreach ($linkTableMap as [$tableA, $tableB]) {
            $a = $schema->entityByTable($tableA);
            $b = $schema->entityByTable($tableB);
            if ($a !== null && $b !== null) {
                $connected[self::pair($a->name, $b->name)] = true;
            }
        }

        foreach ($schema->entities as $entity) {
            foreach (self::declaredLines($schema, $entity) as [$line, $pair]) {
                if (isset($connected[$pair]) || isset($seen[$line])) {
                    continue;
                }
                $lines[] = $line;
                $seen[$line] = true;
                $connected[$pair] = true;
            }
        }

        return $lines;
    }

    /** An unordered pair of entity names, like Python's frozenset((a, b)). */
    private static function pair(string $a, string $b): string
    {
        if ($a === $b) {
            return $a;
        }
        return strcmp($a, $b) < 0 ? "$a\0$b" : "$b\0$a";
    }

    /**
     * Binary link tables as [table1, table2] pairs, both directions.
     *
     * @return list<array{string, string}>
     */
    private static function linkTableMap(Schema $schema): array
    {
        $map = [];
        foreach ($schema->entities as $entity) {
            if (!$entity->isLinkTable || count($entity->fields) !== 2) {
                continue;
            }
            $fks = array_values(array_filter($entity->fields, static fn (Field $f) => $f->foreignKey));
            if (count($fks) !== 2) {
                continue;
            }
            $t1 = self::nonEmpty($fks[0]->foreignTable) ? self::firstSegment($fks[0]->foreignTable) : null;
            $t2 = self::nonEmpty($fks[1]->foreignTable) ? self::firstSegment($fks[1]->foreignTable) : null;
            if (self::nonEmpty($t1) && self::nonEmpty($t2)) {
                // Python dict semantics: re-setting a key keeps its first position.
                $map["$t1\0$t2"] ??= [$t1, $t2];
                $map["$t2\0$t1"] ??= [$t2, $t1];
            }
        }
        return array_values($map);
    }

    /** @phpstan-assert-if-true non-empty-string $value */
    private static function nonEmpty(?string $value): bool
    {
        return $value !== null && $value !== '';
    }

    /** Crow's-foot glyph for a direct foreign key, written `child ... parent`. */
    private static function fkGlyph(Field $field): string
    {
        if (!$field->unique) {
            return '}o--||';
        }
        return $field->nullable ? '|o--o|' : '|o--||';
    }

    /** @return array<string, true> */
    private static function connectedPairs(Schema $schema): array
    {
        $pairs = [];
        foreach ($schema->entities as $entity) {
            foreach ($entity->fields as $field) {
                if ($field->foreignKey && self::nonEmpty($field->foreignTable)) {
                    $target = $schema->entityByTable(self::firstSegment($field->foreignTable));
                    if ($target !== null) {
                        $pairs[self::pair($entity->name, $target->name)] = true;
                    }
                }
            }
        }
        return $pairs;
    }

    /** @return list<string> */
    private static function directLines(Schema $schema, Entity $entity): array
    {
        $lines = [];
        foreach ($entity->fields as $field) {
            if ($field->foreignKey && self::nonEmpty($field->foreignTable)) {
                $target = $schema->entityByTable(self::firstSegment($field->foreignTable));
                if ($target !== null) {
                    $lines[] = sprintf('%s %s %s : "%s"', $entity->name, self::fkGlyph($field), $target->name, $field->name);
                }
            }
        }
        return $lines;
    }

    /**
     * A binary link table is drawn as the M:N pass-through path; a ternary or
     * larger one as a star of edges from each parent (erdify #118).
     *
     * @return list<string>
     */
    private static function linkTableLines(Schema $schema, Entity $link): array
    {
        $fks = array_values(array_filter($link->fields, static fn (Field $f) => $f->foreignKey));
        if (count($fks) < 2) {
            return [];
        }

        $targets = [];
        foreach ($fks as $fk) {
            if (self::nonEmpty($fk->foreignTable)) {
                $target = $schema->entityByTable(self::firstSegment($fk->foreignTable));
                if ($target !== null) {
                    $targets[] = [$target, $fk->name];
                }
            }
        }

        $lines = [];
        if (count($fks) === 2) {
            if (count($targets) === 2) {
                [[$e1, $fk1], [$e2, $fk2]] = $targets;
                $lines[] = sprintf('%s ||--o{ %s : "%s"', $e1->name, $link->name, $fk1);
                $lines[] = sprintf('%s }o--|| %s : "%s"', $link->name, $e2->name, $fk2);
            }
        } else {
            foreach ($targets as [$parent, $fkName]) {
                $lines[] = sprintf('%s ||--o{ %s : "%s"', $parent->name, $link->name, $fkName);
            }
        }
        return $lines;
    }

    /** @return list<array{string, string}> [line, pair] */
    private static function declaredLines(Schema $schema, Entity $entity): array
    {
        $results = [];
        foreach ($entity->relationships as $rel) {
            $target = $schema->entities[$rel->target] ?? null;
            if ($target === null) {
                continue;
            }
            $glyph = match ($rel->type) {
                Relationship::MANY => '||--o{',
                Relationship::ONE_TO_ONE => '||--||',
                Relationship::MANY_TO_MANY => '}o--o{',
                default => '}o--||',
            };
            $results[] = [
                sprintf('%s %s %s : "%s"', $entity->name, $glyph, $target->name, $rel->attribute),
                self::pair($entity->name, $target->name),
            ];
        }
        return $results;
    }
}
