<?php

declare(strict_types=1);

namespace Mrseams\Erdify\Render;

use Mrseams\Erdify\Model\Entity;
use Mrseams\Erdify\Model\EnumType;
use Mrseams\Erdify\Model\Field;
use Mrseams\Erdify\Model\Schema;

/** PlantUML ERD source. Port of erdify's PlantUMLGenerator. */
final class PlantUmlRenderer implements Renderer
{
    public function render(Schema $schema, RenderOptions $options = new RenderOptions()): string
    {
        $enums = $options->includeEnums ? $schema->enums : [];
        $lines = [
            "@startuml {$schema->title}",
            '!define Table(name,desc) class name as "desc" << (T,#FFAAAA) >>',
            '!define primary_key(x) <b><color:#b8861b><&key></color> x</b>',
            '!define foreign_key(x) <color:#aaaaaa><&key></color> x',
            '!define column(x) <color:#efefef><&media-record></color> x',
            '',
            'skinparam linetype ortho',
            'skinparam roundcorner 5',
            'skinparam class {',
            '    BackgroundColor White',
            '    ArrowColor Gray',
            '    BorderColor Gray',
            '}',
            '',
        ];

        $used = Relationships::usedEnums($schema, $enums);
        if ($used !== []) {
            $lines[] = "' Enums";
            $lines[] = '';
            foreach ($used as $name) {
                array_push($lines, ...$this->enum($enums[$name]));
                $lines[] = '';
            }
        }

        $lines[] = "' Entities";
        $lines[] = '';
        foreach ($schema->entities as $entity) {
            array_push($lines, ...$this->entity($entity));
            $lines[] = '';
        }

        $lines[] = "' Relationships";
        $lines[] = '';
        array_push($lines, ...Relationships::lines($schema));

        $lines[] = '';
        $lines[] = '@enduml';

        return implode("\n", $lines);
    }

    /** @return list<string> */
    private function entity(Entity $entity): array
    {
        $lines = [
            $entity->isLinkTable
                ? "entity \"{$entity->tableName}\" as {$entity->name} << (L, #AAFFAA) link >> {"
                : "entity \"{$entity->tableName}\" as {$entity->name} {",
        ];
        if ($entity->fields === []) {
            $lines[] = "  ' (no fields)";
        }
        foreach ($entity->fields as $field) {
            $lines[] = '  ' . $this->field($field);
        }
        $lines[] = '}';
        return $lines;
    }

    /** @return list<string> */
    private function enum(EnumType $enum): array
    {
        $lines = ["enum {$enum->name} << (E,#FFCC00) >> {"];
        foreach ($enum->values as $value) {
            $lines[] = "  $value";
        }
        $lines[] = '}';
        return $lines;
    }

    private function field(Field $field): string
    {
        $prefix = match (true) {
            $field->primaryKey => 'primary_key',
            $field->foreignKey => 'foreign_key',
            default => 'column',
        };
        $type = Relationships::lastSegment($field->type);
        $nullable = $field->nullable ? '?' : '';
        $default = $field->default !== null ? ' = ' . Relationships::lastSegment($field->default) : '';
        $unique = $field->unique && !$field->primaryKey ? ' UK' : '';

        // No type: omit the suffix entirely instead of leaving a dangling ":".
        if ($type === '') {
            return "$prefix({$field->name})$unique";
        }
        return "$prefix({$field->name}) : $type$nullable$default$unique";
    }
}
