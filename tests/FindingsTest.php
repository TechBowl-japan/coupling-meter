<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter\Tests;

use PHPUnit\Framework\TestCase;
use Techtrain\CouplingMeter\Analyzer;
use Techtrain\CouplingMeter\BalanceReport;
use Techtrain\CouplingMeter\ModuleMap;
use Techtrain\CouplingMeter\Rules;

final class FindingsTest extends TestCase
{
    /** @return list<array{type: string, pair: string, detail: string}> */
    private static function findings(?Rules $rules = null): array
    {
        $analyzer = new Analyzer(__DIR__ . '/fixtures/app');
        $analyzer->run();
        $report = new BalanceReport($analyzer, new ModuleMap(2), null, __DIR__ . '/fixtures/app', $rules);
        $report->build();

        return $report->findings();
    }

    /** @return list<string> */
    private static function pairsOfType(string $type, ?Rules $rules = null): array
    {
        return array_values(array_map(
            static fn (array $finding): string => $finding['pair'],
            array_filter(self::findings($rules), static fn (array $finding): bool => $finding['type'] === $type),
        ));
    }

    public function testMutualRequiresModelOrStrongerInBothDirections(): void
    {
        // Http <-> Support は trait と戻り値型で双方向とも model 以上
        $this->assertContains('Fixture\Http <-> Fixture\Support', self::pairsOfType('mutual'));
        // Domain -> Http は interface 経由（contract）なので、逆転済みの依存として mutual にしない
        $this->assertNotContains('Fixture\Http <-> Fixture\Domain', self::pairsOfType('mutual'));
        $this->assertNotContains('Fixture\Domain <-> Fixture\Http', self::pairsOfType('mutual'));
    }

    public function testInvertedDependencyIsReportedAsInformation(): void
    {
        // 逆転済みの依存は「互いに依存」ではなく、情報として別の種類で出す
        $this->assertContains('Fixture\Domain -> Fixture\Http', self::pairsOfType('inverted'));
    }

    public function testAllowedPairsAreNotReported(): void
    {
        $rules = Rules::fromArray(['allow' => ['Fixture\Support -> Fixture\Http', 'Fixture\Http -> Fixture\Support']]);

        $this->assertNotContains('Fixture\Http <-> Fixture\Support', self::pairsOfType('mutual', $rules));
    }

    public function testRulesAcceptWildcards(): void
    {
        $rules = Rules::fromArray(['allow' => ['Fixture\* -> Fixture\Http']]);

        $this->assertTrue($rules->allows('Fixture\Support', 'Fixture\Http'));
        $this->assertFalse($rules->allows('Fixture\Http', 'Fixture\Support'));
        $this->assertFalse($rules->allows('Other\Support', 'Fixture\Http'));
    }
}
