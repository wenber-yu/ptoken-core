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
     * Generate a new access token for the given user key.
     *
     * Each token has a unique ID (jti), so multiple tokens for the same user
     * are independent of each other.
     *
     * @param string        $userKey   Unique identifier for the user (e.g., user ID).
     * @param array<string> $abilities Token abilities (scopes). Use ['*'] for all abilities.
     * @param mixed         $data      Custom data to associate with the token.
     * @param array{ip?: string, user_agent?: string, device_name?: string}|null $device Device info, only used when config record_device is true.
     * @return array{token: string}
     * @throws RuntimeException on encryption or cache failure.
     */
    public function generate(string $userKey, array $abilities = ['*'], mixed $data = [], ?array $device = null): array
    {
        $encryptedUserKey = $this->encryptUserKey($userKey);
        $token_id = $this->generateTokenId();

        if (!$this->config->multi_login) {
            $this->destroyAllByUserKey($userKey);
        }

        $now = time();
        $cacheKey = $this->buildCacheKey($encryptedUserKey, $token_id);

        $cacheData = [
            'token_id'   => $token_id,
            'user_key'   => $userKey,
            'data'       => $data,
            'abilities'  => $abilities,
            'token_type' => $this->config->token_type,
            'iat'        => $now,
            'nbf'        => $now,
            'create_at'  => $now,
            'expire_at'  => $now + $this->config->timeout,
        ];

        if ($this->config->issuer !== '') {
            $cacheData['iss'] = $this->config->issuer;
        }

        if ($this->config->audience !== '') {
            $cacheData['aud'] = $this->config->audience;
        }

        // Record device info
        if ($this->config->record_device && $device !== null) {
            $cacheData['device'] = [
                'ip'          => $device['ip'] ?? '',
                'user_agent'  => $device['user_agent'] ?? '',
                'device_name' => $device['device_name'] ?? '',
            ];
        }

        if (!$this->cacheDriver->set($cacheKey, $cacheData, $this->config->timeout)) {
            throw new RuntimeException('Failed to store token data in cache');
        }

        // Register tokenId in the user's token index
        $this->addToUserTokenIndex($encryptedUserKey, $token_id);

        $token = $this->buildToken($encryptedUserKey, $token_id);

        return [
            'token' => $token,
        ];
    }

    /**
     * Get token data.
     *
     * When auto-refresh triggers (max_refresh > 0 and remaining lifetime < max_refresh),
     * the token is rotated: a new token string is generated and returned via 'new_token'.
     *
     * @param string $token Token string.
     * @return array{token_id: string, user_key: string, data: mixed, abilities: array<string>, token_type: string, iat: int, nbf: int, create_at: int, expire_at: int, new_token?: string}|null
     */
    public function get(string $token): ?array
    {
        $parsed = $this->parseToken($token);
        if ($parsed === null) {
            return null;
        }

        [$version, $encryptedUserKey, $token_id] = $parsed;

        $cacheKey = $this->buildCacheKey($encryptedUserKey, $token_id);
        $cacheData = $this->cacheDriver->get($cacheKey);

        if (!is_array($cacheData) || !isset($cacheData['user_key'], $cacheData['expire_at'])) {
            return null;
        }

        if (time() > $cacheData['expire_at']) {
            $this->cacheDriver->delete($cacheKey);
            $this->removeFromUserTokenIndex($encryptedUserKey, $token_id);
            return null;
        }

        // Auto-refresh: rotate token if remaining lifetime < max_refresh
        if ($this->config->max_refresh > 0) {
            $remaining = $cacheData['expire_at'] - time();
            if ($remaining < $this->config->max_refresh) {
                $old_token_id = $cacheData['token_id'];

                // Delete old token from cache
                $this->cacheDriver->delete($cacheKey);
                $this->removeFromUserTokenIndex($encryptedUserKey, $token_id);

                // Generate new tokenId, keep all other data
                $new_token_id = $this->generateTokenId();
                $newCacheKey = $this->buildCacheKey($encryptedUserKey, $new_token_id);
                $now = time();

                $cacheData['token_id']  = $new_token_id;
                $cacheData['iat']      = $now;
                $cacheData['nbf']      = $now;
                $cacheData['expire_at'] = $now + $this->config->timeout;

                $this->cacheDriver->set($newCacheKey, $cacheData, $this->config->timeout);
                $this->addToUserTokenIndex($encryptedUserKey, $new_token_id);

                $cacheData['new_token'] = $this->buildToken($encryptedUserKey, $new_token_id);
            }
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

        [$version, $encryptedUserKey, $token_id] = $parsed;

        $cacheKey = $this->buildCacheKey($encryptedUserKey, $token_id);

        $this->removeFromUserTokenIndex($encryptedUserKey, $token_id);
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
     * Get all active tokens with full detail for a user.
     *
     * Useful for admin panels to view multi-device login records.
     * Automatically filters out expired tokens.
     *
     * @param string $userKey The user identifier.
     * @return array<int, array{token_id: string, user_key: string, data: mixed, abilities: array<string>, create_at: int, expire_at: int}>
     */
    public function getTokensDetail(string $userKey): array
    {
        $encryptedUserKey = $this->encryptUserKey($userKey);
        $tokenIds = $this->getUserTokenIds($encryptedUserKey);

        $result = [];
        $now = time();
        $expiredTokenIds = [];

        foreach ($tokenIds as $tokenId) {
            $cacheKey = $this->buildCacheKey($encryptedUserKey, $tokenId);
            $cacheData = $this->cacheDriver->get($cacheKey);

            if (!is_array($cacheData) || !isset($cacheData['user_key'], $cacheData['expire_at'])) {
                $expiredTokenIds[] = $tokenId;
                continue;
            }

            if ($now > $cacheData['expire_at']) {
                $this->cacheDriver->delete($cacheKey);
                $expiredTokenIds[] = $tokenId;
                continue;
            }

            $result[] = $cacheData;
        }

        // Clean up expired entries from index
        foreach ($expiredTokenIds as $tokenId) {
            $this->removeFromUserTokenIndex($encryptedUserKey, $tokenId);
        }

        return $result;
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
        return $this->config->token_version
            . $this->config->token_delimiter
            . $encryptedUserKey
            . $this->config->token_delimiter
            . $tokenId;
    }

    /**
     * Parse token into [version, encryptedUserKey, tokenId] or null.
     *
     * Token format: v1.{encryptedUserKey}.{tokenId}
     *
     * @return array{string, string, string}|null
     */
    private function parseToken(string $token): ?array
    {
        $delimiter = $this->config->token_delimiter;
        $parts = explode($delimiter, $token, 4);

        if (count($parts) === 3 && !empty($parts[0]) && !empty($parts[1]) && !empty($parts[2])) {
            return [$parts[0], $parts[1], $parts[2]];
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
            throw new RuntimeException('max_refresh must be >= 0');
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
