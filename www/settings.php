<?php
/**
 * [관리자] 설정 — 쪽지 주소, 쪽지 문안 4종, SOOP 쪽지 서버 발송용 로그인 세션
 */
require __DIR__ . '/app/bootstrap.php';
require APP_DIR . '/prizes.php';
require APP_DIR . '/note.php';

require_admin_role();

if (is_post()) {
    csrf_check();
    $action = input_str('action', '', 20);

    if ($action === 'urls') {
        $noteUrl = input_str('note_url', '', 500);
        $writeUrl = input_str('note_write_url', '', 500);
        foreach (['쪽지 링크' => $noteUrl, '쪽지 쓰기창 주소' => $writeUrl] as $label => $url) {
            if ($url !== '' && !preg_match('#^https?://#i', $url)) {
                flash('error', "$label 는 http:// 또는 https:// 로 시작해야 합니다.");
                redirect('settings.php');
            }
        }
        setting_set('note_url', $noteUrl);
        setting_set('note_write_url', $writeUrl);
        audit('setting_update', 'note_url', $noteUrl . ' / ' . $writeUrl);
        flash('success', '쪽지 주소를 저장했습니다.');
    } elseif ($action === 'templates') {
        $defaults = default_note_templates();
        $saved = [];
        foreach (array_keys($defaults) as $k) {
            $text = str_replace("\r\n", "\n", (string) ($_POST['tpl'][$k] ?? ''));
            $saved[$k] = mb_substr($text, 0, 5000);
        }
        setting_set('note_templates', json_encode($saved, JSON_UNESCAPED_UNICODE));
        audit('setting_update', 'note_templates');
        flash('success', '쪽지 문안을 저장했습니다.');
    } elseif ($action === 'templates_reset') {
        $key = input_str('key', '', 10);
        $saved = note_templates();
        if (isset($saved[$key])) {
            $saved[$key] = default_note_templates()[$key];
            setting_set('note_templates', json_encode($saved, JSON_UNESCAPED_UNICODE));
            audit('setting_update', 'note_templates', "$key 기본값으로");
            flash('success', "'" . NOTE_TYPES[$key] . "' 문안을 기본값으로 되돌렸습니다.");
        }
    } elseif ($action === 'cookie_save') {
        $cookie = trim(preg_replace('/^cookie:\s*/i', '', str_replace(["\r", "\n"], '', (string) ($_POST['cookie'] ?? ''))));
        if ($cookie === '' || strlen($cookie) > 8000 || !str_contains($cookie, '=')) {
            flash('error', '쿠키 값을 확인해 주세요. (이름=값; 이름=값 … 형식)');
            redirect('settings.php#note-session');
        }
        setting_set('note_cookie', (string) pii_encrypt($cookie));
        setting_set('note_cookie_saved_at', now());
        audit('note_cookie_save', 'note_cookie', '세션 등록');
        $r = note_check($cookie);
        flash($r['valid'] ? 'success' : 'error', '세션을 암호화해 저장했습니다. 확인 결과: ' . $r['reason']);
    } elseif ($action === 'cookie_check') {
        $r = note_check(note_cookie());
        audit('note_cookie_check', 'note_cookie', $r['valid'] ? '유효' : '무효');
        flash($r['valid'] ? 'success' : 'error', $r['reason']);
    } elseif ($action === 'cookie_delete') {
        setting_set('note_cookie', '');
        setting_set('note_cookie_saved_at', '');
        audit('note_cookie_delete', 'note_cookie');
        flash('success', '저장된 세션을 지웠습니다.');
    }
    redirect('settings.php' . (str_starts_with($action, 'cookie') ? '#note-session' : ''));
}

$templates = note_templates();
$defaults = default_note_templates();
$hasCookie = note_cookie() !== '';

page_header('설정', ['menu' => 'settings']);
?>
<div class="page-head"><h1>설정</h1></div>

<div class="card">
  <h2>쪽지 주소</h2>
  <form method="post" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="urls">
    <label>✉ 쪽지 링크 (수집 창에서 ✉ 를 누르면 열 주소, <code>{id}</code> = 받는 사람 아이디)
      <input name="note_url" maxlength="500" value="<?= h(setting_get('note_url')) ?>" placeholder="비우면 받는 사람의 SOOP 방송국 페이지를 엽니다 (기존 시스템과 같음)">
    </label>
    <label>쪽지 쓰기창 주소 ([상품 지급] 쪽지 바의 [쪽지 쓰기창 열기])
      <input name="note_write_url" maxlength="500" value="<?= h(setting_get('note_write_url')) ?>" placeholder="<?= h(note_write_url()) ?>">
    </label>
    <div class="actions"><button class="btn primary">저장</button></div>
  </form>
  <p class="muted small">✉ 를 누르면 아이디가 복사되니, 열린 쪽지 창의 받는 사람 칸에 붙여넣기(Ctrl+V) 하면 됩니다.</p>
</div>

<div class="card">
  <h2>쪽지 문안</h2>
  <p class="muted small">쓸 수 있는 칸: <code>{nick}</code> 닉네임 · <code>{id}</code> 아이디 · <code>{prize}</code> 상품 · <code>{date}</code> 등록일.
    상품 지급 화면에서 고른 당첨자의 상품 [쪽지 문안 종류]에 맞는 문안이 자동으로 선택됩니다.</p>
  <form method="post" class="form" id="tpl-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="templates">
    <?php foreach ($templates as $k => $text): ?>
      <label><?= h(NOTE_TYPES[$k] ?? $k) ?><?= $text !== $defaults[$k] ? ' <span class="muted small">(수정됨)</span>' : '' ?>
        <textarea name="tpl[<?= h($k) ?>]" rows="<?= $k === 'blank' ? 3 : 9 ?>" maxlength="5000"><?= h($text) ?></textarea>
      </label>
    <?php endforeach; ?>
    <div class="actions"><button class="btn primary">문안 저장</button></div>
  </form>
  <div class="actions">
    <?php foreach ($templates as $k => $text): if ($text === $defaults[$k]) continue; ?>
      <form method="post" class="inline">
        <?= csrf_field() ?><input type="hidden" name="action" value="templates_reset"><input type="hidden" name="key" value="<?= h($k) ?>">
        <button class="btn small" data-confirm="'<?= h(NOTE_TYPES[$k]) ?>' 문안을 기본값으로 되돌릴까요?">'<?= h(NOTE_TYPES[$k]) ?>' 기본값으로</button>
      </form>
    <?php endforeach; ?>
  </div>
</div>

<div class="card" id="note-session">
  <h2>SOOP 쪽지 서버 발송 (회사 계정 로그인 세션)</h2>
  <p>상태:
    <?php if ($hasCookie): ?>
      <span class="ok">등록됨</span> <span class="muted small">(<?= h(fmt_dt(note_cookie_saved_at(), 'Y-m-d H:i')) ?> 등록, 암호화 저장)</span>
    <?php else: ?>
      <span class="muted">등록 안 됨</span> — [상품 지급]의 [서버로 바로 보내기]를 쓸 수 없습니다. 복사·쪽지 쓰기창 방식은 그대로 쓸 수 있습니다.
    <?php endif; ?>
  </p>
  <?php if ($hasCookie): ?>
  <div class="actions">
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="cookie_check"><button class="btn">세션 확인</button></form>
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="cookie_delete"><button class="btn danger" data-confirm="저장된 세션을 지울까요?">세션 지우기</button></form>
  </div>
  <?php endif; ?>
  <form method="post" class="form" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="cookie_save">
    <label><?= $hasCookie ? '새 세션으로 바꾸기' : '세션 등록' ?> — 쿠키 값
      <textarea name="cookie" rows="3" maxlength="8000" spellcheck="false" placeholder="PdboxTicket=…; PdboxUser=…; …"></textarea>
    </label>
    <div class="actions"><button class="btn primary">암호화해 저장</button></div>
  </form>
  <details class="help">
    <summary>쿠키 값 가져오는 방법</summary>
    <ol class="small">
      <li>Chrome(또는 Edge)에서 회사 SOOP 계정으로 로그인한 뒤 <code>note.sooplive.com</code> 쪽지함을 엽니다.</li>
      <li>F12 → [Network(네트워크)] 탭 → 페이지 새로고침(F5) → 맨 위 요청을 누릅니다.</li>
      <li>[Headers] 의 Request Headers 에서 <code>Cookie:</code> 오른쪽 값을 전부 복사해 위 칸에 붙여넣습니다.</li>
    </ol>
    <p class="muted small">세션은 SOOP 에서 로그아웃하거나 시간이 지나면 만료됩니다. 보내다가 "세션 만료"가 나오면 같은 방법으로 다시 등록해 주세요.
      이 값은 계정 로그인과 같은 효력이 있으니 다른 사람에게 보내지 마세요. SOOP 공식 기능이 아니라서 SOOP 쪽 방식이 바뀌면 서버 발송이 안 될 수 있습니다.</p>
  </details>
</div>
<?php
page_footer();
