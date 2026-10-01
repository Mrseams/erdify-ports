<?php

declare(strict_types=1);

namespace Mrseams\Erdify\Render;

use Mrseams\Erdify\Model\EnumType;
use Mrseams\Erdify\Model\Field;
use Mrseams\Erdify\Model\PythonJson;
use Mrseams\Erdify\Model\Relationship;
use Mrseams\Erdify\Model\Schema;

/** The model as JSON (spec/ir.schema.json), formatted exactly like upstream. */
final class JsonRenderer implements Renderer
{
    public function render(Schema $schema, RenderOptions $options = new RenderOptions()): string
    {
        $entities = [];
        foreach ($schema->entities as $entity) {
            $entities[] = [
                'name' => $entity->name,
                'table_name' => $entity->tableName,
                'source' => $entity->source,
                'is_link_table' => $entity->isLinkTable,
                'fields' => array_map(static fn (Field $f): array => [
                    'name' => $f->name,
                    'type' => $f->type,
                    'primary_key' => $f->primaryKey,
                    'foreign_key' => $f->foreignKey,
                    'nullable' => $f->nullable,
                    'foreign_table' => $f->foreignTable,
                    'index' => $f->index,
                    'unique' => $f->unique,
                    'default' => $f->default,
                ], $entity->fields),
                'relationships' => $options->includeRelationships
                    ? array_map(static fn (Relationship $r): array => [
                        'target' => $r->target,
                        'type' => $r->type,
                        'attribute' => $r->attribute,
                    ], $entity->relationships)
                    : [],
            ];
        }

        return PythonJson::encode([
            'title' => $schema->title,
            'entities' => $entities,
            'enums' => $options->includeEnums
                ? array_values(array_map(
                    static fn (EnumType $e): array => ['name' => $e->name, 'values' => $e->values],
                    $schema->enums,
                ))
                : [],
        ]);
    }
}
