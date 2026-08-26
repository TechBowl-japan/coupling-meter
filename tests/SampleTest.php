<?php

declare(strict_types=1);

namespace TechBowl\CouplingMeter\Tests;

use PHPUnit\Framework\TestCase;
use TechBowl\CouplingMeter\Analyzer;
use TechBowl\CouplingMeter\BalanceReport;
use TechBowl\CouplingMeter\ModuleMap;

final class SampleTest extends TestCase
{
    private static BalanceReport $report;

    public static function setUpBeforeClass(): void
    {
        $analyzer = new Analyzer(__DIR__ . '/fixtures/app');
        $analyzer->run();
        self::$report = new BalanceReport($analyzer, new ModuleMap(2), null, __DIR__ . '/fixtures/app');
        self::$report->build();
    }

    public function testEachPairCarriesSamples(): void
    {
        foreach (self::$report->pairs() as $pair) {
            $this->assertNotEmpty($pair->samples, "{$pair->from} -> {$pair->to} に代表例がない");
        }
    }

    public function testSamplesPointAtFileAndLine(): void
    {
        $pairs = self::$report->pairs();
        $sample = $pairs[0]->samples[0];

        $this->assertArrayHasKey('file', $sample);
        $this->assertArrayHasKey('line', $sample);
        $this->assertArrayHasKey('kind', $sample);
        $this->assertArrayHasKey('strength', $sample);
        $this->assertGreaterThan(0, $sample['line']);
    }

    public function testStrongestReferenceComesFirst(): void
    {
        // Http -> Support は trait の use（intrusive）を含む。代表例の先頭がそれになる
        foreach (self::$report->pairs() as $pair) {
            if ($pair->from !== 'Fixture\Http' || $pair->to !== 'Fixture\Support') {
                continue;
            }
            $this->assertSame('intrusive', $pair->samples[0]['strength']);

            return;
        }
        $this->fail('Fixture\Http -> Fixture\Support の組が見つからない');
    }

    public function testSamplesAreCapped(): void
    {
        foreach (self::$report->pairs() as $pair) {
            $this->assertLessThanOrEqual(3, count($pair->samples));
        }
    }

    public function testSamplesPreferDistinctTargets(): void
    {
        // Http -> Domain は 5 種類のクラスを参照している。代表例 3 件が同じ相手に偏らない
        foreach (self::$report->pairs() as $pair) {
            if ($pair->from !== 'Fixture\\Http' || $pair->to !== 'Fixture\\Domain') {
                continue;
            }
            $targets = array_column($pair->samples, 'to');
            $this->assertCount(3, $targets);
            $this->assertSame($targets, array_values(array_unique($targets)), '代表例の相手が重複している');
            // 強い順は保つ。先頭は intrusive な extends
            $this->assertSame('intrusive', $pair->samples[0]['strength']);

            return;
        }
        $this->fail('Fixture\\Http -> Fixture\\Domain の組が見つからない');
    }
}
