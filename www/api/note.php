<?php
/**
 * [관리자] SOOP 쪽지 서버 발송
 *  POST {act:"send", prize_id, content}  → 한 명에게 보내고 결과를 지급 기록에 남깁니다.
 *  POST {act:"check"}                    → 등록한 로그인 세션이 유효한지 확인
 * 화면에서 여러 명을 고르면 브라우저가 한 명씩 0.9초 간격으로 이 주소를 부릅니다.
 */
define('API_REQUEST', true);
require dirname(__DIR__) . '/app/bootstrap.php';
require APP_DIR . '/note.php';

require_admin_role();
if (!is_post()) {
    json_response(['ok' => false, 'error' => 'POST 요청만 허용됩니다.'], 405);
}
csrf_check();
session_write_close();
@set_time_limit(60);

$in = json_decode((string) file_get_contents('php://input'), true);
$act = is_array($in) ? (string) ($in['act'] ?? '') : '';

if ($act === 'check') {
    json_response(['ok' => true] + note_check(note_cookie()));
}

if ($act === 'send') {
    $prize = db_one('SELECT * FROM prizes WHERE id = ?', [(int) ($in['prize_id'] ?? 0)]);
    if (!$prize) {
        json_response(['ok' => false, 'error' => '지급 기록을 찾을 수 없습니다.'], 404);
    }
    $to = trim((string) $prize['user_id']);
    $content = mb_substr(note_fill(is_string($in['content'] ?? null) ? $in['content'] : '', $prize), 0, 5000);
    if ($to === '' || trim($content) === '') {
        json_response(['ok' => false, 'error' => '받는 사람 아이디와 쪽지 내용을 확인해 주세요.'], 400);
    }
    $cookie = note_cookie();
    if ($cookie === '') {
        json_response(['ok' => false, 'error' => '[설정]에서 SOOP 쪽지 세션을 먼저 등록해 주세요.', 'expired' => true]);
    }
    $r = note_send($cookie, $to, $content);
    if ($r['ok']) {
        db_exec('UPDATE prizes SET note_sent_at = ?, note_result = ?, updated_at = ? WHERE id = ?', [now(), '서버 발송 성공', now(), $prize['id']]);
    } else {
        db_exec('UPDATE prizes SET note_result = ?, updated_at = ? WHERE id = ?', [mb_substr($r['reason'], 0, 250), now(), $prize['id']]);
    }
    audit('note_send', "prize:{$prize['id']}", $to . ' · ' . ($r['ok'] ? '성공' : '실패: ' . $r['reason']));
    json_response(['ok' => $r['ok'], 'reason' => $r['reason'], 'expired' => !empty($r['expired']), 'rejected' => !empty($r['rejected']),
        'sent_at' => $r['ok'] ? now() : null]);
}

json_response(['ok' => false, 'error' => '요청 형식이 올바르지 않습니다.'], 400);
