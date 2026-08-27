<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter\Tests;

use PHPUnit\Framework\TestCase;
use Techtrain\CouplingMeter\Packages;

final class PackagesTest extends TestCase
{
    public function testComposerFilesDefinePackagesByPsr4Prefix(): void
    {
        $dir = sys_get_temp_dir() . '/coupling-meter-pkg-' . bin2hex(random_bytes(4));
        mkdir($dir . '/packages/billing', 0o777, true);
        mkdir($dir . '/vendor/acme/lib', 0o777, true);
        file_put_contents($dir . '/composer.json', json_encode(['name' => 'shop/app', 'autoload' => ['psr-4' => ['App\\' => 'app/']]]));
        file_put_contents($dir . '/packages/billing/composer.json', json_encode(['name' => 'shop/billing', 'autoload' => ['psr-4' => ['Billing\\' => 'src/']]]));
        // vendor は読まない
        file_put_contents($dir . '/vendor/acme/lib/composer.json', json_encode(['name' => 'acme/lib', 'autoload' => ['psr-4' => ['Acme\\' => 'src/']]]));
        try {
            $packages = Packages::fromRoot($dir);

            $this->assertSame('shop/app', $packages->packageOf('App\Http'));
            $this->assertSame('shop/billing', $packages->packageOf('Billing\Invoice'));
            $this->assertNull($packages->packageOf('Acme\Thing'));
            $this->assertNull($packages->packageOf('Unknown\Thing'));
        } finally {
            exec(\sprintf('rm -rf %s', escapeshellarg($dir)));
        }
    }

    public function testLongestPrefixWins(): void
    {
        $packages = Packages::fromArray(['shop/app' => ['App\\'], 'shop/app-legacy' => ['App\\Legacy\\']]);

        $this->assertSame('shop/app-legacy', $packages->packageOf('App\Legacy\Reports'));
        $this->assertSame('shop/app', $packages->packageOf('App\Http'));
    }

    public function testNoComposerMeansNoPackages(): void
    {
        $this->assertNull(Packages::none()->packageOf('App\Http'));
    }
}
