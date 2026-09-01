<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter;

/**
 * モジュールを誰が持っているかを持つ。
 *
 * 原著は距離を社会技術的なものとして扱い、担当が分かれているほど変更を合わせる労力が
 * 上がるとしている。CODEOWNERS があればその宣言を、なければコミットの著者を所有者の代わりに使う。
 * 宣言と観測を混ぜて比べると、宣言のあるモジュールとないモジュールの組が全部「離れている」に
 * なってしまうので、両方に宣言があるときだけ宣言で比べ、それ以外は著者で比べる。
 */
final class Ownership
{
    /** この割合に満たない著者は、そのモジュールの所有者とみなさない。 */
    private const MINOR_RATIO = 0.1;

    /** 重なりがこの値を下回ると、所有権が離れていると判断する。 */
    private const DISTANT_THRESHOLD = 0.34;

    /** @var array<string, list<string>> モジュール => 主な著者（観測値） */
    private array $authors = [];

    /** @var array<string, list<string>> モジュール => CODEOWNERS の所有者（宣言値） */
    private array $declared = [];

    /**
     * @param array<string, array<string, int>> $moduleAuthors モジュール => 著者 => コミット数
     * @param array<string, list<string>> $declaredOwners モジュール => CODEOWNERS に宣言された所有者
     */
    public function __construct(array $moduleAuthors, array $declaredOwners = [])
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
                $this->authors[$module] = $main;
            }
        }

        foreach ($declaredOwners as $module => $owners) {
            if ($owners !== []) {
                $this->declared[$module] = array_values(array_unique($owners));
            }
        }
    }

    /**
     * 2 つのモジュールの所有者がどれだけ重なっているか。
     * 判断材料がないモジュール（履歴も宣言もない）は 1.0（近い）を返す。
     */
    public function overlap(string $a, string $b): float
    {
        [$left, $right] = $this->isDeclared($a) && $this->isDeclared($b)
            ? [$this->declared[$a], $this->declared[$b]]
            : [$this->authors[$a] ?? null, $this->authors[$b] ?? null];
        if ($left === null || $right === null) {
            return 1.0;
        }

        $shared = \count(array_intersect($left, $right));
        $union = \count(array_unique([...$left, ...$right]));

        return $union > 0 ? $shared / $union : 1.0;
    }

    public function isDistant(string $a, string $b): bool
    {
        return $this->overlap($a, $b) < self::DISTANT_THRESHOLD;
    }

    /** CODEOWNERS に所有者が宣言されているか。 */
    public function isDeclared(string $module): bool
    {
        return isset($this->declared[$module]);
    }
}
