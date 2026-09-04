<?php
declare(strict_types=1);

namespace MftfFast;

/**
 * Which Magento modules the instance has switched on.
 *
 * MFTF asks the running instance, but the answer is written to
 * `app/etc/config.php` by `bin/magento module:enable` and `module:disable`, and
 * reading the file costs nothing.
 *
 * This matters more than it looks. A module that is off still has all its files
 * on disk, and MFTF merges none of them - so anything that walks the corpus
 * instead of asking will keep offering an action group fragment, a section or a
 * data entity that no test will ever see.
 */
final class ModuleState
{
    private ?array $modules = null;

    /** Module name per directory, so each package's module.xml is read once. */
    private array $names = [];

    public function __construct(private readonly string $root)
    {
    }

    /**
     * Whether the module rooted at this directory is on.
     *
     * A directory whose module name cannot be read counts as on: the callers
     * use this to decide what to leave out, and leaving out something real is
     * the worse way to be wrong.
     */
    public function directoryIsOn(string $dir): bool
    {
        $name = $this->nameOf($dir);

        return $name === null || ($this->all()[$name] ?? true);
    }

    /** A digest of what is on, for cache keys that must change when it does. */
    public function digest(): string
    {
        $state = $this->all();
        if ($state === []) {
            return 'unknown';
        }

        $on = array_keys(array_filter($state));
        sort($on);

        return md5(implode(',', $on));
    }

    /** @return array<string,bool> */
    public function all(): array
    {
        if ($this->modules !== null) {
            return $this->modules;
        }

        $file = $this->root . '/app/etc/config.php';
        $config = is_file($file) ? @include $file : null;
        $modules = is_array($config) ? ($config['modules'] ?? null) : null;

        return $this->modules = is_array($modules)
            ? array_map(static fn($v): bool => (bool) $v, $modules)
            : [];
    }

    /** The module a directory declares itself to be, from its etc/module.xml. */
    private function nameOf(string $dir): ?string
    {
        if (array_key_exists($dir, $this->names)) {
            return $this->names[$dir];
        }

        $xml = $dir . '/etc/module.xml';
        $name = is_file($xml)
            && preg_match('/<module\s[^>]*name="([^"]+)"/', (string) file_get_contents($xml), $m)
            ? $m[1]
            : null;

        return $this->names[$dir] = $name;
    }
}
