<?php
declare(strict_types=1);

namespace MftfFast;

use Magento\FunctionalTestingFramework\DataGenerator\Handlers\DataObjectHandler;
use Magento\FunctionalTestingFramework\DataGenerator\Handlers\OperationDefinitionObjectHandler;
use Magento\FunctionalTestingFramework\DataGenerator\Objects\EntityDataObject;
use Magento\FunctionalTestingFramework\DataGenerator\Persist\CurlHandler;
use Magento\FunctionalTestingFramework\DataGenerator\Persist\DataPersistenceHandler;
use Magento\FunctionalTestingFramework\Exceptions\TestFrameworkException;
use Magento\FunctionalTestingFramework\Module\MagentoSequence;

/**
 * Runs an entity's operations against the installation the way a test's
 * createData, getData, updateData and deleteData do, through MFTF's own
 * DataPersistenceHandler and CurlHandler, and keeps each created entity for
 * the operations after it. One request on stdin per line, one answer on
 * stdout per line, as JSON; what was created lives as long as the process.
 */
final class Operate
{
    /** What MFTF's form executors put before the page when a successRegex does not match it. */
    private const NOT_CREATED = 'Entity creation was not successful! Response: ';

    /** @var array<string, array{handler: DataPersistenceHandler, entity: string, type: string}> */
    private array $staged = [];

    public function serve($in, $out): void
    {
        class_exists(MagentoSequence::class);
        // CurlHandler attaches the exchange to the Allure step it thinks is running; outside a test there is none.
        \Qameta\Allure\Allure::getLifecycleConfigurator()->setOutputDirectory(sys_get_temp_dir() . '/mftf-fast-operate');
        // A parent's stdin is often a socket, where fgets gives up after default_socket_timeout; only the end of it stops this.
        while (!feof($in)) {
            $line = trim((string) fgets($in));
            if ($line === '') {
                continue;
            }
            ob_start();
            try {
                $answer = ['ok' => true] + $this->handle(json_decode($line, true, 512, JSON_THROW_ON_ERROR));
            } catch (NotCreated $e) {
                $answer = ['ok' => false, 'error' => $e->getMessage(), 'class' => $e::class, 'exchange' => $e->exchange];
            } catch (\Throwable $e) {
                $answer = ['ok' => false, 'error' => $e->getMessage(), 'class' => $e::class];
            }
            $printed = ob_get_clean();
            if ($printed !== '') {
                $answer['printed'] = $printed;
            }
            fwrite($out, json_encode($answer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n");
        }
    }

    private function handle(array $request): array
    {
        return match ($request['do'] ?? null) {
            'create' => $this->create($request['entity'], $request['requires'] ?? [], $request['fields'] ?? []),
            'get' => $this->get($request['entity'], $request['requires'] ?? []),
            'update' => $this->update($request['key'], $request['entity'], $request['requires'] ?? []),
            'delete' => $this->delete($request['key']),
            'staged' => ['staged' => array_map(
                fn (array $s) => ['entity' => $s['entity'], 'type' => $s['type'], 'data' => $s['handler']->getCreatedObject()->getAllData()],
                $this->staged
            )],
            default => throw new \InvalidArgumentException('do is one of create, get, update, delete, staged'),
        };
    }

    /** @param array<array{key: string, value: string, unique?: 'prefix'|'suffix'}> $fields */
    private function create(string $entityName, array $requires, array $fields): array
    {
        $entity = $this->entity($entityName);
        // msq() answers the same suffix for a name for the life of the process; a test gets a fresh one per test.
        unset(MagentoSequence::$hash[$entityName]);
        $handler = new DataPersistenceHandler($entity, $this->handlers($requires), $this->customFields($fields));
        $merged = (new \ReflectionProperty(DataPersistenceHandler::class, 'entityObject'))->getValue($handler);
        $exchange = $this->send('create', $merged, $handler, $this->createdOf($requires));
        $key = $this->keyFor($entityName);
        $this->staged[$key] = ['handler' => $handler, 'entity' => $entityName, 'type' => $entity->getType()];
        return ['key' => $key, 'type' => $entity->getType()] + $exchange + ['created' => $handler->getCreatedObject()->getAllData()];
    }

    private function get(string $entityName, array $requires): array
    {
        $entity = $this->entity($entityName);
        $handler = new DataPersistenceHandler($entity, $this->handlers($requires));
        $exchange = $this->send('get', $entity, $handler, $this->createdOf($requires));
        return ['type' => $entity->getType()] + $exchange + ['created' => $handler->getCreatedObject()->getAllData()];
    }

    private function update(string $key, string $entityName, array $requires): array
    {
        $staged = $this->staged($key);
        $update = $this->entity($entityName);
        $handler = $staged['handler'];
        $dependents = array_merge($this->dependentsOf($handler), $this->createdOf($requires), [$handler->getCreatedObject()]);
        $exchange = $this->send('update', $update, $handler, $dependents);
        return ['key' => $key] + $exchange + ['created' => $handler->getCreatedObject()->getAllData()];
    }

    private function delete(string $key): array
    {
        $staged = $this->staged($key);
        $created = $staged['handler']->getCreatedObject();
        $exchange = $this->send('delete', $created, null, $this->dependentsOf($staged['handler']));
        unset($this->staged[$key]);
        return ['key' => $key] + $exchange;
    }

    /**
     * One operation through MFTF's CurlHandler, answering what was sent and
     * what came back; with a handler, its created object is set from them as
     * DataPersistenceHandler sets it.
     *
     * @param EntityDataObject[] $dependents
     */
    private function send(string $operation, EntityDataObject $entity, ?DataPersistenceHandler $handler, array $dependents): array
    {
        $curl = new CurlHandler($operation, $entity);
        $definition = (new \ReflectionProperty(CurlHandler::class, 'operationDefinition'))->getValue($curl);
        $resolve = new \ReflectionMethod(CurlHandler::class, 'resolveUrlReference');
        $url = $resolve->invoke($curl, $definition->getApiUrl(), array_merge([$entity], $dependents));
        $method = $definition->getApiMethod() ?? ['create' => 'POST', 'delete' => 'DELETE', 'update' => 'PUT', 'get' => 'GET'][$operation];

        $started = microtime(true);
        try {
            $response = $curl->executeRequest($dependents);
        } catch (TestFrameworkException $e) {
            // A form post whose successRegex missed: MFTF says so only in this message, with the page after it.
            if (!str_starts_with($e->getMessage(), self::NOT_CREATED)) {
                throw $e;
            }
            throw new NotCreated('Entity creation was not successful!', [
                'operation' => $operation,
                'auth' => $definition->getAuth(),
                'method' => $method,
                'url' => $url,
                'request' => $curl->getRequestDataArray(),
                'page' => substr($e->getMessage(), strlen(self::NOT_CREATED)),
                'ms' => (int) round((microtime(true) - $started) * 1000),
            ]);
        }
        $took = microtime(true) - $started;

        if ($handler !== null) {
            (new \ReflectionMethod(DataPersistenceHandler::class, 'setCreatedObject'))
                ->invoke($handler, $response, null, $curl->getRequestDataArray(), $curl->isContentTypeJson());
        }
        return [
            'operation' => $operation,
            'auth' => $definition->getAuth(),
            'method' => $method,
            'url' => $url,
            'request' => $curl->getRequestDataArray(),
            'response' => json_decode((string) $response, true) ?? $response,
            'ms' => (int) round($took * 1000),
        ];
    }

    /**
     * A createData's <field> overrides, each made unique as the generator
     * makes one with a unique attribute. Keys are lowercased, as MFTF stores
     * an entity's own and looks them up, so a mixed-case key still overrides.
     *
     * @param array<array{key: string, value: string, unique?: 'prefix'|'suffix'}> $fields
     * @return array<string, string>
     */
    private function customFields(array $fields): array
    {
        $custom = [];
        foreach ($fields as $field) {
            $value = (string) $field['value'];
            $custom[strtolower($field['key'])] = match ($field['unique'] ?? null) {
                'prefix' => uniqid() . $value,
                'suffix' => $value . uniqid(),
                null => $value,
                default => throw new \InvalidArgumentException('unique is prefix or suffix'),
            };
        }
        return $custom;
    }

    private function entity(string $name): EntityDataObject
    {
        $entity = DataObjectHandler::getInstance()->getObject($name);
        if ($entity === null) {
            throw new \RuntimeException("No data entity is named {$name}.");
        }
        return $entity;
    }

    private function staged(string $key): array
    {
        return $this->staged[$key] ?? throw new \RuntimeException("Nothing staged is named {$key}.");
    }

    /**
     * What an entry was created with, which MFTF's updateData and deleteData
     * fill a URL and a body from as well as the entry itself.
     *
     * @return EntityDataObject[]
     */
    private function dependentsOf(DataPersistenceHandler $handler): array
    {
        return (new \ReflectionProperty(DataPersistenceHandler::class, 'dependentObjects'))->getValue($handler) ?? [];
    }

    /** @return DataPersistenceHandler[] */
    private function handlers(array $keys): array
    {
        return array_map(fn (string $key) => $this->staged($key)['handler'], $keys);
    }

    /** @return EntityDataObject[] */
    private function createdOf(array $keys): array
    {
        return array_map(fn (DataPersistenceHandler $h) => $h->getCreatedObject(), $this->handlers($keys));
    }

    private function keyFor(string $entityName): string
    {
        $key = $entityName;
        for ($n = 2; isset($this->staged[$key]); $n++) {
            $key = $entityName . $n;
        }
        return $key;
    }
}
