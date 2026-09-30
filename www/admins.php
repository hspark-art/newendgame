<?php
/**
 * 관리자 계정 관리 (admin 전용)
 */
require __DIR__ . '/app/bootstrap.php';

$me = require_admin_role();
const ROLES = ['admin' => '관리자 (전체 권한)', 'staff' => '담당자 (개인정보 가림)'];

if (is_post()) {
    csrf_check();
    $action = input_str('action', '', 20);
    $id = input_int('id');

    if ($action === 'create') {
        $username = input_str('username', '', 50);
        $name = input_str('display_name', '', 50);
        $role = input_str('role', 'staff', 10);
        $password = (string) ($_POST['password'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
            flash('error', '아이디는 영문·숫자·_ . - 조합 3자 이상으로 입력해 주세요.');
        } elseif ($name === '') {
            flash('error', '표시 이름을 입력해 주세요.');
        } elseif ($err = password_policy_error($password)) {
            flash('error', $err);
        } elseif (db_value('SELECT 1 FROM admins WHERE username = ?', [$username])) {
            flash('error', '이미 있는 아이디입니다.');
        } else {
            db_exec(
                'INSERT INTO admins (username, password_hash, display_name, role, created_at) VALUES (?, ?, ?, ?, ?)',
                [$username, password_hash($password, PASSWORD_DEFAULT), $name, isset(ROLES[$role]) ? $role : 'staff', now()]
            );
            audit('admin_create', $username, ROLES[$role] ?? 'staff');
            flash('success', "{$username} 계정을 만들었습니다. 첫 로그인 후 [내 정보]에서 비밀번호를 바꾸도록 안내해 주세요.");
        }
    } elseif ($target = db_one('SELECT * FROM admins WHERE id = ?', [$id])) {
        $activeAdmins = (int) db_value("SELECT COUNT(*) FROM admins WHERE role = 'admin' AND is_active = 1");
        $isLastAdmin = $target['role'] === 'admin' && (int) $target['is_active'] === 1 && $activeAdmins <= 1;

        if ($action === 'reset_password') {
            $password = (string) ($_POST['password'] ?? '');
            if ($err = password_policy_error($password)) {
                flash('error', $err);
            } else {
                db_exec('UPDATE admins SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
                audit('admin_password_reset', $target['username']);
                flash('success', "{$target['username']} 의 비밀번호를 바꿨습니다.");
            }
        } elseif ($action === 'toggle_active') {
            if ((int) $target['id'] === (int) $me['id'] || $isLastAdmin) {
                flash('error', '본인 계정이나 마지막 관리자 계정은 사용 중지할 수 없습니다.');
            } else {
                db_exec('UPDATE admins SET is_active = 1 - is_active WHERE id = ?', [$id]);
                audit('admin_toggle', $target['username'], (int) $target['is_active'] === 1 ? '사용 중지' : '사용 재개');
                flash('success', "{$target['username']} 계정 상태를 바꿨습니다.");
            }
        } elseif ($action === 'role') {
            $role = input_str('role', '', 10);
            if (!isset(ROLES[$role])) {
                flash('error', '권한을 선택해 주세요.');
            } elseif ($role !== 'admin' && ($isLastAdmin || (int) $target['id'] === (int) $me['id'])) {
                flash('error', '본인 계정이나 마지막 관리자 계정의 권한은 낮출 수 없습니다.');
            } else {
                db_exec('UPDATE admins SET role = ? WHERE id = ?', [$role, $id]);
                audit('admin_role', $target['username'], ROLES[$role]);
                flash('success', "{$target['username']} 의 권한을 바꿨습니다.");
            }
        }
    }
    redirect('admins.php');
}

$rows = db_all('SELECT * FROM admins ORDER BY is_active DESC, role, username');

page_header('관리자', ['menu' => 'admins', 'wide' => true]);
?>
<div class="page-head"><h1>관리자 계정</h1></div>
<p class="muted small"><strong>관리자</strong>는 모든 기능과 수령자 개인정보 전체 보기·내보내기, 계정 관리를 할 수 있습니다.
  <strong>담당자</strong>는 조회·수집·지급 관리를 할 수 있고 수령자 개인정보는 가려서 봅니다.</p>

<div class="card flush">
<table class="table">
  <thead><tr><th>아이디</th><th>이름</th><th>권한</th><th>상태</th><th>마지막 로그인</th><th>관리</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr class="<?= (int) $r['is_active'] === 1 ? '' : 'muted' ?>">
      <td><?= h($r['username']) ?><?= (int) $r['id'] === (int) $me['id'] ? ' <span class="badge">나</span>' : '' ?></td>
      <td><?= h($r['display_name']) ?></td>
      <td>
        <form method="post" class="inline">
          <?= csrf_field() ?><input type="hidden" name="action" value="role"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <select name="role" data-autosubmit>
            <?php foreach (ROLES as $k => $label): ?><option value="<?= h($k) ?>" <?= $r['role'] === $k ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
          </select>
        </form>
      </td>
      <td><?= (int) $r['is_active'] === 1 ? '사용 중' : '사용 중지' ?></td>
      <td class="muted small"><?= fmt_dt($r['last_login_at'], 'Y-m-d H:i') ?></td>
      <td class="nowrap">
        <form method="post" class="inline">
          <?= csrf_field() ?><input type="hidden" name="action" value="reset_password"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <input type="password" name="password" placeholder="새 비밀번호" autocomplete="new-password" class="w-pass">
          <button class="btn small">비밀번호 변경</button>
        </form>
        <form method="post" class="inline">
          <?= csrf_field() ?><input type="hidden" name="action" value="toggle_active"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <button class="btn small" data-confirm="계정 상태를 바꿀까요?"><?= (int) $r['is_active'] === 1 ? '사용 중지' : '사용 재개' ?></button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<div class="card narrow-card">
  <h2>새 계정 만들기</h2>
  <form method="post" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="row2">
      <label>아이디 <input name="username" required maxlength="50" autocomplete="off"></label>
      <label>표시 이름 <input name="display_name" required maxlength="50"></label>
    </div>
    <div class="row2">
      <label>권한 <select name="role"><?php foreach (ROLES as $k => $label): ?><option value="<?= h($k) ?>" <?= $k === 'staff' ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></label>
      <label>임시 비밀번호 <input type="password" name="password" required autocomplete="new-password"><span class="muted small">8자 이상, 영문+숫자</span></label>
    </div>
    <button class="btn primary">만들기</button>
  </form>
</div>
<?php
page_footer();
