<?php

declare(strict_types=1);

use Wenbo\PToken\CacheDrivers\PTokenFileDriver;

test('文件驱动读写成功', function () {
    $tmpDir = sys_get_temp_dir() . '/ptoken_test_' . uniqid();
    $driver = new PTokenFileDriver($tmpDir);

    $driver->set('file_key1', 'file_value1', 3600);
    expect($driver->get('file_key1'))->toBe('file_value1');
    expect($driver->has('file_key1'))->toBeTrue();

    // 清理
    $driver->delete('file_key1');
    rmdir($tmpDir . '/' . substr(md5('file_key1'), 0, 2));
    rmdir($tmpDir);
});

test('文件驱动删除', function () {
    $tmpDir = sys_get_temp_dir() . '/ptoken_test_' . uniqid();
    $driver = new PTokenFileDriver($tmpDir);

    $driver->set('del_key', 'del_value', 3600);
    $driver->delete('del_key');
    expect($driver->get('del_key'))->toBeNull();

    // 清理
    rmdir($tmpDir . '/' . substr(md5('del_key'), 0, 2));
    rmdir($tmpDir);
});

test('文件驱动过期自动失效', function () {
    $tmpDir = sys_get_temp_dir() . '/ptoken_test_' . uniqid();
    $driver = new PTokenFileDriver($tmpDir);

    $driver->set('expire_fd', 'will_expire', 1);
    sleep(2);

    expect($driver->get('expire_fd'))->toBeNull();

    // 清理
    if (is_dir($tmpDir)) {
        $subDir = $tmpDir . '/' . substr(md5('expire_fd'), 0, 2);
        if (is_dir($subDir)) {
            rmdir($subDir);
        }
        rmdir($tmpDir);
    }
});

test('文件驱动存储复杂数据', function () {
    $tmpDir = sys_get_temp_dir() . '/ptoken_test_' . uniqid();
    $driver = new PTokenFileDriver($tmpDir);

    $data = [
        'userKey'  => 'user_123',
        'data'     => ['role' => 'admin'],
        'createAt' => time(),
        'expireAt' => time() + 3600,
    ];

    $driver->set('complex', $data, 3600);
    $retrieved = $driver->get('complex');

    expect($retrieved)->toBe($data);

    // 清理
    $driver->delete('complex');
    $subDir = $tmpDir . '/' . substr(md5('complex'), 0, 2);
    rmdir($subDir);
    rmdir($tmpDir);
});
