<?php

declare(strict_types=1);

namespace Wenbo\PToken;

use Wenbo\PToken\CacheDrivers\PTokenCacheInterface;
use Wenbo\PToken\CacheDrivers\PTokenFileDriver;
use Wenbo\PToken\CacheDrivers\PTokenRedisDriver;
use RuntimeException;

class PToken
{
    private readonly PTokenConfig $config;

    private readonly PTokenCacheInterface $cacheDriver;

    /**
     * @param PTokenConfig|array<string, mixed> $config      Configuration instance or array.
     * @param PTokenCacheInterface|null         $cacheDriver Optional pre-built cache driver.
     *                                                       When provided, used directly and cache_mode is ignored.
     *                                                       When null, driver is auto-created from cache_mode (standalone mode).
     * @throws RuntimeException on invalid config or driver initialization failure.
     */
    public function __construct(PTokenConfig|array $config = [], ?PTokenCacheInterface $cacheDriver = null)
    {
        if (is_array($config)) {
            $this->config = PTokenConfig::fromArray($config);
        } else {
            $this->config = $config;
        }

        $this->validateConfig();

        if ($cacheDriver !== null) {
            $this->cacheDriver = $cacheDriver;
        } else {
            $this->cacheDriver = $this->createCacheDriver();
        }
    }

    /**
     * Generate a new token for the given user key and associate it with custom data.
     *
     * @param string $userKey Unique identifier for the user (e.g., user ID).
     * @param mixed  $data    Custom data to associate with the token.
     * @return string The generated token string.
     * @throws RuntimeException on encryption or cache failure.
     */
    public function generate(string $userKey, mixed $data): string
    {
        $encryptedUserKey = $this->encryptUserKey($userKey);
        $cacheKey = $this->config->cache_pre_key . $encryptedUserKey;

        if (!$this->config->multi_login) {
            $this->destroyByUserKey($userKey);
        }

        $now = time();
        $randomStr = $this->generateRandomString(16);
        $token = $this->buildToken($encryptedUserKey, $randomStr);

        $cacheData = [
            'userKey'  => $userKey,
            'data'     => $data,
            'createAt' => $now,
            'expireAt' => $now + $this->config->timeout,
        ];

        if (!$this->cacheDriver->set($cacheKey, $cacheData, $this->config->timeout)) {
            throw new RuntimeException('Failed to store token data in cache');
        }

        return $token;
    }

    /**
     * 获取 Token 关联的用户数据。若在续期窗口内则自动续期。
     *
     * @param string $token Token 字符串
     * @return array{userKey: string, data: mixed, createAt: int, expireAt: int}|null
     */
    public function get(string $token): ?array
    {
        $encryptedUserKey = $this->parseToken($token);
        if ($encryptedUserKey === null) {
            return null;
        }

        $cacheKey = $this->config->cache_pre_key . $encryptedUserKey;
        $cacheData = $this->cacheDriver->get($cacheKey);

        if (!is_array($cacheData) || !isset($cacheData['userKey'], $cacheData['expireAt'])) {
            return null;
        }

        if (time() > $cacheData['expireAt']) {
            $this->cacheDriver->delete($cacheKey);
            return null;
        }

        $now = time();
        $remainingTtl = $cacheData['expireAt'] - $now;

        if ($remainingTtl <= ($this->config->timeout - $this->config->max_refresh)) {
            $this->refresh($token);
        }

        return $cacheData;
    }

    /**
     * Destroy a token, removing it from cache.
     *
     * @param string $token The token string.
     * @return bool
     */
    public function destroy(string $token): bool
    {
        $encryptedUserKey = $this->parseToken($token);
        if ($encryptedUserKey === null) {
            return false;
        }

        $cacheKey = $this->config->cache_pre_key . $encryptedUserKey;
        return $this->cacheDriver->delete($cacheKey);
    }

    /**
     * Manually refresh a token's expiration time.
     *
     * @param string $token The token string.
     * @return string|null Returns the token string on success, null on failure.
     */
    public function refresh(string $token): ?string
    {
        $encryptedUserKey = $this->parseToken($token);
        if ($encryptedUserKey === null) {
            return null;
        }

        $cacheKey = $this->config->cache_pre_key . $encryptedUserKey;
        $cacheData = $this->cacheDriver->get($cacheKey);

        if (!is_array($cacheData) || !isset($cacheData['userKey'], $cacheData['data'])) {
            return null;
        }

        $now = time();
        $cacheData['expireAt'] = $now + $this->config->timeout;
        $cacheData['createAt'] = $now;

        $remainingTtl = $cacheData['expireAt'] - $now;
        if ($remainingTtl <= 0) {
            $this->cacheDriver->delete($cacheKey);
            return null;
        }

        if (!$this->cacheDriver->set($cacheKey, $cacheData, $remainingTtl)) {
            throw new RuntimeException('Failed to refresh token in cache');
        }

        return $token;
    }

    /**
     * Get the configuration instance.
     */
    public function getConfig(): PTokenConfig
    {
        return $this->config;
    }

    private function destroyByUserKey(string $userKey): void
    {
        $encryptedUserKey = $this->encryptUserKey($userKey);
        $cacheKey = $this->config->cache_pre_key . $encryptedUserKey;
        $this->cacheDriver->delete($cacheKey);
    }

    private function encryptUserKey(string $userKey): string
    {
        $key = $this->config->encrypt_key;
        $iv = substr($key, 0, 16);

        $encrypted = openssl_encrypt(
            $userKey,
            'aes-256-cbc',
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($encrypted === false) {
            throw new RuntimeException('Encryption failed: ' . openssl_error_string());
        }

        return $this->base64UrlEncode($encrypted);
    }

    private function decryptUserKey(string $encryptedUserKey): ?string
    {
        $key = $this->config->encrypt_key;
        $iv = substr($key, 0, 16);

        $encrypted = $this->base64UrlDecode($encryptedUserKey);
        if ($encrypted === null) {
            return null;
        }

        $decrypted = openssl_decrypt(
            $encrypted,
            'aes-256-cbc',
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        return $decrypted !== false ? $decrypted : null;
    }

    private function buildToken(string $encryptedUserKey, string $randomStr): string
    {
        return $encryptedUserKey . $this->config->token_delimiter . $randomStr;
    }

    private function parseToken(string $token): ?string
    {
        $delimiter = $this->config->token_delimiter;
        $parts = explode($delimiter, $token, 2);

        if (count($parts) !== 2 || empty($parts[0])) {
            return null;
        }

        return $parts[0];
    }

    private function generateRandomString(int $length): string
    {
        $bytes = random_bytes($length);
        return $this->base64UrlEncode($bytes);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): ?string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        return $decoded !== false ? $decoded : null;
    }

    private function validateConfig(): void
    {
        if (strlen($this->config->encrypt_key) !== 32) {
            throw new RuntimeException('encrypt_key must be exactly 32 bytes for AES-256-CBC');
        }

        if ($this->config->timeout <= 0) {
            throw new RuntimeException('timeout must be greater than 0');
        }

        if ($this->config->max_refresh < 0) {
            throw new RuntimeException('max_refresh must be non-negative');
        }

        if ($this->config->max_refresh > $this->config->timeout) {
            throw new RuntimeException('max_refresh must not exceed timeout');
        }
    }

    private function createCacheDriver(): PTokenCacheInterface
    {
        switch ($this->config->cache_mode) {
            case 2:
                return new PTokenRedisDriver($this->config->redis_config);

            case 3:
                $filePath = $this->config->file_cache_path;
                if (empty($filePath)) {
                    $filePath = sys_get_temp_dir() . '/ptokenCache';
                }
                return new PTokenFileDriver($filePath);

            default:
                throw new RuntimeException("Unsupported cache mode: {$this->config->cache_mode}. Valid values: 2 (Redis), 3 (File).");
        }
    }
}
