<?php
/**
 * CSV 내려받기 (엑셀에서 바로 열 수 있게 UTF-8 BOM 포함)
 * type = chats | donations | donation_log | activity | prizes | cumulative
 */
require __DIR__ . '/app/bootstrap.php';
require APP_DIR . '/stats.php';
require APP_DIR . '/prizes.php';

$admin = require_login();
$type = input_str('type', '', 20);

/** 엑셀 수식으로 해석되지 않도록 앞에 ' 를 붙입니다. */
function csv_cell(mixed $v): string
{
    $v = (string) $v;
    if ($v !== '' && str_contains('=+-@', $v[0]) && !is_numeric($v)) {
        $v = "'" . $v;
    }
    return $v;
}

function csv_start(string $filename): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header("Content-Disposition: attachment; filename=\"export.csv\"; filename*=UTF-8''" . rawurlencode($filename));
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF";
}

function csv_row(array $cells): void
{
    static $out = null;
    $out ??= fopen('php://output', 'w');
    fputcsv($out, array_map('csv_cell', $cells), ',', '"', '');
}

$stamp = date('Ymd-His');

switch ($type) {
    case 'chats': {
        $b = load_broadcast(input_int('id'));
        $id = (int) $b['id'];
        $where = 'broadcast_id = ?';
        $params = [$id];
        if (($q = input_str('q', '', 100)) !== '') { $where .= ' AND message LIKE ?'; $params[] = "%$q%"; }
        if (($u = input_str('user', '', 100)) !== '') { $where .= ' AND (user_id = ? OR nickname LIKE ?)'; $params[] = $u; $params[] = "%$u%"; }
        if ($from = input_datetime('from')) { $where .= ' AND sent_at >= ?'; $params[] = $from; }
        if ($to = input_datetime('to')) { $where .= ' AND sent_at <= ?'; $params[] = $to; }
        audit('export', "broadcast:$id", '채팅 CSV');
        csv_start("채팅_{$b['broadcast_date']}_{$stamp}.csv");
        csv_row(['시간', '아이디', '접속아이디', '닉네임', '내용', '종류', '방송인', '매니저', '구독', '팬', '열혈']);
        @set_time_limit(600);
        db_stream("SELECT sent_at, user_id, raw_user_id, nickname, message, kind, badges FROM chat_messages WHERE $where ORDER BY sent_at, id", $params, function (array $r) {
            $bd = (int) $r['badges'];
            csv_row([$r['sent_at'], $r['user_id'], $r['raw_user_id'], $r['nickname'], $r['message'], $r['kind'] === 'emoticon' ? '이모티콘' : '채팅',
                $bd & BADGE_BJ ? 'Y' : '', $bd & BADGE_MANAGER ? 'Y' : '', $bd & BADGE_SUBSCRIBER ? 'Y' : '', $bd & BADGE_FAN ? 'Y' : '', $bd & BADGE_TOPFAN ? 'Y' : '']);
        });
        break;
    }

    case 'donations': {
        $b = load_broadcast(input_int('id'));
        $id = (int) $b['id'];
        $sort = input_str('sort', 'balloon', 20);
        $data = donation_ranking($id, stats_filters(), isset(DONATION_SORTS[$sort]) ? $sort : 'balloon', max(0, input_int('min', 0)), null);
        audit('export', "broadcast:$id", '후원 순위 CSV');
        csv_start("후원순위_{$b['broadcast_date']}_{$stamp}.csv");
        csv_row(['순위', '아이디', '닉네임', '별풍선', '애드벌룬', '구독', '구독 선물', '첫 후원', '마지막 후원']);
        foreach ($data['rows'] as $i => $r) {
            csv_row([$i + 1, $r['user_id'], $r['nickname'], $r['balloons'], $r['adballoons'], $r['subs'], $r['gifts'], $r['first_at'], $r['last_at']]);
        }
        break;
    }

    case 'donation_log': {
        $b = load_broadcast(input_int('id'));
        $id = (int) $b['id'];
        $f = stats_filters();
        $params = [$id];
        $where = 'broadcast_id = ?' . time_where($f, $params);
        $dtype = input_str('dtype', '', 20);
        if (in_array($dtype, ['balloon', 'adballoon', 'subscription'], true)) { $where .= ' AND type = ?'; $params[] = $dtype; }
        if ($f['q'] !== '') { $where .= ' AND (user_id = ? OR nickname LIKE ? OR target_user_id = ?)'; array_push($params, $f['q'], '%' . $f['q'] . '%', $f['q']); }
        audit('export', "broadcast:$id", '후원 기록 CSV');
        csv_start("후원기록_{$b['broadcast_date']}_{$stamp}.csv");
        csv_row(['시간', '종류', '수량', '아이디', '접속아이디', '닉네임', '받은 사람 아이디', '받은 사람 닉네임', '비고']);
        foreach (db_all("SELECT * FROM donations WHERE $where ORDER BY sent_at, id", $params) as $r) {
            csv_row([$r['sent_at'], donation_type_label($r['type'], $r['subtype']), $r['amount'], $r['user_id'], $r['raw_user_id'], $r['nickname'], $r['target_user_id'], $r['target_nickname'], $r['extra']]);
        }
        break;
    }

    case 'activity': {
        $b = load_broadcast(input_int('id'));
        $id = (int) $b['id'];
        $rule = activity_rule_from_request();
        $sort = input_str('sort', 'effective', 20);
        $data = activity_ranking($id, stats_filters(), $rule, isset(ACTIVITY_SORTS[$sort]) ? $sort : 'effective', max(0, input_int('min', 0)), null);
        audit('export', "broadcast:$id", '채팅 활동량 CSV');
        csv_start("채팅활동량_{$b['broadcast_date']}_{$stamp}.csv");
        csv_row(['순위', '아이디', '닉네임', '인정 채팅', '전체 채팅', "활동 구간({$rule['slot']}분)", '첫 채팅', '마지막 채팅']);
        foreach ($data['rows'] as $i => $r) {
            csv_row([$i + 1, $r['user_id'], $r['nickname'], $r['effective'], $r['total'], $r['slots'], $r['first_at'], $r['last_at']]);
        }
        break;
    }

    case 'prizes': {
        $isAdmin = $admin['role'] === 'admin';
        [$where, $params] = prize_list_where(input_int('broadcast_id'), input_str('status', '', 20), input_str('prize', '', 200), input_str('note', '', 10), input_str('q', '', 100));
        audit('export', 'prizes', $isAdmin ? '상품 지급 CSV (수령자 정보 포함)' : '상품 지급 CSV (정보 가림)');
        csv_start("상품지급_{$stamp}.csv");
        csv_row(['방송일', '회차', '아이디', '닉네임', '선정 사유', '상품', '유형', '상태', '쪽지', '수령자 이름', '연락처', '주소', '정보 제출 기한', '지급일', '메모', '등록일']);
        foreach (db_all("SELECT p.*, b.title, b.broadcast_date FROM prizes p LEFT JOIN broadcasts b ON b.id = p.broadcast_id WHERE $where ORDER BY p.created_at, p.id", $params) as $p) {
            $name = pii_decrypt($p['recipient_name']);
            $phone = pii_decrypt($p['recipient_phone']);
            $addr = pii_decrypt($p['recipient_address']);
            if (!$isAdmin) {
                [$name, $phone, $addr] = [mask_name($name), mask_phone($phone), mask_address($addr)];
            }
            csv_row([$p['broadcast_date'], $p['title'], $p['user_id'], $p['nickname'], $p['reason'], $p['prize_name'], PRIZE_TYPES[$p['prize_type']] ?? '',
                PRIZE_STATUSES[$p['status']] ?? $p['status'], $p['note_sent_at'] ? '보냄 ' . substr($p['note_sent_at'], 0, 16) : ($p['note_result'] ? '실패: ' . $p['note_result'] : ''), $name, $phone, $addr, $p['due_date'], $p['paid_at'], $p['memo'], $p['created_at']]);
        }
        break;
    }

    case 'cumulative': {
        $data = cumulative_ranking(input_int('weeks', 8), !isset($_GET['submitted']) || isset($_GET['exclude']), input_str('q', '', 100), input_str('sort', 'streak', 20));
        audit('export', 'cumulative', "누적 순위 CSV ({$data['weeks']}주)");
        csv_start("누적순위_{$data['weeks']}주_{$stamp}.csv");
        csv_row(['순위', '아이디', '닉네임', '연속 출석', '참여 회차', '기간 채팅', '기간 별풍선', '기간 애드벌룬']);
        foreach ($data['rows'] as $i => $r) {
            csv_row([$i + 1, $r['user_id'], $r['nickname'], $r['streak'], $r['attend'], $r['chats'], $r['balloons'], $r['adballoons']]);
        }
        break;
    }

    default:
        render_error('내려받기 종류가 올바르지 않습니다.');
}
