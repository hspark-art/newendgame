<?php
declare(strict_types=1);

/**
 * 최소 xlsx 읽기 — Google 시트 "파일 → 다운로드 → Microsoft Excel(.xlsx)" 파일에서 필요한 탭의 값만 읽는다.
 * 수식은 계산하지 않고 파일에 저장된 값을 쓴다. 날짜 서식 셀은 "YYYY-MM-DD" 문자열로 바꾼다.
 * 압축 폭탄·외부 엔터티를 막기 위해 크기를 제한하고 네트워크 접근 없이 XML을 읽는다.
 */

const XLSX_MAX_BYTES = 10 * 1024 * 1024;      // 올린 파일 (요청 본문 16MB 안에 base64로 들어가는 크기)
const XLSX_MAX_UNPACKED = 80 * 1024 * 1024;   // 풀었을 때 합계

/**
 * @param array<string,string> $tabs 키 => 탭 이름 (SHEET_TABS_DEFAULT 모양)
 * @return array<string, ?list<array>> 키 => 행 목록 (탭이 없으면 null)
 */
function xlsx_tables(string $bytes, array $tabs): array
{
    if (!class_exists('ZipArchive')) {
        throw new ProviderError('이 PHP에 zip 확장이 없어 xlsx 파일을 읽을 수 없습니다. (php.ini의 extension=zip 확인)');
    }
    if (strlen($bytes) > XLSX_MAX_BYTES) {
        throw new ProviderError('파일이 너무 큽니다 (최대 10MB).');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'cgx');
    if ($tmp === false || file_put_contents($tmp, $bytes) === false) {
        throw new ProviderError('임시 파일을 만들 수 없습니다.');
    }
    try {
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::RDONLY) !== true) {
            throw new ProviderError('xlsx 파일이 아닙니다. Google 시트에서 "파일 → 다운로드 → Microsoft Excel(.xlsx)"로 받으세요.');
        }
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $total += (int)($zip->statIndex($i)['size'] ?? 0);
        }
        if ($total > XLSX_MAX_UNPACKED) {
            throw new ProviderError('xlsx 파일 내용이 너무 큽니다.');
        }
        $xml = static function (string $name) use ($zip): ?SimpleXMLElement {
            $s = $zip->getFromName($name);
            if ($s === false) {
                return null;
            }
            $x = @simplexml_load_string($s, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
            return $x === false ? null : $x;
        };
        $wb = $xml('xl/workbook.xml');
        $rels = $xml('xl/_rels/workbook.xml.rels');
        if ($wb === null || $rels === null) {
            throw new ProviderError('xlsx 파일 구조를 읽을 수 없습니다.');
        }
        $target = [];
        foreach ($rels->children() as $r) {
            $target[(string)$r['Id']] = (string)$r['Target'];
        }
        $sheetFile = [];
        foreach ($wb->sheets->children() as $sh) {
            $rid = (string)$sh->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $t = $target[$rid] ?? '';
            $sheetFile[(string)$sh['name']] = str_starts_with($t, '/') ? ltrim($t, '/') : 'xl/' . $t;
        }
        $strings = xlsx_shared_strings($xml('xl/sharedStrings.xml'));
        $dateStyles = xlsx_date_styles($xml('xl/styles.xml'));
        $out = [];
        foreach ($tabs as $k => $title) {
            $file = $sheetFile[$title] ?? null;
            $sx = $file === null ? null : $xml($file);
            $out[$k] = $sx === null ? null : xlsx_rows($sx, $strings, $dateStyles);
        }
        $zip->close();
        return $out;
    } finally {
        @unlink($tmp);
    }
}

function xlsx_shared_strings(?SimpleXMLElement $x): array
{
    $out = [];
    if ($x === null) {
        return $out;
    }
    foreach ($x->si as $si) {
        if (isset($si->t)) {
            $out[] = (string)$si->t;
        } else {
            $s = '';
            foreach ($si->r as $r) {
                $s .= (string)$r->t;
            }
            $out[] = $s;
        }
    }
    return $out;
}

/** 날짜 서식인 셀 스타일 번호 (기본 날짜 서식 14~22, 45~47 또는 y·m·d가 들어간 사용자 서식) */
function xlsx_date_styles(?SimpleXMLElement $x): array
{
    if ($x === null) {
        return [];
    }
    $custom = [];
    foreach ($x->numFmts->numFmt ?? [] as $f) {
        $code = strtolower(preg_replace('/"[^"]*"|\[[^\]]*\]/', '', (string)$f['formatCode']));
        $custom[(int)$f['numFmtId']] = (bool)preg_match('/[yd]/', $code);
    }
    $out = [];
    $i = 0;
    foreach ($x->cellXfs->xf ?? [] as $xf) {
        $id = (int)$xf['numFmtId'];
        if (($id >= 14 && $id <= 22) || ($id >= 45 && $id <= 47) || ($custom[$id] ?? false)) {
            $out[$i] = true;
        }
        $i++;
    }
    return $out;
}

/** 시트 XML → 행 목록 (빈 칸은 ''). 숫자는 int/float, 날짜 서식 숫자는 "YYYY-MM-DD" */
function xlsx_rows(SimpleXMLElement $sheet, array $strings, array $dateStyles): array
{
    $rows = [];
    foreach ($sheet->sheetData->row ?? [] as $row) {
        $r = (int)$row['r'] - 1;
        if ($r < 0 || $r > 200000) {
            continue;
        }
        $cells = [];
        foreach ($row->c as $c) {
            if (!preg_match('/^([A-Z]{1,3})\d+$/', (string)$c['r'], $m)) {
                continue;
            }
            $col = 0;
            foreach (str_split($m[1]) as $ch) {
                $col = $col * 26 + (ord($ch) - 64);
            }
            $t = (string)$c['t'];
            $v = (string)$c->v;
            $val = match ($t) {
                's' => $strings[(int)$v] ?? '',
                'inlineStr' => (string)$c->is->t,
                'str', 'e' => $v,
                'b' => $v === '1' ? 'TRUE' : 'FALSE',
                default => $v === '' ? '' : (is_numeric($v) ? $v + 0 : $v),
            };
            if (($t === '' || $t === 'n') && (is_int($val) || is_float($val))) {
                if (isset($dateStyles[(int)$c['s']])) {
                    $val = date_norm((int)floor((float)$val), true) ?? $val;
                } elseif (is_float($val) && floor($val) == $val && abs($val) < 1e15) {
                    $val = (int)$val;
                }
            }
            $cells[$col - 1] = $val;
        }
        if ($cells) {
            $max = max(array_keys($cells));
            $line = array_fill(0, $max + 1, '');
            foreach ($cells as $i => $v) {
                $line[$i] = $v;
            }
            $rows[$r] = $line;
        }
    }
    if (!$rows) {
        return [];
    }
    $out = array_fill(0, max(array_keys($rows)) + 1, []);
    foreach ($rows as $i => $line) {
        $out[$i] = $line;
    }
    return $out;
}
