<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter;

/**
 * Vlad Khononov, "Balancing Coupling in Software Design" の統合強度。
 * 値が大きいほど、相手の知識をより多く共有している。
 */
enum Strength: int
{
    case Contract = 1;
    case Model = 2;
    case Functional = 3;
    case Intrusive = 4;

    public function label(): string
    {
        return match ($this) {
            self::Contract => 'contract',
            self::Model => 'model',
            self::Functional => 'functional',
            self::Intrusive => 'intrusive',
        };
    }

    public function max(self $other): self
    {
        return $this->value >= $other->value ? $this : $other;
    }
}
