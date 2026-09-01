<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter\Tests;

use PHPUnit\Framework\TestCase;
use Techtrain\CouplingMeter\Ownership;

final class OwnershipTest extends TestCase
{
    public function testSameAuthorsMeanFullOverlap(): void
    {
        $ownership = new Ownership(['A' => ['taro' => 5], 'B' => ['taro' => 3]]);

        $this->assertSame(1.0, $ownership->overlap('A', 'B'));
    }

    public function testDisjointAuthorsMeanNoOverlap(): void
    {
        $ownership = new Ownership(['A' => ['taro' => 5], 'B' => ['hanako' => 3]]);

        $this->assertSame(0.0, $ownership->overlap('A', 'B'));
    }

    public function testPartialOverlapIsJaccard(): void
    {
        $ownership = new Ownership([
            'A' => ['taro' => 5, 'hanako' => 2],
            'B' => ['hanako' => 3, 'jiro' => 1],
        ]);

        // 共通 1（hanako）、和集合 3（taro, hanako, jiro）
        $this->assertEqualsWithDelta(1 / 3, $ownership->overlap('A', 'B'), 0.0001);
    }

    public function testUnknownModuleFallsBackToOne(): void
    {
        $ownership = new Ownership(['A' => ['taro' => 5]]);

        // 履歴のないモジュールは、所有権による補正をしない
        $this->assertSame(1.0, $ownership->overlap('A', 'Z'));
    }

    public function testMinorContributorsAreIgnored(): void
    {
        // 全体の 1 割に満たない著者は所有者とみなさない
        $ownership = new Ownership([
            'A' => ['taro' => 100, 'guest' => 1],
            'B' => ['guest' => 1, 'hanako' => 100],
        ]);

        $this->assertSame(0.0, $ownership->overlap('A', 'B'));
    }

    public function testDistantOwnershipIsDetected(): void
    {
        $ownership = new Ownership([
            'A' => ['taro' => 10],
            'B' => ['hanako' => 10],
        ]);

        $this->assertTrue($ownership->isDistant('A', 'B'));
        $this->assertFalse($ownership->isDistant('A', 'A'));
    }

    public function testDeclaredOwnersTakePriorityOverAuthors(): void
    {
        // 同じ人が両方を書いていても、CODEOWNERS で別のチームに振られていれば離れている
        $ownership = new Ownership(
            ['A' => ['taro' => 10], 'B' => ['taro' => 10]],
            ['A' => ['@org/a'], 'B' => ['@org/b']],
        );

        $this->assertSame(0.0, $ownership->overlap('A', 'B'));
        $this->assertTrue($ownership->isDistant('A', 'B'));
        $this->assertTrue($ownership->isDeclared('A'));
    }

    public function testModulesWithoutDeclarationFallBackToAuthors(): void
    {
        $ownership = new Ownership(
            ['A' => ['taro' => 10], 'B' => ['hanako' => 10]],
            ['A' => ['@org/a']],
        );

        // B は宣言がないので著者（hanako）。A の宣言（@org/a）とは重ならない
        $this->assertSame(0.0, $ownership->overlap('A', 'B'));
        $this->assertFalse($ownership->isDeclared('B'));
    }

    public function testDeclaredOwnersAreComparedAsTeams(): void
    {
        // 両方が同じチームなら、著者が誰であれ近い
        $ownership = new Ownership(
            ['A' => ['taro' => 10], 'B' => ['hanako' => 10]],
            ['A' => ['@org/core'], 'B' => ['@org/core']],
        );

        $this->assertSame(1.0, $ownership->overlap('A', 'B'));
        $this->assertFalse($ownership->isDistant('A', 'B'));
    }
}
