<?php
declare(strict_types=1);

namespace MftfFast;

use MftfFast\ModuleState;
use ReflectionClass;
use ReflectionProperty;
use Throwable;

/**
 * Caches MFTF's parsed object handlers so generation does not re-read the whole XML corpus.
 *
 * MFTF resolves references by flat global name, so every handler eagerly parses every module's
 * XML before it can generate anything. That costs ~18.5s. The parsed graphs serialise cleanly,
 * so we snapshot them and reflect them back into the singletons on the next run.
 *
 * Restore needs no MftfApplicationConfig, so it is safe to run before the console command
 * creates its own - important, because MftfApplicationConfig::create() is first-write-wins and
 * pre-empting it would silently drop the command's --filter/--debug options.
 */
final class HandlerCache
{
    /** Bump when the snapshot layout changes, to invalidate every existing blob. */
    private const FORMAT = 3;

    /**
     * Handlers worth caching, mapped to the Test/Mftf subdirectory that feeds them.
     *
     * Test and Suite are here despite being what a developer edits, because
     * leaving them out was costing more than it saved: building them is 20 of
     * the 22 seconds a single-test generation takes, and it was being paid on
     * every run rather than only after an edit. An edit still invalidates the
     * whole test snapshot - names are global, so one file can change what
     * another resolves to - but that is one slow run, not all of them.
     *
     * Metadata is not here. OperationDefinitionObjectHandler is read when a
     * test persists an entity, inside the Codeception process, which this
     * binary never wraps; generation never touches it. So a metadata
     * snapshot was only ever written by cache:warm and read back by nothing.
     */
    private const TYPES = [
        'actiongroup' => [
            'class' => \Magento\FunctionalTestingFramework\Test\Handlers\ActionGroupObjectHandler::class,
            'dir' => 'ActionGroup',
            'declares' => 'actionGroup',
        ],
        'section' => [
            'class' => \Magento\FunctionalTestingFramework\Page\Handlers\SectionObjectHandler::class,
            'dir' => 'Section',
            'declares' => 'section',
        ],
        'page' => [
            'class' => \Magento\FunctionalTestingFramework\Page\Handlers\PageObjectHandler::class,
            'dir' => 'Page',
            'declares' => 'page',
        ],
        'data' => [
            'class' => \Magento\FunctionalTestingFramework\DataGenerator\Handlers\DataObjectHandler::class,
            'dir' => 'Data',
            'declares' => 'entity',
        ],
        'test' => [
            'class' => \Magento\FunctionalTestingFramework\Test\Handlers\TestObjectHandler::class,
            'dir' => 'Test',
            'declares' => 'test',
        ],
        'suite' => [
            'class' => \Magento\FunctionalTestingFramework\Suite\Handlers\SuiteObjectHandler::class,
            'dir' => 'Suite',
            'declares' => 'suite',
        ],
    ];

    private string $root;

    /** Module enablement, read once from the instance's config. */
    private ?ModuleState $modules = null;
    private string $dir;
    private ?array $signatures = null;
    /** Types refused at save because the load they came from was partial. */
    private array $incomplete = [];
    /**
     * Whether this generation was asked to ignore which modules are enabled.
     *
     * `--force` is not a detail of how the run is reported; it changes what
     * MFTF merges. ModuleResolver::getModulesPath() returns every module path
     * it found when forceGenerateEnabled() is true, and filters to the
     * instance's enabled modules when it is false - so a disabled module's
     * Sections, Pages and Data are in the merge under force and absent
     * without it. Two different corpora, from the same files on disk.
     */
    private bool $force;

    public function __construct(string $projectRoot, bool $force = false)
    {
        $this->root = rtrim($projectRoot, '/');
        $this->dir = $this->root . '/var/mftf-cache';
        $this->force = $force;
    }

    /**
     * Whether an invocation asked MFTF to ignore module state.
     *
     * Read from argv because restore() runs before the command does, so
     * MftfApplicationConfig has not been told anything yet. Symfony accepts
     * the short flag bundled with others, so -fv and -vf both count.
     *
     * @param list<string> $argv
     */
    public static function forceInArgv(array $argv): bool
    {
        foreach ($argv as $arg) {
            if ($arg === '--force') {
                return true;
            }
            if ($arg === '--') {
                return false;
            }
            // A bundled short group: -f, -fv, -rf. Never a long option or a value.
            if (strlen($arg) > 1 && $arg[0] === '-' && $arg[1] !== '-' && strpos($arg, 'f') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Restore every handler whose snapshot is still valid. Returns the types restored.
     */
    public function restore(): array
    {
        $restored = [];
        foreach (self::TYPES as $type => $spec) {
            $file = $this->file($type);
            // No framework to restore into says nothing about the snapshot: leave it be.
            if (!is_file($file) || !class_exists($spec['class'])) {
                continue;
            }
            try {
                $blob = self::decode((string) file_get_contents($file));
                if (!is_array($blob) || ($blob['sig'] ?? null) !== $this->signature($type)) {
                    continue;
                }
                $this->inject($spec['class'], $blob['state']);
                $restored[] = $type;
            } catch (Throwable $e) {
                // A stale or shape-changed blob must never be trusted - drop it and rebuild.
                @unlink($file);
            }
        }
        return $restored;
    }

    /**
     * Snapshot any handler that got built this run and is not already cached.
     * Uninitialised handlers are skipped.
     */
    public function persist(array $alreadyRestored = []): array
    {
        $saved = [];
        foreach (self::TYPES as $type => $spec) {
            if (in_array($type, $alreadyRestored, true)) {
                continue;
            }
            try {
                $obj = $this->liveInstance($spec['class']);
                if ($obj === null) {
                    continue;
                }
                $state = $this->capture($spec['class'], $obj);

                // The signature describes the files on disk; the snapshot holds
                // whatever MFTF actually loaded. Those differ whenever module
                // filtering does - a handler built without --force omits every
                // module the instance reports as disabled - and nothing about
                // the files would ever say so. Caching that view once poisons
                // every later run, so a partial load is simply not cached.
                $missing = $this->missingFrom($type, $spec, $state);
                if ($missing !== []) {
                    $this->incomplete[$type] = $missing;
                    continue;
                }

                $blob = ['sig' => $this->signature($type), 'state' => $state];
                if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
                    continue;
                }
                $tmp = $this->file($type) . '.' . getmypid();
                if (@file_put_contents($tmp, self::encode($blob)) !== false) {
                    @rename($tmp, $this->file($type));
                    $saved[] = $type;
                }
            } catch (Throwable $e) {
                // Caching is an optimisation; never let it break a generation run.
            }
        }
        return $saved;
    }

    /**
     * The object graphs are large and read on every run, so how they are
     * encoded is not a detail: igbinary stores the test registry in 12MB
     * against serialize's 152MB, and reads it back in a third of the time.
     * It is an optional extension, so the built-in stays as the fallback.
     */
    private static function encode(array $blob): string
    {
        return function_exists('igbinary_serialize')
            ? "\x00ig" . igbinary_serialize($blob)
            : serialize($blob);
    }

    /** @return mixed the decoded blob, whichever encoder wrote it */
    private static function decode(string $raw)
    {
        if (str_starts_with($raw, "\x00ig")) {
            return function_exists('igbinary_unserialize')
                ? igbinary_unserialize(substr($raw, 3))
                : null;
        }

        return unserialize($raw, ['allowed_classes' => true]);
    }

    /**
     * The types a snapshot is kept for, as cache:warm takes them.
     *
     * @return string[]
     */
    public static function types(): array
    {
        return array_keys(self::TYPES);
    }

    /**
     * Build the named handlers - every cacheable one when none is named - in the mode this
     * cache was opened in, so what is built is what its snapshots are filed under. A type in
     * $skip is left alone: its snapshot was just restored and is still good. Only safe as a
     * standalone action - it creates MftfApplicationConfig, which is first-write-wins, so no
     * MFTF command may follow.
     *
     * @param string[] $types
     * @param string[] $skip
     */
    public function warm(array $types = [], array $skip = []): array
    {
        $cfg = \Magento\FunctionalTestingFramework\Config\MftfApplicationConfig::class;
        $cfg::create($this->force, $cfg::GENERATION_PHASE, false, $cfg::LEVEL_DEFAULT, true);

        $times = [];
        foreach (self::TYPES as $type => $spec) {
            if (($types !== [] && !in_array($type, $types, true)) || in_array($type, $skip, true)) {
                continue;
            }
            $t = microtime(true);
            $spec['class']::getInstance();
            $times[$type] = microtime(true) - $t;
        }
        return $times;
    }

    public function clear(): int
    {
        $n = 0;
        foreach ($this->allFiles() as $file) {
            if (@unlink($file)) {
                $n++;
            }
        }
        return $n;
    }

    public function status(): array
    {
        $out = [];
        foreach (self::TYPES as $type => $spec) {
            $file = $this->file($type);
            $valid = false;
            if (is_file($file)) {
                try {
                    $blob = self::decode((string)file_get_contents($file));
                } catch (Throwable $e) {
                    $blob = null;
                }
                $valid = is_array($blob) && ($blob['sig'] ?? null) === $this->signature($type);
            }
            $out[$type] = [
                'exists' => is_file($file),
                'valid' => $valid,
                'bytes' => is_file($file) ? (int)filesize($file) : 0,
            ];
        }
        return $out;
    }

    /** @return array<string,list<string>> types refused at save, and what they lacked */
    public function incomplete(): array
    {
        return $this->incomplete;
    }

    /**
     * Names the corpus declares that this handler never loaded.
     *
     * Read straight out of the XML rather than trusted from the parse, so it
     * catches exactly the case the file signature cannot see. Only ever run on
     * the slow path, where the corpus has just been parsed anyway.
     *
     * @return list<string>
     */
    private function missingFrom(string $type, array $spec, array $state): array
    {
        $declared = $this->declaredNames($spec);
        if ($declared === []) {
            return [];
        }

        $loaded = [];
        foreach ($state as $value) {
            if (is_array($value)) {
                $loaded += array_flip(array_keys($value));
            }
        }

        $missing = [];
        foreach ($declared as $name) {
            if (!isset($loaded[$name])) {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    /**
     * Every name the corpus declares for this type that MFTF will actually load.
     *
     * A switched-off module's files sit in vendor exactly like everyone
     * else's, but MFTF never reads them - so counting what they declare would
     * make a complete snapshot look like it had lost something, and nothing
     * would ever cache again. Under --force it does read them, and then not
     * counting them is the hole: a snapshot short by exactly those names
     * passes the gate and is served to a run that needs them.
     *
     * @return list<string>
     */
    private function declaredNames(array $spec): array
    {
        $roots = array_filter([$this->root . '/vendor', $this->root . '/app/code'], 'is_dir');
        if ($roots === []) {
            return [];
        }

        $cmd = 'find ' . implode(' ', array_map('escapeshellarg', $roots))
            . ' -path ' . escapeshellarg('*/Test/Mftf/' . $spec['dir'] . '/*')
            . " -name '*.xml' -exec grep -Hho " . escapeshellarg('<' . $spec['declares'] . ' name="[^"]*"')
            . ' {} + 2>/dev/null';

        $names = [];
        foreach (explode("\n", (string) shell_exec($cmd)) as $line) {
            $cut = strpos($line, '/Test/Mftf/');
            if ($cut === false || !preg_match('/name="([^"]*)"/', $line, $m)) {
                continue;
            }
            // Under --force MFTF loads a disabled module's files too, so its
            // declarations are exactly what a complete snapshot must contain.
            if ($this->force || $this->moduleState()->directoryIsOn(substr($line, 0, $cut))) {
                $names[$m[1]] = true;
            }
        }

        return array_keys($names);
    }

    private function moduleState(): ModuleState
    {
        return $this->modules ??= new ModuleState($this->root);
    }

    /**
     * Where a type's snapshot lives, one file per mode.
     *
     * Force and non-force merge different corpora, so they cannot share a
     * file: keyed only by the stamp they would each invalidate the other on
     * every alternation and re-parse the whole corpus, which is the cost this
     * class exists to avoid. Side by side, both stay warm.
     */
    private function file(string $type): string
    {
        return $this->dir . '/' . $type . ($this->force ? '.force' : '') . '.snapshot';
    }

    /** Every snapshot on disk, both modes - for clear(), which means all of it. */
    private function allFiles(): array
    {
        return glob($this->dir . '/*.snapshot') ?: [];
    }

    /**
     * Per-type content signature, so editing a test does not invalidate the action groups.
     * Covers path, mtime and size - mtime alone misses same-second edits.
     */
    private function signature(string $type): string
    {
        if ($this->signatures === null) {
            $this->signatures = $this->scan();
        }
        return $this->signatures[$type] ?? 'empty';
    }

    private function scan(): array
    {
        $roots = array_filter([$this->root . '/vendor', $this->root . '/app/code'], 'is_dir');
        $buckets = array_fill_keys(array_keys(self::TYPES), []);
        if ($roots === []) {
            return array_map(static fn($b) => 'empty', $buckets);
        }

        $cmd = 'find ' . implode(' ', array_map('escapeshellarg', $roots))
            . " -path '*/Test/Mftf/*' -name '*.xml' -printf '%p\\t%T@\\t%s\\n' 2>/dev/null";
        $lines = explode("\n", (string)shell_exec($cmd));

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $path = strstr($line, "\t", true);
            foreach (self::TYPES as $type => $spec) {
                if (strpos((string)$path, '/Test/Mftf/' . $spec['dir'] . '/') !== false) {
                    $buckets[$type][] = $line;
                    break;
                }
            }
        }

        foreach ($this->testOnlyModuleRoots() as [$dir, $depth]) {
            $cmd = 'find ' . escapeshellarg($dir)
                . " -name '*.xml' -not -path '*/.*' -not -path '*/_generated/*' -printf '%p\\t%T@\\t%s\\n' 2>/dev/null";
            foreach (explode("\n", (string)shell_exec($cmd)) as $line) {
                $path = (string)strstr($line, "\t", true);
                $folder = explode('/', substr($path, strlen($dir) + 1))[$depth] ?? null;
                foreach (self::TYPES as $type => $spec) {
                    if ($folder === $spec['dir']) {
                        $buckets[$type][] = $line;
                        break;
                    }
                }
            }
        }

        // MAGENTO_BP changes which corpus is discovered at all, so a snapshot
        // taken under one is not valid under another. Neither is one taken
        // under a different set of enabled modules: MFTF merges a module's
        // action groups only while it is on, and none of its files change when
        // it is switched off - so without this a snapshot keeps merging a
        // module that is no longer installed, and the test fails somewhere
        // that has nothing to do with what it tests.
        //
        // --force is in the key for the same reason from the other side: it
        // makes MFTF ignore module state and merge everything, so a snapshot
        // taken without it is missing every disabled module's sections. Serve
        // that to a --force run and a reference the XML plainly declares
        // cannot be resolved.
        $stamp = self::FORMAT . '|' . PHP_VERSION . '|' . $this->frameworkVersion()
            . '|bp=' . (string) getenv('MAGENTO_BP')
            . '|mods=' . $this->enabledModules()
            . '|force=' . ($this->force ? '1' : '0');
        $out = [];
        foreach ($buckets as $type => $lines) {
            sort($lines);
            $out[$type] = md5($stamp . '|' . implode("\n", $lines));
        }
        return $out;
    }

    /**
     * Where test-only modules sit, whose XML is straight under the module
     * rather than under Test/Mftf: beside the acceptance tests as
     * Vendor/Module, and each CUSTOM_MODULE_PATHS entry, a module itself.
     *
     * @return list<array{0:string,1:int}> each directory, and how many
     *         folders below it the Test, Data or Section folder sits
     */
    private function testOnlyModuleRoots(): array
    {
        $roots = [[$this->root . '/dev/tests/acceptance/tests/functional', 2]];
        foreach (explode(',', (string) getenv('CUSTOM_MODULE_PATHS')) as $path) {
            $path = rtrim(trim($path), '/');
            if ($path !== '') {
                $roots[] = [str_starts_with($path, '/') ? $path : $this->root . '/' . $path, 0];
            }
        }
        return array_values(array_filter($roots, static fn(array $root): bool => is_dir($root[0])));
    }

    /**
     * A digest of which modules are switched on, from the file `bin/magento
     * module:enable` and `module:disable` write.
     *
     * MFTF asks the running instance rather than reading this, but the two
     * agree and reading the file costs nothing.
     */
    private function enabledModules(): string
    {
        return $this->moduleState()->digest();
    }

    /**
     * Serialised graphs are coupled to the framework's class shapes, so the version is part of
     * the key. Without this an MFTF upgrade yields subtly wrong objects rather than a clean miss.
     *
     * The framework that is loaded, not the one in vendor: FW_BP is where
     * MFTF's bootstrap says it is, and a program carrying its own MFTF
     * bootstraps one that sits outside the project altogether.
     * Keyed on vendor's copy, its snapshots would be served to the carried one.
     */
    private function frameworkVersion(): string
    {
        $framework = defined('FW_BP') ? FW_BP : $this->root . '/vendor/magento/magento2-functional-testing-framework';
        $json = $framework . '/composer.json';
        if (!is_file($json)) {
            return 'unknown';
        }
        $data = json_decode((string)file_get_contents($json), true);
        return (string)($data['version'] ?? 'unknown');
    }

    private function capture(string $class, object $obj): array
    {
        $ref = new ReflectionClass($class);
        $state = [];
        foreach ($ref->getProperties() as $prop) {
            if ($prop->isStatic()) {
                continue;
            }
            $prop->setAccessible(true);
            $state[$prop->getName()] = $prop->getValue($obj);
        }
        return $state;
    }

    private function inject(string $class, array $state): void
    {
        $ref = new ReflectionClass($class);
        $obj = $ref->newInstanceWithoutConstructor();
        foreach ($state as $name => $value) {
            if (!$ref->hasProperty($name)) {
                throw new \RuntimeException("$class no longer has property $name");
            }
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($obj, $value);
        }
        $this->singletonProperty($ref)->setValue(null, $obj);
    }

    private function liveInstance(string $class): ?object
    {
        $ref = new ReflectionClass($class);
        $value = $this->singletonProperty($ref)->getValue();
        return is_object($value) ? $value : null;
    }

    /**
     * Handlers disagree on the name - $instance, $INSTANCE, $testObjectHandler - but each
     * declares exactly one static, so detect it rather than hardcoding.
     */
    private function singletonProperty(ReflectionClass $ref): ReflectionProperty
    {
        $statics = array_filter(
            $ref->getProperties(ReflectionProperty::IS_STATIC),
            static fn(ReflectionProperty $p) => $p->getDeclaringClass()->getName() === $ref->getName()
        );
        if (count($statics) !== 1) {
            throw new \RuntimeException($ref->getName() . ' has no single static singleton holder');
        }
        $prop = array_values($statics)[0];
        $prop->setAccessible(true);
        return $prop;
    }
}
