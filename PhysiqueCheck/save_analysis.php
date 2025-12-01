<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

require_once 'db.php';

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data || !isset($data['analysis'], $data['plans'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload']);
    exit;
}

$userId  = (int)$_SESSION['user_id'];
$analysis = $data['analysis'];
$plans    = $data['plans'];

// Extract scores from backend JSON
$overall = $analysis['physiqueRating']['overallScore'] ?? 0;
$chest   = $analysis['muscleAnalysis']['chest']['score'] ?? 0;
$abs     = $analysis['muscleAnalysis']['abs']['score'] ?? 0;
$arms    = $analysis['muscleAnalysis']['arms']['score'] ?? 0;
$back    = $analysis['muscleAnalysis']['back']['score'] ?? 0;
$legs    = $analysis['muscleAnalysis']['legs']['score'] ?? 0;

$stmt = $pdo->prepare("
    INSERT INTO analyses
        (user_id, overall_score, chest_score, abs_score, arms_score, back_score, legs_score, analysis_json, plans_json)
    VALUES
        (:user_id, :overall, :chest, :abs, :arms, :back, :legs, :analysis_json, :plans_json)
");

$stmt->execute([
    ':user_id'       => $userId,
    ':overall'       => $overall,
    ':chest'         => $chest,
    ':abs'           => $abs,
    ':arms'          => $arms,
    ':back'          => $back,
    ':legs'          => $legs,
    ':analysis_json' => json_encode($analysis),
    ':plans_json'    => json_encode($plans),
]);

$newId = $pdo->lastInsertId();

echo json_encode([
    'success'     => true,
    'analysis_id' => $newId,
]);
