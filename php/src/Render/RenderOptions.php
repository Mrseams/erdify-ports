<?php

declare(strict_types=1);

namespace Mrseams\Erdify\Render;

final class RenderOptions
{
    public function __construct(
        public readonly bool $includeEnums = true,
        /**
         * Only the JSON renderer honours this. Upstream erdify 0.14.1 ignores it
         * for PlantUML, Mermaid and HTML, and the ports match that on purpose
         * (see spec/cli.md, "Known upstream quirks").
         */
        public readonly bool $includeRelationships = true,
    ) {
    }
}
