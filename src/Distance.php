<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter;

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

    /** 同じ composer パッケージの中で許す階層差の上限。同一デプロイ内で目盛り 7 を出さない。 */
    private const SAME_PACKAGE_MAX_GAP = 4;

    /** 別の composer パッケージに属する組に足す段数。 */
    private const CROSS_PACKAGE_GAP = 2;

    /**
     * @param bool $asyncOnly キューやイベントなど非同期の呼び出しだけでつながっている組か
     * @param Packages|null $packages composer パッケージの境界。null なら見ない
     */
    public static function of(
        ModuleMap $modules,
        string $from,
        string $to,
        bool $sharedKernel,
        bool $distantOwners,
        bool $asyncOnly = false,
        ?Packages $packages = null,
    ): self {
        $gap = $modules->hierarchyGap($from, $to);

        // 同じデプロイ単位なら名前空間が深くても近い。別のパッケージなら遠い。
        $fromPackage = $packages?->packageOf($from);
        $toPackage = $packages?->packageOf($to);
        if ($fromPackage !== null && $toPackage !== null) {
            $gap = $fromPackage === $toPackage
                ? min(self::SAME_PACKAGE_MAX_GAP, $gap)
                : $gap + self::CROSS_PACKAGE_GAP;
        }

        if ($sharedKernel) {
            $gap = max(0, $gap - 1);
        }
        if ($distantOwners) {
            ++$gap;
        }
        // 非同期でしか呼ばれない相手は、実行時にも時間的にも離れている。原著 8.1 の実行時結合による距離。
        if ($asyncOnly) {
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
