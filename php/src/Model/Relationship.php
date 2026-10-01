<?php

declare(strict_types=1);

namespace Mrseams\Erdify\Model;

/** A declared relationship, e.g. an Eloquent hasMany() method. */
final class Relationship
{
    public const ONE = 'one';
    public const MANY = 'many';
    public const ONE_TO_ONE = 'one_to_one';
    public const MANY_TO_MANY = 'many_to_many';

    public function __construct(
        /** Target entity name (class name, not table name). */
        public string $target,
        /** One of the constants above. */
        public string $type,
        /** Attribute or method name, used as the edge label. */
        public string $attribute,
    ) {
    }
}
