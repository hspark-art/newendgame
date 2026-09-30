# 프로젝트 전체 요구사항

## 1. 목적과 최종 운영 흐름
스타크래프트 「끝장전」 방송의 하단 데이터 CG를 자동 생성·관리한다. 운영자가 선수 A/B를 선택하면 Google Sheets와 외부 사이트 기록을 조회하고 통계를 계산해 사용 가능한 CG 후보를 제공한다.

선수 선택 → 자동 수집·계산 → DATA CHECK → 필요 시 QUICK EDIT → PREVIEW 확인 → TAKE → PROGRAM → OBS/vMix Browser Source.

9개 유형은 상대 종족 승률, 최근 특정 종족전, 끝장전 맞대결, 다승 순위, 중계진 승자 예측 순위, 온라인 상대 전적, 더블 찬스 승률, 연승 순위, 풀세트 접전 확률이다. 구체적 필드·레퍼런스 기준은 [CG_REFERENCE_GUIDE](CG_REFERENCE_GUIDE.md)에 둔다. 데이터·송출 상태의 세부 규칙은 [DATA_AND_OVERRIDE](DATA_AND_OVERRIDE.md)가 기준이다.

## 2. 범위와 구현 원칙
- Windows 우선, 단일 운영자 기준. 프로그램 시작과 패널 열기는 .bat 더블클릭 또는 앱 버튼으로 제공한다. 사용자에게 매번 명령줄 입력을 요구하지 않는다.
- Electron Control Panel과 웹 CG Renderer를 분리한다. 실제 CG는 투명한 HTML/CSS/JavaScript 웹 페이지이며 React 템플릿으로 재사용한다.
- 첫 MVP는 MOCK JSON과 CG 1종으로 전체 방송 조작 흐름을 검증한다. Google Sheets 및 외부 사이트는 나중에 연결한다.
- 단계별 구현·검증 후 다음 단계로 진행한다. 미확정 데이터나 실제 전적을 만들어내지 않는다.
- 향후 웹 이전이 가능하도록 의존성을 분리하되 클라우드 배포, PostgreSQL 전환, 다중 사용자 계정은 지금 구현하지 않는다. KEEP OVERRIDE 등 명시된 운영 요구는 생략하지 않는다.

## 3. 권장 기술 구조
원 프롬프트의 권장안: Electron / React / TypeScript / Vite / Node.js / Express 또는 Fastify / SQLite / WebSocket. 기존 프로젝트가 있으면 먼저 확인하고 변경 이유를 설명한다. Express와 Fastify는 하나만 선택한다. 버전·패키지 선택은 구현 계획에서 검토한다.

```text
Google Sheets / Website
        ↓
Data Provider → Raw Cache → Normalizer → Validation → SQLite
                                                       ↓
                                                  Stats Engine
                                                       ↓
                                                   AUTO DATA
                                                       + MANUAL OVERRIDE
                                                       ↓
                                                   FINAL DATA
                                                       ↓
                                                PREVIEW / PROGRAM
                                                       ↓
                                                API / WebSocket
                                              ↙                ↘
                               Control Panel (Electron)    CG Renderer (Web)
                                                               ↓
                                                            OBS / vMix
```

Database·Provider·Stats Engine·API·WebSocket·CG Renderer는 Electron에 의존하지 않게 한다. Electron은 조작 화면과 로컬 실행 관리를 맡는다. CG 내부에서 통계나 외부 조회를 실행하지 않는다.

예: `http://localhost:3100/cg/race-win-rate`, `/cg/head-to-head`, `/cg/win-ranking`. 호스트를 고정한 코드를 곳곳에 두지 않고, 향후 온라인화 때는 인증·통신·운영도 별도로 검토한다.

> **확정된 구현 결정 (2026-09-30)**: 최종 목표가 PHP 웹이므로 Electron/React/Node/WebSocket 대신 **PHP 8.1+ 코어 하나로 PC 응용프로그램(포터블 PHP + SQLite)과 웹 버전(일반 웹호스팅 + MySQL)** 을 만든다. 실시간 반영은 WebSocket 대신 짧은 폴링이다. 이유와 구조는 [ARCHITECTURE](ARCHITECTURE.md)를 따른다. 웹 버전의 팀 계정(초대·승인)은 사용자가 요청해 범위에 포함했다.

## 4. データモデル
以下は最小検討項目。実装時に型・制約・関係を定義し、未使用項目のために空の仕組みを量産しない。

| Entity | 主な項目 |
|---|---|
| Player | id, name, nickname, race, aliases, active, metadata |
| Match | id, date, competition, season, playerA/B, playerARace/BRace, scoreA/B, winner, loser, bestOf, matchType, source, sourceUrl, metadata |
| Game | id, matchId, gameNumber, map, playerA/B, winner, loser, playerARace/BRace, duration, metadata |
| Competition | id, name, season, year, type |
| DataSource | id, name, type, lastUpdated, status, metadata |
| CGTemplate | id, name, slug, category, renderer, config |
| CGInstance | id, templateId, parameters, autoData, manualOverride, finalData, createdAt, updatedAt |

매치 단위(Match)와 개별 게임(Game)을 구분한다. 설명과 UI는 한국어로 작성한다.

DB에는 `"33승 21패 (61.1%)"` 같은 완성 문자열 대신 wins/losses 등의 구조화된 수치를 저장한다. 표시 문자열과 반올림은 Renderer 또는 명시된 표시 계층에서 처리한다. 승률·집계의 계산 책임은 Stats Engine에 둔다.

선수 Alias는 확정된 매핑만 사용한다. 원문 속 이름·닉네임 예시는 실제 대응 관계가 검증된 DB가 아니다. 확신이 없는 자동 매칭은 병합하지 않고 운영자 확인 상태로 둔다.

## 5. 통계 엔진
독립 함수로 상대 종족 승률, 최근전적, 맞대결, 다승, 예측 정확도, 온라인전적, 더블 찬스, 연승, 풀세트 비율을 계산한다. 예: `getRaceWinRate(playerId, opponentRace)`, `getRecentMatches(playerId, opponentRace, limit)`, `getHeadToHead(playerA, playerB)`.

기간·대회·시즌·온라인/오프라인·경기/게임 단위·동률 순위·연승 중단 기준·더블 찬스 정의는 실제 기록과 방송 규칙을 확인한다. 풀세트 CG의 '확률'은 우선 과거 풀세트 비율을 표시하는 명칭이며 예측 모델을 임의로 만들지 않는다. 집계 정의가 없으면 NEEDS CONFIRMATION으로 두고 MOCK만 사용한다.

## 6. Control Panel
현재 경기의 Player A/B, CG 후보/템플릿 목록, 데이터 표, PREVIEW, 현재 PROGRAM, QUICK EDIT, DATA CHECK, Data Refresh, AUTO REFRESH, Source별 상태·갱신 시각, AUTO/MANUAL/CONFLICT/STALE/ERROR 표시를 제공한다.

동작 버튼: SAVE TO PREVIEW, TAKE(SEND TO PROGRAM), SHOW, HIDE, UPDATE LIVE, RESET TO AUTO. 일반 저장과 긴급 송출 수정은 시각적으로 구분한다. 운영자가 수동 수정 적용 여부와 현재 송출 내용을 즉시 파악할 수 있어야 한다.

## 7. 화면·방송 출력
- 기준 Canvas 1920×1080, 투명 배경, 기본 우측 하단 CG. X/Y/Width/Height/Scale을 패널 또는 설정에서 조정한다.
- Browser Source 새로고침 없이 SHOW/HIDE/TAKE/UPDATE LIVE를 WebSocket으로 반영한다.
- SHOW는 Fade/Slide In, HIDE는 Fade/Slide Out 정도로 시작한다. 데이터 전환은 간단한 Transition을 선택적으로 적용한다.
- 테마/CSS Variables로 배경·타이틀·행·텍스트·강조색·테두리·폰트를 관리한다. 라이선스 미확인 유료 폰트를 포함하지 않는다.
- 실패·재연결 때 기존 PROGRAM이 비거나 임의로 바뀌지 않도록 마지막 정상 상태를 유지한다.

## 8. 수집·검증·보안·로그
Google Sheets API + 컬럼 Mapping, 외부 Website Provider, Raw Cache, 정규화·중복 검증·별칭 매칭·Stats Engine을 분리한다. 자세한 동작은 DATA_AND_OVERRIDE를 따른다.

Validation: 승+패/총 경기수 불일치, 승률 오류, 알 수 없는 선수, 중복 경기, 잘못된 날짜/Race/Score, 선수 A/B 중복, Source 불일치. 경고를 패널에 표시하고 비정상 데이터를 조용히 송출하지 않는다.

Google Credential/API Key/Password/Cookie/Login Token은 .env 또는 안전한 로컬 비밀 설정으로 관리하고 .gitignore를 작성한다. 비밀값을 Git·로그·클라이언트 번들에 넣지 않는다. 외부 입력과 수동 값은 검증하고 화면에는 안전하게 렌더링한다.

data/error/broadcast/override 로그: 수집 시각·결과·오류, PREVIEW/TAKE/SHOW/HIDE/UPDATE LIVE, 수정·초기화 이력을 남긴다. 수정 로그 필드는 DATA_AND_OVERRIDE에 정의한다.

## 9. 권장 파일 구조와 설정
필요한 단계에서만 폴더와 파일을 만든다.

```text
src/
  desktop/ server/ ui/
  cg/templates/ cg/components/ cg/styles/
  providers/ stats/ database/ models/ services/ utils/
data/ logs/ config/ docs/
```

설정 예: serverPort=3100, resolution=1920×1080, defaultCGPosition=right-bottom, 좌표/크기/스케일, Source별 refreshInterval, SheetMapping. 30초(Sheets)/60초(Website)는 예시이며 실제 제한과 운영 환경을 확인한다.

개발 명령은 npm install, npm run dev, npm run desktop, npm run server를 제공한다. 가능하면 npm run dev 한 번으로 필요한 프로세스를 시작한다. 운영자를 위한 더블클릭 시작 수단과 실패 시 읽을 수 있는 안내도 제공한다.

## 10. 구현 중 유지할 문서와 검증
README(설치·더블클릭 시작·OBS/vMix 연결·조작), ARCHITECTURE(데이터 흐름), DATA_MODEL, CG_TEMPLATE_GUIDE, DATA_PROVIDER_GUIDE를 해당 구현 단계에서 작성·갱신한다. 단계 기록은 기존 [ROADMAP](ROADMAP.md)을 사용한다. Manual Override와 방송 흐름은 DATA_AND_OVERRIDE를 활용하고 동일 내용 문서를 중복 작성하지 않는다.

통계와 Override/Reset/Conflict/Preview-Program 분리를 Fixture 기반 자동 테스트로 검증한다. 33/54×100=61.111… → 표시 61.1% 등 계산과 출력 반올림을 확인한다. 화면은 실제 브라우저에서 확인하고 OBS/vMix 실송출 여부는 별도로 명시한다. 미실시 검증을 통과로 보고하지 않는다.

## 11. 시작 전 확인 사항
실제 Sheet URL/권한/탭/컬럼, 외부 사이트 URL/HTML/API/로그인 여부, 실제 Match DB, 선수 Alias, 방송 폰트, 인증 방식, 각 통계 집계 규칙은 미확정이다. MOCK MVP를 막지 않는 질문은 해당 연결 단계까지 미룬다.

첫 응답은 기존 프로젝트와 스크린샷을 확인한 뒤 요구사항 해석, 추천 아키텍처·기술 문제, 디렉터리 구조, 첫 단계 파일, 지금 필요한 확인사항을 짧게 제시하고 구현 승인을 기다린다. 전체 프로그램을 한 번에 생성하지 않는다.
