<?php

declare(strict_types=1);

namespace Techtrain\CouplingMeter\Tests;

use PHPUnit\Framework\TestCase;
use Techtrain\CouplingMeter\CodeOwners;

/**
 * CODEOWNERS の照合規則（GitHub と GitLab の共通部分）。
 *
 * gitignore と同じで、後に書いた行が勝つ。所有者のない行は、それまでの所有者を打ち消す。
 */
final class CodeOwnersTest extends TestCase
{
    public function testCatchAllOwnsEverything(): void
    {
        $owners = CodeOwners::fromString("* @org/core\n");

        $this->assertSame(['@org/core'], $owners->ownersOf('app/Billing/Invoice.php'));
        $this->assertSame(['@org/core'], $owners->ownersOf('README.md'));
    }

    public function testLastMatchingLineWins(): void
    {
        $owners = CodeOwners::fromString(<<<TXT
            *              @org/core
            /app/Billing/  @org/billing
            TXT);

        $this->assertSame(['@org/billing'], $owners->ownersOf('app/Billing/Invoice.php'));
        $this->assertSame(['@org/core'], $owners->ownersOf('app/Shop/Cart.php'));
    }

    public function testLineWithoutOwnersUnsetsPreviousOwners(): void
    {
        // GitHub の例。/apps/ は @octocat だが、/apps/github だけは所有者なしに戻す
        $owners = CodeOwners::fromString(<<<TXT
            /apps/        @octocat
            /apps/github
            TXT);

        $this->assertSame(['@octocat'], $owners->ownersOf('apps/web/index.php'));
        $this->assertSame([], $owners->ownersOf('apps/github/Hook.php'));
    }

    public function testCommentsAndBlankLinesAreIgnored(): void
    {
        $owners = CodeOwners::fromString(<<<TXT
            # 全体
            *.php   @org/php

            # GitLab のセクション見出しも読み飛ばす
            [Backend]
            ^[Optional]
            TXT);

        $this->assertSame(['@org/php'], $owners->ownersOf('app/A/X.php'));
    }

    public function testMultipleOwnersOnOneLine(): void
    {
        $owners = CodeOwners::fromString("/scripts/ @doctocat @octocat someone@example.com\n");

        $this->assertSame(['@doctocat', '@octocat', 'someone@example.com'], $owners->ownersOf('scripts/build.php'));
    }

    public function testLeadingSlashAnchorsToRoot(): void
    {
        $owners = CodeOwners::fromString("/docs/ @doctocat\n");

        $this->assertSame(['@doctocat'], $owners->ownersOf('docs/index.php'));
        $this->assertSame(['@doctocat'], $owners->ownersOf('docs/build-app/troubleshooting.php'));
        // root 以外の docs は対象外
        $this->assertSame([], $owners->ownersOf('packages/foo/docs/index.php'));
    }

    public function testDirectoryWithoutSlashMatchesAnywhere(): void
    {
        // GitHub の例: apps/ はリポジトリ内のどこにある apps ディレクトリにも効く
        $owners = CodeOwners::fromString("apps/ @octocat\n");

        $this->assertSame(['@octocat'], $owners->ownersOf('apps/web/index.php'));
        $this->assertSame(['@octocat'], $owners->ownersOf('packages/foo/apps/web/index.php'));
        $this->assertSame([], $owners->ownersOf('lib/apps.php'));
    }

    public function testStarDoesNotCrossDirectories(): void
    {
        // GitHub の例: docs/* は docs 直下だけで、docs/build-app/ の下には効かない
        $owners = CodeOwners::fromString("docs/* @octocat\n");

        $this->assertSame(['@octocat'], $owners->ownersOf('docs/getting-started.php'));
        $this->assertSame([], $owners->ownersOf('docs/build-app/troubleshooting.php'));
    }

    public function testDoubleStarCrossesDirectories(): void
    {
        $owners = CodeOwners::fromString("**/logs @octocat\n");

        $this->assertSame(['@octocat'], $owners->ownersOf('build/logs/x.php'));
        $this->assertSame(['@octocat'], $owners->ownersOf('deeply/nested/logs/y.php'));
        $this->assertSame([], $owners->ownersOf('build/log.php'));
    }

    public function testExtensionPatternMatchesAnyDepth(): void
    {
        $owners = CodeOwners::fromString("*.php @org/php\n");

        $this->assertSame(['@org/php'], $owners->ownersOf('X.php'));
        $this->assertSame(['@org/php'], $owners->ownersOf('app/deep/er/X.php'));
        $this->assertSame([], $owners->ownersOf('app/x.js'));
    }

    public function testPatternWithMiddleSlashIsAnchoredToRoot(): void
    {
        // gitignore と同じで、途中に / を含む pattern は root からの相対
        $owners = CodeOwners::fromString("app/Billing/ @org/billing\n");

        $this->assertSame(['@org/billing'], $owners->ownersOf('app/Billing/Invoice.php'));
        $this->assertSame([], $owners->ownersOf('packages/foo/app/Billing/Invoice.php'));
    }

    public function testDiscoverFindsGitHubLocation(): void
    {
        $dir = sys_get_temp_dir() . '/coupling-meter-codeowners-' . bin2hex(random_bytes(4));
        mkdir($dir . '/.github', 0o777, true);
        file_put_contents($dir . '/.github/CODEOWNERS', "* @org/core\n");

        $owners = CodeOwners::discover($dir);

        $this->assertNotNull($owners);
        $this->assertSame(['@org/core'], $owners->ownersOf('app/X.php'));

        exec(\sprintf('rm -rf %s', escapeshellarg($dir)));
    }

    public function testDiscoverReturnsNullWhenAbsent(): void
    {
        $dir = sys_get_temp_dir() . '/coupling-meter-codeowners-' . bin2hex(random_bytes(4));
        mkdir($dir);

        $this->assertNull(CodeOwners::discover($dir));

        rmdir($dir);
    }

    public function testExplicitPathMustExist(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CodeOwners::discover(sys_get_temp_dir(), sys_get_temp_dir() . '/no-such-CODEOWNERS');
    }

    public function testModuleOwnersAreTheUnionOfItsFiles(): void
    {
        $owners = CodeOwners::fromString(<<<TXT
            /app/A/       @org/a
            /app/A/Sub/   @org/a-sub
            /app/B/       @org/b
            TXT);

        $moduleOwners = $owners->moduleOwners([
            'app/A/X.php' => ['App\\A'],
            'app/A/Sub/Y.php' => ['App\\A'],
            'app/B/Z.php' => ['App\\B'],
            'app/C/W.php' => ['App\\C'],
        ]);

        $this->assertSame(['@org/a', '@org/a-sub'], $moduleOwners['App\\A']);
        $this->assertSame(['@org/b'], $moduleOwners['App\\B']);
        // 所有者の宣言がないモジュールは含めない（git の著者に任せる）
        $this->assertArrayNotHasKey('App\\C', $moduleOwners);
    }
}
