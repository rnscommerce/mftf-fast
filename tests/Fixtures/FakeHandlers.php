<?php

declare(strict_types=1);

namespace Magento\FunctionalTestingFramework\Test\Handlers;

// MFTF is not a dependency of this package - the project under test supplies
// it - so the test suite stands in a minimal double for the one handler
// class it exercises. Guarded because a real MFTF install must win if one is
// ever present on the include path.
if (!class_exists(ActionGroupObjectHandler::class, false)) {
    /**
     * Mirrors just the shape HandlerCache relies on: a single static
     * singleton property and a registry property reflection can capture.
     */
    final class ActionGroupObjectHandler
    {
        private static ?self $instance = null;

        /** @var array<string,mixed> */
        private array $actionGroups = [];

        public static function getInstance(): self
        {
            return self::$instance ??= new self();
        }

        public static function reset(): void
        {
            self::$instance = null;
        }
    }
}
