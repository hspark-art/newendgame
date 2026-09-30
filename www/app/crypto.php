<?php
/**
 * 수령자 개인정보(이름·연락처·주소) 암호화
 * config.php 의 app_key 로 AES-256-GCM 암호화하여 DB 에 저장합니다.
 */
declare(strict_types=1);

function generate_app_key(): string
{
    return base64_encode(random_bytes(32));
}

function app_key_valid(): bool
{
    $key = base64_decode((string) config('app_key', ''), true);
    return $key !== false && strlen($key) === 32;
}

function app_key(): string
{
    $key = base64_decode((string) config('app_key', ''), true);
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('config.php 의 app_key 가 올바르지 않습니다. install.php 에서 안내하는 값을 넣어주세요.');
    }
    return $key;
}

function pii_encrypt(?string $plain): ?string
{
    $plain = trim((string) $plain);
    if ($plain === '') {
        return null;
    }
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', app_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        throw new RuntimeException('개인정보 암호화에 실패했습니다.');
    }
    return 'v1:' . base64_encode($iv . $tag . $cipher);
}

function pii_decrypt(?string $stored): ?string
{
    if ($stored === null || $stored === '') {
        return null;
    }
    if (!str_starts_with($stored, 'v1:')) {
        return null;
    }
    $raw = base64_decode(substr($stored, 3), true);
    if ($raw === false || strlen($raw) < 29) {
        return null;
    }
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', app_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $plain === false ? null : $plain;
}
