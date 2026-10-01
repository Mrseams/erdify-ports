<?php

declare(strict_types=1);

namespace Mrseams\Erdify\Model;

/**
 * Everything a renderer needs. Entities and enums are keyed by name and keep
 * insertion order, which the renderers depend on (like erdify's dicts).
 */
final class Schema
{
    /**
     * @param array<string, Entity> $entities
     * @param array<string, EnumType> $enums
     */
    public function __construct(
        public array $entities = [],
        public array $enums = [],
        public string $title = 'Database ERD',
    ) {
    }

    public function entityByTable(string $tableName): ?Entity
    {
        foreach ($this->entities as $entity) {
            if ($entity->tableName === $tableName) {
                return $entity;
            }
        }
        return null;
    }
}
