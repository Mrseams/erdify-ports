<?php

declare(strict_types=1);

namespace Mrseams\Erdify\Render;

use Mrseams\Erdify\Model\Schema;

/** A self-contained page that draws the Mermaid diagram from a pinned CDN. */
final class HtmlRenderer implements Renderer
{
    public const MERMAID_CDN = 'https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.min.js';

    public function render(Schema $schema, RenderOptions $options = new RenderOptions()): string
    {
        $diagram = (new MermaidRenderer())->render($schema, $options);
        $title = self::escape($schema->title);

        return "<!DOCTYPE html>\n"
            . "<html lang=\"en\">\n"
            . "<head>\n"
            . "  <meta charset=\"utf-8\">\n"
            . "  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
            . "  <title>$title</title>\n"
            . '  <script src="' . self::MERMAID_CDN . "\"></script>\n"
            . '  <style>body{font-family:system-ui,sans-serif;margin:2rem;color:#222}'
            . "h1{font-size:1.1rem;color:#555;font-weight:600}</style>\n"
            . "</head>\n"
            . "<body>\n"
            . "  <h1>$title</h1>\n"
            . "  <pre class=\"mermaid\">\n"
            . "$diagram\n"
            . "  </pre>\n"
            . "  <script>mermaid.initialize({ startOnLoad: true });</script>\n"
            . "</body>\n"
            . "</html>\n";
    }

    /** Python's html.escape(s, quote=True), which differs from htmlspecialchars for "'". */
    private static function escape(string $text): string
    {
        return strtr($text, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "'" => '&#x27;']);
    }
}
