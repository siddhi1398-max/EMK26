<?php
declare(strict_types=1);

const GOOGLE_SHEETS_SCOPE = 'https://www.googleapis.com/auth/spreadsheets';

function syncRegistrationToGoogleSheet(string $linkId): bool
{
    $records = readRegistrations();
    $record = $records[$linkId] ?? null;
    if (!is_array($record)) return false;
    if (($record['googleSheets']['status'] ?? '') === 'SYNCED') return true;
    if (!googleSheetsConfigured()) return false;
    if (!claimGoogleSheetsSync($linkId)) return false;

    try {
        $credentials = googleServiceAccountCredentials();
        $token = googleServiceAccountToken($credentials);
        $spreadsheetId = trim((string) env('GOOGLE_SHEETS_SPREADSHEET_ID', ''));
        $range = trim((string) env('GOOGLE_SHEETS_RANGE', 'Registrations!A:W'));
        $existing = googleSheetsRequest(
            'GET',
            'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($spreadsheetId) . '/values/' . rawurlencode(firstColumnRange($range)),
            $token
        );
        if ($existing['status'] < 200 || $existing['status'] >= 300) {
            throw new RuntimeException(googleApiError($existing, 'Could not read the Google Sheet.'));
        }

        $registrationId = (string) ($record['registrationId'] ?? '');
        $values = is_array($existing['body']['values'] ?? null) ? $existing['body']['values'] : [];
        foreach ($values as $row) {
            if ((string) ($row[0] ?? '') === $registrationId) {
                finishGoogleSheetsSync($linkId, true, '', true);
                return true;
            }
        }

        $rows = [];
        if ($values === []) $rows[] = googleSheetsHeaders();
        $rows[] = googleSheetsRegistrationRow($record);
        $appendUrl = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($spreadsheetId)
            . '/values/' . rawurlencode($range) . ':append?valueInputOption=RAW&insertDataOption=INSERT_ROWS';
        $append = googleSheetsRequest('POST', $appendUrl, $token, ['majorDimension' => 'ROWS', 'values' => $rows]);
        if ($append['status'] < 200 || $append['status'] >= 300) {
            throw new RuntimeException(googleApiError($append, 'Could not append the registration to Google Sheets.'));
        }

        finishGoogleSheetsSync($linkId, true);
        return true;
    } catch (Throwable $error) {
        error_log('EMK Google Sheets sync failed for ' . $linkId . ': ' . $error->getMessage());
        finishGoogleSheetsSync($linkId, false, $error->getMessage());
        return false;
    }
}

function googleSheetsConfigured(): bool
{
    if (strtolower((string) env('GOOGLE_SHEETS_ENABLED', 'false')) !== 'true') return false;
    $spreadsheetId = trim((string) env('GOOGLE_SHEETS_SPREADSHEET_ID', ''));
    if ($spreadsheetId === '' || str_starts_with(strtolower($spreadsheetId), 'replace_')) return false;
    return is_file(googleServiceAccountFile());
}

function googleServiceAccountFile(): string
{
    $configured = trim((string) env('GOOGLE_SERVICE_ACCOUNT_FILE', ''));
    if ($configured === '') return __DIR__ . '/../config/google-service-account.json';
    if (preg_match('~^[A-Za-z]:[\\/]~', $configured) === 1 || str_starts_with($configured, '/')) return $configured;
    return dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', $configured), '/');
}

function googleServiceAccountCredentials(): array
{
    $decoded = json_decode((string) file_get_contents(googleServiceAccountFile()), true);
    if (!is_array($decoded) || empty($decoded['client_email']) || empty($decoded['private_key'])) {
        throw new RuntimeException('The Google service-account JSON file is invalid.');
    }
    return $decoded;
}

function googleServiceAccountToken(array $credentials): string
{
    $now = time();
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    if (!empty($credentials['private_key_id'])) $header['kid'] = $credentials['private_key_id'];
    $claims = [
        'iss' => $credentials['client_email'],
        'scope' => GOOGLE_SHEETS_SCOPE,
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600,
    ];
    $unsigned = base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES)) . '.'
        . base64UrlEncode(json_encode($claims, JSON_UNESCAPED_SLASHES));
    $privateKey = openssl_pkey_get_private((string) $credentials['private_key']);
    if (!$privateKey || !openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Could not sign the Google service-account token.');
    }
    $jwt = $unsigned . '.' . base64UrlEncode($signature);

    $curl = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($curl);
    if ($raw === false) {
        $message = curl_error($curl);
        curl_close($curl);
        throw new RuntimeException('Google OAuth connection failed: ' . $message);
    }
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    $body = json_decode($raw, true);
    if ($status < 200 || $status >= 300 || !is_array($body) || empty($body['access_token'])) {
        $reason = is_array($body) ? trim(($body['error'] ?? '') . ': ' . ($body['error_description'] ?? ''), ': ') : '';
        throw new RuntimeException('Google OAuth rejected the service-account credentials (HTTP ' . $status . ($reason !== '' ? ', ' . $reason : '') . ').');
    }
    return (string) $body['access_token'];
}

function googleSheetsRequest(string $method, string $url, string $token, ?array $payload = null): array
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer ' . $token],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($payload !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
    $raw = curl_exec($curl);
    if ($raw === false) {
        $message = curl_error($curl);
        curl_close($curl);
        throw new RuntimeException('Google Sheets connection failed: ' . $message);
    }
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    $body = json_decode($raw, true);
    return ['status' => $status, 'body' => is_array($body) ? $body : []];
}

function googleApiError(array $response, string $fallback): string
{
    return cleanText($response['body']['error']['message'] ?? $fallback, 220);
}

function claimGoogleSheetsSync(string $linkId): bool
{
    $claimed = false;
    mutateRegistrations(function (array &$records) use ($linkId, &$claimed): void {
        if (!isset($records[$linkId])) return;
        $sync = $records[$linkId]['googleSheets'] ?? [];
        $status = (string) ($sync['status'] ?? 'PENDING');
        $attempts = (int) ($sync['attempts'] ?? 0);
        $lastAttempt = isset($sync['lastAttemptAt']) ? strtotime((string) $sync['lastAttemptAt']) : false;
        $processingExpired = $status === 'PROCESSING' && (!$lastAttempt || $lastAttempt < time() - 120);
        $retryReady = $status === 'FAILED' && (!$lastAttempt || $lastAttempt < time() - 15);
        if ($status === 'SYNCED' || $attempts >= 5 || ($status === 'PROCESSING' && !$processingExpired) || ($status === 'FAILED' && !$retryReady)) return;
        $records[$linkId]['googleSheets'] = [
            'status' => 'PROCESSING',
            'attempts' => $attempts + 1,
            'lastAttemptAt' => date(DATE_ATOM),
        ];
        $claimed = true;
    });
    return $claimed;
}

function finishGoogleSheetsSync(string $linkId, bool $synced, string $error = '', bool $alreadyPresent = false): void
{
    mutateRegistrations(function (array &$records) use ($linkId, $synced, $error, $alreadyPresent): void {
        if (!isset($records[$linkId])) return;
        $sync = $records[$linkId]['googleSheets'] ?? [];
        $sync['status'] = $synced ? 'SYNCED' : 'FAILED';
        if ($synced) {
            $sync['syncedAt'] = date(DATE_ATOM);
            $sync['alreadyPresent'] = $alreadyPresent;
            unset($sync['error']);
        } else {
            $sync['error'] = cleanText($error, 220);
        }
        $records[$linkId]['googleSheets'] = $sync;
    });
}

function googleSheetsHeaders(): array
{
    return [
        'Registration ID', 'Payment Status', 'Amount Paid (INR)', 'Registration Type', 'Workshops',
        'Competitions', 'Name', 'Email', 'Phone', 'Designation', 'Institution', 'Medical Council',
        'Council Registration No.', 'Category', 'Diet', 'Manipal Interest', 'Payment Reference',
        'Payment URL', 'Transaction ID', 'Payment Screenshot', 'Created At', 'Paid At', 'Sheet Synced At',
    ];
}

function googleSheetsRegistrationRow(array $record): array
{
    return [
        (string) ($record['registrationId'] ?? ''),
        (string) ($record['paymentStatus'] ?? ''),
        (float) ($record['amount'] ?? 0),
        (string) ($record['tier'] ?? ''),
        formatList($record['workshops'] ?? [], 'Conference only'),
        formatList($record['competitions'] ?? [], 'None selected'),
        (string) ($record['name'] ?? ''),
        (string) ($record['email'] ?? ''),
        (string) ($record['phone'] ?? ''),
        (string) ($record['designation'] ?? ''),
        (string) ($record['institution'] ?? ''),
        (string) ($record['council'] ?? ''),
        (string) ($record['regno'] ?? ''),
        (string) ($record['category'] ?? ''),
        (string) ($record['diet'] ?? ''),
        !empty($record['manipalInterest']) ? 'Yes' : 'No',
        (string) ($record['linkId'] ?? ''),
        (string) ($record['linkUrl'] ?? ''),
        (string) ($record['transactionId'] ?? ''),
        (string) ($record['paymentProof']['originalName'] ?? ''),
        (string) ($record['createdAt'] ?? ''),
        (string) ($record['paidAt'] ?? ''),
        date(DATE_ATOM),
    ];
}

function firstColumnRange(string $range): string
{
    $sheet = str_contains($range, '!') ? explode('!', $range, 2)[0] : 'Registrations';
    return $sheet . '!A:A';
}

function base64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}
