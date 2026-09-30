<?php
declare(strict_types=1);

// Google 시트 연결: 서비스 계정 키·JWT·오류 안내·비밀값 보관, xlsx 가져오기, 실제 HTTP(가짜 서버)

/** 테스트용 서비스 계정 키 (매번 새로 만든 RSA 키) */
function fx_key(string $tokenUri = GOOGLE_TOKEN_URI): array
{
    $pk = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($pk, $pem);
    $pub = openssl_pkey_get_details($pk)['key'];
    $json = json_encode(['type' => 'service_account', 'project_id' => 'test', 'private_key_id' => 'kid1', 'private_key' => $pem,
        'client_email' => 'cg-reader@test-project.iam.gserviceaccount.com', 'token_uri' => $tokenUri]);
    return ['json' => $json, 'pub' => $pub, 'pem' => $pem];
}

/** 탭 이름 => 행 목록 */
function fx_tabs(?callable $tamper = null): array
{
    $t = fx_tables($tamper);
    return [SHEET_TABS_DEFAULT['results'] => $t['results'], SHEET_TABS_DEFAULT['players'] => $t['players'],
        SHEET_TABS_DEFAULT['matches'] => $t['matches'], SHEET_TABS_DEFAULT['predictions'] => $t['predictions'], '상금 보정' => [['x']]];
}

/** 메모리 안의 가짜 Google (HTTP 함수를 바꿔 끼움) */
function fx_transport(array $tabs, string $pub, array &$log, array $fail = []): callable
{
    return function (string $method, string $url, array $headers, ?string $body) use ($tabs, $pub, &$log, $fail): array {
        $log[] = [$method, $url, $headers, $body];
        $path = (string)parse_url($url, PHP_URL_PATH);
        if (str_ends_with($path, '/token')) {
            if (isset($fail['token'])) {
                return ['status' => $fail['token'], 'body' => '{"error":"invalid_grant"}'];
            }
            parse_str((string)$body, $form);
            [$h, $c, $s] = explode('.', $form['assertion']);
            $dec = static fn(string $x) => (string)base64_decode(strtr($x, '-_', '+/'));
            assert_same(1, openssl_verify("$h.$c", $dec($s), $pub, OPENSSL_ALGO_SHA256), 'JWT 서명');
            $claims = json_decode($dec($c), true);
            assert_same(SHEETS_SCOPE, $claims['scope'], '읽기 전용 권한만 요청');
            assert_same(3600, $claims['exp'] - $claims['iat']);
            return ['status' => 200, 'body' => '{"access_token":"tok-secret-123","expires_in":3600}'];
        }
        assert_true(in_array('Authorization: Bearer tok-secret-123', $headers, true), '토큰 사용');
        if (isset($fail['sheets'])) {
            return ['status' => $fail['sheets'], 'body' => '{"error":{"message":"The caller does not have permission tok-secret-123"}}'];
        }
        if (str_contains($path, 'values:batchGet')) {
            $out = [];
            preg_match_all('/ranges=([^&]+)/', (string)parse_url($url, PHP_URL_QUERY), $m);
            foreach ($m[1] as $r) {
                $r = rawurldecode($r);
                $title = str_replace("''", "'", substr($r, 1, strrpos($r, "'!") - 1));
                $out[] = ['range' => $r, 'values' => $tabs[$title] ?? []];
            }
            return ['status' => 200, 'body' => json_encode(['valueRanges' => $out])];
        }
        return ['status' => 200, 'body' => json_encode(['sheets' => array_map(fn($t) => ['properties' => ['title' => $t]], array_keys($tabs))])];
    };
}

/** 합성 표 → 최소 xlsx (날짜는 날짜 서식 일련번호, 이름은 공유 문자열) */
function fx_xlsx(array $tabs): string
{
    $strings = [];
    $sid = static function (string $s) use (&$strings): int {
        $i = array_search($s, $strings, true);
        if ($i === false) {
            $strings[] = $s;
            $i = count($strings) - 1;
        }
        return $i;
    };
    $col = static function (int $i): string {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    };
    $sheets = [];
    foreach ($tabs as $title => $rows) {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $r => $row) {
            $xml .= '<row r="' . ($r + 1) . '">';
            foreach ($row as $c => $v) {
                $ref = $col($c) . ($r + 1);
                if (is_string($v) && ($d = date_norm($v)) !== null && strlen($v) === 10) {
                    $serial = (int)(new DateTimeImmutable('1899-12-30'))->diff(new DateTimeImmutable($d))->days;
                    $xml .= "<c r=\"$ref\" s=\"1\"><v>$serial</v></c>";
                } elseif (is_int($v) || is_float($v)) {
                    $xml .= "<c r=\"$ref\"><v>$v</v></c>";
                } elseif ($v !== '') {
                    $xml .= "<c r=\"$ref\" t=\"s\"><v>" . $sid((string)$v) . '</v></c>';
                }
            }
            $xml .= '</row>';
        }
        $sheets[$title] = $xml . '</sheetData></worksheet>';
    }
    $path = $GLOBALS['TEST_TMP'] . '/x-' . bin2hex(random_bytes(4)) . '.xlsx';
    $z = new ZipArchive();
    $z->open($path, ZipArchive::CREATE);
    $wb = '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
    $rels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    $i = 0;
    foreach ($sheets as $title => $xml) {
        $i++;
        $wb .= '<sheet name="' . htmlspecialchars($title, ENT_XML1) . "\" sheetId=\"$i\" r:id=\"rId$i\"/>";
        $rels .= "<Relationship Id=\"rId$i\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet$i.xml\"/>";
        $z->addFromString("xl/worksheets/sheet$i.xml", $xml);
    }
    $z->addFromString('xl/workbook.xml', $wb . '</sheets></workbook>');
    $z->addFromString('xl/_rels/workbook.xml.rels', $rels . '</Relationships>');
    $z->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<cellXfs count="2"><xf numFmtId="0"/><xf numFmtId="14" applyNumberFormat="1"/></cellXfs></styleSheet>');
    $sst = '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    foreach ($strings as $s) {
        $sst .= '<si><t xml:space="preserve">' . htmlspecialchars($s, ENT_XML1) . '</t></si>';
    }
    $z->addFromString('xl/sharedStrings.xml', $sst . '</sst>');
    $z->close();
    $bytes = (string)file_get_contents($path);
    unlink($path);
    return $bytes;
}

test('google: 서비스 계정 키 확인 — 형식·토큰 주소·개인 키', function () {
    $k = fx_key();
    $parsed = google_key_parse($k['json']);
    assert_same('cg-reader@test-project.iam.gserviceaccount.com', $parsed['client_email']);
    $bad = [
        json_encode(['type' => 'authorized_user']),
        str_replace('iam.gserviceaccount.com', 'example.com', $k['json']),
        str_replace(json_encode(GOOGLE_TOKEN_URI), json_encode('https://evil.example/token'), $k['json']),
        str_replace('PRIVATE KEY', 'PUBLIC KEY', $k['json']),
        'not json',
    ];
    foreach ($bad as $json) {
        assert_throws(InvalidArgumentException::class, fn() => google_key_parse($json));
    }
    assert_same('1kTgQlye20qKemyZ8Bt0REe0MrQ2kaZXiO0mdZEkySmg',
        sheet_id_from('https://docs.google.com/spreadsheets/d/1kTgQlye20qKemyZ8Bt0REe0MrQ2kaZXiO0mdZEkySmg/edit?gid=467683547#gid=467683547'));
    assert_same(null, sheet_id_from('https://example.com/x'));
});

test('google: 서명한 JWT로 토큰 → 탭 목록 → 필요한 열만 batchGet → 반영', function () {
    fresh_db();
    $k = fx_key();
    $log = [];
    $GLOBALS['CG_HTTP'] = fx_transport(fx_tabs(), $k['pub'], $log);
    try {
        google_key_save($k['json'], op());
        data_settings_save(['source' => 'sheet', 'sheet' => 'https://docs.google.com/spreadsheets/d/TESTsheetID_0123456789abc/edit'], op());
        $r = data_refresh(op());
        assert_same(0, $r['changed']);
        assert_same(3, count($log), '토큰·탭 목록·batchGet 3번');
        $batch = rawurldecode($log[2][1]);
        foreach (["'Results'!A:F", "'Players'!A:O", "'상대전적조회NEW'!A:I", "'중계진 예측 현황입력용'!A:Q"] as $range) {
            assert_true(str_contains($batch, $range), "요청 범위 $range");
        }
        assert_true(!str_contains($batch, '상금'), '필요 없는 탭(상금)은 읽지 않음');
        assert_true(str_contains($batch, 'TESTsheetID_0123456789abc/values:batchGet'));
        $sum = panel_state(op())['data']['check'];
        assert_same(['games' => 40, 'matches' => 5], array_intersect_key($sum['counts'], ['games' => 1, 'matches' => 1]));
        assert_same(['sets' => true, 'matches' => true, 'predictions' => true], $sum['verified']);
        assert_same('OK', source_status()['status']);
        assert_same('Google 시트', source_status()['label']);
        // 연결 테스트는 반영하지 않고 결과만
        $t = data_test(op());
        assert_same(1, $t['anomalies']);
    } finally {
        unset($GLOBALS['CG_HTTP']);
    }
});

test('google: 권한·인증 오류는 원인별 안내, 토큰·키는 메시지·로그·화면에 없음', function () {
    fresh_db();
    $k = fx_key();
    $log = [];
    google_key_save($k['json'], op());
    data_settings_save(['source' => 'sheet', 'sheet' => 'TESTsheetID_0123456789abc'], op());
    foreach ([[['sheets' => 403], '"뷰어"로 공유'], [['sheets' => 404], '시트를 찾을 수 없습니다'], [['token' => 400], '서비스 계정 인증에 실패'],
        [['sheets' => 500], 'HTTP 500']] as [$fail, $msg]) {
        $GLOBALS['CG_HTTP'] = fx_transport(fx_tabs(), $k['pub'], $log, $fail);
        $e = assert_throws(ActionError::class, fn() => data_refresh(op()), 'SOURCE_ERROR');
        assert_true(str_contains($e->getMessage(), $msg), $e->getMessage());
        assert_true(!str_contains($e->getMessage(), 'tok-secret'), '토큰 없음');
    }
    unset($GLOBALS['CG_HTTP']);
    $everything = json_encode([panel_state(op()), data_settings_view(op()), db_all('SELECT * FROM cg_logs'), db_all('SELECT * FROM cg_settings'),
        db_all('SELECT * FROM cg_sources')], JSON_UNESCAPED_UNICODE);
    assert_true(!str_contains($everything, 'PRIVATE KEY') && !str_contains($everything, 'tok-secret'), '키·토큰이 DB·화면에 없음');
    assert_true(str_contains($everything, 'cg-reader@test-project.iam.gserviceaccount.com'), '서비스 계정 이메일만 표시');
    // 키 파일: 비밀 폴더, 0600, 접근 차단 파일
    $path = google_key_path();
    assert_same('0600', substr(sprintf('%o', fileperms($path)), -4));
    assert_true(is_file(dirname($path) . '/.htaccess'));
    google_key_remove(op());
    assert_true(!is_file($path), '키 삭제');
    assert_same(null, data_settings_view(op())['key_email']);
});

test('google: 설정 변경은 관리자만, 운영자는 새로고침·점검만', function () {
    fresh_db();
    $operator = ['name' => '운영', 'role' => 'operator', 'user_id' => 2];
    $k = fx_key();
    assert_throws(ActionError::class, fn() => google_key_save($k['json'], $operator), 'ADMIN_ONLY');
    assert_throws(ActionError::class, fn() => data_settings_save(['source' => 'sheet'], $operator), 'ADMIN_ONLY');
    assert_throws(ActionError::class, fn() => data_import_xlsx(['file' => 'eA=='], $operator), 'ADMIN_ONLY');
    assert_throws(ActionError::class, fn() => data_test($operator), 'ADMIN_ONLY');
    assert_throws(ActionError::class, fn() => google_key_remove($operator), 'ADMIN_ONLY');
    data_settings_save(['source' => 'sheet', 'sheet' => 'TESTsheetID_0123456789abc'], op());
    $v = data_settings_view($operator);
    assert_same(['설정됨', []], [$v['sheet_id'], $v['tabs']], '운영자에게 시트 주소를 보여 주지 않음');
    assert_throws(ActionError::class, fn() => data_settings_save(['source' => 'sheet', 'sheet' => 'https://example.com'], op()), 'BAD_SHEET');
    assert_throws(ActionError::class, fn() => data_settings_save(['source' => 'x'], op()), 'BAD_SOURCE');
    assert_throws(ActionError::class, fn() => google_key_save('{"type":"x"}', op()), 'BAD_KEY');
});

test('xlsx: 시트 파일 가져오기 → 날짜 서식·공유 문자열 읽기 → API와 같은 결과', function () {
    $bytes = fx_xlsx(fx_tabs());
    $tables = xlsx_tables($bytes, SHEET_TABS_DEFAULT);
    assert_same(['Winner', 'Race', 'Loser', 'Race', 'Map', 'Date'], $tables['results'][0]);
    assert_same('2024-01-06', $tables['results'][1][5], '날짜 서식 일련번호 → 날짜');
    $fromXlsx = sheet_dataset($tables, 'xlsx', true);
    $fromApi = sheet_dataset(fx_tables(), 'api');
    foreach (['games', 'matches', 'predictions', 'verify'] as $k) {
        assert_same($fromApi[$k], $fromXlsx[$k], "xlsx와 API 결과 같음: $k");
    }
    assert_throws(ProviderError::class, fn() => xlsx_tables('not a zip', SHEET_TABS_DEFAULT));
    assert_same(null, xlsx_tables($bytes, ['results' => '없는 탭'])['results']);
    // 패널 동작: 관리자 가져오기 → 소스가 Google 시트로 바뀌고 반영
    fresh_db();
    data_import_xlsx(['file' => base64_encode($bytes)], op());
    assert_same('sheet', data_source());
    assert_same('xlsx', panel_state(op())['data']['check']['method']);
    assert_same('가선수', players_cache()['가선수']['name']);
    assert_throws(ActionError::class, fn() => data_import_xlsx(['file' => base64_encode('PK broken')], op()), 'SOURCE_ERROR');
    assert_throws(ActionError::class, fn() => data_import_xlsx(['file' => '***'], op()), 'BAD_FILE');
});

test('google: 실제 HTTP 스트림으로 가짜 Google 서버와 통신 (토큰 → 탭 목록 → batchGet)', function () {
    $dir = $GLOBALS['TEST_TMP'] . '/fg-' . bin2hex(random_bytes(3));
    mkdir($dir, 0775, true);
    $port = 39900 + random_int(0, 90);
    $base = "http://127.0.0.1:$port";
    $k = fx_key("$base/token");
    file_put_contents("$dir/pub.pem", $k['pub']);
    file_put_contents("$dir/tables.json", json_encode(fx_tabs(), JSON_UNESCAPED_UNICODE));
    $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/fake_google.php'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$dir/server.log", 'a'], 2 => ['file', "$dir/server.log", 'a']],
        $pipes, __DIR__, array_merge(getenv(), ['FAKE_GOOGLE_DIR' => $dir]));
    try {
        for ($i = 0; $i < 50 && !@file_get_contents("$base/health"); $i++) {
            usleep(100000);
        }
        fresh_db(['google' => ['token_uri' => "$base/token", 'sheets_base' => "$base/v4/spreadsheets/"]]);
        $key = google_key_parse($k['json']);
        $tables = sheets_fetch_tables(['id' => 'TESTsheetID_0123456789abc', 'tabs' => SHEET_TABS_DEFAULT], $key);
        assert_same(fx_tables()['results'], $tables['results']);
        $claims = json_decode((string)file_get_contents("$dir/token_claims.json"), true);
        assert_same('cg-reader@test-project.iam.gserviceaccount.com', $claims['iss']);
        assert_true(str_contains(rawurldecode((string)file_get_contents("$dir/batch_query.txt")), "'Results'!A:F"));
        // 잘못된 키(다른 개인 키)로 서명하면 인증 실패 안내
        $other = google_key_parse(fx_key("$base/token")['json']);
        $e = assert_throws(ProviderError::class, fn() => sheets_fetch_tables(['id' => 'TESTsheetID_0123456789abc', 'tabs' => SHEET_TABS_DEFAULT], $other));
        assert_true(str_contains($e->getMessage(), '서비스 계정 인증에 실패'));
    } finally {
        proc_terminate($proc);
        proc_close($proc);
    }
});
