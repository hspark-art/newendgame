<?php
/**
 * SOOP 방송 채팅 서버 정보 조회
 *
 * 브라우저는 보안 정책(CORS) 때문에 SOOP 방송 정보 API 를 직접 부를 수 없어서
 * 서버(PHP)가 대신 조회해 채팅 서버 주소만 수집 화면에 넘겨줍니다.
 * 채팅 자체는 수집 화면(브라우저)이 SOOP 채팅 서버에 직접 연결해서 받습니다.
 *
 * ※ SOOP 공식 API 가 아닌 방식입니다. SOOP 쪽 구조가 바뀌면 이 파일과
 *   assets/soop-chat.js 를 수정해야 합니다.
 */
declare(strict_types=1);

final class SoopError extends RuntimeException
{
    /** @param bool $retryable 잠시 후 다시 시도하면 될 수 있는 오류인지 */
    public function __construct(string $message, public readonly string $reason = 'error', public readonly bool $retryable = true)
    {
        parent::__construct($message);
    }
}

function valid_streamer_id(string $id): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_.-]{2,40}$/', $id);
}

/**
 * @return array{broadcast_no:string, chat_no:string, chat_domain:string, chat_port:int, title:string, streamer_nick:string}
 */
function soop_resolve_channel(string $streamerId): array
{
    if (!valid_streamer_id($streamerId)) {
        throw new SoopError('SOOP 방송국 ID 형식이 올바르지 않습니다.', 'invalid', false);
    }
    $url = config('soop_live_api', 'https://live.sooplive.com/afreeca/player_live_api.php') . '?bjid=' . rawurlencode($streamerId);
    $body = http_build_query([
        'bid'         => $streamerId,
        'bno'         => '',
        'type'        => 'live',
        'pwd'         => '',
        'player_type' => 'html5',
        'stream_type' => 'common',
        'quality'     => 'HD',
        'mode'        => 'landing',
        'from_api'    => '0',
        'is_revive'   => 'false',
    ]);

    $json = soop_http_post($url, $body);
    $root = json_decode($json, true);
    if (!is_array($root)) {
        throw new SoopError('SOOP 방송 정보 응답을 해석하지 못했습니다.');
    }
    $ch = is_array($root['CHANNEL'] ?? null) ? $root['CHANNEL'] : $root;
    $result = isset($ch['RESULT']) ? (int) $ch['RESULT'] : (isset($root['RESULT']) ? (int) $root['RESULT'] : null);

    if ($result !== 1) {
        [$message, $reason, $retryable] = match ($result) {
            0          => ['현재 방송 중이 아닙니다. 방송이 시작되면 자동으로 다시 연결합니다.', 'offline', true],
            -1988      => ['비밀번호가 걸린 방송은 아직 지원하지 않습니다.', 'password', false],
            -2, -13    => ['지역 제한으로 이 방송에 접속할 수 없습니다.', 'region', false],
            -3         => ['방송인이 차단한 접속입니다.', 'blacklisted', false],
            -4         => ['강제 퇴장 이후 재입장이 제한된 상태입니다.', 'kicked', false],
            -5         => ['SOOP 서비스 이용이 정지된 상태입니다.', 'suspended', false],
            -6, -8     => ['19세 방송은 아직 지원하지 않습니다. (로그인 필요)', 'adult', false],
            -10, -12   => ['유료 티켓이 필요한 방송은 지원하지 않습니다.', 'ticket', false],
            -11        => ['로그인이 필요한 방송은 아직 지원하지 않습니다.', 'login', false],
            -14        => ['구독플러스 전용 방송은 아직 지원하지 않습니다.', 'subscription_plus', false],
            default    => ['SOOP 방송 정보를 가져오지 못했습니다. (응답 코드 ' . var_export($result, true) . ')', 'error', true],
        };
        throw new SoopError($message, $reason, $retryable);
    }
    if (strtoupper((string) ($ch['BPWD'] ?? '')) === 'Y') {
        throw new SoopError('비밀번호가 걸린 방송은 아직 지원하지 않습니다.', 'password', false);
    }

    $info = [
        'broadcast_no'  => (string) ($ch['BNO'] ?? ''),
        'chat_no'       => (string) ($ch['CHATNO'] ?? ''),
        'chat_domain'   => strtolower((string) ($ch['CHDOMAIN'] ?? '')),
        'chat_port'     => (int) ($ch['CHPT'] ?? 0),
        'title'         => (string) ($ch['TITLE'] ?? ''),
        'streamer_nick' => (string) ($ch['BJNICK'] ?? ''),
    ];
    if (
        !preg_match('/^[\x21-\x7e]+$/', $info['broadcast_no'])
        || !preg_match('/^[\x21-\x7e]+$/', $info['chat_no'])
        || !preg_match('/^[a-z0-9.-]+$/', $info['chat_domain'])
        || $info['chat_port'] < 1 || $info['chat_port'] > 65534
    ) {
        throw new SoopError('SOOP 채팅 서버 정보가 올바르지 않습니다.');
    }
    return $info;
}

function soop_http_post(string $url, string $body): string
{
    $headers = [
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json, text/plain, */*',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
    ];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
        ]);
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
            curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        }
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        if ($response === false) {
            throw new SoopError('SOOP 서버에 연결하지 못했습니다. (' . $error . ')');
        }
    } else {
        $context = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $headers),
            'content'       => $body,
            'timeout'       => 20,
            'ignore_errors' => true,
        ]]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new SoopError('SOOP 서버에 연결하지 못했습니다.');
        }
        $status = 200;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            }
        }
    }
    if ($status !== 200) {
        throw new SoopError('SOOP 방송 정보 조회가 실패했습니다. (HTTP ' . $status . ')');
    }
    return (string) $response;
}
