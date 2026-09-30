<?php
/**
 * 백업 파일 업로드
 *  - 수집 화면에서 내려받은 백업 파일 (.jsonl / .jsonl.gz)
 *  - 다른 프로그램으로 뽑은 채팅 CSV (시간, 아이디, 닉네임, 내용)
 * 이미 저장된 채팅·후원은 자동으로 건너뜁니다. 같은 파일을 여러 번 올려도 괜찮습니다.
 */
require __DIR__ . '/app/bootstrap.php';
require APP_DIR . '/ingest.php';

require_login();
$b = load_broadcast(input_int('id'));
$id = (int) $b['id'];

const CSV_COLUMNS = [
    'time' => ['시간', '시각', '일시', '날짜', 'time', 'date', 'datetime', 'timestamp', 'sent_at'],
    'user' => ['아이디', 'id', 'user_id', 'userid', 'user', '계정'],
    'nick' => ['닉네임', 'nickname', 'nick', 'name', '이름'],
    'msg'  => ['내용', '메시지', '채팅', 'message', 'chat', 'text', 'comment'],
];

function csv_time_to_ms(string $value, string $baseDate): ?int
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if (ctype_digit($value)) {
        return strlen($value) >= 12 ? (int) $value : (int) $value * 1000;
    }
    if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $value)) {
        $value = $baseDate . ' ' . $value;
    }
    $value = preg_replace('#^(\d{4})[./](\d{1,2})[./](\d{1,2})#', '$1-$2-$3', $value) ?? $value;
    $ms = 0;
    if (preg_match('/\.(\d{1,3})$/', $value, $m)) {
        $ms = (int) str_pad($m[1], 3, '0');
        $value = substr($value, 0, -strlen($m[0]));
    }
    $ts = strtotime($value);
    return $ts === false ? null : $ts * 1000 + $ms;
}

function register_import_collector(string $collectorId, int $broadcastId, array &$known, string $label): void
{
    if (isset($known[$collectorId])) {
        return;
    }
    $known[$collectorId] = true;
    db_exec(
        "INSERT IGNORE INTO collectors (id, broadcast_id, admin_id, label, status, status_message, started_at, last_seen_at) VALUES (?, ?, ?, ?, 'imported', '백업 업로드', ?, ?)",
        [$collectorId, $broadcastId, current_admin()['id'] ?? null, $label, now(), now()]
    );
}

$result = null;
if (is_post()) {
    csrf_check();
    $file = $_FILES['file'] ?? null;
    $err = is_array($file) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
    if ($err !== UPLOAD_ERR_OK) {
        flash('error', match ($err) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => '파일이 서버 업로드 한도(' . ini_get('upload_max_filesize') . ')보다 큽니다. 압축된 백업(.gz)을 쓰거나 호스팅 설정에서 한도를 늘려주세요.',
            UPLOAD_ERR_NO_FILE => '파일을 선택해 주세요.',
            default => '파일 업로드에 실패했습니다. (오류 코드 ' . $err . ')',
        });
        redirect("import.php?id=$id");
    }
    @set_time_limit(900);

    $path = $file['tmp_name'];
    $fh = fopen($path, 'rb');
    $magic = $fh ? fread($fh, 2) : '';
    if ($fh) fclose($fh);
    $isGz = $magic === "\x1f\x8b";
    $h = $isGz ? gzopen($path, 'rb') : fopen($path, 'rb');
    $readLine = fn() => $isGz ? gzgets($h) : fgets($h);

    $totals = ['chats' => 0, 'donations' => 0, 'duplicates' => 0, 'invalid' => 0, 'lines' => 0];
    $buffer = [];
    $knownCollectors = [];
    $flush = function () use (&$buffer, &$totals, $id) {
        if (!$buffer) return;
        $r = ingest_events($id, $buffer, 'file');
        foreach (['chats', 'donations', 'duplicates', 'invalid'] as $k) {
            $totals[$k] += $r[$k];
        }
        $buffer = [];
    };

    $first = $readLine();
    $first = $first === false ? '' : preg_replace('/^\xEF\xBB\xBF/', '', $first);
    $mode = str_starts_with(ltrim($first), '{') ? 'json' : 'csv';
    $error = null;

    if ($mode === 'json') {
        $line = $first;
        $header = json_decode(trim($line), true);
        if (is_array($header) && ($header['format'] ?? '') === 'endgame-backup') {
            if ((int) ($header['broadcast_id'] ?? 0) !== $id) {
                $error = '이 백업 파일은 다른 방송 회차(번호 ' . (int) ($header['broadcast_id'] ?? 0) . ')의 것입니다. 해당 회차의 [백업 업로드]에서 올려주세요.';
            }
            $line = $readLine();
        }
        while ($error === null && $line !== false) {
            $line = trim($line);
            if ($line !== '') {
                $totals['lines']++;
                $ev = json_decode($line, true);
                if (is_array($ev) && isset($ev['uid']) && is_string($ev['uid'])) {
                    register_import_collector(collector_id_from_uid($ev['uid']), $id, $knownCollectors, '백업 파일');
                    $buffer[] = $ev;
                } else {
                    $totals['invalid']++;
                }
                if (count($buffer) >= 1000) $flush();
            }
            $line = $readLine();
        }
    } else {
        $collectorId = 'f' . $id;
        register_import_collector($collectorId, $id, $knownCollectors, 'CSV 업로드');
        $toUtf8 = fn(string $s) => mb_check_encoding($s, 'UTF-8') ? $s : (string) mb_convert_encoding($s, 'UTF-8', 'CP949');
        $firstLine = $toUtf8($first);
        $delimiter = substr_count($firstLine, "\t") > substr_count($firstLine, ',') ? "\t" : ',';
        $cells = array_map(fn($c) => mb_strtolower(trim((string) $c)), str_getcsv(trim($firstLine), $delimiter, '"', ''));
        $map = [];
        foreach (CSV_COLUMNS as $key => $names) {
            foreach ($cells as $i => $cell) {
                if (in_array($cell, $names, true)) {
                    $map[$key] = $i;
                    break;
                }
            }
        }
        $hasHeader = isset($map['user'], $map['msg']);
        if (!$hasHeader) {
            $map = ['time' => 0, 'user' => 1, 'nick' => 2, 'msg' => 3];
        }
        $seen = [];
        $line = $hasHeader ? $readLine() : $first;
        while ($line !== false) {
            $line = $toUtf8(rtrim($line, "\r\n"));
            if (trim($line) !== '') {
                $totals['lines']++;
                $row = str_getcsv($line, $delimiter, '"', '');
                $ms = csv_time_to_ms((string) ($row[$map['time'] ?? -1] ?? ''), $b['broadcast_date']);
                $user = trim((string) ($row[$map['user']] ?? ''));
                $msg = (string) ($row[$map['msg']] ?? '');
                if ($ms === null || $user === '') {
                    $totals['invalid']++;
                } else {
                    $key = md5("$ms|$user|$msg", true);
                    $occ = $seen[$key] = ($seen[$key] ?? 0) + 1;
                    $buffer[] = [
                        'uid' => $collectorId . ':' . substr(sha1("$ms|$user|$msg|$occ"), 0, 23),
                        't' => $ms, 'k' => 'c', 'u' => $user,
                        'n' => trim((string) ($row[$map['nick'] ?? -1] ?? '')), 'm' => $msg, 'kd' => 'chat', 'b' => 0,
                    ];
                }
                if (count($buffer) >= 1000) $flush();
            }
            $line = $readLine();
        }
    }
    $flush();
    $isGz ? gzclose($h) : fclose($h);

    if ($error) {
        flash('error', $error);
    } else {
        $name = mb_substr((string) $file['name'], 0, 100);
        audit('import', "broadcast:$id", sprintf('%s · 채팅 %d · 후원 %d · 중복 %d · 오류 %d', $name, $totals['chats'], $totals['donations'], $totals['duplicates'], $totals['invalid']));
        flash('success', sprintf(
            '업로드 완료: 채팅 %s건, 후원 %s건을 새로 저장했습니다. (이미 있던 기록 %s건 건너뜀%s)',
            fmt_num($totals['chats']), fmt_num($totals['donations']), fmt_num($totals['duplicates']),
            $totals['invalid'] ? ', 형식 오류 ' . fmt_num($totals['invalid']) . '건' : ''
        ));
    }
    redirect("import.php?id=$id");
}

page_header('백업 업로드 · ' . $b['title'], ['menu' => 'broadcasts', 'broadcast' => $b, 'tab' => 'import']);
?>
<div class="card">
  <h2>백업 파일 올리기</h2>
  <form method="post" enctype="multipart/form-data" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <label>파일 <input type="file" name="file" required accept=".jsonl,.gz,.csv,.txt,.tsv"></label>
    <button class="btn primary">업로드</button>
  </form>
  <p class="muted small">서버 업로드 한도: 파일 <?= h(ini_get('upload_max_filesize')) ?> / 요청 <?= h(ini_get('post_max_size')) ?></p>
</div>

<div class="card muted small">
  <strong>올릴 수 있는 파일</strong>
  <ul>
    <li><strong>수집 화면 백업 파일</strong> (endgame-backup-….jsonl.gz): [실시간 수집] 탭의 [백업 파일 내려받기]로 받은 파일입니다. 채팅과 후원이 모두 들어 있습니다.</li>
    <li><strong>채팅 CSV</strong>: 첫 줄에 <code>시간, 아이디, 닉네임, 내용</code> 제목이 있는 파일(엑셀 저장 CSV 가능). 제목 줄이 없으면 이 순서로 읽습니다.
      시간은 <code>2026-10-01 20:15:03</code> 또는 <code>20:15:03</code>(방송일 기준) 형식을 씁니다. CSV 는 채팅만 올릴 수 있습니다.</li>
    <li>이미 저장된 기록은 자동으로 건너뛰므로 같은 파일을 다시 올려도 중복 저장되지 않습니다.</li>
  </ul>
</div>
<?php
page_footer();
