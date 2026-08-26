<?php

declare(strict_types=1);

namespace TechBowl\CouplingMeter;

/**
 * 2 つのモジュールの距離。
 *
 * 名前空間の階層差（原著 8.1.3）を生の値とし、共有カーネルなら 1 段近く、
 * 触っている人が分かれていれば 1 段遠くする。象限判定に使う 4 段階と、
 * 均衡結合方程式に使う目盛りは、どちらもこの補正済みの gap から出す。
 */
final class Distance
{
    /** 4 段階のうち、この値以上を「距離が高い」とみなす。 */
    private const HIGH_LEVEL = 3;

    private function __construct(
        /** 補正済みの階層差。0 以上。 */
        public readonly int $gap,
        /** 象限判定用の 4 段階（1 から 4）。 */
        public readonly int $level,
        /** 均衡結合方程式用の目盛り（2 から 7）。 */
        public readonly int $scale,
    ) {
    }

    public static function of(ModuleMap $modules, string $from, string $to, bool $sharedKernel, bool $distantOwners): self
    {
        $gap = $modules->hierarchyGap($from, $to);
        if ($sharedKernel) {
            $gap = max(0, $gap - 1);
        }
        if ($distantOwners) {
            ++$gap;
        }

        return new self(
            gap: $gap,
            level: max(1, min(4, $gap)),
            scale: BalanceEquation::distanceValue($gap),
        );
    }

    public function isHigh(): bool
    {
        return $this->level >= self::HIGH_LEVEL;
    }
}
