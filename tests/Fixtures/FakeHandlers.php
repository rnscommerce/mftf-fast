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

namespace Magento\FunctionalTestingFramework\Console;

use Magento\FunctionalTestingFramework\Test\Handlers\TestObjectHandler;
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
                    TestObjectHandler::getInstance((string) $input->getOption('die'));
                    return Command::SUCCESS;
                }
            };
            return [$generate];
        }
    }
}
