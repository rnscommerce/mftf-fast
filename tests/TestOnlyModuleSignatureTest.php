<?php

declare(strict_types=1);

namespace MftfFast\Tests;

use MftfFast\HandlerCache;
use PHPUnit\Framework\TestCase;

/**
 * A test-only module keeps its XML straight under the module, not under
 * Test/Mftf, so an edit there has to go stale the same as one in vendor.
 */
final class TestOnlyModuleSignatureTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mftf-fast-test-' . bin2hex(random_bytes(6));
        foreach (['dev/tests/acceptance', 'vendor', 'var/mftf-cache'] as $dir) {
            mkdir($this->root . '/' . $dir, 0775, true);
        }
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testNewTestBesideTheAcceptanceTestsMakesTheSnapshotStale(): void
    {
        $this->fast('generate:tests');
        self::assertSame('yes', $this->testSnapshotValidity());

        $this->write('dev/tests/acceptance/tests/functional/Acme/Sandbox/Test/AcmeNewTest.xml');

        self::assertSame('STALE', $this->testSnapshotValidity());
    }

    public function testNewTestUnderACustomModulePathMakesTheSnapshotStale(): void
    {
        $env = 'CUSTOM_MODULE_PATHS=custom/Acme/Extra';
        mkdir($this->root . '/custom/Acme/Extra', 0775, true);
        $this->fast('generate:tests', $env);
        self::assertSame('yes', $this->testSnapshotValidity($env));

        $this->write('custom/Acme/Extra/Test/AcmeExtraTest.xml');

        self::assertSame('STALE', $this->testSnapshotValidity($env));
    }

    public function testNewTestChangesTheSuiteSignature(): void
    {
        $before = $this->signature('suite');

        $this->write('vendor/acme/module-sandbox/Test/Mftf/Test/AcmeNewTest.xml');

        self::assertNotSame($before, $this->signature('suite'));
    }

    public function testGeneratedCodeBesideTheAcceptanceTestsLeavesTheSnapshotAlone(): void
    {
        $this->fast('generate:tests');

        $this->write('dev/tests/acceptance/tests/functional/Magento/_generated/default/Test/AcmeCest.xml');
        $this->write('dev/tests/acceptance/tests/functional/.studio/default/tests/functional/Magento/Test/Acme.xml');

        self::assertSame('yes', $this->testSnapshotValidity());
    }

    private function write(string $relative): void
    {
        $file = $this->root . '/' . $relative;
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        file_put_contents($file, '<tests><test name="Acme"/></tests>');
    }

    private function testSnapshotValidity(string $env = ''): string
    {
        [, $out] = $this->fast('cache:status', $env);
        preg_match('/^test\s+\S+\s+(\S+)/m', $out, $m);
        return $m[1] ?? 'missing';
    }

    /** A fresh cache's signature for a type, since one cache reads the files once. */
    private function signature(string $type): string
    {
        $signature = new \ReflectionMethod(HandlerCache::class, 'signature');
        return $signature->invoke(new HandlerCache($this->root), $type);
    }

    /** @return array{0:int,1:string} exit code and everything printed */
    private function fast(string $args, string $env = ''): array
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
