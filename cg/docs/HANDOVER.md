# 인수인계 (세션이 바뀔 때 먼저 읽기)

최종 갱신: 2026-10-07 · 작업 버전 v0.7.0 (DB 구조 6, 변경 없음)

## 1. 최신 원본의 출처
- 기준 원본: 이 저장소 `cg/` (브랜치 `claude/optimistic-cray-mekgoi`). 운영 서버는 카페24 웹호스팅 `~/www/cg/` (https://etalent.co.kr/cg/), SSH 계정 talente.
- **운영 서버 원본은 아직 직접 확인하지 못했다.** 작업 환경에서 운영 서버 접속이 막혀 있다(프록시 403). 2026-10-07 송출 로그의 "이번 맵 빼기"(묶음 단위 기록)로 보아 서버는 v0.6.0으로 추정.
- 서버 확인 방법: 서버에서 `cd ~/www/cg && /usr/local/php/bin/php app/cli.php check` → 첫 줄 버전 + "파일 N개 모두 배포본과 같습니다"면 서버 파일 = 그 버전 배포본(`dist/EndgameCG_Web_v*.zip`). `[실패]` 파일은 다른 담당자 변경일 수 있으니 받아서 비교한 뒤에만 덮어쓴다.

## 2. 요청과 승인받은 계획
- 요청서: 사용자 업로드 "끝장전 CG 제작툴 업데이트 요청" (매치별 인상적인 기록 CG, 매치 프리뷰 크기 통일, 관련 오류 점검, 수정 파일만 사이트 루트 기준 ZIP + 별도 설치 안내서).
- 사용자 결정 (2026-10-07): 누적 ZIP(0.6.0 → 0.7.0), 기록 CG = 한 장에 최대 3개(1개면 크게), 매치 프리뷰 = 폭 560 + 높이 줄 수만큼.
- 답변 대기 → 보수적으로 처리: ① 서버 점검 결과(설치 안내서 1단계로 넣음) ② 시트가 첫 끝장전부터 기록됐는지(→ 'N번째 출전·맞대결' 미구현, 시트 첫 기록까지 이어지는 연승은 확인 필요) ③ Results 행 순서 = 세트 순서인지(→ 세트 연승 미구현). 선정 기준(3연승·365일 등)은 제안대로 진행.

## 3. 수정한 파일 (v0.7.0)
- 신규: `www/app/templates/match-records.def.php`, `.view.php`, `tests/records_test.php`, `docs/HANDOVER.md`
- 수정: `www/app/stats.php`, `templates.php`, `match.php`, `actions.php`, `www/index.php`, `www/assets/panel.js`, `panel.css`, `cg.css`, `www/app/version.json`, `tests/cg_types_test.php`, `tests/match_test.php`, `docs/CG_REFERENCE_GUIDE.md`(12·13절), `docs/ROADMAP.md`, `tests/VALIDATION.md`
- 0.6.1·0.6.2 변경분(항목마다 빼기, 자동 새로고침 정리)은 누적 ZIP에 함께 들어간다.

## 4. 완료한 작업
- 기록 계산·CG·오늘 매치 연결·페이지 추가 입력, 매치 프리뷰 560, 규격표 문서화, 자동 테스트·실데이터 대조·브라우저 확인(VALIDATION 12-5절).

## 5. 남은 작업
- 사용자 답변 ②③을 받으면: `RECORDS_FULL_HISTORY`(stats.php) 조정, 'N번째 출전·맞대결'·'세트 연승' 추가 여부 결정, 매치 프리뷰 "첫 맞대결" 문구 유지/변경.
- 서버 적용 후 운영자 확인 결과(OBS 화면 크기·위치) 받기.

## 6. 검증 결과와 미확인 사항
- 결과: tests/VALIDATION.md 12-5절.
- 미확인: 실제 운영 서버 파일·동작, OBS/vMix 실제 화면, 서버의 최신 Google 시트(실데이터 대조는 2026-09-30 xlsx 사본).
- 인증 정보(비밀번호·키)는 이 문서와 저장소에 적지 않는다.
