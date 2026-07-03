<?php

declare(strict_types=1);

namespace Wenbo\PToken;

class PTokenConfig
{
    /**
     * 缓存模式：2=Redis、3=File。
     * 仅在不传入外部 CacheDriver（standalone 模式）时使用。
     */
    public int $cache_mode = 3;

    /**
     * 缓存键前缀。
     */
    public string $cache_pre_key = 'ptoken:';

    /**
     * Token 过期时间（秒）。默认 7 天。
     */
    public int $timeout = 604800;

    /**
     * 最大续期窗口（秒）。当剩余 TTL <= (timeout - max_refresh) 时，
     * 认证过程中自动续期 Token。
     */
    public int $max_refresh = 86400;

    /**
     * Token 字符串分隔符，格式：{encryptedUserKey}_{randomStr}。
     */
    public string $token_delimiter = '_';

    /**
     * AES-256-CBC 加密密钥，必须恰好 32 字节。
     * 前 16 字节用作 IV。
     */
    public string $encrypt_key = '12345678901234567890123456789012';

    /**
     * 是否允许同一 userKey 多端同时登录。
     * false = 每个 userKey 仅一个有效 Token（后登录者覆盖前者）。
     */
    public bool $multi_login = false;

    /**
     * User Model 类名（FQCN）。设置后中间件将通过懒加载自动将
     * 已认证用户与对应 Model 实例关联（Laravel 走 Eloquent，Hyperf 走 Container）。
     *
     * 示例：'App\Models\User' 或 \App\Models\User::class
     *
     * null = 不自动关联 User Model。
     */
    public ?string $user_model = null;

    /**
     * 认证中间件排除路径。
     *
     * @var array<string>
     */
    public array $auth_exclude_paths = [];

    /**
     * Redis 连接参数（host、port、password、database）。
     * 仅在 cache_mode == 2（standalone 模式传入 Redis 驱动）时使用。
     *
     * @var array{host: string, port: int, password: string, database: int}
     */
    public array $redis_config = [
        'host'     => '127.0.0.1',
        'port'     => 6379,
        'password' => '',
        'database' => 0,
    ];

    /**
     * 文件缓存目录路径。仅在 cache_mode == 3（standalone 模式传入 File 驱动）时使用。
     * 留空则使用系统临时目录。
     */
    public string $file_cache_path = '';

    /**
     * 从数组创建配置对象。
     *
     * @param array<string, mixed> $config
     * @return static
     */
    public static function fromArray(array $config): self
    {
        $instance = new static();

        foreach ($config as $key => $value) {
            if (property_exists($instance, $key)) {
                $instance->{$key} = $value;
            }
        }

        return $instance;
    }
}
