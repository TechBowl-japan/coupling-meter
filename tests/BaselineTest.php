<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter\Tests;

use PHPUnit\Framework\TestCase;
use Techtrain\CouplingMeter\Analyzer;
use Techtrain\CouplingMeter\BalanceReport;
use Techtrain\CouplingMeter\Baseline;
use Techtrain\CouplingMeter\ModuleMap;
use Techtrain\CouplingMeter\Packages;
use Techtrain\CouplingMeter\Pair;
use Techtrain\CouplingMeter\Presets;
use Techtrain\CouplingMeter\Rules;

final class BaselineTest extends TestCase
{
    private string $file = '';

    /** @var list<Pair> */
    private array $pairs = [];

    protected function setUp(): void
    {
        $root = __DIR__ . '/fixtures/app';
        $analyzer = new Analyzer($root, preset: Presets::resolve(['laravel']));
        $analyzer->run();
        $report = new BalanceReport(
            $analyzer,
            ModuleMap::autoSplit(2, array_keys($analyzer->index()), 0),
            null,
            $root,
            Rules::none(),
            Packages::fromRoot($root),
        );
        $report->build();
        $this->pairs = $report->pairs();
        $this->file = sys_get_temp_dir() . '/coupling-meter-baseline-' . uniqid() . '.json';
    }

    protected function tearDown(): void
    {
        if ($this->file !== '' && is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function testEmptyBaselineTreatsEveryPairAsNew(): void
    {
        $regressions = Baseline::empty()->regressions($this->pairs);

        $this->assertCount(\count($this->pairs), $regressions);
        $this->assertNull($regressions[0]['was']);
    }

    public function testRecordedPairsAreNotReported(): void
    {
        file_put_contents($this->file, Baseline::render($this->pairs));

        $this->assertSame([], Baseline::load($this->file)->regressions($this->pairs));
    }

    public function testOnlyWorsePairsAreReported(): void
    {
        $pair = $this->pairs[0];
        $key = "{$pair->from} -> {$pair->to}";
        // 基準では 1 段良かったことにする。今の値のほうが低いので悪化として出る
        file_put_contents($this->file, json_encode(['version' => 1, 'pairs' => [$key => $pair->balance + 1]]));

        $regressions = Baseline::load($this->file)->regressions([$pair]);

        $this->assertCount(1, $regressions);
        $this->assertSame($pair->balance + 1, $regressions[0]['was']);
    }

    public function testImprovedPairsAreNotReported(): void
    {
        $pair = $this->pairs[0];
        $key = "{$pair->from} -> {$pair->to}";
        file_put_contents($this->file, json_encode(['version' => 1, 'pairs' => [$key => $pair->balance - 1]]));

        $this->assertSame([], Baseline::load($this->file)->regressions([$pair]));
    }

    public function testMissingFileIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Baseline::load($this->file);
    }

    public function testBrokenFileIsRejected(): void
    {
        file_put_contents($this->file, json_encode(['pairs' => ['A -> B' => 'low']]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/From -> To/');

        Baseline::load($this->file);
    }
}
