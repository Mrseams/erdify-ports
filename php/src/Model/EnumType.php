<?php

declare(strict_types=1);

namespace Mrseams\Erdify\Model;

/** An enum. Mirrors erdify's EnumInfo. */
final class EnumType
{
    /** @param list<string> $values */
    public function __construct(
        public string $name,
        public array $values = [],
    ) {
    }
}
