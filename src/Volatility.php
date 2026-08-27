<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter;

/**
 * 観測された変更回数を、モジュール全体の中での分位（1 から 4）に写す。
 *
 * 上位 10% を 4、上位 30% を 3、上位 60% を 2、残りを 1 とする。
 * 同じ回数のモジュールは平均順位を取る。全員が同じなら（単独モジュールを含む）順位に意味がないので中位に置く。
 * 変更回数 0 のモジュールは順位に関係なく 1。
 */
final class Volatility
{
    /**
     * @param list<int> $counts 全モジュールの変更回数
     */
    public static function quartile(array $counts, int $value): int
    {
        $total = \count($counts);
        // 一度も変わっていないなら、他がどうであれ変動性は最も低い。
        if ($total === 0 || $value === 0) {
            return 1;
        }
        // 全員が同じ回数なら順位に意味がない。中位に置く。
        if (min($counts) === max($counts)) {
            return 2;
        }

        $below = 0;
        $equal = 0;
        foreach ($counts as $count) {
            if ($count < $value) {
                ++$below;
            } elseif ($count === $value) {
                ++$equal;
            }
        }

        // 同順位の平均順位。below + 1 から below + equal までの中央。
        $rank = $below + ($equal + 1) / 2;
        $ratio = $rank / $total;

        return match (true) {
            $ratio > 0.90 => 4,
            $ratio > 0.70 => 3,
            $ratio > 0.40 => 2,
            default => 1,
        };
    }
}
