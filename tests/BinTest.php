<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter\Tests;

use PHPUnit\Framework\TestCase;

/** bin/coupling-meter はテストから直接は読み込めないので、lint と --help で壊れていないことを見る。 */
final class BinTest extends TestCase
{
    private const BIN = __DIR__ . '/../bin/coupling-meter';

    public function testScriptHasNoSyntaxError(): void
    {
        exec(\sprintf('%s -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg(self::BIN)), $output, $status);

        $this->assertSame(0, $status, implode("\n", $output));
    }

    public function testHelpListsEveryOption(): void
    {
        exec(\sprintf('%s %s --help 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg(self::BIN)), $output, $status);
        $help = implode("\n", $output);

        $this->assertSame(0, $status, $help);
        foreach (['--depth', '--since', '--top', '--include', '--exclude', '--json', '--samples', '--rules', '--split', '--weight-by-references'] as $option) {
            $this->assertStringContainsString($option, $help);
        }
    }

    public function testFixtureRunsEndToEnd(): void
    {
        exec(\sprintf('%s %s %s --json 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg(self::BIN), escapeshellarg(__DIR__ . '/fixtures/app')), $output, $status);

        $this->assertSame(0, $status, implode("\n", $output));
        $payload = json_decode(implode("\n", $output), true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('pairs', $payload);
    }

    public function testSamplesOutputCarriesHints(): void
    {
        exec(\sprintf('%s %s %s --samples --top=1 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg(self::BIN), escapeshellarg(__DIR__ . '/fixtures/app')), $output, $status);
        $text = implode("\n", $output);

        $this->assertSame(0, $status, $text);
        // 代表例の直後に、理由と次の一手が 1 行ずつ付く
        $this->assertMatchesRegularExpression('/^- .+:\\d+  .+\\n    なぜ: .+\\n    次に: .+$/m', $text);
    }
}
