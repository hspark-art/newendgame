# PC / 웹 반영 현황

- 두 버전은 같은 `www/` 코드를 쓴다. 기능은 기본적으로 양쪽에 함께 반영된다. 차이는 접근 방식(계정·송출 주소)과 설치 방법뿐이다.
- 버전 기준: `www/app/version.json`

| 항목 | PC 응용프로그램 | 웹 버전 |
|---|---|---|
| 상태 | 첫 MVP 완료 (Windows 실행 미검증) | 첫 MVP 완료 (실제 호스팅 미검증) |
| 상대 종족 승률 CG (MOCK) | 구현 | 구현 (공용 코드) |
| 페이지 리스트·번호 큐·단축키 | 구현 | 구현 (공용 코드) |
| PREVIEW/PROGRAM·TAKE·SHOW/OUT | 구현 | 구현 (공용 코드) |
| QUICK EDIT·Override·UPDATE LIVE | 구현 | 구현 (공용 코드) |
| 실행 방법 | 시작.bat (포터블 PHP) | 웹호스팅 업로드 + install.php |
| 계정 | 없음 (이 PC 전용) | 초대 → 가입 → 승인, 관리자/운영자 |
| 송출 주소 | 127.0.0.1 / LAN IP | 비밀 토큰 주소 |
| 나머지 CG 8종 | 미착수 (ROADMAP PHASE 7) | 미착수 |
| Google Sheets / 외부 사이트 | 미착수 (PHASE 8·9) | 미착수 |

| 페이지 리스트 내보내기/가져오기 | 구현 | 구현 (PC↔웹 파일 호환) |
| 무결성 검사 | 해당 없음 (새 폴더에 풀기) | 관리자 화면 |

**실환경 확인이 필요한 항목**
- Windows 실행과 OBS/vMix 송출.
- 실제 웹호스팅(Apache·.htaccess)·HTTPS. MySQL은 개발 환경의 MariaDB 10.11로 확인했다.
