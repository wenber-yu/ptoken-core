<?php

declare(strict_types=1);

namespace Wenbo\PToken\CacheDrivers;

use Override;
use Wenbo\PToken\CacheDrivers\PTokenCacheInterface;
use RuntimeException;

class PTokenFileDriver implements PTokenCacheInterface
{
    private readonly string $cachePath;

    /**
     * @param string $cachePath Directory for storing cache files.
     * @throws RuntimeException when directory is not writable.
     */
    public function __construct(string $cachePath)
    {
        if (empty($cachePath)) {
            throw new RuntimeException('File cache path must be configured for FileDriver');
        }

        if (!is_dir($cachePath) && !mkdir($cachePath, 0755, true)) {
            throw new RuntimeException("Failed to create cache directory: {$cachePath}");
        }

        if (!is_writable($cachePath)) {
            throw new RuntimeException("Cache directory is not writable: {$cachePath}");
        }

        $this->cachePath = rtrim($cachePath, '/');
    }

    #[Override]
    public function get(string $key): mixed
    {
        $filePath = $this->getFilePath($key);

        if (!file_exists($filePath)) {
            return null;
        }

        $content = @file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        $entry = @unserialize($content);
        if (!is_array($entry) || !isset($entry['value'], $entry['expireAt'])) {
            return null;
        }

        if (time() > $entry['expireAt']) {
            @unlink($filePath);
            return null;
        }

        return $entry['value'];
    }

    #[Override]
    public function set(string $key, mixed $value, int $ttl): bool
    {
        if ($ttl <= 0) {
            throw new RuntimeException('TTL must be greater than 0');
        }

        $filePath = $this->getFilePath($key);

        $entry = [
            'value'    => $value,
            'expireAt' => time() + $ttl,
        ];

        $serialized = serialize($entry);
        $dir = dirname($filePath);

        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new RuntimeException("Failed to create cache sub-directory: {$dir}");
        }

        $result = @file_put_contents($filePath, $serialized, LOCK_EX);
        return $result !== false;
    }

    #[Override]
    public function delete(string $key): bool
    {
        $filePath = $this->getFilePath($key);

        if (!file_exists($filePath)) {
            return false;
        }

        return @unlink($filePath);
    }

    #[Override]
    public function has(string $key): bool
    {
        $filePath = $this->getFilePath($key);

        if (!file_exists($filePath)) {
            return false;
        }

        $content = @file_get_contents($filePath);
        if ($content === false) {
            return false;
        }

        $entry = @unserialize($content);
        if (!is_array($entry) || !isset($entry['expireAt'])) {
            return false;
        }

        if (time() > $entry['expireAt']) {
            @unlink($filePath);
            return false;
        }

        return true;
    }

    /**
     * 根据缓存 key 生成安全的文件路径。
     */
    private function getFilePath(string $key): string
    {
        $safeName = md5($key);
        $subDir = substr($safeName, 0, 2);
        return "{$this->cachePath}/{$subDir}/{$safeName}.cache";
    }
}
