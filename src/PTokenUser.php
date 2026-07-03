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
    protected readonly string $tokenId;

    protected readonly string $userKey;

    protected readonly mixed $data;

    /**
     * @var array<string>
     */
    protected readonly array $abilities;

    protected readonly int $createAt;

    protected readonly int $expireAt;

    /**
     * @param string        $tokenId   Token 唯一标识
     * @param string        $userKey   用户标识
     * @param mixed         $data      用户关联数据
     * @param array<string> $abilities Token 能力/作用域
     * @param int           $createAt  Token 创建时间（Unix 时间戳）
     * @param int           $expireAt  Token 过期时间（Unix 时间戳）
     */
    public function __construct(
        string $tokenId,
        string $userKey,
        mixed $data,
        array $abilities,
        int $createAt,
        int $expireAt,
    ) {
        $this->tokenId   = $tokenId;
        $this->userKey   = $userKey;
        $this->data      = $data;
        $this->abilities = $abilities;
        $this->createAt  = $createAt;
        $this->expireAt  = $expireAt;
    }

    public function getTokenId(): string
    {
        return $this->tokenId;
    }

    public function getUserKey(): string
    {
        return $this->userKey;
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
        return $this->createAt;
    }

    public function getExpireAt(): int
    {
        return $this->expireAt;
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
        return time() > $this->expireAt;
    }

    /**
     * 获取剩余有效时间（秒），已过期返回 0。
     */
    public function getRemainingTtl(): int
    {
        $remaining = $this->expireAt - time();
        return max(0, $remaining);
    }

    // ───── ArrayAccess 实现 ─────

    public function offsetExists(mixed $offset): bool
    {
        return in_array($offset, ['tokenId', 'userKey', 'data', 'abilities', 'createAt', 'expireAt'], true);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return match ($offset) {
            'tokenId'   => $this->tokenId,
            'userKey'   => $this->userKey,
            'data'      => $this->data,
            'abilities' => $this->abilities,
            'createAt'  => $this->createAt,
            'expireAt'  => $this->expireAt,
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
            'tokenId'   => $this->tokenId,
            'userKey'   => $this->userKey,
            'data'      => $this->data,
            'abilities' => $this->abilities,
            'createAt'  => $this->createAt,
            'expireAt'  => $this->expireAt,
        ];
    }
}
