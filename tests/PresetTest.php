<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter\Tests;

use PHPUnit\Framework\TestCase;
use Techtrain\CouplingMeter\Config\Preset;
use Techtrain\CouplingMeter\Config\Presets;
use Techtrain\CouplingMeter\Source\Analyzer;
use Techtrain\CouplingMeter\Source\Reference;
use Techtrain\CouplingMeter\Strength;

final class PresetTest extends TestCase
{
    private string $temp = '';

    protected function tearDown(): void
    {
        if ($this->temp !== '' && is_dir($this->temp)) {
            foreach (glob($this->temp . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->temp);
        }
    }

    /**
     * @param list<string> $names
     * @return list<Reference>
     */
    private function references(string $fixture, array $names): array
    {
        $analyzer = new Analyzer(__DIR__ . '/fixtures/' . $fixture, preset: Presets::resolve($names));
        $analyzer->run();

        return $analyzer->references();
    }

    /**
     * @param list<Reference> $references
     */
    private function find(array $references, string $from, string $to): ?Reference
    {
        foreach ($references as $reference) {
            if ($reference->from === $from && $reference->to === $to) {
                return $reference;
            }
        }

        return null;
    }

    public function testSymfonyMessageBusDispatchIsAsync(): void
    {
        $reference = $this->find(
            $this->references('symfony', ['symfony']),
            'Fixture\Symfony\Controller\OrderController',
            'Fixture\Symfony\Message\PlaceOrder',
        );

        $this->assertNotNull($reference);
        $this->assertSame('async-dispatch', $reference->kind);
    }

    public function testLaravelPresetDoesNotSeeTheMessageBus(): void
    {
        $reference = $this->find(
            $this->references('symfony', ['laravel']),
            'Fixture\Symfony\Controller\OrderController',
            'Fixture\Symfony\Message\PlaceOrder',
        );

        // 生成として数えられるだけで、非同期の印は付かない
        $this->assertNotNull($reference);
        $this->assertSame('new', $reference->kind);
    }

    public function testDoctrineTableAttributeAndRawSqlShareATable(): void
    {
        $reference = $this->find(
            $this->references('symfony', ['symfony']),
            'Fixture\Symfony\Entity\Product',
            'Fixture\Symfony\Legacy\ProductDao',
        );

        $this->assertNotNull($reference);
        $this->assertSame(Strength::Intrusive, $reference->strength);
        $this->assertSame('shared-table', $reference->kind);
    }

    public function testDoctrineAnnotationIsReadWhenTheAttributeIsAbsent(): void
    {
        $analyzer = new Analyzer(__DIR__ . '/fixtures/symfony', preset: Presets::resolve(['symfony']));
        $analyzer->run();

        // dtb_customer は docblock の注釈でしか宣言されていない。読めていれば他と衝突せずに済む
        $this->assertNotNull($analyzer->index()['Fixture\Symfony\Entity\Customer'] ?? null);
    }

    public function testPresetsAreMerged(): void
    {
        $merged = Presets::resolve(['laravel', 'symfony']);

        $this->assertSame('laravel+symfony', $merged->name);
        $this->assertContains('app', $merged->containerFunctions);
        $this->assertContains('dispatch', $merged->asyncMethods);
        $this->assertContains('Model', $merged->entityBaseClasses);
        $this->assertContains('ORM\Table', $merged->tableAttributes);
    }

    public function testNoneAddsNothing(): void
    {
        $preset = Presets::resolve(['none']);

        $this->assertSame('none', $preset->name);
        $this->assertFalse($preset->findsEntities());
    }

    public function testUnknownPresetIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown preset/');

        Presets::resolve(['cakephp']);
    }

    public function testCustomPresetIsReadFromConfig(): void
    {
        $this->temp = sys_get_temp_dir() . '/coupling-meter-preset-' . uniqid();
        mkdir($this->temp);
        file_put_contents($this->temp . '/coupling-meter.yaml', <<<'YAML'
            presets:
              house:
                async_methods: [publish]
                table_attributes: ["Persisted"]
            YAML);

        $custom = Presets::custom($this->temp);
        $preset = Presets::resolve(['laravel', 'house'], $custom);

        $this->assertSame('laravel+house', $preset->name);
        $this->assertContains('publish', $preset->asyncMethods);
        $this->assertContains('Persisted', $preset->tableAttributes);
    }

    public function testCustomPresetWithAnUnknownKeyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unknown key/');

        Preset::fromArray('house', ['async_method' => ['publish']]);
    }

    public function testDetectReadsComposerJson(): void
    {
        $this->temp = sys_get_temp_dir() . '/coupling-meter-detect-' . uniqid();
        mkdir($this->temp);
        file_put_contents($this->temp . '/composer.json', json_encode([
            'require' => ['php' => '>=8.2', 'symfony/framework-bundle' => '^7.0', 'doctrine/orm' => '^3.0'],
        ]));

        $this->assertSame(['symfony'], Presets::detect($this->temp));
    }
}
