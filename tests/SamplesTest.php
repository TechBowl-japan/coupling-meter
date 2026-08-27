<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter\Tests;

use PHPUnit\Framework\TestCase;
use Techtrain\CouplingMeter\Samples;

final class SamplesTest extends TestCase
{
    /** @return array{file: string, line: int, kind: string, strength: string, from: string, to: string, why: string, next: string, weight: int} */
    private static function sample(string $to, int $weight, int $line): array
    {
        return [
            'file' => 'X.php',
            'line' => $line,
            'kind' => 'new',
            'strength' => 'functional',
            'from' => 'X',
            'to' => $to,
            'why' => 'why',
            'next' => 'next',
            'weight' => $weight,
        ];
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

        // 相手が尽きたら残りで埋める。最終的な並びは強い順
        $this->assertSame(['A', 'A', 'B'], array_column($picked, 'to'));
    }

    public function testWeightIsStripped(): void
    {
        $picked = Samples::pick([self::sample('A', 4, 1)], 3);

        $this->assertArrayNotHasKey('weight', $picked[0]);
    }
}
