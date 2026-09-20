<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter;

use Symfony\Component\Yaml\Yaml;

/**
 * preset の解決。
 *
 * 組み込みは laravel と symfony の 2 つ。複数を足して使える（laravel,symfony）。
 * 足りないものは coupling-meter.yaml の presets: に書いて名前で呼ぶ。
 * 省略したときは composer.json から推測し、分からなければ何も足さない。
 */
final class Presets
{
    public const BUILTIN = ['laravel', 'symfony'];

    /** 組み込みの preset。知らない名前なら null。 */
    public static function builtin(string $name): ?Preset
    {
        return match ($name) {
            'laravel' => new Preset(
                name: 'laravel',
                containerFunctions: ['app', 'resolve'],
                containerMethods: ['make', 'makewith', 'bind', 'singleton', 'instance'],
                asyncStaticMethods: ['dispatch', 'dispatchafterresponse'],
                asyncFunctions: ['dispatch', 'event', 'broadcast'],
                tableBuilderMethods: ['table', 'from'],
                entityBaseClasses: ['Model'],
                tableProperties: ['table'],
                pluralizeEntityName: true,
            ),
            'symfony' => new Preset(
                name: 'symfony',
                // $container->get(Foo::class) のように、引数がクラス定数のときだけ拾う
                containerMethods: ['get'],
                // $bus->dispatch(new Message) / $dispatcher->dispatch(new Event)
                asyncMethods: ['dispatch'],
                tableBuilderMethods: ['from'],
                // Doctrine。属性でも docblock の注釈でも同じ名前で書かれる
                tableAttributes: ['ORM\Table', 'Table'],
            ),
            default => null,
        };
    }

    /**
     * 名前の並びを 1 つの preset にまとめる。"none" は何も足さない。
     *
     * @param list<string> $names
     * @param array<string, Preset> $custom coupling-meter.yaml で定義されたもの
     * @throws \InvalidArgumentException 知らない名前のとき
     */
    public static function resolve(array $names, array $custom = []): Preset
    {
        $preset = Preset::none();
        foreach ($names as $name) {
            $name = strtolower(trim($name));
            if ($name === '' || $name === 'none') {
                continue;
            }
            $found = $custom[$name] ?? self::builtin($name);
            if ($found === null) {
                $known = implode(', ', [...self::BUILTIN, ...array_keys($custom), 'none']);

                throw new \InvalidArgumentException("知らない preset です: {$name}（使えるもの: {$known}）");
            }
            $preset = $preset->with($found);
        }

        return $preset;
    }

    /**
     * composer.json の require から推測する。見つからなければ空。
     *
     * @return list<string>
     */
    public static function detect(string $root): array
    {
        $file = $root . '/composer.json';
        if (!is_file($file)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($file), true);
        if (!\is_array($decoded)) {
            return [];
        }

        $packages = [];
        foreach (['require', 'require-dev'] as $section) {
            $requirements = $decoded[$section] ?? null;
            if (\is_array($requirements)) {
                $packages = [...$packages, ...array_keys($requirements)];
            }
        }

        $found = [];
        foreach ($packages as $package) {
            $package = strtolower((string) $package);
            if (str_starts_with($package, 'laravel/') || str_starts_with($package, 'illuminate/')) {
                $found['laravel'] = true;
            }
            if (str_starts_with($package, 'symfony/') || str_starts_with($package, 'doctrine/')) {
                $found['symfony'] = true;
            }
        }

        return array_keys($found);
    }

    /**
     * coupling-meter.yaml の presets: を読む。無ければ空。
     *
     * presets:
     *   eccube:
     *     async_methods: [dispatch]
     *     table_attributes: ["ORM\\Table"]
     *
     * @return array<string, Preset>
     * @throws \InvalidArgumentException 書き方が壊れているとき
     */
    public static function custom(string $root, ?string $explicit = null): array
    {
        $file = $explicit ?? $root . '/coupling-meter.yaml';
        if (!is_file($file)) {
            return [];
        }

        $parsed = Yaml::parseFile($file);
        if (!\is_array($parsed) || !isset($parsed['presets'])) {
            return [];
        }
        if (!\is_array($parsed['presets'])) {
            throw new \InvalidArgumentException('presets: には preset の名前と定義を書いてください');
        }

        $custom = [];
        foreach ($parsed['presets'] as $name => $definition) {
            $name = strtolower((string) $name);
            if (\in_array($name, self::BUILTIN, true)) {
                throw new \InvalidArgumentException("組み込みと同じ名前の preset は定義できません: {$name}");
            }
            if (!\is_array($definition)) {
                throw new \InvalidArgumentException("preset {$name} の定義が空です");
            }
            /** @var array<string, mixed> $definition */
            $custom[$name] = Preset::fromArray($name, $definition);
        }

        return $custom;
    }
}
