<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter;

/**
 * フレームワークの規約。
 *
 * どの呼び出しが「非同期に渡している」のか、どれが「コンテナに生成を任せている」のか、
 * どのクラスがテーブルを持つのかは、フレームワークが決めていて、コードベースからは読み取れない。
 * 解析の本体に埋めると Laravel 以外で黙って取りこぼすので、名前の並びとして外に出す。
 *
 * ここに置いてよいのは「フレームワークが公式に決めていて、有限で、変わらないもの」だけ。
 * プロジェクト固有の命名規則のように無限に増えるものは、ここではなく判断の側で扱う。
 */
final class Preset
{
    /**
     * @param list<string> $containerFunctions コンテナに生成を任せる関数。app(Foo::class) など
     * @param list<string> $containerMethods   コンテナのメソッド。$container->get(Foo::class) など
     * @param list<string> $asyncStaticMethods 静的呼び出しでキューに積むもの。Job::dispatch() など
     * @param list<string> $asyncFunctions     関数呼び出しで非同期に渡すもの。event(new E) など
     * @param list<string> $asyncMethods       メソッド呼び出しで非同期に渡すもの。$bus->dispatch(new M) など
     * @param list<string> $tableBuilderMethods テーブル名を文字列で受けるビルダ。DB::table('x') など
     * @param list<string> $entityBaseClasses  継承していたらテーブルを持つとみなす親クラスの短い名前
     * @param list<string> $tableProperties    テーブル名を宣言するプロパティ名。Eloquent の $table など
     * @param list<string> $tableAttributes    テーブル名を宣言する属性の名前。ORM\Table など
     * @param bool $pluralizeEntityName        テーブル名の宣言が無いとき、クラス名の複数形を使うか
     */
    public function __construct(
        public readonly string $name,
        public readonly array $containerFunctions = [],
        public readonly array $containerMethods = [],
        public readonly array $asyncStaticMethods = [],
        public readonly array $asyncFunctions = [],
        public readonly array $asyncMethods = [],
        public readonly array $tableBuilderMethods = [],
        public readonly array $entityBaseClasses = [],
        public readonly array $tableProperties = [],
        public readonly array $tableAttributes = [],
        public readonly bool $pluralizeEntityName = false,
    ) {
    }

    public static function none(): self
    {
        return new self('none');
    }

    /**
     * coupling-meter.yaml の presets: から読む。知らないキーは綴り間違いとして弾く。
     *
     * @param array<string, mixed> $data
     * @throws \InvalidArgumentException
     */
    public static function fromArray(string $name, array $data): self
    {
        $known = [
            'container_functions', 'container_methods', 'async_static_methods', 'async_functions',
            'async_methods', 'table_builder_methods', 'entity_base_classes', 'table_properties',
            'table_attributes', 'pluralize_entity_name',
        ];
        foreach (array_keys($data) as $key) {
            if (!\in_array($key, $known, true)) {
                throw new \InvalidArgumentException("preset {$name} に不明なキーがあります: {$key}");
            }
        }

        return new self(
            name: $name,
            containerFunctions: self::names($name, $data, 'container_functions'),
            containerMethods: self::names($name, $data, 'container_methods'),
            asyncStaticMethods: self::names($name, $data, 'async_static_methods'),
            asyncFunctions: self::names($name, $data, 'async_functions'),
            asyncMethods: self::names($name, $data, 'async_methods'),
            tableBuilderMethods: self::names($name, $data, 'table_builder_methods'),
            entityBaseClasses: self::names($name, $data, 'entity_base_classes', lower: false),
            tableProperties: self::names($name, $data, 'table_properties', lower: false),
            tableAttributes: self::names($name, $data, 'table_attributes', lower: false),
            pluralizeEntityName: (bool) ($data['pluralize_entity_name'] ?? false),
        );
    }

    /** 2 つの preset を足す。同じ名前は 1 つにまとめる。 */
    public function with(self $other): self
    {
        return new self(
            name: $this->name === 'none' ? $other->name : "{$this->name}+{$other->name}",
            containerFunctions: self::union($this->containerFunctions, $other->containerFunctions),
            containerMethods: self::union($this->containerMethods, $other->containerMethods),
            asyncStaticMethods: self::union($this->asyncStaticMethods, $other->asyncStaticMethods),
            asyncFunctions: self::union($this->asyncFunctions, $other->asyncFunctions),
            asyncMethods: self::union($this->asyncMethods, $other->asyncMethods),
            tableBuilderMethods: self::union($this->tableBuilderMethods, $other->tableBuilderMethods),
            entityBaseClasses: self::union($this->entityBaseClasses, $other->entityBaseClasses),
            tableProperties: self::union($this->tableProperties, $other->tableProperties),
            tableAttributes: self::union($this->tableAttributes, $other->tableAttributes),
            pluralizeEntityName: $this->pluralizeEntityName || $other->pluralizeEntityName,
        );
    }

    /** テーブルを持つクラスを 1 つでも判定できるか */
    public function findsEntities(): bool
    {
        return $this->entityBaseClasses !== [] || $this->tableAttributes !== [];
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private static function names(string $preset, array $data, string $key, bool $lower = true): array
    {
        $value = $data[$key] ?? [];
        if (!\is_array($value)) {
            throw new \InvalidArgumentException("preset {$preset} の {$key} には名前の並びを書いてください");
        }

        $names = [];
        foreach ($value as $name) {
            if (!\is_string($name) || trim($name) === '') {
                throw new \InvalidArgumentException("preset {$preset} の {$key} に文字列でない値があります");
            }
            $names[] = $lower ? strtolower(trim($name)) : trim($name);
        }

        return array_values(array_unique($names));
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     * @return list<string>
     */
    private static function union(array $a, array $b): array
    {
        return array_values(array_unique([...$a, ...$b]));
    }
}
