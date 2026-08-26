<?php

declare(strict_types=1);

namespace TechBowl\CouplingMeter;

/** 名前空間からモジュールを切り出し、モジュール間の距離を測る。 */
final class ModuleMap
{
    public function __construct(private readonly int $depth = 2)
    {
    }

    public function moduleOf(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        array_pop($parts);

        if ($parts === []) {
            return '(root)';
        }

        return implode('\\', array_slice($parts, 0, $this->depth));
    }

    /**
     * 共通の名前空間を取り除いた残りの階層数を距離とする。
     * 同じモジュールなら 0、離れるほど大きく、上限は 4。
     */
    public function distance(string $a, string $b): int
    {
        return max(1, min(4, $this->hierarchyGap($a, $b)));
    }

    /**
     * 共通の祖先を取り除いた階層の差。原著 8.1.3 の距離の評価方法。
     * こちらはクランプしない生の値を返す。
     */
    public function hierarchyGap(string $a, string $b): int
    {
        if ($a === $b) {
            return 0;
        }

        $left = explode('\\', $a);
        $right = explode('\\', $b);

        $shared = 0;
        $limit = min(count($left), count($right));
        while ($shared < $limit && $left[$shared] === $right[$shared]) {
            ++$shared;
        }

        return (count($left) - $shared) + (count($right) - $shared);
    }
}
