<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter;

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

        return implode('\\', \array_slice($parts, 0, $this->depth));
    }

    /**
     * 共通の祖先を取り除いた階層の差。原著 8.1.3 の距離の評価方法。
     * 補正と目盛りへの変換は Distance が行う。
     */
    public function hierarchyGap(string $a, string $b): int
    {
        if ($a === $b) {
            return 0;
        }

        $left = explode('\\', $a);
        $right = explode('\\', $b);

        $shared = 0;
        $limit = min(\count($left), \count($right));
        while ($shared < $limit && $left[$shared] === $right[$shared]) {
            ++$shared;
        }

        return (\count($left) - $shared) + (\count($right) - $shared);
    }
}
