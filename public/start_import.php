<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Database;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Only POST requests are allowed.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$sessionId = $input['session_id'] ?? null;
$filePath = $input['file_path'] ?? null;
$tableName = $input['table_name'] ?? 'imported_excel';
$mapping = $input['mapping'] ?? null;
$columns = $input['columns'] ?? null;
$dbConfig = $input['db_config'] ?? ['type' => 'sqlite'];

if (!$sessionId || !$filePath || !$mapping || !$columns) {
    echo json_encode(['status' => 'error', 'message' => 'Missing required parameters.']);
    exit;
}

// 1. Verify and clean dbConfig
if ($dbConfig['type'] === 'sqlite') {
    $dbConfig['path'] = __DIR__ . '/../data/database.sqlite';
}

// 2. Validate connection before starting worker
try {
    $db = new Database($dbConfig);
    // Connection works, close it
    unset($db);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// 3. Save Config for CLI Worker
$dataDir = __DIR__ . '/../data';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

$configPath = $dataDir . '/config_' . $sessionId . '.json';
$configData = [
    'db' => $dbConfig,
    'table_name' => $tableName,
    'mapping' => $mapping,
    'columns' => $columns
];

file_put_contents($configPath, json_encode($configData, JSON_PRETTY_PRINT));

// Initialize a blank status file indicating "starting"
$statusFile = $dataDir . '/status_' . $sessionId . '.json';
$initialStatus = [
    'status' => 'starting',
    'total_rows' => 0,
    'processed_rows' => 0,
    'imported_rows' => 0,
    'skipped_rows' => 0,
    'error_message' => null,
    'start_time' => time(),
    'end_time' => null
];
file_put_contents($statusFile, json_encode($initialStatus, JSON_PRETTY_PRINT));

// 4. Launch Worker in Background
$workerPath = realpath(__DIR__ . '/../src/ImportWorker.php');

if (str_starts_with(strtoupper(PHP_OS), 'WIN')) {
    $cmd = "start /B php " . escapeshellarg($workerPath) . 
           " --file " . escapeshellarg($filePath) . 
           " --config " . escapeshellarg($configPath) . 
           " --status-id " . escapeshellarg($sessionId) . " > NUL 2>&1";
    pclose(popen($cmd, "r"));
} else {
    $cmd = "php " . escapeshellarg($workerPath) . 
           " --file " . escapeshellarg($filePath) . 
           " --config " . escapeshellarg($configPath) . 
           " --status-id " . escapeshellarg($sessionId) . " > /dev/null 2>&1 &";
    exec($cmd);
}

echo json_encode([
    'status' => 'success',
    'message' => 'Import started in the background.',
    'session_id' => $sessionId
]);
