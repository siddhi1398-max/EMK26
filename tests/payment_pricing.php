<?php
declare(strict_types=1);

define('EMK_PAYMENT_SKIP_ROUTER', true);
require_once __DIR__ . '/../api/payment.php';

$early = new DateTimeImmutable('2026-09-30 23:59:59', new DateTimeZone('Asia/Kolkata'));
$standard = new DateTimeImmutable('2026-10-01 00:00:00', new DateTimeZone('Asia/Kolkata'));
$cases = [
    ['PG / Student conference', 4000, calculateRegistrationAmount('PG / Student', [], false, $early)],
    ['PG / Student airway', 5000, calculateRegistrationAmount('PG / Student', ['Advanced Airway'], false, $early)],
    ['PG / Student radiology', 5500, calculateRegistrationAmount('PG / Student', ['EM Radiology'], false, $early)],
    ['Standard airway', 5500, calculateRegistrationAmount('PG / Student', ['Advanced Airway'], false, $standard)],
    ['Standard two workshops', 7500, calculateRegistrationAmount('PG / Student', ['Advanced Airway', 'EM Radiology'], false, $standard)],
    ['PG / Student plus Manipal', 5500, calculateRegistrationAmount('PG / Student', [], true, $early)],
    ['Faculty all workshops (early)', 15000, calculateRegistrationAmount('Faculty / Consultant', ALLOWED_WORKSHOPS, false, $early)],
    ['Faculty all workshops plus Manipal (standard)', 19500, calculateRegistrationAmount('Faculty / Consultant', ALLOWED_WORKSHOPS, true, $standard)],
];

foreach ($cases as [$label, $expected, $actual]) {
    if ($actual !== $expected) {
        fwrite(STDERR, $label . ': expected ' . $expected . ', got ' . $actual . "\n");
        exit(1);
    }
}

echo "Payment pricing tests passed (" . count($cases) . " cases)\n";
