<?php
declare(strict_types=1);

define('EMK_PAYMENT_SKIP_ROUTER', true);
require_once __DIR__ . '/../api/payment.php';

$record = [
    'registrationId' => 'EMKA2026-TEST',
    'paymentStatus' => 'PAID',
    'amount' => 4500,
    'tier' => 'Delegate',
    'workshops' => ['Advanced Airway', 'EM Radiology'],
    'competitions' => [],
    'name' => 'Test Delegate',
    'email' => 'test@example.com',
    'phone' => '9876543210',
    'designation' => 'Doctor',
    'institution' => 'Test Hospital',
    'council' => 'KMC',
    'regno' => '12345',
    'category' => 'Consultant',
    'diet' => 'Vegetarian',
    'manipalInterest' => true,
    'linkId' => 'EMK26_TEST',
    'linkUrl' => 'https://example.com/payment',
    'createdAt' => '2026-08-13T10:00:00+05:30',
    'paidAt' => '2026-08-13T10:05:00+05:30',
];

$headers = googleSheetsHeaders();
$row = googleSheetsRegistrationRow($record);
if (count($headers) !== 25 || count($row) !== 25) {
    fwrite(STDERR, "Google Sheets column count mismatch\n");
    exit(1);
}

$day1Col = array_search('Workshop Day 1', $headers, true);
$day2Col = array_search('Workshop Day 2', $headers, true);
$wildernessCol = array_search('Wilderness Interest', $headers, true);
if ($row[$day1Col] !== 'Advanced Airway' || $row[$day2Col] !== 'EM Radiology' || $row[$wildernessCol] !== 'Yes') {
    fwrite(STDERR, "Workshop day/wilderness split is wrong: Day1='{$row[$day1Col]}' Day2='{$row[$day2Col]}' Wilderness='{$row[$wildernessCol]}'\n");
    exit(1);
}

echo "Google Sheets mapping test passed (25 columns)\n";
