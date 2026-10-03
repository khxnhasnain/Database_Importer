<?php

require_once __DIR__ . '/../vendor/autoload.php';

use OpenSpout\Writer\XLSX\Writer;
use OpenSpout\Common\Entity\Row;

$filePath = __DIR__ . '/../data/mock_dataset.xlsx';
$dir = dirname($filePath);
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}

echo "Generating mock dataset at: $filePath ...\n";

$writer = new Writer();
$writer->openToFile($filePath);

// Headers
$headers = ['Full Name', 'Mobile Number', 'Date of Birth', 'Email Address', 'Age', 'Score', 'Country'];
$writer->addRow(Row::fromValues($headers));

$countries = ['India', 'USA', 'UK', 'Canada', 'Australia', 'Germany', 'France', 'Japan'];
$firstNames = ['Rahul', 'Amit', 'Priya', 'Sneha', 'John', 'Emma', 'David', 'Sophia', 'James', 'Olivia'];
$lastNames = ['Sharma', 'Kumar', 'Singh', 'Patel', 'Smith', 'Johnson', 'Brown', 'Davis', 'Wilson', 'Jones'];

// Let's generate 20,000 rows. This is large enough to test speed and batching, but fast to generate.
// If we want millions of rows, the user can upload their own or we can generate it. Let's keep it to 20,000 for standard testing.
$totalRows = 20000;

for ($i = 1; $i <= $totalRows; $i++) {
    $firstName = $firstNames[array_rand($firstNames)];
    $lastName = $lastNames[array_rand($lastNames)];
    $name = "$firstName $lastName";
    
    // Mobile Number configurations:
    // - 75% valid: 10 digits starting with 6,7,8,9
    // - 25% invalid: 9 digits, starting with 1/2/3, or alphanumeric, or containing country code
    $rand = rand(1, 100);
    if ($rand <= 75) {
        // Valid mobile (starts with 6,7,8,9)
        $mobile = rand(6, 9) . str_pad(rand(0, 999999999), 9, '0', STR_PAD_LEFT);
    } elseif ($rand <= 85) {
        // Invalid: starts with 1,2,3,4,5
        $mobile = rand(1, 5) . str_pad(rand(0, 999999999), 9, '0', STR_PAD_LEFT);
    } elseif ($rand <= 95) {
        // Invalid: wrong length (e.g. 9 digits or 11 digits)
        $mobile = str_pad(rand(0, 999999999), rand(8, 11), '0', STR_PAD_LEFT);
    } else {
        // Invalid: contains text or special characters
        $mobile = "MOB-" . rand(60000, 99999);
    }

    // Date of Birth configurations:
    // - 40% standard YYYY-MM-DD
    // - 30% slash format DD/MM/YYYY
    // - 15% Excel serial date (numeric)
    // - 10% Empty/Null
    // - 5% Invalid strings
    $dobRand = rand(1, 100);
    $dobYear = rand(1970, 2015);
    $dobMonth = str_pad(rand(1, 12), 2, '0', STR_PAD_LEFT);
    $dobDay = str_pad(rand(1, 28), 2, '0', STR_PAD_LEFT);

    if ($dobRand <= 40) {
        $dob = "$dobYear-$dobMonth-$dobDay";
    } elseif ($dobRand <= 70) {
        $dob = "$dobDay/$dobMonth/$dobYear";
    } elseif ($dobRand <= 85) {
        // Excel serial date formula: days since 1900-01-01.
        // Unix timestamp for dobYear-dobMonth-dobDay
        $time = strtotime("$dobYear-$dobMonth-$dobDay");
        $days = ($time / 86400) + 25569;
        $dob = (int)$days; // serial number format
    } elseif ($dobRand <= 95) {
        $dob = null;
    } else {
        $dob = "N/A - Born in " . $dobYear;
    }

    $email = strtolower($firstName . "." . $lastName . rand(10, 99) . "@example.com");
    $age = date('Y') - $dobYear;
    $score = rand(10, 100) / 10.0;
    $country = $countries[array_rand($countries)];

    $writer->addRow(Row::fromValues([$name, $mobile, $dob, $email, $age, $score, $country]));
}

$writer->close();
echo "Successfully generated $totalRows rows inside $filePath\n";
