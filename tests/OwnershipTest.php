<?php

declare(strict_types=1);

namespace Valbeat\PhpCoupling\Tests;

use PHPUnit\Framework\TestCase;
use Valbeat\PhpCoupling\Ownership;

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
}
