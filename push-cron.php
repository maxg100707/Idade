<?php
// Tarefa agendada (cron, a cada minuto): envia por push
//  - lembrete REMIND_MIN (10) minutos antes de cada sessão da agenda de cada inscrito;
//  - aviso quando uma sessão da agenda muda de horário ou sai da programação;
//  - resumo das demais mudanças na programação oficial.
// Uso: php push-cron.php   (PUSH_NOW=2026-10-28T11:55:00-03:00 simula o horário em testes)
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/programacao-lib.php';
require __DIR__ . '/push-lib.php';

const REMIND_MIN = 10; // minutos de antecedência do lembrete (igual a REMIND_MIN na página)

$lock = fopen(push_dir() . '/cron.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit; // execução anterior ainda rodando
touch(push_dir() . '/heartbeat'); // push.php informa a última execução

$tz = new DateTimeZone('America/Sao_Paulo');
$now = new DateTimeImmutable(getenv('PUSH_NOW') ?: 'now', $tz);
$now = $now->setTimezone($tz);
$today = $now->format('Y-m-d');
$nowMin = (int)$now->format('H') * 60 + (int)$now->format('i');
$toMin = fn(string $t) => (int)substr($t, 0, 2) * 60 + (int)substr($t, 3, 2);

$cache = programacao_cache(120);
if (empty($cache['sessions'])) exit;
$sessions = sessions_by_id($cache['sessions']);

// Mudanças desde a última execução
$stateFile = push_dir() . '/state.json';
$prev = is_file($stateFile) ? json_decode(file_get_contents($stateFile), true) : null;
$moves = $added = $gone = [];
if ($prev) {
    foreach ($sessions as $id => $s) {
        if (!isset($prev[$id])) $added[] = $id;
        elseif ($prev[$id]['start'] !== $s['start'] || $prev[$id]['end'] !== $s['end']) $moves[$id] = $prev[$id];
    }
    foreach ($prev as $id => $s) if (!isset($sessions[$id])) $gone[$id] = $s;
}

$who = fn($s) => trim(implode(' · ', array_filter([$s['arena'], $s['sp'] ?? null])));
$sent = $failed = $removed = 0;
foreach (glob(push_dir('subs') . '/*.json') as $file) {
    $sub = json_decode((string)file_get_contents($file), true);
    if (!$sub) continue;
    $done = $sub['sent'] ?? [];
    $favs = array_flip($sub['favs'] ?? []);
    $msgs = [];

    foreach ($favs as $id => $_) {
        $s = $sessions[$id] ?? null;
        if (!$s || "2026-10-{$s['day']}" !== $today) continue;
        $left = $toMin($s['start']) - $nowMin;
        if ($left >= 1 && $left <= REMIND_MIN) $msgs["rem:$id:{$s['start']}"] = [['title' => "Começa em $left min: {$s['title']}",
            'body' => "{$s['start']}–{$s['end']} · " . $who($s), 'tag' => "rem-$id", 'url' => "./#dia-{$s['day']}"], $left * 60];
    }
    foreach ($moves as $id => $o) if (isset($favs[$id])) {
        $s = $sessions[$id];
        $msgs["chg:$id:{$s['start']}-{$s['end']}"] = [['title' => "Sua agenda mudou: {$s['title']}",
            'body' => "Agora {$s['start']}–{$s['end']} (antes {$o['start']}–{$o['end']}), dia {$s['day']}/10 · {$s['arena']}", 'tag' => "chg-$id", 'url' => "./#dia-{$s['day']}"], 6 * 3600];
    }
    foreach ($gone as $id => $o) if (isset($favs[$id])) {
        $msgs["gone:$id"] = [['title' => "Saiu da programação: {$o['title']}",
            'body' => "Estava na sua agenda às {$o['start']} do dia {$o['day']}/10. Confira o site oficial.", 'tag' => "gone-$id", 'url' => "./#dia-{$o['day']}"], 6 * 3600];
    }
    $m = count(array_diff_key($moves, $favs)); $a = count($added); $g = count(array_diff_key($gone, $favs));
    if ($m + $a + $g) {
        $parts = array_filter([$m ? ($m === 1 ? '1 sessão mudou de horário' : "$m sessões mudaram de horário") : '',
            $a ? ($a === 1 ? '1 sessão nova' : "$a sessões novas") : '', $g ? ($g === 1 ? '1 sessão removida' : "$g sessões removidas") : '']);
        $key = 'upd:' . h32(implode(',', array_keys(array_diff_key($moves, $favs))) . '|' . implode(',', $added) . '|' . implode(',', array_keys(array_diff_key($gone, $favs))));
        $msgs[$key] = [['title' => 'Programação atualizada', 'body' => implode(', ', $parts) . '. Toque para ver o que mudou.', 'tag' => 'upd', 'url' => './'], 6 * 3600];
    }

    $newDone = []; $dead = false;
    foreach ($msgs as $key => [$msg, $ttl]) {
        if (isset($done[$key])) continue;
        $code = push_send($sub, $msg, $ttl);
        if ($code >= 200 && $code < 300) { $newDone[$key] = time(); $sent++; }
        elseif ($code === 404 || $code === 410) { $dead = true; break; } // inscrição expirada ou cancelada
        else $failed++;
    }
    if ($dead) { sub_update($file, fn() => false); $removed++; continue; }
    if ($newDone || count($done) > 300) sub_update($file, function ($cur) use ($newDone) {
        if (!$cur) return null;
        $cur['sent'] = array_filter(($cur['sent'] ?? []) + $newDone, fn($t) => $t > time() - 4 * 86400);
        return $cur;
    });
}

if (!$prev || $moves || $added || $gone) {
    file_put_contents($stateFile . '.tmp', json_encode(array_map(fn($s) => array_intersect_key($s, array_flip(['day', 'start', 'end', 'title', 'arena'])), $sessions), JSON_UNESCAPED_UNICODE));
    rename($stateFile . '.tmp', $stateFile);
}
if ($sent || $failed || $removed || $moves || $added || $gone) {
    $log = push_dir() . '/cron.log';
    if (is_file($log) && filesize($log) > 200000) rename($log, $log . '.1');
    file_put_contents($log, sprintf("%s enviados=%d falhas=%d removidos=%d mudanças=%d novas=%d removidas=%d\n",
        $now->format('c'), $sent, $failed, $removed, count($moves), count($added), count($gone)), FILE_APPEND);
}
