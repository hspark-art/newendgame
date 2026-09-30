<?php
declare(strict_types=1);

/**
 * 웹 버전 관리자: 계정 승인·정지·역할, 초대 링크, 재설정 링크, 비밀 송출 주소, 시스템 정보·무결성 검사.
 * 다른 사람의 비밀번호를 볼 수 있는 기능은 없다.
 */
require __DIR__ . '/app/bootstrap.php';

portal_start();
$admin = auth_require_admin();
$me = auth_user_optional();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    form_check();
    $id = (int)($_POST['id'] ?? 0);
    try {
        switch (post_str('do', 30)) {
            case 'invite':
                $t = auth_invite_create(post_str('label', 120), (int)($_POST['hours'] ?? 72), $admin);
                $_SESSION['new_link'] = ['초대 링크', base_url() . '/invite.php?token=' . $t];
                break;
            case 'revoke':
                auth_link_revoke($id, $admin);
                flash('ok', '링크를 취소했습니다.');
                break;
            case 'reset':
                $t = auth_reset_create($id, $admin);
                $_SESSION['new_link'] = ['비밀번호 재설정 링크 (1시간 유효)', base_url() . '/reset.php?token=' . $t];
                break;
            case 'user':
                auth_user_update($id, post_str('action', 20), $admin);
                flash('ok', '계정을 변경했습니다.');
                break;
            case 'rotate':
                output_token_rotate($admin);
                flash('ok', '송출 주소를 새로 만들었습니다. OBS/vMix에 새 주소를 다시 넣어야 합니다.');
                break;
            case 'verify':
                $_SESSION['verify'] = release_verify();
                break;
        }
    } catch (ActionError $e) {
        flash('err', $e->getMessage());
    }
    redirect('admin.php');
}

$newLink = $_SESSION['new_link'] ?? null;
$verify = $_SESSION['verify'] ?? null;
unset($_SESSION['new_link'], $_SESSION['verify']);
$users = db_all('SELECT * FROM cg_users ORDER BY CASE status WHEN \'pending\' THEN 0 ELSE 1 END, id');
$links = db_all("SELECT l.*, u.username AS user_name FROM cg_links l LEFT JOIN cg_users u ON u.id = l.user_id
    ORDER BY l.id DESC LIMIT 30");
$info = release_info();
$authLogs = db_all("SELECT * FROM cg_logs WHERE type = 'auth' ORDER BY id DESC LIMIT 30");
$statusName = ['pending' => '승인 대기', 'active' => '사용 중', 'suspended' => '정지', 'rejected' => '거절'];
$outputUrl = base_url() . '/output.php?t=' . rawurlencode((string)setting_get('output_token')) . '&layer=1';

/** 계정 작업 버튼 하나 */
$btn = static function (int $id, string $action, string $label, string $confirm = '') {
    return '<form method="post" class="inline"' . ($confirm !== '' ? ' data-confirm="' . h($confirm) . '"' : '') . '>'
        . csrf_field() . '<input type="hidden" name="do" value="user"><input type="hidden" name="id" value="' . $id . '">'
        . '<input type="hidden" name="action" value="' . h($action) . '"><button class="sm">' . h($label) . '</button></form>';
};

portal_head('관리자', $me, true);
if ($info['install_php_exists']) {
    echo '<p class="msg err">보안: 서버에 <b>install.php</b> 파일이 남아 있습니다. FTP로 삭제하세요.</p>';
}
if ($newLink !== null): ?>
  <div class="card newlink">
    <b><?= h($newLink[0]) ?></b> — 지금 한 번만 표시됩니다. 복사해서 대상자에게 직접 전달하세요.
    <code class="copy" tabindex="0"><?= h($newLink[1]) ?></code>
  </div>
<?php endif ?>

<section>
  <h2>계정</h2>
  <table>
    <thead><tr><th>아이디</th><th>이름</th><th>역할</th><th>상태</th><th>가입</th><th>마지막 로그인</th><th>작업</th></tr></thead>
    <tbody>
<?php foreach ($users as $u): $uid = (int)$u['id']; $self = $uid === $admin['user_id']; ?>
      <tr class="st-<?= h($u['status']) ?>">
        <td><?= h($u['username']) ?><?= $self ? ' <span class="tag">나</span>' : '' ?></td>
        <td><?= h($u['name']) ?></td>
        <td><?= $u['role'] === 'admin' ? '관리자' : '운영자' ?></td>
        <td><span class="st"><?= h($statusName[$u['status']] ?? $u['status']) ?></span></td>
        <td><?= h(substr($u['created_at'], 0, 16)) ?></td>
        <td><?= h(substr((string)$u['last_login_at'], 0, 16)) ?></td>
        <td class="actions">
<?php if (!$self): ?>
          <?php if ($u['status'] === 'pending'): ?><?= $btn($uid, 'approve', '승인') ?><?= $btn($uid, 'reject', '거절', '가입을 거절할까요?') ?><?php endif ?>
          <?php if ($u['status'] === 'active'): ?><?= $btn($uid, 'suspend', '정지', '이 계정을 정지할까요? 즉시 로그아웃됩니다.') ?>
            <?= $u['role'] === 'admin' ? $btn($uid, 'make_operator', '운영자로') : $btn($uid, 'make_admin', '관리자로', '관리자 권한을 줄까요?') ?><?php endif ?>
          <?php if (in_array($u['status'], ['suspended', 'rejected'], true)): ?><?= $btn($uid, 'activate', '다시 사용') ?><?php endif ?>
          <form method="post" class="inline" data-confirm="재설정 링크를 만들까요? 이전에 만든 재설정 링크는 무효가 됩니다."><?= csrf_field() ?>
            <input type="hidden" name="do" value="reset"><input type="hidden" name="id" value="<?= $uid ?>"><button class="sm">재설정 링크</button></form>
<?php else: ?>
          <span class="muted">자기 계정은 내 계정 화면에서</span>
<?php endif ?>
        </td>
      </tr>
<?php endforeach ?>
    </tbody>
  </table>
</section>

<section>
  <h2>초대 링크</h2>
  <form method="post" class="row">
    <?= csrf_field() ?><input type="hidden" name="do" value="invite">
    <label>메모 <input name="label" maxlength="100" placeholder="예: 박작가 (중계 작가)"></label>
    <label>유효 시간 <select name="hours"><option value="24">1일</option><option value="72" selected>3일</option><option value="168">7일</option></select></label>
    <button class="primary">초대 링크 만들기</button>
  </form>
  <p class="hint">링크를 가진 사람은 누구나 가입 신청을 할 수 있습니다. 승인 전에 이름·아이디가 실제 대상자인지 확인하세요.</p>
  <table>
    <thead><tr><th>종류</th><th>메모/대상</th><th>만든 시각</th><th>만료</th><th>상태</th><th></th></tr></thead>
    <tbody>
<?php foreach ($links as $l):
    $state = $l['used_at'] !== null ? '사용됨' . ($l['user_name'] ? ' (' . $l['user_name'] . ')' : '')
        : ($l['revoked_at'] !== null ? '취소됨' : (strtotime($l['expires_at']) <= time() ? '만료' : '사용 가능')); ?>
      <tr>
        <td><?= $l['kind'] === 'invite' ? '초대' : '재설정' ?></td>
        <td><?= h($l['label']) ?></td>
        <td><?= h(substr($l['created_at'], 0, 16)) ?></td>
        <td><?= h(substr($l['expires_at'], 0, 16)) ?></td>
        <td><?= h($state) ?></td>
        <td><?php if ($state === '사용 가능'): ?><form method="post" class="inline"><?= csrf_field() ?>
          <input type="hidden" name="do" value="revoke"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><button class="sm">취소</button></form><?php endif ?></td>
      </tr>
<?php endforeach ?>
    </tbody>
  </table>
</section>

<section>
  <h2>비밀 송출 주소</h2>
  <p>OBS/vMix 브라우저 소스(1920 × 1080)에 넣는 주소입니다. 로그인 없이 <b>송출 화면만</b> 볼 수 있습니다. 밖으로 공유하지 마세요.</p>
  <code class="copy" tabindex="0"><?= h($outputUrl) ?></code>
  <form method="post" class="row" data-confirm="송출 주소를 새로 만들까요? 지금 OBS/vMix에 넣은 주소는 즉시 동작하지 않습니다.">
    <?= csrf_field() ?><input type="hidden" name="do" value="rotate"><button class="danger">송출 주소 재발급</button>
  </form>
</section>

<section>
  <h2>시스템 정보</h2>
  <p>버전 <b><?= h($info['version']) ?></b> · DB <b><?= h($info['db']) ?></b> (구조 <?= (int)$info['schema'] ?>) · PHP <?= h($info['php']) ?></p>
  <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="verify"><button>파일 무결성 검사</button></form>
<?php if ($verify !== null): ?>
  <?php if ($verify['status'] === 'no_manifest'): ?>
    <p class="msg">manifest 파일이 없습니다 (개발용 설치). 배포 zip으로 설치하면 검사할 수 있습니다.</p>
  <?php elseif ($verify['status'] === 'ok'): ?>
    <p class="msg ok">파일 <?= (int)$verify['checked'] ?>개 모두 배포본과 같습니다.</p>
  <?php else: ?>
    <p class="msg err">배포본과 다른 파일이 있습니다. 해당 파일을 다시 업로드하세요.</p>
    <ul class="files">
      <?php foreach ($verify['missing'] as $f): ?><li>없음: <?= h($f) ?></li><?php endforeach ?>
      <?php foreach ($verify['changed'] as $f): ?><li>다름: <?= h($f) ?></li><?php endforeach ?>
    </ul>
  <?php endif ?>
<?php endif ?>
</section>

<section>
  <h2>계정 기록</h2>
  <table class="log">
<?php foreach ($authLogs as $l): ?>
    <tr><td><?= h($l['created_at']) ?></td><td><?= h($l['action']) ?></td><td><?= h($l['operator']) ?></td><td><?= h((string)$l['detail']) ?></td></tr>
<?php endforeach ?>
  </table>
</section>
<script src="<?= h(asset_url('portal.js')) ?>"></script>
<?php
portal_foot();
