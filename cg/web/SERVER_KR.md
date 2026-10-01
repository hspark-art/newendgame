# 끝장전 CG — 카페24 가상서버 설치·업데이트 안내

카페24 가상서버(직접 관리하는 리눅스 서버)에 웹 버전을 올려 쓰는 방법입니다.
- 프로그램은 **PHP 파일 그대로** 동작합니다. 빌드, GitHub, 별도 PC 작업이 필요 없습니다.
  - 파일을 서버에 올리면 바로 실행됩니다.
  - 업데이트도 바뀐 파일만 FileZilla로 덮어쓰고, 서버에서 점검 명령으로 확인합니다.
- CG는 **고정 템플릿 9종 + 데이터**(Google 시트·DB)로 그립니다.
  - 조작 패널의 '페이지 추가'는 DB에 큐 한 줄을 넣는 것입니다. 서버에 페이지나 파일을 새로 만들지 않습니다.
  - 송출 주소는 하나(`output.php`)입니다.

일반 웹호스팅(카페24 웹호스팅)이면 [INSTALL_KR.md](INSTALL_KR.md)를 보세요.

## 0. 서버 정보 확인 (처음 한 번, SSH)
OS·설치된 프로그램에 따라 설치 명령이 다릅니다. 아래를 SSH에 그대로 붙여 넣고, 나온 결과를 Claude에게 보내 주세요. 그 서버에 맞는 설치 명령을 정확히 안내합니다(추측하지 않음).

```bash
grep -E '^(NAME|VERSION)=' /etc/os-release
command -v php >/dev/null && php -v | head -1 || echo "PHP 없음"
command -v php >/dev/null && php -m | grep -iE '^(pdo_mysql|pdo_sqlite|mbstring|openssl|zip|intl)$'
command -v apache2 >/dev/null && apache2 -v | head -1
command -v httpd >/dev/null && httpd -v | head -1
command -v nginx >/dev/null && nginx -v
command -v mysql >/dev/null && mysql --version
ls /var/www 2>/dev/null; id -un
```

## 1. 폴더 구성 (권장)
| 위치 | 내용 |
|---|---|
| `/var/www/endgame-cg/` | **문서 루트**. 배포 zip의 `www` 폴더 **안의 내용**을 올립니다 |
| `/var/www/endgame-cg/app/config.php` | 설정 파일. 서버에서 직접 만들고, 업로드 파일에는 없습니다 |
| `/var/lib/endgame-cg/data` | 데이터·오류 기록 (`storage_dir`) |
| `/var/lib/endgame-cg/secrets` | Google 서비스 계정 키 (`secrets_dir`) |
| `/var/lib/endgame-cg/sessions` | 로그인 세션 (`session_path`) |

- 데이터·키·세션은 **웹 폴더 밖**에 둡니다. 웹 서버 설정이 틀려도 바깥에서 열 수 없게 하기 위해서입니다.
- 아래 명령의 `웹서버계정`은 OS마다 다릅니다(예: Ubuntu `www-data`, Rocky `apache` 또는 `nginx`). 0단계 결과로 확정합니다.

## 2. 서버에 필요한 것
- **PHP 8.1 이상**. 확장: `pdo_mysql`(또는 `pdo_sqlite`), `mbstring`, `openssl`, `zip`, `intl`(권장)
- **웹 서버**: Apache 또는 Nginx (5단계 설정 필요)
- **DB**: MySQL/MariaDB (권장) 또는 SQLite
- **도메인과 SSL 인증서(https)**: 로그인 정보와 비밀 송출 주소를 보호합니다
- **나가는 접속 허용**: `oauth2.googleapis.com`, `sheets.googleapis.com` (443). Google 시트를 읽을 때 필요합니다
- PHP 설정 `post_max_size`·`upload_max_filesize`는 16M 이상이어야 합니다(xlsx 가져오기)

## 3. DB 만들기 (MySQL/MariaDB)
SSH에서 `sudo mysql` 로 들어가 실행합니다. 비밀번호는 새로 정한 긴 값으로 바꿉니다.
```sql
CREATE DATABASE endgame_cg CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'endgame_cg'@'localhost' IDENTIFIED BY '새로_정한_긴_비밀번호';
GRANT ALL PRIVILEGES ON endgame_cg.* TO 'endgame_cg'@'localhost';
FLUSH PRIVILEGES;
```

## 4. 파일 올리기 (FileZilla) + 설정 파일
1. FileZilla 연결은 **SFTP**(포트 22, SSH 계정)를 권장합니다. 일반 FTP(21)는 비밀번호가 암호화되지 않습니다.
2. 배포 zip을 PC에서 풀고 `www` 폴더 **안의 내용 전체**를 서버 `/var/www/endgame-cg/`에 올립니다.
   - 숨김 파일 `.htaccess`도 올라가야 합니다. FileZilla [서버 → 숨김 파일 강제 표시]를 켜세요.
3. SSH에서 폴더와 설정 파일을 만듭니다.
```bash
sudo mkdir -p /var/lib/endgame-cg/data /var/lib/endgame-cg/secrets /var/lib/endgame-cg/sessions
sudo chown -R 웹서버계정: /var/lib/endgame-cg
sudo chmod 700 /var/lib/endgame-cg/secrets /var/lib/endgame-cg/sessions
cd /var/www/endgame-cg
sudo cp app/config.sample.php app/config.php
sudo nano app/config.php
sudo chown root:웹서버계정 app/config.php && sudo chmod 640 app/config.php
```
4. `config.php`에는 아래 값을 넣습니다.
```php
'db' => ['driver' => 'mysql', 'host' => 'localhost', 'port' => 3306,
         'name' => 'endgame_cg', 'user' => 'endgame_cg', 'pass' => '3단계 비밀번호'],
'storage_dir' => '/var/lib/endgame-cg/data',
'secrets_dir' => '/var/lib/endgame-cg/secrets',
'session_path' => '/var/lib/endgame-cg/sessions',
```

## 5. 웹 서버 설정
`app/` 폴더(설정·코드)는 웹에서 열리면 안 됩니다.
- **Apache**
  - 사이트 설정의 문서 루트를 `/var/www/endgame-cg`로 합니다.
  - 그 폴더에 `AllowOverride All`을 줍니다. 그래야 함께 올린 `.htaccess`가 `app/`을 막습니다.
- **Nginx**: `.htaccess`가 동작하지 않으므로 server 블록에 직접 막는 설정을 넣습니다.
  - 아래는 예시입니다. PHP-FPM 소켓 경로는 0단계 결과로 확정합니다.
```nginx
root /var/www/endgame-cg;
index index.php;
client_max_body_size 16m;
location ^~ /app/ { return 404; }
location ~ /\. { return 404; }
location / { try_files $uri $uri/ =404; }
location ~ \.php$ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_pass unix:/run/php/php-fpm.sock;   # 서버에 맞게
}
```
- 설정을 바꾼 뒤에는 웹 서버를 다시 읽게 합니다(reload).
- SSL 인증서(Let's Encrypt 등) 적용 명령도 OS에 맞게 따로 안내합니다.

## 6. 첫 점검 → 첫 관리자
```bash
cd /var/www/endgame-cg
sudo -u 웹서버계정 php app/cli.php check --net --url=https://도메인
```
1. 결과의 **[실패]가 0건**인지 확인합니다. [실패]·[주의] 줄에는 무엇을 고칠지 함께 나옵니다.
   - `--net`: Google 시트 서버 접속을 확인합니다.
   - `--url`: 웹에서 `app/`이 막혀 있는지 확인합니다.
2. 브라우저로 `https://도메인/install.php`에 들어가 첫 관리자를 만듭니다.
3. `sudo rm /var/www/endgame-cg/install.php`로 설치 파일을 지운 뒤 점검을 다시 실행합니다.
4. 이후 팀원 초대·OBS 연결은 [INSTALL_KR.md](INSTALL_KR.md) 5·6절, 시트 연결은 [GOOGLE_SHEET_KR.md](GOOGLE_SHEET_KR.md)를 따릅니다.

## 7. 업데이트 (앞으로의 방식)
수정이 있을 때마다 Claude가 아래 세 가지를 드립니다. 전체를 다시 올리지 않습니다.
- **바뀐 파일 목록**: 문서 루트 기준 경로(예: `app/data.php`, `assets/panel.js`)
- **그 파일만 담은 zip**: `files/www/` 안이 문서 루트와 같은 구조입니다
- **서버에서 실행할 SSH 명령**: 보통 `check`이고, DB 구조가 바뀔 때만 `migrate`가 함께 나옵니다

순서는 아래와 같습니다.
1. 방송이 없을 때 진행합니다. 먼저 DB를 백업합니다.
   `sudo mysqldump endgame_cg > ~/endgame_cg-$(date +%Y%m%d).sql`
2. FileZilla로 `files/www/` 안의 파일을 문서 루트의 **같은 위치에 덮어씁니다**. `app/version.json`은 **맨 마지막**에 올립니다.
3. SSH에서 안내받은 명령을 실행합니다.
```bash
cd /var/www/endgame-cg
sudo -u 웹서버계정 php app/cli.php migrate   # DB 구조가 바뀔 때만 (안내가 있을 때)
sudo -u 웹서버계정 php app/cli.php check
```
4. **[실패] 0건이면 완료**입니다.
   - "배포본과 다름: 파일명"이 나오면 그 파일을 다시 올리고 점검을 반복합니다.
   - `app/version.json`·`app/manifest.sha256`도 함께 올렸는지 확인합니다.
5. 조작 패널을 Ctrl+F5로 새로고침합니다.

지키는 것
- `app/config.php`는 덮어쓰지 않습니다. 업데이트 파일에도 들어 있지 않습니다.
- `install.php`는 다시 올리지 않습니다.
- 서버에서 git·GitHub로 받는 방식은 쓰지 않습니다.

## 8. 백업·기록
- **DB 백업**: 7-1의 `mysqldump` 명령입니다. 정기 백업이 필요하면 말씀해 주세요. 서버에 맞는 예약 작업(cron) 명령을 드립니다.
- **오류 기록**: `/var/lib/endgame-cg/data/error.log`
- **조작 기록**: 조작 패널의 송출 로그와 관리자 화면의 계정 기록

## 9. 문제 해결
| 증상 | 확인할 것 |
|---|---|
| 모든 화면이 "설정 파일이 없습니다" | `app/config.php`가 있는지, 웹서버계정이 읽을 수 있는지(640, 그룹=웹서버계정) |
| 흰 화면 / 500 오류 | `php app/cli.php check` 결과, 웹 서버 오류 로그, `/var/lib/endgame-cg/data/error.log` |
| 점검에서 "웹에서 /app/... 이(가) 막혀 있지 않습니다" | Apache `AllowOverride All` 또는 Nginx `location ^~ /app/` 설정 (5단계) |
| 점검에서 "googleapis.com:443 에 접속할 수 없습니다" | 서버 방화벽의 나가는 443 허용, DNS. 당장은 xlsx 가져오기로 운영 |
| 로그인 후 자꾸 로그아웃됨 | `session_path` 폴더가 있고 웹서버계정이 쓸 수 있는지 |
| 업데이트 후 화면이 이전 그대로 | `app/version.json`을 올렸는지, Ctrl+F5 |
