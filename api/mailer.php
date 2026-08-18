<?php
declare(strict_types=1);

/**
 * Minimal authenticated SMTP client for Gmail-compatible SMTP servers.
 * Credentials are loaded by payment.php from .env and never reach the browser.
 */
function sendSmtpMail(string $toEmail, string $toName, string $subject, string $html, string $text, array $attachments = []): void
{
    $host = (string) env('SMTP_HOST', 'smtp.gmail.com');
    $port = (int) env('SMTP_PORT', '465');
    $encryption = strtolower((string) env('SMTP_ENCRYPTION', 'tls'));
    $username = (string) env('SMTP_USERNAME', '');
    $password = preg_replace('/\s+/', '', (string) env('SMTP_PASSWORD', ''));
    $fromEmail = (string) env('SMTP_FROM_EMAIL', $username);
    $fromName = (string) env('SMTP_FROM_NAME', 'EM Karnataka 2026');

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Invalid recipient email address.');
    }
    if (!filter_var($username, FILTER_VALIDATE_EMAIL) || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL) || $password === '') {
        throw new RuntimeException('Gmail SMTP is not configured.');
    }

    $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
        ],
    ]);
    $socket = @stream_socket_client(
        $transport . $host . ':' . $port,
        $errorNumber,
        $errorMessage,
        15,
        STREAM_CLIENT_CONNECT,
        $context
    );
    if (!$socket) throw new RuntimeException('SMTP connection failed: ' . $errorMessage . ' (' . $errorNumber . ')');
    stream_set_timeout($socket, 20);

    try {
        smtpExpect($socket, [220]);
        smtpCommand($socket, 'EHLO ' . smtpHostName(), [250]);

        if ($encryption === 'tls') {
            smtpCommand($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Could not start SMTP TLS encryption.');
            }
            smtpCommand($socket, 'EHLO ' . smtpHostName(), [250]);
        }

        smtpCommand($socket, 'AUTH LOGIN', [334]);
        smtpCommand($socket, base64_encode($username), [334]);
        smtpCommand($socket, base64_encode($password), [235]);
        smtpCommand($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
        smtpCommand($socket, 'RCPT TO:<' . $toEmail . '>', [250, 251]);
        smtpCommand($socket, 'DATA', [354]);

        $boundary = '=_EMK26_ALT_' . bin2hex(random_bytes(12));
        $mixedBoundary = '=_EMK26_MIXED_' . bin2hex(random_bytes(12));
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . mailAddressHeader($fromName, $fromEmail),
            'To: ' . mailAddressHeader($toName, $toEmail),
            'Subject: ' . encodeMailHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . smtpHostName() . '>',
            'MIME-Version: 1.0',
            'Content-Type: ' . ($attachments === []
                ? 'multipart/alternative; boundary="' . $boundary . '"'
                : 'multipart/mixed; boundary="' . $mixedBoundary . '"'),
            'X-Mailer: EMK26 Registration',
        ];
        $alternative = '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text), 76, "\r\n")
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html), 76, "\r\n")
            . '--' . $boundary . "--\r\n";

        $message = implode("\r\n", $headers) . "\r\n\r\n";
        if ($attachments === []) {
            $message .= $alternative;
        } else {
            $message .= '--' . $mixedBoundary . "\r\n"
                . 'Content-Type: multipart/alternative; boundary="' . $boundary . "\"\r\n\r\n"
                . $alternative;
            foreach ($attachments as $attachment) {
                $path = (string) ($attachment['path'] ?? '');
                if (!is_file($path) || !is_readable($path)) throw new RuntimeException('Email attachment is unavailable.');
                $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', basename((string) ($attachment['name'] ?? basename($path))));
                $mimeType = preg_replace('/[^A-Za-z0-9.+\/-]/', '', (string) ($attachment['mimeType'] ?? 'application/octet-stream'));
                $message .= '--' . $mixedBoundary . "\r\n"
                    . 'Content-Type: ' . $mimeType . '; name="' . $filename . "\"\r\n"
                    . "Content-Transfer-Encoding: base64\r\n"
                    . 'Content-Disposition: attachment; filename="' . $filename . "\"\r\n\r\n"
                    . chunk_split(base64_encode((string) file_get_contents($path)), 76, "\r\n");
            }
            $message .= '--' . $mixedBoundary . "--\r\n";
        }

        $message = preg_replace('/(?m)^\./', '..', $message);
        fwrite($socket, $message . "\r\n.\r\n");
        smtpExpect($socket, [250]);
        smtpCommand($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
    }
}

function smtpCommand($socket, string $command, array $expectedCodes): string
{
    if (fwrite($socket, $command . "\r\n") === false) {
        throw new RuntimeException('Could not write to SMTP server.');
    }
    return smtpExpect($socket, $expectedCodes);
}

function smtpExpect($socket, array $expectedCodes): string
{
    $response = '';
    do {
        $line = fgets($socket, 515);
        if ($line === false) {
            $meta = stream_get_meta_data($socket);
            throw new RuntimeException(!empty($meta['timed_out']) ? 'SMTP response timed out.' : 'SMTP connection closed unexpectedly.');
        }
        $response .= $line;
    } while (isset($line[3]) && $line[3] === '-');

    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('SMTP rejected the message with code ' . $code . '.');
    }
    return $response;
}

function smtpHostName(): string
{
    $host = preg_replace('/[^A-Za-z0-9.-]/', '', (string) ($_SERVER['SERVER_NAME'] ?? 'localhost'));
    return $host !== '' ? $host : 'localhost';
}

function mailAddressHeader(string $name, string $email): string
{
    return encodeMailHeader(str_replace(["\r", "\n"], '', $name)) . ' <' . $email . '>';
}

function encodeMailHeader(string $value): string
{
    $value = str_replace(["\r", "\n"], '', $value);
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}
