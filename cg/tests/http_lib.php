<?php
declare(strict_types=1);

/** HTTP 테스트 도우미: 실제 PHP 서버를 띄우고, 사용자별 쿠키를 따로 가진 클라이언트로 요청한다. */

final class TestServer
{
    public string $base;
    private $proc;
    public string $dir;

    public function __construct(array $config, string $router)
    {
        $this->dir = $GLOBALS['TEST_TMP'] . '/srv-' . bin2hex(random_bytes(3));
        @mkdir($this->dir, 0775, true);
        $cfgFile = $this->dir . '/config.php';
        file_put_contents($cfgFile, '<?php return ' . var_export($config, true) . ';');
        $port = 39000 + random_int(0, 900);
        $this->base = "http://127.0.0.1:$port";
        $root = dirname(__DIR__);
        $env = array_merge(getenv(), ['CG_CONFIG' => $cfgFile]);
        $this->proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', "$root/www", $router],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->dir . '/server.log', 'a'], 2 => ['file', $this->dir . '/server.log', 'a']],
            $pipes, $root, $env);
        for ($i = 0; $i < 50; $i++) {
            if (@file_get_contents($this->base . '/api/ping.php')) {
                return;
            }
            usleep(100000);
        }
        throw new RuntimeException('테스트 서버가 시작되지 않았습니다');
    }

    public function stop(): void
    {
        if (is_resource($this->proc)) {
            proc_terminate($this->proc);
            proc_close($this->proc);
        }
    }
}

final class Client
{
    private $ch;
    public string $csrf = '';

    public function __construct(private string $base)
    {
        $this->ch = curl_init();
        curl_setopt_array($this->ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 10,
        ]);
    }

    /** @return array{status:int, headers:string, body:string, location:?string, json:?array} */
    public function req(string $method, string $path, array|string|null $data = null, array $headers = [], bool $json = false): array
    {
        $h = $headers;
        curl_setopt($this->ch, CURLOPT_URL, $this->base . $path);
        curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($method === 'POST') {
            if ($json) {
                $h[] = 'Content-Type: application/json';
                curl_setopt($this->ch, CURLOPT_POSTFIELDS, json_encode($data));
            } else {
                curl_setopt($this->ch, CURLOPT_POSTFIELDS, is_array($data) ? http_build_query($data) : (string)$data);
            }
        } else {
            curl_setopt($this->ch, CURLOPT_HTTPGET, true);
        }
        curl_setopt($this->ch, CURLOPT_HTTPHEADER, $h);
        $raw = (string)curl_exec($this->ch);
        $size = curl_getinfo($this->ch, CURLINFO_HEADER_SIZE);
        $head = substr($raw, 0, $size);
        $body = substr($raw, $size);
        $loc = preg_match('/^Location: (.+)$/mi', $head, $m) ? trim($m[1]) : null;
        if (preg_match('/name="_csrf" value="([^"]+)"/', $body, $m) || preg_match('/name="csrf-token" content="([^"]+)"/', $body, $m)) {
            $this->csrf = html_entity_decode($m[1]);
        }
        return ['status' => (int)curl_getinfo($this->ch, CURLINFO_RESPONSE_CODE), 'headers' => $head, 'body' => $body,
            'location' => $loc, 'json' => json_decode($body, true)];
    }

    public function get(string $path): array
    {
        return $this->req('GET', $path);
    }

    /** 폼 제출 (같은 출처 Origin + CSRF) */
    public function form(string $path, array $fields, ?string $origin = null): array
    {
        return $this->req('POST', $path, $fields + ['_csrf' => $this->csrf], ['Origin: ' . ($origin ?? $this->base)]);
    }

    /** 조작 API */
    public function action(string $action, array $payload = [], ?string $origin = null): array
    {
        return $this->req('POST', '/api/action.php', ['action' => $action] + $payload,
            ['Origin: ' . ($origin ?? $this->base), 'X-CSRF-Token: ' . $this->csrf], true);
    }
}
