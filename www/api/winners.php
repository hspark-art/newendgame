<?php
/**
 * [수집 창 전용] 시청자별 당첨 기록 + 상품 목록
 * 채팅 닉네임 옆 당첨 아이콘과 "당첨 지명" 경고에 씁니다.
 */
define('API_REQUEST', true);
require dirname(__DIR__) . '/app/bootstrap.php';
require APP_DIR . '/prizes.php';

require_login();
session_write_close();

$map = [];
foreach (winners_by_user() as $userId => $list) {
    // 짧은 형태: [아이콘, 색, 상품, 날짜, 상품번호]
    $map[$userId] = array_map(fn($w) => [$w['icon'], $w['color'], $w['prize'], $w['date'], $w['item_id']], $list);
}
$items = array_map(fn($it) => [
    'id' => (int) $it['id'], 'name' => $it['name'], 'icon' => $it['icon'], 'color' => $it['color'],
], prize_items(true));

json_response(['ok' => true, 'winners' => $map, 'items' => $items, 'note_url' => setting_get('note_url')]);
