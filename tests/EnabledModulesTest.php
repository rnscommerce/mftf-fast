<?php

declare(strict_types=1);

namespace MftfFast\Tests;

use PHPUnit\Framework\TestCase;

/**
 * MFTF asks the running instance which modules are on, through an admin
 * token; the Magento folder's app/etc/config.php answers instead, so nothing
 * that reads the corpus needs the instance or its credentials.
 */
final class EnabledModulesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mftf-fast-test-' . bin2hex(random_bytes(6));
        foreach (['dev/tests/acceptance', 'var/mftf-cache', 'instance/vendor', 'instance/app/etc'] as $dir) {
            mkdir($this->root . '/' . $dir, 0775, true);
        }
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testTheMagentoFolderSaysWhichModulesAreOn(): void
    {
        $this->modules(['Magento_Backend' => 1, 'Magento_AdminAnalytics' => 0, 'Magento_TwoFactorAuth' => 1]);

        [$code, $out] = $this->fast('modules:enabled', $this->magentoBp());

        self::assertSame(0, $code, $out);
        self::assertSame('Magento_Backend,Magento_TwoFactorAuth', trim($out));
    }

    public function testAMagentoFolderWithoutConfigLeavesTheInstanceToBeAsked(): void
    {
        [$code, $out] = $this->fast('modules:enabled', $this->magentoBp());

        self::assertSame(1, $code);
        self::assertStringContainsString('Cannot retrieve API token', $out);
    }

    public function testAModuleSwitchedOffInTheMagentoFolderMakesTheSnapshotStale(): void
    {
        $this->modules(['Magento_Backend' => 1, 'Magento_AdminAnalytics' => 1]);
        $this->fast('generate:tests', $this->magentoBp());
        self::assertSame('yes', $this->testSnapshotValidity());

        $this->modules(['Magento_Backend' => 1, 'Magento_AdminAnalytics' => 0]);

        self::assertSame('STALE', $this->testSnapshotValidity());
    }

    /** @param array<string,int> $modules */
    private function modules(array $modules): void
    {
        file_put_contents($this->root . '/instance/app/etc/config.php', '<?php return ' . var_export(['modules' => $modules], true) . ';');
    }

    private function magentoBp(): string
    {
        return 'MAGENTO_BP=' . escapeshellarg($this->root . '/instance');
    }

    private function testSnapshotValidity(): string
    {
        [, $out] = $this->fast('cache:status', $this->magentoBp());
        preg_match('/^test\s+\S+\s+(\S+)/m', $out, $m);
        return $m[1] ?? 'missing';
    }

    /** @return array{0:int,1:string} exit code and everything printed */
    private function fast(string $args, string $env): array
    {
        $cmd = sprintf(
            '%s MFTF_UI_WORKSPACE=%s %s %s %s 2>&1',
            $env,
            escapeshellarg($this->root),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/bin/mftf-fast'),
            $args
        );
        exec($cmd, $lines, $code);
        return [$code, implode("\n", $lines)];
    }
}
