<?php
/**
 * 실시간 수집
 *  - 탭 화면(기본): 수집 창을 여는 안내 화면. 수집은 별도 창에서 하므로 메인 창에서는 다른 메뉴를 자유롭게 씁니다.
 *  - 수집 창(popup=1): SOOP 채팅·후원을 받아 서버와 브라우저(백업)에 동시에 저장합니다.
 */
require __DIR__ . '/app/bootstrap.php';

$admin = require_login();
$b = load_broadcast(input_int('id'));
$id = (int) $b['id'];
$popup = input_str('popup', '', 1) === '1';

// ── 탭 화면: 수집 창 열기 안내 ─────────────────────────────
if (!$popup) {
    $live = db_all(
        "SELECT label, status, chat_count, donation_count, last_seen_at FROM collectors
         WHERE broadcast_id = ? AND status <> 'imported' AND last_seen_at >= ? ORDER BY last_seen_at DESC",
        [$id, date('Y-m-d H:i:s', time() - 60)]
    );
    page_header('실시간 수집 · ' . $b['title'], ['menu' => 'broadcasts', 'broadcast' => $b, 'tab' => 'collector']);
    ?>
    <div class="card launch-card">
      <h2>실시간 수집은 별도 창에서 진행합니다</h2>
      <p class="muted">수집 창을 켜두는 동안 이 창에서는 후원 순위·채팅 활동량·상품 지급 등 다른 메뉴를 자유롭게 쓸 수 있습니다.
        수집 중에는 모든 화면 위쪽에 <span class="live-ind-sample">● 수집 중</span> 표시가 나타나고, 누르면 수집 창으로 이동합니다.</p>
      <p>
        <a class="btn primary big" href="collector.php?id=<?= $id ?>&amp;popup=1" target="collector-<?= $id ?>"
           data-popup="collector.php?id=<?= $id ?>&amp;popup=1" data-popup-name="collector-<?= $id ?>">수집 창 열기</a>
      </p>
      <p class="muted small">창이 열리지 않으면 브라우저 주소창 오른쪽의 팝업 차단 표시를 눌러 허용해 주세요.</p>
    </div>
    <div class="card">
      <h2>지금 이 회차를 수집 중인 창</h2>
      <?php if ($live): ?>
        <table class="table compact">
          <thead><tr><th>수집 PC</th><th>상태</th><th>마지막 통신</th><th class="num">채팅</th><th class="num">후원</th></tr></thead>
          <?php foreach ($live as $c): ?>
            <tr><td><?= h($c['label'] !== '' ? $c['label'] : '(이름 없음)') ?></td>
              <td><?= $c['status'] === 'live' ? '<span class="dot live"></span> 수집 중' : h($c['status']) ?></td>
              <td class="muted small"><?= fmt_dt($c['last_seen_at']) ?></td>
              <td class="num"><?= fmt_num($c['chat_count']) ?></td><td class="num"><?= fmt_num($c['donation_count']) ?></td></tr>
          <?php endforeach; ?>
        </table>
      <?php else: ?>
        <p class="muted">수집 중인 창이 없습니다.</p>
      <?php endif; ?>
    </div>
    <?php
    page_footer();
    exit;
}

// ── 수집 창 ────────────────────────────────────────────────
page_header('수집 · ' . $b['title'], ['bare' => true, 'wide' => true, 'body_class' => 'collector-window']);
?>
<div id="collector" data-broadcast-id="<?= $id ?>" data-streamer-id="<?= h($b['streamer_id']) ?>">

  <header class="cw-head">
    <div class="cw-title">
      <span id="c-dot" class="dot"></span>
      <strong id="c-state">대기</strong>
      <span class="cw-bc"><?= h($b['title']) ?> <span class="muted">· <?= h($b['streamer_id']) ?></span></span>
    </div>
    <div class="cw-actions">
      <label class="cw-label">수집 PC <input id="c-label" maxlength="50" placeholder="예: 사무실 PC 1"></label>
      <label class="check" title="방송이 꺼져도 재시작을 기다리며 계속 대기"><input type="checkbox" id="c-keepwait" checked> 재시작 대기</label>
      <button type="button" id="c-start" class="btn primary">수집 시작</button>
      <button type="button" id="c-stop" class="btn" disabled>수집 중지</button>
      <a class="btn" href="index.php" id="c-main" target="endgame-main">메인 창</a>
    </div>
  </header>
  <div id="c-message" class="cw-msg muted">[수집 시작]을 누르면 SOOP 방송국 <strong><?= h($b['streamer_id']) ?></strong> 의 채팅을 받기 시작합니다.</div>
  <div id="c-title" class="cw-msg muted small"></div>

  <div id="c-auth" class="alert alert-warn hidden">
    로그인이 만료되어 서버 저장이 잠시 멈췄습니다. 받은 채팅은 이 브라우저에 보관 중입니다.
    <a href="login.php" target="endgame-main">메인 창에서 다시 로그인</a>하면 자동으로 이어서 저장합니다. (이 창은 닫지 마세요)
  </div>
  <div id="toast" class="toast hidden"></div>
  <div id="c-noidb" class="alert alert-warn hidden">
    이 브라우저에서는 백업 저장을 쓸 수 없습니다. (시크릿 창이거나 저장 공간이 막혀 있음) 일반 창에서 여는 것을 권장합니다.
  </div>

  <div class="cw-stats">
    <span>채팅 <strong id="s-chat">0</strong></span>
    <span class="gold">별풍선 <strong id="s-balloon">0</strong></span>
    <span class="teal">애드벌룬 <strong id="s-ad">0</strong></span>
    <span class="purple">구독 <strong id="s-sub">0</strong></span>
    <span class="sep"></span>
    <span>서버 저장 <strong id="s-saved">0</strong></span>
    <span>미전송 <strong id="s-pending">0</strong></span>
    <span>중복 제외 <strong id="s-dup">0</strong></span>
    <span>마지막 저장 <strong id="s-last">-</strong></span>
  </div>

  <div class="cw-grid">
    <section class="card chat-card">
      <div class="card-head">
        <h2>실시간 채팅</h2>
        <div class="chat-tools">
          <label class="check small"><input type="checkbox" id="opt-time" checked> 시각</label>
          <label class="zoom small" title="글자 크기">가 <input type="range" id="opt-zoom" min="50" max="200" step="10" value="100"> <span id="opt-zoom-v">100%</span></label>
          <button type="button" class="btn small" id="chat-clear" title="화면에서만 지웁니다 (저장된 기록은 그대로)">화면 지우기</button>
        </div>
      </div>
      <div class="chatwrap">
        <div id="feed-chat" class="chatbox" aria-live="off"></div>
        <button type="button" id="chat-new" class="newbtn hidden">새 채팅 <span>0</span>개 ↓</button>
      </div>
      <p class="muted small">오래된 채팅이 위, 새 채팅이 아래로 이어집니다. 위로 올려 읽는 동안에는 자동으로 내려가지 않습니다. 채팅창 아래 모서리를 끌어 높이를 바꿀 수 있습니다.</p>
    </section>

    <aside class="cw-side">
      <section class="card nominate" id="nominate">
        <div class="card-head"><h2>당첨 지명</h2><span class="muted small">채팅 줄을 누르면 선택됩니다</span></div>
        <p id="nm-empty" class="muted small">채팅창에서 시청자 줄을 누르면 여기서 바로 당첨 등록할 수 있습니다. 이름 옆 ✉ 는 아이디 복사 + 쪽지 창 열기입니다.</p>
        <div id="nm-body" class="hidden">
          <div class="nm-user"><span id="nm-badges"></span><b id="nm-nick" class="nk"></b> <span id="nm-id" class="muted small"></span>
            <button type="button" id="nm-dm" class="btn small">✉ 쪽지</button></div>
          <div id="nm-wins" class="nm-wins"></div>
          <div id="nm-warn" class="nm-warn"></div>
          <div class="nm-form">
            <label>상품 <select id="nm-item"><option value="">(직접 입력)</option></select></label>
            <label>직접 입력 <input id="nm-prize" maxlength="200" placeholder="목록에 없을 때만"></label>
            <label>선정 사유 <input id="nm-reason" maxlength="200" value="채팅 지명"></label>
            <button type="button" id="nm-save" class="btn primary">당첨 등록</button>
          </div>
          <div id="nm-result" class="small"></div>
        </div>
      </section>

      <section class="card">
        <div class="card-head"><h2>후원</h2><span class="muted small">최근 300건</span></div>
        <div class="chatwrap">
          <div id="feed-don" class="chatbox donbox"></div>
          <button type="button" id="don-new" class="newbtn hidden">새 후원 <span>0</span>건 ↓</button>
        </div>
      </section>

      <section class="card">
        <h2>백업</h2>
        <div class="savebar">
          <span>브라우저 백업 <strong id="s-backup">0</strong>건</span>
        </div>
        <div class="actions">
          <button type="button" id="b-download" class="btn small">백업 파일 내려받기</button>
          <button type="button" id="b-clear" class="btn small">서버 저장 끝난 백업 비우기</button>
        </div>
        <p class="muted small">받은 채팅·후원은 서버와 이 브라우저에 동시에 저장됩니다. 서버 저장이 실패해도 자동으로 다시 보내고, 필요하면 백업 파일을 [백업 업로드]에 올릴 수 있습니다.</p>
      </section>

      <details class="card">
        <summary>연결 기록</summary>
        <ul id="c-log" class="log"></ul>
      </details>

      <details class="card muted small">
        <summary>사용 안내</summary>
        <ul>
          <li>이 창을 닫거나 새로고침하면 수집이 멈춥니다. 다른 작업은 메인 창에서 하세요.</li>
          <li>노트북은 전원을 연결하고 절전 모드를 꺼두세요. 수집 중에는 화면 꺼짐을 막도록 요청합니다.</li>
          <li>PC 두 대에서 같은 회차를 동시에 수집해도 됩니다. 겹치는 기록은 한 번만 저장됩니다.</li>
          <li>비밀번호 방송, 19세 방송, 구독플러스 전용 방송은 아직 지원하지 않습니다.</li>
        </ul>
      </details>
    </aside>
  </div>
</div>
<?php
page_footer(['assets/soop-chat.js', 'assets/collector.js']);
