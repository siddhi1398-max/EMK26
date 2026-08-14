<?php
declare(strict_types=1);

define('EMK_PAYMENT_SKIP_ROUTER', true);
require_once __DIR__ . '/../api/payment.php';

$early = new DateTimeImmutable('2026-09-15 23:59:59', new DateTimeZone('Asia/Kolkata'));
$standard = new DateTimeImmutable('2026-09-16 00:00:00', new DateTimeZone('Asia/Kolkata'));
$cases = [
    ['Delegate early conference', 4000, calculateRegistrationAmount('Delegate', [], false, $early)],
    ['Delegate early airway', 5000, calculateRegistrationAmount('Delegate', ['Advanced Airway'], false, $early)],
    ['Delegate early radiology', 5500, calculateRegistrationAmount('Delegate', ['EM Radiology'], false, $early)],
    ['Delegate standard airway', 5500, calculateRegistrationAmount('Delegate', ['Advanced Airway'], false, $standard)],
    ['Delegate standard two workshops', 7500, calculateRegistrationAmount('Delegate', ['Advanced Airway', 'EM Radiology'], false, $standard)],
    ['Delegate plus Manipal', 5500, calculateRegistrationAmount('Delegate', [], true, $early)],
    ['Faculty workshops included', 7000, calculateRegistrationAmount('Faculty', ALLOWED_WORKSHOPS, false, $early)],
    ['Faculty plus Manipal', 8500, calculateRegistrationAmount('Faculty', ALLOWED_WORKSHOPS, true, $standard)],
];

foreach ($cases as [$label, $expected, $actual]) {
    if ($actual !== $expected) {
        fwrite(STDERR, $label . ': expected ' . $expected . ', got ' . $actual . "\n");
        exit(1);
    }
}

echo "Payment pricing tests passed (" . count($cases) . " cases)\n";
