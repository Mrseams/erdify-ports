<?php

declare(strict_types=1);

namespace Mrseams\Erdify\Tests\Laravel;

use Mrseams\Erdify\Laravel\LaravelSchemaBuilder;
use Mrseams\Erdify\Model\Field;
use Mrseams\Erdify\Model\Schema;
use Mrseams\Erdify\Scan\FileFinder;
use Mrseams\Erdify\Tests\TempApp;
use PHPUnit\Framework\TestCase;

final class MigrationReplayerTest extends TestCase
{
    /** @param array<string, string> $migrations file name => up() body */
    private static function schema(array $migrations): Schema
    {
        $files = [];
        foreach ($migrations as $name => $body) {
            $files["database/migrations/$name"] = TempApp::migration($body);
        }
        return (new TempApp($files))->schema();
    }

    private static function field(Schema $schema, string $table, string $column): Field
    {
        $field = $schema->entityByTable($table)?->field($column);
        self::assertNotNull($field, "$table.$column");
        return $field;
    }

    /** @return list<string> */
    private static function columns(Schema $schema, string $table): array
    {
        return array_map(static fn (Field $f) => $f->name, $schema->entityByTable($table)->fields ?? []);
    }

    public function testMigrationsRunInFileNameOrderAcrossDirectories(): void
    {
        $app = new TempApp([
            // Path order would run "a_module" first; Laravel goes by file name.
            'database/migrations/a_module/2024_02_01_000000_add_bio.php' => TempApp::migration(
                "Schema::table('users', fn (Blueprint \$t) => \$t->text('bio'));"
            ),
            'database/migrations/z_core/2024_01_01_000000_create_users.php' => TempApp::migration(
                "Schema::create('users', function (Blueprint \$t) { \$t->id(); \$t->string('bio'); });"
            ),
        ]);
        $files = (new FileFinder(['database/migrations/**/*.php']))->find($app->path());
        $schema = (new LaravelSchemaBuilder())->build($files);

        self::assertSame('text', self::field($schema, 'users', 'bio')->type);
    }

    public function testDownIsIgnored(): void
    {
        $schema = self::schema(['m.php' => "Schema::create('ignored_because_down', fn (Blueprint \$t) => \$t->id());"]);

        self::assertNotNull($schema->entityByTable('ignored_because_down'));
    }

    public function testForeignIdIsOnlyAForeignKeyWhenConstrained(): void
    {
        $schema = self::schema(['m.php' => "Schema::create('posts', function (Blueprint \$t) {
            \$t->foreignId('user_id');
            \$t->foreignId('team_id')->constrained(table: 'organisations', column: 'uuid');
            \$t->unsignedBigInteger('editor_id');
            \$t->foreign('editor_id')->references('id');
        });"]);

        self::assertFalse(self::field($schema, 'posts', 'user_id')->foreignKey);
        self::assertSame('organisations.uuid', self::field($schema, 'posts', 'team_id')->foreignTable);
        self::assertFalse(self::field($schema, 'posts', 'editor_id')->foreignKey, 'references() without on()');
    }

    public function testDropForeignByColumnsAndDropPrimary(): void
    {
        $schema = self::schema([
            '1.php' => "Schema::create('posts', function (Blueprint \$t) {
                \$t->id();
                \$t->foreignId('user_id')->constrained();
            });",
            '2.php' => "Schema::table('posts', function (Blueprint \$t) {
                \$t->dropForeign(['user_id']);
                \$t->dropPrimary();
            });",
        ]);

        self::assertFalse(self::field($schema, 'posts', 'user_id')->foreignKey);
        self::assertNull(self::field($schema, 'posts', 'user_id')->foreignTable);
        self::assertFalse(self::field($schema, 'posts', 'id')->primaryKey);
    }

    public function testModifiers(): void
    {
        $schema = self::schema(['m.php' => "Schema::connection('pgsql')->create('posts', function (Blueprint \$t) {
            \$t->string('title')->nullable(false)->default('Untitled');
            \$t->boolean('draft')->default(true);
            \$t->timestamp('published_at')->nullable()->useCurrent();
            \$t->string('slug')->first();
            \$t->unique(['title', 'slug']);
            \$t->index('draft');
        });"]);

        self::assertSame(['slug', 'title', 'draft', 'published_at'], self::columns($schema, 'posts'));
        $title = self::field($schema, 'posts', 'title');
        self::assertFalse($title->nullable);
        self::assertSame("'Untitled'", $title->default);
        self::assertFalse($title->unique, 'composite unique keys are not drawn');
        self::assertSame('true', self::field($schema, 'posts', 'draft')->default);
        self::assertTrue(self::field($schema, 'posts', 'draft')->index);
        self::assertSame('CURRENT_TIMESTAMP', self::field($schema, 'posts', 'published_at')->default);
    }

    public function testMorphsAndDropMorphs(): void
    {
        $schema = self::schema([
            '1.php' => "Schema::create('comments', function (Blueprint \$t) {
                \$t->nullableMorphs('commentable');
                \$t->uuidMorphs('author');
            });",
            '2.php' => "Schema::table('comments', fn (Blueprint \$t) => \$t->dropMorphs('author'));",
        ]);

        self::assertSame(['commentable_type', 'commentable_id'], self::columns($schema, 'comments'));
        self::assertTrue(self::field($schema, 'comments', 'commentable_id')->nullable);
    }

    public function testTableRenameKeepsPositionAndDropRemoves(): void
    {
        $schema = self::schema([
            '1.php' => "Schema::create('a', fn (\$t) => \$t->id()); Schema::create('b', fn (\$t) => \$t->id()); Schema::create('c', fn (\$t) => \$t->id());",
            '2.php' => "Schema::rename('a', 'z'); Schema::drop('b');",
        ]);

        self::assertSame(['z', 'c'], array_map(static fn ($e) => $e->tableName, array_values($schema->entities)));
    }

    public function testNonLiteralTableNameIsSkipped(): void
    {
        $app = new TempApp(['database/migrations/m.php' => TempApp::migration(
            "Schema::create(\$this->table, fn (\$t) => \$t->id()); Schema::create('kept', fn (\$t) => \$t->id());"
        )]);

        self::assertSame(['kept'], array_keys($app->schema()->entities));
    }
}
