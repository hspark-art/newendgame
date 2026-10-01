# 끝장전 CG

스타크래프트 「끝장전」 방송의 하단 데이터 CG를 만들고 OBS/vMix로 송출하는 프로그램입니다.
**PC 응용프로그램**과 **웹 버전** 두 가지로 제공하며, 두 버전은 같은 코드(`www/`)를 씁니다.

> 현재 버전은 v0.4.3입니다. **운영: 카페24 가상서버 웹 버전** — 설치·업데이트는 [web/SERVER_KR.md](web/SERVER_KR.md). CG **9종**을 송출하고, 끝장전 데이터는 **Google 시트**(서비스 계정 읽기 전용)에서 읽습니다. 처음 설치하면 데이터가 비어 있고(가짜 수치 없음), 시트를 연결하거나 xlsx 파일을 가져오면 시트의 선수 전원이 나옵니다. 연결 방법은 [web/GOOGLE_SHEET_KR.md](web/GOOGLE_SHEET_KR.md)를 보세요.
>
> 정확성 원칙: 시트가 스스로 계산한 집계와 프로그램 계산값을 대조합니다. 다르거나 대조할 수 없는 수치는 운영자가 확인해 직접 입력하기 전까지 송출하지 않습니다. 데이터 오류와 새로고침 실패는 관리자 [알림]에 모입니다.

## CG 9종
| # | CG | 고르는 값 | 집계 |
|---|---|---|---|
| 1 | 상대 종족 승률 | A·B 선수, 상대 종족 | 세트 |
| 2 | 최근 종족전 전적 | 선수, 상대 종족, 경기 수 | 경기 목록 |
| 3 | 끝장전 맞대결 | A·B 선수, 경기 수 | 요약은 끝장전 승수, 목록은 세트 스코어 |
| 4 | 다승 순위 | 종족(전체 가능), 인원 | 끝장전 승수 (레퍼런스 04와 일치) |
| 5 | 중계진 승자 예측 순위 | 연도, 자리 순서 | 끝장전 1회 = 예측 1건 |
| 6 | 온라인 상대 전적 | A·B 선수, 상대 종족 | 온라인 게임 (eloboard 확인 전까지 직접 입력) |
| 7 | 더블 찬스 승률 | A·B 선수 | 끝장전당 2회 시도, 승 = 더블 찬스 세트 승자(상금 보정 반영). 선수별 통계 탭과 대조 |
| 8 | 연승 순위 | 종족(기본 테란), 인원 | 끝장전 연승. 이어지는 연승의 종료일은 마지막 출전일 |
| 9 | 풀세트 접전 확률 | A·B 선수 | 9전 중 5:4·4:5 비율 (지난 기록) |

페이지 추가 창에서 종류를 고르면 필요한 입력칸만 나옵니다. 모든 값은 타이틀 에디터에서 수정할 수 있고, 목록형 CG는 행 이름을 비우면 그 행을 표시하지 않습니다.

## 두 가지 버전
| | PC 응용프로그램 | 웹 버전 |
|---|---|---|
| 누구에게 | 방송 PC 한 대에서 혼자 운영 | 여러 운영자가 인터넷으로 함께 운영 |
| 실행 | `시작.bat` 더블클릭 (포터블 PHP) | 웹호스팅에 업로드 → `install.php` |
| 계정 | 없음 (이 PC에서만 조작) | 초대 링크 → 가입 → 관리자 승인 |
| 송출 주소 | `http://127.0.0.1:3100/output.php?layer=1` | `https://도메인/output.php?t=비밀값&layer=1` |
| 데이터 | `%LOCALAPPDATA%\EndgameCG` (SQLite) | 호스팅 MySQL |
| 안내 | [desktop/README_KR.txt](desktop/README_KR.txt) | [web/INSTALL_KR.md](web/INSTALL_KR.md) |

## 운영 방식 (토네이도식)
- **페이지 리스트**: 이번 경기에 쓸 CG를 001, 002 … 페이지로 등록합니다.
- **숫자 + Enter**: PREVIEW(녹색)에 준비합니다.
- **F1 / Space = TAKE**: PROGRAM(빨강)으로 송출합니다. 효과는 CUT·FADE·SLIDE 중에서 고릅니다.
- **F2 OUT**, **F3 SHOW**, **F4 / ↓ NEXT**, **↑ PREV**
- **타이틀 에디터**: 필드별로 AUTO / MANUAL / FINAL / LIVE(송출값)를 보여 줍니다. Ctrl+S로 PREVIEW에 저장합니다.
- **UPDATE LIVE**: 송출 중인 같은 CG에만 수정값을 바로 반영합니다. 적용 전에 확인창이 뜹니다.
- **KEEP**: 새 방송 세션을 시작해도 이 수정값을 유지합니다. KEEP하지 않은 수정값은 AUTO로 돌아갑니다.
- **실패해도 유지**: 데이터 갱신이 실패하면 마지막 정상값을 쓰고 STALE로 표시합니다. 서버가 끊겨도 송출 화면은 마지막 화면을 유지합니다.

## 폴더
```
cg/
├─ www/        PC·웹 공용 코드 (웹은 이 폴더 내용을 업로드)
├─ desktop/    PC 실행기 (시작.bat, 종료.bat, PHP준비, router.php, php.ini)
├─ web/        웹 설치·패치 안내
├─ tools/      배포 zip·패치 zip 만들기
├─ tests/      자동 테스트 (php tests/run.php)
└─ docs/       설계·지침 문서
```

## 문서
| 문서 | 내용 |
|---|---|
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | 구조, PC/웹 차이, 데이터·송출 흐름, 보안 |
| [docs/DATA_MODEL.md](docs/DATA_MODEL.md) | 테이블, 값 규칙, AUTO/MANUAL/FINAL |
| [PROJECT_STATUS.md](PROJECT_STATUS.md) | PC/웹 반영 현황 |
| [docs/ROADMAP.md](docs/ROADMAP.md) | 단계별 진행 기록 |
| [tests/VALIDATION.md](tests/VALIDATION.md) | 검증 결과와 미검증 항목 |

## 개발
```bash
php tests/run.php                        # 자동 테스트 (임시 SQLite)
CG_TEST_MYSQL="127.0.0.1:3306:DB:아이디:비밀번호" php tests/run.php   # MySQL로 (테스트 전용 DB)
CG_CONFIG=$PWD/desktop/config.desktop.php php -S 127.0.0.1:3100 -t www desktop/router.php   # PC 모드 실행
```
