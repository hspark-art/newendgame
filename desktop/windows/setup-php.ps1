# 끝장전 관리 PC 버전 - 처음 실행할 때 PHP 를 자동으로 설치합니다.
# (공식 배포처 windows.php.net 에서 내려받아 프로그램 폴더 안의 php 폴더에 풀어 둡니다. 컴퓨터 설정은 바꾸지 않습니다)
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
try { [Console]::OutputEncoding = [System.Text.Encoding]::UTF8 } catch { }
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$root   = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$phpDir = Join-Path $root 'php'
$tmp    = Join-Path $env:TEMP ('endgame-setup-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Force -Path $tmp | Out-Null

$ini = @'
; PHP settings for Endgame Manager (desktop)
[PHP]
memory_limit = 512M
max_execution_time = 600
upload_max_filesize = 512M
post_max_size = 520M
display_errors = Off
log_errors = On
default_charset = "UTF-8"
date.timezone = Asia/Seoul
extension=curl
extension=fileinfo
extension=mbstring
extension=openssl
extension=pdo_sqlite
extension=sqlite3
'@

try {
    Write-Host ''
    Write-Host '처음 실행 준비: 프로그램에 필요한 PHP 를 설치합니다. (한 번만, 약 1~2분)'

    # 1) Microsoft Visual C++ 런타임 (PHP 실행에 필요)
    $sys = Join-Path $env:WINDIR 'System32'
    if (-not (Test-Path (Join-Path $sys 'vcruntime140.dll')) -or -not (Test-Path (Join-Path $sys 'vcruntime140_1.dll'))) {
        Write-Host 'Microsoft Visual C++ 런타임을 설치합니다. 권한 확인 창이 뜨면 [예]를 눌러주세요.'
        $vc = Join-Path $tmp 'vc_redist.x64.exe'
        Invoke-WebRequest -Uri 'https://aka.ms/vs/17/release/vc_redist.x64.exe' -OutFile $vc -UseBasicParsing
        $p = Start-Process -FilePath $vc -ArgumentList '/install', '/passive', '/norestart' -Wait -PassThru
        if (@(0, 1638, 3010) -notcontains $p.ExitCode) { throw ('Visual C++ 런타임 설치 실패 (코드 ' + $p.ExitCode + ')') }
    }

    # 2) PHP 최신 안정 버전 찾기 (8.4 → 8.3 → 8.5 순서)
    Write-Host 'PHP 버전 정보를 확인하는 중...'
    $rel = Invoke-RestMethod -Uri 'https://windows.php.net/downloads/releases/releases.json' -UseBasicParsing
    $build = $null
    $version = ''
    foreach ($ver in @('8.4', '8.3', '8.5')) {
        $info = $rel.$ver
        if ($null -eq $info) { continue }
        foreach ($prop in $info.PSObject.Properties) {
            if ($prop.Name -match '^nts-vs\d+-x64$' -and $null -ne $prop.Value.zip) {
                $build = $prop.Value
                $version = $info.version
                break
            }
        }
        if ($null -ne $build) { break }
    }
    if ($null -eq $build) { throw 'PHP 설치 파일 정보를 찾지 못했습니다.' }

    # 3) 내려받기 · 무결성 확인 · 압축 풀기
    $zipName = $build.zip.path
    $zip = Join-Path $tmp $zipName
    Write-Host ('PHP ' + $version + ' 내려받는 중... (약 30MB)')
    Invoke-WebRequest -Uri ('https://windows.php.net/downloads/releases/' + $zipName) -OutFile $zip -UseBasicParsing
    if ($build.zip.sha256) {
        $hash = (Get-FileHash -Algorithm SHA256 -Path $zip).Hash
        if ($hash -ne $build.zip.sha256) { throw '내려받은 파일이 손상되었습니다. 다시 실행해 주세요.' }
    }
    Write-Host '압축 푸는 중...'
    if (Test-Path $phpDir) { Remove-Item -Recurse -Force $phpDir }
    Expand-Archive -Path $zip -DestinationPath $phpDir -Force
    Set-Content -Path (Join-Path $phpDir 'php.ini') -Value $ini -Encoding ASCII

    # 4) 인증서 목록 (SOOP 접속용, 실패해도 Windows 인증서로 대신함)
    try {
        Invoke-WebRequest -Uri 'https://curl.se/ca/cacert.pem' -OutFile (Join-Path $phpDir 'cacert.pem') -UseBasicParsing
    } catch {
        Write-Host '(참고) 인증서 목록을 받지 못해 Windows 인증서를 사용합니다.'
    }

    # 5) 실행 확인
    & (Join-Path $phpDir 'php.exe') -n -r 'exit(0);'
    if ($LASTEXITCODE -ne 0) { throw 'PHP 실행 확인에 실패했습니다. Visual C++ 런타임 설치가 필요할 수 있습니다.' }

    Write-Host 'PHP 설치 완료'
    exit 0
}
catch {
    Write-Host ''
    Write-Host ('[설치 실패] ' + $_.Exception.Message)
    Write-Host '인터넷 연결을 확인한 뒤 다시 실행하거나, 사용안내.txt 의 "직접 설치하는 방법"을 참고해 주세요.'
    if (Test-Path (Join-Path $phpDir 'php.exe')) { Remove-Item -Recurse -Force $phpDir -ErrorAction SilentlyContinue }
    exit 1
}
finally {
    Remove-Item -Recurse -Force $tmp -ErrorAction SilentlyContinue
}
