<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Only POST requests are allowed.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$sessionId = $input['session_id'] ?? null;

if (!$sessionId) {
    echo json_encode(['status' => 'error', 'message' => 'Missing session_id']);
    exit;
}

$cancelFile = __DIR__ . "/../data/cancel_{$sessionId}.txt";
$statusFile = __DIR__ . "/../data/status_{$sessionId}.json";

// Create cancel signal file
file_put_contents($cancelFile, 'cancel');

// Update status to cancelling if it's currently running
if (file_exists($statusFile)) {
    $statusData = json_decode(file_get_contents($statusFile), true);
    if ($statusData['status'] === 'running' || $statusData['status'] === 'starting') {
        $statusData['status'] = 'cancelling';
        file_put_contents($statusFile, json_encode($statusData, JSON_PRETTY_PRINT));
    }
}

echo json_encode(['status' => 'success', 'message' => 'Cancel signal sent successfully.']);
