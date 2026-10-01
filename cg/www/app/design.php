<?php
declare(strict_types=1);

/**
 * CG 디자인 (v0.5): 테마 1개 + 역할별 폰트(제목·이름·숫자) + 크기 배율 + 글자색 + 프리셋.
 * - 모든 CG·모든 송출 화면에 공통으로 하나만 적용된다 (cg_settings 'cg_design').
 * - 송출 화면은 CSP로 인라인 style을 막으므로 output.js가 CSS 변수로 적용한다 (assets/cg.css 변수 목록).
 * - 바꾸면 PREVIEW·PROGRAM 모두 바로 바뀐다 (데이터가 아니라 모양이므로 TAKE를 기다리지 않음). 관리자만 바꾼다.
 * - 폰트는 모두 SIL OFL 1.1 — 파일과 라이선스는 assets/fonts/ (README.txt).
 */

const CG_THEMES = ['' => '현재 디자인', 'play' => 'A 플레이 화이트', 'night' => 'B 나이트 블루', 'pop' => 'C 블루 그라데이션',
    'four' => 'D 구글 4색', 'sky' => 'E 스카이 볼드'];

/** 폰트: id => [화면 이름, CSS font-family (한글 없는 글자는 기본 글꼴로), 한글 지원] — @font-face는 assets/cg-fonts.css */
const CG_FONTS = [
    'default' => ['기본 (맑은 고딕)', 'var(--cg-font)', true],
    'pretendard' => ['프리텐다드', '"Pretendard", var(--cg-font)', true],
    'noto' => ['본고딕 (Noto Sans KR)', '"Noto Sans KR", var(--cg-font)', true],
    'nanum' => ['나눔고딕', '"NanumGothic", var(--cg-font)', true],
    'gothica1' => ['고딕 A1', '"Gothic A1", var(--cg-font)', true],
    'plex' => ['IBM Plex Sans KR', '"IBM Plex Sans KR", var(--cg-font)', true],
    'gowun' => ['고운돋움', '"Gowun Dodum", var(--cg-font)', true],
    'blackhan' => ['검은고딕', '"Black Han Sans", var(--cg-font)', true],
    'dohyeon' => ['도현', '"Do Hyeon", var(--cg-font)', true],
    'jua' => ['주아', '"Jua", var(--cg-font)', true],
    'sunflower' => ['해바라기', '"Sunflower", var(--cg-font)', true],
    'orbit' => ['오르빗', '"Orbit", var(--cg-font)', true],
    'dongle' => ['동글', '"Dongle", var(--cg-font)', true],
    'oswald' => ['Oswald', '"Oswald", var(--cg-font)', false],
    'bebas' => ['Bebas Neue', '"Bebas Neue", var(--cg-font)', false],
    'barlow' => ['Barlow Condensed', '"Barlow Condensed", var(--cg-font)', false],
    'rajdhani' => ['Rajdhani', '"Rajdhani", var(--cg-font)', false],
    'teko' => ['Teko', '"Teko", var(--cg-font)', false],
];

/** 크기 배율(%): 키 => [이름, 최소, 최대] → CSS 변수 --k-<키> */
const CG_SIZES = ['w' => ['박스 폭', 80, 140], 'h' => ['박스·줄 높이', 80, 140], 'title' => ['제목 글자', 70, 140],
    'name' => ['이름 글자', 70, 140], 'num' => ['숫자 글자', 70, 140]];

/** 글자색: 키 => 이름 → CSS 변수 --c-<키>. 지정하지 않으면 테마 색 */
const CG_COLORS = ['title' => '제목', 'text' => '이름·글자', 'nick' => '닉네임', 'num' => '숫자 (전적·스코어)', 'sub' => '보조 (승률·항목)',
    'rank' => '순위', 'accent' => '강조 (승자·1위)', 'plus' => '+ 값 (수익률)', 'minus' => '− 값 (수익률)'];

const CG_DESIGN_PRESET_MAX = 30;

function design_default(): array
{
    return ['theme' => '', 'fonts' => ['title' => 'default', 'name' => 'default', 'num' => 'default'], 'k' => [], 'colors' => []];
}

/**
 * 입력 검증 (모르는 값은 조용히 바꾸지 않고 거부한다). 크기 100%·지정 안 한 색은 저장하지 않는다.
 * @throws ActionError
 */
function design_normalize(mixed $in): array
{
    if (!is_array($in)) {
        throw new ActionError('BAD_DESIGN', '디자인 값 형식이 올바르지 않습니다.', 422);
    }
    $d = design_default();
    $theme = (string)($in['theme'] ?? '');
    if (!array_key_exists($theme, CG_THEMES)) {
        throw new ActionError('BAD_DESIGN', '알 수 없는 테마입니다.', 422);
    }
    $d['theme'] = $theme;
    foreach (['title', 'name', 'num'] as $role) {
        $f = (string)($in['fonts'][$role] ?? 'default');
        if (!isset(CG_FONTS[$f])) {
            throw new ActionError('BAD_DESIGN', '알 수 없는 폰트입니다.', 422);
        }
        $d['fonts'][$role] = $f;
    }
    foreach (is_array($in['k'] ?? null) ? $in['k'] : [] as $k => $v) {
        if (!isset(CG_SIZES[$k]) || !(is_int($v) || (is_string($v) && preg_match('/^\d{2,3}$/D', $v)))) {
            throw new ActionError('BAD_DESIGN', '크기 값이 올바르지 않습니다.', 422);
        }
        [, $min, $max] = CG_SIZES[$k];
        if ((int)$v < $min || (int)$v > $max) {
            throw new ActionError('BAD_DESIGN', CG_SIZES[$k][0] . " 크기는 {$min}~{$max}% 사이로 정하세요.", 422);
        }
        if ((int)$v !== 100) {
            $d['k'][$k] = (int)$v;
        }
    }
    foreach (is_array($in['colors'] ?? null) ? $in['colors'] : [] as $k => $v) {
        if (!isset(CG_COLORS[$k]) || !is_string($v) || !preg_match('/^#[0-9a-fA-F]{6}$/D', $v)) {
            throw new ActionError('BAD_DESIGN', '글자색 값이 올바르지 않습니다.', 422);
        }
        $d['colors'][$k] = strtolower($v);
    }
    ksort($d['k']);
    ksort($d['colors']);
    return $d;
}

/** 지금 디자인. 저장값이 깨져 있으면 기본 디자인 (송출 화면은 항상 그려져야 한다) */
function design_get(): array
{
    try {
        return design_normalize(json_dec(setting_get('cg_design', 'null')) ?? design_default());
    } catch (ActionError) {
        return design_default();
    }
}

/** 송출 화면에 보내는 값: body 클래스용 테마 + CSS 변수 + 미리 불러올 폰트 이름 */
function design_payload(?array $d = null): array
{
    $d ??= design_get();
    $vars = [];
    $families = [];
    foreach ($d['fonts'] as $role => $f) {
        $vars["--cg-font-$role"] = CG_FONTS[$f][1];
        if (preg_match('/^"([^"]+)"/', CG_FONTS[$f][1], $m)) {
            $families[$m[1]] = true;
        }
    }
    foreach (CG_SIZES as $k => $_) {
        $vars["--k-$k"] = (string)(($d['k'][$k] ?? 100) / 100);
    }
    foreach ($d['colors'] as $k => $c) {
        $vars["--c-$k"] = $c;
    }
    return ['theme' => $d['theme'], 'vars' => $vars, 'families' => array_keys($families)];
}

/** @return list<array{name:string, design:array, by:string, at:string}> */
function design_presets(): array
{
    $list = json_dec(setting_get('cg_design_presets', '[]'));
    return is_array($list) ? array_values(array_filter($list, static fn($p) => is_array($p) && isset($p['name'], $p['design']))) : [];
}

/** 조작 패널 'CG 디자인' 창 */
function design_view(array $op): array
{
    return [
        'admin' => ($op['role'] ?? '') === 'admin',
        'current' => design_get(),
        'presets' => design_presets(),
        'themes' => CG_THEMES,
        'fonts' => array_map(static fn($f) => ['name' => $f[0], 'family' => $f[1], 'ko' => $f[2]], CG_FONTS),
        'sizes' => array_map(static fn($s) => ['name' => $s[0], 'min' => $s[1], 'max' => $s[2]], CG_SIZES),
        'colors' => CG_COLORS,
    ];
}

/** 송출 화면에 적용 (관리자). PREVIEW·PROGRAM 모두 바로 바뀐다 */
function design_save(array $in, array $op): array
{
    require_admin_op($op);
    $d = design_normalize($in['design'] ?? null);
    db_tx(function () use ($d, $op) {
        setting_set('cg_design', json_enc($d));
        cg_log('control', 'DESIGN', $op, ['detail' => 'CG 디자인 적용: ' . design_summary($d)]);
        state_bump(['preview', 'program']);
    });
    return design_view($op);
}

/** 프리셋 저장 (같은 이름이면 덮어씀, 관리자) */
function design_preset_save(array $in, array $op): array
{
    require_admin_op($op);
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 20 || preg_match('/[\x00-\x1F\x7F<>"]/u', $name)) {
        throw new ActionError('BAD_PRESET', '프리셋 이름은 20자 이내 글자로 입력하세요.', 422);
    }
    $d = design_normalize($in['design'] ?? null);
    $list = array_values(array_filter(design_presets(), static fn($p) => $p['name'] !== $name));
    if (count($list) >= CG_DESIGN_PRESET_MAX) {
        throw new ActionError('TOO_MANY', '프리셋은 ' . CG_DESIGN_PRESET_MAX . '개까지 저장할 수 있습니다. 쓰지 않는 프리셋을 지우세요.', 422);
    }
    $list[] = ['name' => $name, 'design' => $d, 'by' => (string)($op['name'] ?? ''), 'at' => now()];
    db_tx(function () use ($list, $name, $d, $op) {
        setting_set('cg_design_presets', json_enc($list));
        cg_log('control', 'DESIGN_PRESET', $op, ['detail' => "프리셋 저장: $name (" . design_summary($d) . ')']);
    });
    return design_view($op);
}

function design_preset_remove(array $in, array $op): array
{
    require_admin_op($op);
    $name = (string)($in['name'] ?? '');
    $list = design_presets();
    $left = array_values(array_filter($list, static fn($p) => $p['name'] !== $name));
    if (count($left) === count($list)) {
        throw new ActionError('NO_PRESET', '없는 프리셋입니다.', 404);
    }
    db_tx(function () use ($left, $name, $op) {
        setting_set('cg_design_presets', json_enc($left));
        cg_log('control', 'DESIGN_PRESET', $op, ['detail' => "프리셋 삭제: $name"]);
    });
    return design_view($op);
}

/** 로그용 한 줄: "B 나이트 블루 · 제목 검은고딕 · 숫자 Oswald · 숫자 글자 106%" */
function design_summary(array $d): string
{
    $parts = [CG_THEMES[$d['theme']]];
    foreach (['title' => '제목', 'name' => '이름', 'num' => '숫자'] as $role => $label) {
        if ($d['fonts'][$role] !== 'default') {
            $parts[] = "$label " . CG_FONTS[$d['fonts'][$role]][0];
        }
    }
    foreach ($d['k'] as $k => $v) {
        $parts[] = CG_SIZES[$k][0] . " $v%";
    }
    if ($d['colors']) {
        $parts[] = '글자색 ' . count($d['colors']) . '개 지정';
    }
    return implode(' · ', $parts);
}
