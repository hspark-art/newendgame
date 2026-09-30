<?php
declare(strict_types=1);

// 패치 zip 만들기: php tools/build_patch.php 이전버전.zip 새버전.zip [패치.zip]
// 두 배포 zip을 비교해 바뀐 파일만 담고, 업로드 순서(PATCH_INFO.txt)와 해시 목록(patch.json)을 넣는다.
require __DIR__ . '/release_lib.php';

if ($argc < 3) {
    fwrite(STDERR, "사용법: php tools/build_patch.php 이전버전.zip 새버전.zip [패치.zip]\n");
    exit(2);
}
$out = $argv[3] ?? dirname($argv[2]) . '/' . basename($argv[2], '.zip') . '_patch.zip';
$r = patch_build($argv[1], $argv[2], $out);
printf("%s\n추가 %d · 변경 %d · 삭제 %d\n", $r['zip'], count($r['added']), count($r['changed']), count($r['removed']));
