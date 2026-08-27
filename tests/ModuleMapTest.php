<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter\Tests;

use PHPUnit\Framework\TestCase;
use Techtrain\CouplingMeter\ModuleMap;

final class ModuleMapTest extends TestCase
{
    public function testDepthCutsTheNamespace(): void
    {
        $map = new ModuleMap(2);

        $this->assertSame('App\Http', $map->moduleOf('App\Http\Controllers\UserController'));
        $this->assertSame('App', $map->moduleOf('App\Kernel'));
        $this->assertSame('(root)', $map->moduleOf('Standalone'));
    }

    public function testLargeModulesAreSplitOneLevelDeeper(): void
    {
        // App\Models にクラスが 3 つ以上あるなら、その下の名前空間を別モジュールとして切る
        $classes = [
            'App\Models\User', 'App\Models\Order', 'App\Models\Product',
            'App\Models\Billing\Invoice', 'App\Models\Billing\Payment',
            'App\Http\Controllers\UserController',
        ];
        $map = ModuleMap::autoSplit(2, $classes, maxClasses: 3);

        // 巨大な名前空間の直下のクラスは、そのまま
        $this->assertSame('App\Models', $map->moduleOf('App\Models\User'));
        // 巨大な名前空間の中の子名前空間は、1 段深く切る
        $this->assertSame('App\Models\Billing', $map->moduleOf('App\Models\Billing\Invoice'));
        // 閾値以下のモジュールは今までどおり
        $this->assertSame('App\Http', $map->moduleOf('App\Http\Controllers\UserController'));
    }

    public function testSplitIsRecursiveWhileTheChildIsStillLarge(): void
    {
        $classes = [];
        for ($i = 0; $i < 4; ++$i) {
            $classes[] = "App\\Models\\Billing\\Invoices\\Invoice{$i}";
        }
        $classes[] = 'App\Models\Billing\Payment';
        $map = ModuleMap::autoSplit(2, $classes, maxClasses: 3);

        // App\Models（5 クラス）も App\Models\Billing（5 クラス）も大きいので、Invoices まで切る
        $this->assertSame('App\Models\Billing\Invoices', $map->moduleOf('App\Models\Billing\Invoices\Invoice0'));
        $this->assertSame('App\Models\Billing', $map->moduleOf('App\Models\Billing\Payment'));
    }

    public function testZeroDisablesSplitting(): void
    {
        $classes = ['App\Models\A', 'App\Models\B', 'App\Models\Sub\C'];
        $map = ModuleMap::autoSplit(2, $classes, maxClasses: 0);

        $this->assertSame('App\Models', $map->moduleOf('App\Models\Sub\C'));
    }
}
