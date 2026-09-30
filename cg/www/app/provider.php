<?php
declare(strict_types=1);

/**
 * 데이터 소스. 수집(fetch) → 정규화(normalize) → 검증(validate)을 나눈다.
 * 현재 소스는 MOCK JSON 하나다. Google Sheets·외부 사이트는 실제 주소·구조가 확인된 뒤(PHASE 8·9) 추가한다.
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
 * @return array{source:string, mock:bool, players:array<string,array>, matches:list<array>}
 */
function provider_load(string $sourceId = 'mock', ?string $dir = null): array
{
    if ($sourceId !== 'mock') {
        throw new ProviderError("알 수 없는 데이터 소스: $sourceId");
    }
    $raw = mock_fetch($dir ?? (string)config('mock_dir', APP_DIR . '/data/mock'));
    $ds = dataset_normalize($raw, 'mock');
    $problems = dataset_validate($ds);
    if ($problems) {
        throw new ProviderError('데이터 검증 실패 (' . count($problems) . '건)', $problems);
    }
    return $ds;
}

function mock_fetch(string $dir): array
{
    $out = [];
    foreach (['players', 'matches'] as $name) {
        $path = "$dir/$name.json";
        $text = @file_get_contents($path);
        if ($text === false) {
            throw new ProviderError("MOCK 파일을 읽을 수 없습니다: $name.json");
        }
        $json = json_decode($text, true);
        if (!is_array($json) || !isset($json[$name]) || !is_array($json[$name])) {
            throw new ProviderError("MOCK 파일 형식이 올바르지 않습니다: $name.json");
        }
        $out[$name] = $json[$name];
        $out['mock'] = ($out['mock'] ?? true) && !empty($json['_mock']);
    }
    return $out;
}

function dataset_normalize(array $raw, string $source): array
{
    $str = static fn($v) => is_scalar($v) ? trim((string)$v) : '';
    $int = static fn($v) => is_int($v) ? $v : (is_string($v) && preg_match('/^-?\d+$/D', trim($v)) ? (int)$v : null);
    $players = [];
    $dupPlayers = [];
    foreach ($raw['players'] ?? [] as $p) {
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
    foreach ($raw['matches'] ?? [] as $m) {
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
    return [
        'source' => $source,
        'mock' => (bool)($raw['mock'] ?? false),
        'players' => $players,
        'matches' => $matches,
        'duplicate_players' => $dupPlayers,
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
    return $problems;
}
