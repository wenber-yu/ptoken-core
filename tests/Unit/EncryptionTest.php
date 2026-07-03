<?php

declare(strict_types=1);

use Wenbo\PToken\PToken;

/**
 * 通过反射测试 PToken 的私有加密方法。
 */
test('加密用户 Key 可逆（encrypt → decrypt 还原）', function () {
    $ptoken = new PToken();

    $encryptMethod = getPrivateMethod(PToken::class, 'encryptUserKey');
    $decryptMethod = getPrivateMethod(PToken::class, 'decryptUserKey');

    $original = 'user_key_test_123';
    $encrypted = $encryptMethod->invoke($ptoken, $original);

    expect($encrypted)->not->toBe($original);
    expect($encrypted)->toBeString();

    $decrypted = $decryptMethod->invoke($ptoken, $encrypted);
    expect($decrypted)->toBe($original);
});

test('不同加密密钥产生不同密文', function () {
    $ptoken1 = new PToken([
        'encrypt_key' => '11111111111111111111111111111111',
    ]);
    $ptoken2 = new PToken([
        'encrypt_key' => '22222222222222222222222222222222',
    ]);

    $encryptMethod = getPrivateMethod(PToken::class, 'encryptUserKey');

    $cipher1 = $encryptMethod->invoke($ptoken1, 'same_user_key');
    $cipher2 = $encryptMethod->invoke($ptoken2, 'same_user_key');

    expect($cipher1)->not->toBe($cipher2);
});

test('同一密钥同一输入产生一致密文', function () {
    $ptoken = new PToken();
    $encryptMethod = getPrivateMethod(PToken::class, 'encryptUserKey');

    $cipher1 = $encryptMethod->invoke($ptoken, 'consistent');
    $cipher2 = $encryptMethod->invoke($ptoken, 'consistent');

    expect($cipher1)->toBe($cipher2);
});

test('buildToken 与 parseToken 往返', function () {
    $ptoken = new PToken();

    $buildMethod = getPrivateMethod(PToken::class, 'buildToken');
    $parseMethod = getPrivateMethod(PToken::class, 'parseToken');

    // Token 格式: v1.{encryptedUserKey}.{tokenId}
    $token = $buildMethod->invoke($ptoken, 'ABC123encrypted', 'XYZ789tokenId');

    expect($token)->toContain('.');
    expect($token)->toBe('v1.ABC123encrypted.XYZ789tokenId');

    $parsed = $parseMethod->invoke($ptoken, $token);
    expect($parsed)->toBe(['v1', 'ABC123encrypted', 'XYZ789tokenId']);
});

test('parseToken 对无效格式返回 null', function () {
    $ptoken = new PToken();
    $parseMethod = getPrivateMethod(PToken::class, 'parseToken');

    // 不含分隔符的字符串
    expect($parseMethod->invoke($ptoken, 'noDelimiterHere'))->toBeNull();
    // 空字符串
    expect($parseMethod->invoke($ptoken, ''))->toBeNull();
});

test('加密特殊字符 userKey', function () {
    $ptoken = new PToken();

    $encryptMethod = getPrivateMethod(PToken::class, 'encryptUserKey');
    $decryptMethod = getPrivateMethod(PToken::class, 'decryptUserKey');

    $specialKeys = [
        'user@email.com',
        '中文用户名',
        'emoji_test',
        'very_long_user_key_that_exceeds_typical_length_' . str_repeat('x', 100),
    ];

    foreach ($specialKeys as $key) {
        $encrypted = $encryptMethod->invoke($ptoken, $key);
        $decrypted = $decryptMethod->invoke($ptoken, $encrypted);
        expect($decrypted)->toBe($key);
    }
});

// ───── 辅助函数 ─────

function getPrivateMethod(string $class, string $method): ReflectionMethod
{
    $ref = new ReflectionMethod($class, $method);

    return $ref;
}
