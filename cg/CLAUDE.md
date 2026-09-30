# 스타크래프트 끝장전 방송 CG 자동화

한국어로 소통한다. Windows 방송 운영자가 버튼과 더블클릭으로 사용하는 도구다.

## 작업 원칙
- 먼저 기존 프로젝트와 지침을 확인한다. 새 기능·구조 변경은 짧은 계획을 제시하고 확인받은 뒤 구현한다. 작은 수정은 바로 진행한다.
- 한 번에 한 PHASE만 작업한다. 기존 코드를 우선 재사용하고 최소 변경한다. 요청되지 않은 기능·추상화·설정은 만들지 않는다.
- 첫 MVP는 MOCK JSON + 상대 종족 승률 CG 1종이다. 실제 데이터 연결은 후속 단계다.
- **확정 스택**: PHP 8.1+ 코어 하나로 PC 응용프로그램(포터블 PHP + SQLite, `mode=desktop`)과 웹 버전(웹호스팅 + MySQL, `mode=web`)을 만든다. 빌드 도구·프레임워크 없음, 실시간은 폴링. 상세는 [ARCHITECTURE](docs/ARCHITECTURE.md). 테스트: `php tests/run.php`.
- 운영 화면은 토네이도식(페이지 리스트·번호 큐·단축키) + vMix식 PVW/PGM 모니터. 레이어는 1개로 시작하고 레이어 번호를 받는 구조를 유지한다.
- 데이터 흐름: 자동 수집 → 검수·수동 수정 → PREVIEW → TAKE → PROGRAM → OBS/vMix.
- AUTO DATA / MANUAL OVERRIDE / FINAL DATA를 분리한다. 수동 수정은 원본을 변경하지 않으며 자동 갱신으로 지워지지 않는다.
- PREVIEW 편집·데이터 갱신은 PROGRAM을 변경하지 않는다. TAKE 또는 명시적 UPDATE LIVE만 송출 데이터를 바꾼다.
- 외부 소스 실패 시 마지막 정상 데이터와 PROGRAM을 유지하고 오류·STALE을 알린다. 충돌을 임의로 해결하지 않는다.
- 실제 URL·시트 컬럼·선수 Alias·통계 정의·인증·폰트는 추측하지 않는다. 미확정은 MOCK / TODO / NEEDS CONFIRMATION으로 표시한다.
- 비밀값은 .env로 관리하고 Git·로그·브라우저에 노출하지 않는다.
- 구현 후 코드 리뷰·불필요한 코드 정리·관련 검증을 수행한다. 화면은 브라우저에서 확인한다. 단계 종료 시 변경 파일, 실행 방법, 검증 결과, 미검증 항목, 문제, 다음 단계를 짧게 보고한다.

## 필요한 문서만 읽기
상세 문서를 자동으로 전부 불러오거나 매 응답에 재인용하지 않는다. 아래 링크는 필요한 때 직접 읽는 안내이며 자동 import를 사용하지 않는다.

| 작업 | 문서 |
|---|---|
| 첫 계획·범위·아키텍처·데이터 모델 | [PROJECT_SPEC](docs/PROJECT_SPEC.md) |
| 단계 선택·완료 조건·인수인계 | [ROADMAP](docs/ROADMAP.md) |
| 수집·통계·수동 수정·충돌·송출 상태 | [DATA_AND_OVERRIDE](docs/DATA_AND_OVERRIDE.md) |
| 스크린샷 분석·CG 9종·화면 구현 | [CG_REFERENCE_GUIDE](docs/CG_REFERENCE_GUIDE.md) |

처음 시작할 때 PROJECT_SPEC과 ROADMAP을 읽고, PHASE 0 분석 및 PHASE 1 계획을 제시한다. 구현 확인 전 코딩하지 않는다. 다음 세션은 ROADMAP의 진행 기록과 현재 단계 관련 문서·코드만 읽고 이어간다.
