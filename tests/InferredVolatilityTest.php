<?php

declare(strict_types=1);

namespace TechBowl\CouplingMeter\Tests;

use PHPUnit\Framework\TestCase;
use TechBowl\CouplingMeter\InferredVolatility;
use TechBowl\CouplingMeter\Strength;

final class InferredVolatilityTest extends TestCase
{
    public function testOwnVolatilityIsKeptWhenNothingIsInherited(): void
    {
        $inferred = new InferredVolatility(['A' => 3], []);

        $this->assertSame(3, $inferred->of('A'));
    }

    public function testIntrusiveDependencyCarriesFullVolatility(): void
    {
        // A は自分では変わらないが、よく変わる B に侵入結合している
        $inferred = new InferredVolatility(
            ['A' => 1, 'B' => 4],
            [['from' => 'A', 'to' => 'B', 'strength' => Strength::Intrusive]],
        );

        $this->assertSame(4, $inferred->of('A'));
    }

    public function testContractDependencyBarelyCarriesVolatility(): void
    {
        // 契約だけの依存なら、相手がよく変わっても影響は小さい
        $inferred = new InferredVolatility(
            ['A' => 1, 'B' => 4],
            [['from' => 'A', 'to' => 'B', 'strength' => Strength::Contract]],
        );

        $this->assertSame(1, $inferred->of('A'));
    }

    public function testFunctionalDependencyCarriesMost(): void
    {
        $inferred = new InferredVolatility(
            ['A' => 1, 'B' => 4],
            [['from' => 'A', 'to' => 'B', 'strength' => Strength::Functional]],
        );

        $this->assertSame(3, $inferred->of('A'));
    }

    public function testStrongestSourceWins(): void
    {
        $inferred = new InferredVolatility(
            ['A' => 1, 'B' => 4, 'C' => 2],
            [
                ['from' => 'A', 'to' => 'B', 'strength' => Strength::Model],
                ['from' => 'A', 'to' => 'C', 'strength' => Strength::Intrusive],
            ],
        );

        // B からは 2（4 の半分）、C からは 2。自分の 1 より高いほうを取る
        $this->assertSame(2, $inferred->of('A'));
    }

    public function testCarriedVolatilityDependsOnStrength(): void
    {
        // よく変わる相手（4）でも、契約だけの依存なら 1 しか伝わらない
        $this->assertSame(1, InferredVolatility::carried(Strength::Contract, 4));
        $this->assertSame(2, InferredVolatility::carried(Strength::Model, 4));
        $this->assertSame(3, InferredVolatility::carried(Strength::Functional, 4));
        $this->assertSame(4, InferredVolatility::carried(Strength::Intrusive, 4));
    }

    public function testStableTargetCarriesNothing(): void
    {
        // 相手が変わらないなら、どんなに強く結合しても伝わらない
        $this->assertSame(1, InferredVolatility::carried(Strength::Intrusive, 1));
        $this->assertSame(0, InferredVolatility::carried(Strength::Model, 1));
    }

    public function testInheritedFlagIsSetOnlyWhenRaised(): void
    {
        $inferred = new InferredVolatility(
            ['A' => 1, 'B' => 4],
            [['from' => 'A', 'to' => 'B', 'strength' => Strength::Intrusive]],
        );

        $this->assertTrue($inferred->isInherited('A'));
        $this->assertFalse($inferred->isInherited('B'));
    }
}
