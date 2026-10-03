<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\ExcelReader;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Only POST requests are allowed.']);
    exit;
}

if (!isset($_FILES['file'])) {
    echo json_encode(['status' => 'error', 'message' => 'No file uploaded.']);
    exit;
}

$file = $_FILES['file'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['status' => 'error', 'message' => 'Upload error code: ' . $file['error']]);
    exit;
}

$chunkIndex = isset($_POST['chunkIndex']) ? (int)$_POST['chunkIndex'] : 0;
$totalChunks = isset($_POST['totalChunks']) ? (int)$_POST['totalChunks'] : 1;
$sessionId = isset($_POST['sessionId']) ? $_POST['sessionId'] : null;
$fileName = isset($_POST['fileName']) ? $_POST['fileName'] : null;

// Generate session ID if not provided, sanitize if provided
if (empty($sessionId)) {
    $sessionId = uniqid('session_', true);
} else {
    // Sanitize to prevent path traversal
    $sessionId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $sessionId);
}

if (empty($fileName)) {
    $fileName = $file['name'];
}

$tempPath = $file['tmp_name'];
$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

if (!in_array($ext, ['xlsx', 'csv', 'ods'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unsupported file format. Please upload .xlsx, .csv, or .ods.']);
    exit;
}

$uploadDir = __DIR__ . '/../data/uploads';

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$targetPath = $uploadDir . '/' . $sessionId . '.' . $ext;

if ($totalChunks > 1) {
    // Chunked upload
    $mode = ($chunkIndex === 0) ? 'wb' : 'ab';
    $out = fopen($targetPath, $mode);
    $in = fopen($tempPath, 'rb');
    
    if (!$out || !$in) {
        if ($in) fclose($in);
        if ($out) fclose($out);
        echo json_encode(['status' => 'error', 'message' => 'Failed to process file chunk.']);
        exit;
    }
    
    stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);
    
    // If we're not on the last chunk, return chunk success and exit
    if ($chunkIndex < $totalChunks - 1) {
        echo json_encode([
            'status' => 'chunk_success',
            'chunkIndex' => $chunkIndex,
            'totalChunks' => $totalChunks,
            'session_id' => $sessionId
        ]);
        exit;
    }
} else {
    // Standard single file upload fallback
    if (!move_uploaded_file($tempPath, $targetPath)) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to save uploaded file.']);
        exit;
    }
}

try {
    // Analyze structure of the excel
    $analysis = ExcelReader::analyze($targetPath);
    
    echo json_encode([
        'status' => 'success',
        'session_id' => $sessionId,
        'file_path' => $targetPath,
        'headers' => $analysis['headers'],
        'column_types' => $analysis['columnTypes'],
        'sample_rows' => $analysis['sampleRows']
    ]);
} catch (Exception $e) {
    @unlink($targetPath);
    echo json_encode(['status' => 'error', 'message' => 'Error reading Excel file: ' . $e->getMessage()]);
}
