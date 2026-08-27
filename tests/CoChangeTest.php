<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter\Tests;

use PHPUnit\Framework\TestCase;
use Techtrain\CouplingMeter\CoChange;

final class CoChangeTest extends TestCase
{
    public function testRateIsJaccardOfCommitSets(): void
    {
        // 共起 5 回、A が 10 回、B が 20 回 → 5 / (10 + 20 - 5) = 0.2
        $this->assertEqualsWithDelta(0.2, CoChange::rate(5, 10, 20), 0.0001);
    }

    public function testHugeModuleDoesNotSaturate(): void
    {
        // 何とでも一緒に変わる巨大モジュール（500 回）との組。小さい側（10 回）が毎回一緒でも、
        // min を分母にすると 100% に張り付くが、Jaccard なら 10 / 500 = 2%
        $this->assertEqualsWithDelta(0.02, CoChange::rate(10, 10, 500), 0.0001);
    }

    public function testIdenticalHistoriesAreOne(): void
    {
        $this->assertEqualsWithDelta(1.0, CoChange::rate(7, 7, 7), 0.0001);
    }

    public function testNoHistoryIsZero(): void
    {
        $this->assertSame(0.0, CoChange::rate(0, 0, 0));
        $this->assertSame(0.0, CoChange::rate(0, 5, 0));
    }
}
