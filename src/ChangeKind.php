<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter;

/**
 * コミットが何の変更かを分ける。
 *
 * 原著は、変動性をコミット数だけで測ると偽陽性が出ると指摘している。バグを直す変更と
 * 機能を足す変更は意味が違い、後者が多いモジュールほどコアサブドメインの可能性が高い。
 */
enum ChangeKind
{
    case Evolution;
    case Correction;
    case Maintenance;
    case Unknown;

    public static function fromSubject(string $subject): self
    {
        if (!preg_match('/^([a-z]+)(?:\([^)]*\))?!?\s*:/i', trim($subject), $matches)) {
            return self::Unknown;
        }

        return match (strtolower($matches[1])) {
            'feat', 'perf' => self::Evolution,
            'fix' => self::Correction,
            'refactor', 'test', 'docs', 'chore', 'style', 'ci', 'build', 'revert' => self::Maintenance,
            default => self::Unknown,
        };
    }
}
