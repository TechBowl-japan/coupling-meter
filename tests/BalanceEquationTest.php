<?php

declare(strict_types=1);

namespace TechBowl\CouplingMeter\Tests;

use PHPUnit\Framework\TestCase;
use TechBowl\CouplingMeter\BalanceEquation;
use TechBowl\CouplingMeter\Strength;

final class BalanceEquationTest extends TestCase
{
    public function testStrengthScale(): void
    {
        $this->assertSame(1, BalanceEquation::strengthValue(Strength::Contract));
        $this->assertSame(3, BalanceEquation::strengthValue(Strength::Model));
        $this->assertSame(8, BalanceEquation::strengthValue(Strength::Functional));
        $this->assertSame(10, BalanceEquation::strengthValue(Strength::Intrusive));
    }

    public function testModularityIsTheGapBetweenStrengthAndDistance(): void
    {
        // 別ベンダーのシステム（距離 10）に侵入結合（強度 10）
        $this->assertSame(1, BalanceEquation::modularity(10, 10));
        // 同じ距離で統合コントラクトのみ
        $this->assertSame(10, BalanceEquation::modularity(1, 10));
        // 同じ名前空間（距離 2）で機能結合（強度 8）
        $this->assertSame(7, BalanceEquation::modularity(8, 2));
    }

    public function testBalanceTakesTheHigherOfModularityAndStability(): void
    {
        // 原著の例 1: モデル結合、別システム、コアサブドメイン
        $this->assertSame(8, BalanceEquation::balance(3, 10, 10));
        // 原著の例 2: 機能結合、同じ名前空間、コアサブドメイン
        $this->assertSame(7, BalanceEquation::balance(8, 2, 10));
        // 原著の例 3: 対称機能結合、分散システム、コアサブドメイン
        $this->assertSame(1, BalanceEquation::balance(9, 9, 10));
        // 原著の例 4: 侵入結合、別システム、レガシー
        $this->assertSame(10, BalanceEquation::balance(10, 9, 1));
    }

    public function testLowVolatilityOffsetsPoorModularity(): void
    {
        // 強度と距離がそろっていても、相手が変わらないなら均衡度は上がる
        $this->assertSame(1, BalanceEquation::balance(10, 10, 10));
        $this->assertSame(10, BalanceEquation::balance(10, 10, 1));
    }

    public function testDistanceScaleMapsHierarchyGap(): void
    {
        // 同じ名前空間は 2、離れるほど 3 から 7 へ
        $this->assertSame(2, BalanceEquation::distanceValue(0));
        $this->assertSame(3, BalanceEquation::distanceValue(1));
        $this->assertSame(7, BalanceEquation::distanceValue(5));
        $this->assertSame(7, BalanceEquation::distanceValue(9));
    }

    public function testVolatilityScaleMapsQuartile(): void
    {
        // git の分位（1 から 4）を、原著のスケール（1、3、10）に寄せる
        $this->assertSame(1, BalanceEquation::volatilityValue(1));
        $this->assertSame(3, BalanceEquation::volatilityValue(2));
        $this->assertSame(6, BalanceEquation::volatilityValue(3));
        $this->assertSame(10, BalanceEquation::volatilityValue(4));
    }
}
