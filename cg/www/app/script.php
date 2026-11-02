<?php
declare(strict_types=1);

/*
 * 대본 (Google 문서) 읽기 — v0.11.0. 온라인 CG 4종(#6·#20·맵별 온라인 전적·맵별 누적 종족 전적)의 값.
 * - 데이터 새로고침 때 같은 서비스 계정으로 Docs API v1 문서를 읽는다 (documents.readonly, 토큰은 따로 받고 저장하지 않음).
 * - 실패해도 끝장전 새로고침은 그대로 성공하고 온라인 CG만 값이 비어 막힌다 (ASL과 같은 방식).
 * - 문서 전체 글은 저장하지 않는다. 경기 구역마다 읽은 숫자만 남긴다.
 * - 확실히 읽지 못한 값(승률이 승·패와 맞지 않음, 선수 이름이 다름)은 비우고 안내한다 (추측 금지).
 * 경기 구역: 제목 줄 'MMDD_선수A_선수B' 또는 'CG용'부터 다음 제목 줄까지.
 */

const DOCS_SCOPE = 'https://www.googleapis.com/auth/documents.readonly';
const DOCS_API_BASE = 'https://docs.googleapis.com/v1/documents/';
const SCRIPT_RACE_RE = '[ZTPztp]|저그(?:전)?|테란(?:전)?|(?:프로)?토스(?:전)?';

/** 문서 주소 또는 ID → {id, tab}. 주소의 ?tab=t.xxx 가 있으면 그 탭만. 형식이 아니면 null */
function script_ref_from(string $s): ?array
{
    $s = trim($s);
    $tab = preg_match('/[?&#]tab=(t\.[A-Za-z0-9_-]{1,40})/', $s, $t) ? $t[1] : '';
    if (preg_match('#/document/d/([a-zA-Z0-9_-]{20,100})#', $s, $m)) {
        return ['id' => $m[1], 'tab' => $tab];
    }
    return preg_match('/^[a-zA-Z0-9_-]{20,100}$/D', $s) ? ['id' => $s, 'tab' => ''] : null;
}

/** 대본 문서를 읽어 경기 구역 목록으로 (선수 이름은 문서 표기 그대로) */
function script_fetch(array $key, string $id, string $tab): array
{
    $token = google_access_token($key, DOCS_SCOPE);
    $base = (string)(((array)config('google', []))['docs_base'] ?? DOCS_API_BASE);
    $res = http_request('GET', $base . rawurlencode($id) . '?includeTabsContent=true', ['Authorization: Bearer ' . $token]);
    if ($res['status'] !== 200) {
        // 403의 원인 구분: Google 응답의 reason (API 꺼짐 / 공유 안 됨)
        $err = json_decode($res['body'], true);
        $reason = is_array($err) ? (string)($err['error']['details'][0]['reason'] ?? '') : '';
        throw new ProviderError(match (true) {
            $res['status'] === 403 && in_array($reason, ['SERVICE_DISABLED', 'ACCESS_TOKEN_SCOPE_INSUFFICIENT'], true)
                => '서비스 계정의 Google Cloud 프로젝트에서 Google Docs API가 꺼져 있습니다 (' . $reason . '). 서비스 계정 이메일 @ 뒤의 프로젝트에서 사용 설정하세요.',
            $res['status'] === 403 => '대본 문서에 접근할 권한이 없습니다. 문서를 서비스 계정 이메일과 "뷰어"로 공유했는지, Google Docs API를 사용 설정했는지 확인하세요.'
                . ($reason !== '' ? ' (' . $reason . ')' : ''),
            $res['status'] === 404 => '대본 문서를 찾을 수 없습니다. 문서 주소를 확인하세요.',
            $res['status'] === 429 => 'Google 요청 한도를 넘었습니다. 잠시 뒤 다시 시도하세요.',
            default => "대본 문서 응답 오류 (HTTP {$res['status']})",
        });
    }
    $doc = json_decode($res['body'], true);
    if (!is_array($doc)) {
        throw new ProviderError('대본 문서 응답을 읽을 수 없습니다.');
    }
    // 경기마다 탭을 추가하는 대본: 주소에 탭이 붙어 있어도 모든 탭을 읽고, 페이지의 두 선수로 경기를 고른다 (탭마다 주소를 바꾸지 않음)
    return script_parse_doc($doc);
}

/** 문서 JSON → {sections, notes}. 탭이 있는 문서는 모든 탭(지정한 탭만)을 순서대로 */
function script_parse_doc(array $doc, string $tab = ''): array
{
    $bodies = [];
    $walk = static function (array $tabs) use (&$walk, &$bodies, $tab): void {
        foreach ($tabs as $t) {
            if ($tab === '' || ($t['tabProperties']['tabId'] ?? '') === $tab) {
                $content = (array)($t['documentTab']['body']['content'] ?? []);
                // 경기마다 탭을 나눈 문서: 탭 이름(예: 1102_유영진_도재욱)을 본문 첫 제목으로 본다 (본문에 같은 제목이 이미 있으면 그대로)
                $title = trim((string)($t['tabProperties']['title'] ?? ''));
                $first = script_blocks($content)[0] ?? null;
                if ($title !== '' && !($first && $first[0] === 'p' && trim($first[1]['t']) === $title)) {
                    array_unshift($content, ['paragraph' => ['elements' => [['textRun' => ['content' => $title . "\n"]]]]]);
                }
                $bodies[] = $content;
            }
            $walk((array)($t['childTabs'] ?? []));
        }
    };
    if (isset($doc['tabs'])) {
        $walk((array)$doc['tabs']);
        if (!$bodies) {
            throw new ProviderError('대본 문서에 주소의 탭이 없습니다. 문서 주소를 다시 붙여 넣으세요.');
        }
    } else {
        $bodies[] = (array)($doc['body']['content'] ?? []);
    }
    $blocks = [];
    foreach ($bodies as $content) {
        array_push($blocks, ...script_blocks($content));
    }
    return script_parse_blocks($blocks);
}

/** 문단 → 줄 목록 [{t: 글자, s: 바이트마다 취소선 '1'/'0'}] (굵게 등으로 나뉜 조각은 이어 붙임, 줄바꿈 문자로 나눔) */
function script_lines(array $para): array
{
    $t = $s = '';
    foreach ((array)($para['elements'] ?? []) as $el) {
        $c = (string)($el['textRun']['content'] ?? '');
        $c = str_replace(["\u{a0}", "\r"], [' ', ''], $c);
        $t .= $c;
        $s .= str_repeat(!empty($el['textRun']['textStyle']['strikethrough']) ? '1' : '0', strlen($c));
    }
    $out = [];
    $pos = 0;
    foreach (preg_split("/[\n\x{0b}]/u", $t) as $part) {
        $out[] = ['t' => $part, 's' => substr($s, $pos, strlen($part))];
        $pos += strlen($part) + 1;
    }
    return array_values(array_filter($out, static fn($l) => trim($l['t']) !== ''));
}

/** 본문 → 블록 목록: ['p', 줄] 또는 ['t', 행[칸[줄]]] */
function script_blocks(array $content): array
{
    $out = [];
    foreach ($content as $el) {
        if (isset($el['paragraph'])) {
            foreach (script_lines($el['paragraph']) as $l) {
                $out[] = ['p', $l];
            }
        } elseif (isset($el['table'])) {
            $rows = [];
            foreach ((array)($el['table']['tableRows'] ?? []) as $tr) {
                $cells = [];
                foreach ((array)($tr['tableCells'] ?? []) as $tc) {
                    $lines = [];
                    foreach ((array)($tc['content'] ?? []) as $c) {
                        if (isset($c['paragraph'])) {
                            array_push($lines, ...script_lines($c['paragraph']));
                        }
                    }
                    $cells[] = $lines;
                }
                $rows[] = $cells;
            }
            $out[] = ['t', $rows];
        }
    }
    return $out;
}

/** 공백 정리 + '(온라인)' 꼬리표 제거 */
function script_norm(string $s): string
{
    $s = preg_replace('/\s*\(\s*온라인\s*\)/u', '', $s) ?? '';
    return trim(preg_replace('/\s+/u', ' ', $s) ?? '');
}

function script_race(string $r): string
{
    $r = mb_strtoupper($r);
    return match (true) {
        str_starts_with($r, '저그') => 'Z',
        str_starts_with($r, '테란') => 'T',
        str_contains($r, '토스') => 'P',
        default => $r,
    };
}

/** 표시된 승률(%)이 승/(승+패)를 소수 한 자리로 반올림한 값과 맞는지 (0.05 이내) */
function script_rate_ok(int $w, int $l, string $pct): bool
{
    return $w + $l > 0 && abs($w * 100 / ($w + $l) - (float)$pct) <= 0.05 + 1e-9;
}

function script_int(string $s): int
{
    return (int)str_replace(',', '', $s);
}

/** "김민철 vs T 23승 18패 (56.1%)" → [이름, {vs, w, l}] 또는 [이름, null](승률 불일치). 형식이 아니면 null */
function script_race_rec(string $s): ?array
{
    $re = '/^(.+?) ?vs ?(' . SCRIPT_RACE_RE . ') ?([\d,]+) ?승 ?([\d,]+) ?패 ?\( ?([\d.]+) ?% ?\)$/iu';
    if (!preg_match($re, script_norm($s), $m)) {
        return null;
    }
    [$w, $l] = [script_int($m[3]), script_int($m[4])];
    return [trim($m[1]), script_rate_ok($w, $l, $m[5]) ? ['vs' => script_race($m[2]), 'w' => $w, 'l' => $l] : null];
}

/** "김민철 3 : 5 유영진" → [왼쪽 이름, x, y, 오른쪽 이름] 또는 null */
function script_score(string $s): ?array
{
    if (!preg_match('/^(\S+) ([\d,]+) ?: ?([\d,]+) (\S+)$/u', script_norm($s), $m)) {
        return null;
    }
    return [$m[1], script_int($m[2]), script_int($m[3]), $m[4]];
}

/** 점수를 A 기준 [A, B]로. 이름이 A·B가 아니면 null */
function script_orient(array $sc, string $a, string $b): ?array
{
    return match (true) {
        $sc[0] === $a && $sc[3] === $b => [$sc[1], $sc[2]],
        $sc[0] === $b && $sc[3] === $a => [$sc[2], $sc[1]],
        default => null,
    };
}

/** 줄의 일부(바이트 위치)가 모두 취소선인지 (공백 제외) */
function script_struck(array $line, int $from, int $len): bool
{
    $any = false;
    for ($i = $from; $i < $from + $len; $i++) {
        if (trim($line['t'][$i]) === '') {
            continue;
        }
        if (($line['s'][$i] ?? '0') !== '1') {
            return false;
        }
        $any = true;
    }
    return $any;
}

/** 블록 목록 → 경기 구역 목록 */
function script_parse_blocks(array $blocks): array
{
    $sections = [];
    $notes = [];
    $cur = null;
    foreach ($blocks as $b) {
        $t = $b[0] === 'p' ? script_norm($b[1]['t']) : '';
        if ($b[0] === 'p' && (preg_match('/^(\d{4})_([^_\s]+)_([^_\s]+)$/u', $t, $m) || $t === 'CG용')) {
            if ($cur !== null) {
                $sections[] = $cur;
            }
            $cur = ['key' => $t, 'cg' => $t === 'CG용', 'a' => $m[2] ?? '', 'b' => $m[3] ?? '', 'blocks' => []];
            continue;
        }
        if ($cur !== null) {
            $cur['blocks'][] = $b;
        }
    }
    if ($cur !== null) {
        $sections[] = $cur;
    }
    $out = [];
    foreach ($sections as $sec) {
        $p = script_parse_section($sec, $notes);
        if ($p !== null) {
            $out[] = $p;
        }
    }
    return ['sections' => $out, 'notes' => $notes];
}

/** 경기 구역 하나: 밴·맵 목록·상대 전적·맵별 전적·맵별 누적 종족 전적 */
function script_parse_section(array $sec, array &$notes): ?array
{
    $key = $sec['key'];
    $note = static function (string $what) use (&$notes, $key): void {
        $notes[] = "대본 $key $what";
    };
    $out = ['key' => $key, 'cg' => $sec['cg'], 'a' => $sec['a'], 'b' => $sec['b'], 'bans' => [], 'maps' => [],
        'total' => null, 'recent' => null, 'map' => [], 'cum' => []];
    $blocks = $sec['blocks'];
    // 1) 세트 표 머리글: "김민철(BAN:오디세이) | 승 | 세트 / 맵 | 승 | 유영진(BAN:…)"
    foreach ($blocks as $b) {
        $head = $b[0] === 't' ? ($b[1][0] ?? []) : [];
        if (count($head) === 5) {
            $ban = static fn(array $cell) => preg_match('/^(.+?) ?\( ?BAN ?: ?(.*?) ?\)$/iu', script_norm($cell[0]['t'] ?? ''), $m) ? $m : null;
            [$x, $y] = [$ban($head[0]), $ban($head[4])];
            if ($x && $y) {
                if ($sec['cg']) {
                    [$out['a'], $out['b']] = [$x[1], $y[1]];
                }
                $out['bans'] = [$x[2], $y[2]];
                break;
            }
        }
    }
    if ($out['a'] === '' || $out['b'] === '') {
        $note(': 선수 이름을 읽지 못해 건너뜀');
        return null;
    }
    [$A, $B] = [$out['a'], $out['b']];
    // 2) 맵 목록 문단 (구분 / 또는 ,, 앞에 '맵:' 가능). 취소선 = 밴
    foreach ($blocks as $b) {
        if ($b[0] !== 'p') {
            continue;
        }
        $line = $b[1];
        $start = preg_match('/^\s*맵\s*:\s*/u', $line['t'], $m) ? strlen($m[0]) : 0;
        $parts = preg_split('#\s*[/,]\s*#u', substr($line['t'], $start), -1, PREG_SPLIT_OFFSET_CAPTURE);
        if (count($parts) < 3) {
            continue;
        }
        $maps = [];
        foreach ($parts as [$txt, $off]) {
            $name = script_norm($txt);
            if ($name === '' || mb_strlen($name) > 20 || preg_match('/\d+ ?:|승|패/u', $name)) {
                $maps = [];
                break;
            }
            $lead = strlen($txt) - strlen(ltrim($txt));
            $maps[] = ['name' => $name, 'banned' => script_struck($line, $start + $off + $lead, strlen(trim($txt)))];
        }
        if ($maps) {
            $out['maps'] = $maps;
            break;
        }
    }
    $mapNames = array_column($out['maps'], 'name');
    foreach ($blocks as $b) {
        if ($b[0] !== 't') {
            continue;
        }
        $rows = $b[1];
        // 3) 상대 전적 (한 칸 표)
        $lines = count($rows) === 1 && count($rows[0]) === 1 ? array_map(static fn($l) => script_norm($l['t']), $rows[0][0]) : [];
        if ($lines && $lines[0] === '상대 전적') {
            $part = 'total';
            $recent = ['label' => null, 'h2h' => null, 'race' => ['a' => null, 'b' => null]];
            foreach (array_slice($lines, 1) as $l) {
                if (str_starts_with($l, '최근 한 달 상대 전적')) {
                    $part = 'recent';
                    if (preg_match('/(\d{1,2})월 ?(\d{1,2})일 ?~ ?(\d{1,2})월 ?(\d{1,2})일/u', $l, $d)) {
                        $recent['label'] = sprintf('%02d.%02d~%02d.%02d', $d[1], $d[2], $d[3], $d[4]);
                    }
                } elseif (str_starts_with($l, '최근 한 달 상대 종족')) {
                    $part = 'race';
                } elseif ($part === 'race') {
                    foreach (explode('|', $l) as $piece) {
                        $r = script_race_rec($piece);
                        $side = $r === null ? null : ($r[0] === $A ? 'a' : ($r[0] === $B ? 'b' : null));
                        if ($side === null) {
                            $note('최근 한 달 종족 전적: 읽지 못한 값 (' . mb_substr(trim($piece), 0, 30) . ')');
                        } elseif ($r[1] === null) {
                            $note("최근 한 달 종족 전적 {$r[0]}: 승률 불일치");
                        } else {
                            $recent['race'][$side] = $r[1];
                        }
                    }
                } elseif (($sc = script_score($l)) !== null) {
                    $v = script_orient($sc, $A, $B);
                    if ($v === null) {
                        $note(($part === 'total' ? '상대 전적' : '최근 한 달 상대 전적') . ': 선수 이름이 제목과 다름');
                    } elseif ($part === 'total') {
                        $out['total'] = $v;
                    } else {
                        $recent['h2h'] = $v;
                    }
                }
            }
            $out['recent'] = $recent;
            continue;
        }
        foreach ($rows as $cells) {
            // 6) 누적 종족 전적: "오디세이 | Z 329 : 376 T (46.7%)"
            if (count($cells) === 2 && count($cells[1]) === 1
                && preg_match('/^([ZTP]) ?([\d,]+) ?: ?([\d,]+) ?([ZTP]) ?\( ?([\d.]+) ?% ?\)$/u', script_norm($cells[1][0]['t']), $m)) {
                $map = script_norm($cells[0][0]['t'] ?? '');
                if ($map === '') {
                    continue;
                }
                [$lw, $rw] = [script_int($m[2]), script_int($m[3])];
                if (!script_rate_ok($lw, $rw, $m[5])) {
                    $note("누적 종족 전적 맵 $map: 승률 불일치");
                    $out['cum'][$map] = null;
                } else {
                    $out['cum'][$map] = ['lr' => $m[1], 'rr' => $m[4], 'lw' => $lw, 'rw' => $rw];
                }
                continue;
            }
            // 5) 맵별 전적 칸: 맵 이름(같은 줄에 오프라인 종족 전적 가능) · 오프라인 종족 전적 · 맞대결 · A·B 종족 전적
            foreach ($cells as $cell) {
                if (count($cell) < 2) {
                    continue;
                }
                $first = script_norm($cell[0]['t']);
                $name = trim((string)preg_replace('/ ?[ZTP] ?\d+ ?: ?\d+ ?[ZTP].*$/u', '', $first));
                if (!in_array($name, $mapNames, true)) {
                    continue;
                }
                $rec = ['h2h' => null, 'a' => null, 'b' => null];
                foreach (array_slice($cell, 1) as $cl) {
                    $l = script_norm($cl['t']);
                    if (($sc = script_score($l)) !== null && !preg_match('/^[ZTP]$/', $sc[0])) {
                        $v = script_orient($sc, $A, $B);
                        $v === null ? $note("맵 $name: 맞대결 선수 이름이 제목과 다름") : $rec['h2h'] = $v;
                    } elseif (($r = script_race_rec($l)) !== null) {
                        $side = $r[0] === $A ? 'a' : ($r[0] === $B ? 'b' : null);
                        if ($side === null) {
                            $note("맵 $name: 종족 전적 선수 이름이 제목과 다름");
                        } elseif ($r[1] === null) {
                            $note("맵 $name: 승률 불일치");
                        } else {
                            $rec[$side] = $r[1];
                        }
                    }
                }
                $out['map'][$name] = $rec;
            }
        }
    }
    return $out;
}

/**
 * 읽은 구역의 선수 이름 → 데이터 선수 id. 데이터에 없는 이름의 구역은 빼고 안내한다.
 * @return array{available:bool, error:?string, sections:list<array>, notes:list<string>}
 */
function script_dataset(array $raw, array $players): array
{
    $byName = [];
    foreach ($players as $id => $p) {
        $byName[(string)$p['name']] = (string)$id;
    }
    $notes = $raw['notes'] ?? [];
    $sections = [];
    foreach ($raw['sections'] ?? [] as $s) {
        $missing = array_values(array_filter([$s['a'], $s['b']], static fn($n) => !isset($byName[$n])));
        if ($missing) {
            $notes[] = "대본 {$s['key']}: 데이터에 없는 선수 이름 (" . implode(', ', $missing) . ') — 건너뜀';
            continue;
        }
        $sections[] = ['a' => $byName[$s['a']], 'b' => $byName[$s['b']]] + $s;
    }
    return ['available' => true, 'error' => null, 'sections' => $sections, 'notes' => $notes];
}

/** 대본 구역 A·B 뒤집기 (제목의 선수 순서가 페이지와 반대일 때) */
function script_swap(array $s): array
{
    $flip = static fn(?array $v) => $v === null ? null : [$v[1], $v[0]];
    $ab = static fn(?array $x) => $x === null ? null : array_replace($x, ['a' => $x['b'], 'b' => $x['a']]);
    $s = array_replace($s, ['a' => $s['b'], 'b' => $s['a'], 'bans' => array_reverse($s['bans']), 'total' => $flip($s['total'])]);
    if ($s['recent'] !== null) {
        $s['recent']['h2h'] = $flip($s['recent']['h2h']);
        $s['recent']['race'] = $ab($s['recent']['race']);
    }
    foreach ($s['map'] as $k => $m) {
        $s['map'][$k] = $ab(['h2h' => $flip($m['h2h'])] + $m);
    }
    return $s;
}

/** 두 선수의 대본 구역: 문서 뒤쪽(최신) 것, A 기준으로 맞춤. 없으면 null */
function script_pick(array $sections, string $a, string $b): ?array
{
    foreach (array_reverse($sections) as $s) {
        if ($s['a'] === $a && $s['b'] === $b) {
            return $s;
        }
        if ($s['a'] === $b && $s['b'] === $a) {
            return script_swap($s);
        }
    }
    return null;
}

/** 템플릿 auto용: 데이터의 대본 구역 (대본을 쓸 수 없으면 null) */
function script_for(array $ds, string $a, string $b): ?array
{
    $sc = $ds['script'] ?? null;
    return ($sc['available'] ?? false) ? script_pick($sc['sections'], $a, $b) : null;
}

/** 템플릿 요약용 한 줄: '대본 1026_김민철_유영진' 또는 '대본 없음' */
function script_summary(array $ctx, string $a, string $b): string
{
    $s = script_pick($ctx['script'] ?? [], $a, $b);
    return $s === null ? '대본 없음' : '대본 ' . $s['key'];
}

/** 대본 맵 파라미터 검사 (맵별 CG) */
function script_check_map(array $p, array $ctx, bool $noBan): void
{
    check_two_players($p);
    $s = script_pick($ctx['script'] ?? [], $p['a']['player'], $p['b']['player']);
    if ($s === null) {
        throw new ActionError('BAD_PARAMS', '대본에 이 두 선수 경기가 없습니다. 데이터 설정의 대본 문서 주소와 데이터 새로고침을 확인하세요.', 422);
    }
    foreach ($s['maps'] as $m) {
        if ($m['name'] === $p['map'] && !($noBan && $m['banned'])) {
            return;
        }
    }
    throw new ActionError('BAD_PARAMS', '맵: 대본 ' . $s['key'] . '의 맵에서 고르세요' . ($noBan ? ' (밴 맵 제외)' : '') . '.', 422);
}
