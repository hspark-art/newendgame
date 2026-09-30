<?php
/**
 * 지금 수집 중인 창 목록 (모든 화면 위쪽 "● 수집 중" 표시용)
 * 최근 60초 안에 서버와 통신한 수집 창만 돌려줍니다.
 */
define('API_REQUEST', true);
require dirname(__DIR__) . '/app/bootstrap.php';

require_login();
session_write_close();

$rows = db_all(
    "SELECT c.broadcast_id, b.title, c.status, c.chat_count, c.last_seen_at
     FROM collectors c JOIN broadcasts b ON b.id = c.broadcast_id
     WHERE c.last_seen_at >= ? AND c.status IN ('live', 'resolving', 'connecting', 'waiting')
     ORDER BY c.last_seen_at DESC",
    [date('Y-m-d H:i:s', time() - 60)]
);
$byBroadcast = [];
foreach ($rows as $r) {
    $bid = (int) $r['broadcast_id'];
    $item = &$byBroadcast[$bid];
    $item ??= ['broadcast_id' => $bid, 'title' => $r['title'], 'live' => false, 'windows' => 0, 'chat_count' => 0];
    $item['windows']++;
    $item['live'] = $item['live'] || $r['status'] === 'live';
    $item['chat_count'] += (int) $r['chat_count'];
    unset($item);
}
json_response(['ok' => true, 'collecting' => array_values($byBroadcast)]);
