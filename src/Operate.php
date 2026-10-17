<?php
declare(strict_types=1);

namespace MftfFast;

use Magento\FunctionalTestingFramework\DataGenerator\Handlers\DataObjectHandler;
use Magento\FunctionalTestingFramework\DataGenerator\Handlers\OperationDefinitionObjectHandler;
use Magento\FunctionalTestingFramework\DataGenerator\Objects\EntityDataObject;
use Magento\FunctionalTestingFramework\DataGenerator\Persist\CurlHandler;
use Magento\FunctionalTestingFramework\DataGenerator\Persist\DataPersistenceHandler;
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
            'create' => $this->create($request['entity'], $request['requires'] ?? []),
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

    private function create(string $entityName, array $requires): array
    {
        $entity = $this->entity($entityName);
        // msq() answers the same suffix for a name for the life of the process; a test gets a fresh one per test.
        unset(MagentoSequence::$hash[$entityName]);
        $handler = new DataPersistenceHandler($entity, $this->handlers($requires));
        $exchange = $this->send('create', $entity, $handler, $this->createdOf($requires));
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
        $dependents = array_merge($this->createdOf($requires), [$handler->getCreatedObject()]);
        $exchange = $this->send('update', $update, $handler, $dependents);
        return ['key' => $key] + $exchange + ['created' => $handler->getCreatedObject()->getAllData()];
    }

    private function delete(string $key): array
    {
        $staged = $this->staged($key);
        $created = $staged['handler']->getCreatedObject();
        $exchange = $this->send('delete', $created, null, []);
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
        $response = $curl->executeRequest($dependents);
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
