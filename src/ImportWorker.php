<?php

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.");
}

require_once __DIR__ . '/../vendor/autoload.php';

use App\Database;
use App\ExcelReader;
use App\DataValidator;

// Parse CLI Arguments
$options = getopt("", ["file:", "config:", "status-id:"]);
$filePath = $options['file'] ?? null;
$configPath = $options['config'] ?? null;
$statusId = $options['status-id'] ?? null;

if (!$filePath || !$configPath || !$statusId) {
    echo "Usage: php ImportWorker.php --file=<file_path> --config=<config_path> --status-id=<status_id>\n";
    exit(1);
}

$statusFile = __DIR__ . "/../data/status_{$statusId}.json";
$cancelFile = __DIR__ . "/../data/cancel_{$statusId}.txt";

function updateStatus(string $statusFile, array $data): void {
    file_put_contents($statusFile, json_encode($data, JSON_PRETTY_PRINT));
}

$status = [
    'status' => 'running',
    'total_rows' => 0,
    'processed_rows' => 0,
    'imported_rows' => 0,
    'skipped_rows' => 0,
    'error_message' => null,
    'start_time' => time(),
    'end_time' => null
];

try {
    $errorLogFile = null;
    if (!file_exists($filePath)) {
        throw new Exception("Excel file not found at: $filePath");
    }
    if (!file_exists($configPath)) {
        throw new Exception("Configuration file not found at: $configPath");
    }

    $config = json_decode(file_get_contents($configPath), true);
    if (!$config) {
        throw new Exception("Invalid JSON configuration in: $configPath");
    }

    $dbConfig = $config['db'];
    $tableName = $config['table_name'];
    $mapping = $config['mapping']; // e.g. ['name' => 'NameHeader', 'mobile' => 'MobileHeader', 'dob' => 'DOBHeader']
    $allColumns = $config['columns']; // All target columns to import, mapped from Excel headers

    // Initialize Database
    $db = new Database($dbConfig);

    // Force correct database types for mapped special fields to prevent MySQL signed INT overflow (max 2147483647)
    if (isset($mapping['mobile']) && $mapping['mobile'] !== '') {
        $allColumns[$mapping['mobile']] = 'TEXT';
    }
    if (isset($mapping['name']) && $mapping['name'] !== '') {
        $allColumns[$mapping['name']] = 'TEXT';
    }
    if (isset($mapping['dob']) && $mapping['dob'] !== '') {
        $allColumns[$mapping['dob']] = 'DATE';
    }

    // Create dynamic database table
    $db->createTable($tableName, $allColumns);

    // Initialize status file
    updateStatus($statusFile, $status);

    // Exact row count for 100% accurate Total Data Found metric
    $status['total_rows'] = ExcelReader::countRows($filePath);
    updateStatus($statusFile, $status);

    // Open spreadsheet for streaming
    $reader = ExcelReader::getReader($filePath);
    $reader->open($filePath);

    // Initialize dedicated CSV error file on disk
    $errorFilePath = __DIR__ . "/../data/errors_{$statusId}.csv";
    $errorLogFile = fopen($errorFilePath, 'w');
    if (!$errorLogFile) {
        throw new Exception("Unable to create or open error log file at: $errorFilePath");
    }
    fputcsv($errorLogFile, ['Row Number', 'Error Reason', 'Raw Line Data']);

    // Prepare batch inserts
    $columnsToInsert = array_keys($allColumns);

    $batchSize = 1000;
    $batchBuffer = [];
    $rowCount = 0;
    $importedCount = 0;
    $skippedCount = 0;

    $db->beginTransaction();

    foreach ($reader->getSheetIterator() as $sheetIndex => $sheet) {
        if ($sheetIndex !== 1) break; // only import first sheet

        $headers = [];
        foreach ($sheet->getRowIterator() as $row) {
            // Check for cancel signal
            if (file_exists($cancelFile)) {
                $status['status'] = 'cancelled';
                $reader->close();
                if ($errorLogFile !== null && is_resource($errorLogFile)) {
                    fclose($errorLogFile);
                }
                $db->rollBack();
                @unlink($cancelFile);
                $status['end_time'] = time();
                updateStatus($statusFile, $status);
                echo "Import cancelled by user.\n";
                exit(0);
            }

            $cells = $row->getCells();
            $rowData = [];
            foreach ($cells as $cell) {
                $rowData[] = $cell->getValue();
            }

            if ($rowCount === 0) {
                // Read and match headers to index
                $headers = array_map(function($val) {
                    return trim((string)$val);
                }, $rowData);
            } else {
                // Map Excel data to target table columns
                $mappedRow = [];
                $isRowValid = true;

                // Pre-map spreadsheet columns by header name
                $rowAssoc = [];
                foreach ($headers as $idx => $header) {
                    if (isset($rowData[$idx])) {
                        $rowAssoc[$header] = $rowData[$idx];
                    }
                }

                // Custom checks
                $validationReason = null;

                // 1. Name validation/cleaning
                $nameHeader = $mapping['name'] ?? null;
                $nameValue = null;
                if ($nameHeader) {
                    $rawName = $rowAssoc[$nameHeader] ?? null;
                    $nameValue = DataValidator::validateName($rawName);
                    if ($nameValue === null || $nameValue === '') {
                        $isRowValid = false;
                        $validationReason = "Missing or invalid name: " . ($rawName ?? 'empty');
                    }
                } else {
                    $isRowValid = false;
                    $validationReason = "Name column mapping is missing";
                }

                // 2. Mobile Number (Strict Validation)
                if ($isRowValid) {
                    $mobileHeader = $mapping['mobile'] ?? null;
                    $mobileValue = null;
                    if ($mobileHeader !== null && $mobileHeader !== '') {
                        $rawMobile = $rowAssoc[$mobileHeader] ?? null;
                        $rawMobileTrimmed = ($rawMobile !== null) ? trim((string)$rawMobile) : '';
                        if ($rawMobileTrimmed === '') {
                            $mobileValue = null;
                        } else {
                            $validMobile = DataValidator::validateMobile($rawMobileTrimmed);
                            if ($validMobile === false || !preg_match('/^[6-9]\d{9}$/', $validMobile)) {
                                $isRowValid = false;
                                $validationReason = "Invalid phone number: " . (string)$rawMobile;
                            } else {
                                $mobileValue = $validMobile;
                            }
                        }
                    }
                }

                // 3. DOB validation
                $dobHeader = $mapping['dob'] ?? null;
                $dobValue = null;
                if ($dobHeader) {
                    $rawDob = $rowAssoc[$dobHeader] ?? null;
                    $dobValue = DataValidator::validateDateOfBirth($rawDob);
                }

                if ($isRowValid) {
                    // Populate columns to insert
                    $insertValues = [];
                    foreach ($columnsToInsert as $col) {
                        // Check if this column is mapped to Name, Mobile or DOB
                        if ($col === $nameHeader) {
                            $insertValues[] = $nameValue;
                        } elseif ($col === $mobileHeader) {
                            $insertValues[] = $mobileValue;
                        } elseif ($col === $dobHeader) {
                            $insertValues[] = $dobValue;
                        } else {
                            $val = $rowAssoc[$col] ?? null;
                            
                            // Basic format cleaning
                            $colType = $allColumns[$col] ?? 'TEXT';
                            if ($colType === 'INTEGER') {
                                $val = ($val !== null && $val !== '') ? (int)$val : null;
                            } elseif ($colType === 'REAL') {
                                $val = ($val !== null && $val !== '') ? (float)$val : null;
                            } elseif ($colType === 'DATE') {
                                $val = DataValidator::validateDateOfBirth($val); // use DOB logic for dates
                            } else {
                                $val = ($val !== null) ? trim((string)$val) : null;
                            }
                            $insertValues[] = $val;
                        }
                    }

                    $batchBuffer[] = $insertValues;
                    if (count($batchBuffer) >= $batchSize) {
                        $inserted = $db->batchInsert($tableName, $columnsToInsert, $batchBuffer);
                        $importedCount += $inserted;
                        $batchBuffer = [];
                    }
                } else {
                    $skippedCount++;
                    // Stream validation error to disk (O(1) memory)
                    fputcsv($errorLogFile, [$rowCount, $validationReason, json_encode($rowData, JSON_UNESCAPED_UNICODE)]);
                }

                // Commit transaction and update status periodically
                if ($rowCount % $batchSize === 0) {
                    $db->commit();
                    
                    $status['processed_rows'] = $rowCount;
                    $status['imported_rows'] = $importedCount;
                    $status['skipped_rows'] = $skippedCount;
                    updateStatus($statusFile, $status);
                    
                    $db->beginTransaction();
                }
            }

            $rowCount++;
        }
        break; // Only parse first sheet
    }

    // Flush any remaining buffered batch rows
    if (!empty($batchBuffer)) {
        $inserted = $db->batchInsert($tableName, $columnsToInsert, $batchBuffer);
        $importedCount += $inserted;
        $batchBuffer = [];
    }

    $reader->close();
    if ($errorLogFile !== null && is_resource($errorLogFile)) {
        fclose($errorLogFile);
    }
    
    // Commit any remaining inserts
    $db->commit();

    // Finalize status with 100% exact row metrics
    $totalDataRows = max(0, $rowCount - 1);
    $status['status'] = 'completed';
    $status['total_rows'] = $totalDataRows;
    $status['processed_rows'] = $totalDataRows;
    $status['imported_rows'] = $importedCount;
    $status['skipped_rows'] = $skippedCount;
    $status['end_time'] = time();
    updateStatus($statusFile, $status);

    echo "Import completed successfully! {$importedCount} rows imported, {$skippedCount} rows skipped.\n";

} catch (Exception $e) {
    if ($errorLogFile !== null && is_resource($errorLogFile)) {
        fclose($errorLogFile);
    }
    if (isset($db)) {
        try {
            $db->rollBack();
        } catch (Exception $_) {}
    }
    
    $status['status'] = 'failed';
    $status['error_message'] = $e->getMessage();
    $status['end_time'] = time();
    updateStatus($statusFile, $status);

    echo "Import failed: " . $e->getMessage() . "\n";
    exit(1);
}
