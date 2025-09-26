<?php
// api/search.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/search_helper.php';

$q = $_GET['q'] ?? '';
$type = $_GET['type'] ?? 'all'; // allowed: people, drugs, all
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = isset($_GET['perPage']) ? (int)$_GET['perPage'] : 10;

$type = in_array($type, ['people','drugs','all']) ? $type : 'all';

try {
    $pdo = getDBConnection();
    $out = perform_search($pdo, $q, $type, $page, $perPage);
    echo json_encode(['ok'=>true, 'q'=>$q, 'type'=>$type, 'data'=>$out], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>'Search failed.']);
}
