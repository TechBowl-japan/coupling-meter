<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter\Tests;

use PHPUnit\Framework\TestCase;
use Techtrain\CouplingMeter\Analyzer;
use Techtrain\CouplingMeter\BalanceReport;
use Techtrain\CouplingMeter\GitHistory;
use Techtrain\CouplingMeter\ModuleMap;
use Techtrain\CouplingMeter\Options;
use Techtrain\CouplingMeter\Pair;

/**
 * git 履歴つきの小さなリポジトリを作って、分母の扱いを確かめる。
 *
 * モジュール A、B、C、D のうち git にコミットされているのは A だけ。B は A に依存する。
 * C と D は誰にも依存されず、誰にも依存しない。tests/ の PHP はコミットされているが解析対象外。
 */
final class BalanceReportTest extends TestCase
{
    private string $repo;

    private BalanceReport $report;

    protected function setUp(): void
    {
        $this->repo = sys_get_temp_dir() . '/coupling-meter-report-' . bin2hex(random_bytes(4));
        foreach (['A', 'B', 'C', 'D'] as $module) {
            mkdir("{$this->repo}/app/{$module}", 0777, true);
        }
        mkdir("{$this->repo}/tests", 0777, true);
        $this->git('init -q');
        $this->git('config user.email test@example.com');
        $this->git('config user.name tester');
        $this->git('config commit.gpgsign false');

        file_put_contents("{$this->repo}/app/A/X.php", "<?php\nnamespace App\\A;\nfinal class X {}\n");
        $this->git('add app/A/X.php');
        $this->git('commit -q -m "feat: A"');

        file_put_contents("{$this->repo}/tests/XTest.php", "<?php\nnamespace Tests;\nfinal class XTest {}\n");
        $this->git('add tests/XTest.php');
        $this->git('commit -q -m "test: X"');

        // 以下はコミットしない（期間内に変更のないモジュール）
        file_put_contents("{$this->repo}/app/B/Y.php", "<?php\nnamespace App\\B;\nuse App\\A\\X;\nfinal class Y { public function make(): X { return new X(); } }\n");
        file_put_contents("{$this->repo}/app/C/Z.php", "<?php\nnamespace App\\C;\nfinal class Z {}\n");
        file_put_contents("{$this->repo}/app/D/W.php", "<?php\nnamespace App\\D;\nfinal class W {}\n");

        $analyzer = new Analyzer($this->repo, Options::DEFAULT_EXCLUDES);
        $analyzer->run();
        $git = new GitHistory($this->repo, '10 years ago');
        $this->assertTrue($git->load());
        $this->report = new BalanceReport($analyzer, new ModuleMap(2), $git, $this->repo);
        $this->report->build();
    }

    protected function tearDown(): void
    {
        exec(sprintf('rm -rf %s', escapeshellarg($this->repo)));
    }

    private function git(string $args): void
    {
        exec(sprintf('git -C %s %s 2>&1', escapeshellarg($this->repo), $args), $output, $status);
        $this->assertSame(0, $status, implode("\n", $output));
    }

    public function testOnlyChangedModuleIsTheMostVolatile(): void
    {
        // A だけが変わった。B、C、D を分布に含めると A は 4 モジュール中の最上位になる
        $pair = $this->pair('App\B', 'App\A');

        $this->assertSame(4, $pair->volatility);
    }

    public function testModuleCountIncludesModulesWithoutPairs(): void
    {
        // C と D は組に現れないが、モジュールとしては存在する
        $this->assertSame(4, $this->report->stats()['modules']);
    }

    public function testCommitCountOnlyIncludesCommitsThatTouchAnalyzedModules(): void
    {
        // tests/ だけを触ったコミットは解析に使っていないので数えない
        $this->assertSame(1, $this->report->stats()['commits']);
    }

    private function pair(string $from, string $to): Pair
    {
        foreach ($this->report->pairs() as $pair) {
            if ($pair->from === $from && $pair->to === $to) {
                return $pair;
            }
        }
        $this->fail("{$from} -> {$to} が見つからない");
    }
}
