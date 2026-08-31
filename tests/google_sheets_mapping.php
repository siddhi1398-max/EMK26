<?php
declare(strict_types=1);

function formatList(mixed $items, string $empty): string
{
    return is_array($items) && $items !== [] ? implode(', ', $items) : $empty;
}

require_once __DIR__ . '/../api/google-sheets.php';

$record = [
    'registrationId' => 'EMKA2026-TEST',
    'paymentStatus' => 'PAID',
    'amount' => 4500,
    'tier' => 'Delegate',
    'workshops' => [],
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
    'manipalInterest' => false,
    'linkId' => 'EMK26_TEST',
    'linkUrl' => 'https://example.com/payment',
    'createdAt' => '2026-08-13T10:00:00+05:30',
    'paidAt' => '2026-08-13T10:05:00+05:30',
];

$headers = googleSheetsHeaders();
$row = googleSheetsRegistrationRow($record);
if (count($headers) !== 22 || count($row) !== 22) {
    fwrite(STDERR, "Google Sheets column count mismatch\n");
    exit(1);
}

echo "Google Sheets mapping test passed (22 columns)\n";
