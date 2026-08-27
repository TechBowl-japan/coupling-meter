<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter;

/**
 * composer パッケージの境界。
 *
 * 原著の距離は「共有する祖先」で決まる。同じ composer パッケージは同じデプロイ単位なので近く、
 * 別パッケージ（モノレポの packages/* など）は遠い。root と、vendor を除く 2 階層までの
 * composer.json から PSR-4 の接頭辞を読み、モジュールがどのパッケージに属するかを引く。
 */
final class Packages
{
    /** @var list<array{name: string, prefix: string}> 接頭辞の長い順 */
    private array $prefixes = [];

    private function __construct()
    {
    }

    public static function none(): self
    {
        return new self();
    }

    /** @param array<string, list<string>> $packages パッケージ名 => PSR-4 の接頭辞 */
    public static function fromArray(array $packages): self
    {
        $self = new self();
        foreach ($packages as $name => $prefixes) {
            foreach ($prefixes as $prefix) {
                $self->prefixes[] = ['name' => $name, 'prefix' => trim($prefix, '\\') . '\\'];
            }
        }
        usort($self->prefixes, static fn (array $a, array $b): int => \strlen($b['prefix']) <=> \strlen($a['prefix']));

        return $self;
    }

    public static function fromRoot(string $root): self
    {
        $packages = [];
        foreach (self::composerFiles($root) as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (!\is_array($decoded)) {
                continue;
            }
            $name = $decoded['name'] ?? null;
            $autoload = $decoded['autoload'] ?? null;
            $psr4 = \is_array($autoload) ? ($autoload['psr-4'] ?? null) : null;
            if (!\is_string($name) || !\is_array($psr4)) {
                continue;
            }
            foreach (array_keys($psr4) as $prefix) {
                if (\is_string($prefix) && $prefix !== '') {
                    $packages[$name][] = $prefix;
                }
            }
        }

        return self::fromArray($packages);
    }

    /** モジュール（名前空間の接頭辞）が属するパッケージ名。どのパッケージにも属さなければ null。 */
    public function packageOf(string $module): ?string
    {
        $probe = trim($module, '\\') . '\\';
        foreach ($this->prefixes as $entry) {
            if (str_starts_with($probe, $entry['prefix'])) {
                return $entry['name'];
            }
        }

        return null;
    }

    /**
     * root 直下と、vendor / node_modules を除く 2 階層までの composer.json。
     *
     * @return list<string>
     */
    private static function composerFiles(string $root): array
    {
        $files = [];
        $patterns = [
            $root . '/composer.json',
            $root . '/*/composer.json',
            $root . '/*/*/composer.json',
        ];
        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (preg_match('#/(vendor|node_modules)/#', $file) === 1) {
                    continue;
                }
                $files[] = $file;
            }
        }

        return $files;
    }
}
