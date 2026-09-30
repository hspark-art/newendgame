@echo off
chcp 65001 >nul
rem 끝장전 CG 서버 종료 (실제 동작은 launcher\stop.bat)
call "%~dp0launcher\stop.bat"
