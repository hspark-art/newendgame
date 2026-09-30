@echo off
chcp 65001 >nul
title Endgame Manager
cd /d "%~dp0"

if exist "php\php.exe" goto run
powershell -NoProfile -ExecutionPolicy Bypass -File "desktop\windows\setup-php.ps1"
if errorlevel 1 goto fail

:run
set "CA="
if exist "php\cacert.pem" set "CA=-d curl.cainfo=php\cacert.pem -d openssl.cafile=php\cacert.pem"
"php\php.exe" -d extension_dir=php\ext %CA% desktop\launcher.php
if errorlevel 1 goto fail
exit /b 0

:fail
echo.
pause
exit /b 1
