<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter;

/** ソースコード上の 1 件の参照。from が to を知っている事実を表す。 */
final class Reference
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly Strength $strength,
        public readonly string $kind,
        public readonly string $file,
        public readonly int $line,
    ) {
    }
}
