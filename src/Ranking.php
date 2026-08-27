<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter;

/**
 * 参照数で重み付けした順位づけ（--weight-by-references）。
 *
 * 原著は関係の数ではなく性質を見る立場なので、既定では均衡度だけで並べる。
 * ただし参照 1 箇所の組が上位を埋めると読みにくいので、任意で参照数の対数で割る。
 * 対数なので、10 倍の参照でようやく 1 段分の差になる。
 */
final class Ranking
{
    /** 小さいほど直す優先度が高い。 */
    public static function score(int $balance, int $references): float
    {
        return $balance / (1 + log10(max(1, $references)));
    }
}
