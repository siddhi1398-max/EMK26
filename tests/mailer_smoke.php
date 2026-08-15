<?php
declare(strict_types=1);

function env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

require_once __DIR__ . '/../api/mailer.php';

sendSmtpMail(
    'delegate@example.com',
    'Test Delegate',
    'Registration confirmed',
    '<p>Payment confirmed.</p>',
    'Payment confirmed.',
    [[
        'path' => __FILE__,
        'name' => 'payment-proof.txt',
        'mimeType' => 'text/plain',
    ]]
);

echo "SMTP smoke test passed\n";
