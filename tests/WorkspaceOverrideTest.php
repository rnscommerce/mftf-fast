<?php

declare(strict_types=1);

namespace MftfFast\Tests;

use MftfFast\ProjectPaths;
use PHPUnit\Framework\TestCase;

/**
 * MFTF_UI_WORKSPACE names the workspace outright, so a folder without the
 * acceptance directory is taken as it is.
 */
final class WorkspaceOverrideTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mftf-fast-test-' . bin2hex(random_bytes(6));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        putenv('MFTF_UI_WORKSPACE');
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testAFolderWithoutTheAcceptanceDirectoryIsTheWorkspace(): void
    {
        putenv('MFTF_UI_WORKSPACE=' . $this->root . '/');

        $this->assertSame($this->root, ProjectPaths::discover()->root());
    }

    public function testAMissingFolderIsRefused(): void
    {
        putenv('MFTF_UI_WORKSPACE=' . $this->root . '/missing');

        $this->expectException(\RuntimeException::class);
        ProjectPaths::discover();
    }
}
