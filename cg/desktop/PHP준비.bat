@echo off
chcp 65001 >nul
rem 포터블 PHP를 runtime\php 폴더에 내려받습니다 (처음 한 번만).
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0PHP준비.ps1"
echo.
pause
