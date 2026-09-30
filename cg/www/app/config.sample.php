<?php
// 웹 버전 설정 견본. 같은 폴더에 config.php 로 복사한 뒤 DB 정보를 입력하세요.
// config.php 는 서버 접속 정보가 들어가므로 저장소·다른 사람에게 공유하지 않습니다.
return [
    'mode' => 'web',
    'db' => [
        'driver' => 'mysql',          // 호스팅 MySQL/MariaDB. SQLite를 쓰려면 'sqlite' 와 'path'
        'host' => 'localhost',
        'port' => 3306,
        'name' => '',                 // DB 이름
        'user' => '',                 // DB 아이디
        'pass' => '',                 // DB 비밀번호
        // 'path' => __DIR__ . '/storage/cg.sqlite',
    ],
    'session_path' => '',             // 로그아웃이 잦으면 웹 폴더 밖의 쓰기 가능한 폴더 지정
    'debug' => false,                 // true면 오류 내용을 화면에 표시 (점검할 때만)
];
