<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Database;

$sessionId = 'test_session';
$filePath = __DIR__ . '/../data/mock_dataset.xlsx';
$configPath = __DIR__ . "/../data/config_{$sessionId}.json";
$statusFile = __DIR__ . "/../data/status_{$sessionId}.json";

if (!file_exists($filePath)) {
    die("Mock dataset not found! Please run generate_mock.php first.\n");
}

echo "1. Generating test import configuration...\n";

$configData = [
    'db' => [
        'type' => 'sqlite',
        'path' => __DIR__ . '/../data/database.sqlite'
    ],
    'table_name' => 'test_records',
    'mapping' => [
        'name' => 'Full Name',
        'mobile' => 'Mobile Number',
        'dob' => 'Date of Birth'
    ],
    'columns' => [
        'Full Name' => 'TEXT',
        'Mobile Number' => 'TEXT',
        'Date of Birth' => 'DATE',
        'Email Address' => 'TEXT',
        'Age' => 'INTEGER',
        'Score' => 'REAL',
        'Country' => 'TEXT'
    ]
];

file_put_contents($configPath, json_encode($configData, JSON_PRETTY_PRINT));

echo "2. Running ImportWorker via PHP CLI...\n";
$workerScript = __DIR__ . '/../src/ImportWorker.php';
$cmd = "php " . escapeshellarg($workerScript) . 
       " --file " . escapeshellarg($filePath) . 
       " --config " . escapeshellarg($configPath) . 
       " --status-id " . escapeshellarg($sessionId);

system($cmd, $exitCode);

echo "Worker exited with code: $exitCode\n";

if ($exitCode === 0 && file_exists($statusFile)) {
    $status = json_decode(file_get_contents($statusFile), true);
    echo "\nImport Status Results:\n";
    echo "======================\n";
    echo "Status: " . $status['status'] . "\n";
    echo "Total Rows Scanned: " . $status['total_rows'] . "\n";
    echo "Successfully Imported: " . $status['imported_rows'] . "\n";
    echo "Skipped (Invalid Mobiles): " . $status['skipped_rows'] . "\n";
    echo "Time Elapsed: " . ($status['end_time'] - $status['start_time']) . " seconds\n";
    
    // Verify SQLite Data
    echo "\n3. Verifying database integrity...\n";
    try {
        $db = new Database($configData['db']);
        $pdo = $db->getPdo();
        
        // Check row count in db
        $count = $pdo->query("SELECT COUNT(*) FROM test_records")->fetchColumn();
        echo "Actual database row count: $count\n";
        
        // Assert that all mobile numbers are valid
        $invalidMobiles = $pdo->query("SELECT COUNT(*) FROM test_records WHERE 
            length(mobilenumber) != 10 OR 
            substr(mobilenumber, 1, 1) NOT IN ('6', '7', '8', '9')")->fetchColumn();
            
        echo "Rows with invalid mobiles in db: $invalidMobiles (Expected: 0)\n";
        
        // Check date formatting validation
        $invalidDates = $pdo->query("SELECT COUNT(*) FROM test_records WHERE 
            dateofbirth IS NOT NULL AND 
            dateofbirth NOT LIKE '____-__-__'")->fetchColumn();
            
        echo "Rows with non-standard dates in db: $invalidDates (Expected: 0)\n";
        
        // Show sample imported rows
        echo "\nSample Imported Data (first 3 rows):\n";
        $samples = $pdo->query("SELECT fullname, mobilenumber, dateofbirth, emailaddress, age, score, country FROM test_records LIMIT 3")->fetchAll();
        foreach ($samples as $idx => $row) {
            echo "Row #" . ($idx + 1) . ": " . json_encode($row) . "\n";
        }
        
    } catch (Exception $e) {
        echo "Database check error: " . $e->getMessage() . "\n";
    }
} else {
    echo "Import failed or status file not written.\n";
}
