<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter;

/**
 * CODEOWNERS に宣言された所有者。
 *
 * git の著者は「実際に触った人」の観測値で、CODEOWNERS は「責任を持つチーム」の宣言値にあたる。
 * 変動性を coupling-meter.yaml で宣言できるのと同じく、所有者も宣言があればそちらを優先する。
 *
 * 照合は gitignore と同じ規則で、後に書いた行が勝つ。所有者のない行は、それまでの所有者を打ち消す。
 * GitHub の例外として、末尾が * の pattern（docs/* など）はその階層だけに効き、下の階層には及ばない。
 */
final class CodeOwners
{
    /** GitHub と GitLab が探す場所。root から順に、最初に見つかったものを使う。 */
    private const LOCATIONS = ['CODEOWNERS', '.github/CODEOWNERS', 'docs/CODEOWNERS', '.gitlab/CODEOWNERS'];

    /** @var list<array{regex: string, owners: list<string>}> 書かれた順 */
    private array $rules = [];

    private function __construct()
    {
    }

    /**
     * root の CODEOWNERS を読む。なければ null。
     *
     * @param string|null $explicit --codeowners で指定されたファイル。root からの相対でも絶対でもよい
     * @throws \InvalidArgumentException 指定されたファイルがないとき
     */
    public static function discover(string $root, ?string $explicit = null): ?self
    {
        $root = rtrim($root, '/');
        if ($explicit !== null) {
            $file = str_starts_with($explicit, '/') ? $explicit : $root . '/' . $explicit;
            if (!is_file($file)) {
                throw new \InvalidArgumentException("CODEOWNERS が見つかりません: {$file}");
            }

            return self::fromString((string) file_get_contents($file));
        }

        foreach (self::LOCATIONS as $location) {
            $file = $root . '/' . $location;
            if (is_file($file)) {
                return self::fromString((string) file_get_contents($file));
            }
        }

        return null;
    }

    public static function fromString(string $content): self
    {
        $self = new self();
        foreach (preg_split('/\r?\n/', $content) ?: [] as $line) {
            $line = trim($line);
            // 空行、コメント、GitLab のセクション見出し（[Section]、^[Optional]）は読み飛ばす
            if ($line === '' || $line[0] === '#' || $line[0] === '[' || str_starts_with($line, '^[')) {
                continue;
            }
            // pattern の中の空白は \ でエスケープされる。エスケープされていない空白で区切る
            /** @var list<string> $tokens */
            $tokens = preg_split('/(?<!\\\\)\s+/', $line) ?: [];
            $pattern = str_replace('\\ ', ' ', (string) array_shift($tokens));
            if ($pattern === '') {
                continue;
            }
            $self->rules[] = ['regex' => self::regex($pattern), 'owners' => $tokens];
        }

        return $self;
    }

    /**
     * @param string $path リポジトリ相対のパス
     * @return list<string> 所有者。宣言がなければ空
     */
    public function ownersOf(string $path): array
    {
        $path = ltrim($path, '/');
        $owners = [];
        foreach ($this->rules as $rule) {
            if (preg_match($rule['regex'], $path) === 1) {
                $owners = $rule['owners'];
            }
        }

        return $owners;
    }

    /**
     * モジュールごとの所有者。そのモジュールのファイルに宣言された所有者の和集合。
     * 1 つも宣言のないモジュールは含めない（git の著者に任せる）。
     *
     * @param array<string, list<string>> $fileToModules リポジトリ相対のファイル => モジュール
     * @return array<string, list<string>> モジュール => 所有者
     */
    public function moduleOwners(array $fileToModules): array
    {
        $result = [];
        foreach ($fileToModules as $file => $modules) {
            $owners = $this->ownersOf($file);
            if ($owners === []) {
                continue;
            }
            foreach ($modules as $module) {
                foreach ($owners as $owner) {
                    if (!\in_array($owner, $result[$module] ?? [], true)) {
                        $result[$module][] = $owner;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * gitignore 風の pattern を、リポジトリ相対のパスに対する正規表現にする。
     *
     * - 先頭か途中に / があれば root からの相対、なければどの階層にも効く
     * - 末尾の / はディレクトリを指し、その下の全部に効く
     * - * は / をまたがない。** はまたぐ
     * - 末尾が * や ? で終わらない pattern は、そのパスそのものとその下の全部に効く（/apps/github など）
     */
    private static function regex(string $pattern): string
    {
        $anchored = str_starts_with($pattern, '/') || str_contains(rtrim($pattern, '/'), '/');
        $directory = str_ends_with($pattern, '/');
        $body = trim($pattern, '/');
        $endsWithWildcard = $body !== '' && \in_array($body[-1], ['*', '?'], true);

        $regex = '';
        $length = \strlen($body);
        for ($i = 0; $i < $length; ++$i) {
            $char = $body[$i];
            if ($char === '*') {
                if (substr($body, $i, 3) === '**/') {
                    $regex .= '(?:.*/)?';
                    $i += 2;
                } elseif (substr($body, $i, 2) === '**') {
                    $regex .= '.*';
                    ++$i;
                } else {
                    $regex .= '[^/]*';
                }
            } elseif ($char === '?') {
                $regex .= '[^/]';
            } else {
                $regex .= preg_quote($char, '#');
            }
        }

        $prefix = $anchored ? '^' : '^(?:.*/)?';
        $suffix = match (true) {
            $directory => '/.+$',
            $endsWithWildcard => '$',
            default => '(?:/.*)?$',
        };

        return '#' . $prefix . $regex . $suffix . '#';
    }
}
