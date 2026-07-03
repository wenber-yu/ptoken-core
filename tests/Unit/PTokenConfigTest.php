<?php

declare(strict_types=1);

use Wenbo\PToken\PTokenConfig;

test('默认配置值正确', function () {
    $config = new PTokenConfig();

    expect($config->cache_mode)->toBe(3);
    expect($config->cache_pre_key)->toBe('ptoken:');
    expect($config->timeout)->toBe(604800);
    expect($config->token_delimiter)->toBe('.');
    expect($config->encrypt_key)->toBe('12345678901234567890123456789012');
    expect($config->multi_login)->toBeFalse();
    expect($config->user_model)->toBeNull();
    expect($config->auth_exclude_paths)->toBe([]);
    expect($config->redis_config)->toBe([
        'host'     => '127.0.0.1',
        'port'     => 6379,
        'password' => '',
        'database' => 0,
    ]);
    expect($config->file_cache_path)->toBe('');
});

test('fromArray 从数组创建配置', function () {
    $config = PTokenConfig::fromArray([
        'timeout'      => 3600,
        'multi_login'  => true,
        'cache_pre_key' => 'myapp:',
    ]);

    expect($config->timeout)->toBe(3600);
    expect($config->multi_login)->toBeTrue();
    expect($config->cache_pre_key)->toBe('myapp:');

    // 未传入的保持默认值
    expect($config->cache_mode)->toBe(3);
});

test('fromArray 忽略不存在的属性', function () {
    $config = PTokenConfig::fromArray([
        'timeout'     => 7200,
        'unknownProp' => 'should_be_ignored',
    ]);

    expect($config->timeout)->toBe(7200);
    // no exception thrown and unknownProp simply ignored
});

test('各配置项可独立设置和读取', function () {
    $config = new PTokenConfig();
    $config->cache_mode = 3;
    $config->file_cache_path = '/tmp/custom';

    expect($config->cache_mode)->toBe(3);
    expect($config->file_cache_path)->toBe('/tmp/custom');

    $config->auth_exclude_paths = ['/api/login', '/api/public'];

    expect($config->auth_exclude_paths)->toBe(['/api/login', '/api/public']);
});
