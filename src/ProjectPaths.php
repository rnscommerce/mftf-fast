<?php
declare(strict_types=1);

namespace MftfFast;

/**
 * The single place absolute paths are resolved.
 *
 * Everything else in the tool takes this object rather than building paths of
 * its own, which is what lets a future remote executor construct its own
 * instance without any other class knowing the difference.
 */
final class ProjectPaths
{
    public function __construct(private readonly string $root)
    {
    }

    /**
     * Locate the MFTF workspace this tool drives.
     *
     * A workspace is anywhere MFTF can run: a full Magento installation, or a
     * standalone workspace scaffolded by `init` that holds only MFTF and the
     * acceptance directory. Both are the same shape as far as this tool is
     * concerned, which is why the check is that shape and not bin/magento -
     * a trimmed workspace has no Magento binary and never will.
     *
     * MFTF_UI_WORKSPACE overrides; MFTF_UI_MAGENTO_ROOT is the older name for
     * the same thing and still works.
     *
     * @throws \RuntimeException when no workspace can be found
     */
    public static function discover(?string $start = null): self
    {
        foreach (['MFTF_UI_WORKSPACE', 'MFTF_UI_MAGENTO_ROOT'] as $var) {
            $override = getenv($var);
            if (!is_string($override) || $override === '') {
                continue;
            }
            if (!self::looksLikeWorkspace($override)) {
                throw new \RuntimeException("{$var} is not an MFTF workspace: {$override}");
            }
            return new self(rtrim($override, '/'));
        }

        // Working directory first, then the package's own location. A phar
        // lives wherever it was downloaded to, so where the user is standing
        // is a better clue than where the tool is installed; walking up from
        // __DIR__ alone only ever worked because the tool sat inside vendor.
        $starts = $start !== null ? [$start] : array_filter([getcwd() ?: null, __DIR__]);

        foreach ($starts as $from) {
            $dir = $from;
            while (true) {
                if (self::looksLikeWorkspace($dir)) {
                    return new self($dir);
                }
                $parent = dirname($dir);
                if ($parent === $dir) {
                    break;
                }
                $dir = $parent;
            }
        }

        throw new \RuntimeException(
            'No MFTF workspace found above ' . implode(' or ', $starts)
            . '. Set MFTF_UI_WORKSPACE, or run `mftf-ui init` to create one.'
        );
    }

    /**
     * The acceptance directory is the load-bearing marker: MFTF refuses to
     * bootstrap without it, so anything lacking it is not a workspace whatever
     * else it contains.
     */
    public static function looksLikeWorkspace(string $dir): bool
    {
        return is_dir($dir . '/dev/tests/acceptance')
            && is_dir($dir . '/vendor');
    }

    /** True for a real Magento installation rather than a trimmed workspace. */
    public function isMagentoInstall(): bool
    {
        return is_file($this->root . '/bin/magento') && is_dir($this->root . '/app/etc');
    }

    /**
     * Where the test corpus lives, which need not be the workspace.
     *
     * A standalone workspace points MAGENTO_BP at a corpus checked out
     * elsewhere; a Magento installation is its own corpus. Read from .env
     * because that is the value MFTF itself will use - disagreeing with it
     * would mean cataloguing tests MFTF cannot generate.
     */
    public function corpusRoot(): string
    {
        static $resolved = null;
        if ($resolved !== null) {
            return $resolved;
        }

        $env = is_file($this->envFile()) ? (new Settings\EnvFile($this->envFile()))->values() : [];
        $bp = trim((string) ($env['MAGENTO_BP'] ?? ''));

        return $resolved = ($bp !== '' && is_dir($bp)) ? rtrim($bp, '/') : $this->root;
    }

    /** Optional host-project settings from mftf-ui.config.php at the Magento root. */
    public function config(string $key, mixed $default = null): mixed
    {
        static $config = null;
        if ($config === null) {
            // Host-project config, optional; sensible defaults without it.
            $file = $this->root . '/mftf-ui.config.php';
            $config = is_file($file) ? (require $file) : [];
        }
        return $config[$key] ?? $default;
    }

    public function root(): string
    {
        return $this->root;
    }

    public function vendorBin(string $name): string
    {
        return $this->root . '/vendor/bin/' . $name;
    }

    /** The MAG-12197 fast binary; absent until that branch merges. */
    public function mftfFast(): string
    {
        return $this->vendorBin('mftf-fast');
    }

    public function mftf(): string
    {
        return $this->vendorBin('mftf');
    }

    public function acceptanceDir(): string
    {
        return $this->root . '/dev/tests/acceptance';
    }

    public function envFile(): string
    {
        return $this->acceptanceDir() . '/.env';
    }

    public function codeceptionYml(): string
    {
        return $this->acceptanceDir() . '/codeception.yml';
    }

    public function outputDir(): string
    {
        return $this->acceptanceDir() . '/tests/_output';
    }

    public function allureResultsDir(): string
    {
        return $this->outputDir() . '/allure-results';
    }

    public function failedFile(): string
    {
        return $this->outputDir() . '/failed';
    }

    public function generatedDir(): string
    {
        return $this->acceptanceDir() . '/tests/functional/Magento/_generated';
    }

    public function varDir(): string
    {
        return $this->root . '/var/mftf-ui';
    }

    public function catalogFile(): string
    {
        return $this->varDir() . '/catalog.json';
    }

    /**
     * Where the reference index lives.
     *
     * Separate from the catalogue because it is read only when something is
     * being displayed, and the catalogue is decoded on every request.
     */
    public function referenceFile(): string
    {
        return $this->varDir() . '/references.json';
    }

    public function generationLock(): string
    {
        return $this->varDir() . '/generation.lock';
    }

    public function historyFile(): string
    {
        return $this->varDir() . '/index.ndjson';
    }

    /** Journal of core-module renames, so an interrupted run can be reconciled. */
    public function coreGateJournal(): string
    {
        return $this->varDir() . '/core-gate.ndjson';
    }

    /**
     * Roots scanned for MFTF corpora, relative to the corpus root.
     *
     * app/code is included by default because that is where a skeleton
     * workspace's own modules live - a Composer install would put them in
     * vendor, but nobody composer-installs a module they are still writing.
     *
     * @return list<string>
     */
    public function sources(): array
    {
        return $this->config('sources', ['vendor', 'app/code']);
    }

    /** A chromedriver downloaded by this tool, kept beside the workspace state. */
    public function managedDriver(): string
    {
        return $this->varDir() . '/bin/chromedriver' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
    }

    /** Pid of a chromedriver this tool started, so it never stops someone else's. */
    public function chromedriverPid(): string
    {
        return $this->varDir() . '/chromedriver.pid';
    }

    public function chromedriverLog(): string
    {
        return $this->varDir() . '/chromedriver.log';
    }

    public function runsDir(): string
    {
        return $this->varDir() . '/runs';
    }

    public function runDir(string $runId): string
    {
        return $this->runsDir() . '/' . $runId;
    }

    /** Flags a paused run waits on; removing one lets it carry on. */
    public function pauseDir(string $runId): string
    {
        return $this->runDir($runId) . '/pause';
    }
}
