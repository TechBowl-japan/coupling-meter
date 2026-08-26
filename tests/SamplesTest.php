<?php

declare(strict_types=1);

namespace TechBowl\CouplingMeter\Tests;

use PHPUnit\Framework\TestCase;
use TechBowl\CouplingMeter\Samples;

final class SamplesTest extends TestCase
{
    /** @return array{to: string, weight: int, line: int} */
    private static function sample(string $to, int $weight, int $line): array
    {
        return ['to' => $to, 'weight' => $weight, 'line' => $line];
    }

    public function testStrongestComesFirst(): void
    {
        $picked = Samples::pick([
            self::sample('A', 2, 1),
            self::sample('B', 4, 2),
            self::sample('C', 3, 3),
        ], 3);

        $this->assertSame(['B', 'C', 'A'], array_column($picked, 'to'));
    }

    public function testDistinctTargetsArePreferredOverRepeats(): void
    {
        // A への強い参照が 3 つ並んでも、B と C を差し置いて A ばかりにしない
        $picked = Samples::pick([
            self::sample('A', 4, 1),
            self::sample('A', 4, 2),
            self::sample('A', 4, 3),
            self::sample('B', 3, 4),
            self::sample('C', 2, 5),
        ], 3);

        $this->assertSame(['A', 'B', 'C'], array_column($picked, 'to'));
        $this->assertSame(1, $picked[0]['line']);
    }

    public function testRepeatsFillTheRestWhenTargetsRunOut(): void
    {
        $picked = Samples::pick([
            self::sample('A', 4, 1),
            self::sample('A', 3, 2),
            self::sample('B', 2, 3),
        ], 3);

        $this->assertSame(['A', 'B', 'A'], array_column($picked, 'to'));
    }

    public function testWeightIsStripped(): void
    {
        $picked = Samples::pick([self::sample('A', 4, 1)], 3);

        $this->assertArrayNotHasKey('weight', $picked[0]);
    }
}
