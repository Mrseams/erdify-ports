<?php

declare(strict_types=1);

namespace Mrseams\Erdify\Render;

/** Format name -> renderer and file extension. Mirrors erdify.cli.FORMATS. */
final class Formats
{
    public const EXTENSIONS = [
        'plantuml' => '.puml',
        'mermaid' => '.mmd',
        'json' => '.json',
        'html' => '.html',
    ];

    public static function renderer(string $format): Renderer
    {
        return match ($format) {
            'plantuml' => new PlantUmlRenderer(),
            'mermaid' => new MermaidRenderer(),
            'json' => new JsonRenderer(),
            'html' => new HtmlRenderer(),
            default => throw new \InvalidArgumentException("Unknown format '$format'"),
        };
    }

    public static function exists(string $format): bool
    {
        return isset(self::EXTENSIONS[$format]);
    }
}
