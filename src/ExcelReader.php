<?php

namespace App;

use OpenSpout\Reader\XLSX\Reader as XLSXReader;
use OpenSpout\Reader\CSV\Reader as CSVReader;
use OpenSpout\Reader\ODS\Reader as ODSReader;
use OpenSpout\Reader\ReaderInterface;
use Exception;

class ExcelReader {
    /**
     * Get the correct OpenSpout reader for the file type.
     */
    public static function getReader(string $filePath): ReaderInterface {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        switch ($ext) {
            case 'xlsx':
                $reader = new XLSXReader();
                break;
            case 'csv':
                $reader = new CSVReader();
                // Set CSV specific options if needed, e.g. delimiter auto-detection
                break;
            case 'ods':
                $reader = new ODSReader();
                break;
            default:
                throw new Exception("Unsupported file format: .$ext");
        }
        
        return $reader;
    }

    /**
     * Reads headers and a few sample rows to detect data types and structures.
     */
    public static function analyze(string $filePath, int $sampleSize = 20): array {
        $reader = self::getReader($filePath);
        $reader->open($filePath);

        $headers = [];
        $sampleRows = [];
        $columnTypes = [];
        
        foreach ($reader->getSheetIterator() as $sheetIndex => $sheet) {
            // Only process the first sheet for analysis
            if ($sheetIndex !== 1) break; 
            
            $rowCount = 0;
            foreach ($sheet->getRowIterator() as $row) {
                $cells = $row->getCells();
                $rowData = [];
                foreach ($cells as $cell) {
                    $rowData[] = $cell->getValue();
                }

                if ($rowCount === 0) {
                    // Header row
                    $headers = array_map(function($val) {
                        return trim((string)$val);
                    }, $rowData);
                    // Filter empty headers
                    $headers = array_filter($headers, fn($val) => $val !== '');
                    $headers = array_values($headers); // re-index
                } else {
                    // Data rows
                    $sampleRows[] = $rowData;
                    
                    // Profile data types
                    foreach ($rowData as $colIdx => $val) {
                        if (!isset($headers[$colIdx])) continue;
                        $headerName = $headers[$colIdx];
                        $detectedType = DataValidator::detectType($val);
                        
                        if (!isset($columnTypes[$headerName])) {
                            $columnTypes[$headerName] = [];
                        }
                        $columnTypes[$headerName][$detectedType] = ($columnTypes[$headerName][$detectedType] ?? 0) + 1;
                    }
                }

                $rowCount++;
                if ($rowCount > $sampleSize) {
                    break;
                }
            }
            break; // only first sheet
        }
        $reader->close();

        // Determine final type of each column based on majority vote of sample
        $finalColumnTypes = [];
        foreach ($headers as $header) {
            $types = $columnTypes[$header] ?? [];
            if (empty($types)) {
                $finalColumnTypes[$header] = 'TEXT';
                continue;
            }
            arsort($types);
            $finalColumnTypes[$header] = key($types);
        }

        return [
            'headers' => $headers,
            'columnTypes' => $finalColumnTypes,
            'sampleRows' => array_slice($sampleRows, 0, 10) // return first 10 for UI preview
        ];
    }

    /**
     * Counts the total number of rows in the spreadsheet (slow but accurate).
     */
    public static function countRows(string $filePath): int {
        $reader = self::getReader($filePath);
        $reader->open($filePath);
        $totalRows = 0;

        foreach ($reader->getSheetIterator() as $sheetIndex => $sheet) {
            if ($sheetIndex !== 1) break; // only count first sheet
            foreach ($sheet->getRowIterator() as $row) {
                $totalRows++;
            }
            break;
        }
        
        $reader->close();
        // Return total rows excluding header
        return max(0, $totalRows - 1);
    }
}
