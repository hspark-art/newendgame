<?php
declare(strict_types=1);

/**
 * 작업 기록 (data / error / broadcast / override / session / auth).
 * 호출한 트랜잭션 안에서 함께 저장된다. 기록 실패를 숨기지 않는다.
 * ctx의 auto/prev/new는 키가 있으면 JSON으로 저장한다 (값 null은 'null', 키 없음은 SQL NULL).
 */
function cg_log(string $type, string $action, array $op, array $ctx = []): void
{
    $j = static fn(string $k) => array_key_exists($k, $ctx) ? mb_substr(json_enc($ctx[$k]), 0, 255) : null;
    db_exec(
        'INSERT INTO cg_logs (created_at, type, action, operator, session_id, instance_id, template, field,
            auto_json, prev_json, new_json, detail) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            now(), $type, $action, mb_substr((string)($op['name'] ?? '-'), 0, 50),
            $ctx['session_id'] ?? current_session_id(), $ctx['instance_id'] ?? null,
            $ctx['template'] ?? null, $ctx['field'] ?? null,
            $j('auto'), $j('prev'), $j('new'), isset($ctx['detail']) ? mb_substr((string)$ctx['detail'], 0, 2000) : null,
        ]
    );
}

function logs_recent(int $limit = 30): array
{
    return db_all('SELECT * FROM cg_logs ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)));
}
