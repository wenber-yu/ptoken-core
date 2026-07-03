<?php

declare(strict_types=1);

namespace Wenbo\PToken\CacheDrivers;

interface PTokenCacheInterface
{
    /**
     * 通过 key 从缓存中获取值。
     *
     * @param string $key
     * @return mixed|null key 不存在时返回 null
     */
    public function get(string $key): mixed;

    /**
     * 将值存入缓存并设置 TTL。
     *
     * @param string $key
     * @param mixed  $value
     * @param int    $ttl  存活时间（秒）
     * @return bool
     */
    public function set(string $key, mixed $value, int $ttl): bool;

    /**
     * 通过 key 从缓存中删除值。
     *
     * @param string $key
     * @return bool
     */
    public function delete(string $key): bool;

    /**
     * 检查 key 是否存在于缓存中。
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool;
}
