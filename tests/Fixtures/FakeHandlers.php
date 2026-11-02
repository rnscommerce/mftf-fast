<?php

declare(strict_types=1);

// MFTF is supplied by the project under test, not by this package, so the test
// suite stands in the two classes bin/mftf-fast needs: the command list, and the
// one handler the fake command builds. Guarded so a real install always wins.

namespace Magento\FunctionalTestingFramework\Test\Handlers;

if (!class_exists(TestObjectHandler::class, false)) {
    /**
     * Same shape as MFTF's: the singleton is assigned before it is initialised,
     * so a throw mid-parse leaves a live instance with an empty registry.
     */
    final class TestObjectHandler
    {
        private static ?self $testObjectHandler = null;

        /** @var array<string,mixed> */
        private array $tests = [];

        public static function getInstance(string $die = ''): self
        {
            if (!self::$testObjectHandler) {
                self::$testObjectHandler = new self();
                self::$testObjectHandler->initTestData($die);
            }
            return self::$testObjectHandler;
        }

        private function initTestData(string $die): void
        {
            if ($die === 'error') {
                throw new \Error('Class "DOMDocument" not found');
            }
            if ($die === 'exception') {
                throw new \RuntimeException('Unable to parse test XML');
            }
            $this->tests = ['AdminLoginSuccessfulTest' => ['name' => 'AdminLoginSuccessfulTest']];
        }
    }
}

namespace Magento\FunctionalTestingFramework\Util;

if (!class_exists(ModuleResolver::class, false)) {
    /** Same shape as MFTF's: the instance is asked only while nothing has set the list. */
    final class ModuleResolver
    {
        private static ?self $instance = null;

        /** @var list<string>|null */
        protected $enabledModules = null;

        public static function getInstance(): self
        {
            return self::$instance ??= new self();
        }

        /** @return list<string> */
        public function getEnabledModules(): array
        {
            if (isset($this->enabledModules)) {
                return $this->enabledModules;
            }
            throw new \RuntimeException('Cannot retrieve API token with credentials.');
        }
    }
}

namespace Magento\FunctionalTestingFramework\Console;

use Magento\FunctionalTestingFramework\Test\Handlers\TestObjectHandler;
use Magento\FunctionalTestingFramework\Util\ModuleResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

if (!class_exists(CommandList::class, false)) {
    final class CommandList
    {
        /** @return list<Command> */
        public function getCommands(): array
        {
            $generate = new class ('generate:tests') extends Command {
                protected function configure(): void
                {
                    $this->addOption('die', null, InputOption::VALUE_REQUIRED, '', '');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    $die = (string) $input->getOption('die');
                    TestObjectHandler::getInstance($die);
                    if ($die === 'errors') {
                        $output->writeln('Generate Tests Command Run (with errors)');
                        return Command::FAILURE;
                    }
                    $output->writeln('Generate Tests Command Run');
                    return Command::SUCCESS;
                }
            };
            $modules = new class ('modules:enabled') extends Command {
                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    $output->writeln(implode(',', ModuleResolver::getInstance()->getEnabledModules()));
                    return Command::SUCCESS;
                }
            };
            return [$generate, $modules];
        }
    }
}
