<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter\Tests;

use PHPUnit\Framework\TestCase;
use Techtrain\CouplingMeter\Ranking;

final class RankingTest extends TestCase
{
    public function testSingleReferenceKeepsTheBalance(): void
    {
        $this->assertEqualsWithDelta(3.0, Ranking::score(3, 1), 0.0001);
    }

    public function testManyReferencesLowerTheScore(): void
    {
        // 参照が多いほど、同じ均衡度でも上位（スコアが小さい）に来る
        $this->assertLessThan(Ranking::score(3, 1), Ranking::score(3, 10));
        $this->assertLessThan(Ranking::score(3, 10), Ranking::score(3, 100));
    }

    public function testWeightIsLogarithmic(): void
    {
        // 10 箇所では均衡度 3 の組が、1 箇所の均衡度 1 の組を追い越すほどではない
        $this->assertLessThan(Ranking::score(3, 10), Ranking::score(1, 1));
        // 1000 箇所なら追い越す
        $this->assertLessThan(Ranking::score(1, 1), Ranking::score(3, 1000));
    }
}
