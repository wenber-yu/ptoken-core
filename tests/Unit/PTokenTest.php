<?php

declare(strict_types=1);

use Wenbo\PToken\PToken;
use Wenbo\PToken\CacheDrivers\PTokenFileDriver;
use Wenbo\PToken\Exceptions\PTokenForbiddenException;

// ── Helper: generate and extract token string ──
function gen(PToken $ptoken, string $userKey, array $abilities = ['*'], mixed $data = [], ?array $device = null): string
{
    $result = $ptoken->generate($userKey, $abilities, $data, $device);
    return $result['token'];
}

test('generate() 生成 Token 格式正确', function () {
    $ptoken = new PToken();

    $result = $ptoken->generate('user_123', ['*'], ['role' => 'admin']);
    $token = $result['token'];

    expect($token)->toBeString();
    expect(strlen($token))->toBeGreaterThan(10);
    // Token 格式: v1.{encryptedUserKey}.{tokenId}
    expect($token)->toContain('v1.');
});

test('get() 获取用户数据', function () {
    $ptoken = new PToken();

    $result = $ptoken->generate('user_456', ['*'], ['name' => '张三', 'age' => 25]);
    $token = $result['token'];
    $data = $ptoken->get($token);

    expect($data)->toBeArray();
    expect($data['user_key'])->toBe('user_456');
    expect($data['data'])->toBe(['name' => '张三', 'age' => 25]);
    expect($data['abilities'])->toBe(['*']);
    expect($data['token_id'])->toBeString();
    expect($data['create_at'])->toBeInt();
    expect($data['expire_at'])->toBeInt();
    expect($data['expire_at'])->toBeGreaterThan($data['create_at']);
});

test('generate() 支持自定义 abilities', function () {
    $ptoken = new PToken();

    $result = $ptoken->generate('user_abilities', ['read', 'write']);
    $data = $ptoken->get($result['token']);

    expect($data['abilities'])->toBe(['read', 'write']);
});

test('generate() 默认 abilities 为 [*]', function () {
    $ptoken = new PToken();

    $result = $ptoken->generate('user_default_abilities');
    $data = $ptoken->get($result['token']);

    expect($data['abilities'])->toBe(['*']);
});

test('destroy() 销毁 Token', function () {
    $ptoken = new PToken();

    $result = $ptoken->generate('user_789');
    $token = $result['token'];
    expect($ptoken->get($token))->not->toBeNull();

    $destroyResult = $ptoken->destroy($token);
    expect($destroyResult)->toBeTrue();
    expect($ptoken->get($token))->toBeNull();
});

test('destroyAll() 销毁用户所有 Token', function () {
    $ptoken = new PToken(['multi_login' => true]);

    $token1 = gen($ptoken, 'user_destroy_all', ['*'], ['n' => 1]);
    $token2 = gen($ptoken, 'user_destroy_all', ['*'], ['n' => 2]);

    expect($ptoken->get($token1))->not->toBeNull();
    expect($ptoken->get($token2))->not->toBeNull();

    $ptoken->destroyAll('user_destroy_all');

    expect($ptoken->get($token1))->toBeNull();
    expect($ptoken->get($token2))->toBeNull();
});

test('无效 Token 返回 null', function () {
    $ptoken = new PToken();

    $data = $ptoken->get('invalid_token_string');
    expect($data)->toBeNull();
});

test('过期 Token 返回 null', function () {
    $ptoken = new PToken(['timeout' => 1]);

    $token = gen($ptoken, 'user_expire');

    sleep(2);

    $data = $ptoken->get($token);
    expect($data)->toBeNull();
});

test('多端登录 — multi_login=false 时新登录销毁旧 Token', function () {
    $ptoken = new PToken(['multi_login' => false]);

    $token1 = gen($ptoken, 'user_multi', ['*'], ['session' => 1]);
    $token2 = gen($ptoken, 'user_multi', ['*'], ['session' => 2]);

    // 旧 Token 已失效
    expect($ptoken->get($token1))->toBeNull();
    // 新 Token 有效
    $data2 = $ptoken->get($token2);
    expect($data2['data'])->toBe(['session' => 2]);
});

test('多端登录 — multi_login=true 时多个 Token 同时有效', function () {
    $ptoken = new PToken(['multi_login' => true]);

    $token1 = gen($ptoken, 'user_multi2', ['*'], ['session' => 1]);
    $token2 = gen($ptoken, 'user_multi2', ['*'], ['session' => 2]);

    // 两个 Token 各自独立
    $data1 = $ptoken->get($token1);
    $data2 = $ptoken->get($token2);

    expect($data1['data'])->toBe(['session' => 1]);
    expect($data2['data'])->toBe(['session' => 2]);
});

test('getTokens() 获取用户所有活跃 Token ID', function () {
    $ptoken = new PToken([
        'multi_login'  => true,
        'cache_pre_key' => 'test_tokens:',
    ]);

    gen($ptoken, 'user_list', ['*'], ['n' => 1]);
    gen($ptoken, 'user_list', ['*'], ['n' => 2]);

    $tokens = $ptoken->getTokens('user_list');

    expect(count($tokens))->toBe(2);

    // 清理
    $ptoken->destroyAll('user_list');
});

test('tokenCan() 检查 Token 能力', function () {
    $ptoken = new PToken();

    $token = gen($ptoken, 'user_can', ['read', 'write']);

    expect($ptoken->tokenCan($token, 'read'))->toBeTrue();
    expect($ptoken->tokenCan($token, 'write'))->toBeTrue();
    expect($ptoken->tokenCan($token, 'delete'))->toBeFalse();
});

test('tokenCan() 通配符 * 拥有所有能力', function () {
    $ptoken = new PToken();

    $token = gen($ptoken, 'user_wildcard', ['*']);

    expect($ptoken->tokenCan($token, 'anything'))->toBeTrue();
    expect($ptoken->tokenCan($token, 'server:update'))->toBeTrue();
});

test('tokenCanAny() 检查任意能力', function () {
    $ptoken = new PToken();

    $token = gen($ptoken, 'user_any', ['read', 'write']);

    expect($ptoken->tokenCanAny($token, ['read', 'delete']))->toBeTrue();
    expect($ptoken->tokenCanAny($token, ['delete', 'update']))->toBeFalse();
});

test('tokenCanAll() 检查全部能力', function () {
    $ptoken = new PToken();

    $token = gen($ptoken, 'user_all', ['read', 'write']);

    expect($ptoken->tokenCanAll($token, ['read', 'write']))->toBeTrue();
    expect($ptoken->tokenCanAll($token, ['read', 'delete']))->toBeFalse();
});

test('authorizeAbilities() 通过时无异常', function () {
    $ptoken = new PToken();

    $token = gen($ptoken, 'user_authz', ['read', 'write']);

    $ptoken->authorizeAbilities($token, ['read']);  // 不应抛出异常
    $ptoken->authorizeAbilities($token, ['read'], requireAll: false);

    expect(true)->toBeTrue(); // 到这里就是通过了
});

test('authorizeAbilities() 失败时抛出 PTokenForbiddenException', function () {
    $ptoken = new PToken();

    $token = gen($ptoken, 'user_forbidden', ['read']);

    $ptoken->authorizeAbilities($token, ['delete']);
})->throws(PTokenForbiddenException::class);

test('通过配置数组创建 PToken', function () {
    $ptoken = new PToken([
        'cache_mode'    => 3,
        'cache_pre_key' => 'test:',
        'timeout'       => 7200,
        'encrypt_key'   => 'abcdefghijklmnopqrstuvwxyz123456',
    ]);

    $token = gen($ptoken, 'config_test');
    $data = $ptoken->get($token);

    expect($data['user_key'])->toBe('config_test');
});

test('传入外部 CacheDriver 使用自定义驱动', function () {
    $driver = new PTokenFileDriver(sys_get_temp_dir() . '/ptoken-test');
    $ptoken = new PToken(['timeout' => 3600], $driver);

    $token = gen($ptoken, 'driver_test');
    $data = $ptoken->get($token);

    expect($data['user_key'])->toBe('driver_test');

    // 清理测试缓存
    $ptoken->destroyAll('driver_test');
});

test('不同 userKey 生成不同 Token', function () {
    $ptoken = new PToken();

    $token1 = gen($ptoken, 'user_a');
    $token2 = gen($ptoken, 'user_b');

    expect($token1)->not->toBe($token2);
});

test('同一 userKey 多次生成 token 互不相同', function () {
    $ptoken = new PToken(['multi_login' => true]);

    $token1 = gen($ptoken, 'user_unique');
    $token2 = gen($ptoken, 'user_unique');

    expect($token1)->not->toBe($token2);
});

test('同一 userKey 多次调用 get 返回一致数据', function () {
    $ptoken = new PToken();
    $token = gen($ptoken, 'user_consistent', ['*'], ['x' => 1]);

    $data1 = $ptoken->get($token);
    $data2 = $ptoken->get($token);

    expect($data1)->toBe($data2);
});

// ── Token 格式测试 ──

test('token 格式包含版本号 v1', function () {
    $ptoken = new PToken();

    $result = $ptoken->generate('user_version');
    $token = $result['token'];

    // 格式: v1.{encryptedUserKey}.{tokenId}
    expect($token)->toStartWith('v1.');
    $parts = explode('.', $token);
    expect(count($parts))->toBe(3);
});

test('旧格式 token（无版本号）返回 null', function () {
    $ptoken = new PToken();

    // 旧格式只有两部分，缺少版本号前缀
    $data = $ptoken->get('old_part1.old_part2');
    expect($data)->toBeNull();
});

// ── 自动续期（max_refresh）测试 ──

test('max_refresh=0 时不自动续期', function () {
    $ptoken = new PToken(['timeout' => 10, 'max_refresh' => 0]);

    $token = gen($ptoken, 'user_no_refresh');

    $data = $ptoken->get($token);
    expect($data['expire_at'])->toBe($data['create_at'] + 10);
    expect($data)->not->toHaveKey('new_token');
});

test('max_refresh > 0 时，剩余有效期不足 max_refresh 自动续期并轮换 token', function () {
    // timeout=10, max_refresh=5 → 剩余不足5秒时自动续期
    $ptoken = new PToken(['timeout' => 10, 'max_refresh' => 5]);

    $token = gen($ptoken, 'user_auto_refresh');

    // 第一次获取，expire_at = create_at + 10
    $data1 = $ptoken->get($token);
    expect($data1['expire_at'])->toBe($data1['create_at'] + 10);
    expect($data1)->not->toHaveKey('new_token');

    // 直接修改缓存中的 expire_at 为 2 秒后，模拟剩余不足 5 秒
    $delimiter = '.';
    $parts = explode($delimiter, $token, 4);
    $encryptedUserKey = $parts[1];
    $tokenId = $parts[2];
    $cacheKey = 'ptoken:' . $encryptedUserKey . '.' . $tokenId;

    // 通过反射获取 cacheDriver 并手动修改过期时间
    $driverProp = (new ReflectionClass(PToken::class))->getProperty('cacheDriver');
    $driver = $driverProp->getValue($ptoken);

    $cached = $driver->get($cacheKey);
    $cached['expire_at'] = time() + 2; // 只剩 2 秒，不足 max_refresh=5
    $driver->set($cacheKey, $cached, 10);

    // 再次 get()，应自动续期并返回新 token
    $data2 = $ptoken->get($token);
    expect($data2)->toHaveKey('new_token');
    expect($data2['new_token'])->not->toBe($token);
    expect($data2['expire_at'])->toBeGreaterThan(time() + 5);

    // 旧 token 已被销毁
    expect($ptoken->get($token))->toBeNull();

    // 新 token 有效
    $newTokenData = $ptoken->get($data2['new_token']);
    expect($newTokenData['user_key'])->toBe('user_auto_refresh');
});

test('max_refresh 自动续期后 data 和 abilities 保持不变', function () {
    $ptoken = new PToken(['timeout' => 10, 'max_refresh' => 5]);

    $token = gen($ptoken, 'user_refresh_data', ['read', 'write'], ['device' => 'test']);

    // 修改缓存中 expire_at 为 2 秒后
    $delimiter = '.';
    $parts = explode($delimiter, $token, 4);
    $encryptedUserKey = $parts[1];
    $tokenId = $parts[2];
    $cacheKey = 'ptoken:' . $encryptedUserKey . '.' . $tokenId;

    $driverProp = (new ReflectionClass(PToken::class))->getProperty('cacheDriver');
    $driver = $driverProp->getValue($ptoken);

    $cached = $driver->get($cacheKey);
    $cached['expire_at'] = time() + 2;
    $driver->set($cacheKey, $cached, 10);

    $data = $ptoken->get($token);
    expect($data['user_key'])->toBe('user_refresh_data');
    expect($data['data'])->toBe(['device' => 'test']);
    expect($data['abilities'])->toBe(['read', 'write']);
    expect($data['expire_at'])->toBeGreaterThan(time() + 5);
    expect($data)->toHaveKey('new_token');
});

// ── Token Claims 测试 ──

test('generate() 缓存包含 iat 和 nbf', function () {
    $ptoken = new PToken();

    $token = gen($ptoken, 'user_claims');
    $data = $ptoken->get($token);

    expect($data['iat'])->toBe($data['create_at']);
    expect($data['nbf'])->toBe($data['create_at']);
});

test('generate() 缓存包含 token_type', function () {
    $ptoken = new PToken();

    $token = gen($ptoken, 'user_type');
    $data = $ptoken->get($token);

    expect($data['token_type'])->toBe('access_token');
});

test('generate() 自定义 token_type', function () {
    $ptoken = new PToken(['token_type' => 'api_key']);

    $token = gen($ptoken, 'user_custom_type');
    $data = $ptoken->get($token);

    expect($data['token_type'])->toBe('api_key');
});

test('generate() 配置了 issuer 时缓存包含 iss', function () {
    $ptoken = new PToken(['issuer' => 'https://api.example.com']);

    $token = gen($ptoken, 'user_iss');
    $data = $ptoken->get($token);

    expect($data['iss'])->toBe('https://api.example.com');
});

test('generate() 配置了 audience 时缓存包含 aud', function () {
    $ptoken = new PToken(['audience' => 'https://app.example.com']);

    $token = gen($ptoken, 'user_aud');
    $data = $ptoken->get($token);

    expect($data['aud'])->toBe('https://app.example.com');
});

test('generate() 未配置 issuer 时缓存不包含 iss', function () {
    $ptoken = new PToken();

    $token = gen($ptoken, 'user_no_iss');
    $data = $ptoken->get($token);

    expect($data)->not->toHaveKey('iss');
    expect($data)->not->toHaveKey('aud');
});

// ── getTokensDetail 测试 ──

test('getTokensDetail() 返回所有活跃 token 完整信息', function () {
    $ptoken = new PToken([
        'multi_login'  => true,
        'cache_pre_key' => 'test_detail:',
    ]);

    gen($ptoken, 'user_detail', ['read'], ['device' => 'Chrome']);
    gen($ptoken, 'user_detail', ['write'], ['device' => 'Safari']);

    $records = $ptoken->getTokensDetail('user_detail');

    expect(count($records))->toBe(2);
    expect($records[0])->toHaveKeys(['token_id', 'user_key', 'data', 'abilities', 'create_at', 'expire_at']);
    expect($records[0]['user_key'])->toBe('user_detail');
    expect($records[1]['user_key'])->toBe('user_detail');

    $devices = array_column(array_column($records, 'data'), 'device');
    expect($devices)->toContain('Chrome');
    expect($devices)->toContain('Safari');

    // 清理
    $ptoken->destroyAll('user_detail');
});

test('getTokensDetail() 自动过滤过期 token', function () {
    $ptoken = new PToken([
        'multi_login'  => true,
        'timeout'       => 10,
        'cache_pre_key' => 'test_filter:',
    ]);

    $token1 = gen($ptoken, 'user_filter', ['*'], ['n' => 1]);

    // 手动让 token1 过期
    $delimiter = '.';
    $parts = explode($delimiter, $token1, 4);
    $encryptedUserKey = $parts[1];
    $tokenId = $parts[2];
    $cacheKey = 'test_filter:' . $encryptedUserKey . '.' . $tokenId;

    $driverProp = (new ReflectionClass(PToken::class))->getProperty('cacheDriver');
    $driver = $driverProp->getValue($ptoken);

    $cached = $driver->get($cacheKey);
    $cached['expire_at'] = time() - 1;
    $driver->set($cacheKey, $cached, 10);

    // 再生成一个新 token
    gen($ptoken, 'user_filter', ['*'], ['n' => 2]);

    $records = $ptoken->getTokensDetail('user_filter');
    expect(count($records))->toBe(1);
    expect($records[0]['data'])->toBe(['n' => 2]);

    // 清理
    $ptoken->destroyAll('user_filter');
});

test('getTokensDetail() 无活跃 token 返回空数组', function () {
    $ptoken = new PToken([
        'cache_pre_key' => 'test_empty:',
    ]);

    $records = $ptoken->getTokensDetail('nonexistent_user');
    expect($records)->toBe([]);
});

// ── 设备指纹测试 ──

test('generate() record_device=true 时记录设备信息', function () {
    $ptoken = new PToken(['record_device' => true]);

    $device = [
        'ip'          => '192.168.1.1',
        'user_agent'  => 'Mozilla/5.0',
        'device_name' => 'Chrome on macOS',
    ];

    $token = gen($ptoken, 'user_device', ['*'], [], $device);
    $data = $ptoken->get($token);

    expect($data['device'])->toBe($device);
});

test('generate() record_device=false 时不记录设备信息', function () {
    $ptoken = new PToken(['record_device' => false]);

    $device = [
        'ip'          => '192.168.1.1',
        'user_agent'  => 'Mozilla/5.0',
        'device_name' => 'Chrome on macOS',
    ];

    $token = gen($ptoken, 'user_no_device', ['*'], [], $device);
    $data = $ptoken->get($token);

    expect($data)->not->toHaveKey('device');
});

test('getTokensDetail() 包含设备信息', function () {
    $ptoken = new PToken([
        'multi_login'   => true,
        'record_device' => true,
        'cache_pre_key' => 'test_device_detail:',
    ]);

    $device1 = ['ip' => '10.0.0.1', 'user_agent' => 'Chrome', 'device_name' => 'Desktop'];
    $device2 = ['ip' => '10.0.0.2', 'user_agent' => 'Safari', 'device_name' => 'iPhone'];

    gen($ptoken, 'user_devices', ['*'], ['n' => 1], $device1);
    gen($ptoken, 'user_devices', ['*'], ['n' => 2], $device2);

    $records = $ptoken->getTokensDetail('user_devices');
    expect(count($records))->toBe(2);

    $ips = array_map(fn($r) => $r['device']['ip'] ?? null, $records);
    expect($ips)->toContain('10.0.0.1');
    expect($ips)->toContain('10.0.0.2');

    // 清理
    $ptoken->destroyAll('user_devices');
});
