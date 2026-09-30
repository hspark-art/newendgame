<?php
declare(strict_types=1);

/**
 * 배포 무결성 검사: 배포 zip에 함께 들어 있는 app/manifest.sha256 과 서버의 실제 파일을 비교한다.
 * FTP로 일부 파일만 올라갔거나, 올리다 끊긴 파일을 찾기 위해 쓴다.
 * manifest 형식: "<sha256>  <www 기준 경로>" 한 줄에 하나 (sha256sum 형식)
 */

/** @return array{status:string, checked:int, missing:list<string>, changed:list<string>} */
function release_verify(?string $root = null): array
{
    $root ??= WWW_DIR;
    $manifest = $root . '/app/manifest.sha256';
    if (!is_file($manifest)) {
        return ['status' => 'no_manifest', 'checked' => 0, 'missing' => [], 'changed' => []];
    }
    $missing = $changed = [];
    $checked = 0;
    foreach (file($manifest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (!preg_match('/^([0-9a-f]{64})  (.+)$/D', $line, $m) || str_contains($m[2], '..')) {
            $changed[] = '(manifest 형식 오류) ' . mb_substr($line, 0, 80);
            continue;
        }
        $checked++;
        $path = $root . '/' . $m[2];
        if (!is_file($path)) {
            $missing[] = $m[2];
        } elseif (!hash_equals($m[1], hash_file('sha256', $path))) {
            $changed[] = $m[2];
        }
    }
    return [
        'status' => $missing || $changed ? 'mismatch' : 'ok',
        'checked' => $checked,
        'missing' => $missing,
        'changed' => $changed,
    ];
}

function release_info(): array
{
    return [
        'version' => APP_VERSION,
        'schema' => schema_version(),
        'db' => db_driver(),
        'php' => PHP_VERSION,
        'install_php_exists' => is_file(WWW_DIR . '/install.php'),
    ];
}
