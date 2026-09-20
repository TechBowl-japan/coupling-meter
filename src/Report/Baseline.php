<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter\Report;

use Techtrain\CouplingMeter\Balance\Pair;

/**
 * 既知の結合を記録しておき、次の実行で「新しく出た組」と「悪くなった組」だけを取り出す。
 *
 * 既存のコードベースに当てると崩れている組はたいてい最初から大量にあるので、
 * 全部を落とすと CI が永久に赤になる。基準を 1 度置いて、そこから増えたぶんだけ止める。
 */
final class Baseline
{
    /** @param array<string, int> $balances "From -> To" => 均衡度 */
    private function __construct(private readonly array $balances)
    {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /** @throws \InvalidArgumentException 読めない、または形が違うとき */
    public static function load(string $file): self
    {
        if (!is_file($file)) {
            throw new \InvalidArgumentException("基準ファイルが見つかりません: {$file}");
        }

        $decoded = json_decode((string) file_get_contents($file), true);
        if (!\is_array($decoded) || !isset($decoded['pairs']) || !\is_array($decoded['pairs'])) {
            throw new \InvalidArgumentException("基準ファイルの形が違います（pairs がありません）: {$file}");
        }

        $balances = [];
        foreach ($decoded['pairs'] as $key => $balance) {
            if (!\is_string($key) || !\is_int($balance)) {
                throw new \InvalidArgumentException("基準ファイルの pairs は \"From -> To\": 均衡度 の形で書きます: {$file}");
            }
            $balances[$key] = $balance;
        }

        return new self($balances);
    }

    /**
     * @param list<Pair> $pairs
     */
    public static function render(array $pairs): string
    {
        $balances = [];
        foreach ($pairs as $pair) {
            $balances[self::key($pair)] = $pair->balance;
        }
        ksort($balances);

        return json_encode(
            ['version' => 1, 'pairs' => $balances],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) . "\n";
    }

    /**
     * 基準に無い組（新しく出た）と、基準より均衡度が下がった組（悪くなった）を返す。
     *
     * @param list<Pair> $pairs
     * @return list<array{pair: Pair, was: int|null}> was が null なら新しく出た組
     */
    public function regressions(array $pairs): array
    {
        $found = [];
        foreach ($pairs as $pair) {
            $key = self::key($pair);
            $was = $this->balances[$key] ?? null;
            if ($was === null || $pair->balance < $was) {
                $found[] = ['pair' => $pair, 'was' => $was];
            }
        }

        return $found;
    }

    public function isEmpty(): bool
    {
        return $this->balances === [];
    }

    private static function key(Pair $pair): string
    {
        return "{$pair->from} -> {$pair->to}";
    }
}
