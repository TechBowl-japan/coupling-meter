<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter;

/**
 * 文字列からテーブル名を取り出す。
 *
 * SQL の構文解析はしない。FROM / JOIN / INTO / UPDATE / DELETE FROM に続く識別子を拾うだけなので、
 * 取りこぼしも誤検出もある。誤検出は kind が shared-table として --samples に出るので、読めば分かる。
 */
final class TableNames
{
    private const SQL_PATTERN = '/\b(?:FROM|JOIN|INTO|UPDATE)\s+`?(?:[a-z_][a-z0-9_]*`?\.`?)?([a-z_][a-z0-9_]*)`?/i';

    /**
     * @return list<string> 出現順、重複なし、小文字
     */
    public static function fromSql(string $text): array
    {
        if (preg_match_all(self::SQL_PATTERN, $text, $matches) === 0) {
            return [];
        }

        $tables = [];
        foreach ($matches[1] as $table) {
            $table = strtolower($table);
            // "FROM cache" のような自然文を弾くため、SQL らしいキーワードが他にもあるときだけ採用する
            if (!self::looksLikeSql($text)) {
                return [];
            }
            if (!\in_array($table, $tables, true)) {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    /**
     * Eloquent の規約。クラスの短い名前を snake_case にして複数形にする。
     */
    public static function forModel(string $shortName): string
    {
        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $shortName));

        return self::pluralize($snake);
    }

    private static function looksLikeSql(string $text): bool
    {
        return preg_match('/\b(SELECT|INSERT|UPDATE|DELETE|WHERE|SET|VALUES|JOIN)\b/i', $text) === 1;
    }

    /** 英語の規則的な複数形。不規則形は Laravel が扱う範囲のうちよく出るものだけ。 */
    private static function pluralize(string $word): string
    {
        $irregular = [
            'person' => 'people',
            'child' => 'children',
            'man' => 'men',
            'woman' => 'women',
            'mouse' => 'mice',
            'foot' => 'feet',
            'tooth' => 'teeth',
        ];
        $last = $word;
        $prefix = '';
        $underscore = strrpos($word, '_');
        if ($underscore !== false) {
            $prefix = substr($word, 0, $underscore + 1);
            $last = substr($word, $underscore + 1);
        }
        if (isset($irregular[$last])) {
            return $prefix . $irregular[$last];
        }
        if (preg_match('/(s|x|z|ch|sh)$/', $last) === 1) {
            return $prefix . $last . 'es';
        }
        if (preg_match('/[^aeiou]y$/', $last) === 1) {
            return $prefix . substr($last, 0, -1) . 'ies';
        }

        return $prefix . $last . 's';
    }
}
