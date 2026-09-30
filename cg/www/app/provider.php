<?php
declare(strict_types=1);

/**
 * 데이터 소스. 수집(fetch) → 정규화(normalize) → 검증(validate)을 나눈다.
 *   mock  : MOCK JSON (검증용 가짜 수치)
 *   sheet : Google 시트 (서비스 계정으로 Sheets API 읽기, 또는 xlsx 가져오기) — sheet_data.php, sheets.php
 * 두 소스 모두 같은 모양으로 만든다: players, games(세트), matches(끝장전), predictions(예측 결과), predictors …
 */

final class ProviderError extends RuntimeException
{
    /** @param list<string> $problems */
    public function __construct(string $message, public readonly array $problems = [])
    {
        parent::__construct($message);
    }
}

const RACES = ['P', 'T', 'Z'];

/**
 * @return array{source:string, mock:bool, players:array<string,array>, matches:list<array>, online:list<array>,
 *   predictors:array<string,array>, picks:list<array>, double_chance:array<string,array>}
 */
function provider_load(string $sourceId = 'mock', ?string $dir = null): array
{
    if ($sourceId === 'sheet') {
        return sheet_dataset(sheets_fetch_tables(), 'api');
    }
    if ($sourceId !== 'mock') {
        throw new ProviderError("알 수 없는 데이터 소스: $sourceId");
    }
    $raw = mock_fetch($dir ?? (string)config('mock_dir', APP_DIR . '/data/mock'));
    $ds = dataset_normalize($raw, 'mock');
    $problems = dataset_validate($ds);
    if ($problems) {
        throw new ProviderError('데이터 검증 실패 (' . count($problems) . '건)', $problems);
    }
    return mock_enrich($ds);
}

/**
 * MOCK 데이터를 시트 데이터와 같은 모양으로: 끝장전 스코어로 세트 목록을 만들고, 예측(누가 이길지 고른 기록)을
 * 성공/실패 결과로 바꾼다. MOCK은 검증 대상이 아니다(verify = null).
 */
function mock_enrich(array $ds): array
{
    $games = [];
    foreach ($ds['matches'] as $m) {
        foreach ([['playerA', 'raceA', 'playerB', 'raceB', 'scoreA'], ['playerB', 'raceB', 'playerA', 'raceA', 'scoreB']] as [$w, $wr, $l, $lr, $sc]) {
            for ($i = 0; $i < $m[$sc]; $i++) {
                $games[] = ['row' => null, 'date' => $m['date'], 'winner' => $m[$w], 'wrace' => $m[$wr], 'loser' => $m[$l],
                    'lrace' => $m[$lr], 'map' => ''];
            }
        }
    }
    $winner = [];
    $date = [];
    foreach ($ds['matches'] as $m) {
        $winner[$m['id']] = $m['scoreA'] > $m['scoreB'] ? $m['playerA'] : $m['playerB'];
        $date[$m['id']] = $m['date'];
    }
    $predictions = [];
    foreach ($ds['picks'] as $p) {
        $predictions[] = ['date' => $date[$p['match']], 'predictor' => $p['predictor'], 'correct' => $p['pick'] === $winner[$p['match']],
            'row' => null];
    }
    $ds['matches'] = array_map(static fn($m) => $m + ['anomaly' => null, 'sets' => $m['scoreA'] + $m['scoreB']], $ds['matches']);
    return $ds + ['games' => $games, 'matches_all' => $ds['matches'], 'predictions' => $predictions,
        'online_available' => true, 'verify' => null, 'check' => null];
}

/**
 * 필수: players.json, matches.json
 * 선택: online.json(온라인 게임), predictions.json(승자 예측), double_chance.json(더블 찬스 집계) — 없으면 빈 목록
 */
function mock_fetch(string $dir): array
{
    $out = ['mock' => true];
    $files = [
        'players' => ['players'], 'matches' => ['matches'], 'online' => ['games'],
        'predictions' => ['predictors', 'picks'], 'double_chance' => ['records'],
    ];
    foreach ($files as $name => $keys) {
        $path = "$dir/$name.json";
        $required = in_array($name, ['players', 'matches'], true);
        if (!$required && !is_file($path)) {
            foreach ($keys as $k) {
                $out[$name . '.' . $k] = [];
            }
            continue;
        }
        $text = @file_get_contents($path);
        if ($text === false) {
            throw new ProviderError("MOCK 파일을 읽을 수 없습니다: $name.json");
        }
        $json = json_decode($text, true);
        foreach ($keys as $k) {
            if (!is_array($json) || !isset($json[$k]) || !is_array($json[$k])) {
                throw new ProviderError("MOCK 파일 형식이 올바르지 않습니다: $name.json");
            }
        }
        foreach ($keys as $k) {
            $out[$name . '.' . $k] = $json[$k];
        }
        $out['mock'] = $out['mock'] && !empty($json['_mock']);
    }
    return $out;
}

function dataset_normalize(array $raw, string $source): array
{
    $str = static fn($v) => is_scalar($v) ? trim((string)$v) : '';
    $int = static fn($v) => is_int($v) ? $v : (is_string($v) && preg_match('/^-?\d+$/D', trim($v)) ? (int)$v : null);
    $players = [];
    $dupPlayers = [];
    foreach ($raw['players.players'] ?? $raw['players'] ?? [] as $p) {
        $id = strtolower($str($p['id'] ?? ''));
        if (isset($players[$id])) {
            $dupPlayers[] = $id;
        }
        $players[$id] = [
            'id' => $id,
            'name' => $str($p['name'] ?? ''),
            'nickname' => ($p['nickname'] ?? null) === null ? null : $str($p['nickname']),
            'race' => strtoupper($str($p['race'] ?? '')),
            'aliases' => array_values(array_filter(array_map($str, (array)($p['aliases'] ?? [])))),
            'active' => (bool)($p['active'] ?? true),
        ];
    }
    $matches = [];
    foreach ($raw['matches.matches'] ?? $raw['matches'] ?? [] as $m) {
        $matches[] = [
            'id' => $str($m['id'] ?? ''),
            'date' => $str($m['date'] ?? ''),
            'competition' => $str($m['competition'] ?? ''),
            'playerA' => strtolower($str($m['playerA'] ?? '')),
            'playerB' => strtolower($str($m['playerB'] ?? '')),
            'raceA' => strtoupper($str($m['raceA'] ?? '')),
            'raceB' => strtoupper($str($m['raceB'] ?? '')),
            'scoreA' => $int($m['scoreA'] ?? null),
            'scoreB' => $int($m['scoreB'] ?? null),
            'bestOf' => $int($m['bestOf'] ?? null),
            'winner' => isset($m['winner']) ? strtolower($str($m['winner'])) : null,
            'source' => $source,
        ];
    }
    $online = [];
    foreach ($raw['online.games'] ?? [] as $g) {
        $online[] = [
            'id' => $str($g['id'] ?? ''), 'date' => $str($g['date'] ?? ''),
            'playerA' => strtolower($str($g['playerA'] ?? '')), 'playerB' => strtolower($str($g['playerB'] ?? '')),
            'raceA' => strtoupper($str($g['raceA'] ?? '')), 'raceB' => strtoupper($str($g['raceB'] ?? '')),
            'winner' => strtolower($str($g['winner'] ?? '')),
        ];
    }
    $predictors = [];
    foreach ($raw['predictions.predictors'] ?? [] as $p) {
        $id = strtolower($str($p['id'] ?? ''));
        $predictors[$id] = ['id' => $id, 'name' => $str($p['name'] ?? ''), 'dup' => isset($predictors[$id])];
    }
    $picks = [];
    foreach ($raw['predictions.picks'] ?? [] as $p) {
        $picks[] = ['match' => $str($p['match'] ?? ''), 'predictor' => strtolower($str($p['predictor'] ?? '')),
            'pick' => strtolower($str($p['pick'] ?? ''))];
    }
    $double = [];
    $dupDouble = [];
    foreach ($raw['double_chance.records'] ?? [] as $r) {
        $pid = strtolower($str($r['player'] ?? ''));
        if (isset($double[$pid])) {
            $dupDouble[] = $pid;
        }
        $double[$pid] = ['wins' => $int($r['wins'] ?? null), 'losses' => $int($r['losses'] ?? null)];
    }
    return [
        'source' => $source,
        'mock' => (bool)($raw['mock'] ?? false),
        'players' => $players,
        'matches' => $matches,
        'online' => $online,
        'predictors' => $predictors,
        'picks' => $picks,
        'double_chance' => $double,
        'duplicate_players' => $dupPlayers,
        'duplicate_double' => $dupDouble,
    ];
}

/** @return list<string> 문제 목록 (비어 있으면 정상) */
function dataset_validate(array $ds): array
{
    $problems = [];
    foreach ($ds['duplicate_players'] ?? [] as $id) {
        $problems[] = "중복 선수 id: $id";
    }
    foreach ($ds['players'] as $id => $p) {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/D', (string)$id)) {
            $problems[] = "선수 id 형식 오류: $id";
        }
        if ($p['name'] === '' || mb_strlen($p['name']) > 20) {
            $problems[] = "선수 이름 오류: $id";
        }
        if (!in_array($p['race'], RACES, true)) {
            $problems[] = "선수 종족 오류: $id ({$p['race']})";
        }
    }
    $seen = [];
    foreach ($ds['matches'] as $i => $m) {
        $label = $m['id'] !== '' ? $m['id'] : '#' . ($i + 1);
        if ($m['id'] === '') {
            $problems[] = "경기 id 없음: $label";
        } elseif (isset($seen[$m['id']])) {
            $problems[] = "중복 경기 id: $label";
        }
        $seen[$m['id']] = true;
        $d = DateTime::createFromFormat('!Y-m-d', $m['date']);
        if (!$d || $d->format('Y-m-d') !== $m['date']) {
            $problems[] = "날짜 오류: $label ({$m['date']})";
        }
        foreach (['playerA', 'playerB'] as $side) {
            if (!isset($ds['players'][$m[$side]])) {
                $problems[] = "알 수 없는 선수: $label ({$m[$side]})";
            }
        }
        if ($m['playerA'] === $m['playerB']) {
            $problems[] = "같은 선수끼리 경기: $label";
        }
        foreach (['raceA', 'raceB'] as $side) {
            if (!in_array($m[$side], RACES, true)) {
                $problems[] = "종족 오류: $label ($side={$m[$side]})";
            }
        }
        $bo = $m['bestOf'];
        if ($bo === null || $bo < 1 || $bo % 2 === 0 || $bo > 99) {
            $problems[] = "bestOf 오류: $label";
            continue;
        }
        $need = intdiv($bo, 2) + 1;
        [$a, $b] = [$m['scoreA'], $m['scoreB']];
        if ($a === null || $b === null || $a < 0 || $b < 0 || $a > $need || $b > $need
            || !(($a === $need) xor ($b === $need))) {
            $problems[] = "스코어 오류: $label ({$a}:{$b}, {$bo}전)";
            continue;
        }
        if ($m['winner'] !== null && $m['winner'] !== ($a > $b ? $m['playerA'] : $m['playerB'])) {
            $problems[] = "승자와 스코어 불일치: $label";
        }
    }
    return array_merge($problems, dataset_validate_extra($ds, $seen));
}

/** 온라인 게임·승자 예측·더블 찬스 검증 */
function dataset_validate_extra(array $ds, array $matchIds): array
{
    $problems = [];
    $valid = static fn(string $d) => ($x = DateTime::createFromFormat('!Y-m-d', $d)) && $x->format('Y-m-d') === $d;
    $seen = [];
    foreach ($ds['online'] ?? [] as $i => $g) {
        $label = '온라인 ' . ($g['id'] !== '' ? $g['id'] : '#' . ($i + 1));
        if ($g['id'] === '' || isset($seen[$g['id']])) {
            $problems[] = "$label: id 없음 또는 중복";
        }
        $seen[$g['id']] = true;
        if (!$valid($g['date'])) {
            $problems[] = "$label: 날짜 오류";
        }
        if (!isset($ds['players'][$g['playerA']], $ds['players'][$g['playerB']]) || $g['playerA'] === $g['playerB']) {
            $problems[] = "$label: 선수 오류";
        }
        if (!in_array($g['raceA'], RACES, true) || !in_array($g['raceB'], RACES, true)) {
            $problems[] = "$label: 종족 오류";
        }
        if ($g['winner'] !== $g['playerA'] && $g['winner'] !== $g['playerB']) {
            $problems[] = "$label: 승자 오류";
        }
    }
    $matchPlayers = [];
    foreach ($ds['matches'] as $m) {
        $matchPlayers[$m['id']] = [$m['playerA'], $m['playerB']];
    }
    foreach ($ds['predictors'] ?? [] as $id => $p) {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/D', (string)$id) || $p['name'] === '' || $p['dup']) {
            $problems[] = "예측자 오류: $id";
        }
    }
    $pickSeen = [];
    foreach ($ds['picks'] ?? [] as $i => $p) {
        $label = '예측 #' . ($i + 1);
        if (!isset($matchIds[$p['match']])) {
            $problems[] = "$label: 없는 경기 {$p['match']}";
            continue;
        }
        if (!isset($ds['predictors'][$p['predictor']])) {
            $problems[] = "$label: 알 수 없는 예측자 {$p['predictor']}";
        }
        if (!in_array($p['pick'], $matchPlayers[$p['match']], true)) {
            $problems[] = "$label: 그 경기의 선수가 아닌 예측";
        }
        $k = $p['match'] . '|' . $p['predictor'];
        if (isset($pickSeen[$k])) {
            $problems[] = "$label: 같은 경기 중복 예측";
        }
        $pickSeen[$k] = true;
    }
    foreach ($ds['duplicate_double'] ?? [] as $pid) {
        $problems[] = "더블 찬스 중복: $pid";
    }
    foreach ($ds['double_chance'] ?? [] as $pid => $r) {
        if (!isset($ds['players'][$pid]) || $r['wins'] === null || $r['losses'] === null || $r['wins'] < 0 || $r['losses'] < 0) {
            $problems[] = "더블 찬스 기록 오류: $pid";
        }
    }
    return $problems;
}
