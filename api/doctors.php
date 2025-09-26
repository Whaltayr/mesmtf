<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');

$pdo = pdo();   

$action = $_GET['action'] ?? 'search';
$q = trim((string)($_GET['q'] ?? ''));
$limit = (int)($_GET['limit'] ?? 50);
if ($limit < 1) $limit = 1;
if ($limit > 200) $limit = 200;
//api to search for doctors on the db, this is connected with appointments search bar
try {   
    if ($action !== 'search') {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
        exit;
    }
    $prefix = $q === '' ? '' : '%'.str_replace(['%','_'],['\\%','\\_'],$q).'%';
    if ($prefix !== '') {
        $sql = "SELECT id,
        COALESCE(NULLIF(full_name, ''), username) AS full_name,
        username,
        specialty
        FROM users WHERE role_id = 4
        AND (full_name LIKE :q OR username LIKE :q) ORDER BY full_name ASC LIMIT $limit";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':q' => $prefix]);
    } else {
        $sql = "SELECT id,
        COALESCE(NULLIF(full_name,''), username) AS full_name,
        username,
        specialty
        FROM users WHERE role_id = 4 ORDER BY full_name ASC LIMIT $limit";
        $stmt = $pdo->query($sql);
    }
    $rows = $stmt->fetchAll();
    echo json_encode(
        ['data' => ['rows' => $rows]],
        JSON_UNESCAPED_UNICODE
    );
} catch (Throwable $e) {
    error_log('api/doctors.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to Load doctors, try again later.']);
}
