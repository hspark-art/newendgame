<?php
declare(strict_types=1);

/**
 * 교차 검증 결과(sheet_data.php의 $ds['verify']) → CG 필드별 송출 차단 사유.
 * 사유 = {fields: [필드 키], msg}. 그 필드들에 운영자 수동값이 모두 있으면 송출할 수 있다 (template_problems).
 * MOCK 데이터는 검증 대상이 아니므로($ds['verify'] = null) 사유가 없다.
 */

function verify_issue(array $fields, string $msg): array
{
    return ['fields' => array_values($fields), 'msg' => $msg];
}

/** 목록형 CG의 행 필드 키: row_keys(['wins', 'losses']) → r1.wins, r1.losses, … r5.losses */
function row_keys(array $subs, int $rows = 5): array
{
    $out = [];
    for ($i = 1; $i <= $rows; $i++) {
        foreach ($subs as $k) {
            $out[] = "r$i.$k";
        }
    }
    return $out;
}

/** 순위 후보: 종족 필터(주 종족)에 맞는 선수 id. null이면 전체 */
function players_of_race(array $players, ?string $race): array
{
    return array_keys(array_filter($players, static fn($p) => $race === null || ($p['race'] ?? null) === $race));
}

/** 세트 전적 ($key = all | P | T | Z) */
function verify_sets(array $ds, string $pid, string $key, array $fields): array
{
    $v = $ds['verify'] ?? null;
    if ($v === null) {
        return [];
    }
    $name = pname($ds['players'], $pid);
    if (!$v['sets']['available']) {
        return [verify_issue($fields, '세트 전적을 시트 집계(Players 탭)와 대조할 수 없습니다')];
    }
    if (($v['sets']['players'][$pid][$key] ?? false) !== true) {
        return [verify_issue($fields, "$name 세트 전적" . ($key === 'all' ? '' : "(vs $key)") . '이 시트 집계와 다릅니다')];
    }
    return [];
}

/** 선수의 끝장전 목록 (날짜·상대·세트 스코어). 이상 경기가 있는 선수도 확실하지 않은 것으로 본다 */
function verify_matches(array $ds, string $pid, array $fields): array
{
    $v = $ds['verify'] ?? null;
    if ($v === null) {
        return [];
    }
    $name = pname($ds['players'], $pid);
    if (!$v['matches']['available']) {
        return [verify_issue($fields, '끝장전 기록을 시트 집계(상대전적조회NEW 탭)와 대조할 수 없습니다')];
    }
    if (($v['matches']['players'][$pid] ?? false) !== true) {
        return [verify_issue($fields, "$name 끝장전 기록이 시트 집계와 다르거나 이상 경기가 있습니다")];
    }
    return [];
}

/** 더블 찬스 성공·시도 (선수별 통계 탭) */
function verify_double(array $ds, string $pid, array $fields): array
{
    $v = $ds['verify'] ?? null;
    if ($v === null) {
        return [];
    }
    if (!($v['double']['available'] ?? false)) {
        return [verify_issue($fields, '더블 찬스를 시트 집계(선수별 통계 탭)와 대조할 수 없습니다')];
    }
    if (($v['double']['players'][$pid] ?? false) !== true) {
        return [verify_issue($fields, pname($ds['players'], $pid) . ' 더블 찬스 기록이 시트 집계와 다릅니다')];
    }
    return [];
}

function verify_predictor(array $ds, string $id, array $fields): array
{
    $v = $ds['verify'] ?? null;
    if ($v === null) {
        return [];
    }
    if (!$v['predictions']['available']) {
        return [verify_issue($fields, '승자 예측을 시트 순위표와 대조할 수 없습니다')];
    }
    if (($v['predictions']['predictors'][$id] ?? false) !== true) {
        return [verify_issue($fields, "$id 예측 기록이 시트 순위표와 다릅니다")];
    }
    return [];
}

/** 미션 성공 지수·수익률 (예측 탭 지수표) */
function verify_mission(array $ds, string $id, array $fields): array
{
    $v = $ds['verify'] ?? null;
    if ($v === null) {
        return [];
    }
    if (!($v['mission']['available'] ?? false)) {
        return [verify_issue($fields, '미션 지수를 시트 순위표(지수·수익률)와 대조할 수 없습니다')];
    }
    if (($v['mission']['predictors'][$id] ?? false) !== true) {
        return [verify_issue($fields, "$id 미션 지수가 시트 순위표와 다릅니다")];
    }
    return [];
}

/** 맵 종족 상성·사용 기간 (MAP 통계 탭) */
function verify_map(array $ds, string $map, array $fields): array
{
    $v = $ds['verify'] ?? null;
    if ($v === null) {
        return [];
    }
    if (!($v['maps']['available'] ?? false)) {
        return [verify_issue($fields, '맵 상성을 시트 집계(MAP 통계 탭)와 대조할 수 없습니다')];
    }
    if (isset($v['maps']['bad'][$map])) {
        return [verify_issue($fields, "$map 맵 상성이 시트 집계와 다릅니다")];
    }
    return [];
}

/** 선수의 맵 세트 전적 (MAP 선수별 전적 탭). 기록이 없는 맵(0승 0패)도 탭을 대조할 수 있으면 확인된 것으로 본다 */
function verify_map_sets(array $ds, string $pid, string $map, array $fields): array
{
    $v = $ds['verify'] ?? null;
    if ($v === null) {
        return [];
    }
    if (!($v['mapsets']['available'] ?? false)) {
        return [verify_issue($fields, '선수 맵 전적을 시트 집계(MAP 선수별 전적 탭)와 대조할 수 없습니다')];
    }
    if (isset($v['mapsets']['bad']["$pid|$map"])) {
        return [verify_issue($fields, pname($ds['players'], $pid) . " $map 맵 전적이 시트 집계와 다릅니다")];
    }
    return [];
}

/**
 * 순위형 CG: 순위는 후보 전체로 정해지므로 후보 중 하나라도 확실하지 않으면 행 전체(순위·이름·기록)를 막는다.
 * 시트 집계에만 있는 선수(Results에 기록 없음)가 있어도 모집단이 확실하지 않은 것으로 본다.
 * @param string $kind sets | matches | predictions | mission
 */
function verify_population(array $ds, string $kind, array $ids, array $fields): array
{
    $v = $ds['verify'] ?? null;
    if ($v === null) {
        return [];
    }
    if (!($v[$kind]['available'] ?? false)) {
        return [verify_issue($fields, '순위를 시트 집계와 대조할 수 없습니다')];
    }
    $bad = [];
    foreach ($ids as $id) {
        $ok = match ($kind) {
            'sets' => ($v['sets']['players'][$id]['all'] ?? false) === true,
            'matches' => ($v['matches']['players'][$id] ?? false) === true,
            'predictions' => ($v['predictions']['predictors'][$id] ?? false) === true,
            'mission' => ($v['mission']['predictors'][$id] ?? false) === true,
        };
        if (!$ok) {
            $bad[] = (string)$id;
        }
    }
    $extra = $v[$kind]['extra'] ?? [];
    $names = static fn(array $l) => implode(', ', array_slice($l, 0, 3)) . (count($l) > 3 ? ' 외 ' . (count($l) - 3) . '명' : '');
    $issues = [];
    if ($bad) {
        $issues[] = verify_issue($fields, '순위 확인 불가: ' . $names($bad) . '의 기록이 시트 집계와 다릅니다');
    }
    if ($extra) {
        $issues[] = verify_issue($fields, '순위 확인 불가: 시트 집계에만 있는 ' . $names($extra) . ' (Results에 기록 없음)');
    }
    return $issues;
}

/** 종족별 순위: 주 종족을 정하지 못한 선수가 있으면 그 종족 순위 모집단이 확실하지 않다 */
function verify_race_known(array $ds, ?string $race, array $fields): array
{
    if (($ds['verify'] ?? null) === null || $race === null) {
        return [];
    }
    $unknown = array_map('strval', array_keys(array_filter($ds['players'], static fn($p) => ($p['race'] ?? null) === null)));
    return $unknown ? [verify_issue($fields, '주 종족을 정하지 못한 선수가 있어 종족별 순위를 확인할 수 없습니다: '
        . implode(', ', array_slice($unknown, 0, 3)))] : [];
}
