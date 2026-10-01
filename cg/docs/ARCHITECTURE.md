# 아키텍처

끝장전 CG는 **PHP 코드 하나로 PC 응용프로그램과 웹 버전을 함께** 제공한다. 두 버전은 같은 `www/` 코드를 실행하고, 설정의 `mode` 값(`desktop` / `web`)으로 접근 방식만 달라진다. 배포 파일(zip)만 따로 만든다.

## 1. 지침 원안과 달라진 점

| 지침 원안 | 확정 구조 | 이유 |
|---|---|---|
| Electron 조작 화면 | 포터블 PHP 로컬 서버 + Edge 앱 창(`--app`) | 최종 목표가 PHP 웹이다. 같은 코드를 웹호스팅에 그대로 올린다. |
| React/TypeScript/Vite | 빌드 단계 없는 PHP 템플릿 + 순수 JS/CSS | FTP 배포에 빌드 도구가 필요 없다. 기존 채팅·상품 관리와 같은 방식이다. |
| Node.js + Express/Fastify | PHP 8.1+ (프레임워크 없음) | 위와 같다. |
| WebSocket | 짧은 폴링(출력 0.3초, 패널 1초) | PHP 내장 서버는 Windows에서 단일 프로세스다. 일반 웹호스팅도 WebSocket·장시간 연결을 지원하지 않는다. |
| SQLite | PC: SQLite / 웹: MySQL (SQLite도 가능) | 호스팅 기본 DB가 MySQL이다. SQL은 두 DB에서 모두 동작하게 작성한다. |

데이터 흐름, AUTO/MANUAL/FINAL 분리, PREVIEW/PROGRAM 분리 같은 운영 규칙은 지침([DATA_AND_OVERRIDE](DATA_AND_OVERRIDE.md))을 그대로 따른다.

## 2. 폴더 구조

```
cg/
├─ www/                     PC·웹 공용 실행 코드 (웹은 이 폴더 안의 내용을 업로드)
│  ├─ index.php             조작 패널 (토네이도식 페이지 리스트 + PVW/PGM)
│  ├─ output.php            송출 화면 (OBS/vMix Browser Source, 1920×1080 투명)
│  ├─ login.php 등          웹 전용 화면 (desktop 모드에서는 404)
│  ├─ api/                  ping · state(패널 상태) · output(송출 상태) · action(조작)
│  ├─ assets/               panel.* output.* cg.css(CG 테마) portal.*(웹 계정 화면)
│  └─ app/                  내부 코드 (.htaccess로 외부 접근 차단)
│     ├─ bootstrap.php      설정·모드·DB·마이그레이션·헤더
│     ├─ db.php migrate.php SQLite/MySQL 공용 DB 계층, 번호식 마이그레이션
│     ├─ guard.php          접근 규칙 (desktop: 로컬 PC만 / web: 로그인·역할) + CSRF·Origin
│     ├─ provider.php       데이터 소스 (MOCK | Google 시트) fetch → normalize → validate
│     ├─ sheet_data.php     시트 표 → 세트·끝장전·예측 + 이상 사례 + 시트 자체 집계와 교차 검증 (순수 함수)
│     ├─ sheets.php         Sheets API (서비스 계정 JWT, 읽기 전용) · xlsx.php 파일 가져오기(예비)
│     ├─ verify.php         교차 검증 결과 → CG 필드별 송출 차단 사유
│     ├─ data.php           데이터 소스 설정·키 보관·마지막 정상 데이터 캐시·선수 닉네임
│     ├─ alerts.php         관리자 알림 (데이터 오류·새로고침 실패 → 새 알림/확인함/해결됨)
│     ├─ stats.php          통계 엔진 (순수 함수)
│     ├─ templates.php      CG 템플릿 목록·파라미터 검사·공용 도우미
│     ├─ override.php       수동 수정 검증·병합 (순수 함수)
│     ├─ control.php        방송 세션·페이지 리스트·PREVIEW·PROGRAM 동작
│     ├─ auth.php           웹 계정 (초대·가입·승인·재설정·정지·시도 제한)
│     ├─ release.php        버전·무결성 검사
│     ├─ templates/         CG 9종: <slug>.def.php(파라미터·필드·AUTO·표시 문자열) + <slug>.view.php(HTML)
├─ desktop/                 PC 전용: 시작.bat, 종료.bat, PHP 준비, router.php, php.ini, 설정
├─ web/                     웹 전용: 설치·패치 안내, 설정 견본
├─ tools/                   배포 zip·패치 zip 생성
├─ tests/                   자동 테스트 (php tests/run.php). fixtures/mock = 테스트 전용 MOCK 데이터 (배포본에 없음)
└─ docs/                    지침·설계 문서
```

## 3. PC / 웹 차이

| 항목 | PC (`desktop`) | 웹 (`web`) |
|---|---|---|
| 실행 | `시작.bat` → `php -S 127.0.0.1:3100` → Edge 앱 창 | 호스팅 Apache + PHP. `install.php`로 첫 관리자 생성 |
| 데이터 | `%LOCALAPPDATA%\EndgameCG\cg.sqlite` (프로그램 폴더 밖) | MySQL (설정으로 SQLite 가능) |
| 조작 권한 | 이 PC(127.0.0.1)에서만. Host·Origin·CSRF 확인 | 로그인한 승인 계정. 관리자/운영자. Origin·CSRF 확인 |
| 계정 | 없음 (운영자 이름만 설정) | 초대 링크 → 가입 신청 → 관리자 승인, 재설정 링크, 정지 |
| 송출 주소 | `http://127.0.0.1:3100/output.php?layer=1` (LAN 모드: PC IP) | `https://도메인/output.php?t=비밀값&layer=1` |
| 업데이트 | 새 버전을 새 폴더에 풀기 (데이터 유지) | 변경 파일만 FTP 업로드, 관리자 화면에서 무결성 검사 |

웹은 팀 공용 작업공간이다. 승인된 모든 운영자가 같은 페이지 리스트와 송출 상태를 공유한다. 동시 조작은 버전 번호(rev) 확인으로 충돌을 막는다.

## 4. 데이터·송출 흐름

```
Google 시트 (Results 탭: 1행 = 1세트)  또는  MOCK JSON  (온라인: eloboard는 약관 확인 전까지 수동 입력)
   → provider: fetch → normalize → validate      형식 오류가 하나라도 있으면 실패 → 마지막 정상값 유지 + ERROR/STALE
   → 세트 → 끝장전(같은 날·같은 두 선수), 이상 경기 분리
   → 교차 검증: 시트 자체 집계(Players·상대전적조회NEW·예측 순위표)와 비교
   → cg_dataset_cache (마지막 정상 데이터, 페이지 추가 때 네트워크 없이 사용)
   → stats: 세트 기준(종족 승률·다승), 끝장전 기준(맞대결·연승·풀세트), 예측 결과
   → cg_instances.auto_json  (AUTO DATA)  + issues_json (검증 사유: 해당 필드는 수동 입력 전 송출 차단)
   + cg_overrides            (MANUAL OVERRIDE: 방송 세션 + CG 인스턴스 + 필드)
   → override 병합·재계산    (FINAL DATA)
   → PREVIEW 채널 (준비 화면, 자동 갱신 반영)
   → TAKE: FINAL + 표시 문자열 + 위치 + 전환 효과를 PROGRAM 스냅샷으로 고정
   → output.php 폴링 → OBS / vMix
```

- **PROGRAM은 스냅샷이다.** 자동 갱신·PREVIEW 편집·RESET은 PROGRAM을 바꾸지 않는다. PROGRAM을 바꾸는 것은 TAKE와 UPDATE LIVE뿐이다.
- **UPDATE LIVE**는 현재 PROGRAM과 같은 CG 인스턴스일 때만 허용한다. 수정값(MANUAL, 이번 입력 포함) 필드만 스냅샷에서 교체하고 파생값(승률)을 다시 계산한다. 자동 갱신으로 바뀐 AUTO 값은 반영하지 않고 TAKE로만 반영한다.
- **승률**은 0.1% 단위 정수로 저장한다(61.1% → 611). 계산식은 `intdiv(2000×승 + 경기수, 2×경기수)`로 반올림이 PHP 버전과 무관하다. 0경기는 null이며 "자료 없음"으로 표시한다.
- **표시 문자열**("33승 21패", "(61.1%)")은 PHP Presenter가 만든다. 송출 화면 JS는 HTML 교체·애니메이션·폴링만 한다.

## 5. 실시간 반영 (폴링)

- 상태를 바꾸는 모든 동작은 트랜잭션 안에서 `state_rev`를 1 올리고, 영향받은 채널(`preview`/`program`)의 rev를 그 값으로 기록한다.
- 송출 화면은 `api/output.php?since=rev`를 0.3초마다, 패널은 `api/state.php?since=rev`를 1초마다 요청한다. 바뀐 것이 없으면 `{same:true}`만 받는다.
- 송출 화면의 첫 화면은 서버가 직접 그린다. OBS가 새로고침해도 빈 화면이 되지 않는다.
- 요청이 실패하면 송출 화면은 **아무것도 바꾸지 않고** 마지막 정상 화면을 유지한 채 재시도한다.
- 송출 화면은 5초마다 하트비트를 보낸다. 패널에 "출력 연결 n개 · 마지막 수신"을 표시한다.

## 6. 보안

- **공통**
  - 조작 API는 POST + JSON, `X-CSRF-Token`, 같은 출처(Origin = Host)를 모두 확인한다.
  - CSP는 `script-src 'self'`. 인라인 스크립트는 쓰지 않는다.
  - 송출 화면만 같은 출처 iframe을 허용한다(`frame-ancestors 'self'`). 나머지는 `'none'`.
  - `app/`는 PHP 내장 서버에서는 라우터 허용목록, Apache에서는 `.htaccess`로 차단한다.
- **PC**
  - 조작 화면과 API는 `127.0.0.1`/`::1` 접속이면서 Host가 `127.0.0.1:포트`·`localhost:포트`일 때만 허용한다. DNS 리바인딩을 막기 위해서다.
  - LAN 모드에서도 다른 PC는 송출 화면만 읽을 수 있다.
- **웹**
  - 비밀번호는 `password_hash`로 저장한다.
  - 초대·재설정 링크는 32바이트 난수이고 DB에는 SHA-256만 저장한다.
  - 로그인 시도 제한: 5분 동안 IP당 30회, 아이디당 10회.
  - 세션 쿠키는 HttpOnly·SameSite=Lax이며, https면 Secure를 붙인다. Lax인 이유: 메신저 링크로 들어와도 기존 로그인이 끊기지 않게 한다. 조작 위조는 CSRF 토큰과 Origin 확인으로 막는다.
  - 계정 정지나 비밀번호 재설정 시 `session_gen`이 올라가 기존 로그인이 즉시 끊긴다.
- **Google 시트**
  - 시트는 공개하지 않고 서비스 계정 이메일에만 "뷰어"로 공유한다. 권한 범위는 `spreadsheets.readonly`.
  - 키 파일은 비밀 폴더(PC: `%LOCALAPPDATA%\EndgameCG\secrets`, 웹: `app/storage/secrets` 또는 `secrets_dir`)에 0600으로 둔다. DB·로그·화면·배포 zip에 넣지 않고, 화면에는 서비스 계정 이메일만 보인다.
  - 액세스 토큰은 새로고침마다 새로 받고 저장하지 않는다. 필요한 탭·열만 읽는다(상금 열 제외).
  - 설정 변경(시트 주소·키·xlsx 가져오기)은 관리자만 한다. 운영자는 새로고침·점검만 한다.
- **비밀 출력 주소(웹)**
  - `output_token`(32바이트)을 가진 주소는 로그인 없이 PROGRAM 송출 화면만 읽는다. PREVIEW·패널 데이터·조작은 막는다.
  - 관리자가 재발급하면 이전 주소는 즉시 404가 된다.
  - 토큰은 복사를 위해 평문으로 저장하므로 DB 백업 접근을 제한한다.

## 7. PC 실행기 (Windows)

0. `시작.bat`·`시작-LAN.bat`·`종료.bat`·`PHP준비.bat`은 얇은 실행 파일이고, 실제 동작은 영문 이름의 `launcher\start.bat`·`stop.bat`·`setup-php.ps1`에 있다. 압축 프로그램이 한글 파일명을 깨뜨려도 동작하도록 하기 위해서다.
1. `시작.bat`이 `runtime\php\php.exe`를 확인한다. 없으면 `PHP준비.bat` 안내를 띄운다.
2. 이미 서버가 떠 있으면(`api/ping.php` 응답) 패널 창만 연다.
3. 서버가 없으면 `php -S 127.0.0.1:3100 -t www router.php`를 최소화 창으로 실행한다. `extension_dir`는 절대경로로 넘긴다.
4. 준비되면 Edge `--app` 창으로 패널을 연다. Edge가 없으면 기본 브라우저로 연다.

- `시작-LAN.bat`은 `0.0.0.0`으로 열어 다른 PC의 OBS/vMix가 송출 화면을 읽게 한다. 조작은 여전히 이 PC에서만 된다.
- `.bat` 파일은 CRLF·UTF-8(BOM 없음)이며 `chcp 65001`을 쓴다. `.gitattributes`로 줄바꿈을 고정한다.

## 8. DB 이식성

- DDL은 `{pk}`(자동 증가 기본키), `{opts}`(MySQL 테이블 옵션) 토큰으로 작성해 두 DB로 치환한다.
- `ON DUPLICATE KEY`·`ON CONFLICT`·`INSERT IGNORE`·`IF()`를 쓰지 않는다. SELECT 후 UPDATE/INSERT로 처리한다.
- UPDATE 뒤 영향받은 행 수에 의존하지 않는다. MySQL과 SQLite의 의미가 다르다.
- 트랜잭션 잠금: SQLite는 `BEGIN IMMEDIATE`, MySQL은 InnoDB 행 잠금을 쓴다. 마이그레이션은 MySQL에서 `GET_LOCK`, SQLite에서 즉시 트랜잭션으로 한 번만 실행한다.
- 테이블은 `cg_` 접두사를 쓴다. 기존 채팅·상품 관리 DB와 같은 DB에 두어도 충돌하지 않는다.

## 9. 배포·업데이트

- 버전의 기준은 `www/app/version.json`이다. 배포 zip 최상위에도 `VERSION.json`으로 복사한다.
- `tools/build_release.php`가 PC zip과 웹 zip을 만든다. 각 zip에는 `MANIFEST.sha256`이 포함된다.
  - 웹 zip은 서버 쪽 무결성 검사를 위해 `www/app/manifest.sha256`도 함께 담는다.
- `tools/build_patch.php`가 두 배포 zip을 비교해 변경 파일만 담은 패치 zip과 목록을 만든다.
- 웹 관리자 화면의 **무결성 검사**가 서버 파일과 manifest를 비교해 누락·변경을 보여 준다. FTP 부분 업로드를 잡기 위해서다.
