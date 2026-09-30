<?php
// PC 응용프로그램 설정. 시작.bat 이 CG_CONFIG 환경변수로 이 파일을 지정합니다.
// 작업 데이터는 프로그램 폴더 밖(%LOCALAPPDATA%\EndgameCG)에 저장하므로
// 새 버전 폴더로 바꿔도 페이지 리스트·수정값이 유지됩니다.
$dataDir = getenv('CG_DATA_DIR') ?: __DIR__ . '/data';

return [
    'mode' => 'desktop',
    'db' => [
        'driver' => 'sqlite',
        'path' => $dataDir . '/cg.sqlite',
    ],
    'storage_dir' => $dataDir,
    'operator' => '운영자',           // 로그에 남는 운영자 이름
    'debug' => false,
];
