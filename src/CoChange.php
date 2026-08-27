<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter;

/**
 * 2 つのモジュールがどれだけ一緒に変わるか。
 *
 * コミット集合の Jaccard 係数（共起 / 和集合）で出す。変更の少ない側を分母にすると、
 * 何とでも一緒に変わる巨大モジュールとの組が全部 100% に張り付いてしまう。
 * Jaccard なら、相手が大きいほど自然に下がる。
 */
final class CoChange
{
    /**
     * @param int $shared 両方が同じコミットで変わった回数
     * @param int $left 片方の変更コミット数
     * @param int $right もう片方の変更コミット数
     * @return float 0.0 から 1.0
     */
    public static function rate(int $shared, int $left, int $right): float
    {
        $union = $left + $right - $shared;
        if ($union <= 0) {
            return 0.0;
        }

        return $shared / $union;
    }
}
