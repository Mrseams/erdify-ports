<?php

declare(strict_types=1);

namespace Mrseams\Erdify\Render;

use Mrseams\Erdify\Model\Entity;
use Mrseams\Erdify\Model\EnumType;
use Mrseams\Erdify\Model\Field;
use Mrseams\Erdify\Model\Schema;

/** Mermaid `erDiagram` source. Port of erdify's MermaidGenerator. */
final class MermaidRenderer implements Renderer
{
    public function render(Schema $schema, RenderOptions $options = new RenderOptions()): string
    {
        $enums = $options->includeEnums ? $schema->enums : [];
        $lines = ['erDiagram'];

        foreach ($schema->entities as $entity) {
            array_push($lines, ...$this->entity($entity));
        }

        // Mermaid has no enum construct: draw each used enum as an entity block.
        foreach (Relationships::usedEnums($schema, $enums) as $name) {
            array_push($lines, ...$this->enum($enums[$name]));
        }

        foreach (Relationships::lines($schema) as $line) {
            $lines[] = "    $line";
        }

        return implode("\n", $lines);
    }

    /** @return list<string> */
    private function entity(Entity $entity): array
    {
        $lines = ["    {$entity->name} {"];
        foreach ($entity->fields as $field) {
            $lines[] = '        ' . $this->attribute($field);
        }
        $lines[] = '    }';
        return $lines;
    }

    /** @return list<string> */
    private function enum(EnumType $enum): array
    {
        $lines = ["    {$enum->name} {"];
        foreach ($enum->values as $value) {
            $lines[] = "        enum $value";
        }
        $lines[] = '    }';
        return $lines;
    }

    /** `<type> <name> [keys] ["comment"]` */
    private function attribute(Field $field): string
    {
        $keys = [];
        if ($field->primaryKey) {
            $keys[] = 'PK';
        }
        if ($field->foreignKey) {
            $keys[] = 'FK';
        }
        if ($field->unique && !$field->primaryKey) {
            $keys[] = 'UK';
        }

        $comment = [];
        if ($field->nullable) {
            $comment[] = 'nullable';
        }
        if ($field->default !== null) {
            $comment[] = 'default ' . Relationships::lastSegment($field->default);
        }

        $parts = [self::type($field->type), $field->name];
        if ($keys !== []) {
            $parts[] = implode(',', $keys);
        }
        $line = implode(' ', $parts);
        if ($comment !== []) {
            $line .= ' "' . implode(', ', $comment) . '"';
        }
        return $line;
    }

    /** A Mermaid-safe single token: `list[str]` -> `list_str`. */
    public static function type(string $type): string
    {
        $token = trim((string) preg_replace('/[^0-9A-Za-z_]+/', '_', Relationships::lastSegment($type)), '_');
        return $token !== '' ? $token : 'unknown';
    }
}
