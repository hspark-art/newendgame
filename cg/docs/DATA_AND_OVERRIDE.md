# 자동 데이터·수동 수정·송출 상태 규칙

## 1. 데이터 흐름과 자동화
Google Sheets/외부 사이트 → Provider → Raw Cache → Normalize → Validate → Database → Stats Engine → AUTO DATA → 필드별 Override 병합 → FINAL DATA → PREVIEW → TAKE → PROGRAM.

- Google Sheets API를 사용한다. SheetMapping으로 playerColumn/opponentColumn/dateColumn/scoreColumn/raceColumn/winnerColumn 등을 설정한다. 실제 탭·컬럼을 하드코딩하지 않는다.
- Website URL/구조/인증이 없으면 실제 scraper를 만들지 않는다. fetch/normalize/validate 책임을 가진 최소 Provider 경계를 마련하고 실제 확인 뒤 WebsiteProvider를 추가한다.
- 수집·파싱·정규화·검증·저장은 구분한다. Source 원본과 수집 시각·URL을 보존하고 중복·Alias·날짜·Race·Score를 검증한다. 불확실한 선수 연결은 자동 병합하지 않는다.
- Source별 갱신 주기를 설정한다. Sheets 30초, Website 60초는 예시다. 외부 요청을 매 렌더링마다 실행하지 않는다.
- 실패는 패널과 로그에 드러낸다. 마지막 정상 데이터는 유지하되 STALE/ERROR와 마지막 성공 시각을 표시한다. 정상 데이터가 한 번도 없으면 0승/0패로 위장하지 않고 '데이터 없음'으로 구분한다.
- 자동 갱신은 AUTO와 준비 중인 PREVIEW에만 영향을 줄 수 있다. PROGRAM은 고정된 송출 스냅샷이다.

## 2. AUTO / MANUAL / FINAL
AUTO DATA는 자동 수집·계산 결과다. MANUAL OVERRIDE는 방송용 수정값이며 Google Sheets/사이트 원본, Raw Data, 자동 DB·계산 결과를 변경하지 않는다. FINAL DATA는 유효한 필드별 수정값과 자동값을 병합한 결과다.

직접 입력 필드의 개념식: `FINAL = MANUAL OVERRIDE ?? AUTO`.

| 필드 | AUTO | OVERRIDE | FINAL |
|---|---:|---:|---:|
| wins | 33 | 없음 | 33 |
| losses | 21 | 없음 | 21 |
| winRate | 61.1 | 60.8 | 60.8 |

0은 유효한 수동값이다. truthy/falsy로 Override 존재를 판단하지 않는다. Override 없음은 null 또는 명시적 해제 상태로 정의한다. 빈 입력, 결측값, 0은 구별하며 잘못된 입력을 자동값으로 조용히 대체하지 않는다.

Override는 방송 세션 + CG Instance + 필드에 연결한다. 선수/종족/대회/기간 등 CG parameters가 바뀌면 다른 대상의 수정값이 따라오지 않도록 구분한다.

## 3. 계산 필드의 재계산과 직접 입력
승률 AUTO CALCULATE 모드에서는 **최종 wins/losses**로 다시 계산한다. wins/losses를 수정하고 과거 AUTO winRate를 그대로 남기지 않는다.

1. 직접 입력 필드의 Override를 병합한다.
2. 최종 입력값에서 파생값을 Stats Engine으로 재계산한다.
3. 파생 필드가 MANUAL 모드이면 그 직접 입력값을 최우선 사용한다.

예: 33승 21패 → 61.1%; 34승 20패로 수정 → 63.0%; winRate MANUAL=62.8이면 표시 62.8%. 기본 표시는 소수 첫째 자리다. 0경기의 승률은 나누기 오류나 허위 0% 대신 미정/자료 없음으로 정의한다.

숫자는 유한값, 승패/경기수는 0 이상의 정수, 비율은 0~100 범위로 검증한다. 수동 승률이 계산값과 다르면 MANUAL과 차이를 보여 주며 덮어쓰지 않는다. 불일치 이유는 운영자가 판단한다.

## 4. QUICK EDIT와 송출 동작
PREVIEW 옆에 현재 CG의 선수명·Wins·Losses·Win Rate 등을 편집하는 영역을 둔다. AUTO/MANUAL 모드, 자동값/수정값/최종값과 검증 결과를 바로 확인한다.

| 동작 | 효과 |
|---|---|
| SAVE TO PREVIEW | 검증된 수정값을 저장하고 PREVIEW 즉시 갱신. PROGRAM 유지 |
| RESET TO AUTO | 선택 필드 또는 명시한 CG의 Override를 삭제하고 AUTO/재계산으로 복귀. 기본은 PREVIEW만 변경 |
| TAKE / SEND TO PROGRAM | 검수된 PREVIEW의 템플릿·parameters·최종 데이터·표시 설정을 PROGRAM 스냅샷으로 전송 |
| SHOW | 현재 PROGRAM을 표시. 준비 중인 PREVIEW를 자동 전송하지 않음 |
| HIDE | 현재 PROGRAM을 숨김. 데이터·Override를 삭제하지 않음 |
| UPDATE LIVE | 현재 PROGRAM의 해당 CG를 명시적으로 수정하고 WebSocket으로 즉시 반영 |

TAKE의 표시/애니메이션 동작은 UI에서 분명히 안내한다. RESET으로 송출값까지 바꾸려면 TAKE 또는 UPDATE LIVE가 필요하다.

UPDATE LIVE는 SAVE TO PREVIEW와 색상·위치·라벨을 명확히 구분한다. 편집 대상 Instance/선수와 현재 PROGRAM이 다르면 적용하지 않고 대상 불일치를 알린다. 다른 PREVIEW CG를 실수로 현재 PROGRAM에 덮어쓰지 않는다. 긴급 수정도 입력 검증·이력 기록을 거친다.

PREVIEW의 즉시 반영은 운영자의 준비 화면을 의미한다. 자동 갱신·일반 편집만으로 실제 송출값이 바뀌지 않는다.

## 5. 자동 갱신과 Override 보존
AUTO=61.1 → MANUAL=60.8 → 새 AUTO=62.0이어도 FINAL=60.8이다. 패널에는 AUTO 62.0 / MANUAL 60.8 / FINAL 60.8과 자동값 변경 사실을 표시한다. 실제 PROGRAM 값은 별도로 표시하며 'FINAL'과 'LIVE'를 혼동하지 않는다.

Override 없는 필드만 새 AUTO를 사용한다. 선택된 Source의 데이터가 갱신되어도 수동값을 삭제하지 않는다. RESET은 최신의 유효한 AUTO로 복귀한다. AUTO가 충돌/결측/오류 상태라면 이를 먼저 알리고 임의 선택하지 않는다.

## 6. DATA CONFLICT
동일한 선수·대회·기간·집계 단위의 Google Sheets=33승21패와 Website=35승21패가 다르면 CONFLICT다. 기간이 다른 기록을 같은 통계로 비교하지 않는다. 중복 경기를 두 번 합산하지 않는다.

시스템은 임의로 한쪽을 우선하지 않는다. Source별 값·범위·수집 시각·근거를 보여 주고 다음 선택을 제공한다.

- USE GOOGLE SHEET: 해당 대상/필드에 Sheets 근거를 선택.
- USE WEBSITE: 해당 대상/필드에 Website 근거를 선택.
- MANUAL EDIT: 원본을 보존한 채 Override로 최종값을 결정.

Source 선택과 수동 수치 입력은 구분해 기록한다. 해결은 대상/범위에 적용하고 선택 소스·시각을 남긴다. 새로운 불일치는 운영자에게 다시 알린다. 향후 Source 우선순위 정책을 추가할 수 있게 책임을 분리하되 현재 자동 우선순위 기능을 임의로 만들지 않는다.

방송 사고 방지를 위한 구현 기준: 송출할 필드의 미해결 충돌이나 유효값 부재는 TAKE/UPDATE LIVE 전에 해결하도록 안내한다. 기존 PROGRAM은 유지한다. 오래된 정상값(STALE)은 시각·경고를 보여 주고 운영자가 확인한 경우 사용하도록 한다. 수동 해결된 값은 원본 CONFLICT 근거를 숨기지 않는다.

## 7. 상태와 DATA CHECK
| 상태 | 의미 |
|---|---|
| AUTO | 유효한 자동값 |
| MANUAL | 운영자 수정값 적용 |
| CONFLICT | 비교 가능한 Source 사이 불일치 |
| STALE | 마지막 정상 데이터가 오래되었거나 갱신 실패 |
| ERROR | 수집·파싱·검증·계산 오류 |

값의 출처(AUTO/MANUAL)와 Source의 상태(CONFLICT/STALE/ERROR)는 동시에 보여 줄 수 있어야 한다. MANUAL이면 자동 소스 오류를 숨기는 단일 배지로 대체하지 않는다.

DATA CHECK/PRE-FLIGHT CHECK는 현재 경기의 CG별 AUTO OK/MANUAL/CONFLICT/STALE/ERROR, Source와 갱신 시각, 확인 필요 항목을 한 화면에 보여 준다. 운영자가 이 화면에서 검토·QUICK EDIT·충돌 해결로 이동할 수 있어야 한다.

## 8. 방송 세션과 이력
Override는 기본적으로 현재 방송 세션에서만 유지한다. 앱 재시작과 새 방송 세션 시작을 구분해 실수로 수정값을 지우지 않는다. 새 세션에서는 AUTO로 복귀하고 명시적 KEEP OVERRIDE 항목만 다음 세션으로 유지한다. 선수·CG·기간이 바뀌면 유지 범위를 확인한다.

단일 운영자 기준으로 누가(운영자 식별자), 언제(timestamp), session/CG Instance/template, field, 당시 auto value, previous manual value, new manual value를 기록한다. RESET, Source 선택, TAKE, UPDATE LIVE도 추적한다. 미래 계정을 위해 현재 로그인 시스템을 만들 필요는 없다.

## 9. 필수 검증 시나리오
1. 승패 수정 → 승률 재계산; 직접 승률 Override → 직접값 우선.
2. 수동 0값 적용, 빈값/잘못된 값 거부, 0경기 처리.
3. 자동 갱신 → Override 보존·변경 알림·PROGRAM 유지.
4. RESET → 최신 AUTO로 PREVIEW 복귀; PROGRAM 유지.
5. PREVIEW 선수·템플릿·데이터 변경 → PROGRAM 불변; TAKE → 검수한 스냅샷 반영.
6. UPDATE LIVE → 현재 PROGRAM 대상만 즉시 변경; 대상 불일치 차단.
7. Source 충돌 → 임의 선택 없음; 3가지 해결 경로와 이력; 미해결 송출 방지.
8. Source 실패/WebSocket 재연결 → 마지막 정상 데이터·송출 상태 유지, 오류 표기.
9. 새 세션 → 기본 AUTO; KEEP OVERRIDE 선택 항목만 유지; 재시작 복원.
10. 이름/종족/대회/기간 변경 → 다른 대상의 Override가 섞이지 않음.
