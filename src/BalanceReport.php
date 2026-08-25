<?php

declare(strict_types=1);

namespace Valbeat\PhpCoupling;

/**
 * 参照と履歴を突き合わせ、モジュールの組ごとに結合バランスを組み立てる。
 *
 * 強度・距離・変動の 3 つが同時に高い箇所ほど、変更のたびに痛む。
 */
final class BalanceReport
{
    /** @var array<string, array<string, mixed>> */
    private array $pairs = [];

    /** @var array<string, int> */
    private array $volatilityScore = [];

    /** @var array<string, int> */
    private array $moduleCommitCount = [];

    private int $classCount = 0;

    private int $referenceCount = 0;

    private int $commitCount = 0;

    public function __construct(
        private readonly Analyzer $analyzer,
        private readonly ModuleMap $modules,
        private readonly ?GitHistory $git,
        private readonly string $root,
    ) {
    }

    public function build(): void
    {
        $index = $this->analyzer->index();
        $references = $this->analyzer->references();
        $this->classCount = count($index);
        $this->referenceCount = count($references);

        $fileToModule = [];
        foreach ($index as $fqcn => $entry) {
            $relative = ltrim(str_replace(rtrim($this->root, '/'), '', $entry['file']), '/');
            $fileToModule[$relative] = $this->modules->moduleOf($fqcn);
        }

        $this->buildVolatility($fileToModule);
        $coChanges = $this->git?->coChanges($fileToModule) ?? [];

        foreach ($references as $reference) {
            $from = $this->modules->moduleOf($reference->from);
            $to = $this->modules->moduleOf($reference->to);
            if ($from === $to) {
                continue;
            }

            $key = $from . ' -> ' . $to;
            if (!isset($this->pairs[$key])) {
                $this->pairs[$key] = [
                    'from' => $from,
                    'to' => $to,
                    'strength' => Strength::Contract,
                    'distance' => $this->modules->distance($from, $to),
                    'kinds' => [],
                    'references' => 0,
                    'samples' => [],
                ];
            }

            $pair = &$this->pairs[$key];
            $pair['strength'] = $pair['strength']->max($reference->strength);
            $pair['kinds'][$reference->kind] = ($pair['kinds'][$reference->kind] ?? 0) + 1;
            ++$pair['references'];
            if ($reference->strength === Strength::Intrusive && count($pair['samples']) < 3) {
                $pair['samples'][] = sprintf('%s:%d (%s)', $this->relative($reference->file), $reference->line, $reference->kind);
            }
            unset($pair);
        }

        $dependants = [];
        foreach ($this->pairs as $pair) {
            $dependants[$pair['to']][$pair['from']] = true;
        }
        $moduleCount = count(array_unique(array_merge(
            array_column($this->pairs, 'from'),
            array_column($this->pairs, 'to'),
        )));

        foreach ($this->pairs as $key => $pair) {
            $volatility = $this->volatilityScore[$pair['to']] ?? 1;
            $coKey = $this->coKey($pair['from'], $pair['to']);
            $coCommits = $coChanges[$coKey] ?? 0;
            $base = $this->smallerCommitCount($pair['from'], $pair['to']);

            // ほとんどのモジュールが依存する相手は共有カーネルとみなし、名前空間の距離を割り引く。
            $shared = $moduleCount > 0 && count($dependants[$pair['to']] ?? []) >= $moduleCount * 0.4;
            $distance = $shared ? max(1, $pair['distance'] - 1) : $pair['distance'];

            $this->pairs[$key]['distance'] = $distance;
            $this->pairs[$key]['shared_kernel'] = $shared;
            $this->pairs[$key]['volatility'] = $volatility;
            $this->pairs[$key]['co_changes'] = $coCommits;
            $this->pairs[$key]['co_change_rate'] = $base > 0 ? $coCommits / $base : 0.0;
            $this->pairs[$key]['pain'] = $pair['strength']->value * $distance * $volatility;
        }

        uasort($this->pairs, static function (array $a, array $b): int {
            return [$b['pain'], $b['co_change_rate'], $b['references']]
                <=> [$a['pain'], $a['co_change_rate'], $a['references']];
        });
    }

    /** @param array<string, string> $fileToModule */
    private function buildVolatility(array $fileToModule): void
    {
        if ($this->git === null) {
            return;
        }

        $this->commitCount = $this->git->commitCount();
        $moduleCommits = $this->git->moduleCommits($fileToModule);
        foreach ($moduleCommits as $module => $commits) {
            $this->moduleCommitCount[$module] = count($commits);
        }

        $counts = array_values($this->moduleCommitCount);
        if ($counts === []) {
            return;
        }
        sort($counts);

        foreach ($this->moduleCommitCount as $module => $count) {
            $this->volatilityScore[$module] = $this->quartile($counts, $count);
        }
    }

    /** @param list<int> $sorted */
    private function quartile(array $sorted, int $value): int
    {
        $rank = 0;
        foreach ($sorted as $entry) {
            if ($entry <= $value) {
                ++$rank;
            }
        }
        $ratio = $rank / count($sorted);

        return match (true) {
            $ratio > 0.90 => 4,
            $ratio > 0.70 => 3,
            $ratio > 0.40 => 2,
            default => 1,
        };
    }

    /** 変更の少ない側を分母にする。「片方が変わるとき、もう片方も変わる割合」を見たいため。 */
    private function smallerCommitCount(string $a, string $b): int
    {
        $left = $this->moduleCommitCount[$a] ?? 0;
        $right = $this->moduleCommitCount[$b] ?? 0;
        if ($left === 0 || $right === 0) {
            return max($left, $right);
        }

        return min($left, $right);
    }

    private function coKey(string $a, string $b): string
    {
        $pair = [$a, $b];
        sort($pair);

        return $pair[0] . '|' . $pair[1];
    }

    private function relative(string $file): string
    {
        return ltrim(str_replace(rtrim($this->root, '/'), '', $file), '/');
    }

    /** @return list<array<string, mixed>> */
    public function pairs(): array
    {
        return array_values($this->pairs);
    }

    /**
     * 数値の並びからは読み取りにくい 3 つの型を名指しする。
     *
     * @return list<array{type: string, pair: string, detail: string, data: array<string, mixed>}>
     */
    public function findings(): array
    {
        $findings = [];
        $seenMutual = [];

        foreach ($this->pairs as $key => $pair) {
            $reverseKey = $pair['to'] . ' -> ' . $pair['from'];
            $reverse = $this->pairs[$reverseKey] ?? null;
            $mutualKey = $this->coKey($pair['from'], $pair['to']);
            if ($reverse !== null && !isset($seenMutual[$mutualKey])) {
                $seenMutual[$mutualKey] = true;
                $findings[] = [
                    'type' => 'mutual',
                    'pair' => $pair['from'] . ' <-> ' . $pair['to'],
                    'detail' => sprintf(
                        '互いに依存している（%s へ %d 箇所 / %s へ %d 箇所）',
                        $pair['to'],
                        $pair['references'],
                        $pair['from'],
                        $reverse['references'],
                    ),
                    'data' => $pair,
                ];
            }

            $strength = $pair['strength'];
            $rate = $pair['co_change_rate'];

            if ($strength->value >= 3 && $pair['distance'] >= 3 && $pair['references'] >= 20) {
                $findings[] = [
                    'type' => 'far-and-strong',
                    'pair' => $key,
                    'detail' => sprintf(
                        '距離 %d の相手に %s で依存（%d 箇所）',
                        $pair['distance'],
                        $strength->label(),
                        $pair['references'],
                    ),
                    'data' => $pair,
                ];
            }

            if ($strength->value <= 2 && $rate >= 0.30 && $pair['co_changes'] >= 5) {
                $findings[] = [
                    'type' => 'hidden',
                    'pair' => $key,
                    'detail' => sprintf(
                        '型の上は %s だが、%d 回のコミットで同時に変わっている（%d%%）',
                        $strength->label(),
                        $pair['co_changes'],
                        (int) round($rate * 100),
                    ),
                    'data' => $pair,
                ];
            }

            if ($strength->value >= 4 && $rate >= 0.20 && $pair['co_changes'] >= 5) {
                $findings[] = [
                    'type' => 'intrusive-and-moving',
                    'pair' => $key,
                    'detail' => sprintf(
                        '内部に踏み込んだ依存が %d 箇所あり、%d%% のコミットで同時に変わっている',
                        $pair['references'],
                        (int) round($rate * 100),
                    ),
                    'data' => $pair,
                ];
            }
        }

        return $findings;
    }

    /** @return array<string, int|float> */
    public function stats(): array
    {
        $modules = [];
        foreach ($this->pairs as $pair) {
            $modules[$pair['from']] = true;
            $modules[$pair['to']] = true;
        }

        return [
            'classes' => $this->classCount,
            'references' => $this->referenceCount,
            'modules' => count($modules),
            'pairs' => count($this->pairs),
            'commits' => $this->commitCount,
        ];
    }

    /** @return array<string, int> */
    public function moduleCommitCounts(): array
    {
        return $this->moduleCommitCount;
    }
}
