<?php
declare(strict_types=1);

namespace MftfFast;

use Magento\FunctionalTestingFramework\DataGenerator\Handlers\DataObjectHandler;
use Magento\FunctionalTestingFramework\DataGenerator\Handlers\OperationDefinitionObjectHandler;
use Magento\FunctionalTestingFramework\DataGenerator\Objects\EntityDataObject;
use Magento\FunctionalTestingFramework\DataGenerator\Persist\OperationDataArrayResolver;
use Magento\FunctionalTestingFramework\Module\MagentoSequence;

/**
 * The body a createData or updateData of an entity sends, built by MFTF's own
 * OperationDataArrayResolver exactly as CurlHandler builds it - without
 * sending it, and with what only a run can fill marked rather than filled:
 *
 * - a unique field, which a run prefixes or suffixes with a sequence of its own
 * - a var field, which a run takes from the entities the step depends on
 * - an `{{_ENV.x}}` or `{{_CREDS.x}}` reference, which a run reads or decrypts
 *
 * and which entity filled each object of the body.
 */
final class Operation
{
    /** What msq() answers for every entity here: one character no value holds. */
    private const UNIQUE = "\u{E000}";
    /** Wraps an `{{_ENV.x}}` or `{{_CREDS.x}}` reference so that resolving leaves it alone. */
    private const REFERENCE = "\u{E002}";
    /** A var field's value: a number, so a cast to integer or number keeps it. */
    private const VAR_BASE = 900000000000000;

    /** @var array<int, array{type: string, field: string}> */
    private array $vars = [];
    private array $marks = [];
    private array $fills = [];

    public static function describe(string $entityName, string $operation): array
    {
        return (new self())->body($entityName, $operation);
    }

    private function body(string $entityName, string $operation): array
    {
        $data = DataObjectHandler::getInstance();
        $entity = $data->getObject($entityName);
        if ($entity === null) {
            throw new \RuntimeException("No data entity is named {$entityName}.");
        }
        if ($entity->getType() === null) {
            throw new \RuntimeException("{$entityName} has no type, so no operation builds it.");
        }
        $definition = OperationDefinitionObjectHandler::getInstance()->getAllObjects()[$operation . $entity->getType()] ?? null;
        if ($definition === null) {
            throw new \RuntimeException("{$entity->getType()} has no {$operation} operation.");
        }

        $reached = $this->reach($entity);
        $this->seedUniqueness(array_keys($reached));
        $this->holdReferences($reached);
        $dependents = $this->varEntities($reached);

        $resolver = new class($dependents) extends OperationDataArrayResolver {
            public const FILLED_BY = "\u{E001}entity";

            public function resolveOperationDataArray($entityObject, $operationMetadata, $operation, $fromArray = false)
            {
                $resolved = parent::resolveOperationDataArray($entityObject, $operationMetadata, $operation, $fromArray);
                if (is_array($resolved)) {
                    $resolved[self::FILLED_BY] = $entityObject->getName();
                }
                return $resolved;
            }
        };
        $resolved = $resolver->resolveOperationDataArray($entity, $definition->getOperationMetadata(), $definition->getOperation(), false);

        return [
            'entity' => $entity->getName(),
            'type' => $entity->getType(),
            'operation' => $definition->getOperation(),
            'body' => $this->clean($resolved, [], $resolver::FILLED_BY),
            'fills' => $this->fills,
            'marks' => $this->marks,
        ];
    }

    /**
     * The entity and every entity it links, as far as the links go: what the
     * resolver can reach. Only these, because MFTF reads an entity's parent
     * when the entity is read, and one broken entity elsewhere is no fault of
     * this body.
     *
     * @return array<string, EntityDataObject>
     */
    private function reach(EntityDataObject $entity): array
    {
        $reached = [$entity->getName() => $entity];
        $queue = [$entity];
        while ($queue !== []) {
            foreach (array_keys(array_shift($queue)->getLinkedEntities()) as $name) {
                $linked = DataObjectHandler::getInstance()->getObject($name);
                if ($linked !== null && !isset($reached[$name])) {
                    $reached[$name] = $linked;
                    $queue[] = $linked;
                }
            }
        }
        return $reached;
    }

    /** @param string[] $names */
    private function seedUniqueness(array $names): void
    {
        // Loading MagentoSequence is what defines msq(), which reads this hash first.
        class_exists(MagentoSequence::class);
        foreach ($names as $name) {
            MagentoSequence::$hash[$name] = self::UNIQUE;
        }
    }

    /** @param EntityDataObject[] $entities */
    private function holdReferences(array $entities): void
    {
        $data = new \ReflectionProperty(EntityDataObject::class, 'data');
        foreach ($entities as $entity) {
            $values = $data->getValue($entity);
            if (!is_array($values)) {
                continue;
            }
            foreach ($values as $key => $value) {
                if (is_string($value) && str_contains($value, '{{_')) {
                    $values[$key] = preg_replace('/\{\{(_ENV|_CREDS)\.([^}]+)\}\}/', self::REFERENCE . '$1.$2' . self::REFERENCE, $value);
                }
            }
            $data->setValue($entity, $values);
        }
    }

    /**
     * One entity of every type a var field names, standing in for the entities
     * a step depends on, with each field a var reads set to a number of its own.
     *
     * @param EntityDataObject[] $entities
     * @return EntityDataObject[]
     */
    private function varEntities(array $entities): array
    {
        $fields = [];
        foreach ($entities as $entity) {
            foreach ($entity->getVarReferences() as $reference) {
                [$type, $field] = explode(DataObjectHandler::_SEPARATOR, $reference);
                if (!isset($fields[$type][strtolower($field)])) {
                    $this->vars[] = ['type' => $type, 'field' => $field];
                    $fields[$type][strtolower($field)] = (string) (self::VAR_BASE + count($this->vars) - 1);
                }
            }
        }
        $dependents = [];
        foreach ($fields as $type => $data) {
            $dependents[] = new EntityDataObject(self::UNIQUE . $type, $type, $data, [], []);
        }
        return $dependents;
    }

    private function clean(mixed $value, array $path, string $tag): mixed
    {
        if (is_array($value)) {
            if (array_key_exists($tag, $value)) {
                $this->fills[] = ['path' => $path, 'entity' => $value[$tag]];
                unset($value[$tag]);
            }
            foreach ($value as $key => $child) {
                $value[$key] = $this->clean($child, [...$path, $key], $tag);
            }
            return $value;
        }
        if (is_int($value) || is_float($value) || is_string($value)) {
            $var = is_string($value) && !ctype_digit($value) ? null : (int) $value - self::VAR_BASE;
            if ($var !== null && isset($this->vars[$var]) && (string) $value !== '' && (float) $value === (float) (self::VAR_BASE + $var)) {
                $this->marks[] = ['path' => $path, 'kind' => 'var'] + $this->vars[$var];
                return null;
            }
        }
        if (!is_string($value)) {
            return $value;
        }
        if (str_contains($value, self::UNIQUE)) {
            $prefix = preg_match('/^\d*' . self::UNIQUE . '/u', $value) === 1;
            $value = (string) preg_replace($prefix ? '/^\d*' . self::UNIQUE . '/u' : '/' . self::UNIQUE . '\d*$/u', '', $value);
            $this->marks[] = ['path' => $path, 'kind' => 'unique', 'at' => $prefix ? 'prefix' : 'suffix'];
        }
        if (str_contains($value, self::REFERENCE)) {
            $value = (string) preg_replace_callback(
                '/' . self::REFERENCE . '(_ENV|_CREDS)\.([^' . self::REFERENCE . ']+)' . self::REFERENCE . '/u',
                function (array $match) use ($path): string {
                    $this->marks[] = ['path' => $path, 'kind' => $match[1] === '_ENV' ? 'env' : 'credential', 'name' => $match[2]];
                    return '{{' . $match[1] . '.' . $match[2] . '}}';
                },
                $value
            );
        }
        return $value;
    }
}
