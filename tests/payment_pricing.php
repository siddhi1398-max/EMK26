<?php
declare(strict_types=1);

define('EMK_PAYMENT_SKIP_ROUTER', true);
require_once __DIR__ . '/../api/payment.php';

$early = new DateTimeImmutable('2026-09-15 23:59:59', new DateTimeZone('Asia/Kolkata'));
$standard = new DateTimeImmutable('2026-09-16 00:00:00', new DateTimeZone('Asia/Kolkata'));
// TEST PRICE — Early Bird base fee is temporarily ₹1 for live Cashfree payment testing.
$cases = [
    ['Early Bird conference', 1, calculateRegistrationAmount('Early Bird', [], false, $early)],
    ['Early Bird airway', 1001, calculateRegistrationAmount('Early Bird', ['Advanced Airway'], false, $early)],
    ['Early Bird radiology', 1501, calculateRegistrationAmount('Early Bird', ['EM Radiology'], false, $early)],
    ['Standard airway', 1501, calculateRegistrationAmount('Early Bird', ['Advanced Airway'], false, $standard)],
    ['Standard two workshops', 3501, calculateRegistrationAmount('Early Bird', ['Advanced Airway', 'EM Radiology'], false, $standard)],
    ['Early Bird plus Manipal', 1501, calculateRegistrationAmount('Early Bird', [], true, $early)],
    ['Faculty workshops included', 7000, calculateRegistrationAmount('Faculty / Consultant', ALLOWED_WORKSHOPS, false, $early)],
    ['Faculty plus Manipal', 8500, calculateRegistrationAmount('Faculty / Consultant', ALLOWED_WORKSHOPS, true, $standard)],
];

foreach ($cases as [$label, $expected, $actual]) {
    if ($actual !== $expected) {
        fwrite(STDERR, $label . ': expected ' . $expected . ', got ' . $actual . "\n");
        exit(1);
    }
}

echo "Payment pricing tests passed (" . count($cases) . " cases)\n";
