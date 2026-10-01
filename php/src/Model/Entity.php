<?php

declare(strict_types=1);

namespace Mrseams\Erdify\Model;

/** A table. Mirrors erdify's EntityInfo. */
final class Entity
{
    /**
     * @param list<Field> $fields
     * @param list<Relationship> $relationships
     */
    public function __construct(
        /** Class name. Diagram alias and relationship target. */
        public string $name,
        /** Table name. Foreign keys resolve against this. */
        public string $tableName,
        public array $fields = [],
        public array $relationships = [],
        public bool $isLinkTable = false,
        public string $source = 'eloquent',
    ) {
    }

    public function field(string $name): ?Field
    {
        foreach ($this->fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }
        return null;
    }

    /**
     * A join table: a composite primary key made entirely of foreign keys, with
     * no other columns (two columns or more). Same rule as erdify's
     * is_structural_link_table.
     *
     * @param list<Field> $fields
     */
    public static function isStructuralLinkTable(array $fields): bool
    {
        if (count($fields) < 2) {
            return false;
        }
        foreach ($fields as $field) {
            if (!$field->foreignKey || !$field->primaryKey) {
                return false;
            }
        }
        return true;
    }
}
