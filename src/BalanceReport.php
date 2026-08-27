<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter;

/**
 * 参照と履歴を突き合わせ、モジュールの組ごとに結合バランスを組み立てる。
 *
 * 強度・距離・変動性の 3 つが同時に高い箇所ほど、変更のたびに痛む。
 */
final class BalanceReport
{
    /** 強度と変動性を高低に分ける境目。4 段階のうち 3 以上を高とみなす。距離は Distance が同じ規則で判定する。 */
    private const HIGH = 3;

    /**
     * 指摘を出す閾値。README の「指摘の種類」と対応させる。
     * 参照 1 箇所の依存でも組としては上位に来るが、指摘として名指しするのはある程度の量があるものに限る。
     */
    private const MIN_REFERENCES = 20;
    private const MIN_STRING_REFERENCES = 3;
    private const MIN_CO_CHANGES = 5;
    private const HIDDEN_CO_CHANGE_RATE = 0.30;
    private const INTRUSIVE_CO_CHANGE_RATE = 0.20;

    /** @var array<string, Pair> key => Pair。均衡度の低い順 */
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

    /** index に現れた全モジュールの数。組に現れないモジュールも含む。 */
    private int $moduleCount = 0;

    private int $referenceCount = 0;

    private int $commitCount = 0;

    public function __construct(
        private readonly Analyzer $analyzer,
        private readonly ModuleMap $modules,
        private readonly ?GitHistory $git,
        private readonly string $root,
        private readonly ?Rules $rules = null,
    ) {
    }

    public function build(): void
    {
        $index = $this->analyzer->index();
        $references = $this->analyzer->references();
        $this->classCount = \count($index);
        $this->referenceCount = \count($references);

        // 1 ファイルに複数モジュールのクラスが同居していれば、そのファイルの変更はどのモジュールにも数える。
        $fileToModule = [];
        $allModules = [];
        foreach ($index as $fqcn => $entry) {
            $relative = $this->relative($entry['file']);
            $module = $this->modules->moduleOf($fqcn);
            $allModules[$module] = true;
            if (!\in_array($module, $fileToModule[$relative] ?? [], true)) {
                $fileToModule[$relative][] = $module;
            }
        }
        $this->moduleCount = \count($allModules);

        $this->buildVolatility($fileToModule, array_keys($allModules));
        $coChanges = $this->git?->coChanges($fileToModule) ?? [];
        if ($this->git !== null) {
            $this->ownership = new Ownership($this->git->moduleAuthors($fileToModule));
            $this->changeKinds = $this->git->moduleChangeKinds($fileToModule);
        }

        // 参照をモジュールの組にまとめる。強度は最も強いもの、代表例は後で選ぶ。
        /** @var array<string, array{from: string, to: string, strength: Strength, kinds: array<string, int>, references: int, samples: list<array{file: string, line: int, kind: string, strength: string, from: string, to: string, weight: int}>}> $collected */
        $collected = [];
        foreach ($references as $reference) {
            $from = $this->modules->moduleOf($reference->from);
            $to = $this->modules->moduleOf($reference->to);
            if ($from === $to) {
                continue;
            }

            $key = $from . ' -> ' . $to;
            $entry = $collected[$key] ?? [
                'from' => $from,
                'to' => $to,
                'strength' => Strength::Contract,
                'kinds' => [],
                'references' => 0,
                'samples' => [],
            ];
            $entry['strength'] = $entry['strength']->max($reference->strength);
            $entry['kinds'][$reference->kind] = ($entry['kinds'][$reference->kind] ?? 0) + 1;
            ++$entry['references'];
            $entry['samples'][] = [
                'file' => $this->relative($reference->file),
                'line' => $reference->line,
                'kind' => $reference->kind,
                'strength' => $reference->strength->label(),
                'from' => $reference->from,
                'to' => $reference->to,
                'weight' => $reference->strength->value,
            ];
            $collected[$key] = $entry;
        }

        $dependants = [];
        $modules = [];
        foreach ($collected as $entry) {
            $dependants[$entry['to']][$entry['from']] = true;
            $modules[$entry['from']] = true;
            $modules[$entry['to']] = true;
        }
        $moduleCount = \count($modules);

        $this->inferred = new InferredVolatility(
            $this->volatilityScore,
            array_values(array_map(
                static fn (array $entry): array => [
                    'from' => $entry['from'],
                    'to' => $entry['to'],
                    'strength' => $entry['strength'],
                ],
                $collected,
            )),
        );

        $this->pairs = [];
        foreach ($collected as $key => $entry) {
            $from = $entry['from'];
            $to = $entry['to'];
            $strength = $entry['strength'];
            $volatility = $this->volatilityScore[$to] ?? 1;
            $coCommits = $coChanges[$this->coKey($from, $to)] ?? 0;
            $base = $this->smallerCommitCount($from, $to);

            // ほとんどのモジュールが依存する相手は共有カーネルとみなし、名前空間の距離を割り引く。
            $shared = $moduleCount > 0 && \count($dependants[$to] ?? []) >= $moduleCount * 0.4;

            // 触っている人が分かれていれば、名前空間が近くても調整の労力は上がる。
            $ownershipOverlap = $this->ownership?->overlap($from, $to) ?? 1.0;
            $distantOwners = $this->ownership?->isDistant($from, $to) ?? false;

            $distance = Distance::of($this->modules, $from, $to, $shared, $distantOwners);

            $strengthHigh = $strength->value >= self::HIGH;
            $distanceHigh = $distance->isHigh();
            $volatilityHigh = $volatility >= self::HIGH;

            // 原著の規則: MODULARITY = STRENGTH XOR DISTANCE、COMPLEXITY = STRENGTH AND DISTANCE。
            // 両方低い組は低凝集として、両方高い組と同じく複雑の側に置く。
            $quadrant = match (true) {
                $strengthHigh && $distanceHigh => 'tight-coupling',
                !$strengthHigh && !$distanceHigh => 'low-cohesion',
                $strengthHigh => 'high-cohesion',
                default => 'loose-coupling',
            };

            // 原著 10.3 の均衡結合方程式。3 つの次元を 1 から 10 の目盛りに載せて計算する。
            $strengthValue = BalanceEquation::strengthValue($strength);
            $distanceValue = $distance->scale;
            $volatilityValue = BalanceEquation::volatilityValue($volatility);

            $this->pairs[$key] = new Pair(
                from: $from,
                to: $to,
                strength: $strength,
                kinds: $entry['kinds'],
                references: $entry['references'],
                samples: Samples::pick($entry['samples'], 3),
                distance: $distance->level,
                sharedKernel: $shared,
                ownershipOverlap: round($ownershipOverlap, 2),
                evolutionRatio: $this->evolutionRatio($to),
                inferredVolatilityFrom: $this->inferred->of($from),
                volatilityInherited: $this->inferred->isInherited($from),
                distantOwners: $distantOwners,
                volatility: $volatility,
                quadrant: $quadrant,
                intended: $this->rules?->allows($from, $to) ?? false,
                // BALANCE = (STRENGTH XOR DISTANCE) OR NOT VOLATILITY
                balanced: ($strengthHigh xor $distanceHigh) || !$volatilityHigh,
                coChanges: $coCommits,
                coChangeRate: $base > 0 ? $coCommits / $base : 0.0,
                strengthValue: $strengthValue,
                distanceValue: $distanceValue,
                volatilityValue: $volatilityValue,
                modularity: BalanceEquation::modularity($strengthValue, $distanceValue),
                balance: BalanceEquation::balance($strengthValue, $distanceValue, $volatilityValue),
            );
        }

        // 均衡度が低いほど複雑性に傾いている。低い順に並べる。
        uasort($this->pairs, static function (Pair $a, Pair $b): int {
            return [$a->balance, -$b->coChangeRate, -$b->references]
                <=> [$b->balance, -$a->coChangeRate, -$a->references];
        });
    }

    /**
     * 期間内に変わらなかったモジュールも 0 回として分布に含める。
     * 変わったモジュールだけで順位を付けると、少数の変更が全員「最上位」になってしまう。
     *
     * @param array<string, list<string>> $fileToModule
     * @param list<string> $allModules
     */
    private function buildVolatility(array $fileToModule, array $allModules): void
    {
        if ($this->git === null) {
            return;
        }

        $moduleCommits = $this->git->moduleCommits($fileToModule);

        // 解析対象のモジュールのどれかを変えたコミットだけを「解析コミット」として数える。
        $touched = [];
        foreach ($moduleCommits as $commits) {
            $touched += $commits;
        }
        $this->commitCount = \count($touched);

        foreach ($allModules as $module) {
            $this->moduleCommitCount[$module] = \count($moduleCommits[$module] ?? []);
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

    /** @return list<Pair> 均衡度の低い順 */
    public function pairs(): array
    {
        return array_values($this->pairs);
    }

    /**
     * 数値の並びからは読み取りにくい型を名指しする。
     * Rules で許可された方向の依存（intended）は、順位表には残すが指摘からは外す。
     *
     * @return list<array{type: string, pair: string, detail: string}>
     */
    public function findings(): array
    {
        $findings = [];
        $seenMutual = [];

        foreach ($this->pairs as $key => $pair) {
            $reverse = $this->pairs[$pair->to . ' -> ' . $pair->from] ?? null;
            $mutualKey = $this->coKey($pair->from, $pair->to);
            if ($reverse !== null && !isset($seenMutual[$mutualKey]) && !$pair->intended && !$reverse->intended) {
                $seenMutual[$mutualKey] = true;
                // 片方が contract 止まりなら DIP で逆転済み。互いに依存しているのではなく、意図した形。
                $inverted = match (true) {
                    $pair->strength === Strength::Contract => $pair,
                    $reverse->strength === Strength::Contract => $reverse,
                    default => null,
                };
                if ($inverted !== null) {
                    $other = $inverted === $pair ? $reverse : $pair;
                    $findings[] = [
                        'type' => 'inverted',
                        'pair' => $inverted->key(),
                        'detail' => \sprintf(
                            'interface 経由（contract）で逆転している。相手からは %s で %d 箇所',
                            $other->strength->label(),
                            $other->references,
                        ),
                    ];
                } else {
                    [$first, $second] = $pair->from < $pair->to ? [$pair, $reverse] : [$reverse, $pair];
                    $findings[] = [
                        'type' => 'mutual',
                        'pair' => $first->from . ' <-> ' . $first->to,
                        'detail' => \sprintf(
                            '互いに依存している（%s へ %s で %d 箇所 / %s へ %s で %d 箇所）',
                            $first->to,
                            $first->strength->label(),
                            $first->references,
                            $second->to,
                            $second->strength->label(),
                            $second->references,
                        ),
                    ];
                }
            }

            // 設計として認めている方向の依存は、以下の指摘の対象にしない。
            if ($pair->intended) {
                continue;
            }

            $strength = $pair->strength;
            $rate = $pair->coChangeRate;

            // 強度と距離が両方高い。原著の COMPLEXITY = STRENGTH AND DISTANCE にあたる。
            if ($pair->quadrant === 'tight-coupling' && !$pair->balanced && $pair->references >= self::MIN_REFERENCES) {
                $findings[] = [
                    'type' => 'tight-coupling',
                    'pair' => $key,
                    'detail' => \sprintf(
                        '距離 %d の相手に %s で依存（%d 箇所）。相手はよく変わっている',
                        $pair->distance,
                        $strength->label(),
                        $pair->references,
                    ),
                ];
            }

            // 自分はあまり変わらないのに、よく変わる相手と強く結びついている組。
            $ownVolatility = $this->volatilityScore[$pair->from] ?? 1;
            $carried = InferredVolatility::carried($strength, $pair->volatility);
            if ($carried > $ownVolatility
                && $strength->value >= 3
                && $ownVolatility <= 2
                && $pair->references >= self::MIN_REFERENCES
            ) {
                $findings[] = [
                    'type' => 'inherited-volatility',
                    'pair' => $key,
                    'detail' => \sprintf(
                        '自分はあまり変わらない（%d）が、よく変わる相手（%d）に %s で依存している',
                        $ownVolatility,
                        $pair->volatility,
                        $strength->label(),
                    ),
                ];
            }

            // 触っている人が分かれているのに、強く結びついている組。
            if ($pair->distantOwners && $strength->value >= 3 && $pair->references >= self::MIN_REFERENCES) {
                $findings[] = [
                    'type' => 'split-ownership',
                    'pair' => $key,
                    'detail' => \sprintf(
                        '%s で %d 箇所つながっているが、触っている人がほとんど重なっていない',
                        $strength->label(),
                        $pair->references,
                    ),
                ];
            }

            // クラス名を文字列で書いている依存。型に現れず、リネームでも追えない。
            $stringRefs = $pair->kinds['string-class'] ?? 0;
            if ($stringRefs >= self::MIN_STRING_REFERENCES) {
                $findings[] = [
                    'type' => 'string-reference',
                    'pair' => $key,
                    'detail' => \sprintf(
                        'クラス名を文字列で書いている箇所が %d 件。型に現れず、名前を変えても追えない',
                        $stringRefs,
                    ),
                ];
            }

            // 強度と距離が両方低い。近くに置かれているのに関係が薄く、原著では低凝集として複雑の側に入る。
            if ($pair->quadrant === 'low-cohesion' && !$pair->balanced && $pair->references >= self::MIN_REFERENCES) {
                $findings[] = [
                    'type' => 'low-cohesion',
                    'pair' => $key,
                    'detail' => \sprintf(
                        '距離 %d の近さで %s の依存が %d 箇所。近くに置く理由が弱い',
                        $pair->distance,
                        $strength->label(),
                        $pair->references,
                    ),
                ];
            }

            if ($strength->value <= 2 && $rate >= self::HIDDEN_CO_CHANGE_RATE && $pair->coChanges >= self::MIN_CO_CHANGES) {
                $findings[] = [
                    'type' => 'hidden',
                    'pair' => $key,
                    'detail' => \sprintf(
                        '型の上は %s だが、%d 回のコミットで同時に変わっている（%d%%）',
                        $strength->label(),
                        $pair->coChanges,
                        (int) round($rate * 100),
                    ),
                ];
            }

            if ($strength->value >= 4 && $rate >= self::INTRUSIVE_CO_CHANGE_RATE && $pair->coChanges >= self::MIN_CO_CHANGES) {
                $findings[] = [
                    'type' => 'intrusive-and-moving',
                    'pair' => $key,
                    'detail' => \sprintf(
                        '内部に踏み込んだ依存が %d 箇所あり、%d%% のコミットで同時に変わっている',
                        $pair->references,
                        (int) round($rate * 100),
                    ),
                ];
            }
        }

        return $findings;
    }

    /** @return array<string, int|float> */
    public function stats(): array
    {
        return [
            'classes' => $this->classCount,
            'references' => $this->referenceCount,
            'modules' => $this->moduleCount,
            'pairs' => \count($this->pairs),
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
