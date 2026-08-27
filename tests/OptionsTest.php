<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter\Tests;

use PHPUnit\Framework\TestCase;
use Techtrain\CouplingMeter\Options;

final class OptionsTest extends TestCase
{
    public function testDefaults(): void
    {
        $options = Options::parse(['/path/to/project']);

        $this->assertSame('/path/to/project', $options->path);
        $this->assertSame(2, $options->depth);
        $this->assertSame('12 months ago', $options->since);
        $this->assertSame(15, $options->top);
        $this->assertSame([], $options->includes);
        $this->assertContains('vendor', $options->excludes);
        $this->assertFalse($options->json);
        $this->assertFalse($options->samples);
        $this->assertFalse($options->help);
    }

    public function testValuesAreParsed(): void
    {
        $options = Options::parse([
            '/p', '--depth=3', '--since=6 months ago', '--top=5',
            '--include=app, src', '--exclude=legacy,old', '--json', '--samples',
        ]);

        $this->assertSame(3, $options->depth);
        $this->assertSame('6 months ago', $options->since);
        $this->assertSame(5, $options->top);
        $this->assertSame(['app', 'src'], $options->includes);
        $this->assertContains('legacy', $options->excludes);
        $this->assertContains('old', $options->excludes);
        $this->assertContains('vendor', $options->excludes);
        $this->assertTrue($options->json);
        $this->assertTrue($options->samples);
    }

    public function testHelpWithoutPath(): void
    {
        $this->assertTrue(Options::parse(['--help'])->help);
        $this->assertNull(Options::parse([])->path);
    }

    public function testValuedOptionWithoutValueIsRejected(): void
    {
        // --depth だけ渡すと深さ 1 に潰れて結果が空になっていた。黙って通さない
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--depth');

        Options::parse(['/p', '--depth']);
    }

    public function testNonNumericDepthIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Options::parse(['/p', '--top=many']);
    }

    public function testUnknownOptionIsRejected(): void
    {
        // --sinse=x のようなタイポを既定値で解析してしまわない
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--sinse');

        Options::parse(['/p', '--sinse=x']);
    }

    public function testFlagWithValueIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Options::parse(['/p', '--json=1']);
    }

    public function testExtraPositionalArgumentIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Options::parse(['/p', '/q']);
    }

    public function testJsonAndSamplesAreMutuallyExclusive(): void
    {
        // 両方渡すと --samples が黙って勝っていた
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--json と --samples');

        Options::parse(['/p', '--json', '--samples']);
    }
}
