<?php
// Leitura da programação oficial do AI Brasil Experience 2026, usada por
// programacao.php (página) e push-cron.php (notificações push).
// Lê o bundle JS de experience2026.aibrasil.ai e extrai as sessões sem executá-lo.

const SOURCE = 'https://experience2026.aibrasil.ai';
const PAGE = SOURCE . '/programacao';
const TTL = 300;          // segundos entre verificações do site oficial
const MIN_SESSIONS = 100; // abaixo disso a extração é considerada quebrada

function fetch_url(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 12,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (programacao-aibx26)', CURLOPT_ENCODING => '',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($body !== false && $code === 200) ? $body : null;
}

// Lê um array JS literal começando em $i (strings, arrays e `void 0`, como os da programação)
function js_array(string $s, int $i): ?array {
    $depth = 0; $n = strlen($s); $json = '';
    for ($j = $i; $j < $n; $j++) {
        $c = $s[$j];
        if ($c === '"') {
            $k = $j;
            for ($j++; $j < $n && $s[$j] !== '"'; $j++) if ($s[$j] === '\\') $j++;
            $json .= substr($s, $k, $j - $k + 1);
            continue;
        }
        if (substr_compare($s, 'void 0', $j, 6) === 0) { $json .= 'null'; $j += 5; continue; }
        $json .= $c;
        if ($c === '[') $depth++;
        elseif ($c === ']' && --$depth === 0) {
            $v = json_decode($json, true);
            return is_array($v) ? $v : null;
        }
    }
    return null;
}

function parse_bundle(string $js): array {
    $str = '"((?:[^"\\\\]|\\\\.)*)"';
    $dec = fn($x) => json_decode('"' . $x . '"');

    // Arenas e espaços: {id:"todos-nos",label:"Arena TODOS NÓS",kind:"arena"
    preg_match_all('/\{id:' . $str . ',label:' . $str . ',kind:"(?:arena|space)"/', $js, $m, PREG_SET_ORDER);
    $arenas = [];
    foreach ($m as $a) $arenas[$dec($a[1])] = $dec($a[2]);
    if (!$arenas) throw new RuntimeException('arenas não encontradas');

    // Workshops da Arena Adapta: {startTime:"09:00",endTime:"10:30",title:"…",speaker:"…",role:"…",format:"oficina"
    preg_match_all('/\{startTime:' . $str . ',endTime:' . $str . ',title:' . $str . ',speaker:' . $str . ',role:' . $str . ',format:' . $str . '/', $js, $m, PREG_SET_ORDER);
    $workshops = array_map(fn($w) => [$dec($w[1]), $dec($w[2]), $dec($w[3]), $dec($w[4]), $dec($w[5]), $dec($w[6])], $m);

    $out = [];
    // Blocos por arena: fn("28","todos-nos",[[início,fim,título,palestrante,empresa],…]) ou fn("28","adapta",refWorkshops)
    preg_match_all('/[\w$]+\("(2[89])","([\w-]+)",/', $js, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    foreach ($m as $call) {
        $day = $call[1][0]; $id = $call[2][0];
        if (!isset($arenas[$id])) continue;
        $pos = $call[0][1] + strlen($call[0][0]);
        if ($js[$pos] === '[') {
            $rows = js_array($js, $pos);
            if ($rows === null) throw new RuntimeException("bloco $day/$id ilegível");
            foreach ($rows as $r) $out[] = ['day' => $day, 'arena' => $arenas[$id], 'start' => $r[0] ?? null, 'end' => $r[1] ?? null,
                'title' => $r[2] ?? null, 'sp' => $r[3] ?? null, 'co' => $r[4] ?? null];
        } else {
            foreach ($workshops as $w) $out[] = ['day' => $day, 'arena' => $arenas[$id], 'start' => $w[0], 'end' => $w[1],
                'title' => $w[2], 'sp' => $w[3], 'co' => $w[4], 'format' => $w[5]];
        }
    }

    // Espaço Mão na Massa (Prime): fn("28","10:00 — 10:55","título","palestrante","empresa")
    preg_match_all('/[\w$]+\("(2[89])","(\d\d:\d\d)(?: — |\\\\u2014| \\\\u2014 )(\d\d:\d\d)",(' . $str . '(?:,' . $str . ')*)\)/u', $js, $m, PREG_SET_ORDER);
    foreach ($m as $p) {
        $a = json_decode('[' . $p[4] . ']', true) ?: [];
        $out[] = ['day' => $p[1], 'arena' => 'Espaço Mão na Massa by CEIA', 'start' => $p[2], 'end' => $p[3],
            'title' => $a[0] ?? null, 'sp' => $a[1] ?? null, 'co' => $a[2] ?? null, 'prime' => 1];
    }

    $out = array_values(array_filter($out, fn($s) =>
        preg_match('/^\d\d:\d\d$/', (string)$s['start']) && preg_match('/^\d\d:\d\d$/', (string)$s['end']) && is_string($s['title']) && $s['title'] !== ''));
    foreach ($out as &$s) $s = array_filter($s, fn($v) => $v !== null && $v !== '');
    unset($s);
    if (count($out) < MIN_SESSIONS) throw new RuntimeException('poucas sessões extraídas: ' . count($out));
    return $out;
}

function build(?string $bundleFile = null): array {
    if ($bundleFile) { $js = file_get_contents($bundleFile); $version = basename($bundleFile); }
    else {
        $html = fetch_url(PAGE);
        if (!$html || !preg_match('#/assets/index-[\w-]+\.js#', $html, $m)) throw new RuntimeException('página oficial indisponível');
        $version = basename($m[0]);
        $js = fetch_url(SOURCE . $m[0]);
        if (!$js) throw new RuntimeException('bundle indisponível');
    }
    return ['version' => $version, 'sessions' => parse_bundle($js)];
}

// Programação em cache, verificando o site oficial no máximo a cada $ttl segundos.
// Mantém a última versão válida se a fonte falhar.
function programacao_cache(int $ttl = TTL): array {
    $cacheFile = __DIR__ . '/programacao-cache.json';
    $cache = is_file($cacheFile) ? json_decode((string)file_get_contents($cacheFile), true) : null;
    if ($cache && time() - (int)($cache['checked'] ?? 0) <= $ttl) return $cache;
    $lock = fopen($cacheFile . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return $cache ?: [];
    $cache = $cache ?: [];
    $cache['checked'] = time();
    try {
        $html = fetch_url(PAGE);
        if (!$html || !preg_match('#/assets/index-[\w-]+\.js#', $html, $m)) throw new RuntimeException('página oficial indisponível');
        // O nome do bundle muda a cada publicação; só baixa e extrai de novo quando muda
        if (($cache['version'] ?? '') !== basename($m[0]) || empty($cache['sessions'])) {
            $js = fetch_url(SOURCE . $m[0]);
            if (!$js) throw new RuntimeException('bundle indisponível');
            $sessions = parse_bundle($js);
            if (!empty($cache['sessions']) && $cache['sessions'] !== $sessions) $cache['updated_at'] = gmdate('c');
            $cache['version'] = basename($m[0]);
            $cache['sessions'] = $sessions;
        }
        unset($cache['error']);
    } catch (Throwable $e) {
        $cache['error'] = $e->getMessage();
    }
    $cache['fetched_at'] = gmdate('c');
    file_put_contents($cacheFile . '.tmp', json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    rename($cacheFile . '.tmp', $cacheFile);
    flock($lock, LOCK_UN);
    return $cache;
}

// Mesmo id da página: dia + FNV-1a(arena|título|ocorrência) em base 36
function h32(string $t): string {
    $h = 0x811c9dc5;
    for ($i = 0, $n = strlen($t); $i < $n; $i++) { $h ^= ord($t[$i]); $h = ($h * 0x01000193) & 0xffffffff; }
    return base_convert((string)$h, 10, 36);
}

// Sessões indexadas pelo id usado na página (ver ingest() no HTML)
function sessions_by_id(array $sessions): array {
    $rows = array_values(array_filter($sessions, fn($s) => in_array($s['day'] ?? '', ['28', '29'], true)));
    usort($rows, fn($a, $b) => strcmp($a['start'], $b['start']) ?: strcmp($a['end'], $b['end']));
    $seen = []; $out = [];
    foreach ($rows as $r) {
        $k = $r['day'] . '|' . $r['arena'] . '|' . $r['title'];
        $seen[$k] = ($seen[$k] ?? 0) + 1;
        $out[$r['day'] . '-' . h32($r['arena'] . '|' . $r['title'] . '|' . $seen[$k])] = $r;
    }
    return $out;
}
