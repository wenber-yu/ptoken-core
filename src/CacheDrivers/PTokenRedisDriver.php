<?php

declare(strict_types=1);

namespace Wenbo\PToken\CacheDrivers;

use Override;
use Wenbo\PToken\CacheDrivers\PTokenCacheInterface;
use RuntimeException;

class PTokenRedisDriver implements PTokenCacheInterface
{
    private readonly \Redis $redis;

    /**
     * @param array{host: string, port: int, password: string, database: int} $config
     * @throws RuntimeException Redis 扩展未加载或连接失败时抛出
     */
    public function __construct(array $config)
    {
        if (!extension_loaded('redis')) {
            throw new RuntimeException('Redis PHP extension is not loaded. Required for RedisDriver.');
        }

        $this->redis = new \Redis();

        $connected = $this->redis->connect(
            $config['host'] ?? '127.0.0.1',
            (int)($config['port'] ?? 6379)
        );

        if (!$connected) {
            throw new RuntimeException('Failed to connect to Redis server at ' . ($config['host'] ?? '127.0.0.1'));
        }

        if (!empty($config['password'])) {
            $this->redis->auth($config['password']);
        }

        $database = (int)($config['database'] ?? 0);
        if ($database > 0) {
            $this->redis->select($database);
        }
    }

    #[Override]
    public function get(string $key): mixed
    {
        $value = $this->redis->get($key);

        if ($value === false) {
            return null;
        }

        if (json_validate($value)) {
            return json_decode($value, true);
        }

        return $value;
    }

    #[Override]
    public function set(string $key, mixed $value, int $ttl): bool
    {
        if ($ttl <= 0) {
            throw new RuntimeException('TTL must be greater than 0');
        }

        $serialized = json_encode($value, JSON_UNESCAPED_UNICODE);
        if ($serialized === false) {
            throw new RuntimeException('Failed to serialize cache value');
        }

        return $this->redis->setex($key, $ttl, $serialized);
    }

    #[Override]
    public function delete(string $key): bool
    {
        return (bool)$this->redis->del($key);
    }

    #[Override]
    public function has(string $key): bool
    {
        return (bool)$this->redis->exists($key);
    }
}
