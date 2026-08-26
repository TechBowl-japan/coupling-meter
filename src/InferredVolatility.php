<?php

declare(strict_types=1);

namespace TechBowl\PhpCoupling;

/**
 * 依存先から受け取る変動性を含めて、実際に変わりうる度合いを出す。
 *
 * 原著 9.5 の推定変動性。自分自身はあまり変わらないコンポーネントでも、よく変わる相手と
 * 強く結合していれば、実際には変わることになる。
 */
final class InferredVolatility
{
    /** 強度ごとに、相手の変動性をどれだけ受け取るか。 */
    private const TRANSFER = [
        'Intrusive' => 1.0,
        'Functional' => 0.75,
        'Model' => 0.5,
        'Contract' => 0.25,
    ];

    /** @var array<string, int> */
    private array $inferred = [];

    /** @var array<string, bool> */
    private array $raised = [];

    /**
     * @param array<string, int> $observed モジュール => 観測された変動性（1 から 4）
     * @param list<array{from: string, to: string, strength: Strength}> $dependencies
     */
    public function __construct(array $observed, array $dependencies)
    {
        $this->inferred = $observed;

        foreach ($dependencies as $dependency) {
            $from = $dependency['from'];
            $to = $dependency['to'];
            $transfer = self::TRANSFER[$dependency['strength']->name] ?? 0.0;
            $carried = (int) floor(($observed[$to] ?? 0) * $transfer);

            $own = $observed[$from] ?? 0;
            $current = $this->inferred[$from] ?? $own;
            if ($carried > $current) {
                $this->inferred[$from] = $carried;
            }
            if ($carried > $own) {
                $this->raised[$from] = true;
            }
        }
    }

    /** その強度の依存を通して、相手の変動性がどれだけ伝わるか。 */
    public static function carried(Strength $strength, int $targetVolatility): int
    {
        return (int) floor($targetVolatility * (self::TRANSFER[$strength->name] ?? 0.0));
    }

    public function of(string $module): int
    {
        return $this->inferred[$module] ?? 0;
    }

    /** 自分の履歴から出る値より高くなったか。 */
    public function isInherited(string $module): bool
    {
        return $this->raised[$module] ?? false;
    }
}
