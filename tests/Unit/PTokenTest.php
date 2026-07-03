<?php

declare(strict_types=1);

use Wenbo\PToken\PToken;
use Wenbo\PToken\CacheDrivers\PTokenFileDriver;

test('generate() 生成 Token 格式正确', function () {
    $ptoken = new PToken();

    $token = $ptoken->generate('user_123', ['role' => 'admin']);

    expect($token)->toBeString();
    expect(strlen($token))->toBeGreaterThan(10);
    // Token 包含分隔符 '_'
    expect($token)->toContain('_');
});

test('get() 获取用户数据', function () {
    $ptoken = new PToken();

    $token = $ptoken->generate('user_456', ['name' => '张三', 'age' => 25]);
    $data = $ptoken->get($token);

    expect($data)->toBeArray();
    expect($data['userKey'])->toBe('user_456');
    expect($data['data'])->toBe(['name' => '张三', 'age' => 25]);
    expect($data['createAt'])->toBeInt();
    expect($data['expireAt'])->toBeInt();
    expect($data['expireAt'])->toBeGreaterThan($data['createAt']);
});

test('destroy() 销毁 Token', function () {
    $ptoken = new PToken();

    $token = $ptoken->generate('user_789', []);
    expect($ptoken->get($token))->not->toBeNull();

    $result = $ptoken->destroy($token);
    expect($result)->toBeTrue();
    expect($ptoken->get($token))->toBeNull();
});

test('refresh() 手动续期', function () {
    // 设置较短的 timeout，同时调整 max_refresh 避免配置校验失败
    $ptoken = new PToken(['timeout' => 3600, 'max_refresh' => 0]);

    $token = $ptoken->generate('user_refresh', []);
    $before = $ptoken->get($token);
    $oldExpireAt = $before['expireAt'];

    // 等待 1 秒确保时间有变化，然后刷新
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

    // destroy 会在 CacheDriver 上操作一个不存在的 key，delete 通常返回 true
    expect($ptoken->refresh('invalid_token_string'))->toBeNull();
});

test('过期 Token 返回 null', function () {
    // 设置 1 秒超时，max_refresh=0 避免校验失败
    $ptoken = new PToken(['timeout' => 1, 'max_refresh' => 0]);

    $token = $ptoken->generate('user_expire', []);

    // 等待超时
    sleep(2);

    $data = $ptoken->get($token);
    expect($data)->toBeNull();
});

test('多端登录 — multi_login=false 时旧 Token 指向最新数据', function () {
    // 源码行为：同一 userKey 加密结果相同 → 同一 cacheKey，
    // 旧 Token 依然可解析且返回最新数据，而非 null
    $ptoken = new PToken(['multi_login' => false]);

    $token1 = $ptoken->generate('user_multi', ['session' => 1]);
    $token2 = $ptoken->generate('user_multi', ['session' => 2]);

    // 旧 Token 仍然有效，但返回的是最新数据（后登录者覆盖）
    $data1 = $ptoken->get($token1);
    expect($data1['data'])->toBe(['session' => 2]);
    $data2 = $ptoken->get($token2);
    expect($data2['data'])->toBe(['session' => 2]);
});

test('多端登录 — multi_login=true 时多个生成共享同一缓存', function () {
    // 源码行为：encryptUserKey 是确定性的，两次 generate 写同一个 cacheKey，
    // 后一次的 set 覆盖前一次。所有 token 都返回最新数据。
    $ptoken = new PToken(['multi_login' => true]);

    $token1 = $ptoken->generate('user_multi2', ['session' => 1]);
    $token2 = $ptoken->generate('user_multi2', ['session' => 2]);

    // 两个 Token 都返回最新写入的数据
    $data1 = $ptoken->get($token1);
    $data2 = $ptoken->get($token2);

    expect($data1['data'])->toBe(['session' => 2]);
    expect($data2['data'])->toBe(['session' => 2]);
});

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

    // 清理测试缓存文件
    $driver->delete('ptoken:' . explode('_', $token)[0]);
});

test('不同 userKey 生成不同 Token', function () {
    $ptoken = new PToken();

    $token1 = $ptoken->generate('user_a', []);
    $token2 = $ptoken->generate('user_b', []);

    expect($token1)->not->toBe($token2);
});

test('同一 userKey 多次调用 get 返回一致数据', function () {
    $ptoken = new PToken();
    $token = $ptoken->generate('user_consistent', ['x' => 1]);

    $data1 = $ptoken->get($token);
    $data2 = $ptoken->get($token);

    expect($data1)->toBe($data2);
});
