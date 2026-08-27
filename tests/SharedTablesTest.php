<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter\Tests;

use PHPUnit\Framework\TestCase;
use Techtrain\CouplingMeter\Analyzer;
use Techtrain\CouplingMeter\Reference;
use Techtrain\CouplingMeter\Strength;

final class SharedTablesTest extends TestCase
{
    /** @var list<Reference> */
    private static array $references;

    public static function setUpBeforeClass(): void
    {
        $analyzer = new Analyzer(__DIR__ . '/fixtures/app');
        $analyzer->run();
        self::$references = $analyzer->references();
    }

    /** @return list<Reference> */
    private static function sharedTable(string $from, string $to): array
    {
        return array_values(array_filter(
            self::$references,
            static fn (Reference $r): bool => $r->kind === 'shared-table' && $r->from === $from && $r->to === $to,
        ));
    }

    public function testTwinModelsShareATable(): void
    {
        // Legacy\LegacyOrder（$table = 'orders'）と Domain\Order（規約で orders）は同じテーブルを指す
        $found = self::sharedTable('Fixture\Legacy\LegacyOrder', 'Fixture\Domain\Order');

        $this->assertCount(1, $found);
        $this->assertSame(Strength::Intrusive, $found[0]->strength);
        // 逆向きも出る。テーブルの共有は双方向の結合
        $this->assertCount(1, self::sharedTable('Fixture\Domain\Order', 'Fixture\Legacy\LegacyOrder'));
    }

    public function testRawSqlSharesTheModelsTable(): void
    {
        // 生 SQL の FROM orders は Domain\Order と、DB::table('order_items') は該当モデルがないので出ない
        $this->assertCount(1, self::sharedTable('Fixture\Reports\OrderReport', 'Fixture\Domain\Order'));
        $this->assertCount(1, self::sharedTable('Fixture\Reports\OrderReport', 'Fixture\Legacy\LegacyOrder'));
    }

    public function testSampleLinePointsAtTheSqlOrTableDeclaration(): void
    {
        $found = self::sharedTable('Fixture\Reports\OrderReport', 'Fixture\Domain\Order');

        $this->assertStringEndsWith('Reports/OrderReport.php', $found[0]->file);
        $this->assertSame(12, $found[0]->line);
    }
}
