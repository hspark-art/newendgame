# 인수인계 (세션이 바뀔 때 먼저 읽기)

최종 갱신: 2026-10-07 · 작업 버전 v0.8.0 (DB 구조 6, 변경 없음)

## 1. 최신 원본의 출처
- 기준 원본: 이 저장소 `cg/` (브랜치 `claude/optimistic-cray-mekgoi`). 운영 서버는 카페24 웹호스팅 `~/www/cg/` (https://etalent.co.kr/cg/), SSH 계정 talente, PHP `/usr/local/php/bin/php`.
- 운영 서버 상태: 2026-10-07 사용자가 서버 점검 결과를 보내 줌 — `v0.7.0 · 파일 133개 모두 배포본과 같습니다 · 점검 완료: 실패 없음`. 즉 서버 파일 = `dist/EndgameCG_Web_v0.7.0.zip`.
- 주의: FTP 루트 `/www`에는 다른 사이트의 `app`·`assets`·`index.php`·`config.php`가 있다. CG 파일은 반드시 `/www/cg` 안. 루트 `design.php`(화면)와 `app/design.php`(라이브러리)는 다른 파일이다(과거 잘못 올려 사이트 오류 → 백업으로 복구).
- 175.118.124.225(root)는 다른 서비스가 있는 별도 서버다. 쓰지 않는다.
- 서버 확인 방법: `cd ~/www/cg && /usr/local/php/bin/php app/cli.php check` → 첫 줄 버전 + "파일 N개 모두 배포본과 같습니다". `[실패]` 파일은 다른 담당자 변경일 수 있으니 받아서 비교한 뒤에만 덮어쓴다.

## 2. 요청과 승인받은 계획
- v0.8.0 요청서: "매치 기록 상세 데이터 보강" — 연승·연패 경기 목록(`YYYY.MM.DD | 상대 | 4–5 패`, 그 선수 기준), 맞대결 간격(마지막 맞대결 날짜·승자·이름과 함께 스코어·경과·상대전적 매치/세트), 출전 간격(마지막 출전·결과·경과), 방송 가독성, 요약/상세 분리, 추가 기록 제안. 계획 먼저, 기존 저장 페이지 호환, 전달 형식은 이전과 같음.
- 사용자 결정 (2026-10-07): 새 CG '기록 상세' / 5경기 넘으면 최근 5경기(+ 2쪽 6~10번째는 운영자가 고를 때만, "권장안대로") / 보조 정보 둘 다(연패 직전 마지막 승리 = 직전 경기 한 줄, 요약 1개일 때 근거 한 줄) / 추가 기록 둘 다(최근 5매치 흐름, 현재 연승 vs 개인 최다).
- 답변 대기 → 보수적으로 처리(v0.7.0부터 그대로): ① 시트가 첫 끝장전부터 빠짐없이 기록됐는지(→ 'N번째 출전·맞대결' 미구현, 매치 프리뷰 "첫 맞대결" 문구 보류, 시트 첫 기록까지 이어지는 연승은 확인 필요, 개인 최다는 '시트 기록(YYYY.MM~) 기준'으로 표시) ② Results 행 순서 = 세트 순서인지(→ 세트 연승·역전승 미구현).

## 3. 수정한 파일 (v0.8.0, 0.7.0 대비)
- 신규: `www/app/templates/record-detail.def.php`, `.view.php`
- 수정: `www/app/stats.php`, `www/app/views.php`, `www/app/templates/match-records.def.php`, `.view.php`, `www/index.php`, `www/assets/panel.js`, `panel.css`, `cg.css`, `www/app/version.json`, `tests/records_test.php`, `tests/cg_types_test.php`, `tests/match_test.php`, `docs/CG_REFERENCE_GUIDE.md`(12·14절), `docs/ROADMAP.md`, `docs/HANDOVER.md`, `tests/VALIDATION.md`(12-6절)
- 서버에 올리는 파일(사이트 루트 기준 11개): `app/stats.php`, `app/views.php`, `app/templates/{record-detail,match-records}.{def,view}.php`, `assets/{cg.css,panel.js,panel.css}`, `index.php`, `app/manifest.sha256`, `app/version.json`.

## 4. 완료한 작업
- 기록 상세 CG·근거 보강·요약 근거 한 줄·최근 5매치·개인 최다, 오늘 매치 창 [상세 CG 추가]·[상세 6~N번째 경기], 페이지 추가 입력(기록 1개 + 쪽).
- 검증: 자동 테스트(SQLite·MariaDB), 실데이터 사본 독립 계산 대조, 브라우저, 0.7.0→0.8.0 업데이트·되돌리기 리허설, 별도 코드 리뷰 반영 (VALIDATION 12-6절).
- 전달물(저장소 밖, `cg/dist/`): `EndgameCG_web_0.7.0-0.8.0_files.zip`(사이트 루트 기준), `EndgameCG_v0.8.0_INSTALL_KR.md`(설치 안내, 서버에 올리지 않음). 만드는 법: `php tools/build_release.php` → `php tools/build_patch.php dist/EndgameCG_Web_v0.7.0.zip dist/EndgameCG_Web_v0.8.0.zip 패치.zip` → 패치 zip의 `files/www/` 아래를 사이트 루트 기준으로 다시 묶는다.

## 5. 남은 작업
- 사용자 답변 ①②를 받으면: `RECORDS_FULL_HISTORY`(stats.php) 조정, 'N번째 출전·맞대결'·'세트 연승'·'역전승' 추가 여부, 매치 프리뷰 "첫 맞대결" 문구.
- 서버 적용 후 운영자 확인 결과(OBS 화면 크기·위치, 기록 상세 가독성) 받기.
- 서버의 Google 시트 새로고침 실패(2026-10-07 19:13~, Results 2676~2680행 선수 이름·종족 형식 오류)는 시트 쪽 수정 필요 — 사용자에게 안내함.

## 6. 검증 결과와 미확인 사항
- 결과: tests/VALIDATION.md 12-6절(v0.8.0), 12-5절(v0.7.0).
- 미확인: 실제 운영 서버에서의 동작(업로드 후 사용자 점검), OBS/vMix 실제 화면, 서버의 최신 Google 시트(실데이터 대조는 2026-09-30 xlsx 사본).
- 인증 정보(비밀번호·키·송출 주소 토큰)는 이 문서와 저장소에 적지 않는다.
