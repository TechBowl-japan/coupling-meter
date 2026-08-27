<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter;

/**
 * モジュールの組 1 つ分の計測結果。from が to に依存している。
 *
 * 数値の意味は README の「何を測るか」を参照。toArray() は --json の形をそのまま返す。
 */
final class Pair
{
    /**
     * @param array<string, int> $kinds 参照の種類 => 件数
     * @param list<array{file: string, line: int, kind: string, strength: string, from: string, to: string}> $samples 代表例
     */
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly Strength $strength,
        public readonly array $kinds,
        public readonly int $references,
        public readonly array $samples,
        /** 象限判定用の距離（1 から 4） */
        public readonly int $distance,
        public readonly bool $sharedKernel,
        public readonly float $ownershipOverlap,
        public readonly ?float $evolutionRatio,
        public readonly int $inferredVolatilityFrom,
        public readonly bool $volatilityInherited,
        public readonly bool $distantOwners,
        /** 相手の観測された変動性（1 から 4） */
        public readonly int $volatility,
        /** tight-coupling / low-cohesion / high-cohesion / loose-coupling */
        public readonly string $quadrant,
        public readonly bool $balanced,
        public readonly int $coChanges,
        public readonly float $coChangeRate,
        /** 以下は原著 10.3 の 1 から 10 の目盛り */
        public readonly int $strengthValue,
        public readonly int $distanceValue,
        public readonly int $volatilityValue,
        public readonly int $modularity,
        public readonly int $balance,
    ) {
    }

    public function key(): string
    {
        return $this->from . ' -> ' . $this->to;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'strength' => $this->strength->label(),
            'kinds' => $this->kinds,
            'references' => $this->references,
            'samples' => $this->samples,
            'distance' => $this->distance,
            'shared_kernel' => $this->sharedKernel,
            'ownership_overlap' => $this->ownershipOverlap,
            'evolution_ratio' => $this->evolutionRatio,
            'inferred_volatility_from' => $this->inferredVolatilityFrom,
            'volatility_inherited' => $this->volatilityInherited,
            'distant_owners' => $this->distantOwners,
            'volatility' => $this->volatility,
            'quadrant' => $this->quadrant,
            'balanced' => $this->balanced,
            'co_changes' => $this->coChanges,
            'co_change_rate' => $this->coChangeRate,
            'strength_value' => $this->strengthValue,
            'distance_value' => $this->distanceValue,
            'volatility_value' => $this->volatilityValue,
            'modularity' => $this->modularity,
            'balance' => $this->balance,
        ];
    }
}
