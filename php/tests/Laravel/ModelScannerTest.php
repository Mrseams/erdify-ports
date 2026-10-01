<?php

declare(strict_types=1);

namespace Mrseams\Erdify\Tests\Laravel;

use Mrseams\Erdify\Model\Relationship;
use Mrseams\Erdify\Tests\TempApp;
use PHPUnit\Framework\TestCase;

final class ModelScannerTest extends TestCase
{
    public function testModelsWithoutMigrationsAreDrawnWithoutColumns(): void
    {
        $schema = (new TempApp([
            'app/Models/Post.php' => TempApp::model('Post', 'public function author() { return $this->belongsTo(User::class, "author_id"); }'),
            'app/Models/User.php' => TempApp::model('User'),
        ]))->schema();

        self::assertSame(['Post', 'User'], array_keys($schema->entities));
        self::assertSame([], $schema->entities['Post']->fields);
        self::assertEquals([new Relationship('User', Relationship::ONE, 'author')], $schema->entities['Post']->relationships);
    }

    public function testPivotModelsUseSingularTableNames(): void
    {
        $schema = (new TempApp([
            'app/Models/RoleUser.php' => TempApp::model('RoleUser', extends: 'Pivot', uses: 'use Illuminate\Database\Eloquent\Relations\Pivot;'),
        ]))->schema();

        self::assertSame('role_user', $schema->entities['RoleUser']->tableName);
    }

    public function testTableAndRelationshipsAreInheritedAndCanBeOverridden(): void
    {
        $schema = (new TempApp([
            'app/Models/Base.php' => TempApp::model('Base', 'protected $table = "things"; public function owner() { return $this->belongsTo(User::class); } public function tags() { return $this->hasMany(Tag::class); }'),
            'app/Models/Thing.php' => TempApp::model('Thing', 'public function tags() { return "not a relationship"; }', extends: 'Base'),
            'app/Models/User.php' => TempApp::model('User'),
            'app/Models/Tag.php' => TempApp::model('Tag'),
        ]))->schema();

        self::assertSame('things', $schema->entities['Base']->tableName);
        self::assertArrayNotHasKey('Thing', $schema->entities, 'same table as Base: the first model wins');
        self::assertSame(['owner', 'tags'], array_map(static fn ($r) => $r->attribute, $schema->entities['Base']->relationships));
    }

    public function testThroughAndMorphToRelationsAreNotDrawn(): void
    {
        $schema = (new TempApp([
            'app/Models/Country.php' => TempApp::model('Country', '
                public function posts() { return $this->hasManyThrough(Post::class, User::class); }
                public function commentable() { return $this->morphTo(); }
                public static function boot() { return $this->hasMany(Post::class); }'),
            'app/Models/Post.php' => TempApp::model('Post'),
        ]))->schema();

        self::assertSame([], $schema->entities['Country']->relationships);
    }

    public function testNonModelClassesAreIgnored(): void
    {
        $app = new TempApp([
            'app/Services/Thing.php' => "<?php\nnamespace App\\Services;\nclass Thing extends \\ArrayObject {}\n",
            'app/Models/Interfaces/Nope.php' => "<?php\nnamespace App\\Models\\Interfaces;\ninterface Nope {}\n",
        ]);

        self::assertSame([], $app->schema()->entities);
    }
}
