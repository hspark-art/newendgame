<?php
declare(strict_types=1);

/*
 * #14 자유 입력 — 빈 양식에 원하는 글자를 직접 넣는 CG. 데이터와 연결되지 않고 타이틀 에디터의 MANUAL 칸에 입력한다.
 * 제목(필수) + 1~5줄. 줄마다 [글자] 하나면 가운데, [글자 + 오른쪽 값]이면 양쪽으로 나눈다. 제목만 넣으면 제목 띠만 나간다.
 * 양식 번호가 같은 페이지는 같은 내용을 쓴다 (페이지 추가 때 비어 있는 다음 번호가 자동으로 들어간다).
 */
return [
    'slug' => 'free-text',
    'name' => '자유 입력',
    'short' => '자유',
    'order' => 14,
    'params' => [
        ['key' => 'no', 'label' => '양식 번호 (자유 입력 페이지마다 다른 번호)', 'type' => 'int', 'min' => 1, 'max' => 99, 'default' => 1,
            'default_next' => true],
    ],
    'fields' => ['title' => ['label' => '제목', 'type' => 'text', 'max' => 40]] + row_fields(5, [
        'text' => ['label' => '글자', 'type' => 'text', 'max' => 40],
        'sub' => ['label' => '오른쪽 값 (비우면 글자를 가운데)', 'type' => 'text', 'max' => 20],
    ]),
    'auto' => static fn(array $p, array $ds): array => [], // 데이터 없음: 모두 직접 입력
    'summary' => static fn(array $p, array $ctx): string => '양식 ' . $p['no'],
    'present' => static function (array $f): array {
        $lines = [];
        for ($i = 1; $i <= 5; $i++) {
            $r = row_values($f, $i, ['text', 'sub']); // 뺀 항목은 비어 있다 — 둘 다 비면 줄 없음
            if ($r !== null) {
                $lines[] = ['text' => (string)$r['text'], 'sub' => (string)$r['sub']];
            }
        }
        return ['title' => (string)$f['title'], 'lines' => $lines];
    },
];
