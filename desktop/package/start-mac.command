#!/bin/bash
# 끝장전 관리 PC 버전 (Mac) - 더블클릭으로 실행
cd "$(dirname "$0")" || exit 1
if ! command -v php >/dev/null 2>&1; then
  echo "PHP 가 설치되어 있지 않습니다."
  echo "터미널에서 아래 명령으로 설치한 뒤 다시 실행해 주세요. (Homebrew 필요: https://brew.sh)"
  echo ""
  echo "    brew install php"
  echo ""
  read -r -p "엔터를 누르면 닫힙니다." _
  exit 1
fi
php desktop/launcher.php
status=$?
if [ $status -ne 0 ]; then read -r -p "엔터를 누르면 닫힙니다." _; fi
exit $status
