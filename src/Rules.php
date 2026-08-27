<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter;

use Symfony\Component\Yaml\Yaml;

/**
 * 意図した依存の許可ルール。
 *
 * DIP で逆転した Application -> Infrastructure や、設計上の Adapter -> Http のように、
 * 設計として認めている方向の依存を「指摘」から外すために使う。順位表からは外さない。
 *
 * 2 つの書き方を受け付ける。
 *   - deptrac.yaml の layers（classLike の正規表現）と ruleset
 *   - coupling-meter.yaml の allow（"From -> To"。* をワイルドカードにできる）
 */
final class Rules
{
    /** @var list<array{from: string, to: string}> 正規表現に変換済み */
    private array $allowed = [];

    /** @var list<array{name: string, patterns: list<string>}> deptrac の層 */
    private array $layers = [];

    /** @var array<string, list<string>> 層 => 依存してよい層 */
    private array $ruleset = [];

    private function __construct()
    {
    }

    public static function none(): self
    {
        return new self();
    }

    /**
     * root にある deptrac.yaml / deptrac.config.yaml / coupling-meter.yaml を読む。なければ空のルール。
     */
    public static function discover(string $root, ?string $explicit = null): self
    {
        $candidates = $explicit !== null
            ? [$explicit]
            : [
                $root . '/coupling-meter.yaml',
                $root . '/deptrac.yaml',
                $root . '/deptrac.config.yaml',
            ];

        $rules = new self();
        foreach ($candidates as $file) {
            if (!is_file($file)) {
                if ($explicit !== null) {
                    throw new \InvalidArgumentException("ルールファイルが見つかりません: {$file}");
                }

                continue;
            }
            $parsed = Yaml::parseFile($file);
            if (!\is_array($parsed)) {
                continue;
            }
            /** @var array<string, mixed> $config 文字列キーだけを残す */
            $config = array_filter($parsed, 'is_string', ARRAY_FILTER_USE_KEY);
            $rules = $rules->merge(isset($config['deptrac']) ? self::fromDeptrac($config) : self::fromArray($config));
        }

        return $rules;
    }

    /**
     * @param array<string, mixed> $config coupling-meter.yaml の中身
     */
    public static function fromArray(array $config): self
    {
        $rules = new self();
        $allow = $config['allow'] ?? [];
        if (!\is_array($allow)) {
            throw new \InvalidArgumentException('allow はリストで書いてください');
        }
        foreach ($allow as $entry) {
            if (!\is_string($entry) || !str_contains($entry, '->')) {
                throw new \InvalidArgumentException('allow の各行は "From -> To" の形で書いてください');
            }
            [$from, $to] = array_map('trim', explode('->', $entry, 2));
            $rules->allowed[] = ['from' => self::glob($from), 'to' => self::glob($to)];
        }

        return $rules;
    }

    /**
     * @param array<string, mixed> $config deptrac.yaml の中身（トップに deptrac キーがある形）
     */
    public static function fromDeptrac(array $config): self
    {
        $rules = new self();
        $deptrac = $config['deptrac'] ?? [];
        if (!\is_array($deptrac)) {
            return $rules;
        }

        $layers = $deptrac['layers'] ?? [];
        if (\is_array($layers)) {
            foreach ($layers as $layer) {
                if (!\is_array($layer) || !\is_string($layer['name'] ?? null)) {
                    continue;
                }
                $patterns = [];
                $collectors = $layer['collectors'] ?? [];
                if (\is_array($collectors)) {
                    foreach ($collectors as $collector) {
                        if (!\is_array($collector)) {
                            continue;
                        }
                        $type = $collector['type'] ?? null;
                        $value = $collector['value'] ?? null;
                        // classLike / className / classNameRegex は FQCN への正規表現。ほかの collector（directory など）は判断しない
                        if (\in_array($type, ['classLike', 'className', 'classNameRegex'], true) && \is_string($value)) {
                            $patterns[] = '/' . str_replace('/', '\/', $value) . '/';
                        }
                    }
                }
                $rules->layers[] = ['name' => $layer['name'], 'patterns' => $patterns];
            }
        }

        $ruleset = $deptrac['ruleset'] ?? [];
        if (\is_array($ruleset)) {
            foreach ($ruleset as $layer => $targets) {
                if (!\is_string($layer)) {
                    continue;
                }
                $rules->ruleset[$layer] = \is_array($targets)
                    ? array_values(array_filter($targets, 'is_string'))
                    : [];
            }
        }

        return $rules;
    }

    /** from から to への依存が、設計として認められているか。 */
    public function allows(string $from, string $to): bool
    {
        foreach ($this->allowed as $rule) {
            if (preg_match($rule['from'], $from) === 1 && preg_match($rule['to'], $to) === 1) {
                return true;
            }
        }

        $fromLayer = $this->layerOf($from);
        $toLayer = $this->layerOf($to);
        if ($fromLayer === null || $toLayer === null) {
            return false;
        }
        if ($fromLayer === $toLayer) {
            return true;
        }

        return \in_array($toLayer, $this->ruleset[$fromLayer] ?? [], true);
    }

    public function isEmpty(): bool
    {
        return $this->allowed === [] && $this->layers === [];
    }

    private function merge(self $other): self
    {
        $merged = clone $this;
        $merged->allowed = [...$this->allowed, ...$other->allowed];
        $merged->layers = [...$this->layers, ...$other->layers];
        $ruleset = $this->ruleset;
        foreach ($other->ruleset as $layer => $targets) {
            $ruleset[$layer] = array_values(array_unique([...($ruleset[$layer] ?? []), ...$targets]));
        }
        $merged->ruleset = $ruleset;

        return $merged;
    }

    /**
     * モジュール名がどの層に属するか。モジュールは名前空間の接頭辞なので、
     * 「そのモジュール配下のクラス」を代表する文字列（末尾に \\ を足したもの）で照合する。
     */
    private function layerOf(string $module): ?string
    {
        $probe = $module . '\\';
        foreach ($this->layers as $layer) {
            foreach ($layer['patterns'] as $pattern) {
                if (preg_match($pattern, $probe) === 1 || preg_match($pattern, $module) === 1) {
                    return $layer['name'];
                }
            }
        }

        return null;
    }

    /** "App\\*" のようなワイルドカードを正規表現にする。 */
    private static function glob(string $pattern): string
    {
        return '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';
    }
}
