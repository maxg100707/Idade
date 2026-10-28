<?php
// Web Push sem dependências: chaves VAPID (RFC 8292) e criptografia aes128gcm (RFC 8291).
// Os dados (chaves, inscrições) ficam fora da pasta pública; ver PUSH_DIR.

// Fora da pasta pública quando o servidor permite; senão em push-data/ com .htaccess bloqueando
define('PUSH_DIR', is_writable(dirname(__DIR__)) ? dirname(__DIR__) . '/aibx26-push-data' : __DIR__ . '/push-data');
const PUSH_SUBJECT = 'https://powderblue-gaur-477543.hostingersite.com';
// Serviços de push aceitos (evita que o servidor seja usado para chamar URLs arbitrárias)
const PUSH_HOSTS = ['fcm.googleapis.com', 'updates.push.services.mozilla.com', 'web.push.apple.com', 'notify.windows.com'];

function b64u_enc(string $b): string { return rtrim(strtr(base64_encode($b), '+/', '-_'), '='); }
function b64u_dec(string $s): string|false { return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true); }

function push_dir(string $sub = ''): string {
    $d = PUSH_DIR . ($sub ? "/$sub" : '');
    if (!is_dir($d)) mkdir($d, 0750, true);
    if (!is_file(PUSH_DIR . '/.htaccess')) file_put_contents(PUSH_DIR . '/.htaccess', "Require all denied\nDeny from all\n");
    return $d;
}

function push_host_allowed(string $endpoint): bool {
    if (getenv('PUSH_TEST_ENDPOINT') && str_starts_with($endpoint, getenv('PUSH_TEST_ENDPOINT'))) return true; // testes locais
    $u = parse_url($endpoint);
    if (($u['scheme'] ?? '') !== 'https' || empty($u['host'])) return false;
    foreach (PUSH_HOSTS as $h) if ($u['host'] === $h || str_ends_with($u['host'], '.' . $h)) return true;
    return false;
}

// Ponto público P-256 (65 bytes, 0x04||x||y) <-> chave PEM
function ec_point(OpenSSLAsymmetricKey $k): string {
    $d = openssl_pkey_get_details($k)['ec'];
    return "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT);
}
function ec_public_from_point(string $point): OpenSSLAsymmetricKey|false {
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
    return openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n");
}

// Par VAPID criado no próprio servidor na primeira vez; a chave privada nunca sai dele
function vapid(): array {
    $f = push_dir() . '/vapid.json';
    if (is_file($f)) return json_decode(file_get_contents($f), true);
    $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    openssl_pkey_export($k, $pem);
    $v = ['private_pem' => $pem, 'public' => b64u_enc(ec_point($k))];
    file_put_contents($f, json_encode($v), LOCK_EX);
    chmod($f, 0600);
    return $v;
}

// Assinatura ES256: converte a assinatura DER do OpenSSL em r||s (64 bytes)
function es256(string $data, string $pem): string {
    openssl_sign($data, $der, openssl_pkey_get_private($pem), OPENSSL_ALGO_SHA256);
    $o = 3; $rl = ord($der[$o]); $r = substr($der, $o + 1, $rl); $o += 2 + $rl; $sl = ord($der[$o]); $s = substr($der, $o + 1, $sl);
    return str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT) . str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
}

function vapid_header(string $endpoint, array $v): string {
    $u = parse_url($endpoint);
    $aud = $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
    $jwt = b64u_enc('{"typ":"JWT","alg":"ES256"}') . '.' . b64u_enc(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => PUSH_SUBJECT], JSON_UNESCAPED_SLASHES));
    return 'vapid t=' . $jwt . '.' . b64u_enc(es256($jwt, $v['private_pem'])) . ', k=' . $v['public'];
}

function hkdf_expand(string $prk, string $info, int $len): string { return substr(hash_hmac('sha256', $info . "\x01", $prk, true), 0, $len); }

// Corpo aes128gcm (RFC 8291) para a assinatura do navegador
function push_encrypt(string $payload, string $uaPublic, string $authSecret): string {
    $eph = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $asPublic = ec_point($eph);
    $peer = ec_public_from_point($uaPublic);
    if (!$peer) throw new RuntimeException('chave p256dh inválida');
    $ecdh = openssl_pkey_derive($peer, $eph, 32);
    $ikm = hkdf_expand(hash_hmac('sha256', $ecdh, $authSecret, true), "WebPush: info\0" . $uaPublic . $asPublic, 32);
    $salt = random_bytes(16);
    $prk = hash_hmac('sha256', $ikm, $salt, true);
    $cek = hkdf_expand($prk, "Content-Encoding: aes128gcm\0", 16);
    $nonce = hkdf_expand($prk, "Content-Encoding: nonce\0", 12);
    $ct = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    return $salt . pack('N', 4096) . chr(65) . $asPublic . $ct . $tag;
}

// Envia uma notificação; devolve o status HTTP do serviço de push (0 se falhou a conexão)
function push_send(array $sub, array $msg, int $ttl = 3600): int {
    if (!push_host_allowed($sub['endpoint'])) return 400;
    $v = vapid();
    $body = push_encrypt(json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), b64u_dec($sub['p256dh']), b64u_dec($sub['auth']));
    $ch = curl_init($sub['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream', 'Content-Encoding: aes128gcm', 'TTL: ' . $ttl,
            'Urgency: high', 'Topic: ' . substr(preg_replace('/[^A-Za-z0-9_-]/', '', $msg['tag'] ?? 'aibx26'), 0, 32),
            'Authorization: ' . vapid_header($sub['endpoint'], $v)],
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

function sub_file(string $endpoint): string { return push_dir('subs') . '/' . hash('sha256', $endpoint) . '.json'; }

// Lê e altera uma inscrição sob trava de arquivo (a API e o cron escrevem nela)
function sub_update(string $file, callable $fn): ?array {
    $h = fopen($file, 'c+');
    if (!$h) return null;
    flock($h, LOCK_EX);
    $cur = json_decode(stream_get_contents($h) ?: 'null', true);
    $new = $fn($cur);
    if ($new === false) { ftruncate($h, 0); flock($h, LOCK_UN); fclose($h); @unlink($file); return null; }
    if ($new === null && $cur === null) { flock($h, LOCK_UN); fclose($h); @unlink($file); return null; }
    if ($new !== null) { ftruncate($h, 0); rewind($h); fwrite($h, json_encode($new, JSON_UNESCAPED_SLASHES)); fflush($h); }
    flock($h, LOCK_UN); fclose($h);
    return $new ?? $cur;
}
