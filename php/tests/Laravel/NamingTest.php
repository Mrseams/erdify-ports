<?php

declare(strict_types=1);

namespace Mrseams\Erdify\Tests\Laravel;

use Mrseams\Erdify\Laravel\Naming;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Expected values are what Laravel 11/12 itself returns. */
final class NamingTest extends TestCase
{
    /** @return iterable<array{string, string}> */
    public static function modelTables(): iterable
    {
        yield ['App\Models\User', 'users'];
        yield ['App\Models\OrderItem', 'order_items'];
        yield ['App\Models\Person', 'people'];
        yield ['App\Models\Category', 'categories'];
        yield ['App\Models\Child', 'children'];
        yield ['App\Models\Sheep', 'sheep'];
        yield ['App\Models\HTTPRequest', 'h_t_t_p_requests'];
        yield ['AuditLog', 'audit_logs'];
    }

    #[DataProvider('modelTables')]
    public function testModelTable(string $class, string $table): void
    {
        self::assertSame($table, Naming::modelTable($class));
    }

    public function testPivotModelTableIsSingular(): void
    {
        self::assertSame('role_user', Naming::modelTable('App\Models\RoleUser', isPivot: true));
    }

    public function testForeignKeyAndConstrainedTable(): void
    {
        self::assertSame('order_item_id', Naming::foreignKey('App\Models\OrderItem'));
        self::assertSame('users', Naming::constrainedTable('user_id'));
        self::assertSame('categories', Naming::constrainedTable('category_id'));
        self::assertSame('people', Naming::constrainedTable('person_uuid', 'uuid'));
    }

    public function testPivotTable(): void
    {
        self::assertSame('role_user', Naming::pivotTable('App\Models\User', 'App\Models\Role'));
        self::assertSame('order_item_product', Naming::pivotTable('Product', 'OrderItem'));
    }
}
