<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter;

/**
 * 同じテーブルを触るクラスの組を、shared-table の参照に変える。
 *
 * テーブルのスキーマという内部表現を共有しているので intrusive。方向はないので双方向に 1 件ずつ出す。
 */
final class SharedTables
{
    /**
     * @param list<array{class: string, table: string, file: string, line: int}> $usages
     * @return list<Reference>
     */
    public static function references(array $usages): array
    {
        /** @var array<string, array<string, array{file: string, line: int}>> テーブル => クラス => 最初の出現 */
        $byTable = [];
        foreach ($usages as $usage) {
            $byTable[$usage['table']][$usage['class']] ??= ['file' => $usage['file'], 'line' => $usage['line']];
        }

        $references = [];
        $seen = [];
        foreach ($byTable as $classes) {
            if (\count($classes) < 2) {
                continue;
            }
            foreach ($classes as $from => $origin) {
                foreach ($classes as $to => $_) {
                    if ($from === $to || isset($seen[$from . '|' . $to])) {
                        continue;
                    }
                    $seen[$from . '|' . $to] = true;
                    $references[] = new Reference($from, $to, Strength::Intrusive, 'shared-table', $origin['file'], $origin['line']);
                }
            }
        }

        return $references;
    }
}
