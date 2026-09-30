<?php
declare(strict_types=1);

/**
 * 수동 수정(MANUAL OVERRIDE) 검증과 병합. 순수 함수.
 * - FINAL = 수동값 ?? AUTO (필드별). 수동값 존재 여부는 array_key_exists로만 판단한다 (0도 유효).
 * - 파생 필드(승률): 수동값이 있으면 그 값(직접 입력), 없으면 FINAL 승·패로 다시 계산(자동 계산).
 */

/**
 * 운영자가 입력한 문자열을 검증해 저장할 값으로 바꾼다. 빈 값·잘못된 값은 조용히 대체하지 않고 거부한다.
 * @return array{ok:true, value:mixed}|array{ok:false, error:string}
 */
function field_parse(array $def, mixed $raw): array
{
    if (!is_string($raw) && !is_int($raw)) {
        return ['ok' => false, 'error' => '값 형식이 올바르지 않습니다.'];
    }
    $s = trim((string)$raw);
    if ($s === '') {
        return ['ok' => false, 'error' => '값을 입력하세요. 자동값으로 돌아가려면 되돌리기를 누르세요.'];
    }
    switch ($def['type']) {
        case 'int':
            if (!preg_match('/^\d{1,5}$/D', $s)) {
                return ['ok' => false, 'error' => '0 이상의 정수(최대 99999)만 입력할 수 있습니다.'];
            }
            return ['ok' => true, 'value' => (int)$s];
        case 'rate':
            if (!preg_match('/^(\d{1,3})(?:\.(\d))?$/D', $s, $m)) {
                return ['ok' => false, 'error' => '승률은 62.8처럼 소수 첫째 자리까지 입력하세요.'];
            }
            $t = (int)$m[1] * 10 + (int)($m[2] ?? 0);
            if ($t > 1000) {
                return ['ok' => false, 'error' => '승률은 0.0~100.0 사이여야 합니다.'];
            }
            return ['ok' => true, 'value' => $t];
        case 'text':
            if (preg_match('/[\x00-\x1F\x7F]/u', $s) || !mb_check_encoding($s, 'UTF-8')) {
                return ['ok' => false, 'error' => '사용할 수 없는 문자가 있습니다.'];
            }
            $max = (int)($def['max'] ?? 40);
            if (mb_strlen($s) > $max) {
                return ['ok' => false, 'error' => "최대 {$max}자까지 입력할 수 있습니다."];
            }
            return ['ok' => true, 'value' => $s];
    }
    return ['ok' => false, 'error' => '알 수 없는 필드 종류입니다.'];
}

/**
 * 필드별 AUTO/MANUAL/FINAL 병합.
 * @param ?array $auto 입력 필드의 AUTO 값 (한 번도 정상 데이터가 없으면 null)
 * @param array<string, array{value:mixed, auto_at_set:mixed, keep:bool}> $ov 수동값 (필드 => 행)
 * @return array<string, array> 필드별 {auto, manual, has_manual, final, origin, calc, differs, auto_changed, keep}
 */
function ov_merge(array $fields, ?array $auto, array $ov): array
{
    $out = [];
    foreach ($fields as $key => $def) {
        if (isset($def['derived'])) {
            continue; // 입력 필드를 먼저 확정한 뒤 계산
        }
        $a = $auto === null ? null : ($auto[$key] ?? null);
        $has = array_key_exists($key, $ov);
        $out[$key] = [
            'auto' => $a,
            'manual' => $has ? $ov[$key]['value'] : null,
            'has_manual' => $has,
            'final' => $has ? $ov[$key]['value'] : $a,
            'origin' => $has ? 'MANUAL' : 'AUTO',
            'calc' => null,
            'differs' => false,
            'auto_changed' => $has && $ov[$key]['auto_at_set'] !== $a,
            'auto_at_set' => $has ? $ov[$key]['auto_at_set'] : null,
            'keep' => $has && $ov[$key]['keep'],
        ];
    }
    foreach ($fields as $key => $def) {
        if (!isset($def['derived'])) {
            continue;
        }
        [$wk, $lk] = $def['derived'];
        $autoRate = stats_rate_tenths($out[$wk]['auto'], $out[$lk]['auto']);
        $calc = stats_rate_tenths($out[$wk]['final'], $out[$lk]['final']);
        $has = array_key_exists($key, $ov);
        $out[$key] = [
            'auto' => $autoRate,
            'manual' => $has ? $ov[$key]['value'] : null,
            'has_manual' => $has,
            'final' => $has ? $ov[$key]['value'] : $calc,
            'origin' => $has ? 'MANUAL' : 'AUTO',
            'calc' => $calc,
            'differs' => $has && $ov[$key]['value'] !== $calc,
            'auto_changed' => $has && $ov[$key]['auto_at_set'] !== $autoRate,
            'auto_at_set' => $has ? $ov[$key]['auto_at_set'] : null,
            'keep' => $has && $ov[$key]['keep'],
        ];
    }
    // 정의 순서대로 돌려준다
    return array_merge(array_intersect_key(array_flip(array_keys($fields)), $out), $out);
}

function ov_final(array $merged): array
{
    return array_map(static fn($f) => $f['final'], $merged);
}

/**
 * 송출 가능 여부. 값이 없는 필드가 있으면 TAKE/UPDATE LIVE를 막는다.
 * 승률은 0경기(승·패 모두 0)일 때만 비어 있어도 된다 ("자료 없음").
 * @return list<string> 문제 목록
 */
function ov_sendable(array $fields, array $final): array
{
    $problems = [];
    foreach ($fields as $key => $def) {
        $v = $final[$key] ?? null;
        if (isset($def['derived'])) {
            [$wk, $lk] = $def['derived'];
            if ($v === null && !($final[$wk] === 0 && $final[$lk] === 0)) {
                $problems[] = "{$def['label']}: 값이 없습니다.";
            }
            continue;
        }
        if ($v === null || $v === '') {
            $problems[] = "{$def['label']}: 값이 없습니다.";
        }
    }
    return $problems;
}

/**
 * 스냅샷 값에 일부 필드를 덮어쓴 뒤 파생 필드를 다시 계산한다 (UPDATE LIVE용).
 * @param list<string> $manualDerived 직접 입력 상태인 파생 필드
 */
function ov_apply_to_final(array $fields, array $final, array $values, array $manualDerived): array
{
    foreach ($values as $key => $v) {
        $final[$key] = $v;
    }
    foreach ($fields as $key => $def) {
        if (isset($def['derived']) && !in_array($key, $manualDerived, true) && !array_key_exists($key, $values)) {
            [$wk, $lk] = $def['derived'];
            $final[$key] = stats_rate_tenths($final[$wk], $final[$lk]);
        }
    }
    return $final;
}
