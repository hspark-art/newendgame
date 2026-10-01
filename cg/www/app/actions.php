<?php
declare(strict_types=1);

/** 조작 API(api/action.php)의 동작 이름 → 함수 연결 */

function in_int(array $in, string $key): int
{
    $v = $in[$key] ?? null;
    if (is_int($v)) {
        return $v;
    }
    if (is_string($v) && preg_match('/^-?\d{1,9}$/D', $v)) {
        return (int)$v;
    }
    throw new ActionError('BAD_REQUEST', "요청 값이 올바르지 않습니다: $key", 400);
}

/** 수정값 묶음 {필드: 문자열} */
function in_values(array $in, bool $allowEmpty = false): array
{
    $v = $in['values'] ?? [];
    if (!is_array($v) || array_is_list($v) && $v !== []) {
        throw new ActionError('BAD_REQUEST', '수정값 형식이 올바르지 않습니다.', 400);
    }
    if (!$v && !$allowEmpty) {
        throw new ActionError('VALIDATION', '저장할 수정값이 없습니다.', 422);
    }
    return $v;
}

function action_dispatch(string $action, array $in, array $op): mixed
{
    return match ($action) {
        'page_add' => page_add($in, $op),
        'page_update' => page_update(in_int($in, 'id'), $in, $op),
        'page_copy' => page_copy(in_int($in, 'id'), $op),
        'page_remove' => page_remove(in_int($in, 'id'), $op),
        'page_move' => page_move(in_int($in, 'id'), in_int($in, 'dir')),
        'cue_page' => cue_page(in_int($in, 'page_no')),
        'next' => cue_step(1),
        'prev' => cue_step(-1),
        'take' => program_take(in_int($in, 'preview_rev'), [
            'effect' => (string)($in['effect'] ?? 'slide'),
            'dur_ms' => (int)($in['dur_ms'] ?? 350),
            'auto_next' => !empty($in['auto_next']),
        ], $op),
        'show' => program_visibility(true, $op),
        'out' => program_visibility(false, $op),
        'set_display' => preview_display($in, $op),
        'refresh_data' => data_refresh($op),
        'save_preview' => preview_save(in_int($in, 'instance_id'), in_values($in), $op),
        'reset' => override_reset(in_int($in, 'instance_id'), isset($in['field']) ? (string)$in['field'] : null, $op),
        'hide' => instance_hide(in_int($in, 'instance_id'), array_values(array_filter((array)($in['fields'] ?? []), 'is_string')),
            !empty($in['hide']), $op),
        'set_keep' => override_keep(in_int($in, 'instance_id'), (string)($in['field'] ?? ''), !empty($in['keep']), $op),
        'update_live' => program_update_live(in_int($in, 'instance_id'), in_int($in, 'take_id'), in_int($in, 'preview_rev'),
            in_values($in, true), $op),
        'new_session' => session_start_new((string)($in['name'] ?? ''), $op),
        'rundown_export' => rundown_export(),
        'rundown_import' => rundown_import($in['data'] ?? null, $op),
        // 데이터 소스 (설정 변경은 관리자만 — 함수 안에서 확인)
        'data_check' => data_check_view(),
        'data_settings' => data_settings_view($op),
        'data_settings_save' => data_settings_save($in, $op),
        'data_key_save' => google_key_save((string)($in['key'] ?? ''), $op),
        'data_key_remove' => google_key_remove($op),
        'data_test' => data_test($op),
        'data_import_xlsx' => data_import_xlsx($in, $op),
        'match_exclude' => match_exclude($in, $op),
        'player_info' => player_info_view(),
        'player_info_save' => player_info_save($in, $op),
        // 오늘 매치: 저장 · 2인 CG 한 번에 추가 · 페이지 리스트를 이 매치로
        'match_save' => match_today_save($in, $op),
        'match_add' => match_pages_add($in, $op),
        'match_apply' => match_pages_apply($in, $op),
        'map_info' => map_info_view(),
        'map_info_save' => map_info_save($in, $op),
        // CG 디자인 (바꾸기는 관리자만 — 함수 안에서 확인)
        'design' => design_view($op),
        'design_save' => design_save($in, $op),
        'design_preset_save' => design_preset_save($in, $op),
        'design_preset_remove' => design_preset_remove($in, $op),
        // 관리자 알림
        'alerts' => alerts_view($op),
        'alert_ack' => alert_ack($in, $op),
        default => throw new ActionError('UNKNOWN_ACTION', '알 수 없는 동작입니다.', 400),
    };
}
