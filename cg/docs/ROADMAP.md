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
| 현재 단계 | PHASE 7 완료 — v0.2.0 (CG 9종, MOCK). PC zip·웹 zip·패치 zip(0.1.1→0.2.0) |
| 완료 단계 | 0~6 첫 MVP, W 웹 계정·관리자·비밀 송출 주소, R 배포·패치·무결성 검사, 7 나머지 CG 8종(최근 종족전·맞대결·다승·승자 예측·온라인·더블 찬스·연승·풀세트) |
| 변경 파일 | PHASE 7: www/app/templates/*.{def,view}.php(9종), www/app/{templates,stats,provider,override,control,views}.php, www/app/data/mock/{players,matches,online,predictions,double_chance}.json, www/assets/{cg.css,panel.js,panel.css,output.js}, www/index.php, tests/cg_types_test.php |
| 검증 결과 / 미검증 | 자동 테스트 61건 통과(SQLite·MySQL, v0.2.0). 브라우저(Chromium) 31항목 통과: 기존 18항목 + 9종 대화상자 추가→큐→TAKE→송출 문구·글자 넘침 없음·캡처, 에디터 행 구분, 예측 자리 순서 복원. **미검증**: Windows 실행기, OBS/vMix 실송출, 실제 웹호스팅·HTTPS, 맑은 고딕 글자 폭. 상세는 tests/VALIDATION.md |
| 미확정·장애 | 더블 찬스 정의, 온라인 기록 출처·제목, 예측 순위 표시 순서, 연승 중단 기준·기간, 9전 외 풀세트 처리, 방송 폰트, 웹 도메인·호스팅. 집계 단위는 세트 기준으로 확정(2026-09-30) |
| 다음 작업 | NEEDS CONFIRMATION 항목 확정 → Windows·OBS/vMix·실제 호스팅 실환경 확인 → PHASE 8 Google Sheets(시트 주소·컬럼 확정 필요) |

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
