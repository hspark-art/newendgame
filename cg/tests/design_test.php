<?php
declare(strict_types=1);

// v0.5 CG 디자인: 테마·폰트·크기·글자색 검증, 관리자만 적용, 송출 화면 반영, 프리셋, 폰트 파일·라이선스

test('CG 디자인: 값 검증 (모르는 테마·폰트, 범위 밖 크기, 잘못된 색은 거부), 100%·기본은 저장하지 않음', function () {
    $d = design_normalize(['theme' => 'night', 'fonts' => ['title' => 'blackhan', 'num' => 'oswald'],
        'k' => ['num' => '106', 'w' => 100], 'colors' => ['accent' => '#FBBC04']]);
    assert_same(['theme' => 'night', 'fonts' => ['title' => 'blackhan', 'name' => 'default', 'num' => 'oswald'],
        'k' => ['num' => 106], 'colors' => ['accent' => '#fbbc04']], $d);
    foreach ([['theme' => 'neon'], ['fonts' => ['title' => 'comic']], ['k' => ['w' => 150]], ['k' => ['zoom' => 100]],
        ['colors' => ['accent' => 'red']], ['colors' => ['bg' => '#000000']], 'x'] as $bad) {
        assert_throws(ActionError::class, fn() => design_normalize($bad), 'BAD_DESIGN');
    }
    $p = design_payload($d);
    assert_same('night', $p['theme']);
    assert_same(['Black Han Sans', 'Oswald'], $p['families']);
    assert_same(['"Black Han Sans", var(--cg-font)', 'var(--cg-font)', '1.06', '1', '#fbbc04'],
        [$p['vars']['--cg-font-title'], $p['vars']['--cg-font-name'], $p['vars']['--k-num'], $p['vars']['--k-w'], $p['vars']['--c-accent']]);
    assert_same('B 나이트 블루 · 제목 검은고딕 · 숫자 Oswald · 숫자 글자 106% · 글자색 1개 지정', design_summary($d));
});

test('CG 디자인: 관리자만 적용, 적용하면 PREVIEW·PROGRAM 송출 화면에 바로, 깨진 저장값은 기본 디자인', function () {
    fresh_db();
    $operator = ['name' => '운영', 'role' => 'operator', 'user_id' => 2];
    assert_same(design_default(), design_get());
    assert_same('', output_payload('program')['design']['theme'], '처음은 지금 디자인');
    assert_throws(ActionError::class, fn() => design_save(['design' => ['theme' => 'sky']], $operator));
    assert_same(false, design_view($operator)['admin']);
    $rev = channel_get('program')['rev'];
    $v = design_save(['design' => ['theme' => 'sky', 'fonts' => ['name' => 'pretendard'], 'k' => ['title' => 110]]], op());
    assert_same('sky', $v['current']['theme']);
    assert_true(channel_get('program')['rev'] > $rev && channel_get('preview')['rev'] > $rev, '송출 화면이 다시 받아 가도록');
    foreach (['program', 'preview'] as $ch) {
        $d = output_payload($ch)['design'];
        assert_same(['sky', '"Pretendard", var(--cg-font)', '1.1'], [$d['theme'], $d['vars']['--cg-font-name'], $d['vars']['--k-title']]);
    }
    assert_true(str_contains(db_value("SELECT detail FROM cg_logs WHERE action = 'DESIGN' ORDER BY id DESC"), 'E 스카이 볼드'));
    setting_set('cg_design', '{"theme":"neon"}');
    assert_same(design_default(), design_get(), '깨진 값이면 송출 화면은 기본 디자인으로 그린다');
});

test('CG 디자인 프리셋: 저장(같은 이름은 덮어씀)·삭제는 관리자만, 30개까지', function () {
    fresh_db();
    $operator = ['name' => '운영', 'role' => 'operator', 'user_id' => 2];
    design_preset_save(['name' => '결승전', 'design' => ['theme' => 'play']], op());
    $v = design_preset_save(['name' => '결승전', 'design' => ['theme' => 'pop']], op());
    assert_same([['결승전', 'pop']], array_map(static fn($p) => [$p['name'], $p['design']['theme']], $v['presets']));
    assert_throws(ActionError::class, fn() => design_preset_save(['name' => 'x', 'design' => []], $operator));
    assert_throws(ActionError::class, fn() => design_preset_remove(['name' => '결승전'], $operator));
    assert_throws(ActionError::class, fn() => design_preset_save(['name' => '<b>', 'design' => []], op()), 'BAD_PRESET');
    for ($i = 1; $i < CG_DESIGN_PRESET_MAX; $i++) {
        design_preset_save(['name' => "p$i", 'design' => []], op());
    }
    assert_throws(ActionError::class, fn() => design_preset_save(['name' => '하나 더', 'design' => []], op()), 'TOO_MANY');
    design_preset_save(['name' => 'p1', 'design' => ['theme' => 'four']], op()); // 덮어쓰기는 개수 제한과 무관
    assert_same(CG_DESIGN_PRESET_MAX - 1, count(design_preset_remove(['name' => '결승전'], op())['presets']));
    assert_throws(ActionError::class, fn() => design_preset_remove(['name' => '결승전'], op()), 'NO_PRESET');
});

test('CG 폰트: 모든 폰트에 @font-face와 파일, 파일마다 OFL 라이선스, PC 내장 서버도 폰트 주소 허용', function () {
    $css = file_get_contents(WWW_DIR . '/assets/cg-fonts.css');
    preg_match_all('/font-family: "([^"]+)";[^}]*url\("fonts\/([^"]+)"\)/', $css, $m, PREG_SET_ORDER);
    $families = array_unique(array_column($m, 1));
    foreach (CG_FONTS as $id => [$name, $family]) {
        if ($id !== 'default') {
            assert_true(preg_match('/^"([^"]+)"/', $family, $f) === 1 && in_array($f[1], $families, true), "$name @font-face");
        }
    }
    $files = array_map('basename', glob(WWW_DIR . '/assets/fonts/*.{woff2,ttf}', GLOB_BRACE));
    sort($files);
    $used = array_unique(array_column($m, 2));
    sort($used);
    assert_same($files, $used, '폰트 파일 = cg-fonts.css가 쓰는 파일');
    assert_same(17, count(glob(WWW_DIR . '/assets/fonts/licenses/*-OFL.txt')));
    foreach (glob(WWW_DIR . '/assets/fonts/licenses/*.txt') as $lic) {
        assert_true(str_contains(file_get_contents($lic), 'SIL OPEN FONT LICENSE Version 1.1'), basename($lic));
    }
    assert_true(router_allowed('/assets/fonts/NotoSansKR-Variable.woff2') && router_allowed('/assets/fonts/NanumGothic-Bold.ttf')
        && router_allowed('/assets/cg-fonts.css') && router_allowed('/design.php'));
    assert_true(!router_allowed('/assets/fonts/../app/config.php') && !router_allowed('/assets/fonts/licenses/jua-OFL.txt')
        && !router_allowed('/assets/fonts/x.php'));
});
