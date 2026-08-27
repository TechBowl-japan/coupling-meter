<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter\Tests;

use PHPUnit\Framework\TestCase;
use Techtrain\CouplingMeter\Rules;

final class RulesTest extends TestCase
{
    public function testNoRulesAllowNothing(): void
    {
        $this->assertFalse(Rules::none()->allows('A', 'B'));
    }

    public function testDeptracLayersAndRulesetAreMappedToModules(): void
    {
        // deptrac の layers（classLike の正規表現）と ruleset を、モジュール名に当てはめて許可判定する
        $rules = Rules::fromDeptrac([
            'deptrac' => [
                'layers' => [
                    ['name' => 'Domain', 'collectors' => [['type' => 'classLike', 'value' => '^App\\\\Domain\\\\.*']]],
                    ['name' => 'Application', 'collectors' => [['type' => 'classLike', 'value' => '^App\\\\Application\\\\.*']]],
                    ['name' => 'Infrastructure', 'collectors' => [['type' => 'classLike', 'value' => '^App\\\\Infrastructure\\\\.*']]],
                ],
                'ruleset' => [
                    'Application' => ['Domain'],
                    'Infrastructure' => ['Domain', 'Application'],
                ],
            ],
        ]);

        $this->assertTrue($rules->allows('App\Application', 'App\Domain'));
        $this->assertTrue($rules->allows('App\Infrastructure', 'App\Application'));
        $this->assertFalse($rules->allows('App\Domain', 'App\Infrastructure'));
        // 同じ層の中は常に許可
        $this->assertTrue($rules->allows('App\Domain\Order', 'App\Domain\User'));
        // 層に属さないモジュールは判断しない
        $this->assertFalse($rules->allows('App\Legacy', 'App\Domain'));
    }

    public function testDeptracFileIsDiscoveredAtRoot(): void
    {
        $dir = sys_get_temp_dir() . '/coupling-meter-rules-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/deptrac.yaml', <<<YAML
        deptrac:
          layers:
            - name: A
              collectors:
                - type: classLike
                  value: '^X\\\\A\\\\'
            - name: B
              collectors:
                - type: classLike
                  value: '^X\\\\B\\\\'
          ruleset:
            A: [B]
        YAML);
        try {
            $rules = Rules::discover($dir);

            $this->assertTrue($rules->allows('X\A', 'X\B'));
            $this->assertFalse($rules->allows('X\B', 'X\A'));
        } finally {
            unlink($dir . '/deptrac.yaml');
            rmdir($dir);
        }
    }

    public function testCouplingMeterYamlIsDiscoveredAtRoot(): void
    {
        $dir = sys_get_temp_dir() . '/coupling-meter-rules-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/coupling-meter.yaml', "allow:\n  - 'X\\A -> X\\B'\n");
        try {
            $this->assertTrue(Rules::discover($dir)->allows('X\A', 'X\B'));
        } finally {
            unlink($dir . '/coupling-meter.yaml');
            rmdir($dir);
        }
    }
}
