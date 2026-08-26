<?php

declare(strict_types=1);

namespace TechBowl\CouplingMeter;

/**
 * 参照と履歴を突き合わせ、モジュールの組ごとに結合バランスを組み立てる。
 *
 * 強度・距離・変動性の 3 つが同時に高い箇所ほど、変更のたびに痛む。
 */
final class BalanceReport
{
    /** 強度・距離・変動性を高低に分ける境目。4 段階のうち 3 以上を高とみなす。 */
    private const HIGH = 3;

    /** @var array<string, array<string, mixed>> */
    private array $pairs = [];

    /** @var array<string, int> */
    private array $volatilityScore = [];

    /** @var array<string, int> */
    private array $moduleCommitCount = [];

    private ?Ownership $ownership = null;

    /** @var array<string, array<string, int>> モジュール => 変更の種類 => 件数 */
    private array $changeKinds = [];

    private ?InferredVolatility $inferred = null;

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
        if ($this->git !== null) {
            $this->ownership = new Ownership($this->git->moduleAuthors($fileToModule));
            $this->changeKinds = $this->git->moduleChangeKinds($fileToModule);
        }

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
            // 代表例は強い順に残す。AI や人がその箇所だけを読めるようにするため。
            $pair['samples'][] = [
                'file' => $this->relative($reference->file),
                'line' => $reference->line,
                'kind' => $reference->kind,
                'strength' => $reference->strength->label(),
                'from' => $reference->from,
                'to' => $reference->to,
                'weight' => $reference->strength->value,
            ];
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

        $this->inferred = new InferredVolatility(
            $this->volatilityScore,
            array_map(
                static fn (array $pair): array => [
                    'from' => $pair['from'],
                    'to' => $pair['to'],
                    'strength' => $pair['strength'],
                ],
                array_values($this->pairs),
            ),
        );

        foreach ($this->pairs as $key => $pair) {
            $volatility = $this->volatilityScore[$pair['to']] ?? 1;
            $coKey = $this->coKey($pair['from'], $pair['to']);
            $coCommits = $coChanges[$coKey] ?? 0;
            $base = $this->smallerCommitCount($pair['from'], $pair['to']);

            // ほとんどのモジュールが依存する相手は共有カーネルとみなし、名前空間の距離を割り引く。
            $shared = $moduleCount > 0 && count($dependants[$pair['to']] ?? []) >= $moduleCount * 0.4;
            $distance = $shared ? max(1, $pair['distance'] - 1) : $pair['distance'];

            // 触っている人が分かれていれば、名前空間が近くても調整の労力は上がる。
            $ownershipOverlap = $this->ownership?->overlap($pair['from'], $pair['to']) ?? 1.0;
            $distantOwners = $this->ownership?->isDistant($pair['from'], $pair['to']) ?? false;
            if ($distantOwners) {
                $distance = min(4, $distance + 1);
            }

            $strength = $pair['strength'];

            $strengthHigh = $strength->value >= self::HIGH;
            $distanceHigh = $distance >= self::HIGH;
            $volatilityHigh = $volatility >= self::HIGH;

            // 原著の規則: MODULARITY = STRENGTH XOR DISTANCE、COMPLEXITY = STRENGTH AND DISTANCE。
            // 両方低い組は低凝集として、両方高い組と同じく複雑の側に置く。
            $quadrant = match (true) {
                $strengthHigh && $distanceHigh => 'tight-coupling',
                !$strengthHigh && !$distanceHigh => 'low-cohesion',
                $strengthHigh => 'high-cohesion',
                default => 'loose-coupling',
            };

            // BALANCE = (STRENGTH XOR DISTANCE) OR NOT VOLATILITY
            $balanced = ($strengthHigh xor $distanceHigh) || !$volatilityHigh;

            $this->pairs[$key]['distance'] = $distance;
            $this->pairs[$key]['shared_kernel'] = $shared;
            $this->pairs[$key]['ownership_overlap'] = round($ownershipOverlap, 2);
            $this->pairs[$key]['evolution_ratio'] = $this->evolutionRatio($pair['to']);
            $this->pairs[$key]['inferred_volatility_from'] = $this->inferred->of($pair['from']);
            $this->pairs[$key]['volatility_inherited'] = $this->inferred->isInherited($pair['from']);
            $this->pairs[$key]['distant_owners'] = $distantOwners;
            $this->pairs[$key]['volatility'] = $volatility;
            $this->pairs[$key]['quadrant'] = $quadrant;
            $this->pairs[$key]['balanced'] = $balanced;
            $this->pairs[$key]['co_changes'] = $coCommits;
            $this->pairs[$key]['co_change_rate'] = $base > 0 ? $coCommits / $base : 0.0;
            // 原著 10.3 の均衡結合方程式。3 つの次元を 1 から 10 の目盛りに載せて計算する。
            $gap = $this->modules->hierarchyGap($pair['from'], $pair['to']);
            if ($shared) {
                $gap = max(0, $gap - 1);
            }
            if ($distantOwners) {
                ++$gap;
            }
            $strengthValue = BalanceEquation::strengthValue($strength);
            $distanceValue = BalanceEquation::distanceValue($gap);
            $volatilityValue = BalanceEquation::volatilityValue($volatility);

            $this->pairs[$key]['strength_value'] = $strengthValue;
            $this->pairs[$key]['distance_value'] = $distanceValue;
            $this->pairs[$key]['volatility_value'] = $volatilityValue;
            $this->pairs[$key]['modularity'] = BalanceEquation::modularity($strengthValue, $distanceValue);
            $this->pairs[$key]['balance'] = BalanceEquation::balance($strengthValue, $distanceValue, $volatilityValue);
        }

        foreach ($this->pairs as $key => $pair) {
            $samples = $pair['samples'];
            usort($samples, static fn (array $a, array $b): int => $b['weight'] <=> $a['weight']);
            $this->pairs[$key]['samples'] = array_map(
                static function (array $sample): array {
                    unset($sample['weight']);

                    return $sample;
                },
                array_slice($samples, 0, 3),
            );
        }

        // 均衡度が低いほど複雑性に傾いている。低い順に並べる。
        uasort($this->pairs, static function (array $a, array $b): int {
            return [$a['balance'], -$b['co_change_rate'], -$b['references']]
                <=> [$b['balance'], -$a['co_change_rate'], -$a['references']];
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
        foreach ($this->moduleCommitCount as $module => $count) {
            $this->volatilityScore[$module] = Volatility::quartile($counts, $count);
        }
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

            // 強度と距離が両方高い。原著の COMPLEXITY = STRENGTH AND DISTANCE にあたる。
            if ($pair['quadrant'] === 'tight-coupling' && !$pair['balanced'] && $pair['references'] >= 20) {
                $findings[] = [
                    'type' => 'tight-coupling',
                    'pair' => $key,
                    'detail' => sprintf(
                        '距離 %d の相手に %s で依存（%d 箇所）。相手はよく変わっている',
                        $pair['distance'],
                        $strength->label(),
                        $pair['references'],
                    ),
                    'data' => $pair,
                ];
            }

            // 自分はあまり変わらないのに、よく変わる相手と強く結びついている組。
            $ownVolatility = $this->volatilityScore[$pair['from']] ?? 1;
            $carried = InferredVolatility::carried($strength, $this->volatilityScore[$pair['to']] ?? 1);
            if ($carried > $ownVolatility
                && $strength->value >= 3
                && $ownVolatility <= 2
                && $pair['references'] >= 20
            ) {
                $findings[] = [
                    'type' => 'inherited-volatility',
                    'pair' => $key,
                    'detail' => sprintf(
                        '自分はあまり変わらない（%d）が、よく変わる相手（%d）に %s で依存している',
                        $ownVolatility,
                        $this->volatilityScore[$pair['to']] ?? 1,
                        $strength->label(),
                    ),
                    'data' => $pair,
                ];
            }

            // 触っている人が分かれているのに、強く結びついている組。
            if ($pair['distant_owners'] && $strength->value >= 3 && $pair['references'] >= 20) {
                $findings[] = [
                    'type' => 'split-ownership',
                    'pair' => $key,
                    'detail' => sprintf(
                        '%s で %d 箇所つながっているが、触っている人がほとんど重なっていない',
                        $strength->label(),
                        $pair['references'],
                    ),
                    'data' => $pair,
                ];
            }

            // クラス名を文字列で書いている依存。型に現れず、リネームでも追えない。
            $stringRefs = $pair['kinds']['string-class'] ?? 0;
            if ($stringRefs >= 3) {
                $findings[] = [
                    'type' => 'string-reference',
                    'pair' => $key,
                    'detail' => sprintf(
                        'クラス名を文字列で書いている箇所が %d 件。型に現れず、名前を変えても追えない',
                        $stringRefs,
                    ),
                    'data' => $pair,
                ];
            }

            // 強度と距離が両方低い。近くに置かれているのに関係が薄く、原著では低凝集として複雑の側に入る。
            if ($pair['quadrant'] === 'low-cohesion' && !$pair['balanced'] && $pair['references'] >= 20) {
                $findings[] = [
                    'type' => 'low-cohesion',
                    'pair' => $key,
                    'detail' => sprintf(
                        '距離 %d の近さで %s の依存が %d 箇所。近くに置く理由が弱い',
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

    /**
     * そのモジュールの変更のうち、機能を足す変更が占める割合。
     * 高いほど、ビジネスが力を入れている領域とみなせる。分類できない場合は null。
     */
    private function evolutionRatio(string $module): ?float
    {
        $kinds = $this->changeKinds[$module] ?? null;
        if ($kinds === null) {
            return null;
        }

        $classified = ($kinds['Evolution'] ?? 0) + ($kinds['Correction'] ?? 0) + ($kinds['Maintenance'] ?? 0);
        if ($classified < 5) {
            return null;
        }

        return round(($kinds['Evolution'] ?? 0) / $classified, 2);
    }

    /** @return array<string, array<string, int>> */
    public function changeKinds(): array
    {
        return $this->changeKinds;
    }
}
