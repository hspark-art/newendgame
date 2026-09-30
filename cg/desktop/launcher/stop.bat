@echo off
chcp 65001 >nul
setlocal EnableExtensions
title 끝장전 CG 종료
set "PORT=3100"
set "FOUND=0"
for /f "tokens=5" %%p in ('netstat -ano ^| findstr /r /c:"TCP.*:%PORT% .*LISTENING"') do (
  tasklist /FI "PID eq %%p" 2>nul | findstr /i "php.exe" >nul
  if not errorlevel 1 (
    taskkill /PID %%p /F >nul 2>&1
    set "FOUND=1"
  )
)
if "%FOUND%"=="1" (
  echo 끝장전 CG 서버를 종료했습니다. OBS/vMix 송출 화면은 마지막 화면을 유지한 채 재연결을 기다립니다.
) else (
  echo 실행 중인 끝장전 CG 서버가 없습니다.
)
echo.
pause
