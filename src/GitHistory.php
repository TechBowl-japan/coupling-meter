<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter;

/** git の履歴から、実際に何がどれだけ一緒に変わってきたかを読む。 */
final class GitHistory
{
    /** @var array<string, list<string>> コミットハッシュ => 変更ファイル（リポジトリ相対） */
    private array $commits = [];

    /** @var array<string, string> コミットハッシュ => 著者名 */
    private array $authors = [];

    /** @var array<string, string> コミットハッシュ => 1 行目のメッセージ */
    private array $subjects = [];

    public function __construct(
        private readonly string $root,
        private readonly string $since = '12 months ago',
    ) {
    }

    private string $prefix = '';

    /** 解析対象が git の作業ツリーの中にあるか。履歴が空でも true。 */
    public function isRepository(): bool
    {
        exec(sprintf('git -C %s rev-parse --is-inside-work-tree >/dev/null 2>&1', escapeshellarg($this->root)), $output, $status);

        return $status === 0;
    }

    public function load(): bool
    {
        $prefix = shell_exec(sprintf('git -C %s rev-parse --show-prefix 2>/dev/null', escapeshellarg($this->root)));
        $this->prefix = trim((string) $prefix);

        $command = sprintf(
            // core.quotepath を切らないと、非 ASCII のパスが "\343\201\202" のように引用・エスケープされて出る。
            'git -C %s -c core.quotepath=false log --since=%s --no-merges --name-only --pretty=format:__C__%%H%%x09%%an%%x09%%s 2>/dev/null',
            escapeshellarg($this->root),
            escapeshellarg($this->since),
        );
        $output = shell_exec($command);
        if ($output === null || trim((string) $output) === '') {
            return false;
        }

        $current = null;
        foreach (explode("\n", (string) $output) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, '__C__')) {
                [$hash, $author, $subject] = array_pad(explode("\t", substr($line, 5), 3), 3, '');
                $current = $hash;
                $this->commits[$current] = [];
                $this->authors[$current] = $author;
                $this->subjects[$current] = $subject;

                continue;
            }
            if ($current !== null && str_ends_with($line, '.php')) {
                if ($this->prefix !== '') {
                    if (!str_starts_with($line, $this->prefix)) {
                        continue;
                    }
                    $line = substr($line, strlen($this->prefix));
                }
                $this->commits[$current][] = $line;
            }
        }

        return $this->commits !== [];
    }

    /**
     * ファイルからモジュールへの写像を受け取り、モジュール単位の変更コミット集合を返す。
     *
     * @param array<string, list<string>> $fileToModules リポジトリ相対パス => モジュール（1 ファイルに複数のこともある）
     * @return array<string, array<string, true>> モジュール => コミット集合
     */
    public function moduleCommits(array $fileToModules): array
    {
        $result = [];
        foreach ($this->commits as $hash => $files) {
            foreach ($this->modulesOf($files, $fileToModules) as $module) {
                $result[$module][$hash] = true;
            }
        }

        return $result;
    }

    /**
     * コミットが触ったファイルから、変更されたモジュールの集合を出す。
     *
     * @param list<string> $files
     * @param array<string, list<string>> $fileToModules
     * @return list<string>
     */
    private function modulesOf(array $files, array $fileToModules): array
    {
        $modules = [];
        foreach ($files as $file) {
            foreach ($fileToModules[$file] ?? [] as $module) {
                $modules[$module] = true;
            }
        }
        $modules = array_keys($modules);
        sort($modules);

        return $modules;
    }

    /**
     * 同じコミットに現れたモジュールの組を数える。
     *
     * @param array<string, list<string>> $fileToModules
     * @return array<string, int> "A|B" => 共起コミット数
     */
    public function coChanges(array $fileToModules): array
    {
        $pairs = [];
        foreach ($this->commits as $files) {
            $modules = $this->modulesOf($files, $fileToModules);
            $count = count($modules);
            for ($i = 0; $i < $count; ++$i) {
                for ($j = $i + 1; $j < $count; ++$j) {
                    $key = $modules[$i] . '|' . $modules[$j];
                    $pairs[$key] = ($pairs[$key] ?? 0) + 1;
                }
            }
        }

        return $pairs;
    }

    /**
     * モジュールごとに、誰が何回そこを変更したかを数える。
     *
     * @param array<string, list<string>> $fileToModules
     * @return array<string, array<string, int>> モジュール => 著者 => コミット数
     */
    public function moduleAuthors(array $fileToModules): array
    {
        $result = [];
        foreach ($this->commits as $hash => $files) {
            $author = $this->authors[$hash] ?? '';
            if ($author === '') {
                continue;
            }
            foreach ($this->modulesOf($files, $fileToModules) as $module) {
                $result[$module][$author] = ($result[$module][$author] ?? 0) + 1;
            }
        }

        return $result;
    }

    /**
     * モジュールごとに、変更の種類を数える。
     *
     * @param array<string, list<string>> $fileToModules
     * @return array<string, array<string, int>> モジュール => 種類のラベル => 件数
     */
    public function moduleChangeKinds(array $fileToModules): array
    {
        $result = [];
        foreach ($this->commits as $hash => $files) {
            $kind = ChangeKind::fromSubject($this->subjects[$hash] ?? '');
            foreach ($this->modulesOf($files, $fileToModules) as $module) {
                $result[$module][$kind->name] = ($result[$module][$kind->name] ?? 0) + 1;
            }
        }

        return $result;
    }
}
