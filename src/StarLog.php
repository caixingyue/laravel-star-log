<?php

namespace Caixingyue\LaravelStarLog;

use Caixingyue\LaravelStarLog\Support\UniqueId;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

readonly class StarLog
{
    /**
     * Create a new star log instance.
     */
    public function __construct(
        private Request $request,
        private array   $config = []
    ) {}

    /**
     * Get Request ID
     *
     * @return int|null
     */
    public function getRequestId(): ?int
    {
        return $this->getAvailableObjectId($this->request, 'requestId', 'request');
    }

    /**
     * Get Artisan ID
     *
     * @param object|string|null $object
     * @return int|null
     */
    public function getArtisanId(object|string $object = null): ?int
    {
        return $this->getAvailableObjectId($object, 'artisanId', 'artisan');
    }

    /**
     * Get Queue ID
     *
     * @param object|string|null $object
     * @return int|null
     */
    public function getQueueId(object|string $object = null): ?int
    {
        return $this->getAvailableObjectId($object, 'queueId', 'queue');
    }

    /**
     * Get injection object id
     *
     * @param object|string $object
     * @return int|null
     */
    public function getInjectionObjectId(object|string $object): ?int
    {
        return Arr::get($this->getInjectionLastObject($object), 'id');
    }

    /**
     * Get last a injection object info
     *
     * @param object|string $object
     * @return array
     */
    public function getInjectionLastObject(object|string $object): array
    {
        return Arr::last($this->getInjectionObject($object), default: []);
    }

    /**
     * Get injection object lists
     *
     * @param object|string $object
     * @return array
     */
    public function getInjectionObject(object|string $object): array
    {
        $objectName = $this->getObjectName($object);
        return Arr::where($this->getStarLogIds(), function (array $item) use ($objectName) {
            return Str::contains($item['name'], $objectName);
        });
    }

    /**
     * Get object available id
     *
     * @param object|string|null $object
     * @param string|null $key
     * @param string|null $type
     * @return int|null
     */
    public function getAvailableObjectId(object|string $object = null, string $key = null, string $type = null): ?int
    {
        if ($id = data_get($object, $key)) {
            return $id;
        }

        if ($id = $this->request->attributes->get($key)) {
            return $id;
        }

        if ($object && $id = $this->getInjectionObjectId($object)) {
            return $id;
        }

        $typeData = Arr::last($this->getStarLogIds(), function (array $item) use ($type) {
            return $item['type'] === $type;
        }, []);

        return Arr::get($typeData, 'id');
    }

    /**
     * Get all injection id list
     *
     * @return array
     */
    public function getInjectionIds(): array
    {
        return $this->getStarLogIds();
    }

    /**
     * Get a list of injection IDs for all fields
     *
     * @return array
     */
    private function getStarLogIds(): array
    {
        return $this->request->attributes->get('starLogIds', []);
    }

    /**
     * Load object star log ids
     *
     * @param object|string $object
     * @return void
     */
    public function loadObjectStarLogIds(object|string $object): void
    {
        $data = data_get($object, 'starLogIds', []);
        $this->setStarLogIds($data);
    }

    /**
     * Set star log ids
     *
     * @param array $data
     * @return void
     */
    public function setStarLogIds(array $data): void
    {
        $this->request->attributes->set('starLogIds', $data);
    }

    /**
     * Append a new request id to the object
     *
     * @return int
     */
    public function appendRequestId(): int
    {
        $requestId = UniqueId::generate(10);

        $this->append($requestId, $this->request, 'request');

        $this->request->attributes->add(compact('requestId'));

        return $requestId;
    }

    /**
     * Append a new artisan task id to the object
     *
     * @param object|string $object
     * @return int
     */
    public function appendArtisanTaskId(object|string $object): int
    {
        $taskId = UniqueId::generate(8);

        $this->append($taskId, $object, 'artisan');

        return $taskId;
    }

    /**
     * Append a new queue task id to the object
     *
     * @param object|string $object
     * @return int
     */
    public function appendQueueTaskId(object|string $object): int
    {
        $taskId = UniqueId::generate(8);

        $this->append($taskId, $object, 'queue');

        return $taskId;
    }

    /**
     * Append a new id object to the chain
     *
     * @param int $id
     * @param object|string $object
     * @param string $type
     * @return void
     */
    private function append(int $id, object|string $object, string $type): void
    {
        $ids = $this->getStarLogIds();

        $ids[] = ['id' => $id, 'name' => get_class($object), 'type' => $type];

        $this->setStarLogIds($ids);
    }

    /**
     * Get star log config
     *
     * @param string|null $key
     * @param mixed|null $default
     * @return mixed
     */
    public function getConfig(string $key = null, mixed $default = null): mixed
    {
        return $key ? Arr::get($this->config, $key, $default) : $this->config;
    }

    /**
     * Get object class name
     *
     * @param object|string $object
     * @return string
     */
    public function getObjectName(object|string $object): string
    {
        return is_object($object) ? get_class($object) : $object;
    }
}
