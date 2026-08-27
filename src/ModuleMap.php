<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter;

/** 名前空間からモジュールを切り出し、モジュール間の距離を測る。 */
final class ModuleMap
{
    /**
     * @param array<string, true> $split 1 段深く切る名前空間。巨大なモジュールの子名前空間を別モジュールにするために使う
     */
    public function __construct(
        private readonly int $depth = 2,
        private readonly array $split = [],
    ) {
    }

    /**
     * クラス数が maxClasses を超える名前空間は、その子名前空間を別モジュールとして切る。
     * 子もまだ大きければ、さらに切る。0 なら分割しない。
     *
     * @param list<string> $fqcns 解析対象の全クラス
     */
    public static function autoSplit(int $depth, array $fqcns, int $maxClasses): self
    {
        if ($maxClasses <= 0) {
            return new self($depth);
        }

        $split = [];
        $current = new self($depth);
        // 分割するたびにモジュールの切り方が変わるので、変化がなくなるまで繰り返す
        do {
            $counts = [];
            foreach ($fqcns as $fqcn) {
                $module = $current->moduleOf($fqcn);
                $counts[$module] = ($counts[$module] ?? 0) + 1;
            }
            $changed = false;
            foreach ($counts as $module => $count) {
                if ($count > $maxClasses && !isset($split[$module])) {
                    $split[$module] = true;
                    $changed = true;
                }
            }
            $current = new self($depth, $split);
        } while ($changed);

        return $current;
    }

    public function moduleOf(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        array_pop($parts);

        if ($parts === []) {
            return '(root)';
        }

        $take = $this->depth;
        // 分割対象の名前空間の中なら、その子まで含める。子も分割対象なら、さらに深く
        while ($take < \count($parts) && isset($this->split[implode('\\', \array_slice($parts, 0, $take))])) {
            ++$take;
        }

        return implode('\\', \array_slice($parts, 0, $take));
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
