# 데이터 모델

PC(SQLite)와 웹(MySQL)이 같은 테이블을 쓴다. 테이블 정의는 `www/app/migrate.php`가 기준이다. 이 문서는 의미와 규칙을 설명한다.

## 1. 값 규칙
| 항목 | 규칙 |
|---|---|
| 시각 | `YYYY-MM-DD HH:MM:SS` 문자열(한국 시간). 두 DB에서 같게 비교·정렬된다 |
| 승·패·경기수 | 0 이상의 정수. 0은 유효한 값이다 |
| 승률 | **0.1% 단위 정수**(61.1% → 611). 0경기면 `null`("자료 없음"). 계산은 `intdiv(2000×승 + 경기수, 2×경기수)`(반올림) |
| 수동 입력 승률 | `62.8`처럼 소수 첫째 자리까지만, 0.0~100.0. `62.85`는 반올림하지 않고 거부한다 |
| 표시 문자열 | DB에 저장하지 않는다. `templates.php`의 Presenter가 만든다("33승 21패", "(61.1%)") |
| JSON 칸 | `*_json` 칸은 JSON 문자열이다. `null`과 `0`, 빈 문자열을 구분한다 |
| 종족 | `P`(프로토스) `T`(테란) `Z`(저그) |

## 2. 원천 데이터 (Provider 형식)
소스는 두 가지다(`cg_settings.data_source`). 둘 다 같은 모양의 데이터(players, games, matches, predictions …)로 만든다.
- **Google 시트** (v0.3.0, `sheet_data.php`·`sheets.php`)
  - **Results 탭**: Winner, Race, Loser, Race, Map, Date. **1행 = 1세트**다. 상금·더블 찬스 열은 읽지 않는다.
  - **끝장전** = 같은 날짜·같은 두 선수의 세트 묶음이다. 9세트를 모두 치르는 방식이다(예: 7:2, 6:3, 5:4).
    - A는 그 경기 첫 세트의 승자다. `bestOf`는 9세트일 때만 9다.
    - **이상 경기**(9세트가 아님, 동점, 경기 중 종족 변경)는 끝장전 통계에서 빼고 점검 화면에 표시한다. 세트 통계에는 센다.
  - **선수 id** = 시트 이름(공백 정리·NFC 정규화). 주 종족은 Players 탭의 Race를 쓰고, 없으면 가장 많이 쓴 종족이다. 동률이면 정하지 않는다.
  - **예측 탭**: 세트마다 1건이다. "박상현 캐스터"에서 알려진 직책만 떼어 이름으로 쓴다. 성공/실패가 비어 있으면 결과 입력 전으로 보고 건너뛴다.
  - **형식 오류** 행(이름·종족 P/T/Z·날짜)이 하나라도 있으면 새로고침 전체를 실패로 처리하고 마지막 정상 데이터를 유지한다. 날짜는 연도가 앞인 형식만 받고, 다른 형식은 추측하지 않는다.
  - **교차 검증**: 시트가 스스로 계산한 집계와 프로그램 계산값을 비교한다.
    - Players 탭: 세트 전적(전체, vs Z/T/P).
    - 상대전적조회NEW 탭: 선수별 끝장전 목록.
    - 예측 순위표: 전체·적중 수.
    - 다르거나 탭이 없어 대조할 수 없으면, 그 수치를 쓰는 CG 필드(템플릿의 `verify`)에 사유를 기록한다. 해당 필드에 운영자가 값을 직접 입력하기 전까지 송출을 막는다(`template_problems`).
- **MOCK JSON** (`www/app/data/mock/`): 검증용 가짜 수치다. 끝장전 스코어로 세트 목록을 만든다. 검증 대상이 아니다.

아래는 MOCK JSON 형식이다(시트 연결 전 시연용).

**Player**: `{id, name, nickname, race, aliases[], active}`
- `id`는 영문 소문자·숫자·하이픈이다.
- `aliases`는 확정된 매핑만 넣는다.

**Match** (끝장전 1회): `{id, date, competition, season, playerA, playerB, raceA, raceB, scoreA, scoreB, bestOf, matchType, source}`
- `winner`는 저장하지 않고 스코어로 계산한다. 원본에 winner가 있으면 스코어와 일치하는지 확인한다.
- 스코어는 세트(게임) 수다. 9전 5선승이면 승자 5, 패자 0~4.

**Game** (세트 1개): 아직 쓰지 않는다. 세트 순서·맵이 필요한 기능(세트 연승 등)이 확정되면 추가한다.

**선택 파일** (없으면 빈 목록으로 보고 해당 CG만 값이 비어 송출할 수 없다)
- `online.json` → **OnlineGame** (온라인 게임 1판): `{id, date, playerA, playerB, raceA, raceB, winner, source}`. 끝장전 기록과 섞지 않는다. 출처·기간은 NEEDS CONFIRMATION.
- `predictions.json` → **Predictor** `{id, name}`, **Pick** `{predictor, match, pick}` (끝장전 1회에 1명당 예측 1건, `pick`은 예측한 승자 선수 id).
- `double_chance.json` → **DoubleChance** `{player, wins, losses}` 집계표. "더블 찬스"의 정의가 NEEDS CONFIRMATION이라 경기 기록에서 계산하지 않고 집계값을 그대로 쓴다.

**검증 항목**(`dataset_validate`) — 문제가 있으면 목록으로 돌려주고, 하나라도 있으면 데이터 갱신을 실패로 처리한다.
- 알 수 없는 선수, A와 B가 같은 선수.
- 종족이 P/T/Z가 아님. 경기 기록의 종족을 기준으로 집계하며, 선수의 주 종족과 달라도 거부하지 않는다(랜덤·종족 변경 가능).
- 스코어가 음수이거나 범위를 벗어남, 승자가 정해지지 않음.
- 중복 id, 잘못된 날짜.
- 선택 파일: 온라인 게임의 승자가 두 선수 중 하나가 아님, 없는 예측자·경기·선수, 한 경기에 같은 사람이 두 번 예측, 더블 찬스 음수·중복.

**집계 단위 (2026-09-30 사용자 결정: 세트 기준)**
| CG | 단위 | 비고 |
|---|---|---|
| 상대 종족 승률, 다승 순위 | 세트 (각 끝장전 세트 스코어 합산) | 사용자 결정 |
| 맞대결 요약 "0 : 4", 연승, 풀세트 비율, 승자 예측 | 끝장전(경기) | 원래 경기 단위. 세트 순서 기록이 없어 세트 연승은 계산할 수 없다 |
| 온라인 상대 전적 | 온라인 게임 1판 | MOCK |
| 더블 찬스 | 집계표 값 | 정의 NEEDS CONFIRMATION |

`stats_race_record()`는 세트 합계와 끝장전 승·패를 함께 돌려주므로 단위가 바뀌면 템플릿에서 고르기만 하면 된다.

## 3. 방송 상태 테이블
| 테이블 | 의미 |
|---|---|
| `cg_settings` | 키-값. `schema_version`, `state_rev`(변경 번호), `current_session_id`, `csrf_secret`, `output_token`, 출력 하트비트, `data_source`·`sheet_id`·`sheet_tabs`(관리자 설정), `data_check`(점검 요약) |
| `cg_sessions` | 방송 세션. 수동 수정값의 유효 범위 |
| `cg_instances` | CG 인스턴스 = 템플릿 + 파라미터(선수·상대 종족 등). `params_key`(정규화한 파라미터의 sha1)로 같은 대상을 하나로 모은다. `auto_json`은 마지막 정상 AUTO 값이고, 한 번도 없으면 NULL |
| `cg_overrides` | 수동 수정값. **(세션, 인스턴스, 필드)당 1행.** 행이 있으면 수정값이 있는 것이다. `auto_at_set_json`은 수정 당시 AUTO 값(자동값 변경 알림용), `keep_next`는 KEEP OVERRIDE 표시 |
| `cg_rundown` | 페이지 리스트. 페이지 번호(`page_no`, 중복 불가) → 인스턴스. 방송 세션과 무관하게 유지된다 |
| `cg_channels` | 레이어별 `preview`/`program` 2행(MVP는 레이어 1). 아래 설명 참고 |
| `cg_sources` | 데이터 소스 상태: NEVER/OK/ERROR, 마지막 시도·성공 시각, 마지막 오류 |
| `cg_logs` | data/error/broadcast/override/auth 기록 |
| `cg_dataset_cache` | 소스별 마지막 정상 데이터(gzip+base64 JSON, sha256 확인, MySQL MEDIUMTEXT). 페이지 추가·닉네임 변경 때 네트워크 없이 AUTO를 계산한다 |
| `cg_player_info` | 운영자가 입력한 선수 닉네임. 시트에 없는 값이라 추측하지 않고 입력한 것만 쓴다 |
| `cg_instances.issues_json` | 교차 검증 사유 `[{fields, msg}]`. 새로고침마다 다시 계산한다 |

**cg_channels**
- `preview`
  - `rundown_id`·`instance_id`: 지금 큐된 페이지.
  - `display_json`: 위치 `{right, bottom, scale_pct}`.
- `program`
  - `snapshot_json`: TAKE 순간에 고정한 송출본. 모양은 아래와 같다.
    `{take_id, session_id, rundown_id, page_no, instance_id, template, params, final, modes, view, display, effect, dur_ms, mock, taken_at}`
  - `visible`: 표시 여부(SHOW/OUT).
  - `take_id`: TAKE할 때마다 1 증가.
- `rev`: 그 채널이 마지막으로 바뀐 `state_rev`. 폴링 비교용.

## 4. AUTO / MANUAL / FINAL
- 입력 필드의 FINAL = 수동값이 있으면 수동값, 없으면 AUTO.
- 파생 필드(승률)
  - 수동값이 있으면 수동값이다("직접 입력").
  - 없으면 FINAL 승·패로 다시 계산한다("자동 계산").
  - 직접 입력값이 계산값과 다르면 패널에 차이를 표시하고, 덮어쓰지 않는다.
- 수동값은 원본·AUTO를 바꾸지 않는다. 자동 갱신이 수동값을 지우지 않는다.
- RESET은 해당 필드(또는 인스턴스 전체)의 수동값 행을 지운다. PREVIEW만 바뀐다.
- 새 세션을 시작하면 이전 세션의 `keep_next=1` 행만 새 세션으로 복사된다.
- 파라미터(선수·종족)가 바뀌면 다른 인스턴스가 되므로 수동값이 따라가지 않는다.

## 5. CG 템플릿 필드
템플릿마다 `www/app/templates/<slug>.def.php`(파라미터·필드·AUTO·표시 문자열)와 `<slug>.view.php`(HTML)가 있다. 필드 종류는 글자(`text`), 정수(`int`), 날짜(`date`, 2026-05-06 형식), 비율(`rate`, 파생)이다.
- 파생 비율은 두 가지다. 승률 `[승, 패]` → 승/(승+패), 비율 `share` → 부분 합/전체(풀세트). 분모가 0이면 "자료 없음"으로 송출할 수 있고, 값이 맞지 않으면(부분 합 > 전체) 송출을 막는다.
- 목록형 CG(최근 전적·맞대결·순위)는 1~5행 필드(`r1.name` …)를 두고 모두 비울 수 있다. 이름(또는 날짜)이 빈 행은 표시하지 않고, 표시할 행이 하나도 없으면 송출을 막는다. 패널 에디터·확인창·로그에는 "2행 승"처럼 행 번호가 붙는다.

| # | slug | 파라미터 | 주요 필드 (AUTO) |
|---|---|---|---|
| 1 | `race-win-rate` | a/b 선수·상대 종족 | 제목, 이름, 상대 종족 세트 승·패, 승률 |
| 2 | `recent-race` | 선수, 상대 종족, 경기 수(1~5, 기본 5) | 제목 "{선수} 최근 끝장전 {종족}전 전적", 행: 날짜·선수·세트·세트·상대 (오래된 순) |
| 3 | `head-to-head` | a/b 선수, 경기 수(1~5, 기본 4) | 제목 "… {첫·두·…} 번째 맞대결"(지난 대결 수+1), A/B 끝장전 승수, 행: 날짜·A 세트·B 세트 |
| 4 | `win-ranking` | 종족(전체/P/T/Z), 인원(1~5, 기본 4) | 행: 순위·이름·닉네임·세트 승·패·승률. 같은 승수는 공동 순위 |
| 5 | `prediction-ranking` | 연도(기본 최근), 자리 순서(최대 5명, 비우면 순위순) | 제목 "{연도} 중계진 승자 예측 순위", 행: 순위·이름·적중·실패·적중률 |
| 6 | `online-h2h` | a/b 선수·상대 종족 | 온라인 상대 종족 승·패·승률, 온라인 맞대결 A 승·B 승 |
| 7 | `double-chance` | a/b 선수 | 제목 "{A} vs {B} 더블 찬스 승률", 집계표 승·패·승률 (없으면 빈 값) |
| 8 | `win-streak` | 종족(기본 테란), 인원 | 행: 순위·이름·닉네임·연승·시작일·종료일("진행 중") |
| 9 | `full-set` | a/b 선수 | 9전 경기 수, 5:4 승리, 4:5 패배, 풀세트 비율(파생 share) |

### 5-1. 상대 종족 승률 CG (`race-win-rate`) 필드
| 필드 | 종류 | AUTO |
|---|---|---|
| `title` | 글자(최대 40자) | "중계진 스타 끝장전 상대 종족 승률" |
| `a.name` / `b.name` | 글자(최대 12자) | 선수 이름 |
| `a.wins` / `b.wins` | 정수 0~99999 | 상대 종족 세트 승 |
| `a.losses` / `b.losses` | 정수 0~99999 | 상대 종족 세트 패 |
| `a.rate` / `b.rate` | 승률(파생) | 승·패에서 계산 |

파라미터: `{a:{player, vs}, b:{player, vs}}`. 헤더는 "이름 vs 종족"으로 표시한다(예: "조일장 vs P").

## 6. 웹 계정 테이블 (웹 모드에서만 사용)
| 테이블 | 의미 |
|---|---|
| `cg_users` | 아이디(소문자), 비밀번호 해시, 이름, 역할(admin/operator), 상태(pending/active/suspended/rejected), `session_gen`(올리면 기존 로그인 무효) |
| `cg_links` | 초대·재설정 링크. 토큰 원문은 저장하지 않고 SHA-256만 저장한다. 만료·사용·취소 시각 |
| `cg_attempts` | 로그인 시도 제한 카운터(5분 창) |
