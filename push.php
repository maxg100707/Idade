<?php
// API de inscrição em notificações push.
//   GET  push.php                  -> {"key": chave pública VAPID, "cron": última execução do cron}
//   POST push.php {action:"subscribe", subscription, favs:[ids]}  -> grava/atualiza a inscrição
//   POST push.php {action:"unsubscribe", subscription}            -> remove a inscrição
require __DIR__ . '/push-lib.php';

const MAX_SUBS = 20000;
const MAX_FAVS = 400;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$fail = function (int $code, string $msg) { http_response_code($code); echo json_encode(['error' => $msg]); exit; };

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $hb = push_dir() . '/heartbeat';
    echo json_encode(['key' => vapid()['public'], 'cron' => is_file($hb) ? gmdate('c', filemtime($hb)) : null]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') $fail(405, 'método não permitido');

$raw = file_get_contents('php://input', false, null, 0, 32768);
$in = json_decode((string)$raw, true);
$sub = $in['subscription'] ?? null;
if (!is_array($sub) || !is_string($sub['endpoint'] ?? null) || strlen($sub['endpoint']) > 1024) $fail(400, 'inscrição inválida');
if (!push_host_allowed($sub['endpoint'])) $fail(400, 'serviço de push não suportado');
$p256dh = b64u_dec((string)($sub['keys']['p256dh'] ?? ''));
$auth = b64u_dec((string)($sub['keys']['auth'] ?? ''));
if ($p256dh === false || strlen($p256dh) !== 65 || $p256dh[0] !== "\x04" || $auth === false || strlen($auth) !== 16) $fail(400, 'chaves inválidas');
$file = sub_file($sub['endpoint']);

if (($in['action'] ?? '') === 'unsubscribe') {
    // Só quem tem o segredo da inscrição pode removê-la
    sub_update($file, fn($cur) => ($cur && hash_equals($cur['auth'], b64u_enc($auth))) ? false : null);
    echo json_encode(['ok' => true]); exit;
}
if (($in['action'] ?? '') !== 'subscribe') $fail(400, 'ação inválida');

$favs = array_values(array_unique(array_filter((array)($in['favs'] ?? []), fn($f) => is_string($f) && preg_match('/^2[89]-[0-9a-z]{1,7}$/', $f))));
if (count($favs) > MAX_FAVS) $fail(400, 'agenda grande demais');
if (!is_file($file) && count(glob(push_dir('subs') . '/*.json')) >= MAX_SUBS) $fail(503, 'limite de inscrições atingido');

$saved = sub_update($file, function ($cur) use ($sub, $p256dh, $auth, $favs) {
    if ($cur && !hash_equals($cur['auth'], b64u_enc($auth))) $cur = null; // mesma URL, chaves novas: recomeça
    return [
        'endpoint' => $sub['endpoint'], 'p256dh' => b64u_enc($p256dh), 'auth' => b64u_enc($auth),
        'favs' => $favs, 'sent' => $cur['sent'] ?? [], 'created' => $cur['created'] ?? gmdate('c'), 'updated' => gmdate('c'),
    ];
});
echo json_encode(['ok' => (bool)$saved, 'favs' => count($favs)]);
