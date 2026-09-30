@echo off
chcp 65001 >nul
rem 다른 PC(같은 네트워크)의 OBS/vMix가 송출 화면을 읽을 수 있게 엽니다. 조작은 이 PC에서만 됩니다.
call "%~dp0시작.bat" lan
