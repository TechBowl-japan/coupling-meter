<?php

declare(strict_types=1);

namespace TechBowl\CouplingMeter\Tests;

use PHPUnit\Framework\TestCase;
use TechBowl\CouplingMeter\Analyzer;
use TechBowl\CouplingMeter\Reference;
use TechBowl\CouplingMeter\Strength;

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

    private function countReferences(string $from, string $to, ?string $kind = null): int
    {
        $count = 0;
        foreach (self::$references as $reference) {
            if ($reference->from === $from && $reference->to === $to && ($kind === null || $reference->kind === $kind)) {
                ++$count;
            }
        }

        return $count;
    }

    public function testReferencesAfterAnonymousClassAreKept(): void
    {
        // 無名クラスを抜けた後の new User が、囲んでいるクラスの参照として残る
        $this->assertSame(1, $this->countReferences('Fixture\Http\ScopeController', 'Fixture\Domain\User', 'new'));
    }

    public function testAnonymousClassIsNotASource(): void
    {
        foreach (self::$references as $reference) {
            $this->assertStringNotContainsString('anonymous', $reference->from);
            $this->assertNotSame('', $reference->from);
        }
    }

    public function testFunctionOutsideClassIsNotAttributedToPreviousClass(): void
    {
        $this->assertSame(0, $this->countReferences('Fixture\Http\ScopeController', 'Fixture\Domain\LegacyUser'));
    }

    public function testVariableTypesSurviveClosures(): void
    {
        // クロージャの後でも、引数とプロパティの型からメソッド呼び出しを拾える
        $this->assertSame(1, $this->countReferences('Fixture\Http\ScopeController', 'Fixture\Domain\UserFactory', 'method-call'));
        $this->assertSame(1, $this->countReferences('Fixture\Http\ScopeController', 'Fixture\Domain\UserRepository', 'method-call'));
    }

    public function testContainerResolutionIsCountedOnce(): void
    {
        // app(UserFactory::class) は container の 1 件であり、引数の ::class を model として重ねて数えない
        $this->assertSame(1, $this->countReferences('Fixture\\Http\\UserController', 'Fixture\\Domain\\UserFactory', 'container'));
        $this->assertSame(0, $this->countReferences('Fixture\\Http\\UserController', 'Fixture\\Domain\\UserFactory', 'class-const'));
    }

    public function testContainerResolutionByStringIsCountedOnce(): void
    {
        $this->assertSame(1, $this->countReferences('Fixture\\Http\\ScopeController', 'Fixture\\Domain\\UserFactory', 'container'));
        $this->assertSame(0, $this->countReferences('Fixture\\Http\\ScopeController', 'Fixture\\Domain\\UserFactory', 'string-class'));
    }
}
