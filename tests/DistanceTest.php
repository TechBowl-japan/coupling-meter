<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter\Tests;

use PHPUnit\Framework\TestCase;
use Techtrain\CouplingMeter\Distance;
use Techtrain\CouplingMeter\ModuleMap;
use Techtrain\CouplingMeter\Packages;

final class DistanceTest extends TestCase
{
    public function testGapIsTheHierarchyGapWhenNothingAdjusts(): void
    {
        $distance = Distance::of(new ModuleMap(2), 'App\Http', 'App\Domain', sharedKernel: false, distantOwners: false);

        $this->assertSame(2, $distance->gap);
    }

    public function testSharedKernelBringsTheTargetOneStepCloser(): void
    {
        $distance = Distance::of(new ModuleMap(2), 'App\Http', 'App\Domain', sharedKernel: true, distantOwners: false);

        $this->assertSame(1, $distance->gap);
    }

    public function testSharedKernelDoesNotGoBelowZero(): void
    {
        $distance = Distance::of(new ModuleMap(1), 'App', 'App', sharedKernel: true, distantOwners: false);

        $this->assertSame(0, $distance->gap);
    }

    public function testDistantOwnersPushOneStepAway(): void
    {
        $distance = Distance::of(new ModuleMap(2), 'App\Http', 'App\Domain', sharedKernel: false, distantOwners: true);

        $this->assertSame(3, $distance->gap);
    }

    public function testBothAdjustmentsCancelOut(): void
    {
        $distance = Distance::of(new ModuleMap(2), 'App\Http', 'App\Domain', sharedKernel: true, distantOwners: true);

        $this->assertSame(2, $distance->gap);
    }

    public function testLevelAndScaleComeFromTheSameGap(): void
    {
        // 象限判定の 4 段階（1 から 4）と、方程式の目盛り（2 から 7）は同じ gap から出す
        $near = Distance::of(new ModuleMap(2), 'App\Http', 'App\Domain', sharedKernel: false, distantOwners: false);
        $this->assertSame(2, $near->level);
        $this->assertFalse($near->isHigh());
        $this->assertSame(4, $near->scale);

        $far = Distance::of(new ModuleMap(2), 'App\Http', 'Package\Domain', sharedKernel: false, distantOwners: false);
        $this->assertSame(4, $far->gap);
        $this->assertSame(4, $far->level);
        $this->assertTrue($far->isHigh());
        $this->assertSame(6, $far->scale);
    }

    public function testDistantOwnersCanRaiseTheLevelToHigh(): void
    {
        $distance = Distance::of(new ModuleMap(2), 'App\Http', 'App\Domain', sharedKernel: false, distantOwners: true);

        $this->assertSame(3, $distance->level);
        $this->assertTrue($distance->isHigh());
        $this->assertSame(5, $distance->scale);
    }

    public function testAsyncOnlyPairsAreOneStepFurther(): void
    {
        // 非同期（キューやイベント）でしかつながっていない組は、実行時の距離が遠い
        $sync = Distance::of(new ModuleMap(2), 'App\\Http', 'App\\Jobs', sharedKernel: false, distantOwners: false);
        $async = Distance::of(new ModuleMap(2), 'App\\Http', 'App\\Jobs', sharedKernel: false, distantOwners: false, asyncOnly: true);

        $this->assertSame($sync->gap + 1, $async->gap);
    }

    public function testCrossPackagePairsAreTwoStepsFurther(): void
    {
        $packages = Packages::fromArray(['shop/app' => ['App\\'], 'shop/billing' => ['Billing\\']]);

        $inside = Distance::of(new ModuleMap(2), 'App\\Http', 'App\\Domain', sharedKernel: false, distantOwners: false, packages: $packages);
        $across = Distance::of(new ModuleMap(2), 'App\\Http', 'Billing\\Invoice', sharedKernel: false, distantOwners: false, packages: $packages);

        $this->assertSame(2, $inside->gap);
        $this->assertSame(4 + 2, $across->gap);
        $this->assertSame(7, $across->scale);
    }

    public function testGapInsideOnePackageIsCapped(): void
    {
        // 同じ composer パッケージ（同一デプロイ）の中では、名前空間が深くても DIST 7 にはしない
        $packages = Packages::fromArray(['shop/app' => ['App\\']]);
        $deep = Distance::of(new ModuleMap(4), 'App\\A\\B\\C', 'App\\X\\Y\\Z', sharedKernel: false, distantOwners: false, packages: $packages);

        $this->assertSame(6, $deep->scale);
    }
}
