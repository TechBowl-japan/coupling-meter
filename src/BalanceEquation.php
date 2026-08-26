<?php

declare(strict_types=1);

namespace TechBowl\PhpCoupling;

/**
 * 原著 10.3 の均衡結合方程式。
 *
 *   モジュール性 = |強度 - 距離| + 1
 *   均衡度       = max(|強度 - 距離|, MAX - 変動性) + MIN
 *
 * 3 つの次元を 1 から 10 の同じ物差しに載せたうえで計算する。著者自身が
 * 「これは正確な科学ではない」と断っている点も併せて扱うこと。
 */
final class BalanceEquation
{
    public const MIN_VALUE = 1;
    public const MAX_VALUE = 10;

    /** 統合強度の目盛り。対称機能結合（9）は判定できないので使わない。 */
    public static function strengthValue(Strength $strength): int
    {
        return match ($strength) {
            Strength::Contract => 1,
            Strength::Model => 3,
            Strength::Functional => 8,
            Strength::Intrusive => 10,
        };
    }

    /**
     * 名前空間の階層差を距離の目盛りに写す。
     *
     * 原著では 1 が同じオブジェクトのメソッド、2 が同じ名前空間、3 から 7 が異なる名前空間、
     * 8 以上がライブラリやサービスをまたぐ場合。本ツールは同一リポジトリの名前空間だけを見るため、
     * 2 から 7 の範囲に収める。
     */
    public static function distanceValue(int $hierarchyGap): int
    {
        return min(7, max(2, 2 + $hierarchyGap));
    }

    /**
     * git 履歴の分位（1 から 4）を変動性の目盛りに写す。
     *
     * 原著は 1 が進化していないレガシー、3 が支援と汎用のサブドメイン、10 がコアサブドメイン。
     * 観測値からサブドメインの種類は判らないので、分位を等間隔に割り当てる。
     */
    public static function volatilityValue(int $quartile): int
    {
        return match (max(1, min(4, $quartile))) {
            1 => 1,
            2 => 3,
            3 => 6,
            default => 10,
        };
    }

    /** 強度と距離が離れているほど高い。1 から 10。 */
    public static function modularity(int $strength, int $distance): int
    {
        return abs($strength - $distance) + self::MIN_VALUE;
    }

    /** モジュール性か、変動性の低さのどちらか高いほうを取る。1 から 10。 */
    public static function balance(int $strength, int $distance, int $volatility): int
    {
        return max(
            abs($strength - $distance),
            self::MAX_VALUE - $volatility,
        ) + self::MIN_VALUE;
    }
}
