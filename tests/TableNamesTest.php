<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter\Tests;

use PHPUnit\Framework\TestCase;
use Techtrain\CouplingMeter\TableNames;

final class TableNamesTest extends TestCase
{
    public function testTablesAreExtractedFromSql(): void
    {
        $sql = 'SELECT customer_id FROM orders o JOIN order_items i ON i.order_id = o.id LEFT JOIN `users` u ON u.id = o.user_id';

        $this->assertSame(['orders', 'order_items', 'users'], TableNames::fromSql($sql));
    }

    public function testInsertUpdateDeleteAreCovered(): void
    {
        $this->assertSame(['orders'], TableNames::fromSql('INSERT INTO orders (id) VALUES (1)'));
        $this->assertSame(['orders'], TableNames::fromSql('update orders set total = 0'));
        $this->assertSame(['orders'], TableNames::fromSql('DELETE FROM orders WHERE id = 1'));
    }

    public function testPlainTextIsNotSql(): void
    {
        $this->assertSame([], TableNames::fromSql('注文を更新しました'));
        $this->assertSame([], TableNames::fromSql('Loaded config from cache'));
    }

    public function testSubqueryAndSchemaPrefixAreHandled(): void
    {
        $this->assertSame(['orders', 'payments'], TableNames::fromSql('SELECT * FROM orders WHERE id IN (SELECT order_id FROM shop.payments)'));
    }

    public function testEloquentConventionPluralizesClassName(): void
    {
        $this->assertSame('orders', TableNames::forModel('Order'));
        $this->assertSame('order_items', TableNames::forModel('OrderItem'));
        $this->assertSame('categories', TableNames::forModel('Category'));
        $this->assertSame('addresses', TableNames::forModel('Address'));
        $this->assertSame('people', TableNames::forModel('Person'));
    }
}
