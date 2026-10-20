<?php
declare(strict_types=1);

namespace MftfFast;

use Closure;
use Magento\FunctionalTestingFramework\ObjectManagerFactory;
use RuntimeException;

/**
 * A test that exists for one run: its files are read into the handlers in
 * memory, never into the project, and what is generated from them is removed
 * once Codeception has run it.
 */
final class Scratch
{
    private const NS = 'Magento\\FunctionalTestingFramework\\';

    /** root element => [reader, what the converter keys its output by] */
    private const ROOTS = [
        'tests' => [self::NS . 'Config\\Reader\\TestData', 'tests'],
        'entities' => [self::NS . 'Config\\Reader\\DataProfile', 'entity'],
    ];

    /**
     * Reads each file into the handler its root element belongs to, over
     * anything the project declares by the same name.
     *
     * @param list<string> $files
     * @return list<string> the tests the files declare
     */
    public static function load(array $files): array
    {
        $read = [];
        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if ($content === false) {
                throw new RuntimeException("Cannot read {$file}");
            }
            if (!preg_match('/<(tests|entities)[\s>]/', (string) preg_replace('/<!--.*?-->/s', '', $content), $root)) {
                throw new RuntimeException("{$file} holds no <tests> or <entities>");
            }
            $read[$root[1]][(string) realpath($file)] = $content;
        }
        $tests = [];
        // Data first, so a test can name it.
        foreach (['entities', 'tests'] as $root) {
            foreach ($read[$root] ?? [] as $path => $content) {
                $tests = [...$tests, ...self::put($root, self::parse($root, $path, $content))];
            }
        }
        return $tests;
    }

    /**
     * Generates the named tests into a directory of their own under _generated.
     *
     * @param list<string> $names
     * @return string the directory, which the caller removes
     */
    public static function generate(array $names): string
    {
        $handler = \Magento\FunctionalTestingFramework\Test\Handlers\TestObjectHandler::getInstance();
        $tests = [];
        foreach ($names as $name) {
            $tests[$name] = $handler->getObject($name);
        }
        $generator = \Magento\FunctionalTestingFramework\Util\TestGenerator::getInstance(
            'scratch_' . bin2hex(random_bytes(4)),
            $tests
        );
        $generator->createAllTestFiles(null, []);
        return $generator->getExportDir();
    }

    /**
     * Runs Codeception on the generated tests as MFTF's run:test does, with the
     * caller's options after its own, and answers Codeception's exit code.
     *
     * @param list<string> $names
     * @param list<string> $options
     */
    public static function run(string $dir, array $names, array $options): int
    {
        $command = [PHP_BINARY, PROJECT_ROOT . '/vendor/bin/codecept', 'run', 'functional'];
        // Codeception finds a test by its path from the suite, whichever config is given.
        $fromSuite = basename(TESTS_MODULE_PATH) . substr($dir, strlen(rtrim(TESTS_MODULE_PATH, DIRECTORY_SEPARATOR)));
        $code = 0;
        $cwd = self::configDir($options) ?? TESTS_BP;
        foreach ($names as $name) {
            $process = proc_open(
                [...$command, $fromSuite . DIRECTORY_SEPARATOR . $name . 'Cest.php', '--verbose', '--steps', ...$options],
                [STDIN, STDOUT, STDERR],
                $pipes,
                $cwd
            );
            if (!is_resource($process)) {
                throw new RuntimeException('Could not start Codeception');
            }
            $code = max($code, proc_close($process));
        }
        return $code;
    }

    /**
     * MFTF's Console subscriber reads a Cest back by a path relative to the
     * working directory, which a config's relative paths only hold from its own.
     *
     * @param list<string> $options
     */
    private static function configDir(array $options): ?string
    {
        foreach ($options as $i => $option) {
            $config = match (true) {
                $option === '-c', $option === '--config' => $options[$i + 1] ?? null,
                str_starts_with($option, '--config=') => substr($option, 9),
                default => null,
            };
            if ($config !== null) {
                $path = realpath($config);
                return $path === false ? null : (is_dir($path) ? $path : dirname($path));
            }
        }
        return null;
    }

    public static function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    /** @return array<string, array> what MFTF's own reader makes of one file, by name */
    private static function parse(string $root, string $path, string $content): array
    {
        [$readerClass, $key] = self::ROOTS[$root];
        $reader = ObjectManagerFactory::getObjectManager()->create($readerClass);
        return Closure::bind(function () use ($path, $content, $key) {
            $collector = new \Magento\FunctionalTestingFramework\Exceptions\Collector\ExceptionCollector();
            $merger = $this->createConfigMerger($this->domDocumentClass, $content, $path, $collector);
            $collector->throwException();
            $this->validateSchema($merger);
            // Beside the objects it declares, the root carries the file's own attributes.
            return array_filter($this->converter->convert($merger->getDom())[$key] ?? [], 'is_array');
        }, $reader, get_class($reader))();
    }

    /** @return list<string> the tests put */
    private static function put(string $root, array $parsed): array
    {
        if ($root === 'entities') {
            $class = self::NS . 'DataGenerator\\Handlers\\DataObjectHandler';
            Closure::bind(function () use ($parsed) {
                $this->entityDataObjects = array_merge(
                    $this->entityDataObjects,
                    $this->processParserOutput([self::_ENTITY => $parsed])
                );
            }, $class::getInstance(), $class)();
            return [];
        }
        $class = self::NS . 'Test\\Handlers\\TestObjectHandler';
        $extractor = new \Magento\FunctionalTestingFramework\Test\Util\TestObjectExtractor();
        Closure::bind(function () use ($parsed, $extractor) {
            foreach ($parsed as $name => $data) {
                $this->tests[$name] = $extractor->extractTestData($data, true);
            }
        }, $class::getInstance(), $class)();
        return array_keys($parsed);
    }
}
