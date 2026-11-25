<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests\Support;

use Psr\SimpleCache\CacheInterface;

/**
 * A PSR-16 cache in memory, standing in for Redis or Memcached shared by
 * every PHP worker on a server. It ignores time to live, which the tests
 * read back from {@see $ttls}.
 */
final class ArrayCache implements CacheInterface
{
    /** @var array<string, mixed> */
    public array $values = [];

    /** @var array<string, int|null> */
    public array $ttls = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->values[$key] = $value;
        $this->ttls[$key] = $ttl instanceof \DateInterval ? null : $ttl;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->values[$key], $this->ttls[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->values = [];
        $this->ttls = [];

        return true;
    }

    /**
     * @param iterable<string> $keys
     *
     * @return iterable<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $found = [];

        foreach ($keys as $key) {
            $found[$key] = $this->get($key, $default);
        }

        return $found;
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }

        return true;
    }

    /**
     * @param iterable<string> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }
}
