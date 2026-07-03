<?php

declare(strict_types=1);

use Wenbo\PToken\PToken;
use Wenbo\PToken\CacheDrivers\PTokenFileDriver;
use Wenbo\PToken\Exceptions\PTokenForbiddenException;

test('generate() 生成 Token 格式正确', function () {
    $ptoken = new PToken();

    $token = $ptoken->generate('user_123', ['role' => 'admin']);

    expect($token)->toBeString();
    expect(strlen($token))->toBeGreaterThan(10);
    // Token 包含分隔符 '.'
    expect($token)->toContain('.');
});

test('get() 获取用户数据', function () {
    $ptoken = new PToken();

    $token = $ptoken->generate('user_456', ['name' => '张三', 'age' => 25]);
    $data = $ptoken->get($token);

    expect($data)->toBeArray();
    expect($data['userKey'])->toBe('user_456');
    expect($data['data'])->toBe(['name' => '张三', 'age' => 25]);
    expect($data['abilities'])->toBe(['*']);
    expect($data['tokenId'])->toBeString();
    expect($data['createAt'])->toBeInt();
    expect($data['expireAt'])->toBeInt();
    expect($data['expireAt'])->toBeGreaterThan($data['createAt']);
});

test('generate() 支持自定义 abilities', function () {
    $ptoken = new PToken();

    $token = $ptoken->generate('user_abilities', [], ['read', 'write']);
    $data = $ptoken->get($token);

    expect($data['abilities'])->toBe(['read', 'write']);
});

test('generate() 默认 abilities 为 [*]', function () {
    $ptoken = new PToken();

    $token = $ptoken->generate('user_default_abilities', []);
    $data = $ptoken->get($token);

    expect($data['abilities'])->toBe(['*']);
});

test('destroy() 销毁 Token', function () {
    $ptoken = new PToken();

    $token = $ptoken->generate('user_789', []);
    expect($ptoken->get($token))->not->toBeNull();

    $result = $ptoken->destroy($token);
    expect($result)->toBeTrue();
    expect($ptoken->get($token))->toBeNull();
});

test('destroyAll() 销毁用户所有 Token', function () {
    $ptoken = new PToken(['multi_login' => true]);

    $token1 = $ptoken->generate('user_destroy_all', ['n' => 1]);
    $token2 = $ptoken->generate('user_destroy_all', ['n' => 2]);

    expect($ptoken->get($token1))->not->toBeNull();
    expect($ptoken->get($token2))->not->toBeNull();

    $ptoken->destroyAll('user_destroy_all');

    expect($ptoken->get($token1))->toBeNull();
    expect($ptoken->get($token2))->toBeNull();
});

test('refresh() 手动续期', function () {
    $ptoken = new PToken(['timeout' => 3600, 'max_refresh' => 0]);

    $token = $ptoken->generate('user_refresh', []);
    $before = $ptoken->get($token);
    $oldExpireAt = $before['expireAt'];

    sleep(1);
    $refreshed = $ptoken->refresh($token);

    expect($refreshed)->toBe($token);

    $after = $ptoken->get($token);
    expect($after['expireAt'])->toBeGreaterThan($oldExpireAt);
});

test('无效 Token 返回 null', function () {
    $ptoken = new PToken();

    $data = $ptoken->get('invalid_token_string');
    expect($data)->toBeNull();

    expect($ptoken->refresh('invalid_token_string'))->toBeNull();
});

test('过期 Token 返回 null', function () {
    $ptoken = new PToken(['timeout' => 1, 'max_refresh' => 0]);

    $token = $ptoken->generate('user_expire', []);

    sleep(2);

    $data = $ptoken->get($token);
    expect($data)->toBeNull();
});

test('多端登录 — multi_login=false 时新登录销毁旧 Token', function () {
    $ptoken = new PToken(['multi_login' => false]);

    $token1 = $ptoken->generate('user_multi', ['session' => 1]);
    $token2 = $ptoken->generate('user_multi', ['session' => 2]);

    // 旧 Token 已失效
    expect($ptoken->get($token1))->toBeNull();
    // 新 Token 有效
    $data2 = $ptoken->get($token2);
    expect($data2['data'])->toBe(['session' => 2]);
});

test('多端登录 — multi_login=true 时多个 Token 同时有效', function () {
    $ptoken = new PToken(['multi_login' => true]);

    $token1 = $ptoken->generate('user_multi2', ['session' => 1]);
    $token2 = $ptoken->generate('user_multi2', ['session' => 2]);

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

    $ptoken->generate('user_list', ['n' => 1]);
    $ptoken->generate('user_list', ['n' => 2]);

    $tokens = $ptoken->getTokens('user_list');

    expect(count($tokens))->toBe(2);

    // 清理
    $ptoken->destroyAll('user_list');
});

test('tokenCan() 检查 Token 能力', function () {
    $ptoken = new PToken();

    $token = $ptoken->generate('user_can', [], ['read', 'write']);

    expect($ptoken->tokenCan($token, 'read'))->toBeTrue();
    expect($ptoken->tokenCan($token, 'write'))->toBeTrue();
    expect($ptoken->tokenCan($token, 'delete'))->toBeFalse();
});

test('tokenCan() 通配符 * 拥有所有能力', function () {
    $ptoken = new PToken();

    $token = $ptoken->generate('user_wildcard', [], ['*']);

    expect($ptoken->tokenCan($token, 'anything'))->toBeTrue();
    expect($ptoken->tokenCan($token, 'server:update'))->toBeTrue();
});

test('tokenCanAny() 检查任意能力', function () {
    $ptoken = new PToken();

    $token = $ptoken->generate('user_any', [], ['read', 'write']);

    expect($ptoken->tokenCanAny($token, ['read', 'delete']))->toBeTrue();
    expect($ptoken->tokenCanAny($token, ['delete', 'update']))->toBeFalse();
});

test('tokenCanAll() 检查全部能力', function () {
    $ptoken = new PToken();

    $token = $ptoken->generate('user_all', [], ['read', 'write']);

    expect($ptoken->tokenCanAll($token, ['read', 'write']))->toBeTrue();
    expect($ptoken->tokenCanAll($token, ['read', 'delete']))->toBeFalse();
});

test('authorizeAbilities() 通过时无异常', function () {
    $ptoken = new PToken();

    $token = $ptoken->generate('user_authz', [], ['read', 'write']);

    $ptoken->authorizeAbilities($token, ['read']);  // 不应抛出异常
    $ptoken->authorizeAbilities($token, ['read'], requireAll: false);

    expect(true)->toBeTrue(); // 到这里就是通过了
});

test('authorizeAbilities() 失败时抛出 PTokenForbiddenException', function () {
    $ptoken = new PToken();

    $token = $ptoken->generate('user_forbidden', [], ['read']);

    $ptoken->authorizeAbilities($token, ['delete']);
})->throws(PTokenForbiddenException::class);

test('通过配置数组创建 PToken', function () {
    $ptoken = new PToken([
        'cache_mode'    => 3,
        'cache_pre_key' => 'test:',
        'timeout'       => 7200,
        'max_refresh'   => 0,
        'encrypt_key'   => 'abcdefghijklmnopqrstuvwxyz123456',
    ]);

    $token = $ptoken->generate('config_test', []);
    $data = $ptoken->get($token);

    expect($data['userKey'])->toBe('config_test');
});

test('传入外部 CacheDriver 使用自定义驱动', function () {
    $driver = new PTokenFileDriver(sys_get_temp_dir() . '/ptoken-test');
    $ptoken = new PToken(['timeout' => 3600, 'max_refresh' => 0], $driver);

    $token = $ptoken->generate('driver_test', []);
    $data = $ptoken->get($token);

    expect($data['userKey'])->toBe('driver_test');

    // 清理测试缓存
    $ptoken->destroyAll('driver_test');
});

test('不同 userKey 生成不同 Token', function () {
    $ptoken = new PToken();

    $token1 = $ptoken->generate('user_a', []);
    $token2 = $ptoken->generate('user_b', []);

    expect($token1)->not->toBe($token2);
});

test('同一 userKey 多次生成 token 互不相同', function () {
    $ptoken = new PToken(['multi_login' => true]);

    $token1 = $ptoken->generate('user_unique', []);
    $token2 = $ptoken->generate('user_unique', []);

    expect($token1)->not->toBe($token2);
});

test('同一 userKey 多次调用 get 返回一致数据', function () {
    $ptoken = new PToken();
    $token = $ptoken->generate('user_consistent', ['x' => 1]);

    $data1 = $ptoken->get($token);
    $data2 = $ptoken->get($token);

    expect($data1)->toBe($data2);
});
