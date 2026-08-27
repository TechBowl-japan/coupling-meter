<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter\Tests;

use PHPUnit\Framework\TestCase;
use Techtrain\CouplingMeter\Volatility;

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

    public function testModulesThatNeverChangedAreNotVolatile(): void
    {
        // 期間内に一度も変わらなかったモジュールは、順位に関係なく最も低い
        $this->assertSame(1, Volatility::quartile([1, 0, 0, 0], 0));
        $this->assertSame(1, Volatility::quartile([0, 0], 0));
    }

    public function testUnchangedModulesStayInTheDistribution(): void
    {
        // 4 モジュール中 1 つだけ変わったなら、それは最上位。変わっていないモジュールを分布から外すと 1 件だけの分布になり中位に落ちてしまう
        $this->assertSame(4, Volatility::quartile([1, 0, 0, 0], 1));
    }

    public function testChangeKindsAreWeighted(): void
    {
        // 機能追加（Evolution）は 1、修正は 0.5、整備は 0.25。分類できないコミットは判断できないので 1
        $weighted = Volatility::weightedCount(['Evolution' => 2, 'Correction' => 2, 'Maintenance' => 4, 'Unknown' => 1]);

        $this->assertEqualsWithDelta(2 + 1 + 1 + 1, $weighted, 0.0001);
    }

    public function testQuartileAcceptsWeightedCounts(): void
    {
        // 整備ばかりのモジュール（1.0）は、機能追加が続くモジュール（4.0）より下
        $counts = [4.0, 1.0, 2.5, 0.0];

        $this->assertSame(4, Volatility::quartile($counts, 4.0));
        $this->assertSame(1, Volatility::quartile($counts, 0.0));
    }

    public function testBookScaleMapsToLevel(): void
    {
        // 外から注入する原著の目盛り（1 から 10）を、規則に使う 4 段階に写す
        $this->assertSame(1, Volatility::levelOf(1));
        $this->assertSame(1, Volatility::levelOf(2));
        $this->assertSame(2, Volatility::levelOf(3));
        $this->assertSame(2, Volatility::levelOf(5));
        $this->assertSame(3, Volatility::levelOf(6));
        $this->assertSame(3, Volatility::levelOf(8));
        $this->assertSame(4, Volatility::levelOf(9));
        $this->assertSame(4, Volatility::levelOf(10));
    }
}
