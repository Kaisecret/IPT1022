<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

if (!isset($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing id']);
    exit;
}

$analysisId = (int)$_GET['id'];

require_once 'db.php';

$stmt = $pdo->prepare("
    SELECT id, user_id, created_at, analysis_json, plans_json
    FROM analyses
    WHERE id = :id
");
$stmt->execute([':id' => $analysisId]);
$row = $stmt->fetch();

if (!$row || (int)$row['user_id'] !== (int)$_SESSION['user_id']) {
    http_response_code(404);
    echo json_encode(['error' => 'Analysis not found']);
    exit;
}

echo json_encode([
    'id'        => $row['id'],
    'created_at'=> $row['created_at'],
    'analysis'  => json_decode($row['analysis_json'], true),
    'plans'     => json_decode($row['plans_json'], true),
]);
