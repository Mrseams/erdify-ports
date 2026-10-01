<?php

declare(strict_types=1);

namespace Mrseams\Erdify\Laravel;

use Mrseams\Erdify\Model\Relationship;

/** What the scanner learned about one concrete Eloquent model. */
final class EloquentModel
{
    /**
     * @param list<Relationship> $relationships
     * @param array<string, string> $enumCasts column => enum class (fully qualified)
     */
    public function __construct(
        public readonly string $class,
        public readonly string $table,
        public readonly array $relationships,
        public readonly array $enumCasts,
    ) {
    }

    public function name(): string
    {
        return Naming::classBasename($this->class);
    }
}
