<?php
declare(strict_types=1);

// 배포 zip 만들기: php tools/build_release.php [출력 폴더 = dist]
// PC zip과 웹 zip을 만들고, 각각 VERSION.json·MANIFEST.sha256 을 넣는다.
require __DIR__ . '/release_lib.php';

$cg = dirname(__DIR__);
$out = $argv[1] ?? "$cg/dist";
foreach (['pc', 'web'] as $kind) {
    $r = release_build($cg, $kind, $out);
    printf("%-4s %s (%d개 파일)\n", strtoupper($kind), $r['zip'], $r['count']);
}
