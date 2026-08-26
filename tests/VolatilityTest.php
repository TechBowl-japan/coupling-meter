<?php

declare(strict_types=1);

namespace TechBowl\CouplingMeter\Tests;

use PHPUnit\Framework\TestCase;
use TechBowl\CouplingMeter\Volatility;

final class VolatilityTest extends TestCase
{
    public function testSpreadCountsFallIntoFourQuartiles(): void
    {
        $counts = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];

        $this->assertSame(1, Volatility::quartile($counts, 1));
        $this->assertSame(2, Volatility::quartile($counts, 5));
        $this->assertSame(3, Volatility::quartile($counts, 8));
        $this->assertSame(4, Volatility::quartile($counts, 10));
    }

    public function testTiesDoNotInflateEveryModuleToTheTop(): void
    {
        // 全モジュールが同じ回数なら、誰も「上位 10%」ではない。同順位は平均順位（2.5/4）で中位に置く
        $counts = [5, 5, 5, 5];

        $this->assertSame(2, Volatility::quartile($counts, 5));
    }

    public function testSingleModuleHasNoSpreadToRank(): void
    {
        // 比べる相手がいなければ順位は出せない。中位に置く
        $this->assertSame(2, Volatility::quartile([12], 12));
    }

    public function testTiedGroupTakesItsAverageRank(): void
    {
        // 1, 1, 1, 9: 9 だけが上位。1 は同順位なので平均順位（2/4 = 50%）にとどまる
        $counts = [1, 1, 1, 9];

        $this->assertSame(4, Volatility::quartile($counts, 9));
        $this->assertSame(2, Volatility::quartile($counts, 1));
    }
}
