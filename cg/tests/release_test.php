<?php
declare(strict_types=1);

// 배포 zip·패치 zip

require_once dirname(__DIR__) . '/tools/release_lib.php';

function unzip_to(string $zip, string $dir): string
{
    $z = new ZipArchive();
    $z->open($zip);
    $z->extractTo($dir);
    $top = rtrim(explode('/', (string)$z->getNameIndex(0))[0], '/');
    $z->close();
    return "$dir/$top";
}

test('release: PC·웹 zip 구성과 MANIFEST 일치', function () {
    $out = $GLOBALS['TEST_TMP'] . '/dist-' . bin2hex(random_bytes(3));
    $cg = dirname(__DIR__);
    $pc = release_build($cg, 'pc', $out);
    $web = release_build($cg, 'web', $out);
    assert_same('EndgameCG_PC_v' . APP_VERSION, $pc['name']);
    $pcFiles = zip_entries($pc['zip']);
    foreach (['시작.bat', '시작-LAN.bat', '종료.bat', 'PHP준비.bat', 'launcher/start.bat', 'launcher/setup-php.ps1', 'router.php',
        'config.desktop.php', 'runtime/php.ini', 'README_KR.txt', 'www/index.php', 'www/output.php', 'www/app/bootstrap.php',
        'www/app/data/mock/matches.json', 'VERSION.json', 'MANIFEST.sha256'] as $f) {
        assert_true(isset($pcFiles[$f]), "PC zip에 $f");
    }
    foreach (['www/admin.php', 'www/install.php', 'www/app/config.php', 'www/app/config.sample.php', 'www/assets/portal.css'] as $f) {
        assert_true(!isset($pcFiles[$f]), "PC zip에 없어야 함: $f");
    }
    assert_true(str_contains($pcFiles['시작.bat'], "\r\n"), '.bat은 CRLF 유지');
    $webFiles = zip_entries($web['zip']);
    foreach (['www/install.php', 'www/admin.php', 'www/.htaccess', 'www/app/.htaccess', 'www/app/config.sample.php',
        'www/app/manifest.sha256', 'INSTALL_KR.md', 'PATCHING_KR.md', 'VERSION.json'] as $f) {
        assert_true(isset($webFiles[$f]), "웹 zip에 $f");
    }
    foreach (['www/app/config.php', '시작.bat', 'router.php'] as $f) {
        assert_true(!isset($webFiles[$f]), "웹 zip에 없어야 함: $f");
    }
    assert_true(!array_filter(array_keys($webFiles), fn($k) => str_starts_with($k, 'www/app/storage/')), 'storage 제외');
    assert_same('web', json_decode($webFiles['VERSION.json'], true)['package']);
    // 패키지 MANIFEST가 실제 내용과 일치
    foreach ([$pcFiles, $webFiles] as $files) {
        foreach (explode("\n", trim($files['MANIFEST.sha256'])) as $line) {
            [$hash, $rel] = explode('  ', $line, 2);
            assert_same($hash, hash('sha256', $files[$rel]), "MANIFEST: $rel");
        }
        assert_same(count($files) - 1, count(explode("\n", trim($files['MANIFEST.sha256']))), 'MANIFEST 항목 수');
    }
    // 풀어 놓은 웹 www 폴더에서 서버 무결성 검사 통과 → 파일 하나 바꾸면 검출
    $dir = unzip_to($web['zip'], "$out/x");
    assert_same('ok', release_verify("$dir/www")['status']);
    unlink("$dir/www/install.php");
    assert_same('ok', release_verify("$dir/www")['status'], 'install.php 삭제는 정상');
    file_put_contents("$dir/www/assets/panel.js", '// broken upload');
    unlink("$dir/www/app/control.php");
    $r = release_verify("$dir/www");
    assert_same(['app/control.php'], $r['missing']);
    assert_same(['assets/panel.js'], $r['changed']);
});

test('release: 패치 zip은 바뀐 파일만, 버전 파일은 마지막', function () {
    $out = $GLOBALS['TEST_TMP'] . '/patch-' . bin2hex(random_bytes(3));
    $web = release_build(dirname(__DIR__), 'web', $out);
    // 새 버전 흉내: 파일 하나 변경 + 하나 추가 + 하나 삭제 + 버전 올림
    $new = "$out/new.zip";
    copy($web['zip'], $new);
    $z = new ZipArchive();
    $z->open($new);
    $top = $web['name'];
    $z->addFromString("$top/www/assets/panel.css", "/* changed */\n");
    $z->addFromString("$top/www/assets/extra.js", "// new\n");
    $z->deleteName("$top/www/app/data/mock/players.json");
    $v = json_decode((string)$z->getFromName("$top/www/app/version.json"), true);
    $v['version'] = '0.1.1';
    $z->addFromString("$top/www/app/version.json", json_encode($v));
    $pv = json_decode((string)$z->getFromName("$top/VERSION.json"), true);
    $pv['version'] = '0.1.1';
    $z->addFromString("$top/VERSION.json", json_encode($pv));
    $z->addFromString("$top/www/app/manifest.sha256", "changed\n");
    $z->close();
    $r = patch_build($web['zip'], $new, "$out/patch.zip");
    assert_same(['www/assets/extra.js'], $r['added']);
    assert_same(['www/assets/panel.css', 'www/app/manifest.sha256', 'www/app/version.json', 'VERSION.json'], $r['changed']);
    assert_same(['www/app/data/mock/players.json'], $r['removed']);
    $p = zip_entries("$out/patch.zip");
    assert_true(isset($p['PATCH_INFO.txt'], $p['patch.json'], $p['files/www/assets/panel.css']));
    assert_true(!isset($p['files/www/index.php']), '안 바뀐 파일은 제외');
    $meta = json_decode($p['patch.json'], true);
    assert_same(['0.1.0', '0.1.1'], [$meta['from'], $meta['to']]);
    assert_true(str_contains($p['PATCH_INFO.txt'], 'config.php 는 절대 덮어쓰지'), '안내문');
    $pc = release_build(dirname(__DIR__), 'pc', $out);
    assert_throws(RuntimeException::class, fn() => patch_build($pc['zip'], $new, "$out/bad.zip"));
});
