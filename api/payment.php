<?php
declare(strict_types=1);

// API endpoints must return JSON only. Keep PHP diagnostics in the server log
// so a warning can never corrupt a successful response in the browser.
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const DATA_FILE = __DIR__ . '/../data/registrations.json';
const PAYMENT_PROOF_DIR = __DIR__ . '/../data/payment-proofs';
const MAX_PAYMENT_PROOF_BYTES = 5242880;
const EARLY_BIRD_CUTOFF = '2026-09-30 23:59:59';
const ALLOWED_WORKSHOPS = [
    'Advanced Airway',
    'ToxSim',
    'Maternal Resuscitation Programme',
    'Hidden Curriculum in ED',
    'Resuscitology',
    'EM Radiology',
];
const DAY_ONE_WORKSHOPS = [
    'Advanced Airway',
    'ToxSim',
    'Maternal Resuscitation Programme',
];
const DAY_TWO_WORKSHOPS = [
    'Hidden Curriculum in ED',
    'Resuscitology',
    'EM Radiology',
];
// Seat caps. 'El Nino Wilderness Medicine' and 'Peripheral Nerve Block' are the
// Manipal/JSS pre-conference add-ons, tracked as booleans rather than in ALLOWED_WORKSHOPS.
const WORKSHOP_CAPACITY = [
    'Advanced Airway' => 24,
    'Resuscitology' => 18,
    'EM Radiology' => 30,
    'Hidden Curriculum in ED' => 30,
    'Maternal Resuscitation Programme' => 26,
    'ToxSim' => 20,
    'El Nino Wilderness Medicine' => 15,
    'Peripheral Nerve Block' => 28,
];

loadEnv(__DIR__ . '/../.env');
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/google-sheets.php';

if (!defined('EMK_PAYMENT_SKIP_ROUTER')) {
    try {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $action = $_GET['action'] ?? ($method === 'POST' ? 'create' : 'status');

        if ($method === 'POST' && $action === 'webhook') {
            handleCashfreeWebhook();
        } elseif ($method === 'POST' && $action === 'create') {
            createPaymentLink();
        } elseif ($method === 'POST' && $action === 'upload-proof') {
            uploadPaymentProof();
        } elseif ($method === 'GET' && $action === 'status') {
            checkPaymentStatus();
        } elseif ($method === 'GET' && $action === 'capacity') {
            respond(200, ['ok' => true, 'seats' => workshopSeatsRemaining()]);
        } else {
            respond(405, ['ok' => false, 'message' => 'Method not allowed.']);
        }
    } catch (Throwable $error) {
        error_log('EMK payment error: ' . $error->getMessage());
        respond(500, ['ok' => false, 'message' => 'Payment service is temporarily unavailable. Please try again.']);
    }
}

function createPaymentLink(): void
{
    requireCredentials();
    $input = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input)) {
        respond(400, ['ok' => false, 'message' => 'Invalid request body.']);
    }

    $registration = validateRegistration($input);
    $linkId = 'EMK26_' . gmdate('Ymd_His') . '_' . strtoupper(bin2hex(random_bytes(3)));
    $returnUrl = currentPageUrl() . '?payment_return=1&link_id=' . rawurlencode($linkId) . '#register';

    $payload = [
        'link_id' => $linkId,
        'link_amount' => $registration['amount'],
        'link_currency' => 'INR',
        'link_purpose' => 'EM Karnataka 2026 registration - ' . $registration['tier'],
        'link_expiry_time' => date(DATE_ATOM, time() + 3600),
        'link_partial_payments' => false,
        'link_auto_reminders' => false,
        'customer_details' => [
            'customer_name' => $registration['name'],
            'customer_email' => $registration['email'],
            'customer_phone' => $registration['phone'],
        ],
        'link_meta' => [
            'return_url' => $returnUrl,
            'notify_url' => paymentApiUrl() . '?action=webhook',
            'upi_intent' => false,
        ],
        'link_notify' => [
            'send_email' => false,
            'send_sms' => false,
            'send_whatsapp' => false,
        ],
        'link_notes' => [
            'tier' => $registration['tier'],
            'source' => 'EMK26 website',
        ],
    ];

    $result = cashfreeRequest('POST', '/links', $payload);
    if ($result['status'] < 200 || $result['status'] >= 300) {
        $message = isset($result['body']['message']) ? (string) $result['body']['message'] : 'Cashfree could not create the payment link.';
        respond(502, ['ok' => false, 'message' => $message]);
    }

    $body = $result['body'];
    if (empty($body['link_url']) || empty($body['link_qrcode'])) {
        respond(502, ['ok' => false, 'message' => 'Cashfree returned an incomplete payment link.']);
    }

    $uploadToken = bin2hex(random_bytes(24));
    $registration['linkId'] = $linkId;
    $registration['linkUrl'] = $body['link_url'];
    $registration['uploadTokenHash'] = hash('sha256', $uploadToken);
    $registration['paymentStatus'] = 'PENDING';
    $registration['createdAt'] = date(DATE_ATOM);
    saveRegistration($linkId, $registration);

    respond(200, [
        'ok' => true,
        'linkId' => $linkId,
        'linkUrl' => $body['link_url'],
        'qrCode' => $body['link_qrcode'],
        'amount' => $registration['amount'],
        'uploadToken' => $uploadToken,
        'expiresAt' => $body['link_expiry_time'] ?? null,
    ]);
}

function uploadPaymentProof(): void
{
    $linkId = (string) ($_POST['link_id'] ?? '');
    $uploadToken = (string) ($_POST['upload_token'] ?? '');
    if (!preg_match('/^EMK26_[A-Za-z0-9_]{8,50}$/', $linkId) || $uploadToken === '') {
        respond(400, ['ok' => false, 'message' => 'Invalid payment reference.']);
    }

    $records = readRegistrations();
    $record = $records[$linkId] ?? null;
    $expectedTokenHash = is_array($record) ? (string) ($record['uploadTokenHash'] ?? '') : '';
    if ($expectedTokenHash === '' || !hash_equals($expectedTokenHash, hash('sha256', $uploadToken))) {
        respond(403, ['ok' => false, 'message' => 'This upload link is invalid or expired.']);
    }
    if (!empty($record['paymentProof']['storedAt'])) {
        $notified = sendPaymentProofToAdmin($linkId);
        respond(200, ['ok' => true, 'alreadyUploaded' => true, 'adminNotified' => $notified]);
    }

    $file = $_FILES['payment_screenshot'] ?? null;
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $message = in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'The payment screenshot exceeds the server upload limit.'
            : 'Choose a payment screenshot to upload.';
        respond(422, ['ok' => false, 'message' => $message]);
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > MAX_PAYMENT_PROOF_BYTES) {
        respond(422, ['ok' => false, 'message' => 'The payment screenshot must be 5 MB or smaller.']);
    }
    if (!function_exists('finfo_open')) {
        respond(500, ['ok' => false, 'message' => 'Secure file inspection is unavailable on the server.']);
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string) finfo_file($finfo, (string) $file['tmp_name']) : '';
    if ($finfo) finfo_close($finfo);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];
    if (!isset($allowed[$mime])) {
        respond(422, ['ok' => false, 'message' => 'Only JPG, PNG, WebP, and PDF payment proofs are allowed.']);
    }

    if (!is_dir(PAYMENT_PROOF_DIR) && !mkdir(PAYMENT_PROOF_DIR, 0770, true) && !is_dir(PAYMENT_PROOF_DIR)) {
        throw new RuntimeException('Could not create payment-proof storage.');
    }
    $storedName = $linkId . '.' . $allowed[$mime];
    $destination = PAYMENT_PROOF_DIR . '/' . $storedName;
    if (!move_uploaded_file((string) $file['tmp_name'], $destination)) {
        throw new RuntimeException('Could not store the uploaded payment proof.');
    }
    @chmod($destination, 0660);

    mutateRegistrations(function (array &$allRecords) use ($linkId, $storedName, $mime, $size, $file): void {
        if (!isset($allRecords[$linkId])) return;
        $allRecords[$linkId]['paymentProof'] = [
            'storedName' => $storedName,
            'originalName' => cleanText(basename((string) ($file['name'] ?? 'payment-proof')), 160),
            'mimeType' => $mime,
            'size' => $size,
            'storedAt' => date(DATE_ATOM),
        ];
    });

    $notified = sendPaymentProofToAdmin($linkId);
    respond(200, ['ok' => true, 'adminNotified' => $notified]);
}

/** Admin notifications may go to more than one mailbox — ADMIN_EMAIL accepts a comma-separated list. */
function adminRecipients(): array
{
    $adminName = trim((string) env('ADMIN_NAME', 'Registration Admin'));
    $emails = array_filter(array_map('trim', explode(',', (string) env('ADMIN_EMAIL', ''))));
    $recipients = [];
    foreach ($emails as $email) {
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) $recipients[] = ['email' => $email, 'name' => $adminName];
    }
    return $recipients;
}

function sendPaymentProofToAdmin(string $linkId): bool
{
    $record = readRegistrations()[$linkId] ?? null;
    if (!is_array($record) || empty($record['paymentProof']['storedName'])) return false;
    if (($record['paymentProof']['adminEmailStatus'] ?? '') === 'SENT') return true;

    $recipients = adminRecipients();
    if ($recipients === []) return false;

    $proof = $record['paymentProof'];
    $path = PAYMENT_PROOF_DIR . '/' . basename((string) $proof['storedName']);
    try {
        $subject = 'Payment proof uploaded: ' . $record['name'] . ' - ' . $linkId;
        $rows = [
            'Name' => (string) $record['name'],
            'Email' => (string) $record['email'],
            'Phone' => (string) $record['phone'],
            'Expected amount' => 'INR ' . number_format((float) $record['amount'], 0, '.', ','),
            'Payment reference' => $linkId,
            'Cashfree status' => (string) ($record['paymentStatus'] ?? 'PENDING'),
            'Uploaded at' => (string) ($proof['storedAt'] ?? ''),
        ];
        $html = emailLayout(
            'Backup payment proof received',
            '<p style="margin:0 0 18px">A delegate uploaded backup payment proof because automatic confirmation may be delayed. Verify it against Cashfree before manually confirming anything.</p>'
            . emailDetailTable($rows)
        );
        $text = "Backup payment proof received. Verify it against Cashfree before manually confirming anything.\n\n" . textDetails($rows);
        $attachments = [[
            'path' => $path,
            'name' => (string) ($proof['originalName'] ?? basename($path)),
            'mimeType' => (string) ($proof['mimeType'] ?? 'application/octet-stream'),
        ]];
        foreach ($recipients as $recipient) {
            sendSmtpMail($recipient['email'], $recipient['name'], $subject, $html, $text, $attachments);
        }
        mutateRegistrations(function (array &$records) use ($linkId): void {
            if (!isset($records[$linkId]['paymentProof'])) return;
            $records[$linkId]['paymentProof']['adminEmailStatus'] = 'SENT';
            $records[$linkId]['paymentProof']['adminEmailedAt'] = date(DATE_ATOM);
            unset($records[$linkId]['paymentProof']['adminEmailError']);
        });
        return true;
    } catch (Throwable $error) {
        error_log('EMK payment proof email failed for ' . $linkId . ': ' . $error->getMessage());
        mutateRegistrations(function (array &$records) use ($linkId, $error): void {
            if (!isset($records[$linkId]['paymentProof'])) return;
            $records[$linkId]['paymentProof']['adminEmailStatus'] = 'FAILED';
            $records[$linkId]['paymentProof']['adminEmailError'] = cleanText($error->getMessage(), 180);
        });
        return false;
    }
}

function handleCashfreeWebhook(): void
{
    requireCredentials();
    $rawBody = (string) file_get_contents('php://input');
    $timestamp = (string) ($_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? '');
    $signature = (string) ($_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '');
    if ($rawBody === '' || $timestamp === '' || $signature === '') {
        respond(400, ['ok' => false, 'message' => 'Missing webhook signature.']);
    }

    $timestampSeconds = ((int) $timestamp) > 9999999999 ? ((int) $timestamp) / 1000 : (int) $timestamp;
    if ($timestampSeconds <= 0 || abs(time() - $timestampSeconds) > 300) {
        respond(400, ['ok' => false, 'message' => 'Expired webhook timestamp.']);
    }
    $expectedSignature = base64_encode(hash_hmac('sha256', $timestamp . $rawBody, (string) env('CASHFREE_SECRET_KEY'), true));
    if (!hash_equals($expectedSignature, $signature)) {
        respond(401, ['ok' => false, 'message' => 'Invalid webhook signature.']);
    }

    $payload = json_decode($rawBody, true);
    $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
    $linkId = (string) ($data['link_id'] ?? '');
    if (!preg_match('/^EMK26_[A-Za-z0-9_]{8,50}$/', $linkId)) {
        respond(200, ['ok' => true, 'ignored' => true]);
    }

    $records = readRegistrations();
    if (!isset($records[$linkId])) {
        respond(200, ['ok' => true, 'ignored' => true]);
    }

    $status = strtoupper((string) ($data['link_status'] ?? ''));
    $paidAmount = (float) ($data['link_amount_paid'] ?? 0);
    $expectedAmount = (float) $records[$linkId]['amount'];
    if ($status === 'PAID' && $paidAmount >= $expectedAmount) {
        if (($records[$linkId]['paymentStatus'] ?? '') !== 'PAID') markRegistrationPaid($linkId);
        sendRegistrationNotifications($linkId);
        syncRegistrationToGoogleSheet($linkId);
    }

    respond(200, ['ok' => true]);
}

function checkPaymentStatus(): void
{
    $linkId = (string) ($_GET['link_id'] ?? '');
    if (!preg_match('/^EMK26_[A-Za-z0-9_]{8,50}$/', $linkId)) {
        respond(400, ['ok' => false, 'message' => 'Invalid payment reference.']);
    }

    $records = readRegistrations();
    if (!isset($records[$linkId])) {
        respond(404, ['ok' => false, 'message' => 'Registration payment was not found.']);
    }

    // A verified Cashfree webhook may already have confirmed this registration.
    // Do not turn a completed payment back into an error if Cashfree's status API
    // is temporarily unavailable during a later browser poll.
    if (($records[$linkId]['paymentStatus'] ?? '') === 'PAID') {
        $emailStatus = sendRegistrationNotifications($linkId);
        $sheetTrackingEnabled = googleSheetsConfigured();
        $sheetSynced = $sheetTrackingEnabled ? syncRegistrationToGoogleSheet($linkId) : false;
        $records = readRegistrations();
        respond(200, [
            'ok' => true,
            'paid' => true,
            'status' => 'PAID',
            'registrationId' => $records[$linkId]['registrationId'] ?? null,
            'confirmation' => paymentConfirmation($records[$linkId]),
            'receiptEmailSent' => $emailStatus['user'],
            'adminEmailSent' => $emailStatus['admin'],
            'sheetTrackingEnabled' => $sheetTrackingEnabled,
            'sheetSynced' => $sheetSynced,
        ]);
    }

    // Only a still-pending payment requires a live Cashfree API request.
    requireCredentials();
    $result = cashfreeRequest('GET', '/links/' . rawurlencode($linkId));
    if ($result['status'] < 200 || $result['status'] >= 300) {
        respond(502, ['ok' => false, 'message' => 'Could not verify payment yet.']);
    }

    $details = $result['body'];
    $expected = (float) $records[$linkId]['amount'];
    $paidAmount = (float) ($details['link_amount_paid'] ?? 0);
    $cashfreeStatus = strtoupper((string) ($details['link_status'] ?? 'ACTIVE'));
    $paid = $cashfreeStatus === 'PAID' && $paidAmount >= $expected;

    if ($paid && ($records[$linkId]['paymentStatus'] ?? '') !== 'PAID') {
        markRegistrationPaid($linkId);
        $records = readRegistrations();
    }

    $emailStatus = $paid ? sendRegistrationNotifications($linkId) : ['user' => false, 'admin' => false];
    $sheetTrackingEnabled = googleSheetsConfigured();
    $sheetSynced = $paid && $sheetTrackingEnabled ? syncRegistrationToGoogleSheet($linkId) : false;
    if ($paid) $records = readRegistrations();

    respond(200, [
        'ok' => true,
        'paid' => $paid,
        'status' => $paid ? 'PAID' : $cashfreeStatus,
        'registrationId' => $paid ? $records[$linkId]['registrationId'] : null,
        'confirmation' => $paid ? paymentConfirmation($records[$linkId]) : null,
        'receiptEmailSent' => $emailStatus['user'],
        'adminEmailSent' => $emailStatus['admin'],
        'sheetTrackingEnabled' => $sheetTrackingEnabled,
        'sheetSynced' => $sheetSynced,
    ]);
}

/** Details displayed only to the holder of the unguessable Cashfree link reference. */
function paymentConfirmation(array $record): array
{
    return [
        'registrationId' => (string) ($record['registrationId'] ?? ''),
        'name' => (string) ($record['name'] ?? ''),
        'email' => (string) ($record['email'] ?? ''),
        'tier' => (string) ($record['tier'] ?? ''),
        'workshops' => array_values($record['workshops'] ?? []),
        'amount' => (int) ($record['amount'] ?? 0),
        'paidAt' => (string) ($record['paidAt'] ?? ''),
        'status' => (string) ($record['paymentStatus'] ?? 'PENDING'),
    ];
}

function validateRegistration(array $input): array
{
    $name = cleanText($input['name'] ?? '', 120);
    $email = filter_var(trim((string) ($input['email'] ?? '')), FILTER_VALIDATE_EMAIL);
    $phone = preg_replace('/\D+/', '', (string) ($input['phone'] ?? ''));
    $designation = cleanText($input['designation'] ?? '', 120);
    $institution = cleanText($input['institution'] ?? '', 180);
    $regno = cleanText($input['regno'] ?? '', 80);
    $council = cleanText($input['council'] ?? '', 120);
    $category = cleanText($input['category'] ?? '', 40);
    $diet = cleanText($input['diet'] ?? '', 40);
    $tier = (string) ($input['tier'] ?? '');

    if ($name === '' || !$email || !preg_match('/^[6-9]\d{9}$/', $phone)) {
        respond(422, ['ok' => false, 'message' => 'Enter a valid name, email, and 10-digit Indian mobile number.']);
    }
    if ($designation === '' || $institution === '' || $regno === '' || $council === '') {
        respond(422, ['ok' => false, 'message' => 'Complete all required professional details.']);
    }
    if (!in_array($category, ['PG Student', 'Consultant'], true) || !in_array($diet, ['Vegetarian', 'Non-vegetarian', 'Jain'], true)) {
        respond(422, ['ok' => false, 'message' => 'Select a valid category and dietary preference.']);
    }
    if (!in_array($tier, ['PG / Student', 'Faculty / Consultant'], true)) {
        respond(422, ['ok' => false, 'message' => 'Select a valid registration tier.']);
    }

    $workshops = array_values(array_unique(array_filter(
        is_array($input['workshops'] ?? null) ? $input['workshops'] : [],
        fn($value) => is_string($value) && in_array($value, ALLOWED_WORKSHOPS, true)
    )));
    if (count($workshops) > 2 || count(array_intersect($workshops, DAY_ONE_WORKSHOPS)) > 1 || count(array_intersect($workshops, DAY_TWO_WORKSHOPS)) > 1) {
        respond(422, ['ok' => false, 'message' => 'You may select at most one workshop per day.']);
    }

    $manipalInterest = !empty($input['manipalInterest']);
    $pnbInterest = !empty($input['pnbInterest']);

    $selectedCapped = $workshops;
    if ($manipalInterest) $selectedCapped[] = 'El Nino Wilderness Medicine';
    if ($pnbInterest) $selectedCapped[] = 'Peripheral Nerve Block';
    $seatsLeft = workshopSeatsRemaining();
    foreach ($selectedCapped as $key) {
        if (isset($seatsLeft[$key]) && $seatsLeft[$key] <= 0) {
            respond(409, ['ok' => false, 'message' => "Sorry, \"$key\" is fully booked. Please choose a different workshop or continue without it."]);
        }
    }

    $amount = calculateRegistrationAmount($tier, $workshops, $manipalInterest, $pnbInterest);

    $competitions = array_values(array_intersect(
        is_array($input['competitions'] ?? null) ? $input['competitions'] : [],
        ['CPC', 'Quiz', 'Paper', 'Poster']
    ));

    return [
        'name' => $name,
        'email' => (string) $email,
        'phone' => $phone,
        'designation' => $designation,
        'institution' => $institution,
        'regno' => $regno,
        'council' => $council,
        'category' => $category,
        'diet' => $diet,
        'tier' => $tier,
        'workshops' => $workshops,
        'manipalInterest' => $manipalInterest,
        'pnbInterest' => $pnbInterest,
        'competitions' => $competitions,
        'amount' => $amount,
    ];
}

/** Registrations that still hold a seat: paid, or a pending Cashfree link within its 1-hour expiry. */
function isActiveHold(array $record): bool
{
    $status = (string) ($record['paymentStatus'] ?? '');
    if ($status === 'PAID') return true;
    if ($status !== 'PENDING') return false;
    $created = strtotime((string) ($record['createdAt'] ?? ''));
    return $created !== false && $created >= time() - 3600;
}

function workshopSelectionKeys(array $record): array
{
    $keys = array_values(array_filter(
        is_array($record['workshops'] ?? null) ? $record['workshops'] : [],
        fn($value) => is_string($value)
    ));
    if (!empty($record['manipalInterest'])) $keys[] = 'El Nino Wilderness Medicine';
    if (!empty($record['pnbInterest'])) $keys[] = 'Peripheral Nerve Block';
    return $keys;
}

function workshopSeatsRemaining(): array
{
    $taken = array_fill_keys(array_keys(WORKSHOP_CAPACITY), 0);
    foreach (readRegistrations() as $record) {
        if (!is_array($record) || !isActiveHold($record)) continue;
        foreach (workshopSelectionKeys($record) as $key) {
            if (isset($taken[$key])) $taken[$key]++;
        }
    }
    $remaining = [];
    foreach (WORKSHOP_CAPACITY as $key => $capacity) {
        $remaining[$key] = max(0, $capacity - $taken[$key]);
    }
    return $remaining;
}

function calculateRegistrationAmount(string $tier, array $workshops, bool $manipalInterest, bool $pnbInterest = false, ?DateTimeImmutable $now = null): int
{
    $now ??= new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));
    $cutoff = new DateTimeImmutable(EARLY_BIRD_CUTOFF, new DateTimeZone('Asia/Kolkata'));
    $earlyBird = $now <= $cutoff;
    $amount = $tier === 'Faculty / Consultant' ? 7000 : 4000;

    // Every tier pays per selected workshop at the early/standard rate shown on the form.
    $prices = [
        'Advanced Airway' => [1000, 1500],
        'ToxSim' => [1000, 1500],
        'Maternal Resuscitation Programme' => [1500, 2000],
        'Hidden Curriculum in ED' => [1500, 2000],
        'Resuscitology' => [1500, 2000],
        'EM Radiology' => [1500, 2000],
    ];
    foreach ($workshops as $workshop) {
        if (isset($prices[$workshop])) $amount += $prices[$workshop][$earlyBird ? 0 : 1];
    }
    if ($manipalInterest) $amount += 1500;
    if ($pnbInterest) $amount += 4000;
    return $amount;
}

function cashfreeRequest(string $method, string $path, ?array $payload = null): array
{
    $base = strtolower((string) env('CASHFREE_ENV', 'sandbox')) === 'production'
        ? 'https://api.cashfree.com/pg'
        : 'https://sandbox.cashfree.com/pg';
    $curl = curl_init($base . $path);
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'x-api-version: ' . env('CASHFREE_API_VERSION', '2025-01-01'),
        'x-client-id: ' . env('CASHFREE_APP_ID'),
        'x-client-secret: ' . env('CASHFREE_SECRET_KEY'),
        'x-request-id: ' . bin2hex(random_bytes(12)),
    ];
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($payload !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
    }
    $raw = curl_exec($curl);
    if ($raw === false) {
        $message = curl_error($curl);
        curl_close($curl);
        throw new RuntimeException('Cashfree connection failed: ' . $message);
    }
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    $body = json_decode($raw, true);
    return ['status' => $status, 'body' => is_array($body) ? $body : []];
}

function readRegistrations(): array
{
    if (!is_file(DATA_FILE)) return [];
    $handle = fopen(DATA_FILE, 'rb');
    if (!$handle || !flock($handle, LOCK_SH)) return [];
    $raw = stream_get_contents($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    $decoded = json_decode($raw ?: '{}', true);
    return is_array($decoded) ? $decoded : [];
}

function saveRegistration(string $linkId, array $registration): void
{
    mutateRegistrations(function (array &$records) use ($linkId, $registration): void {
        $records[$linkId] = $registration;
    });
}

function markRegistrationPaid(string $linkId): void
{
    mutateRegistrations(function (array &$records) use ($linkId): void {
        if (!isset($records[$linkId])) return;
        $records[$linkId]['paymentStatus'] = 'PAID';
        $records[$linkId]['paidAt'] = date(DATE_ATOM);
        $records[$linkId]['registrationId'] = $records[$linkId]['registrationId'] ?? makeRegistrationId($linkId, $records);
    });
}

function sendRegistrationNotifications(string $linkId): array
{
    $record = readRegistrations()[$linkId] ?? null;
    if (!is_array($record)) return ['user' => false, 'admin' => false];

    $recipients = adminRecipients();
    $userSent = notificationIsSent($record, 'user');
    $adminSent = notificationIsSent($record, 'admin');

    if (!$userSent && claimNotification($linkId, 'user')) {
        try {
            $mail = buildUserAcknowledgement($record);
            sendSmtpMail($record['email'], $record['name'], $mail['subject'], $mail['html'], $mail['text']);
            finishNotification($linkId, 'user', true);
            $userSent = true;
        } catch (Throwable $error) {
            error_log('EMK user receipt email failed for ' . $linkId . ': ' . $error->getMessage());
            finishNotification($linkId, 'user', false, $error->getMessage());
        }
    }

    if (!$adminSent && $recipients === []) {
        finishNotificationConfigurationError($linkId, 'admin', 'ADMIN_EMAIL is not configured.');
    } elseif (!$adminSent && claimNotification($linkId, 'admin')) {
        try {
            $mail = buildAdminNotification($record);
            foreach ($recipients as $recipient) {
                sendSmtpMail($recipient['email'], $recipient['name'], $mail['subject'], $mail['html'], $mail['text']);
            }
            finishNotification($linkId, 'admin', true);
            $adminSent = true;
        } catch (Throwable $error) {
            error_log('EMK admin notification email failed for ' . $linkId . ': ' . $error->getMessage());
            finishNotification($linkId, 'admin', false, $error->getMessage());
        }
    }

    return ['user' => $userSent, 'admin' => $adminSent];
}

function notificationIsSent(array $record, string $type): bool
{
    return ($record['notifications'][$type]['status'] ?? '') === 'SENT';
}

function claimNotification(string $linkId, string $type): bool
{
    $claimed = false;
    mutateRegistrations(function (array &$records) use ($linkId, $type, &$claimed): void {
        if (!isset($records[$linkId])) return;
        $notification = $records[$linkId]['notifications'][$type] ?? [];
        $status = (string) ($notification['status'] ?? 'PENDING');
        $attempts = (int) ($notification['attempts'] ?? 0);
        $lastAttempt = isset($notification['lastAttemptAt']) ? strtotime((string) $notification['lastAttemptAt']) : false;
        $processingExpired = $status === 'PROCESSING' && (!$lastAttempt || $lastAttempt < time() - 120);
        $retryReady = $status === 'FAILED' && (!$lastAttempt || $lastAttempt < time() - 15);

        if ($status === 'SENT' || $attempts >= 5 || ($status === 'PROCESSING' && !$processingExpired) || ($status === 'FAILED' && !$retryReady)) return;

        $records[$linkId]['notifications'][$type] = [
            'status' => 'PROCESSING',
            'attempts' => $attempts + 1,
            'lastAttemptAt' => date(DATE_ATOM),
        ];
        $claimed = true;
    });
    return $claimed;
}

function finishNotification(string $linkId, string $type, bool $sent, string $error = ''): void
{
    mutateRegistrations(function (array &$records) use ($linkId, $type, $sent, $error): void {
        if (!isset($records[$linkId])) return;
        $current = $records[$linkId]['notifications'][$type] ?? [];
        $current['status'] = $sent ? 'SENT' : 'FAILED';
        if ($sent) {
            $current['sentAt'] = date(DATE_ATOM);
            unset($current['error']);
        } else {
            $current['error'] = cleanText($error, 180);
        }
        $records[$linkId]['notifications'][$type] = $current;
    });
}

function finishNotificationConfigurationError(string $linkId, string $type, string $error): void
{
    mutateRegistrations(function (array &$records) use ($linkId, $type, $error): void {
        if (!isset($records[$linkId])) return;
        $records[$linkId]['notifications'][$type] = [
            'status' => 'FAILED',
            'attempts' => 5,
            'lastAttemptAt' => date(DATE_ATOM),
            'error' => $error,
        ];
    });
}

function buildUserAcknowledgement(array $record): array
{
    $registrationId = (string) $record['registrationId'];
    $amount = '₹' . number_format((float) $record['amount'], 0, '.', ',');
    $workshops = formatList($record['workshops'] ?? [], 'Conference only');
    $competitions = formatList($record['competitions'] ?? [], 'None selected');
    $subject = 'Registration confirmed — ' . $registrationId . ' | EM Karnataka 2026';
    $rows = [
        'Registration ID' => $registrationId,
        'Registration type' => (string) $record['tier'],
        'Amount paid' => $amount,
        'Workshops' => $workshops,
        'Competition interests' => $competitions,
        'Payment reference' => (string) $record['linkId'],
    ];
    $html = emailLayout(
        'Registration confirmed',
        '<p style="margin:0 0 18px">Dear ' . emailEscape($record['name']) . ',</p>'
        . '<p style="margin:0 0 18px">Thank you for registering for <strong>EM Karnataka 2026</strong>. Your payment was received successfully and your registration is confirmed.</p>'
        . emailDetailTable($rows)
        . '<p style="margin:20px 0 0">Please keep your Registration ID available for abstract submissions and conference check-in.</p>'
    );
    $text = "Dear {$record['name']},\n\nYour payment was received successfully and your EM Karnataka 2026 registration is confirmed.\n\n"
        . textDetails($rows) . "\nPlease keep your Registration ID for abstract submissions and conference check-in.\n";
    return compact('subject', 'html', 'text');
}

function buildAdminNotification(array $record): array
{
    $registrationId = (string) $record['registrationId'];
    $subject = 'New paid registration: ' . $record['name'] . ' — ' . $registrationId;
    $rows = [
        'Registration ID' => $registrationId,
        'Name' => (string) $record['name'],
        'Email' => (string) $record['email'],
        'Phone' => (string) $record['phone'],
        'Designation' => (string) $record['designation'],
        'Institution' => (string) $record['institution'],
        'Medical council' => (string) $record['council'],
        'Council registration no.' => (string) $record['regno'],
        'Category' => (string) $record['category'],
        'Diet' => (string) $record['diet'],
        'Registration type' => (string) $record['tier'],
        'Workshops' => formatList($record['workshops'] ?? [], 'Conference only'),
        'Manipal interest' => !empty($record['manipalInterest']) ? 'Yes' : 'No',
        'Peripheral nerve block' => !empty($record['pnbInterest']) ? 'Yes' : 'No',
        'Competitions' => formatList($record['competitions'] ?? [], 'None selected'),
        'Amount paid' => '₹' . number_format((float) $record['amount'], 0, '.', ','),
        'Payment reference' => (string) $record['linkId'],
        'Backup payment proof' => !empty($record['paymentProof']['storedAt']) ? 'Received' : 'Not uploaded',
        'Paid at' => (string) ($record['paidAt'] ?? ''),
    ];
    $html = emailLayout(
        'New paid registration',
        '<p style="margin:0 0 18px">Cashfree has confirmed a new EM Karnataka 2026 registration.</p>' . emailDetailTable($rows)
    );
    $text = "Cashfree has confirmed a new EM Karnataka 2026 registration.\n\n" . textDetails($rows);
    return compact('subject', 'html', 'text');
}

function emailLayout(string $heading, string $content): string
{
    return '<!doctype html><html><body style="margin:0;background:#f3f4f6;font-family:Arial,sans-serif;color:#172033">'
        . '<div style="max-width:640px;margin:0 auto;padding:28px 14px"><div style="background:#0d1b2a;padding:22px 26px;border-radius:12px 12px 0 0;color:#d4a017">'
        . '<div style="font-size:13px;letter-spacing:.7px;text-transform:uppercase">EM Karnataka 2026</div><h1 style="font-size:24px;margin:6px 0 0;color:#fff">' . emailEscape($heading) . '</h1></div>'
        . '<div style="background:#fff;padding:26px;border-radius:0 0 12px 12px;line-height:1.6">' . $content . '</div>'
        . '<p style="text-align:center;color:#6b7280;font-size:11px">12–15 November 2026 · Father Muller Medical College, Mangaluru</p></div></body></html>';
}

function emailDetailTable(array $rows): string
{
    $html = '<table role="presentation" style="width:100%;border-collapse:collapse;font-size:14px">';
    foreach ($rows as $label => $value) {
        $html .= '<tr><td style="padding:9px 8px;border-bottom:1px solid #e5e7eb;color:#6b7280;width:38%;vertical-align:top">' . emailEscape((string) $label) . '</td>'
            . '<td style="padding:9px 8px;border-bottom:1px solid #e5e7eb;font-weight:600;vertical-align:top">' . emailEscape((string) $value) . '</td></tr>';
    }
    return $html . '</table>';
}

function textDetails(array $rows): string
{
    $text = '';
    foreach ($rows as $label => $value) $text .= $label . ': ' . $value . "\n";
    return $text;
}

function formatList(mixed $items, string $empty): string
{
    return is_array($items) && $items !== [] ? implode(', ', array_map('strval', $items)) : $empty;
}

function emailEscape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mutateRegistrations(callable $mutator): void
{
    $directory = dirname(DATA_FILE);
    if (!is_dir($directory)) mkdir($directory, 0770, true);
    $handle = fopen(DATA_FILE, 'c+');
    if (!$handle || !flock($handle, LOCK_EX)) throw new RuntimeException('Unable to lock registration storage.');
    $raw = stream_get_contents($handle);
    $records = json_decode($raw ?: '{}', true);
    if (!is_array($records)) $records = [];
    $mutator($records);
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
}

function makeRegistrationId(string $linkId, ?array $records = null): string
{
    $allRecords = is_array($records) ? $records : readRegistrations();
    $maxSequence = 0;

    foreach ($allRecords as $record) {
        $existingId = trim((string) ($record['registrationId'] ?? ''));
        if (preg_match('/^EMKA2026-(\d{3,})$/i', $existingId, $matches) === 1) {
            $maxSequence = max($maxSequence, (int) $matches[1]);
        }
    }

    return sprintf('EMKA2026-%03d', $maxSequence + 1);
}

function currentPageUrl(): string
{
    $configuredUrl = rtrim((string) env('APP_URL', ''), '/');
    if ($configuredUrl !== '') return $configuredUrl . '/index.html';

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/api/payment.php'));
    $basePath = preg_replace('#/api/payment\.php$#', '', $script);
    return $scheme . '://' . $host . $basePath . '/index.html';
}

function paymentApiUrl(): string
{
    $configuredUrl = rtrim((string) env('APP_URL', ''), '/');
    if ($configuredUrl !== '') return $configuredUrl . '/api/payment.php';

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/api/payment.php'));
    return $scheme . '://' . $host . $script;
}

function requireCredentials(): void
{
    if (!env('CASHFREE_APP_ID') || !env('CASHFREE_SECRET_KEY')) {
        respond(503, ['ok' => false, 'message' => 'Cashfree is not configured. Copy .env.example to .env and add new credentials.']);
    }
    if (!function_exists('curl_init')) {
        respond(500, ['ok' => false, 'message' => 'The PHP cURL extension is required.']);
    }
}

function cleanText(mixed $value, int $maxLength): string
{
    $text = trim(strip_tags((string) $value));
    return function_exists('mb_substr') ? mb_substr($text, 0, $maxLength) : substr($text, 0, $maxLength);
}

function loadEnv(string $path): void
{
    if (!is_file($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $value = trim($value, "\"'");
        if (getenv($key) === false) putenv($key . '=' . $value);
    }
}

function env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}
