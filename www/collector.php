<?php
/**
 * 실시간 수집 화면
 * 이 창을 켜두는 동안 SOOP 채팅·후원을 받아 서버와 브라우저(백업)에 동시에 저장합니다.
 */
require __DIR__ . '/app/bootstrap.php';

$admin = require_login();
$b = load_broadcast(input_int('id'));

page_header('실시간 수집 · ' . $b['title'], ['menu' => 'broadcasts', 'broadcast' => $b, 'tab' => 'collector', 'wide' => true]);
?>
<div id="collector" data-broadcast-id="<?= (int) $b['id'] ?>" data-streamer-id="<?= h($b['streamer_id']) ?>">

  <div class="card">
    <div class="collector-controls">
      <label>수집 PC 이름 <input id="c-label" maxlength="50" placeholder="예: 사무실 PC 1"></label>
      <label class="check"><input type="checkbox" id="c-keepwait" checked> 방송이 꺼져도 재시작을 기다리며 계속 대기</label>
      <div class="actions">
        <button type="button" id="c-start" class="btn primary">수집 시작</button>
        <button type="button" id="c-stop" class="btn" disabled>수집 중지</button>
      </div>
    </div>
    <div class="collector-status">
      <span id="c-dot" class="dot"></span>
      <strong id="c-state">대기</strong>
      <span id="c-message" class="muted">[수집 시작]을 누르면 SOOP 방송국 <strong><?= h($b['streamer_id']) ?></strong> 의 채팅을 받기 시작합니다.</span>
    </div>
    <div id="c-title" class="muted small"></div>
  </div>

  <div id="c-auth" class="alert alert-warn hidden">
    로그인이 만료되어 서버 저장이 잠시 멈췄습니다. 받은 채팅은 이 브라우저에 보관 중입니다.
    <a href="login.php" target="_blank" rel="noopener">새 탭에서 다시 로그인</a>하면 자동으로 이어서 저장합니다. (이 창은 닫지 마세요)
  </div>
  <div id="c-noidb" class="alert alert-warn hidden">
    이 브라우저에서는 백업 저장을 쓸 수 없습니다. (시크릿 창이거나 저장 공간이 막혀 있음) 일반 창에서 여는 것을 권장합니다.
  </div>

  <div class="tiles">
    <div class="tile"><div class="tile-label">채팅</div><div class="tile-value" id="s-chat">0</div><div class="tile-sub">이번 수집</div></div>
    <div class="tile"><div class="tile-label">별풍선</div><div class="tile-value" id="s-balloon">0</div><div class="tile-sub">개</div></div>
    <div class="tile"><div class="tile-label">애드벌룬</div><div class="tile-value" id="s-ad">0</div><div class="tile-sub">개</div></div>
    <div class="tile"><div class="tile-label">구독</div><div class="tile-value" id="s-sub">0</div><div class="tile-sub">건 (선물 포함)</div></div>
  </div>

  <div class="card">
    <h2>저장 상태</h2>
    <div class="savebar">
      <span>서버 저장 <strong id="s-saved">0</strong>건</span>
      <span>미전송 <strong id="s-pending">0</strong>건</span>
      <span>중복 제외 <strong id="s-dup">0</strong>건</span>
      <span>마지막 저장 <strong id="s-last">-</strong></span>
    </div>
    <div class="savebar">
      <span>브라우저 백업 <strong id="s-backup">0</strong>건</span>
      <button type="button" id="b-download" class="btn small">백업 파일 내려받기</button>
      <button type="button" id="b-clear" class="btn small">서버 저장 끝난 백업 비우기</button>
    </div>
    <p class="muted small">받은 채팅·후원은 서버와 이 브라우저에 동시에 저장됩니다. 서버 저장이 실패해도 브라우저에 남아 있다가 자동으로 다시 보내고,
      필요하면 백업 파일로 내려받아 [백업 업로드] 탭에서 올릴 수 있습니다.</p>
  </div>

  <div class="grid2 feeds">
    <div class="card">
      <h2>채팅 <span class="muted small">최근 150건</span></h2>
      <ul id="feed-chat" class="feed"></ul>
    </div>
    <div class="card">
      <h2>후원 <span class="muted small">최근 100건</span></h2>
      <ul id="feed-don" class="feed"></ul>
    </div>
  </div>

  <div class="card">
    <h2>연결 기록</h2>
    <ul id="c-log" class="log"></ul>
  </div>

  <div class="card muted small">
    <strong>사용 안내</strong>
    <ul>
      <li>이 창을 닫거나 새로고침하면 수집이 멈춥니다. 수집 중에는 창을 닫지 마세요. (다른 탭으로 옮겨도 수집은 계속됩니다)</li>
      <li>노트북은 전원을 연결하고 절전 모드를 꺼두세요. 수집 중에는 화면 꺼짐을 막도록 요청합니다.</li>
      <li>안정성을 위해 PC 두 대에서 같은 회차를 동시에 수집해도 됩니다. 겹치는 채팅은 서버에서 자동으로 한 번만 저장합니다.</li>
      <li>비밀번호 방송, 19세 방송, 구독플러스 전용 방송은 아직 지원하지 않습니다.</li>
    </ul>
  </div>
</div>
<?php
page_footer(['assets/soop-chat.js', 'assets/collector.js']);
