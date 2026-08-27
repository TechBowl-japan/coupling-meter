<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter;

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
    private const VALUED = ['depth', 'since', 'top', 'include', 'exclude'];

    /** 値を取らないオプション */
    private const FLAGS = ['help', 'json', 'samples'];

    /**
     * @param list<string> $includes
     * @param list<string> $excludes
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
                    throw new \InvalidArgumentException("解析対象のパスは 1 つだけ指定できます: {$argument}");
                }
                $path = $argument;

                continue;
            }

            $body = substr($argument, 2);
            $hasValue = str_contains($body, '=');
            [$name, $value] = $hasValue ? explode('=', $body, 2) : [$body, null];

            if (in_array($name, self::FLAGS, true)) {
                if ($hasValue) {
                    throw new \InvalidArgumentException("--{$name} は値を取りません");
                }
                $flags[$name] = true;

                continue;
            }

            if (in_array($name, self::VALUED, true)) {
                if (!$hasValue) {
                    throw new \InvalidArgumentException("--{$name} には値が必要です（例: --{$name}=...）");
                }
                $values[$name] = (string) $value;

                continue;
            }

            throw new \InvalidArgumentException("不明なオプションです: --{$name}");
        }

        if (isset($flags['json'], $flags['samples'])) {
            throw new \InvalidArgumentException('--json と --samples は同時に指定できません');
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
            json: $flags['json'] ?? false,
            samples: $flags['samples'] ?? false,
            help: $flags['help'] ?? false,
        );
    }

    private static function integer(string $name, string $value): int
    {
        if (!ctype_digit($value) || (int) $value < 1) {
            throw new \InvalidArgumentException("--{$name} には 1 以上の整数を指定してください: {$value}");
        }

        return (int) $value;
    }

    /** @return list<string> */
    private static function list(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $s): bool => $s !== ''));
    }
}
