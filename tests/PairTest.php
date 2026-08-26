<?php

declare(strict_types=1);

namespace TechBowl\CouplingMeter\Tests;

use PHPUnit\Framework\TestCase;
use TechBowl\CouplingMeter\Analyzer;
use TechBowl\CouplingMeter\BalanceReport;
use TechBowl\CouplingMeter\ModuleMap;
use TechBowl\CouplingMeter\Pair;
use TechBowl\CouplingMeter\Strength;

final class PairTest extends TestCase
{
    private static BalanceReport $report;

    public static function setUpBeforeClass(): void
    {
        $analyzer = new Analyzer(__DIR__ . '/fixtures/app');
        $analyzer->run();
        self::$report = new BalanceReport($analyzer, new ModuleMap(2), null, __DIR__ . '/fixtures/app');
        self::$report->build();
    }

    public function testPairsAreObjects(): void
    {
        $pairs = self::$report->pairs();

        $this->assertNotEmpty($pairs);
        $this->assertContainsOnlyInstancesOf(Pair::class, $pairs);
    }

    public function testHttpToDomainIsIntrusiveAndNear(): void
    {
        $pair = $this->pair('Fixture\Http', 'Fixture\Domain');

        $this->assertSame(Strength::Intrusive, $pair->strength);
        $this->assertSame(2, $pair->distance);
        $this->assertSame('high-cohesion', $pair->quadrant);
        $this->assertTrue($pair->balanced);
        $this->assertSame(10, $pair->strengthValue);
        $this->assertSame(4, $pair->distanceValue);
        $this->assertSame(7, $pair->modularity);
        $this->assertSame('Fixture\Http -> Fixture\Domain', $pair->key());
    }

    public function testToArrayKeepsTheJsonShape(): void
    {
        $array = $this->pair('Fixture\Http', 'Fixture\Domain')->toArray();

        $this->assertSame([
            'from', 'to', 'strength', 'kinds', 'references', 'samples', 'distance', 'shared_kernel',
            'ownership_overlap', 'evolution_ratio', 'inferred_volatility_from', 'volatility_inherited',
            'distant_owners', 'volatility', 'quadrant', 'balanced', 'co_changes', 'co_change_rate',
            'strength_value', 'distance_value', 'volatility_value', 'modularity', 'balance',
        ], array_keys($array));
        $this->assertSame('intrusive', $array['strength']);
        $this->assertIsFloat($array['co_change_rate']);
    }

    public function testFindingsDoNotCarryRawData(): void
    {
        foreach (self::$report->findings() as $finding) {
            $this->assertSame(['type', 'pair', 'detail'], array_keys($finding));
        }
    }

    private function pair(string $from, string $to): Pair
    {
        foreach (self::$report->pairs() as $pair) {
            if ($pair->from === $from && $pair->to === $to) {
                return $pair;
            }
        }
        $this->fail("{$from} -> {$to} が見つからない");
    }
}
