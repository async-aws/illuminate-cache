<?php

namespace AsyncAws\Illuminate\Cache;

use AsyncAws\Core\Exception\RuntimeException;
use AsyncAws\DynamoDb\DynamoDbClient;
use AsyncAws\DynamoDb\Exception\ConditionalCheckFailedException;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\InteractsWithTime;

/**
 * This class is a port from Illuminate\Cache\DynamoDbStore.
 */
class AsyncAwsDynamoDbStore implements LockProvider, Store
{
    use InteractsWithTime;

    /**
     * The DynamoDB client instance.
     *
     * @var DynamoDbClient
     */
    private $dynamoDb;

    /**
     * The table name.
     *
     * @var string
     */
    private $table;

    /**
     * The name of the attribute that should hold the key.
     *
     * @var string
     */
    private $keyAttribute;

    /**
     * The name of the attribute that should hold the value.
     *
     * @var string
     */
    private $valueAttribute;

    /**
     * The name of the attribute that should hold the expiration timestamp.
     *
     * @var string
     */
    private $expirationAttribute;

    /**
     * A string that should be prepended to keys.
     *
     * @var string
     */
    private $prefix;

    /**
     * Create a new store instance.
     */
    public function __construct(
        DynamoDbClient $dynamo,
        string $table,
        string $keyAttribute = 'key',
        string $valueAttribute = 'value',
        string $expirationAttribute = 'expires_at',
        string $prefix = ''
    ) {
        $this->table = $table;
        $this->dynamoDb = $dynamo;
        $this->keyAttribute = $keyAttribute;
        $this->valueAttribute = $valueAttribute;
        $this->expirationAttribute = $expirationAttribute;

        $this->setPrefix($prefix);
    }

    /**
     * Retrieve an item from the cache by key.
     *
     * @param string|array $key
     *
     * @return mixed
     */
    public function get($key)
    {
        if (\is_array($key)) {
            return $this->many($key);
        }

        return $this->getRaw($key, false);
    }

    /**
     * Retrieve an item from the cache by key using a possibly consistent read.
     *
     * @return mixed
     */
    public function getRaw(string $key, bool $consistent)
    {
        $response = $this->dynamoDb->getItem([
            'TableName' => $this->table,
            'ConsistentRead' => $consistent,
            'Key' => [
                $this->keyAttribute => [
                    'S' => $this->prefix . $key,
                ],
            ],
        ]);

        $item = $response->getItem();
        if (empty($item)) {
            return null;
        }

        if ($this->isExpired($item)) {
            return null;
        }

        if (isset($item[$this->valueAttribute])) {
            return $this->unserialize(
                $item[$this->valueAttribute]->getS() ??
                $item[$this->valueAttribute]->getN() ??
                null
            );
        }

        return null;
    }

    /**
     * Retrieve multiple items from the cache by key.
     *
     * Items not found in the cache will have a null value.
     *
     * @return array
     */
    public function many(array $keys)
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key);
        }

        // TODO Use BatchGetItem. Blocked by https://github.com/async-aws/aws/issues/566
        return $result;
    }

    /**
     * Store an item in the cache for a given number of seconds.
     *
     * @param string $key
     * @param mixed  $value
     * @param int    $seconds
     *
     * @return bool
     */
    public function put($key, $value, $seconds)
    {
        $this->dynamoDb->putItem([
            'TableName' => $this->table,
            'Item' => [
                $this->keyAttribute => [
                    'S' => $this->prefix . $key,
                ],
                $this->valueAttribute => [
                    $this->type($value) => $this->serialize($value),
                ],
                $this->expirationAttribute => [
                    'N' => (string) $this->toTimestamp($seconds),
                ],
            ],
        ]);

        return true;
    }

    /**
     * Store multiple items in the cache for a given number of $seconds.
     *
     * @param int $seconds
     *
     * @return bool
     */
    public function putMany(array $values, $seconds)
    {
        foreach ($values as $key => $value) {
            $this->put($key, $value, $seconds);
        }

        // TODO Use BatchWriteItem. Blocked by https://github.com/async-aws/aws/issues/566
        return true;
    }

    /**
     * Store an item in the cache if the key doesn't exist.
     *
     * @param string $key
     * @param mixed  $value
     * @param int    $seconds
     *
     * @return bool
     */
    public function add($key, $value, $seconds)
    {
        try {
            $this->dynamoDb->putItem([
                'TableName' => $this->table,
                'Item' => [
                    $this->keyAttribute => [
                        'S' => $this->prefix . $key,
                    ],
                    $this->valueAttribute => [
                        $this->type($value) => $this->serialize($value),
                    ],
                    $this->expirationAttribute => [
                        'N' => (string) $this->toTimestamp($seconds),
                    ],
                ],
                'ConditionExpression' => 'attribute_not_exists(#key) OR #expires_at < :now',
                'ExpressionAttributeNames' => [
                    '#key' => $this->keyAttribute,
                    '#expires_at' => $this->expirationAttribute,
                ],
                'ExpressionAttributeValues' => [
                    ':now' => [
                        'N' => (string) Carbon::now()->getTimestamp(),
                    ],
                ],
            ]);

            return true;
        } catch (ConditionalCheckFailedException $e) {
            return false;
        }
    }

    /**
     * Increment the value of an item in the cache.
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return int|bool
     */
    public function increment($key, $value = 1)
    {
        try {
            $response = $this->dynamoDb->updateItem([
                'TableName' => $this->table,
                'Key' => [
                    $this->keyAttribute => [
                        'S' => $this->prefix . $key,
                    ],
                ],
                'ConditionExpression' => 'attribute_exists(#key) AND #expires_at > :now',
                'UpdateExpression' => 'SET #value = #value + :amount',
                'ExpressionAttributeNames' => [
                    '#key' => $this->keyAttribute,
                    '#value' => $this->valueAttribute,
                    '#expires_at' => $this->expirationAttribute,
                ],
                'ExpressionAttributeValues' => [
                    ':now' => [
                        'N' => (string) Carbon::now()->getTimestamp(),
                    ],
                    ':amount' => [
                        'N' => (string) $value,
                    ],
                ],
                'ReturnValues' => 'UPDATED_NEW',
            ]);

            return (int) $response->getAttributes()[$this->valueAttribute]->getN();
        } catch (ConditionalCheckFailedException $e) {
            return false;
        }
    }

    /**
     * Decrement the value of an item in the cache.
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return int|bool
     */
    public function decrement($key, $value = 1)
    {
        try {
            $response = $this->dynamoDb->updateItem([
                'TableName' => $this->table,
                'Key' => [
                    $this->keyAttribute => [
                        'S' => $this->prefix . $key,
                    ],
                ],
                'ConditionExpression' => 'attribute_exists(#key) AND #expires_at > :now',
                'UpdateExpression' => 'SET #value = #value - :amount',
                'ExpressionAttributeNames' => [
                    '#key' => $this->keyAttribute,
                    '#value' => $this->valueAttribute,
                    '#expires_at' => $this->expirationAttribute,
                ],
                'ExpressionAttributeValues' => [
                    ':now' => [
                        'N' => (string) Carbon::now()->getTimestamp(),
                    ],
                    ':amount' => [
                        'N' => (string) $value,
                    ],
                ],
                'ReturnValues' => 'UPDATED_NEW',
            ]);

            return (int) $response->getAttributes()[$this->valueAttribute]->getN();
        } catch (ConditionalCheckFailedException $e) {
            return false;
        }
    }

    /**
     * Store an item in the cache indefinitely.
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return bool
     */
    public function forever($key, $value)
    {
        return $this->put($key, $value, Carbon::now()->addYears(5)->getTimestamp());
    }

    /**
     * Get a lock instance.
     *
     * @param string      $name
     * @param int         $seconds
     * @param string|null $owner
     *
     * @return AsyncAwsDynamoDbLock
     */
    public function lock($name, $seconds = 0, $owner = null)
    {
        return new AsyncAwsDynamoDbLock($this, $this->prefix . $name, $seconds, $owner);
    }

    /**
     * Restore a lock instance using the owner identifier.
     *
     * @param string $name
     * @param string $owner
     *
     * @return AsyncAwsDynamoDbLock
     */
    public function restoreLock($name, $owner)
    {
        return $this->lock($name, 0, $owner);
    }

    /**
     * Remove an item from the cache.
     *
     * @param string $key
     *
     * @return bool
     */
    public function forget($key)
    {
        $this->dynamoDb->deleteItem([
            'TableName' => $this->table,
            'Key' => [
                $this->keyAttribute => [
                    'S' => $this->prefix . $key,
                ],
            ],
        ]);

        return true;
    }

    /**
     * Remove all items from the cache.
     *
     * @return bool
     *
     * @throws \RuntimeException
     */
    public function flush()
    {
        throw new RuntimeException('DynamoDb does not support flushing an entire table. Please create a new table.');
    }

    /**
     * Get the cache key prefix.
     *
     * @return string
     */
    public function getPrefix()
    {
        return $this->prefix;
    }

    /**
     * Set the cache key prefix.
     *
     * @param string $prefix
     *
     * @return void
     */
    public function setPrefix($prefix)
    {
        $this->prefix = !empty($prefix) ? $prefix . ':' : '';
    }

    /**
     * Determine if the given item is expired.
     *
     * @param array<string, AttributeValue> $item
     *
     * @return bool
     */
    private function isExpired(array $item, ?\DateTimeInterface $expiration = null)
    {
        $expiration = $expiration ?: Carbon::now();

        return isset($item[$this->expirationAttribute])
               && $expiration->getTimestamp() >= $item[$this->expirationAttribute]->getN();
    }

    /**
     * Get the UNIX timestamp for the given number of seconds.
     */
    private function toTimestamp(int $seconds): int
    {
        return $seconds > 0
                    ? $this->availableAt($seconds)
                    : Carbon::now()->getTimestamp();
    }

    /**
     * Serialize the value.
     *
     * @param mixed $value
     */
    private function serialize($value): string
    {
        return is_numeric($value) ? (string) $value : serialize($value);
    }

    /**
     * Unserialize the value.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    private function unserialize($value)
    {
        if (false !== filter_var($value, \FILTER_VALIDATE_INT)) {
            return (int) $value;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return unserialize($value);
    }

    /**
     * Get the DynamoDB type for the given value.
     *
     * @param mixed $value
     *
     * @return string
     */
    private function type($value)
    {
        return is_numeric($value) ? 'N' : 'S';
    }
}
