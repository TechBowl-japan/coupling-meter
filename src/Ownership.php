<?php

declare(strict_types=1);

namespace TechBowl\CouplingMeter;

/**
 * モジュールを誰が触ってきたかを持つ。
 *
 * 原著は距離を社会技術的なものとして扱い、担当が分かれているほど変更を合わせる労力が
 * 上がるとしている。ここではコミットの著者を所有者の代わりに使う。
 */
final class Ownership
{
    /** この割合に満たない著者は、そのモジュールの所有者とみなさない。 */
    private const MINOR_RATIO = 0.1;

    /** 重なりがこの値を下回ると、所有権が離れていると判断する。 */
    private const DISTANT_THRESHOLD = 0.34;

    /** @var array<string, list<string>> モジュール => 主な著者 */
    private array $owners = [];

    /** @param array<string, array<string, int>> $moduleAuthors モジュール => 著者 => コミット数 */
    public function __construct(array $moduleAuthors)
    {
        foreach ($moduleAuthors as $module => $authors) {
            $total = array_sum($authors);
            if ($total === 0) {
                continue;
            }
            $main = [];
            foreach ($authors as $author => $count) {
                if ($count / $total >= self::MINOR_RATIO) {
                    $main[] = $author;
                }
            }
            if ($main !== []) {
                $this->owners[$module] = $main;
            }
        }
    }

    /**
     * 2 つのモジュールの所有者がどれだけ重なっているか。
     * 履歴のないモジュールは判断材料がないので 1.0（近い）を返す。
     */
    public function overlap(string $a, string $b): float
    {
        $left = $this->owners[$a] ?? null;
        $right = $this->owners[$b] ?? null;
        if ($left === null || $right === null) {
            return 1.0;
        }

        $shared = count(array_intersect($left, $right));
        $union = count(array_unique([...$left, ...$right]));

        return $union > 0 ? $shared / $union : 1.0;
    }

    public function isDistant(string $a, string $b): bool
    {
        return $this->overlap($a, $b) < self::DISTANT_THRESHOLD;
    }
}
