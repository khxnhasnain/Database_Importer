<?php

namespace App;

use DateTime;
use Exception;

class DataValidator {
    /**
     * Clean and validate a name string.
     */
    public static function validateName(?string $name): ?string {
        if ($name === null) {
            return null;
        }
        $cleaned = trim(preg_replace('/\s+/', ' ', $name));
        return $cleaned === '' ? null : $cleaned;
    }

    /**
     * Validate a mobile number.
     * Must be exactly 10 digits and start with 6, 7, 8, or 9.
     * Returns the cleaned 10-digit string on success, or false on failure.
     */
    public static function validateMobile($mobile): string|bool {
        if ($mobile === null) {
            return false;
        }

        // Remove any non-numeric characters (spaces, hyphens, country code prefixes like +91)
        $cleaned = preg_replace('/\D/', '', (string)$mobile);

        // If it starts with a country code like 91 and has 12 digits, strip the 91
        if (strlen($cleaned) == 12 && str_starts_with($cleaned, '91')) {
            $cleaned = substr($cleaned, 2);
        }

        // Must be exactly 10 digits
        if (strlen($cleaned) !== 10) {
            return false;
        }

        // Must start with 6, 7, 8, or 9
        $firstChar = $cleaned[0];
        if (!in_array($firstChar, ['6', '7', '8', '9'])) {
            return false;
        }

        return $cleaned;
    }

    /**
     * Parses and standardizes date of birth to YYYY-MM-DD.
     * Handles DateTime objects, Excel serial dates, and standard date strings.
     */
    public static function validateDateOfBirth($dob): ?string {
        if ($dob === null || $dob === '') {
            return null;
        }

        // 1. If it's already a DateTime object (OpenSpout does this for date-formatted cells)
        if ($dob instanceof DateTime) {
            return $dob->format('Y-m-d');
        }

        $dobStr = trim((string)$dob);
        if ($dobStr === '') {
            return null;
        }

        // 2. If it is a numeric string/float, check if it's an Excel serial date.
        // Excel serial date represents the number of days since Jan 1, 1900.
        // Valid serial dates for dates of birth are typically between 1 (1900-01-01) and 60000 (2064-01-01).
        if (is_numeric($dobStr) && (float)$dobStr > 0 && (float)$dobStr < 100000) {
            $serial = (float)$dobStr;
            // Excel bug: treats 1900 as a leap year. If serial is >= 60, substract 1 to fix the offset.
            if ($serial >= 60) {
                $serial -= 1;
            }
            // 25569 is the number of days between Jan 1, 1900 and Jan 1, 1970 (Unix epoch)
            $timestamp = ($serial - 25568) * 86400; 
            return gmdate('Y-m-d', $timestamp);
        }

        // 3. Try to parse string formats
        // Common formats: YYYY-MM-DD, DD/MM/YYYY, MM/DD/YYYY, DD-MM-YYYY, etc.
        $formats = [
            'Y-m-d', 'Y/m/d',
            'd-m-Y', 'd/m/Y',
            'm-d-Y', 'm/d/Y',
            'd M Y', 'd F Y',
            'Ymd', 'd.m.Y'
        ];

        foreach ($formats as $fmt) {
            $d = DateTime::createFromFormat($fmt, $dobStr);
            if ($d && $d->format($fmt) === $dobStr) {
                // Ensure the date parsed makes sense (e.g. not in the far future or past)
                $year = (int)$d->format('Y');
                if ($year > 1800 && $year <= (int)date('Y') + 10) {
                    return $d->format('Y-m-d');
                }
            }
        }

        // Fallback: try strtotime
        $time = strtotime($dobStr);
        if ($time !== false) {
            $year = (int)date('Y', $time);
            if ($year > 1800 && $year <= (int)date('Y') + 10) {
                return date('Y-m-d', $time);
            }
        }

        return null;
    }

    /**
     * Autodetects the data type of a value for DB schema creation.
     */
    public static function detectType($value): string {
        if ($value === null || $value === '') {
            return 'TEXT';
        }
        if ($value instanceof DateTime) {
            return 'DATE';
        }
        
        $str = trim((string)$value);
        if (is_numeric($str)) {
            // Check if it's an integer
            if (ctype_digit($str)) {
                return 'INTEGER';
            }
            return 'REAL';
        }

        // Check if it matches a date pattern
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $str) || preg_match('/^\d{2}[-\/\.]\d{2}[-\/\.]\d{4}$/', $str)) {
            return 'DATE';
        }

        return 'TEXT';
    }
}
