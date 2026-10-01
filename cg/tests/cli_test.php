<?php
declare(strict_types=1);

// 서버 점검 명령 (app/cli.php): SSH에서 실행. 설정·DB 구조·폴더·웹 보호 확인, 웹으로는 열리지 않음

require_once __DIR__ . '/http_lib.php';

/** @return array{0:int, 1:string} [종료 코드, 출력] */
function cli_run(array $args, string $config): array
{
    $p = proc_open(array_merge([PHP_BINARY, dirname(__DIR__) . '/www/app/cli.php'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes, null, array_merge(getenv(), ['CG_CONFIG' => $config]));
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($p), $out];
}

test('서버 점검(cli.php): 첫 설치 → DB 구조 업데이트 → 관리자 안내, 설정 없음은 실패, 웹에서는 차단', function () {
    $dir = $GLOBALS['TEST_TMP'] . '/cli-' . bin2hex(random_bytes(3));
    mkdir($dir, 0775, true);
    file_put_contents("$dir/config.php", '<?php return ' . var_export(['mode' => 'web',
        'db' => ['driver' => 'sqlite', 'path' => "$dir/cg.sqlite"], 'storage_dir' => "$dir/data", 'secrets_dir' => "$dir/secrets"], true) . ';');
    $latest = max(array_keys(migrations()));
    [$code, $out] = cli_run(['check'], "$dir/config.php");
    assert_same(0, $code, $out);
    assert_true(str_contains($out, "[주의] DB 구조 0 → $latest 업데이트 필요"), $out);
    assert_true(str_contains($out, "[정상] 키 보관(secrets_dir) $dir/secrets"), '웹 폴더 밖 키 폴더는 정상');
    [$code, $out] = cli_run(['migrate'], "$dir/config.php");
    assert_true($code === 0 && str_contains($out, "DB 구조 0 → $latest"), $out);
    [$code, $out] = cli_run(['check'], "$dir/config.php");
    assert_true($code === 0 && str_contains($out, "[정상] DB 구조 $latest (최신)") && str_contains($out, '관리자 계정이 없습니다'), $out);
    assert_true(!str_contains($out, 'PRIVATE KEY') && str_contains($out, '점검 완료: 실패 없음'));
    // 데이터·키 폴더를 지정하지 않으면 웹 폴더 안(app/storage) → 주의 (Apache .htaccess로만 막힘)
    file_put_contents("$dir/config2.php", '<?php return ' . var_export(['mode' => 'web',
        'db' => ['driver' => 'sqlite', 'path' => "$dir/cg.sqlite"]], true) . ';');
    [, $out] = cli_run(['check'], "$dir/config2.php");
    assert_true(str_contains($out, '[주의] 키 보관(secrets_dir)') && str_contains($out, '웹 폴더 안입니다'), $out);
    [$code, $out] = cli_run(['check'], "$dir/없음.php");
    assert_true($code === 1 && str_contains($out, '[실패] 설정 파일이 없습니다'), $out);
    [$code] = cli_run(['drop-all'], "$dir/config.php");
    assert_same(2, $code, '모르는 명령은 실행하지 않음');
    // 웹: app/cli.php 는 열리지 않고, --url 점검이 차단 여부를 확인한다 (테스트 라우터 = Apache .htaccess 흉내)
    $srv = new TestServer(['mode' => 'web', 'db' => ['driver' => 'sqlite', 'path' => "$dir/web.sqlite"], 'storage_dir' => "$dir/web"],
        __DIR__ . '/web_router.php');
    try {
        assert_same(403, (new Client($srv->base))->get('/app/cli.php')['status']);
        [$code, $out] = cli_run(['check', '--url=' . $srv->base], "$dir/config.php");
        assert_true($code === 0 && str_contains($out, '[정상] 웹에서 /app/config.php 차단됨') && str_contains($out, 'https가 아닙니다'), $out);
    } finally {
        $srv->stop();
    }
});
