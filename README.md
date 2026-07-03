# PToken Core

纯 PHP Token 管理核心库，零框架依赖。提供Token生成、验证、销毁、刷新、Refresh Token、能力（abilities）控制及多端登录控制等基础能力，内置两种缓存驱动，可作为独立库使用，也可作为 [wenber-yu/ptoken-laravel](https://github.com/wenber-yu/ptoken-laravel) 和 [wenber-yu/ptoken-hyperf](https://github.com/wenber-yu/ptoken-hyperf) 的底层依赖。

## 环境要求

- PHP >= 8.3
- ext-openssl（AES-256-CBC 加密）
- ext-redis（可选，使用 Redis 缓存驱动时需要）

## 安装

```bash
composer require wenber-yu/ptoken-core
```

## 两种内置缓存驱动

| 驱动 | cache_mode | 说明 |
| --- | --- | --- |
| **File** | `3` | 文件缓存，无需额外扩展，适合单机简单场景，默认驱动 |
| **Redis** | `2` | 基于 Redis，适合生产环境、分布式部署 |

## 基础用法（Standalone 模式）

```php
use Wenbo\PToken\PToken;

// 使用默认配置（File 驱动，不允许多端登录）
$ptoken = new PToken();

// 或传入自定义配置数组
$ptoken = new PToken([
    'cache_mode'   => 2,           // 使用 Redis 驱动
    'timeout'      => 7200,        // Access Token 有效期 2 小时
    'refresh_token_ttl' => 2592000, // Refresh Token 有效期 30 天
    'multi_login'  => true,        // 允许多端登录
    'encrypt_key'  => 'your-32-bytes-encryption-key!', // 32字节密钥
    'redis_config' => [
        'host'     => '127.0.0.1',
        'port'     => 6379,
        'password' => '',
        'database' => 0,
    ],
]);

// ── 登录：生成 Token 对（access token + refresh token） ──
$result = $ptoken->generate('user_123', ['read', 'write'], ['role' => 'admin', 'name' => '张三']);
$token = $result['token'];                // Access Token
$refreshToken = $result['refreshToken'];  // Refresh Token
echo "Token: {$token}\n";

// ── 认证：获取 Token 数据（自动续期） ──
$tokenData = $ptoken->get($token);
if ($tokenData !== null) {
    echo "用户: {$tokenData['userKey']}\n";
    echo "角色: {$tokenData['data']['role']}\n";
    echo "能力: " . implode(', ', $tokenData['abilities']) . "\n";
    echo "过期时间: " . date('Y-m-d H:i:s', $tokenData['expireAt']) . "\n";
}

// ── 能力检查 ──
if ($ptoken->tokenCan($token, 'write')) {
    // 允许写操作
}
$ptoken->authorizeAbilities($token, ['read', 'write']); // 不满足则抛 PTokenForbiddenException

// ── 使用 Refresh Token 换取新 Token 对 ──
$newResult = $ptoken->refreshToken($refreshToken);
if ($newResult !== null) {
    $newToken = $newResult['token'];
    $newRefreshToken = $newResult['refreshToken'];
    // 旧 token 和旧 refreshToken 均已失效
}

// ── 手动刷新 ──
$ptoken->refresh($token);

// ── 登出：销毁 Token（同时销毁关联的 Refresh Token） ──
$ptoken->destroy($token);

// ── 销毁用户所有 Token ──
$ptoken->destroyAll('user_123');

// ── 查看用户所有活跃 Token ID ──
$tokenIds = $ptoken->getTokens('user_123');
```

## API 参考

| 方法 | 签名 | 说明 |
| --- | --- | --- |
| `generate` | `generate(string $userKey, array $abilities = ['*'], mixed $data = []): array` | 为用户生成新 Token 对。返回 `['token' => string, 'refreshToken' => string]`。每个 Token 有唯一 ID（jti），`multi_login=false` 时自动销毁该用户旧 Token |
| `get` | `get(string $token): ?array` | 校验Token并返回缓存数据。在续期窗口内自动刷新过期时间。过期/无效返回 `null` |
| `refreshToken` | `refreshToken(string $refreshToken): ?array` | 用 Refresh Token 换取新的 Token 对。旧 access token 和 refresh token 均被销毁，返回新的 `['token' => string, 'refreshToken' => string]` |
| `destroy` | `destroy(string $token): bool` | 销毁单个 Token 及其关联的 Refresh Token |
| `destroyAll` | `destroyAll(string $userKey): bool` | 销毁某用户的所有 Token 及关联的 Refresh Token |
| `getTokens` | `getTokens(string $userKey): array` | 获取某用户所有活跃 Token ID |
| `refresh` | `refresh(string $token): ?string` | 手动刷新Token过期时间，成功返回Token本身，失败返回 `null` |
| `tokenCan` | `tokenCan(string $token, string $ability): bool` | 检查 Token 是否拥有指定能力 |
| `tokenCanAny` | `tokenCanAny(string $token, array $abilities): bool` | 检查 Token 是否拥有至少一项能力 |
| `tokenCanAll` | `tokenCanAll(string $token, array $abilities): bool` | 检查 Token 是否拥有全部能力 |
| `authorizeAbilities` | `authorizeAbilities(string $token, array $abilities, bool $requireAll = true): void` | 检查能力，不满足则抛出 `PTokenForbiddenException` |
| `getConfig` | `getConfig(): Config` | 获取当前配置对象 |

### `generate()` 返回值结构

```php
[
    'token'        => 'v1.xxx.yyy',    // Access Token 字符串
    'refreshToken' => 'zzz...',        // Refresh Token 字符串
]
```

### `get()` 返回值结构

```php
[
    'tokenId'   => 'abc123...',          // Token 唯一标识
    'userKey'   => 'user_123',           // 用户唯一标识
    'data'      => ['role' => 'admin'],  // 登录时关联的自定义数据
    'abilities' => ['read', 'write'],    // Token 能力/作用域
    'createAt'  => 1719500000,           // Token 创建时间（Unix 时间戳）
    'expireAt'  => 1719579200,           // Token 过期时间（Unix 时间戳）
]
```

## Token 能力（Abilities）

每个 Token 可附带能力列表，用于实现细粒度 API 权限控制：

```php
// 生成带能力的 Token
$result1 = $ptoken->generate('user_1', ['read']);
$result2 = $ptoken->generate('user_1', ['read', 'write', 'delete']);
$result3 = $ptoken->generate('admin_1', ['*']); // * 表示所有能力

$readOnlyToken = $result1['token'];

// 检查能力
$ptoken->tokenCan($readOnlyToken, 'read');   // true
$ptoken->tokenCan($readOnlyToken, 'write');  // false

// 授权检查（不满足抛 PTokenForbiddenException，HTTP 403）
$ptoken->authorizeAbilities($token, ['write']);
$ptoken->authorizeAbilities($token, ['read', 'write'], requireAll: false); // 任意一项
```

在框架中间件层，认证通过后 `PTokenUser` 对象也支持 `tokenCan()` / `tokenCant()` 方法。

## Refresh Token 机制

每个 Access Token 生成时，自动附带一个 Refresh Token。Refresh Token 的特点：

- **长有效期**：默认 30 天（`refresh_token_ttl` 配置），远长于 Access Token
- **一次性使用**：调用 `refreshToken()` 后，旧的 refresh token 和 access token 均被销毁
- **关联销毁**：调用 `destroy()` 或 `destroyAll()` 时，对应的 refresh token 也会被清理
- **级联失效**：如果关联的 access token 已过期，refresh token 也会失效

```php
// 用 Refresh Token 换新 Token 对
$newResult = $ptoken->refreshToken($oldRefreshToken);
// 返回 ['token' => '...', 'refreshToken' => '...'] 或 null
```

## TokenUser 使用方法

`PTokenUser` 是一个通用的 Token 用户对象，实现 `ArrayAccess` 和 `JsonSerializable`，支持类数组访问和 JSON 序列化。

```php
use Wenbo\PToken\PTokenUser;

$tokenUser = new PTokenUser(
    $tokenData['tokenId'],
    $tokenData['userKey'],
    $tokenData['data'],
    $tokenData['abilities'],
    $tokenData['createAt'],
    $tokenData['expireAt'],
);

// 属性访问
echo $tokenUser->getUserKey();       // user_123
echo $tokenUser->getTokenId();       // 唯一 Token ID
echo $tokenUser->getData()['role'];  // admin
print_r($tokenUser->getAbilities()); // ['read', 'write']

// 能力检查
$tokenUser->tokenCan('read');    // true
$tokenUser->tokenCant('delete'); // true

// 数组式访问
echo $tokenUser['userKey'];
echo $tokenUser['abilities'][0]; // 'read'

// 状态判断
if ($tokenUser->isExpired()) { /* ... */ }
echo $tokenUser->getRemainingTtl(); // 剩余有效秒数

// JSON 序列化
echo json_encode($tokenUser);
```

## Token 格式说明

Access Token 字符串格式：`v1.{encryptedUserKey}.{tokenId}`

- `v1`：Token 格式版本号，未来格式变更时递增，确保向后兼容
- `encryptedUserKey`：对 `userKey` 进行 AES-256-CBC 加密后做 URL 安全的 Base64 编码
- `delimiter`：分隔符，默认 `.`，可通过 `token_delimiter` 配置
- `tokenId`：16 字节随机字符串，URL 安全 Base64 编码，作为 Token 唯一标识（jti）

Refresh Token 是独立的随机字符串（32 字节 Base64URL 编码），不包含用户信息。

## 自动续期机制

调用 `get()` 校验 Token 时，若当前处于续期窗口内（`剩余TTL <= timeout - maxRefresh`），系统会自动调用 `refresh()` 延长 Token 有效期，对客户端完全透明。

示例：`timeout=7200, max_refresh=3600`，当 Token 剩余有效时间 ≤ 3600 秒时，自动续期为 7200 秒。

## 多端登录控制

通过 `multi_login` 配置项控制：

- `false`（默认）：新登录自动销毁该用户所有旧 Token（`destroyAll`）
- `true`：同一 `userKey` 允许多个 Token 同时有效，每个 Token 有独立 ID

## 完整配置参考

| 配置项 | 类型 | 默认值 | 说明 |
| --- | --- | --- | --- |
| `cache_mode` | `int` | `3` | 缓存模式：`2`=Redis, `3`=File（仅 standalone 模式生效） |
| `cache_pre_key` | `string` | `'ptoken:'` | 缓存键前缀 |
| `timeout` | `int` | `604800` | Access Token 有效期（秒），默认 7 天 |
| `max_refresh` | `int` | `86400` | 最大续期窗口（秒），默认 1 天 |
| `token_delimiter` | `string` | `'.'` | Token 字符串分隔符 |
| `token_version` | `string` | `'v1'` | Token 格式版本号，嵌入 token 前缀 |
| `encrypt_key` | `string` | *(32 字节默认值)* | AES-256-CBC 加密密钥，**必须恰好 32 字节** |
| `multi_login` | `bool` | `false` | 是否允许多端登录 |
| `refresh_token_ttl` | `int` | `2592000` | Refresh Token 有效期（秒），默认 30 天 |
| `user_model` | `?string` | `null` | User Model 类名（FQCN），供框架集成包使用 |
| `auth_exclude_paths` | `array` | `[]` | 认证排除路径，供框架集成包使用 |
| `redis_config` | `array` | `['host'=>'127.0.0.1',...]` | Redis 连接配置（cache_mode=2 时使用） |
| `file_cache_path` | `string` | `''` | 文件缓存目录（cache_mode=3 时使用，留空使用系统临时目录） |

## 框架集成包

PToken Core 提供零框架依赖的独立使用方式，同时也为以下框架提供了开箱即用的集成包：

| 包名 | 说明 |
| --- | --- |
| [wenber-yu/ptoken-laravel](https://github.com/wenber-yu/ptoken-laravel) | Laravel 集成包，支持中间件认证、User Model 自动关联 |
| [wenber-yu/ptoken-hyperf](https://github.com/wenber-yu/ptoken-hyperf) | Hyperf 集成包，支持中间件认证、`#[PTokenAuth(exclude: true)]` 排除标记、User Model 自动关联 |

框架集成包提供了：
- 自动配置注入（`ConfigProvider` / `ServiceProvider`）
- 简单路由中间件即可完成认证拦截
- 认证失败自动抛出 `PTokenAuthException`，配合框架异常处理器统一处理
- 生成加密密钥的命令行工具
- 与框架 Cache 系统的无缝集成

> 如果使用 standalone 模式，直接使用本核心包即可，无需安装框架集成包。

## 安全建议

1. **生产环境务必更换 `encrypt_key`**：使用 32 字节高熵随机字符串，切勿使用默认值
2. **通过环境变量注入密钥**：避免硬编码在配置文件中
3. **启用 HTTPS**：防止 Token 在网络传输中被窃取
4. **合理设置 `timeout`**：建议 Access Token 短一些（如 2 小时），靠 Refresh Token 续期
5. **合理设置 `refresh_token_ttl`**：Refresh Token 不应无限有效
6. **谨慎开启 `multi_login`**：多端登录会增加 Token 泄露风险面

## 许可证

[MIT](LICENSE)
