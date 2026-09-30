# 끝장전 CG — 포터블 PHP 준비 (Windows 10/11)
# runtime\php 폴더에 PHP를 내려받아 압축을 풉니다. 이미 있으면 아무것도 하지 않습니다.
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$dest = Join-Path $root 'runtime\php'
if (Test-Path (Join-Path $dest 'php.exe')) {
    Write-Host 'PHP가 이미 준비되어 있습니다:' $dest
    exit 0
}
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
# PHP 공식 Windows 배포(windows.php.net)의 8.3 최신판 (Non Thread Safe, x64)
$url = 'https://windows.php.net/downloads/releases/latest/php-8.3-nts-Win32-vs16-x64-latest.zip'
$zip = Join-Path $env:TEMP 'endgame-cg-php.zip'
try {
    Write-Host '내려받는 중:' $url
    Invoke-WebRequest -Uri $url -OutFile $zip -UseBasicParsing
    New-Item -ItemType Directory -Force -Path $dest | Out-Null
    Expand-Archive -Path $zip -DestinationPath $dest -Force
    Remove-Item $zip -Force
} catch {
    Write-Host ''
    Write-Host '[자동 준비 실패]' $_.Exception.Message
    Write-Host '직접 준비하는 방법:'
    Write-Host '  1. https://windows.php.net/download 에서 PHP 8.3 "VS16 x64 Non Thread Safe" Zip 을 받습니다.'
    Write-Host '  2. 받은 파일을 마우스 오른쪽 > 속성 > "차단 해제" 체크 후 확인.'
    Write-Host '  3. 압축을 풀어 php.exe 가' $dest '안에 오도록 넣습니다.'
    exit 1
}
if (Test-Path (Join-Path $dest 'php.exe')) {
    & (Join-Path $dest 'php.exe') -v
    Write-Host ''
    Write-Host '준비 완료. 이제 "시작.bat" 을 더블클릭하세요.'
} else {
    Write-Host '[오류] 압축을 풀었지만 php.exe 를 찾지 못했습니다:' $dest
    exit 1
}
