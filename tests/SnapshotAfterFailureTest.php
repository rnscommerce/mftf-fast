<?php

declare(strict_types=1);

namespace MftfFast\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Runs bin/mftf-fast against a bare workspace whose generate:tests command is
 * the fake in tests/Fixtures, and checks what ends up in var/mftf-cache.
 */
final class SnapshotAfterFailureTest extends TestCase
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

    public function testFinishedRunIsSnapshotted(): void
    {
        [$code, $stderr] = $this->generate();

        self::assertSame(0, $code, $stderr);
        self::assertStringContainsString('snapshotted test', $stderr);
        self::assertFileExists($this->snapshot());
    }

    public function testRunKilledByAnErrorWritesNothing(): void
    {
        [$code, $stderr] = $this->generate('--die=error');

        self::assertSame(1, $code);
        self::assertStringContainsString('Error: Class "DOMDocument" not found', $stderr);
        self::assertStringNotContainsString('snapshotted', $stderr);
        self::assertSame([], glob($this->root . '/var/mftf-cache/*'));
    }

    public function testRunFailedByAnExceptionWritesNothing(): void
    {
        [$code, $stderr] = $this->generate('--die=exception');

        self::assertSame(1, $code);
        self::assertStringContainsString('Unable to parse test XML', $stderr);
        self::assertStringNotContainsString('snapshotted', $stderr);
        self::assertSame([], glob($this->root . '/var/mftf-cache/*'));
    }

    public function testFailedRunLeavesTheExistingSnapshotAlone(): void
    {
        file_put_contents($this->snapshot(), 'a previously good snapshot');

        [$code] = $this->generate('--die=error');

        self::assertSame(1, $code);
        self::assertSame('a previously good snapshot', file_get_contents($this->snapshot()));
    }

    /** @return array{0:int,1:string} exit code and stderr */
    private function generate(string $args = ''): array
    {
        $cmd = sprintf(
            'MFTF_UI_WORKSPACE=%s %s %s generate:tests %s 2>&1 >/dev/null',
            escapeshellarg($this->root),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/bin/mftf-fast'),
            $args
        );
        exec($cmd, $lines, $code);
        return [$code, implode("\n", $lines)];
    }

    private function snapshot(): string
    {
        return $this->root . '/var/mftf-cache/test.snapshot';
    }
}
