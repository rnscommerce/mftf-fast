<?php
declare(strict_types=1);

namespace MftfFast;

use Magento\FunctionalTestingFramework\Test\Handlers\ActionGroupObjectHandler;
use Magento\FunctionalTestingFramework\Test\Handlers\TestObjectHandler;
use Magento\FunctionalTestingFramework\Test\Objects\ActionObject;
use Magento\FunctionalTestingFramework\Test\Util\ActionMergeUtil;

/**
 * A test as MFTF holds it once merged: every file of it folded together,
 * `extends` resolved, its steps in the order MFTF settles - and the action
 * groups it calls left as calls, each described alongside with its own
 * merged steps. What generation does after this point - expanding the
 * groups, substituting values - is left out.
 */
final class Describe
{
    /** @var array<string, array> */
    private array $groups = [];

    public static function test(string $name): array
    {
        return (new self())->describeTest($name);
    }

    private function describeTest(string $name): array
    {
        $test = TestObjectHandler::getInstance()->getObject($name);
        $hooks = $test->getHooks();

        $out = [
            'name' => $test->getName(),
            'extends' => $test->getParentName(),
            'before' => isset($hooks['before'])
                ? $this->ordered($name, 'before', $hooks['before']->getUnresolvedActions())
                : [],
            'test' => $this->ordered($name, 'Test', $test->getUnresolvedSteps()),
            'after' => isset($hooks['after'])
                ? $this->ordered($name, 'after', $hooks['after']->getUnresolvedActions())
                : [],
        ];
        $out['groups'] = $this->groups;
        return $out;
    }

    /**
     * @param ActionObject[] $actions
     */
    private function ordered(string $context, string $scope, array $actions): array
    {
        $merged = (new ActionMergeUtil($context, $scope))->resolveActionSteps($actions, true);
        return array_values(array_map(fn (ActionObject $action) => $this->step($action), $merged));
    }

    private function step(ActionObject $action): array
    {
        // As written: getCustomActionAttributes() lays what sorting resolved over them.
        $written = new \ReflectionProperty(ActionObject::class, 'actionAttributes');
        $step = [
            'stepKey' => $action->getStepKey(),
            'type' => $action->getType(),
            'attributes' => $written->getValue($action),
        ];
        if ($action->getType() === 'actionGroup') {
            $ref = (string) ($step['attributes']['ref'] ?? '');
            if ($ref !== '') {
                $this->group($ref);
            }
        }
        return $step;
    }

    private function group(string $ref): void
    {
        if (array_key_exists($ref, $this->groups)) {
            return;
        }
        $group = ActionGroupObjectHandler::getInstance()->getObject($ref);
        if ($group === null) {
            return;
        }
        // Filed before its steps are walked: a group calling itself would otherwise never end.
        $this->groups[$ref] = [];
        $arguments = [];
        foreach ($group->getArguments() as $argument) {
            $arguments[] = [
                'name' => $argument->getName(),
                'default' => $argument->getValue(),
            ];
        }
        $this->groups[$ref] = [
            'name' => $group->getName(),
            'extends' => $group->getParentName(),
            'arguments' => $arguments,
            'steps' => $this->ordered($ref, 'ActionGroup', array_map([$this, 'unresolved'], $group->getActions())),
        ];
    }

    /**
     * A copy of the step that ordering leaves as written: MFTF resolves each
     * step's references as it sorts them, and a group's own steps still name
     * its arguments, which resolve only once a call supplies them.
     */
    private function unresolved(ActionObject $action): ActionObject
    {
        $copy = clone $action;
        $resolved = new \ReflectionProperty(ActionObject::class, 'resolvedCustomAttributes');
        $resolved->setValue($copy, ['unresolved' => true]);
        return $copy;
    }
}
