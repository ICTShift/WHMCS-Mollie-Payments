<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/mollie/vendor/autoload.php';

use Mollie\Api\Webhooks\SignatureValidator;

function usage(): void
{
    $usage = <<<'TXT'
Usage:
  php tools/simulate-mollie-webhook.php --secret SECRET --entity-id tr_xxx --transaction-id 123 --gateway mollieideal_devapp [options]

Required:
  --secret          Mollie webhook signing secret
  --entity-id       Payment id, for example tr_test_123
  --transaction-id  Local gateway_mollie row id
  --gateway         Gateway name, for example mollieideal_devapp

Optional:
  --event           Event type, defaults to payment.paid
  --url             Callback URL to POST to
  --send            Actually POST to --url using cURL
  --amount-value    Amount value, defaults to 10.00
  --currency        Currency, defaults to EUR
  --description     Payment description, defaults to Simulated Mollie payment

Without --send the script prints the JSON payload and signature only.
TXT;

    fwrite(STDERR, $usage . PHP_EOL);
}

function readOptions(array $argv): array
{
    $options = getopt('', [
        'secret:',
        'entity-id:',
        'transaction-id:',
        'gateway:',
        'event::',
        'url::',
        'send',
        'amount-value::',
        'currency::',
        'description::',
    ]);

    $required = ['secret', 'entity-id', 'transaction-id', 'gateway'];

    foreach ($required as $requiredKey) {
        if (!isset($options[$requiredKey]) || $options[$requiredKey] === '') {
            usage();
            throw new InvalidArgumentException('Missing required option --' . $requiredKey);
        }
    }

    if (!ctype_digit((string) $options['transaction-id'])) {
        throw new InvalidArgumentException('--transaction-id must be numeric');
    }

    if (preg_match('/^mollie[a-z0-9]+_devapp$/', (string) $options['gateway']) !== 1) {
        throw new InvalidArgumentException('--gateway must look like mollieideal_devapp');
    }

    if (preg_match('/^tr_[A-Za-z0-9_]+$/', (string) $options['entity-id']) !== 1) {
        throw new InvalidArgumentException('--entity-id must look like a Mollie payment id');
    }

    if (isset($options['send']) && (!isset($options['url']) || $options['url'] === '')) {
        throw new InvalidArgumentException('--send requires --url');
    }

    return [
        'secret' => (string) $options['secret'],
        'entityId' => (string) $options['entity-id'],
        'transactionId' => (int) $options['transaction-id'],
        'gateway' => (string) $options['gateway'],
        'event' => isset($options['event']) && $options['event'] !== false ? (string) $options['event'] : 'payment.paid',
        'url' => isset($options['url']) && $options['url'] !== false ? (string) $options['url'] : null,
        'send' => isset($options['send']),
        'amountValue' => isset($options['amount-value']) && $options['amount-value'] !== false ? (string) $options['amount-value'] : '10.00',
        'currency' => isset($options['currency']) && $options['currency'] !== false ? (string) $options['currency'] : 'EUR',
        'description' => isset($options['description']) && $options['description'] !== false ? (string) $options['description'] : 'Simulated Mollie payment',
    ];
}

function buildPayload(array $options): array
{
    $entityId = $options['entityId'];

    return [
        'id' => 'event_' . substr(hash('sha256', $entityId . '|' . $options['event']), 0, 16),
        'type' => $options['event'],
        'entityId' => $entityId,
        'createdAt' => gmdate('Y-m-d\TH:i:sP'),
        '_links' => [
            'self' => [
                'href' => 'https://api.mollie.com/v2/payments/' . $entityId,
                'type' => 'application/hal+json',
            ],
        ],
        '_embedded' => [
            'entity' => [
                'resource' => 'payment',
                'id' => $entityId,
                'mode' => 'test',
                'status' => paymentStatusForEvent($options['event']),
                'amount' => [
                    'value' => $options['amountValue'],
                    'currency' => $options['currency'],
                ],
                'description' => $options['description'],
                'metadata' => [
                    'transaction_id' => (string) $options['transactionId'],
                    'gateway' => $options['gateway'],
                ],
            ],
        ],
    ];
}

function paymentStatusForEvent(string $eventType): string
{
    $map = [
        'payment.paid' => 'paid',
        'payment.authorized' => 'authorized',
        'payment.canceled' => 'canceled',
        'payment.expired' => 'expired',
        'payment.failed' => 'failed',
    ];

    return $map[$eventType] ?? 'paid';
}

function sendPayload(string $url, string $payload, string $signature): void
{
    $curl = curl_init($url);

    if ($curl === false) {
        throw new RuntimeException('Unable to initialize cURL');
    }

    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Mollie-Signature: sha256=' . $signature,
        ],
        CURLOPT_POSTFIELDS => $payload,
    ]);

    $responseBody = curl_exec($curl);
    $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($responseBody === false) {
        throw new RuntimeException('Webhook POST failed: ' . $curlError);
    }

    fwrite(STDOUT, 'HTTP ' . $httpCode . PHP_EOL);
    fwrite(STDOUT, (string) $responseBody . PHP_EOL);
}

try {
    $options = readOptions($argv);
    $payloadArray = buildPayload($options);
    $payloadJson = json_encode($payloadArray, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($payloadJson === false) {
        throw new RuntimeException('Unable to encode payload as JSON');
    }

    $signature = SignatureValidator::createSignature($payloadJson, $options['secret']);

    fwrite(STDOUT, 'Payload:' . PHP_EOL . $payloadJson . PHP_EOL . PHP_EOL);
    fwrite(STDOUT, 'Signature:' . PHP_EOL . 'sha256=' . $signature . PHP_EOL . PHP_EOL);

    if ($options['send']) {
        sendPayload((string) $options['url'], $payloadJson, $signature);
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}