<?php

declare(strict_types=1);

namespace Wenbo\PToken;

use Wenbo\PToken\CacheDrivers\PTokenCacheInterface;
use Wenbo\PToken\CacheDrivers\PTokenFileDriver;
use Wenbo\PToken\CacheDrivers\PTokenRedisDriver;
use Wenbo\PToken\Exceptions\PTokenForbiddenException;
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
     * Generate a new token for the given user key.
     *
     * Each token has a unique ID (jti), so multiple tokens for the same user
     * are independent of each other.
     *
     * @param string        $userKey   Unique identifier for the user (e.g., user ID).
     * @param mixed         $data      Custom data to associate with the token.
     * @param array<string> $abilities Token abilities (scopes). Use ['*'] for all abilities.
     * @return string The generated token string.
     * @throws RuntimeException on encryption or cache failure.
     */
    public function generate(string $userKey, mixed $data, array $abilities = ['*']): string
    {
        $encryptedUserKey = $this->encryptUserKey($userKey);
        $tokenId = $this->generateTokenId();

        if (!$this->config->multi_login) {
            $this->destroyAllByUserKey($userKey);
        }

        $now = time();
        $cacheKey = $this->buildCacheKey($encryptedUserKey, $tokenId);

        $cacheData = [
            'tokenId'   => $tokenId,
            'userKey'   => $userKey,
            'data'      => $data,
            'abilities' => $abilities,
            'createAt'  => $now,
            'expireAt'  => $now + $this->config->timeout,
        ];

        if (!$this->cacheDriver->set($cacheKey, $cacheData, $this->config->timeout)) {
            throw new RuntimeException('Failed to store token data in cache');
        }

        // Register tokenId in the user's token index
        $this->addToUserTokenIndex($encryptedUserKey, $tokenId);

        return $this->buildToken($encryptedUserKey, $tokenId);
    }

    /**
     * Get token data. Automatically refreshes if within the refresh window.
     *
     * @param string $token Token string.
     * @return array{tokenId: string, userKey: string, data: mixed, abilities: array<string>, createAt: int, expireAt: int}|null
     */
    public function get(string $token): ?array
    {
        $parsed = $this->parseToken($token);
        if ($parsed === null) {
            return null;
        }

        [$encryptedUserKey, $tokenId] = $parsed;

        $cacheKey = $this->buildCacheKey($encryptedUserKey, $tokenId);
        $cacheData = $this->cacheDriver->get($cacheKey);

        if (!is_array($cacheData) || !isset($cacheData['userKey'], $cacheData['expireAt'])) {
            return null;
        }

        if (time() > $cacheData['expireAt']) {
            $this->cacheDriver->delete($cacheKey);
            $this->removeFromUserTokenIndex($encryptedUserKey, $tokenId);
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
     * Check if a token has a specific ability.
     *
     * @param string $token   The token string.
     * @param string $ability The ability to check.
     * @return bool
     */
    public function tokenCan(string $token, string $ability): bool
    {
        $tokenData = $this->get($token);
        if ($tokenData === null) {
            return false;
        }

        $abilities = $tokenData['abilities'] ?? [];

        if (in_array('*', $abilities, true)) {
            return true;
        }

        return in_array($ability, $abilities, true);
    }

    /**
     * Check if a token has at least one of the given abilities.
     *
     * @param string          $token     The token string.
     * @param array<string>   $abilities The abilities to check.
     * @return bool
     */
    public function tokenCanAny(string $token, array $abilities): bool
    {
        foreach ($abilities as $ability) {
            if ($this->tokenCan($token, $ability)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a token has all of the given abilities.
     *
     * @param string          $token     The token string.
     * @param array<string>   $abilities The abilities to check.
     * @return bool
     */
    public function tokenCanAll(string $token, array $abilities): bool
    {
        foreach ($abilities as $ability) {
            if (!$this->tokenCan($token, $ability)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check token abilities and throw an exception on failure.
     *
     * @param string        $token      The token string.
     * @param array<string> $abilities  Required abilities.
     * @param bool          $requireAll If true, all abilities are required; if false, at least one.
     * @throws PTokenForbiddenException
     */
    public function authorizeAbilities(string $token, array $abilities, bool $requireAll = true): void
    {
        $passed = $requireAll
            ? $this->tokenCanAll($token, $abilities)
            : $this->tokenCanAny($token, $abilities);

        if (!$passed) {
            $condition = $requireAll ? 'all' : 'any';
            throw new PTokenForbiddenException(
                "Token lacks required abilities. Required ({$condition}): " . implode(', ', $abilities)
            );
        }
    }

    /**
     * Destroy a single token.
     *
     * @param string $token The token string.
     * @return bool
     */
    public function destroy(string $token): bool
    {
        $parsed = $this->parseToken($token);
        if ($parsed === null) {
            return false;
        }

        [$encryptedUserKey, $tokenId] = $parsed;

        $cacheKey = $this->buildCacheKey($encryptedUserKey, $tokenId);
        $this->removeFromUserTokenIndex($encryptedUserKey, $tokenId);

        return $this->cacheDriver->delete($cacheKey);
    }

    /**
     * Destroy all tokens belonging to a user.
     *
     * @param string $userKey The user identifier.
     * @return bool
     */
    public function destroyAll(string $userKey): bool
    {
        $encryptedUserKey = $this->encryptUserKey($userKey);
        $tokenIds = $this->getUserTokenIds($encryptedUserKey);

        foreach ($tokenIds as $tokenId) {
            $cacheKey = $this->buildCacheKey($encryptedUserKey, $tokenId);
            $this->cacheDriver->delete($cacheKey);
        }

        $indexKey = $this->buildUserTokenIndexKey($encryptedUserKey);
        $this->cacheDriver->delete($indexKey);

        return true;
    }

    /**
     * Get all active token IDs for a user.
     *
     * @param string $userKey The user identifier.
     * @return array<string>
     */
    public function getTokens(string $userKey): array
    {
        $encryptedUserKey = $this->encryptUserKey($userKey);
        return $this->getUserTokenIds($encryptedUserKey);
    }

    /**
     * Manually refresh a token's expiration time.
     *
     * @param string $token The token string.
     * @return string|null Returns the token string on success, null on failure.
     */
    public function refresh(string $token): ?string
    {
        $parsed = $this->parseToken($token);
        if ($parsed === null) {
            return null;
        }

        [$encryptedUserKey, $tokenId] = $parsed;
        $cacheKey = $this->buildCacheKey($encryptedUserKey, $tokenId);
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
            $this->removeFromUserTokenIndex($encryptedUserKey, $tokenId);
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

    // ───── Private helpers ─────

    private function destroyAllByUserKey(string $userKey): void
    {
        $this->destroyAll($userKey);
    }

    private function generateTokenId(): string
    {
        return $this->base64UrlEncode(random_bytes(16));
    }

    private function buildToken(string $encryptedUserKey, string $tokenId): string
    {
        return $encryptedUserKey
            . $this->config->token_delimiter
            . $tokenId;
    }

    /**
     * Parse token into [encryptedUserKey, tokenId] or null.
     *
     * @return array{string, string}|null
     */
    private function parseToken(string $token): ?array
    {
        $delimiter = $this->config->token_delimiter;
        $parts = explode($delimiter, $token, 3);

        // Support legacy tokens: {encryptedUserKey}_{randomStr}
        if (count($parts) === 2 && !empty($parts[0]) && !empty($parts[1])) {
            return [$parts[0], $parts[1]];
        }

        if (count($parts) === 3 && !empty($parts[0]) && !empty($parts[1])) {
            return [$parts[0], $parts[1]];
        }

        return null;
    }

    private function buildCacheKey(string $encryptedUserKey, string $tokenId): string
    {
        return $this->config->cache_pre_key . $encryptedUserKey . $this->config->token_delimiter . $tokenId;
    }

    private function buildUserTokenIndexKey(string $encryptedUserKey): string
    {
        return $this->config->cache_pre_key . $encryptedUserKey . ':tokens';
    }

    /**
     * Get all token IDs for a user from the index.
     *
     * @return array<string>
     */
    private function getUserTokenIds(string $encryptedUserKey): array
    {
        $indexKey = $this->buildUserTokenIndexKey($encryptedUserKey);
        $data = $this->cacheDriver->get($indexKey);

        if (!is_array($data)) {
            return [];
        }

        return $data;
    }

    private function addToUserTokenIndex(string $encryptedUserKey, string $tokenId): void
    {
        $indexKey = $this->buildUserTokenIndexKey($encryptedUserKey);
        $tokenIds = $this->getUserTokenIds($encryptedUserKey);

        if (!in_array($tokenId, $tokenIds, true)) {
            $tokenIds[] = $tokenId;
        }

        $this->cacheDriver->set($indexKey, $tokenIds, $this->config->timeout);
    }

    private function removeFromUserTokenIndex(string $encryptedUserKey, string $tokenId): void
    {
        $indexKey = $this->buildUserTokenIndexKey($encryptedUserKey);
        $tokenIds = $this->getUserTokenIds($encryptedUserKey);

        $filtered = array_values(array_filter($tokenIds, fn (string $id) => $id !== $tokenId));

        if (empty($filtered)) {
            $this->cacheDriver->delete($indexKey);
        } else {
            $this->cacheDriver->set($indexKey, $filtered, $this->config->timeout);
        }
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
