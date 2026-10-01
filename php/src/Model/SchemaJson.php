<?php

declare(strict_types=1);

namespace Mrseams\Erdify\Model;

/** Reads and writes the model JSON defined by spec/ir.schema.json. */
final class SchemaJson
{
    public static function decode(string $json): Schema
    {
        /** @var array{title: string, entities: list<array<string, mixed>>, enums: list<array{name: string, values: list<string>}>} $data */
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        $entities = [];
        foreach ($data['entities'] as $e) {
            $entities[$e['name']] = new Entity(
                name: $e['name'],
                tableName: $e['table_name'],
                fields: array_map(
                    static fn (array $f): Field => new Field(
                        name: $f['name'],
                        type: $f['type'],
                        primaryKey: $f['primary_key'],
                        foreignKey: $f['foreign_key'],
                        nullable: $f['nullable'],
                        foreignTable: $f['foreign_table'],
                        index: $f['index'],
                        default: $f['default'],
                        unique: $f['unique'],
                    ),
                    $e['fields'],
                ),
                relationships: array_map(
                    static fn (array $r): Relationship => new Relationship($r['target'], $r['type'], $r['attribute']),
                    $e['relationships'],
                ),
                isLinkTable: $e['is_link_table'],
                source: $e['source'],
            );
        }

        $enums = [];
        foreach ($data['enums'] as $e) {
            $enums[$e['name']] = new EnumType($e['name'], $e['values']);
        }

        return new Schema($entities, $enums, $data['title']);
    }
}
