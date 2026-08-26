<?php

declare(strict_types=1);

namespace Valbeat\PhpCoupling\Tests;

use PHPUnit\Framework\TestCase;
use Valbeat\PhpCoupling\Analyzer;
use Valbeat\PhpCoupling\Reference;
use Valbeat\PhpCoupling\Strength;

final class ReferenceCollectorTest extends TestCase
{
    /** @var list<Reference> */
    private static array $references;

    public static function setUpBeforeClass(): void
    {
        $analyzer = new Analyzer(__DIR__ . '/fixtures/app');
        $analyzer->run();
        self::$references = $analyzer->references();
    }

    private function find(string $from, string $to): ?Reference
    {
        $found = null;
        foreach (self::$references as $reference) {
            if ($reference->from !== $from || $reference->to !== $to) {
                continue;
            }
            if ($found === null || $reference->strength->value > $found->strength->value) {
                $found = $reference;
            }
        }

        return $found;
    }

    public function testTraitUseIsIntrusive(): void
    {
        $reference = $this->find('Fixture\Http\UserController', 'Fixture\Support\Audit');

        $this->assertNotNull($reference);
        $this->assertSame(Strength::Intrusive, $reference->strength);
    }

    public function testInterfaceDependencyStaysContract(): void
    {
        $reference = $this->find('Fixture\Http\UserController', 'Fixture\Domain\UserRepository');

        $this->assertNotNull($reference);
        $this->assertSame(Strength::Contract, $reference->strength);
    }

    public function testReturnTypeIsModel(): void
    {
        $reference = $this->find('Fixture\Http\UserController', 'Fixture\Domain\User');

        $this->assertNotNull($reference);
        $this->assertSame(Strength::Model, $reference->strength);
    }

    public function testContainerResolutionIsFunctional(): void
    {
        $reference = $this->find('Fixture\Http\UserController', 'Fixture\Domain\UserFactory');

        $this->assertNotNull($reference, 'コンテナ経由の解決が依存として拾えていない');
        $this->assertSame(Strength::Functional, $reference->strength);
    }

    public function testClassNameInStringIsDetected(): void
    {
        $reference = $this->find('Fixture\Http\UserController', 'Fixture\Domain\LegacyUser');

        $this->assertNotNull($reference, '文字列で書かれたクラス名が依存として拾えていない');
        $this->assertSame('string-class', $reference->kind);
    }

    public function testAttributeIsDetected(): void
    {
        $reference = $this->find('Fixture\Http\UserController', 'Fixture\Support\Track');

        $this->assertNotNull($reference, '属性が依存として拾えていない');
        $this->assertSame(Strength::Model, $reference->strength);
    }
}
