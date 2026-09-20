<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter\Config;

/**
 * コマンドライン引数。
 *
 * 値が要るオプションを値なしで渡したり、綴りを間違えたりしても黙って既定値で走らないよう、
 * 受け付けるものを列挙して突き合わせる。
 */
final class Options
{
    public const DEFAULT_EXCLUDES = ['vendor', 'node_modules', 'storage', 'bootstrap/cache', 'tests', 'test'];

    /** 値が要るオプション */
    private const VALUED = ['depth', 'since', 'top', 'include', 'exclude', 'rules', 'split', 'codeowners', 'preset', 'format', 'fail-on', 'baseline'];

    /** 値を取らないオプション */
    private const FLAGS = ['help', 'json', 'samples', 'weight-by-references', 'write-baseline'];

    /** 出力の形。json と samples は --json / --samples でも指定できる */
    public const FORMATS = ['text', 'json', 'samples', 'github'];

    /**
     * @param list<string> $includes
     * @param list<string> $excludes
     * @param list<string>|null $presets
     */
    private function __construct(
        public readonly ?string $path,
        public readonly int $depth,
        public readonly string $since,
        public readonly int $top,
        public readonly array $includes,
        public readonly array $excludes,
        public readonly bool $json,
        public readonly bool $samples,
        public readonly bool $help,
        /** 許可ルールのファイル。null なら root の coupling-meter.yaml / deptrac.yaml を探す */
        public readonly ?string $rules,
        /** この数を超えるクラスを持つ名前空間は 1 段深く切る。0 なら切らない */
        public readonly int $split,
        /** 順位づけを参照数の対数で重み付けする */
        public readonly bool $weightByReferences,
        /** CODEOWNERS のファイル。null なら root / .github / docs / .gitlab を探す */
        public readonly ?string $codeowners,
        /** フレームワークの規約。null なら composer.json から推測する。空なら何も足さない */
        public readonly ?array $presets,
        /** 出力の形。self::FORMATS のいずれか */
        public readonly string $format,
        /** この均衡度以下の組があれば終了コード 1。null なら落とさない */
        public readonly ?int $failOn,
        /** 既知の組を記録したファイル。あれば、そこから増えたぶんだけを見る */
        public readonly ?string $baseline,
        /** baseline のファイルを今の計測で書き直して終わる */
        public readonly bool $writeBaseline,
    ) {
    }

    /**
     * @param list<string> $arguments $argv からプログラム名を除いたもの
     * @throws \InvalidArgumentException 受け付けられない引数があるとき
     */
    public static function parse(array $arguments): self
    {
        $path = null;
        $values = [];
        $flags = [];

        foreach ($arguments as $argument) {
            if (!str_starts_with($argument, '--')) {
                if ($path !== null) {
                    throw new \InvalidArgumentException("Only one path can be analyzed at a time: {$argument}");
                }
                $path = $argument;

                continue;
            }

            $body = substr($argument, 2);
            $hasValue = str_contains($body, '=');
            [$name, $value] = $hasValue ? explode('=', $body, 2) : [$body, null];

            if (\in_array($name, self::FLAGS, true)) {
                if ($hasValue) {
                    throw new \InvalidArgumentException("--{$name} takes no value");
                }
                $flags[$name] = true;

                continue;
            }

            if (\in_array($name, self::VALUED, true)) {
                if (!$hasValue) {
                    throw new \InvalidArgumentException("--{$name} needs a value (for example --{$name}=...)");
                }
                $values[$name] = (string) $value;

                continue;
            }

            throw new \InvalidArgumentException("Unknown option: --{$name}");
        }

        if (isset($flags['json'], $flags['samples'])) {
            throw new \InvalidArgumentException('--json and --samples cannot be combined');
        }

        $format = $values['format'] ?? match (true) {
            isset($flags['json']) => 'json',
            isset($flags['samples']) => 'samples',
            default => 'text',
        };
        if (!\in_array($format, self::FORMATS, true)) {
            throw new \InvalidArgumentException('--format must be one of ' . implode(' / ', self::FORMATS) . ': ' . $format);
        }
        if (isset($values['format']) && (isset($flags['json']) || isset($flags['samples']))) {
            throw new \InvalidArgumentException('--format cannot be combined with --json or --samples');
        }
        if (isset($flags['write-baseline']) && !isset($values['baseline'])) {
            throw new \InvalidArgumentException('--write-baseline needs --baseline=<file>');
        }

        $excludes = self::DEFAULT_EXCLUDES;
        if (isset($values['exclude'])) {
            $excludes = [...$excludes, ...self::list($values['exclude'])];
        }

        return new self(
            path: $path,
            depth: self::integer('depth', $values['depth'] ?? '2'),
            since: $values['since'] ?? '12 months ago',
            top: self::integer('top', $values['top'] ?? '15'),
            includes: isset($values['include']) ? self::list($values['include']) : [],
            excludes: $excludes,
            json: $format === 'json',
            samples: $format === 'samples',
            help: $flags['help'] ?? false,
            rules: $values['rules'] ?? null,
            split: self::integerOrZero('split', $values['split'] ?? '0'),
            weightByReferences: $flags['weight-by-references'] ?? false,
            codeowners: $values['codeowners'] ?? null,
            presets: isset($values['preset']) ? self::list($values['preset']) : null,
            format: $format,
            failOn: isset($values['fail-on']) ? self::integer('fail-on', $values['fail-on']) : null,
            baseline: $values['baseline'] ?? null,
            writeBaseline: $flags['write-baseline'] ?? false,
        );
    }

    private static function integerOrZero(string $name, string $value): int
    {
        if (!ctype_digit($value)) {
            throw new \InvalidArgumentException("--{$name} needs an integer of 0 or more: {$value}");
        }

        return (int) $value;
    }

    private static function integer(string $name, string $value): int
    {
        if (!ctype_digit($value) || (int) $value < 1) {
            throw new \InvalidArgumentException("--{$name} needs an integer of 1 or more: {$value}");
        }

        return (int) $value;
    }

    /** @return list<string> */
    private static function list(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $s): bool => $s !== ''));
    }
}
