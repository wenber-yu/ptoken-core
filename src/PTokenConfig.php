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
     * Token 字符串分隔符，格式：v1.{encryptedUserKey}.{tokenId}。
     * 使用 '.' 因为它不会出现在 Base64URL 编码中。
     */
    public string $token_delimiter = '.';

    /**
     * AES-256-CBC 加密密钥，必须恰好 32 字节。
     * 前 16 字节用作 IV。
     */
    public string $encrypt_key = '12345678901234567890123456789012';

    /**
     * 是否允许同一 userKey 多端同时登录。
     * false = 新登录时自动销毁该 userKey 的所有旧 Token。
     * true  = 允许多个 Token 同时有效。
     */
    public bool $multi_login = false;

    /**
     * Token 格式版本号，嵌入 token 字符串中。
     * 如 'v1'，未来格式变更时递增。
     */
    public string $token_version = 'v1';

    /**
     * 最大自动续期时间（秒）。
     *
     * 当 Token 剩余有效期不足此值时，get() 会自动续期并轮换 token。
     * 设为 0 可禁用自动续期。
     */
    public int $max_refresh = 0;

    /**
     * Token 类型标识，写入缓存的 token_type 字段。
     *
     * 示例：'access_token'、'api_key'、'personal_access_token'
     */
    public string $token_type = 'access_token';

    /**
     * Token 签发者（iss），用于标识 token 由谁签发。
     *
     * 示例：'https://api.example.com'
     * 留空则不校验。
     */
    public string $issuer = '';

    /**
     * Token 受众（aud），用于标识 token 的预期接收方。
     *
     * 示例：'https://api.example.com'
     * 留空则不校验。
     */
    public string $audience = '';

    /**
     * 自定义请求头名称，用于从请求中提取 token。
     *
     * 默认 'Authorization'，可改为 'X-Api-Token' 等。
     */
    public string $token_header = 'Authorization';

    /**
     * 响应头名称，自动续期后用于告知前端新 token。
     *
     * 默认 'X-New-Token'。
     */
    public string $new_token_header = 'X-New-Token';

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
     * 设备指纹记录开关。
     *
     * true = 记录客户端 IP、User-Agent 等信息到 token 缓存中。
     * false = 不记录设备信息。
     */
    public bool $record_device = false;

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
