<?php
declare(strict_types=1);

/**
 * 배포 zip·패치 zip 만들기 (tools/build_release.php, tools/build_patch.php 에서 사용).
 * - PC zip : EndgameCG_PC_v<버전>/  실행기(시작.bat 등) + www/ + VERSION.json + MANIFEST.sha256
 * - 웹 zip : EndgameCG_Web_v<버전>/ www/ (서버에 올릴 내용) + 설치·패치 안내 + VERSION.json + MANIFEST.sha256
 *            www/app/manifest.sha256 은 관리자 화면의 "파일 무결성 검사"가 쓴다.
 */

const WEB_ONLY_PAGES = ['login.php', 'logout.php', 'invite.php', 'reset.php', 'pending.php', 'account.php', 'admin.php', 'install.php'];

function rel_files(string $dir): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile()) {
            $out[] = str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
        }
    }
    sort($out, SORT_STRING);
    return $out;
}

/** 배포에서 빼는 파일: 설정·데이터·생성 파일·숨김 파일(.htaccess 제외) */
function release_skip_www(string $rel): bool
{
    return $rel === 'app/config.php'
        || $rel === 'app/manifest.sha256'
        || str_starts_with($rel, 'app/storage/')
        || (bool)preg_match('#(^|/)\.(?!htaccess$)#', $rel);
}

/**
 * @param string $kind 'pc' | 'web'
 * @return array<string, string> 패키지 안 경로 => 원본 파일 경로
 */
function release_collect(string $cg, string $kind): array
{
    $files = [];
    foreach (rel_files("$cg/www") as $rel) {
        if (release_skip_www($rel)) {
            continue;
        }
        if ($kind === 'pc' && (in_array($rel, WEB_ONLY_PAGES, true) || str_starts_with($rel, 'assets/portal.')
            || $rel === '.htaccess' || $rel === 'app/config.sample.php')) {
            continue;
        }
        $files["www/$rel"] = "$cg/www/$rel";
    }
    if ($kind === 'pc') {
        foreach (rel_files("$cg/desktop") as $rel) {
            if (str_starts_with($rel, 'runtime/php/') || str_starts_with($rel, 'data/')) {
                continue; // 포터블 PHP·로컬 데이터는 넣지 않는다
            }
            $files[$rel] = "$cg/desktop/$rel";
        }
    } else {
        foreach (['INSTALL_KR.md', 'PATCHING_KR.md'] as $doc) {
            $files[$doc] = "$cg/web/$doc";
        }
    }
    ksort($files, SORT_STRING);
    return $files;
}

function manifest_text(array $hashes): string
{
    $lines = [];
    foreach ($hashes as $rel => $hash) {
        $lines[] = "$hash  $rel";
    }
    return implode("\n", $lines) . "\n";
}

/** @return array{zip:string, name:string, count:int} */
function release_build(string $cg, string $kind, string $outDir): array
{
    $ver = json_decode((string)file_get_contents("$cg/www/app/version.json"), true, 8, JSON_THROW_ON_ERROR);
    $name = sprintf('EndgameCG_%s_v%s', $kind === 'pc' ? 'PC' : 'Web', $ver['version']);
    $files = release_collect($cg, $kind);
    $contents = [];
    foreach ($files as $rel => $src) {
        $contents[$rel] = (string)file_get_contents($src);
    }
    $contents['VERSION.json'] = json_encode($ver + ['package' => $kind], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    if ($kind === 'web') {
        // 서버 무결성 검사용 (www 기준 경로). install.php 는 설치 후 삭제하므로 제외한다.
        $www = [];
        foreach ($contents as $rel => $data) {
            if (str_starts_with($rel, 'www/') && $rel !== 'www/install.php') {
                $www[substr($rel, 4)] = hash('sha256', $data);
            }
        }
        $contents['www/app/manifest.sha256'] = manifest_text($www);
    }
    ksort($contents, SORT_STRING);
    $contents['MANIFEST.sha256'] = manifest_text(array_map(static fn($d) => hash('sha256', $d), $contents));

    @mkdir($outDir, 0775, true);
    $zipPath = "$outDir/$name.zip";
    @unlink($zipPath);
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
        throw new RuntimeException("zip을 만들 수 없습니다: $zipPath");
    }
    foreach ($contents as $rel => $data) {
        $zip->addFromString("$name/$rel", $data, ZipArchive::FL_ENC_UTF_8);
    }
    $zip->close();
    return ['zip' => $zipPath, 'name' => $name, 'count' => count($contents)];
}

/** zip 안 파일들 (맨 위 폴더 이름을 뺀 경로 => 내용) */
function zip_entries(string $zipPath): array
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
        throw new RuntimeException("zip을 열 수 없습니다: $zipPath");
    }
    $out = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $n = (string)$zip->getNameIndex($i);
        if (str_ends_with($n, '/')) {
            continue;
        }
        $rel = substr($n, strpos($n, '/') + 1);
        $out[$rel] = (string)$zip->getFromIndex($i);
    }
    $zip->close();
    return $out;
}

/**
 * 두 배포 zip을 비교해 바뀐 파일만 담은 패치 zip을 만든다.
 * 업로드 순서: 일반 파일 → www/app/manifest.sha256 → www/app/version.json → VERSION.json (버전 파일은 마지막)
 * @return array{zip:string, added:list<string>, changed:list<string>, removed:list<string>}
 */
function patch_build(string $oldZip, string $newZip, string $outZip): array
{
    $old = zip_entries($oldZip);
    $new = zip_entries($newZip);
    $vOld = json_decode($old['VERSION.json'] ?? '{}', true);
    $vNew = json_decode($new['VERSION.json'] ?? '{}', true);
    if (($vOld['package'] ?? null) !== ($vNew['package'] ?? null)) {
        throw new RuntimeException('PC zip과 웹 zip은 서로 비교할 수 없습니다.');
    }
    $added = $changed = [];
    foreach ($new as $rel => $data) {
        if ($rel === 'MANIFEST.sha256') {
            continue;
        }
        if (!array_key_exists($rel, $old)) {
            $added[] = $rel;
        } elseif (hash('sha256', $old[$rel]) !== hash('sha256', $data)) {
            $changed[] = $rel;
        }
    }
    $removed = array_values(array_filter(array_keys($old), static fn($r) => $r !== 'MANIFEST.sha256' && !array_key_exists($r, $new)));
    $last = ['www/app/manifest.sha256', 'www/app/version.json', 'VERSION.json'];
    $order = static function (array $list) use ($last): array {
        $head = array_values(array_diff($list, $last));
        sort($head, SORT_STRING);
        return array_merge($head, array_values(array_intersect($last, $list)));
    };
    $added = $order($added);
    $changed = $order($changed);
    $kind = $vNew['package'] ?? 'web';
    $name = sprintf('EndgameCG_%s_patch_%s_to_%s', $kind === 'pc' ? 'PC' : 'Web', $vOld['version'] ?? '?', $vNew['version'] ?? '?');
    $info = "끝장전 CG 패치 {$vOld['version']} → {$vNew['version']} (" . ($kind === 'pc' ? 'PC' : '웹') . ")\n\n";
    if ($kind === 'web') {
        $info .= "웹: 아래 파일을 순서대로 FTP 업로드하세요. www/ 안의 파일은 서버 웹 폴더 기준 경로입니다.\n"
            . "버전 파일(app/version.json)은 반드시 마지막에 올리고, 관리자 화면에서 '파일 무결성 검사'로 확인하세요.\n"
            . "app/config.php 는 절대 덮어쓰지 마세요.\n\n";
    } else {
        $info .= "PC: 전체 배포 zip을 새 폴더에 푸는 방법을 권장합니다 (작업 데이터는 %LOCALAPPDATA%\\EndgameCG 에 그대로 있음).\n"
            . "이 패치로 덮어쓸 때는 프로그램을 종료한 뒤 아래 파일을 같은 위치에 복사하세요.\n\n";
    }
    $info .= "[추가]\n" . implode("\n", $added ?: ['(없음)']) . "\n\n[변경]\n" . implode("\n", $changed ?: ['(없음)'])
        . "\n\n[삭제할 파일]\n" . implode("\n", $removed ?: ['(없음)']) . "\n";
    $meta = ['product' => 'EndgameCG', 'package' => $kind, 'from' => $vOld['version'] ?? null, 'to' => $vNew['version'] ?? null,
        'changes' => []];
    foreach (array_merge($added, $changed) as $rel) {
        $meta['changes'][] = ['path' => $rel, 'before' => isset($old[$rel]) ? hash('sha256', $old[$rel]) : null,
            'after' => hash('sha256', $new[$rel])];
    }
    foreach ($removed as $rel) {
        $meta['changes'][] = ['path' => $rel, 'before' => hash('sha256', $old[$rel]), 'after' => null];
    }
    @unlink($outZip);
    $zip = new ZipArchive();
    if ($zip->open($outZip, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
        throw new RuntimeException("패치 zip을 만들 수 없습니다: $outZip");
    }
    $zip->addFromString("$name/PATCH_INFO.txt", $info, ZipArchive::FL_ENC_UTF_8);
    $zip->addFromString("$name/patch.json", json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n", ZipArchive::FL_ENC_UTF_8);
    foreach (array_merge($added, $changed) as $rel) {
        $zip->addFromString("$name/files/$rel", $new[$rel], ZipArchive::FL_ENC_UTF_8);
    }
    $zip->close();
    return ['zip' => $outZip, 'added' => $added, 'changed' => $changed, 'removed' => $removed];
}
