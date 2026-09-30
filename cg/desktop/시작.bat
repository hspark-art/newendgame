@echo off
chcp 65001 >nul
setlocal EnableExtensions
title 끝장전 CG
cd /d "%~dp0"

set "PORT=3100"
set "BIND=127.0.0.1"
set "CG_LAN=0"
if /i "%~1"=="lan" (
  set "BIND=0.0.0.0"
  set "CG_LAN=1"
)
set "PHP=%~dp0runtime\php\php.exe"
set "CG_CONFIG=%~dp0config.desktop.php"
set "CG_DATA_DIR=%LOCALAPPDATA%\EndgameCG"

echo %~dp0| findstr /i "OneDrive" >nul
if not errorlevel 1 (
  echo [주의] OneDrive 폴더에서는 동기화 때문에 오류가 날 수 있습니다.
  echo        C:\EndgameCG 처럼 OneDrive 밖의 폴더로 옮기는 것을 권장합니다.
  echo.
)

if not exist "%PHP%" (
  echo [안내] PHP 실행 파일이 없습니다: runtime\php\php.exe
  echo        같은 폴더의 "PHP준비.bat" 을 먼저 실행하세요. 자세한 방법은 README_KR.txt
  echo.
  pause
  exit /b 1
)

"%PHP%" -v >nul 2>&1
if errorlevel 1 (
  echo [오류] PHP를 실행할 수 없습니다.
  echo        Microsoft Visual C++ 재배포 가능 패키지 2015-2022 x64 설치가 필요할 수 있습니다.
  echo        https://aka.ms/vs/17/release/vc_redist.x64.exe
  echo.
  pause
  exit /b 1
)

rem 이미 실행 중이면 조작 패널만 다시 연다
curl -s -m 2 "http://127.0.0.1:%PORT%/api/ping.php" 2>nul | findstr /c:"EndgameCG" >nul
if not errorlevel 1 goto open

if not exist "%CG_DATA_DIR%" mkdir "%CG_DATA_DIR%"
start "끝장전 CG 서버 - 이 창을 닫으면 송출이 멈춥니다" /min "%PHP%" -c "%~dp0runtime\php.ini" -d extension_dir="%~dp0runtime\php\ext" -S %BIND%:%PORT% -t "%~dp0www" "%~dp0router.php"

rem 서버 준비 대기 (최대 약 15초)
for /l %%i in (1,1,15) do (
  ping -n 2 127.0.0.1 >nul
  curl -s -m 1 "http://127.0.0.1:%PORT%/api/ping.php" 2>nul | findstr /c:"EndgameCG" >nul
  if not errorlevel 1 goto open
)
echo [오류] 서버가 시작되지 않았습니다.
echo        다른 프로그램이 %PORT% 포트를 쓰고 있는지, 최소화된 "끝장전 CG 서버" 창의 메시지를 확인하세요.
echo.
pause
exit /b 1

:open
set "URL=http://127.0.0.1:%PORT%/"
set "EDGE=%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe"
if not exist "%EDGE%" set "EDGE=%ProgramFiles%\Microsoft\Edge\Application\msedge.exe"
if exist "%EDGE%" (
  start "" "%EDGE%" --app="%URL%" --window-size=1920,1080 --start-maximized
) else (
  start "" "%URL%"
)
if "%CG_LAN%"=="1" (
  echo 다른 PC의 OBS/vMix 송출 주소는 조작 패널 오른쪽 "송출 주소"에 표시됩니다.
  echo 처음 실행할 때 Windows 방화벽 허용 창이 뜨면 "개인 네트워크"를 허용하세요.
  echo 조작 패널은 이 PC에서만 열립니다.
  echo.
  pause
)
exit /b 0
