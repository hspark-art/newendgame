# 단계별 구현 로드맵

## 진행 방식
한 번에 한 PHASE를 수행한다. 새 기능·구조 변경의 범위와 위험을 간단히 설명하고 확인받은 뒤 구현한다. 작은 수정은 계획을 생략한다. 실제 연결 미확정 사항은 MOCK으로 분리하며, 필수 입력이 없는 연동을 완료했다고 하지 않는다.

각 단계 종료 시 변경 파일, 실행 방법, 테스트/브라우저 결과, 미검증 항목, 남은 문제, 다음 단계를 보고한다. 코드 리뷰와 불필요한 코드 정리를 수행하고 관련 오류를 바로 수정한다. 입력·외부 API·인증 관련 구현에는 보안 검토, 에러를 삼키는 부분에는 실패 처리 검토를 추가한다. 테스트 인프라가 없는 초기 프로젝트에는 필요한 통계·상태 검증부터 마련하며 불필요한 커버리지 목표를 강제하지 않는다.

## PHASE와 완료 조건
| 단계 | 작업 | 완료 조건 |
|---|---|---|
| 0 레퍼런스 | 첨부 스크린샷 전부 분석, CG 9종 대응 | 실제 관찰과 추정을 구분한 레이아웃 요약, 누락 자료·미확정 폰트 표시 |
| 1 아키텍처 | 기존 프로젝트 점검, 기술 구조·폴더·상태 흐름 계획 | ARCHITECTURE와 짧은 계획, 사용자 확인; 아직 코딩하지 않음 |
| 2 Data Model | Player/Match/Game/Source/CG 모델, 상태·Override 범위 | DATA_MODEL, 경기/게임 단위·ID·유효값·관계·원본/수정 분리 정의 |
| 3 Mock Data | 샘플 JSON, Stats Engine 기본, Fixture | MOCK 표시, 상대 종족 승률·0경기·입력 검증 테스트 |
| 4 첫 CG | 상대 종족 승률 1종, Electron 패널·로컬 서버·CG URL | 1920×1080 투명 화면, 우측 하단 CG, 실제 브라우저 확인, 더블클릭 시작 수단 |
| 5 PREVIEW/PROGRAM | 독립 상태, TAKE, SHOW/HIDE, WebSocket | PREVIEW 편집이 PROGRAM을 바꾸지 않음; TAKE 시 데이터 스냅샷 반영; 재연결 시 상태 복원 |
| 6 Manual Override | QUICK EDIT, 필드 수정, 재계산, RESET, UPDATE LIVE, 로그·세션·KEEP OVERRIDE | DATA_AND_OVERRIDE의 핵심 시나리오 통과; 여기까지 첫 MVP 완료 |
| 7 나머지 CG | 맞대결 → 최근전적 → 다승 → 나머지 5종 | 9종 모두 MOCK 기반 구현·통계 Fixture·브라우저 검증, 입력 범위·집계 정의 확인 |
| 8 Google Sheets | API, 인증, 컬럼 Mapping, Cache·갱신·정규화·Alias | 실제 접근 허용된 Sheet로 검증, 실패 시 마지막 정상값·STALE/ERROR, Override/PROGRAM 유지 |
| 9 Website | URL/구조/접근 방법 확인 후 Provider 구현 | 실제 사이트 수집·파싱·정규화·검증, 실패/구조 변경 감지; 미확정이면 단계 대기 |
| 10 Data Conflict | Source별 근거 비교·선택·수동 해결 | USE GOOGLE SHEET / USE WEBSITE / MANUAL EDIT, 미해결 송출 방지와 해결 이력 확인 |
| 11 DATA CHECK | 현재 경기 CG·Source 상태를 한 화면에 표시 | AUTO OK/MANUAL/CONFLICT/STALE/ERROR·시각·근거 확인, 수정/확인으로 연결 |
| 12 방송 안정화 | 실패·재연결·재시작·장문/다행·실운영 점검 | 빈 CG 방지, 송출 상태 보존, 로그, OBS/vMix 연결·조작 리허설, 운영 README |
| 13 웹 이전 준비 | Electron 의존성·설정·환경 점검 | 코어가 Electron 없이 실행됨, 이전에 필요한 사항 문서화; 실제 클라우드/DB 전환은 별도 요청 |

충돌 데이터의 기본 표현·원본 분리·실패 상태는 초기 모델부터 고려한다. 실제 Source 간 충돌 UI 완성은 PHASE 10에서 한다. 첫 MVP를 위해 전체 외부 연동이나 전체 CG를 앞당기지 않는다.

## 첫 MVP 검수표 (PHASE 0~6)
- [ ] Electron Control Panel과 더블클릭 시작 수단 동작
- [ ] MOCK: 조일장 vs P 33승 21패 → 61.1%; 장윤철 vs Z 129승 123패 → 51.2%
- [ ] 상대 종족 승률 CG, 독립 Browser URL, 1920×1080 투명 배경, 레퍼런스에 가까운 우측 하단 배치
- [ ] PREVIEW/PROGRAM 표시, SHOW/HIDE/TAKE/WebSocket 동작
- [ ] QUICK EDIT → SAVE TO PREVIEW, 필드 Override, 자동 재계산/직접 승률 입력, RESET TO AUTO
- [ ] UPDATE LIVE가 현재 PROGRAM 대상에만 명시적으로 적용
- [ ] 자동값 변경 시 수동값 보존, 세션 초기화/KEEP OVERRIDE, 수정 이력
- [ ] 자동 테스트·브라우저 확인 결과와 OBS/vMix 미검증 여부 보고

샘플 수치는 검증용이며 실제 방송 기록이 아니다.

## 세션을 이어가는 방법
CLAUDE.md → 아래 진행 기록 → 현재 단계 관련 문서·코드만 읽는다. 첫 계획은 PROJECT_SPEC을 읽고, 데이터 작업은 DATA_AND_OVERRIDE, 화면 작업은 CG_REFERENCE_GUIDE를 추가로 읽는다. 문서 전체를 매번 프롬프트에 붙여넣지 않는다.

단계 종료 시 이 파일의 기록을 짧게 갱신한다. 긴 대화 전문·원본 로그는 복사하지 않는다.

| 항목 | 현재 기록 |
|---|---|
| 현재 단계 | v0.5.0 — CG 디자인(테마 A~E·폰트 17종 OFL·크기·글자색·프리셋, 관리자 design.php, 송출 화면 즉시 반영) + 새 CG 4종(미션 성공 지수·매치 프리뷰·맵 전적·맵 종족 상성) + 맵 한글 이름(시트 '맵 이름' 탭·프로그램 입력). 기본 모습은 그대로(현재 디자인), 테마는 관리자가 고른다. 매치 프리뷰 '기타' 항목은 사용자 답 대기. v0.4.3 — 카페24 가상서버(웹) 운영 체계: 서버 점검 명령(app/cli.php), SERVER_KR.md, 바뀐 파일만 전달. 서버 정보(OS·웹 서버·DB) 확인 대기. v0.4.2 — 송출 화면 자동 맞춤, 위치·크기 송출에 바로 적용, 시트 '닉네임' 탭. v0.4.1 — MOCK 데이터 제거(배포본·기존 DB), Google 시트 전용. v0.4.0: 더블 찬스 자동값, 연승 종료일, 관리자 알림, 시트 입력 점검. PHASE 9 eloboard는 조건부 대기 |
| 완료 단계 | 0~6 첫 MVP, W 웹 계정·관리자·비밀 송출 주소, R 배포·패치·무결성 검사, 7 CG 9종, 8 Google 시트(세트 단위 원천·끝장전 묶기·이상 경기 분리·시트 집계 교차 검증·마지막 정상 데이터 캐시·데이터 점검 창·선수 닉네임) |
| 변경 파일 | v0.5.0: www/design.php·assets/design.*·cg-fonts.css·fonts/(신규), app/design.php(신규), templates/{mission-index,match-preview,map-record,map-matchup}.*(신규), stats·sheet_data·sheets·verify·templates·override·views·control·data·migrate(5)·actions·route·bootstrap, assets/cg.css·cg-themes.css·output.js·panel.js, output.php·index.php·.htaccess(폰트 MIME), tests/design_test.php·sheet_cg_test.php(신규). v0.4.3: www/app/cli.php(신규), web/SERVER_KR.md(신규), config.sample.php, tools/release_lib.php(패치 안내에 SSH 명령), CLAUDE.md(운영·수정 방식), tests/cli_test.php(신규). v0.4.2: assets/output.*, control(preview_display live), sheet_data·sheets(닉네임 탭), data(player_info_view), index.php·panel.js, tests/nick_test.php(신규)·browser_check.cjs. v0.4.1: www/app/data/mock → tests/fixtures/mock(이동), data·provider·migrate(4)·views, assets/panel.js, tests/nomock_test.php·browser_check_fresh.cjs(신규), 설치 안내. v0.4.0: www/app/alerts.php(신규), sheet_data·sheets·xlsx·verify·data·control·views·actions·stats·migrate(3), templates/{double-chance,win-streak}.*, www/index.php·admin.php·assets/panel.*, tests/alerts_test.php(신규)·sheet·google·cg_types·http_test, browser_check.cjs |
| 검증 결과 / 미검증 | v0.5.0: 자동 테스트 99건(SQLite·MySQL), 브라우저 패널 회귀 + 디자인·송출 19항목(VALIDATION 12절). 실제 시트 xlsx로 지수·수익률 3명, 맵 83개, 선수×맵 1,081행 일치. 미검증: Windows·OBS에서 폰트 렌더링, 카페24 Apache의 woff2 MIME. 자동 테스트 91건 통과(SQLite·MySQL, v0.4.3). 실제 카페24 가상서버는 미검증(서버 정보 대기). v0.4.2: 90건, 브라우저 48항목 + 첫 실행 9항목. v0.4.1: 자동 테스트 87건. 배포 설정 첫 실행 브라우저 9항목, 실제 시트 xlsx로 선수 31명 전원 확인. 실제 시트 xlsx로 더블 찬스 31명 모두 선수별 통계와 일치, 알림 15건(행 번호 포함) 확인. 브라우저 확인은 VALIDATION 8절. **미검증**: 실제 Google 연결(키 없음), Windows openssl·zip, eloboard(접속 차단), OBS/vMix, 실제 호스팅 |
| 미확정·장애 | eloboard 약관·접속(이 환경에서 차단 — 사용자가 네트워크 허용 필요), 예측 순위 표시 순서, 방송 폰트, 웹 도메인·호스팅. 실제 시트 수정 필요: Results 236·281·454·497행 종족 칸 공백, 2110행 변현제 종족 |
| 다음 작업 | 시트 수정 후 새로고침 → 알림 해결 확인 → (eloboard 접속 허용 시) 약관·robots 확인 → PHASE 9 또는 수동 입력 유지 |

**MVP 검수표 (PHASE 0~6)**
- [x] 조작 패널과 더블클릭 시작 수단 (Electron 대신 PHP 로컬 서버 + Edge 앱 창; Windows 실행은 미검증)
- [x] MOCK: 조일장 vs P 33승 21패 → 61.1%; 장윤철 vs Z 129승 123패 → 51.2%
- [x] 상대 종족 승률 CG, 독립 송출 주소, 1920×1080 투명 배경, 레퍼런스에 가까운 우측 하단 배치
- [x] PREVIEW/PROGRAM 표시, SHOW/OUT/TAKE, 실시간 반영(WebSocket 대신 폴링)
- [x] QUICK EDIT → SAVE TO PREVIEW, 필드 Override, 자동 재계산/직접 승률 입력, RESET TO AUTO
- [x] UPDATE LIVE가 현재 PROGRAM 대상에만 명시적으로 적용
- [x] 자동값 변경 시 수동값 보존, 세션 초기화/KEEP OVERRIDE, 수정 이력
- [x] 자동 테스트·브라우저 확인 결과 기록, OBS/vMix 미검증 명시

### 추가 단계 (사용자 요청, 2026-09-30)
| 단계 | 작업 | 완료 조건 |
|---|---|---|
| W 웹 버전 | 로그인·초대·가입·승인·재설정·정지, 관리자 화면, 비밀 출력 주소·재발급, 보안 헤더, 시도 제한, install.php, MySQL | PHASE 6까지의 기능이 웹 모드에서 동작하고, 계정·출력 토큰 테스트가 통과 |
| R 배포 | PC zip·웹 zip(각 VERSION.json·MANIFEST), 패치 zip, 무결성 검사, 설치·패치 안내, 검증 기록 | zip과 manifest가 일치하고, 무결성 검사가 누락·변경 파일을 잡음 |

운영 방식은 토네이도식(페이지 리스트·번호 큐·단축키)이며, PVW/PGM 모니터와 타이틀 에디터는 vMix를 참고한다. 레이어는 1개로 시작하고 레이어 번호를 받는 구조로 설계한다.

### PHASE 8 결정 (2026-09-30)
- 시트 접근: 서비스 계정(읽기 전용) 자동 연결 + xlsx 파일 가져오기(예비). 시트는 공개하지 않는다.
- 기준 탭: Results(1행 = 1세트). 검증 탭: Players, 상대전적조회NEW, 중계진 예측 현황입력용(순위표).
- 시트 자체 집계와 다르거나 대조할 수 없는 수치는 해당 CG 필드 송출 차단(운영자가 직접 입력하면 해제).
- eloboard: 약관·robots 확인 후 자동 수집(사용자 결정). 이 환경에서 접속이 차단되어 확인 전이며, 확인 전까지는 온라인 CG를 수동 입력으로 운영한다.
- 2026-10-01 실제 시트 대조 후 결정
  - 다승 순위는 끝장전 승패로 센다(레퍼런스 04와 일치).
  - 2023-12-11 5세트 경기 2건은 끝장전 통계에서 제외한다. 관리자 "제외 확정" 기능으로 처리하며, 자동 제외 규칙은 두지 않는다.

### v0.4.0 결정 (2026-10-01)
- 연승 CG: "진행 중" 대신 날짜를 표시한다. 종료일 = 연승의 마지막 경기 날짜이고, 지금도 이어지는 연승이면 마지막 출전일이다. 노랑 강조는 없앴다.
- 더블 찬스: 사용자 정의(Results A열 = 승, C열 = 패)와 실제 시트를 대조한 뒤 "어떤 게 정확한지 확인하고 진행" 지시에 따라 시트 공식 집계와 일치하는 규칙을 쓴다.
  - 승 = A열 승자인 더블 찬스 세트 수(상금 보정 탭 우선), 패 = 2 × 경기 수 − 승. 선수별 통계 탭과 31명 모두 일치.
  - C열 기준 패는 89건이 시트 집계와 달라 쓰지 않는다. 선수별 통계 탭과 다르면 송출 차단.
- 데이터 오류 알림: 관리자 메뉴의 알림 목록으로만 알린다(브라우저·메일 알림 없음, 사용자 선택). PC는 상단 [알림], 웹은 [알림]과 관리자 화면 "데이터 알림".

### v0.4.1 결정 (2026-10-01)
- 사용자 요청 "MOCK로 나오는 데이터는 다 지워줘": 배포본에서 MOCK JSON을 빼고(테스트 전용 `tests/fixtures/mock`), 데이터 소스는 Google 시트 하나로 한다.
- 기존 DB는 마이그레이션 4가 정리한다. 대상은 MOCK 선수·중계진 페이지와 수정값, MOCK 닉네임·캐시·목록이다. 선수와 무관한 페이지는 AUTO만 비우고, 송출 중이던 MOCK 화면은 내린다.
- 시트 주소·키가 없으면 자동 새로고침을 하지 않는다(xlsx만 쓰는 경우 실패 알림이 반복되지 않게).
- "시트 선수가 전부 나오지 않음"의 원인: MOCK 데이터(17명, 가짜 선수 포함)로 동작 중이었기 때문이다. 실제 시트의 Results 선수 31명은 Players·선수별 통계·섭외 리스트 탭과 같고, 가져오면 전원 나온다.

### v0.4.2 결정 (2026-10-01)
- 송출 화면이 일반 브라우저 창(1920×1080보다 작은 창·화면 배율 125%/150%)에서 오른쪽·아래가 잘리던 문제.
  - 1920×1080 캔버스를 창에 맞춰 비율대로 줄이거나 키운다(오른쪽 아래 기준). OBS 1920×1080 소스는 그대로다.
- 위치·크기는 [적용](PREVIEW)과 별도로 [송출에도 바로 적용] 버튼으로 송출 중인 화면에 바로 반영한다. 수치는 바꾸지 않는 명시적 동작이며 로그에 DISPLAY_LIVE로 남는다.
- 닉네임은 시트 '닉네임' 탭(선택)과 프로그램 입력 두 가지로 받는다. 프로그램 입력이 우선한다.
  - 템플릿에는 레퍼런스 04에 보이는 4명(박상현 soma, 이재호 Light, 장윤철 SnOw, 도재욱 Best)만 채웠다. 나머지는 추측하지 않고 비웠다.

### v0.4.3 결정 (2026-10-01)
- 운영을 카페24 가상서버의 웹 버전으로 바꾼다. 파일은 FileZilla로 올리고, 확인·추가 작업은 SSH로 서버에서 한다. GitHub·다른 PC로 배포하지 않는다.
- 이후 수정은 필요한 파일만 고쳐서 아래 세 가지로 전달한다(CLAUDE.md "운영·수정 방식").
  - 바뀐 파일 목록.
  - 그 파일만 담은 zip.
  - SSH 명령.
- 서버 점검은 `php app/cli.php check`로 한다(관리자 화면의 무결성 검사와 같은 대조). 범위: PHP·확장·설정·DB·DB 구조·폴더 권한·install.php·파일 무결성. 옵션으로 Google 접속과 웹 보호도 확인한다.
- 데이터·키·세션 폴더는 웹 폴더 밖(/var/lib/endgame-cg 권장)에 둔다. Nginx는 .htaccess가 동작하지 않으므로 app/ 차단 설정이 필수다.
- OS별 설치 명령은 사용자가 SSH 진단 결과를 보내 준 뒤 확정한다.

