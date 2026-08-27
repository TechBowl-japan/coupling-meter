<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter\Tests;

use PHPUnit\Framework\TestCase;
use Techtrain\CouplingMeter\GitHistory;

final class GitHistoryTest extends TestCase
{
    private string $repo;

    protected function setUp(): void
    {
        $this->repo = sys_get_temp_dir() . '/coupling-meter-' . bin2hex(random_bytes(4));
        mkdir($this->repo . '/app/顧客', 0777, true);
        $this->git('init -q');
        $this->git('config user.email test@example.com');
        $this->git('config user.name tester');
        $this->git('config core.quotepath true');
        $this->git('config commit.gpgsign false');

        file_put_contents($this->repo . '/app/顧客/Customer.php', '<?php');
        file_put_contents($this->repo . '/app/Order.php', '<?php');
        $this->git('add -A');
        $this->git('commit -q -m "feat: 顧客と注文"');
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

    public function testNonAsciiPathsAreReadAsIs(): void
    {
        // core.quotepath が既定（true）でも、日本語を含むパスがエスケープされずにモジュールへ写る
        $history = new GitHistory($this->repo, '10 years ago');
        $this->assertTrue($history->load());

        $commits = $history->moduleCommits([
            'app/顧客/Customer.php' => ['App\Customer'],
            'app/Order.php' => ['App\Order'],
        ]);

        $this->assertArrayHasKey('App\Customer', $commits);
        $this->assertArrayHasKey('App\Order', $commits);
    }

    public function testCommitCountOnlyIncludesCommitsThatTouchPhpFiles(): void
    {
        file_put_contents($this->repo . '/README.md', '# doc');
        $this->git('add -A');
        $this->git('commit -q -m "docs: readme"');

        $history = new GitHistory($this->repo, '10 years ago');
        $history->load();

        // README だけのコミットは解析に使わないので数えない
        $this->assertSame(1, $history->commitCount());
    }

    public function testFileWithClassesFromSeveralModulesCountsForEach(): void
    {
        $history = new GitHistory($this->repo, '10 years ago');
        $history->load();

        // 1 ファイルに複数モジュールのクラスが同居している場合、どちらのモジュールも変更されたとみなす
        $commits = $history->moduleCommits([
            'app/Order.php' => ['App\\Order', 'App\\Billing'],
        ]);

        $this->assertArrayHasKey('App\\Order', $commits);
        $this->assertArrayHasKey('App\\Billing', $commits);
    }
}
