<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Techtrain\CouplingMeter\Hints;

final class HintsTest extends TestCase
{
    /** @return list<array{string}> */
    public static function kinds(): array
    {
        return [
            ['extends'], ['use-trait'], ['static-property'], ['shared-table'],
            ['new'], ['static-call'], ['method-call'], ['container'], ['async-dispatch'],
            ['param-type'], ['return-type'], ['property-type'], ['instanceof'], ['catch'],
            ['class-const'], ['attribute'], ['string-class'], ['implements'],
        ];
    }

    #[DataProvider('kinds')]
    public function testEveryKindExplainsWhyAndWhatToDo(string $kind): void
    {
        $hint = Hints::for($kind);

        $this->assertNotSame('', $hint->why, "{$kind} の理由がない");
        $this->assertNotSame('', $hint->next, "{$kind} の直し方がない");
    }

    public function testIntrusiveKindsPointToTheNextWeakerStrength(): void
    {
        // 継承は委譲に、trait は注入に。どちらも intrusive から functional へ落とす
        $this->assertStringContainsString('委譲', Hints::for('extends')->next);
        $this->assertStringContainsString('functional', Hints::for('extends')->next);
        $this->assertStringContainsString('functional', Hints::for('use-trait')->next);
    }

    public function testFunctionalKindsPointToContract(): void
    {
        $this->assertStringContainsString('interface', Hints::for('new')->next);
        $this->assertStringContainsString('contract', Hints::for('method-call')->next);
    }

    public function testContractKindsNeedNothing(): void
    {
        // すでに契約止まりなら、これ以上弱める必要はない
        $this->assertStringContainsString('十分', Hints::for('implements')->next);
    }

    public function testUnknownKindFallsBackToAGenericHint(): void
    {
        $hint = Hints::for('something-new');

        $this->assertNotSame('', $hint->why);
        $this->assertNotSame('', $hint->next);
    }
}
