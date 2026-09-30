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
현재는 MOCK JSON(`www/app/data/mock/`)이다. MVP에서는 DB에 적재하지 않고, 불러올 때마다 검증한 결과를 메모리에서 쓴다. 실제 소스를 연결하는 PHASE 8부터 Match/Game 테이블과 원본 캐시를 추가한다.

**Player**: `{id, name, nickname, race, aliases[], active}`
- `id`는 영문 소문자·숫자·하이픈이다.
- `aliases`는 확정된 매핑만 넣는다.

**Match** (끝장전 1회): `{id, date, competition, season, playerA, playerB, raceA, raceB, scoreA, scoreB, bestOf, matchType, source}`
- `winner`는 저장하지 않고 스코어로 계산한다. 원본에 winner가 있으면 스코어와 일치하는지 확인한다.
- 스코어는 세트(게임) 수다. 9전 5선승이면 승자 5, 패자 0~4.

**Game** (세트 1개): 이번 MVP에서는 쓰지 않는다. 맵·세트별 종족이 필요한 CG(PHASE 7 이후)에서 추가한다.

**검증 항목**(`dataset_validate`) — 문제가 있으면 목록으로 돌려주고, 하나라도 있으면 데이터 갱신을 실패로 처리한다.
- 알 수 없는 선수, A와 B가 같은 선수.
- 종족이 P/T/Z가 아님. 경기 기록의 종족을 기준으로 집계하며, 선수의 주 종족과 달라도 거부하지 않는다(랜덤·종족 변경 가능).
- 스코어가 음수이거나 범위를 벗어남, 승자가 정해지지 않음.
- 중복 id, 잘못된 날짜.

> **NEEDS CONFIRMATION — 집계 단위**: "33승 21패"가 세트 기준인지 끝장전(매치) 기준인지 확정되지 않았다. MOCK과 현재 통계는 **세트 기준**(각 끝장전의 세트 스코어 합산)이다. `stats_race_record()`는 세트 합계와 끝장전 승·패를 함께 돌려주므로, 확정되면 템플릿에서 고르기만 하면 된다.

## 3. 방송 상태 테이블
| 테이블 | 의미 |
|---|---|
| `cg_settings` | 키-값. `schema_version`, `state_rev`(변경 번호), `current_session_id`, `csrf_secret`, `output_token`, 출력 하트비트 |
| `cg_sessions` | 방송 세션. 수동 수정값의 유효 범위 |
| `cg_instances` | CG 인스턴스 = 템플릿 + 파라미터(선수·상대 종족 등). `params_key`(정규화한 파라미터의 sha1)로 같은 대상을 하나로 모은다. `auto_json`은 마지막 정상 AUTO 값이고, 한 번도 없으면 NULL |
| `cg_overrides` | 수동 수정값. **(세션, 인스턴스, 필드)당 1행.** 행이 있으면 수정값이 있는 것이다. `auto_at_set_json`은 수정 당시 AUTO 값(자동값 변경 알림용), `keep_next`는 KEEP OVERRIDE 표시 |
| `cg_rundown` | 페이지 리스트. 페이지 번호(`page_no`, 중복 불가) → 인스턴스. 방송 세션과 무관하게 유지된다 |
| `cg_channels` | 레이어별 `preview`/`program` 2행(MVP는 레이어 1). 아래 설명 참고 |
| `cg_sources` | 데이터 소스 상태: NEVER/OK/ERROR, 마지막 시도·성공 시각, 마지막 오류 |
| `cg_logs` | data/error/broadcast/override/auth 기록 |

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

## 5. 상대 종족 승률 CG (`race-win-rate`) 필드
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
