<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter\Report;

use Techtrain\CouplingMeter\Balance\Pair;

/**
 * GitHub Actions のワークフローコマンド。
 *
 * 代表例にファイルと行があるので、PR の差分にそのまま注釈として出せる。
 * 注釈は 1 ジョブあたり 10 件までしか表示されないため、出す数は呼び出す側で絞る。
 */
final class Annotations
{
    /**
     * @param array{pair: Pair, was: int|null} $entry
     */
    public static function forRegression(array $entry, string $root): string
    {
        $pair = $entry['pair'];
        $sample = $pair->samples[0] ?? null;
        $title = $entry['was'] === null
            ? \sprintf('結合バランス: 新しい組（均衡度 %d）', $pair->balance)
            : \sprintf('結合バランス: 均衡度が %d から %d へ下がった', $entry['was'], $pair->balance);

        $message = \sprintf(
            '%s -> %s は強度 %s(%d) / 距離 %d / 変動性 %d。%s',
            $pair->from,
            $pair->to,
            $pair->strength->label(),
            $pair->strengthValue,
            $pair->distanceValue,
            $pair->volatilityValue,
            $sample === null ? '' : $sample['next'],
        );

        return self::command('warning', $sample === null ? null : $sample, $root, $title, $message);
    }

    /**
     * @param array{file: string, line: int, why: string, next: string, from: string, to: string, kind: string, strength: string}|null $sample
     */
    private static function command(string $level, ?array $sample, string $root, string $title, string $message): string
    {
        $properties = ['title=' . self::escapeProperty($title)];
        if ($sample !== null) {
            $properties[] = 'file=' . self::escapeProperty(self::relative($sample['file'], $root));
            $properties[] = 'line=' . $sample['line'];
        }

        return \sprintf('::%s %s::%s', $level, implode(',', $properties), self::escapeData($message));
    }

    private static function relative(string $file, string $root): string
    {
        $prefix = rtrim($root, '/') . '/';

        return str_starts_with($file, $prefix) ? substr($file, \strlen($prefix)) : $file;
    }

    /** ワークフローコマンドのデータ部。改行はそのまま書けない */
    private static function escapeData(string $value): string
    {
        return str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $value);
    }

    /** プロパティ部はさらに , と : を逃がす */
    private static function escapeProperty(string $value): string
    {
        return str_replace(['%', "\r", "\n", ':', ','], ['%25', '%0D', '%0A', '%3A', '%2C'], $value);
    }
}
