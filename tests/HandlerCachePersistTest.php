<?php

declare(strict_types=1);

namespace MftfFast\Tests;

use Magento\FunctionalTestingFramework\Test\Handlers\ActionGroupObjectHandler;
use MftfFast\HandlerCache;
use PHPUnit\Framework\TestCase;

final class HandlerCachePersistTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mftf-fast-test-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/var/mftf-cache', 0775, true);
    }

    protected function tearDown(): void
    {
        ActionGroupObjectHandler::reset();
        $this->removeDir($this->root);
    }

    public function testFatalRunWritesNoSnapshot(): void
    {
        // A handler whose constructor threw mid-parse - e.g. the DOMDocument
        // class not found - leaves exactly this behind: a live singleton
        // holding an empty registry, not the finished one a snapshot should
        // capture.
        ActionGroupObjectHandler::getInstance();

        $cache = new HandlerCache($this->root);
        $saved = $cache->persist([], true);

        self::assertSame([], $saved);
        self::assertSame([], glob($this->root . '/var/mftf-cache/*.snapshot'));
    }

    public function testFatalRunReplacesNothingAlreadyOnDisk(): void
    {
        $existing = $this->root . '/var/mftf-cache/actiongroup.snapshot';
        file_put_contents($existing, 'a previously good snapshot');

        ActionGroupObjectHandler::getInstance();

        $cache = new HandlerCache($this->root);
        $cache->persist([], true);

        self::assertSame('a previously good snapshot', file_get_contents($existing));
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
