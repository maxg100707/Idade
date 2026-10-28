<?php
// Proxy da programação oficial do AI Brasil Experience 2026: devolve as sessões em JSON
// para a página (mesma origem, sem CORS). A extração fica em programacao-lib.php.
//
// Uso no servidor: GET /programacao.php
// Uso local (gerar os dados embutidos): php programacao.php --cli [bundle.js]
require __DIR__ . '/programacao-lib.php';

if (PHP_SAPI === 'cli') {
    $data = build($argv[2] ?? null);
    $data['fetched_at'] = gmdate('c');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');
$cache = programacao_cache();
if (empty($cache['sessions'])) { http_response_code(503); echo json_encode(['error' => $cache['error'] ?? 'sem dados']); exit; }
unset($cache['checked']);
echo json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
