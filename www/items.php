<?php
/**
 * 상품 목록 관리 (사진·아이콘·색·쪽지 문안 종류·순서)
 * 당첨 등록 때 여기서 상품을 고르고, 닉네임 옆 당첨 아이콘도 여기 설정을 따릅니다.
 */
require __DIR__ . '/app/bootstrap.php';
require APP_DIR . '/prizes.php';

$admin = require_login();

const PHOTO_MAX_BYTES = 8 * 1024 * 1024;
const PHOTO_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

/** 올린 사진을 저장하고 파일 이름을 돌려줍니다. 사진이 없으면 null, 형식이 틀리면 오류 문구. */
function save_photo(int $itemId): string|null|false
{
    $f = $_FILES['photo'] ?? null;
    if (!is_array($f) || (int) $f['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ((int) $f['error'] !== UPLOAD_ERR_OK || $f['size'] > PHOTO_MAX_BYTES) {
        flash('error', '사진은 8MB 이하의 jpg·png·webp·gif 파일만 올릴 수 있습니다.');
        return false;
    }
    $info = @getimagesize($f['tmp_name']);
    $ext = $info ? (PHOTO_TYPES[$info['mime']] ?? null) : null;
    if ($ext === null) {
        flash('error', '사진 파일 형식을 확인해 주세요. (jpg·png·webp·gif)');
        return false;
    }
    $name = $itemId . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], storage_dir('uploads/prizes') . '/' . $name)) {
        flash('error', '사진을 저장하지 못했습니다. 저장 폴더 권한을 확인해 주세요.');
        return false;
    }
    return $name;
}

function delete_photo(string $name): void
{
    if ($name !== '') {
        @unlink(storage_dir('uploads/prizes') . '/' . basename($name));
    }
}

if (is_post()) {
    csrf_check();
    $action = input_str('action', '', 20);
    $id = input_int('id');
    $item = $id ? prize_item($id) : null;

    if ($action === 'save') {
        $name = input_str('name', '', 200);
        if ($name === '') {
            flash('error', '상품 이름을 입력해 주세요.');
            redirect('items.php' . ($id ? "?edit=$id" : ''));
        }
        $cat = prize_category($name);
        $icon = input_str('icon', '', 8);
        $color = input_str('color', '', 7);
        $noteType = input_str('note_type', '', 10);
        $data = [
            'name'      => $name,
            'icon'      => $icon !== '' ? $icon : $cat[3],
            'color'     => !isset($_POST['auto_color']) && preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtolower($color) : $cat[2],
            'note_type' => isset(NOTE_TYPES[$noteType]) ? $noteType : suggest_note_type($name),
            'memo'      => input_str('memo', '', 500),
            'updated_at' => now(),
        ];
        if ($item) {
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($data)));
            db_exec("UPDATE prize_items SET $set WHERE id = ?", array_merge(array_values($data), [$id]));
            audit('item_update', "item:$id", $name);
        } else {
            $data += ['sort_order' => (int) db_value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM prize_items'), 'is_active' => 1, 'created_at' => now()];
            db_exec('INSERT INTO prize_items (' . implode(', ', array_keys($data)) . ') VALUES (' . db_placeholders($data) . ')', array_values($data));
            $id = db_last_id();
            $item = prize_item($id);
            audit('item_create', "item:$id", $name);
        }
        $photo = save_photo($id);
        if (is_string($photo)) {
            delete_photo((string) $item['photo']);
            db_exec('UPDATE prize_items SET photo = ? WHERE id = ?', [$photo, $id]);
        } elseif (isset($_POST['remove_photo']) && $item) {
            delete_photo((string) $item['photo']);
            db_exec("UPDATE prize_items SET photo = '' WHERE id = ?", [$id]);
        }
        flash('success', "'$name' 상품을 저장했습니다.");
        redirect('items.php');
    }

    if ($item && $action === 'toggle') {
        db_exec('UPDATE prize_items SET is_active = 1 - is_active, updated_at = ? WHERE id = ?', [now(), $id]);
        audit('item_toggle', "item:$id", $item['name']);
    } elseif ($item && in_array($action, ['up', 'down'], true)) {
        // 순서 바꾸기: 바로 위(아래) 상품과 순서를 맞바꿉니다.
        $items = prize_items();
        $ids = array_column($items, 'id');
        $pos = array_search($item['id'], $ids);
        $swap = $action === 'up' ? $pos - 1 : $pos + 1;
        if (isset($ids[$swap])) {
            [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
            foreach ($ids as $order => $itemId) {
                db_exec('UPDATE prize_items SET sort_order = ? WHERE id = ?', [$order + 1, $itemId]);
            }
        }
    } elseif ($item && $action === 'delete') {
        $used = (int) db_value('SELECT COUNT(*) FROM prizes WHERE item_id = ?', [$id]);
        if ($used > 0) {
            flash('error', "이 상품으로 지급 기록이 {$used}건 있어 지울 수 없습니다. [사용 중지]를 이용해 주세요.");
        } else {
            delete_photo((string) $item['photo']);
            db_exec('DELETE FROM prize_items WHERE id = ?', [$id]);
            audit('item_delete', "item:$id", $item['name']);
            flash('success', "'{$item['name']}' 상품을 지웠습니다.");
        }
    }
    redirect('items.php');
}

$items = prize_items();
$counts = [];
foreach (db_all('SELECT item_id, COUNT(*) AS cnt FROM prizes WHERE item_id IS NOT NULL GROUP BY item_id') as $r) {
    $counts[(int) $r['item_id']] = (int) $r['cnt'];
}
$edit = input_int('edit') ? prize_item(input_int('edit')) : null;
$form = $edit ?? ['id' => 0, 'name' => '', 'icon' => '', 'color' => '', 'note_type' => '', 'memo' => '', 'photo' => ''];

page_header('상품 목록', ['menu' => 'items']);
?>
<div class="page-head"><h1>상품 목록</h1></div>
<p class="muted">당첨 등록 때 여기서 상품을 고릅니다. 아이콘·색은 닉네임 옆 당첨 표시에, 쪽지 문안 종류는 쪽지 보낼 때 문안 자동 선택에 쓰입니다.</p>

<div class="grid-items">
<?php foreach ($items as $i => $it): ?>
  <div class="card item-card<?= (int) $it['is_active'] === 1 ? '' : ' inactive' ?>">
    <div class="item-photo">
      <?php if ($it['photo'] !== ''): ?>
        <img src="prize_photo.php?id=<?= (int) $it['id'] ?>&amp;v=<?= h(substr(md5($it['photo']), 0, 8)) ?>" alt="">
      <?php else: ?>
        <span class="item-icon-big" style="--c:<?= h($it['color']) ?>"><?= h($it['icon']) ?></span>
      <?php endif; ?>
    </div>
    <div class="item-body">
      <div class="item-name"><span class="wi" style="--c:<?= h($it['color']) ?>"><?= h($it['icon']) ?></span> <?= h($it['name']) ?></div>
      <div class="muted small"><?= h(NOTE_TYPES[$it['note_type']] ?? $it['note_type']) ?> · 지급 <?= fmt_num($counts[(int) $it['id']] ?? 0) ?>회<?= (int) $it['is_active'] === 1 ? '' : ' · <span class="bad">사용 중지</span>' ?></div>
      <?php if ($it['memo'] !== ''): ?><div class="muted small"><?= h($it['memo']) ?></div><?php endif; ?>
      <div class="actions item-actions">
        <a class="btn small" href="items.php?edit=<?= (int) $it['id'] ?>">수정</a>
        <?php foreach (['up' => '▲', 'down' => '▼', 'toggle' => (int) $it['is_active'] === 1 ? '사용 중지' : '다시 사용', 'delete' => '지우기'] as $act => $label): ?>
          <form method="post" class="inline">
            <?= csrf_field() ?><input type="hidden" name="action" value="<?= $act ?>"><input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
            <button class="btn small"<?= $act === 'delete' ? ' data-confirm="이 상품을 지울까요?"' : '' ?><?= ($act === 'up' && $i === 0) || ($act === 'down' && $i === count($items) - 1) ? ' disabled' : '' ?>><?= h($label) ?></button>
          </form>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
<?php endforeach; ?>
<?php if (!$items): ?><div class="card muted">등록된 상품이 없습니다. 아래에서 추가하세요.</div><?php endif; ?>
</div>

<div class="card narrow-card" id="item-form">
  <h2><?= $edit ? "'" . h($edit['name']) . "' 수정" : '상품 추가' ?></h2>
  <form method="post" enctype="multipart/form-data" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">
    <label>상품 이름 <input name="name" required maxlength="200" value="<?= h($form['name']) ?>" placeholder="예: 레이저 바이퍼 마우스 화이트"></label>
    <div class="row2">
      <label>아이콘 (이모지 한 글자) <input name="icon" maxlength="8" value="<?= h($form['icon']) ?>" placeholder="비우면 이름으로 자동 (🖱️ 👕 👓 🎟️ …)"></label>
      <label>색 <input type="color" name="color" value="<?= h($form['color'] !== '' ? $form['color'] : '#8a93a6') ?>"></label>
    </div>
    <label class="check"><input type="checkbox" name="auto_color" value="1" <?= $edit ? '' : 'checked' ?>> 색은 상품 이름으로 자동 (마우스 파랑, 화이트 흰색, 유니폼 빨강 …)</label>
    <label>쪽지 문안 종류
      <select name="note_type">
        <option value="">이름으로 자동 선택</option>
        <?php foreach (NOTE_TYPES as $k => $v): ?><option value="<?= h($k) ?>" <?= $form['note_type'] === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>메모 <input name="memo" maxlength="500" value="<?= h($form['memo']) ?>" placeholder="재고, 발송 방법 등"></label>
    <label>사진 (8MB 이하 jpg·png·webp·gif) <input type="file" name="photo" accept="image/jpeg,image/png,image/webp,image/gif"></label>
    <?php if ($edit && $edit['photo'] !== ''): ?><label class="check"><input type="checkbox" name="remove_photo" value="1"> 지금 사진 지우기</label><?php endif; ?>
    <div class="actions">
      <button class="btn primary">저장</button>
      <?php if ($edit): ?><a class="btn" href="items.php">취소</a><?php endif; ?>
    </div>
  </form>
  <p class="muted small">이름에 마우스·패드·유니폼·안경·쿠폰·코드 등이 들어가면 아이콘·색·쪽지 문안이 기존 끝장전 규칙대로 자동으로 정해집니다.</p>
</div>
<?php
page_footer();
