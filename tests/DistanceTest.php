<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter\Tests;

use PHPUnit\Framework\TestCase;
use Techtrain\CouplingMeter\Distance;
use Techtrain\CouplingMeter\ModuleMap;

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
}
