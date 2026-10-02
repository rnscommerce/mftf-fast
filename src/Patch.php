<?php
declare(strict_types=1);

namespace MftfFast;

use Closure;
use Magento\FunctionalTestingFramework\ObjectManagerFactory;
use Magento\FunctionalTestingFramework\Util\Validation\NameValidationUtil;
use ReflectionClass;
use Throwable;

/**
 * Brings a restored but stale snapshot of one handler up to date by reading
 * again only what the changed files declare, merged from every file that
 * declares it, in MFTF's order, as MFTF's own read would.
 *
 * The index it keeps beside the snapshot says which file declares which name
 * and where each file sits in that order.
 */
final class Patch
{
    private const NS = 'Magento\\FunctionalTestingFramework\\';

    private const KINDS = [
        'test' => [
            'reader' => self::NS . 'Config\\Reader\\TestData',
            'root' => 'tests',
            'tag' => 'test',
            'property' => 'tests',
            'nameKind' => 'test name',
        ],
        'actiongroup' => [
            'reader' => self::NS . 'Config\\Reader\\ActionGroupData',
            'root' => 'actionGroups',
            'tag' => 'actionGroup',
            'property' => 'actionGroups',
            'nameKind' => 'action group name',
        ],
    ];

    /** @var list<callable> schema checks put off until after the answer is out */
    private array $unvalidated = [];
    /** A name sits where its first file does, so a file joining or leaving it can move it. */
    private bool $reorder = false;
    private bool $newFiles = false;

    /**
     * @param Closure(): array<string, string> $files every file of the type now: path => "mtime\tsize"
     */
    private function __construct(
        private string $type,
        private string $handlerClass,
        private string $stamp,
        private Closure $files,
        private ?array $index,
        private ?array $pending,
    ) {
    }

    public static function patchable(string $type): bool
    {
        return isset(self::KINDS[$type]);
    }

    public static function stale(string $type, string $handlerClass, string $stamp, Closure $files, array $index): self
    {
        return new self($type, $handlerClass, $stamp, $files, null, $index);
    }

    public static function fresh(string $type, string $handlerClass, string $stamp, Closure $files): self
    {
        return new self($type, $handlerClass, $stamp, $files, null, null);
    }

    public function index(): array
    {
        return $this->index ??= $this->buildIndex();
    }

    /**
     * Reads again what changed and puts it into the handler. Returns each
     * name read and whether it is still declared, or null when the handler
     * was left for MFTF to build because the patch could not be made.
     *
     * @return array{changed:int, names:array<string, bool>}|null
     */
    public function apply(): ?array
    {
        $index = $this->pending;
        $this->pending = null;
        if ($index === null) {
            return ['changed' => 0, 'names' => []];
        }
        $now = ($this->files)();
        $changed = [];
        foreach ($now as $path => $stat) {
            if (($index['scan'][$path] ?? null) !== $stat) {
                $changed[] = $path;
            }
        }
        $removed = array_values(array_diff(array_keys($index['scan']), array_keys($now)));

        $affected = [];
        foreach ($index['names'] as $name => $paths) {
            if (array_intersect($paths, $changed) !== [] || array_intersect($paths, $removed) !== []) {
                $affected[$name] = true;
            }
        }
        $contents = [];
        $parents = $index['parents'];
        foreach ($changed as $path) {
            $contents[$path] = (string) file_get_contents($path);
            foreach ($this->declared($contents[$path]) as $name => $parent) {
                $affected[$name] = true;
                $parents[$name] = $parent ?? ($parents[$name] ?? null);
            }
        }
        // An action group is kept extended once anything has asked for all of
        // them, so whatever extends a changed one is read again too.
        if ($this->type === 'actiongroup') {
            $affected += $this->descendants($parents, $affected);
        }

        // Who declares each affected name now, in MFTF's order. A file new to a
        // name that others declare too takes its place from MFTF's own file list.
        $names = $index['names'];
        foreach (array_keys($affected) as $name) {
            $names[$name] = array_values(array_filter(
                $names[$name] ?? [],
                static fn ($p) => !in_array($p, $removed, true) && !in_array($p, $changed, true)
            ));
        }
        $position = $index['position'];
        foreach ($changed as $path) {
            foreach (array_keys($this->declared($contents[$path])) as $name) {
                if (!isset($position[$path]) && $names[$name] !== []) {
                    $position = array_flip(array_column($this->filesInOrder(), 0));
                    break 2;
                }
            }
        }
        foreach ($changed as $path) {
            foreach (array_keys($this->declared($contents[$path])) as $name) {
                $names[$name][] = $path;
                usort($names[$name], static fn ($a, $b) => ($position[$a] ?? PHP_INT_MAX) <=> ($position[$b] ?? PHP_INT_MAX));
            }
            $this->newFiles = $this->newFiles || !isset($position[$path]);
            $position[$path] ??= count($position);
        }
        foreach ($removed as $path) {
            unset($position[$path]);
        }

        try {
            $read = $this->read($index, $affected, $names, $contents, $changed);
        } catch (Throwable $e) {
            // MFTF's own read says what is wrong, as it would have without a snapshot.
            return null;
        }
        foreach (array_keys($affected) as $name) {
            $this->reorder = $this->reorder || $names[$name] !== ($index['names'][$name] ?? []);
            unset($parents[$name]);
            foreach ($names[$name] as $path) {
                $parents[$name] = $this->declared($contents[$path] ??= (string) file_get_contents($path))[$name] ?? ($parents[$name] ?? null);
            }
            if ($names[$name] === []) {
                unset($names[$name]);
            }
        }
        $this->index = ['stamp' => $this->stamp, 'scan' => $now, 'position' => $position, 'names' => $names,
            'parents' => $parents, 'modules' => $read['modules']];
        return ['changed' => count($changed) + count($removed), 'names' => $read['names']];
    }

    /** Runs the schema checks the patch put off; false when one fails, and the patch must not be kept. */
    public function validate(): bool
    {
        try {
            foreach ($this->unvalidated as $validate) {
                $validate();
            }
        } catch (Throwable $e) {
            return false;
        } finally {
            $this->unvalidated = [];
        }
        return true;
    }

    /**
     * Each name's place in MFTF's order, once a patch may have moved one; a
     * new file's place only a full file list knows.
     *
     * @return array<string, int>|null
     */
    public function order(): ?array
    {
        if (!$this->reorder) {
            return null;
        }
        if ($this->newFiles) {
            $this->index = $this->buildIndex($this->index['modules'] ?? null);
        }
        $rank = [];
        foreach ($this->index['names'] as $name => $paths) {
            $rank[$name] = min(array_map(fn ($path) => $this->index['position'][$path], $paths));
        }
        return $rank;
    }

    /**
     * @param array<string, true> $affected
     * @return array{names: array<string, bool>, modules: array<string, string>|null}
     */
    private function read(array $index, array $affected, array $names, array $contents, array $changed): array
    {
        $kind = self::KINDS[$this->type];
        $reader = ObjectManagerFactory::getObjectManager()->create($kind['reader']);
        $root = $kind['root'];
        $merge = Closure::bind(function (array $files) use ($root) {
            $collector = new \Magento\FunctionalTestingFramework\Exceptions\Collector\ExceptionCollector();
            $merger = null;
            foreach ($files as $path => $content) {
                if (!$this->verifyFileEmpty($content, $path)) {
                    continue;
                }
                if ($merger === null) {
                    $merger = $this->createConfigMerger($this->domDocumentClass, $content, $path, $collector);
                } else {
                    $merger->merge($content, $path, $collector);
                }
            }
            $collector->throwException();
            if ($merger === null) {
                return [[], null];
            }
            return [$this->converter->convert($merger->getDom())[$root] ?? [], fn () => $this->validateSchema($merger)];
        }, $reader, get_class($reader));

        [$extract, $modules] = $this->extractor($index['modules'] ?? null, $changed);
        $nameCheck = new NameValidationUtil();
        $parsed = [];
        foreach (array_keys($affected) as $name) {
            $files = [];
            foreach ($names[$name] as $path) {
                $files[$path] = $contents[$path] ??= (string) file_get_contents($path);
            }
            [$data, $validate] = $files === [] ? [[], null] : $merge($files);
            if ($validate !== null) {
                $this->unvalidated[] = $validate;
            }
            $parsed[$name] = $data[$name] ?? null;
        }

        $property = $kind['property'];
        $nameKind = $kind['nameKind'];
        Closure::bind(function () use ($parsed, $extract, $nameCheck, $property, $nameKind) {
            foreach ($parsed as $name => $data) {
                if (!is_array($data)) {
                    unset($this->$property[$name]);
                    continue;
                }
                $nameCheck->validatePascalCase($name, $nameKind, $data['filename'] ?? '');
                $this->$property[$name] = $extract($data);
            }
        }, $this->handlerClass::getInstance(), $this->handlerClass)();

        return ['names' => array_map('is_array', $parsed), 'modules' => $modules];
    }

    /** @return array{0: callable(array): object, 1: array<string, string>|null} */
    private function extractor(?array $modules, array $changed): array
    {
        if ($this->type === 'actiongroup') {
            $extractor = new \Magento\FunctionalTestingFramework\Test\Util\ActionGroupObjectExtractor();
            return [static fn (array $data) => $extractor->extractActionGroup($data), null];
        }
        // Building a TestObjectExtractor asks MFTF for every module's path, which
        // is most of the cost of a patch; the index keeps them.
        $known = static fn (string $path) => array_filter($modules ?? [], static fn ($root) => str_starts_with($path, $root)) !== [];
        if ($modules === null || array_filter($changed, static fn ($path) => !$known($path)) !== []) {
            $extractor = new \Magento\FunctionalTestingFramework\Test\Util\TestObjectExtractor();
            $modules = self::modulePathsOf(Closure::bind(fn () => $this->modulePathExtractor, $extractor, get_class($extractor))());
        } else {
            $extractor = self::testExtractorWith($modules);
        }
        return [static fn (array $data) => $extractor->extractTestData($data, true), $modules];
    }

    /**
     * @param array<string, string|null> $parents
     * @param array<string, true> $names
     * @return array<string, true> every name that extends one of these, however far down
     */
    private function descendants(array $parents, array $names): array
    {
        $found = [];
        do {
            $more = false;
            foreach ($parents as $child => $parent) {
                if ($parent !== null && !isset($names[$child]) && !isset($found[$child]) && (isset($names[$parent]) || isset($found[$parent]))) {
                    $found[$child] = true;
                    $more = true;
                }
            }
        } while ($more);
        return $found;
    }

    private function buildIndex(?array $modules = null): array
    {
        $names = [];
        $parents = [];
        $position = [];
        foreach ($this->filesInOrder() as $i => [$path, $content]) {
            $position[$path] = $i;
            foreach ($this->declared($content) as $name => $parent) {
                $names[$name][] = $path;
                $parents[$name] = $parent ?? ($parents[$name] ?? null);
            }
        }
        if ($this->type === 'test') {
            $modules ??= self::modulePathsOf(new \Magento\FunctionalTestingFramework\Util\ModulePathExtractor());
        }
        return ['stamp' => $this->stamp, 'scan' => ($this->files)(), 'position' => $position, 'names' => $names,
            'parents' => $parents, 'modules' => $modules];
    }

    /** @return list<array{0:string,1:string}> every file of the type and its content, in the order MFTF reads them */
    private function filesInOrder(): array
    {
        $reader = ObjectManagerFactory::getObjectManager()->create(self::KINDS[$this->type]['reader']);
        $order = [];
        Closure::bind(function () use (&$order) {
            $list = $this->fileResolver->get($this->fileName, $this->defaultScope);
            foreach ($list as $content) {
                $order[] = [$list->getFilename(), $content];
            }
        }, $reader, get_class($reader))();
        return $order;
    }

    /** @return array<string, string|null> each name a file declares, comments aside, and what it extends */
    private function declared(string $content): array
    {
        $tag = self::KINDS[$this->type]['tag'];
        preg_match_all('/<' . $tag . '\s[^>]*>/', preg_replace('/<!--.*?-->/s', '', $content), $m);
        $out = [];
        foreach ($m[0] as $open) {
            if (preg_match('/\bname\s*=\s*"([^"]*)"/', $open, $name)) {
                $out[$name[1]] = preg_match('/\bextends\s*=\s*"([^"]*)"/', $open, $parent) ? $parent[1] : null;
            }
        }
        return $out;
    }

    /** @return array<string, string> what MFTF knows each module's test path by */
    private static function modulePathsOf(object $extractor): array
    {
        return Closure::bind(fn () => $this->testModulePaths, $extractor, get_class($extractor))();
    }

    /** A TestObjectExtractor as its constructor builds one, with the module paths already known. */
    private static function testExtractorWith(array $modules): object
    {
        $paths = (new ReflectionClass(self::NS . 'Util\\ModulePathExtractor'))->newInstanceWithoutConstructor();
        Closure::bind(function () use ($modules) {
            $this->testModulePaths = $modules;
        }, $paths, get_class($paths))();
        $extractor = (new ReflectionClass(self::NS . 'Test\\Util\\TestObjectExtractor'))->newInstanceWithoutConstructor();
        Closure::bind(function () use ($paths) {
            $this->actionObjectExtractor = new \Magento\FunctionalTestingFramework\Test\Util\ActionObjectExtractor();
            $this->annotationExtractor = new \Magento\FunctionalTestingFramework\Test\Util\AnnotationExtractor();
            $this->testHookObjectExtractor = new \Magento\FunctionalTestingFramework\Test\Util\TestHookObjectExtractor();
            $this->modulePathExtractor = $paths;
        }, $extractor, get_class($extractor))();
        return $extractor;
    }
}
