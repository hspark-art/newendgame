<?php
/**
 * SOOP 쪽지 (당첨 안내)
 *  - 문안 템플릿: 기존 끝장전 당첨자 시트(prize_sheet.php)의 문안을 기본값으로, [설정]에서 수정
 *  - 서버 발송: 회사 SOOP 계정의 로그인 쿠키를 [설정]에 등록해 두면 이 서버가 대신 쪽지를 보냅니다.
 *    (SOOP 은 다른 사이트에서 직접 쪽지를 보낼 수 없게 막아 두어서, 기존 시스템과 같은 방식으로 서버가 보냅니다)
 *    SOOP 공식 기능이 아니므로 SOOP 쪽 방식이 바뀌면 동작하지 않을 수 있습니다.
 */
declare(strict_types=1);

const NOTE_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';

/** 기본 문안 (기존 시스템 그대로) — 쓸 수 있는 칸: {nick} {id} {prize} {date} */
function default_note_templates(): array
{
    $tail = "\n\n위에 첨부된 구글폼 링크에 접속, 작성해주시면 됩니다.\n\n"
        . "기타 문의 사항은 숲 중계진 계정으로 쪽지 혹은 help@etalent.co.kr 로 보내주시면 됩니다\n\n"
        . "앞으로도 저희 스타 끝장전에 많은 시청과 사랑, 관심 부탁드립니다.\n감사합니다!";
    $head = "안녕하세요.\n\n구글 플레이 x 스타 끝장전 시청자 이벤트 안내 드립니다.\n\n아래 링크의 폼을 작성해주세요.\n\n"
        . "스타 끝장전 시청자 이벤트 개인정보 수집·이용에 관한 동의서\n";
    return [
        'tax'   => $head . '링크 : https://forms.gle/AhPLwkWZwuNhkHn6A' . $tail,
        'free'  => $head . '링크 : https://forms.gle/VLEkozjSJRVZije26' . $tail,
        'code'  => "안녕하세요. {nick}님! 주식회사 중계진입니다.\n\n"
            . "금일 중계진 분들께서 전달 주신 구글 플레이 5000 포인트 코드 전달 드립니다\n\n코드 번호 : \n\n"
            . "코드는 Google play 앱 -> 결제 및 정기 결제 -> 기프트 코드 사용 메뉴에서 등록할 수 있습니다.\n\n"
            . "2026년 12월 31일 23:59에 만료되니 참고 부탁드립니다.\n\n"
            . "항상 저희 끝장전 및 주식회사 중계진 콘텐츠에 관심과 성원 보내주셔서 감사합니다!",
        'blank' => '',
    ];
}

function note_templates(): array
{
    $saved = json_decode(setting_get('note_templates', ''), true);
    $defaults = default_note_templates();
    if (!is_array($saved)) {
        return $defaults;
    }
    foreach ($defaults as $k => $v) {
        if (isset($saved[$k]) && is_string($saved[$k])) {
            $defaults[$k] = $saved[$k];
        }
    }
    return $defaults;
}

/** 문안의 {nick} {id} {prize} {date} 를 채웁니다. */
function note_fill(string $template, array $prize): string
{
    return strtr($template, [
        '{nick}'  => (string) ($prize['nickname'] ?? ''),
        '{id}'    => (string) ($prize['user_id'] ?? ''),
        '{prize}' => (string) ($prize['prize_name'] ?? ''),
        '{date}'  => substr((string) ($prize['created_at'] ?? ''), 0, 10),
    ]);
}

function note_write_url(): string
{
    return setting_get('note_write_url') ?: 'https://note.sooplive.com/app/index.php?page=write';
}

// ── 서버 발송용 로그인 쿠키 (암호화 저장) ──────────────────
function note_cookie(): string
{
    return (string) pii_decrypt(setting_get('note_cookie', '') ?: null);
}

function note_cookie_saved_at(): string
{
    return setting_get('note_cookie_saved_at', '');
}

function note_http(string $url, ?array $post, string $cookie, array $extraHeaders = []): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => array_merge(['User-Agent: ' . NOTE_UA, 'Cookie: ' . $cookie], $extraHeaders),
    ];
    if ($post !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($post);
    }
    curl_setopt_array($ch, $opts);
    if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
        curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
    }
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    return [$res === false ? false : (string) $res, $code, $err];
}

/**
 * 쪽지 한 통 보내기 (기존 시스템 note_write 와 같은 방식, 2026-08-21 실측)
 * 성공은 SOOP 응답의 RESULT 가 1 일 때만 인정합니다. (거짓 성공 방지)
 * @return array{ok:bool, reason:string, expired?:bool, rejected?:bool}
 */
function note_send(string $cookie, string $to, string $content): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'reason' => '서버에 cURL 이 없어 쪽지를 보낼 수 없습니다.'];
    }
    [$res, $code, $err] = note_http(
        (string) config('soop_note_api', 'https://note.sooplive.com/api/note_api.php'),
        ['szWork' => 'WRITE', 'recv_id' => $to, 'txt_to' => $to, 'file_key' => '', 'file_size' => '', 'content' => $content],
        $cookie,
        ['Origin: https://note.sooplive.com', 'Referer: https://note.sooplive.com/app/index.php?page=write', 'X-Requested-With: XMLHttpRequest']
    );
    if ($res === false || $code === 0) {
        return ['ok' => false, 'reason' => 'SOOP 접속 실패: ' . $err];
    }
    if ((stripos($res, 'login') !== false && stripos($res, 'member') !== false) || mb_strpos($res, '로그인이 필요') !== false) {
        return ['ok' => false, 'expired' => true, 'reason' => '로그인 세션이 만료됐습니다. [설정]에서 다시 등록해 주세요.'];
    }
    $j = json_decode(trim($res), true);
    if (is_array($j) && array_key_exists('RESULT', $j)) {
        if (!empty($j['all_reject']) || !empty($j['sender_balck'])) {
            return ['ok' => false, 'rejected' => true, 'reason' => '상대가 쪽지 수신을 거부한 계정입니다.'];
        }
        $ok = (string) $j['RESULT'] === '1' || $j['RESULT'] === true;
        $msg = (string) ($j['MSG'] ?? $j['MESSAGE'] ?? $j['message'] ?? '');
        return ['ok' => $ok, 'reason' => $ok ? '' : ($msg !== '' ? $msg : '전송에 실패했습니다.')];
    }
    return ['ok' => false, 'reason' => '예상하지 못한 응답입니다. (세션 만료이거나 SOOP 방식이 바뀌었을 수 있음)'];
}

/** 등록한 쿠키가 로그인 상태인지 확인 (쪽지함을 열어 봄) */
function note_check(string $cookie): array
{
    if ($cookie === '') {
        return ['valid' => false, 'reason' => '등록된 세션이 없습니다.'];
    }
    [$res, $code] = note_http((string) config('soop_note_check', 'https://note.sooplive.com/app/index.php?page=recv_list'), null, $cookie);
    $valid = $res !== false && $code === 200
        && (mb_strpos($res, '받은 쪽지') !== false || mb_strpos($res, '쪽지함') !== false)
        && mb_strpos($res, '로그인이 필요') === false;
    return ['valid' => $valid, 'reason' => $valid ? '세션이 유효합니다.' : '로그인 세션이 아닙니다. 쿠키를 다시 확인해 주세요.'];
}
