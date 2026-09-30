<?php
/**
 * 최초 설치 화면
 * 설정 확인 → 첫 관리자 계정 생성. 설치가 끝나면 서버에서 이 파일을 지워주세요.
 */
define('INSTALLING', true);
require __DIR__ . '/app/bootstrap.php';

$checks = [
    ['PHP 8.1 이상', version_compare(PHP_VERSION, '8.1.0', '>='), 'PHP ' . PHP_VERSION],
    db_driver() === 'sqlite' ? ['PDO SQLite (PC 버전 저장소)', extension_loaded('pdo_sqlite'), ''] : ['PDO MySQL', extension_loaded('pdo_mysql'), ''],
    ['OpenSSL (개인정보 암호화)', extension_loaded('openssl'), ''],
    ['mbstring (한글 처리)', extension_loaded('mbstring'), ''],
    ['cURL (SOOP 방송 정보 조회)', extension_loaded('curl') || ini_get('allow_url_fopen'), extension_loaded('curl') ? '' : 'cURL 없음 → allow_url_fopen 사용'],
    ['zlib (압축 백업 파일)', extension_loaded('zlib'), ''],
];
$storage = APP_DIR . '/storage';
$checks[] = ['app 폴더 쓰기 권한', is_dir($storage) ? is_writable($storage) : is_writable(APP_DIR), '오류 기록 저장용 (없어도 동작)'];

$step = 'config';
$dbError = $GLOBALS['INSTALL_DB_ERROR'] ?? null;
$adminCount = 0;
if (app_configured() && !$dbError) {
    try {
        $adminCount = (int) db_value('SELECT COUNT(*) FROM admins');
        $step = !app_key_valid() ? 'key' : ($adminCount > 0 ? 'done' : 'admin');
    } catch (PDOException $e) {
        $dbError = $e->getMessage();
    }
}

$error = null;
if ($step === 'admin' && is_post()) {
    csrf_check();
    $username = input_str('username', '', 50);
    $name = input_str('display_name', '', 50);
    $password = (string) ($_POST['password'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
        $error = '아이디는 영문·숫자·_ . - 조합 3자 이상으로 입력해 주세요.';
    } elseif ($name === '') {
        $error = '표시 이름을 입력해 주세요.';
    } elseif ($e = password_policy_error($password)) {
        $error = $e;
    } elseif ($password !== ($_POST['password2'] ?? '')) {
        $error = '비밀번호 확인이 일치하지 않습니다.';
    } else {
        db_exec(
            'INSERT INTO admins (username, password_hash, display_name, role, created_at) VALUES (?, ?, ?, \'admin\', ?)',
            [$username, password_hash($password, PASSWORD_DEFAULT), $name, now()]
        );
        attempt_login($username, $password);
        audit('install', $username, '첫 관리자 계정 생성');
        flash('success', is_desktop() ? '준비가 끝났습니다. [+ 새 회차 등록]으로 시작하세요.' : '설치가 완료되었습니다. 보안을 위해 서버에서 install.php 파일을 삭제해 주세요.');
        redirect('index.php');
    }
}

page_header('설치', ['bare' => true]);
?>
<div class="narrow">
  <h1>설치</h1>

  <div class="card">
    <h2>서버 환경 확인</h2>
    <table class="table compact">
      <?php foreach ($checks as [$label, $ok, $note]): ?>
        <tr><td><?= h($label) ?></td><td><?= $ok ? '<span class="ok">정상</span>' : '<span class="bad">확인 필요</span>' ?></td><td class="muted small"><?= h($note) ?></td></tr>
      <?php endforeach; ?>
    </table>
  </div>

  <?php if ($step === 'config'): ?>
    <div class="card">
      <h2>1단계 · 설정 파일 만들기</h2>
      <?php if ($dbError): ?>
        <div class="alert alert-error">DB 에 접속하지 못했습니다. config.php 의 DB 정보를 확인해 주세요.<br><span class="small"><?= h($dbError) ?></span></div>
      <?php endif; ?>
      <ol>
        <li>서버의 <code>app/config.sample.php</code> 를 복사해 같은 폴더에 <code>app/config.php</code> 로 저장합니다.</li>
        <li>호스팅 관리 화면의 DB 이름·아이디·비밀번호를 <code>db</code> 항목에 입력합니다.</li>
        <li><code>app_key</code> 에 아래 값을 그대로 붙여넣습니다. (개인정보 암호화 키 · 한 번 정하면 바꾸지 마세요)</li>
      </ol>
      <pre class="code"><?= h(generate_app_key()) ?></pre>
      <p>저장 후 이 화면을 새로고침하세요.</p>
    </div>
  <?php elseif ($step === 'key'): ?>
    <div class="card">
      <h2>1단계 · 암호화 키 입력</h2>
      <p>DB 접속은 정상입니다. <code>app/config.php</code> 의 <code>app_key</code> 에 아래 값을 붙여넣고 새로고침하세요.</p>
      <pre class="code"><?= h(generate_app_key()) ?></pre>
    </div>
  <?php elseif ($step === 'admin'): ?>
    <div class="card">
      <h2><?= is_desktop() ? '첫 관리자 계정 만들기' : '2단계 · 첫 관리자 계정 만들기' ?></h2>
      <?php if (is_desktop()): ?><p class="muted small">이 프로그램에 로그인할 계정입니다. 수령자 개인정보가 저장되므로 비밀번호를 꼭 기억해 두세요.</p><?php endif; ?>
      <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
      <form method="post" class="form">
        <?= csrf_field() ?>
        <label>아이디 <input name="username" required value="<?= h(input_str('username', '', 50)) ?>" autocomplete="username"></label>
        <label>표시 이름 <input name="display_name" required value="<?= h(input_str('display_name', '', 50)) ?>" placeholder="예: 홍길동 매니저"></label>
        <label>비밀번호 <input type="password" name="password" required autocomplete="new-password"><span class="muted small">8자 이상, 영문+숫자</span></label>
        <label>비밀번호 확인 <input type="password" name="password2" required autocomplete="new-password"></label>
        <button type="submit" class="btn primary">관리자 만들기</button>
      </form>
    </div>
  <?php else: ?>
    <div class="card">
      <h2>설치 완료</h2>
      <p>이미 설치가 끝났습니다.<?php if (!is_desktop()): ?> <strong>보안을 위해 서버에서 install.php 파일을 삭제해 주세요.</strong><?php endif; ?></p>
      <p><a class="btn primary" href="login.php">로그인하러 가기</a></p>
    </div>
  <?php endif; ?>
</div>
<?php
page_footer();
