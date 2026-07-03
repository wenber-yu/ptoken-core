<?php

declare(strict_types=1);

namespace Wenbo\PToken;

use ArrayAccess;
use JsonSerializable;

/**
 * 通用 TokenUser 对象，用于承载从 Token 解析的用户信息。
 *
 * 实现 ArrayAccess 和 JsonSerializable，支持类数组访问和 JSON 序列化。
 */
class PTokenUser implements ArrayAccess, JsonSerializable
{
    protected readonly string $token_id;

    protected readonly string $user_key;

    protected readonly mixed $data;

    /**
     * @var array<string>
     */
    protected readonly array $abilities;

    protected readonly int $create_at;

    protected readonly int $expire_at;

    /**
     * @var array{ip: string, user_agent: string, device_name: string}|null
     */
    protected readonly ?array $device;

    /**
     * @param string        $token_id   Token 唯一标识
     * @param string        $user_key   用户标识
     * @param mixed         $data       用户关联数据
     * @param array<string> $abilities  Token 能力/作用域
     * @param int           $create_at  Token 创建时间（Unix 时间戳）
     * @param int           $expire_at  Token 过期时间（Unix 时间戳）
     * @param array{ip: string, user_agent: string, device_name: string}|null $device 设备信息
     */
    public function __construct(
        string $token_id,
        string $user_key,
        mixed $data,
        array $abilities,
        int $create_at,
        int $expire_at,
        ?array $device = null,
    ) {
        $this->token_id  = $token_id;
        $this->user_key  = $user_key;
        $this->data      = $data;
        $this->abilities = $abilities;
        $this->create_at = $create_at;
        $this->expire_at = $expire_at;
        $this->device    = $device;
    }

    public function getTokenId(): string
    {
        return $this->token_id;
    }

    public function getUserKey(): string
    {
        return $this->user_key;
    }

    public function getData(): mixed
    {
        return $this->data;
    }

    /**
     * @return array<string>
     */
    public function getAbilities(): array
    {
        return $this->abilities;
    }

    public function getCreateAt(): int
    {
        return $this->create_at;
    }

    public function getExpireAt(): int
    {
        return $this->expire_at;
    }

    /**
     * @return array{ip: string, user_agent: string, device_name: string}|null
     */
    public function getDevice(): ?array
    {
        return $this->device;
    }

    /**
     * Check if the token has a specific ability.
     */
    public function tokenCan(string $ability): bool
    {
        if (in_array('*', $this->abilities, true)) {
            return true;
        }

        return in_array($ability, $this->abilities, true);
    }

    /**
     * Check if the token does NOT have a specific ability.
     */
    public function tokenCant(string $ability): bool
    {
        return !$this->tokenCan($ability);
    }

    /**
     * 检查 Token 是否已过期。
     */
    public function isExpired(): bool
    {
        return time() > $this->expire_at;
    }

    /**
     * 获取剩余有效时间（秒），已过期返回 0。
     */
    public function getRemainingTtl(): int
    {
        $remaining = $this->expire_at - time();
        return max(0, $remaining);
    }

    // ───── ArrayAccess 实现 ─────

    public function offsetExists(mixed $offset): bool
    {
        return in_array($offset, ['token_id', 'user_key', 'data', 'abilities', 'create_at', 'expire_at', 'device'], true);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return match ($offset) {
            'token_id'  => $this->token_id,
            'user_key'  => $this->user_key,
            'data'      => $this->data,
            'abilities' => $this->abilities,
            'create_at' => $this->create_at,
            'expire_at' => $this->expire_at,
            'device'    => $this->device,
            default     => null,
        };
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        // 只读对象，禁止设置
    }

    public function offsetUnset(mixed $offset): void
    {
        // 只读对象，禁止删除
    }

    // ───── JsonSerializable 实现 ─────

    public function jsonSerialize(): array
    {
        return [
            'token_id'  => $this->token_id,
            'user_key'  => $this->user_key,
            'data'      => $this->data,
            'abilities' => $this->abilities,
            'create_at' => $this->create_at,
            'expire_at' => $this->expire_at,
            'device'    => $this->device,
        ];
    }
}
