<?php

declare(strict_types=1);

namespace TechBowl\CouplingMeter;

/**
 * モジュールの組ごとに、人や AI が読む代表例を選ぶ。
 *
 * 強い参照を優先しつつ、同じ相手クラスばかりにならないよう、まず相手が異なるものを 1 件ずつ取り、
 * 余った枠を残りで埋める。
 */
final class Samples
{
    /**
     * @param list<array<string, mixed>> $samples 各要素は to と weight を持つ
     * @return list<array<string, mixed>> weight を除いた上位 $limit 件
     */
    public static function pick(array $samples, int $limit): array
    {
        usort($samples, static fn (array $a, array $b): int => $b['weight'] <=> $a['weight']);

        $picked = [];
        $rest = [];
        $seen = [];
        foreach ($samples as $sample) {
            if (count($picked) < $limit && !isset($seen[$sample['to']])) {
                $seen[$sample['to']] = true;
                $picked[] = $sample;
            } else {
                $rest[] = $sample;
            }
        }
        foreach ($rest as $sample) {
            if (count($picked) >= $limit) {
                break;
            }
            $picked[] = $sample;
        }

        usort($picked, static fn (array $a, array $b): int => $b['weight'] <=> $a['weight']);

        return array_map(static function (array $sample): array {
            unset($sample['weight']);

            return $sample;
        }, $picked);
    }
}
