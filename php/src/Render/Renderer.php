<?php

declare(strict_types=1);

namespace Mrseams\Erdify\Render;

use Mrseams\Erdify\Model\Schema;

interface Renderer
{
    /** Rendered text, with no trailing newline. */
    public function render(Schema $schema, RenderOptions $options = new RenderOptions()): string;
}
