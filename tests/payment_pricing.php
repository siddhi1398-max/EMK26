<?php
declare(strict_types=1);

define('EMK_PAYMENT_SKIP_ROUTER', true);
require_once __DIR__ . '/../api/payment.php';

$early = new DateTimeImmutable('2026-10-15 23:59:59', new DateTimeZone('Asia/Kolkata'));
$standard = new DateTimeImmutable('2026-10-16 00:00:00', new DateTimeZone('Asia/Kolkata'));
$cases = [
    ['PG / Student conference', 4000, calculateRegistrationAmount('PG / Student', [], false, false, $early)],
    ['PG / Student airway', 5000, calculateRegistrationAmount('PG / Student', ['Advanced Airway'], false, false, $early)],
    ['PG / Student radiology', 5500, calculateRegistrationAmount('PG / Student', ['EM Radiology'], false, false, $early)],
    ['Standard airway', 5500, calculateRegistrationAmount('PG / Student', ['Advanced Airway'], false, false, $standard)],
    ['Standard two workshops', 7500, calculateRegistrationAmount('PG / Student', ['Advanced Airway', 'EM Radiology'], false, false, $standard)],
    ['PG / Student plus Manipal', 5500, calculateRegistrationAmount('PG / Student', [], true, false, $early)],
    ['PG / Student plus airway, EM radiology, and Manipal (early)', 8000, calculateRegistrationAmount('PG / Student', ['Advanced Airway', 'EM Radiology'], true, false, new DateTimeImmutable('2026-10-10 17:00:00', new DateTimeZone('Asia/Kolkata')))],
    ['Faculty all workshops (early)', 15000, calculateRegistrationAmount('Faculty / Consultant', ALLOWED_WORKSHOPS, false, false, $early)],
    ['Faculty all workshops plus Manipal (standard)', 19500, calculateRegistrationAmount('Faculty / Consultant', ALLOWED_WORKSHOPS, true, false, $standard)],
    ['PG / Student plus PNB', 8000, calculateRegistrationAmount('PG / Student', [], false, true, $early)],
];

$duplicateWorkshopKeys = workshopSelectionKeys(['workshops' => ['Resuscitology', 'Resuscitology']]);
if ($duplicateWorkshopKeys !== ['Resuscitology']) {
    fwrite(STDERR, "Duplicate workshop selections must reserve only one seat\n");
    exit(1);
}

if (WORKSHOP_CAPACITY['Resuscitology'] !== 15) {
    fwrite(STDERR, "Resuscitology cap should be 15 seats\n");
    exit(1);
}

$firstAvailableId = makeRegistrationId('test-link', []);
$nextAvailableId = makeRegistrationId('test-link-2', [
    ['registrationId' => $firstAvailableId],
]);
if ($firstAvailableId !== 'EMKA2026-132' || $nextAvailableId !== 'EMKA2026-133') {
    fwrite(STDERR, "Registration IDs should resume at EMKA2026-132 and continue sequentially\n");
    exit(1);
}

$capacityRecords = [];
for ($index = 1; $index <= 15; $index++) {
    $capacityRecords[] = [
        'paymentStatus' => 'PAID',
        'email' => 'paid-' . $index . '@example.test',
        'phone' => '900000' . str_pad((string) $index, 4, '0', STR_PAD_LEFT),
        'workshops' => ['Resuscitology'],
    ];
}
for ($index = 0; $index < 4; $index++) {
    $capacityRecords[] = [
        'paymentStatus' => 'PENDING',
        'createdAt' => date(DATE_ATOM),
        'email' => 'retry@example.test',
        'phone' => '9876543210',
        'linkId' => 'retry-' . $index,
        'workshops' => ['Resuscitology'],
    ];
}
$remainingSeats = workshopSeatsRemaining($capacityRecords)['Resuscitology'];
if ($remainingSeats !== 0) {
    fwrite(STDERR, "Expected 0 Resuscitology seats after 15 paid attendees and one attendee with duplicate pending links, got {$remainingSeats}\n");
    exit(1);
}

foreach ($cases as [$label, $expected, $actual]) {
    if ($actual !== $expected) {
        fwrite(STDERR, $label . ': expected ' . $expected . ', got ' . $actual . "\n");
        exit(1);
    }
}

$acknowledgement = buildUserAcknowledgement([
    'registrationId' => 'EMK26-TEST',
    'name' => 'Test Delegate',
    'tier' => 'PG / Student',
    'amount' => 5500,
    'linkId' => 'test-link',
    'workshops' => [],
    'manipalInterest' => true,
    'competitions' => [],
]);
if (!str_contains($acknowledgement['text'], 'Workshops: Medicine in the Wilderness')
    || !str_contains($acknowledgement['html'], 'Medicine in the Wilderness')) {
    fwrite(STDERR, "User acknowledgement omitted the Medicine in the Wilderness selection\n");
    exit(1);
}

echo "Payment pricing tests passed (" . count($cases) . " cases)\n";
