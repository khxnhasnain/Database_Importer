<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$sessionId = $_GET['session_id'] ?? null;

if (!$sessionId) {
    echo json_encode(['status' => 'error', 'message' => 'Missing session_id']);
    exit;
}

$statusFile = __DIR__ . "/../data/status_{$sessionId}.json";

if (!file_exists($statusFile)) {
    echo json_encode([
        'status' => 'starting',
        'total_rows' => 0,
        'processed_rows' => 0,
        'imported_rows' => 0,
        'skipped_rows' => 0,
        'error_message' => null
    ]);
    exit;
}

$statusData = json_decode(file_get_contents($statusFile), true);

// If completed, failed or cancelled, we can delete the uploaded file and config file to save space
if (in_array($statusData['status'] ?? '', ['completed', 'failed', 'cancelled'])) {
    $configPath = __DIR__ . "/../data/config_{$sessionId}.json";
    if (file_exists($configPath)) {
        $config = json_decode(file_get_contents($configPath), true);
        $filePath = $config['file_path'] ?? null;
        if ($filePath && file_exists($filePath)) {
            @unlink($filePath);
        }
        @unlink($configPath);
    }
}

echo json_encode($statusData);
