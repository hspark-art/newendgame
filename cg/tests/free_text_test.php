<?php
declare(strict_types=1);

// v0.5.1 자유 입력 CG: 빈 양식에 직접 입력, 양식 번호마다 따로, 제목만 넣으면 제목 띠만

test('자유 입력: 제목을 넣기 전에는 송출 차단, 입력한 줄만 표시(가운데/양쪽), 모든 값 이스케이프', function () {
    setup_sheet();
    $st = type_state('free-text', []);
    assert_same(['제목: 값이 없습니다.'], $st['problems'], '빈 양식은 제목 입력 안내');
    $iid = channel_get('preview')['instance_id'];
    preview_save($iid, ['title' => '오늘의 <관전 포인트>', 'r1.text' => '첫 맞대결', 'r3.text' => '상금', 'r3.sub' => '1,000만 원'], op());
    $st = instance_state(instance_get($iid), current_session_id());
    assert_same([], $st['problems']);
    assert_same([['text' => '첫 맞대결', 'sub' => ''], ['text' => '상금', 'sub' => '1,000만 원']], $st['view']['lines'], '빈 2행은 건너뜀');
    $html = cg_render($st['view']);
    assert_true(str_contains($html, '오늘의 &lt;관전 포인트&gt;') && !str_contains($html, '<관전'));
    assert_true(str_contains($html, 'class="fr-line is-center"') && str_contains($html, '<span class="fr-sub">'));
    assert_true(!str_contains($html, 'style='), '인라인 style 없음 (CSP)');
    // 제목만: 줄 영역 없이 제목 띠만
    preview_save($iid, ['title' => '잠시 후 3세트'], op());
    override_reset($iid, null, op());
    preview_save($iid, ['title' => '잠시 후 3세트'], op());
    $st = instance_state(instance_get($iid), current_session_id());
    assert_same([[], []], [$st['problems'], $st['view']['lines']]);
    assert_true(!str_contains(cg_render($st['view']), 'fr-lines'));
});

test('자유 입력: 양식 번호가 다르면 내용이 따로, 페이지 추가 때 다음 번호가 기본값', function () {
    setup_sheet();
    $tpl = static fn() => array_column(panel_state(op())['templates'], null, 'slug')['free-text']['params'][0];
    assert_same(1, $tpl()['default'], '처음은 1번');
    $a = page_add(['template' => 'free-text', 'params' => []], op());
    assert_same(2, $tpl()['default'], '1번을 쓰면 다음은 2번');
    $b = page_add(['template' => 'free-text', 'params' => ['no' => '2']], op());
    assert_true(rundown_get($a['id'])['instance_id'] !== rundown_get($b['id'])['instance_id'], '번호가 다르면 다른 CG');
    assert_same('양식 2', array_column(panel_state(op())['rundown'], 'summary', 'page_no')[$b['page_no']]);
    assert_true(!array_key_exists('default_next', $tpl()), '패널에는 내부 표시를 보내지 않음');
});
