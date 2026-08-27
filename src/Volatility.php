<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter;

/**
 * 観測された変更回数を、モジュール全体の中での分位（1 から 4）に写す。
 *
 * 上位 10% を 4、上位 30% を 3、上位 60% を 2、残りを 1 とする。
 * 同じ回数のモジュールは平均順位を取る。全員が同じなら（単独モジュールを含む）順位に意味がないので中位に置く。
 * 変更回数 0 のモジュールは順位に関係なく 1。
 * 回数は変更の種類で重み付けする（weightedCount）。
 */
final class Volatility
{
    /**
     * 変更の種類ごとの重み。原著 9.5 は、機能を足す変更こそが本質的な変動性で、
     * 修正や整備は設計の悪さや偶発を含むとする。分類できないコミットは判断できないので 1 のまま。
     */
    private const WEIGHTS = [
        'Evolution' => 1.0,
        'Correction' => 0.5,
        'Maintenance' => 0.25,
        'Unknown' => 1.0,
    ];

    /**
     * @param array<string, int> $kinds 変更の種類 => 件数
     */
    public static function weightedCount(array $kinds): float
    {
        $total = 0.0;
        foreach ($kinds as $kind => $count) {
            $total += $count * (self::WEIGHTS[$kind] ?? 1.0);
        }

        return $total;
    }

    /**
     * 外から注入する原著の目盛り（1 から 10）を、規則に使う 4 段階に写す。
     * volatilityValue の逆写像（1、3、6、10 がそれぞれ 1 から 4 の代表値）。
     */
    public static function levelOf(int $scale): int
    {
        return match (true) {
            $scale >= 9 => 4,
            $scale >= 6 => 3,
            $scale >= 3 => 2,
            default => 1,
        };
    }

    /**
     * @param list<int|float> $counts 全モジュールの変更回数（重み付きなら小数）
     */
    public static function quartile(array $counts, int|float $value): int
    {
        $total = \count($counts);
        // 一度も変わっていないなら、他がどうであれ変動性は最も低い。
        if ($total === 0 || $value <= 0) {
            return 1;
        }
        // 全員が同じ回数なら順位に意味がない。中位に置く。
        if (min($counts) == max($counts)) {
            return 2;
        }

        $below = 0;
        $equal = 0;
        foreach ($counts as $count) {
            if ($count < $value) {
                ++$below;
            } elseif ($count == $value) {
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
