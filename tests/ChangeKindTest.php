<?php

declare(strict_types=1);

namespace TechBowl\PhpCoupling\Tests;

use PHPUnit\Framework\TestCase;
use TechBowl\PhpCoupling\ChangeKind;

final class ChangeKindTest extends TestCase
{
    public function testFeatIsEvolution(): void
    {
        $this->assertSame(ChangeKind::Evolution, ChangeKind::fromSubject('feat: 通知を追加する'));
        $this->assertSame(ChangeKind::Evolution, ChangeKind::fromSubject('feat(user): 名前を編集できるようにする'));
        $this->assertSame(ChangeKind::Evolution, ChangeKind::fromSubject('perf: クエリを減らす'));
    }

    public function testFixIsCorrection(): void
    {
        $this->assertSame(ChangeKind::Correction, ChangeKind::fromSubject('fix: 例外を握りつぶしていた'));
        $this->assertSame(ChangeKind::Correction, ChangeKind::fromSubject('fix(api)!: 破壊的な修正'));
    }

    public function testRefactorAndTestAreMaintenance(): void
    {
        $this->assertSame(ChangeKind::Maintenance, ChangeKind::fromSubject('refactor: 依存を整理する'));
        $this->assertSame(ChangeKind::Maintenance, ChangeKind::fromSubject('test: ケースを足す'));
        $this->assertSame(ChangeKind::Maintenance, ChangeKind::fromSubject('chore(deps): 更新'));
    }

    public function testUnknownSubjectIsUnclassified(): void
    {
        $this->assertSame(ChangeKind::Unknown, ChangeKind::fromSubject('通知まわりを直した'));
        $this->assertSame(ChangeKind::Unknown, ChangeKind::fromSubject(''));
    }

    public function testCaseIsIgnored(): void
    {
        $this->assertSame(ChangeKind::Evolution, ChangeKind::fromSubject('Feat: 大文字でも拾う'));
    }
}
