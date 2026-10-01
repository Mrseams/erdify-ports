<?php

declare(strict_types=1);

namespace Mrseams\Erdify\Model;

/** A database column. Mirrors erdify's FieldInfo. */
final class Field
{
    public function __construct(
        public string $name,
        /** Type as written in the source, e.g. "bigint" or "string". May be empty. */
        public string $type = '',
        public bool $primaryKey = false,
        public bool $foreignKey = false,
        public bool $nullable = false,
        /** "table.column" (or "table") the foreign key points to. */
        public ?string $foreignTable = null,
        public bool $index = false,
        /** Default value as source text. */
        public ?string $default = null,
        public bool $unique = false,
    ) {
    }
}
