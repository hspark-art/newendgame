<?php
declare(strict_types=1);

/** 웹 버전 계정 기능 (W 단계에서 구현). */

function auth_require_user(): array
{
    deny(503, 'WEB_NOT_READY', '웹 버전 계정 기능은 준비 중입니다.');
}

function auth_csrf_token(): string
{
    return '';
}

function auth_user_optional(): ?array
{
    return null;
}
