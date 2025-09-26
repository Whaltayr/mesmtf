<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/util.php';

$pdo = pdo();
$action = $_GET['action'] ?? '';

if ($action === 'diagnosis_counts') {
    $from = $_GET['from'] ?? '';
    $to   = $_GET['to'] ?? '';
    $where = '1=1';
    $args = [];
    if ($from) {
        $where .= ' AND d.created_at >= ?';
        $args[] = $from . ' 00:00:00';
    }
    if ($to) {
        $where .= ' AND d.created_at <= ?';
        $args[] = $to . ' 23:59:59';
    }
    $sql = "SELECT COALESCE(di.name, d.result) AS disease, COUNT(*) AS cnt
            FROM diagnoses d
            LEFT JOIN diseases di ON di.id = d.result_disease_id
            WHERE $where
            GROUP BY COALESCE(di.name, d.result)
            ORDER BY cnt DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    json_ok(['rows' => $stmt->fetchAll()]);
}

if ($action === 'rx_counts') {
    $sql = "SELECT status, COUNT(*) AS cnt FROM prescriptions GROUP BY status";
    $rows = $pdo->query($sql)->fetchAll();
    json_ok(['rows' => $rows]);
}

if ($action === 'low_stock') {
    $sql = "SELECT name, stock, reorder_level FROM drugs WHERE stock <= reorder_level ORDER BY stock ASC";
    $rows = $pdo->query($sql)->fetchAll();
    json_ok(['rows' => $rows]);
}

json_err('Unknown action', 400);
