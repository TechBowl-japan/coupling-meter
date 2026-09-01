<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter\Tests;

use PHPUnit\Framework\TestCase;
use Techtrain\CouplingMeter\Analyzer;
use Techtrain\CouplingMeter\BalanceReport;
use Techtrain\CouplingMeter\CodeOwners;
use Techtrain\CouplingMeter\GitHistory;
use Techtrain\CouplingMeter\ModuleMap;
use Techtrain\CouplingMeter\Options;
use Techtrain\CouplingMeter\Pair;

/**
 * 1 人が全部書いたリポジトリでも、CODEOWNERS で別チームに振られていれば距離が 1 段遠くなる。
 *
 * A と B は同じ著者がコミットしている。B は A に依存する。
 */
final class CodeOwnersReportTest extends TestCase
{
    private string $repo = '';

    protected function setUp(): void
    {
        $this->repo = sys_get_temp_dir() . '/coupling-meter-codeowners-report-' . bin2hex(random_bytes(4));
        mkdir("{$this->repo}/app/A", 0o777, true);
        mkdir("{$this->repo}/app/B", 0o777, true);
        mkdir("{$this->repo}/.github", 0o777, true);
        $this->git('init -q');
        $this->git('config user.email test@example.com');
        $this->git('config user.name tester');
        $this->git('config commit.gpgsign false');

        file_put_contents("{$this->repo}/app/A/X.php", "<?php\nnamespace App\\A;\nfinal class X {}\n");
        file_put_contents("{$this->repo}/app/B/Y.php", "<?php\nnamespace App\\B;\nuse App\\A\\X;\nfinal class Y { public function make(): X { return new X(); } }\n");
        $this->git('add app');
        $this->git('commit -q -m "feat: A and B"');
    }

    protected function tearDown(): void
    {
        exec(\sprintf('rm -rf %s', escapeshellarg($this->repo)));
    }

    private function git(string $args): void
    {
        exec(\sprintf('git -C %s %s 2>&1', escapeshellarg($this->repo), $args), $output, $status);
        $this->assertSame(0, $status, implode("\n", $output));
    }

    private function build(?CodeOwners $codeOwners): Pair
    {
        $analyzer = new Analyzer($this->repo, Options::DEFAULT_EXCLUDES);
        $analyzer->run();
        $git = new GitHistory($this->repo, '10 years ago');
        $this->assertTrue($git->load());
        $report = new BalanceReport($analyzer, new ModuleMap(2), $git, $this->repo, null, null, false, $codeOwners);
        $report->build();

        foreach ($report->pairs() as $pair) {
            if ($pair->from === 'App\\B' && $pair->to === 'App\\A') {
                return $pair;
            }
        }
        $this->fail('App\\B -> App\\A の組がない');
    }

    public function testSameAuthorMeansCloseWithoutCodeowners(): void
    {
        $pair = $this->build(null);

        $this->assertSame(1.0, $pair->ownershipOverlap);
        $this->assertFalse($pair->distantOwners);
        $this->assertFalse($pair->ownersDeclared);
    }

    public function testDeclaredTeamsOverrideObservedAuthors(): void
    {
        file_put_contents("{$this->repo}/.github/CODEOWNERS", "/app/A/ @org/team-a\n/app/B/ @org/team-b\n");
        $without = $this->build(null);
        $with = $this->build(CodeOwners::discover($this->repo));

        $this->assertSame(0.0, $with->ownershipOverlap);
        $this->assertTrue($with->distantOwners);
        $this->assertTrue($with->ownersDeclared);
        // 所有者が離れた分だけ、距離の目盛りが 1 段遠くなる
        $this->assertSame($without->distanceValue + 1, $with->distanceValue);
    }

    public function testJsonExposesWhetherOwnersWereDeclared(): void
    {
        file_put_contents("{$this->repo}/.github/CODEOWNERS", "* @org/core\n");
        $pair = $this->build(CodeOwners::discover($this->repo));

        $this->assertTrue($pair->toArray()['owners_declared']);
        $this->assertSame(1.0, $pair->ownershipOverlap);
    }
}
