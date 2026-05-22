<?php
/**
 *
 *    Setting requirements and includes
 *
 */
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/payment_webhook_events.php';

use WHMCS\Database\Capsule;
use Mollie\Api\Exceptions\InvalidSignatureException;
use Mollie\Api\Webhooks\SignatureValidator;
use Mollie\Api\Webhooks\WebhookEventMapper;
use WHMCSMollie\WebhookEvents\PaymentAuthorized;
use WHMCSMollie\WebhookEvents\PaymentCanceled;
use WHMCSMollie\WebhookEvents\PaymentExpired;
use WHMCSMollie\WebhookEvents\PaymentFailed;
use WHMCSMollie\WebhookEvents\PaymentPaid;

function mollie_is_valid_payment_id($paymentId)
{
    return is_string($paymentId)
        && preg_match('/^tr_[A-Za-z0-9]+$/', $paymentId) === 1;
}

function mollie_normalize_gateway_name($gatewayName)
{
    if (!is_string($gatewayName)) {
        return null;
    }

    $gatewayName = trim($gatewayName);

    if ($gatewayName === '' || preg_match('/^mollie[a-z0-9]+_devapp$/', $gatewayName) !== 1) {
        return null;
    }

    return $gatewayName;
}

function mollie_extract_metadata_values($metadata)
{
    $values = array(
        'transaction_id' => null,
        'gateway' => null,
    );

    if (is_array($metadata)) {
        if (isset($metadata['transaction_id']) && ctype_digit((string) $metadata['transaction_id'])) {
            $values['transaction_id'] = (int) $metadata['transaction_id'];
        }

        if (isset($metadata['gateway'])) {
            $values['gateway'] = mollie_normalize_gateway_name($metadata['gateway']);
        }
    } else if (is_object($metadata)) {
        if (isset($metadata->transaction_id) && ctype_digit((string) $metadata->transaction_id)) {
            $values['transaction_id'] = (int) $metadata->transaction_id;
        }

        if (isset($metadata->gateway)) {
            $values['gateway'] = mollie_normalize_gateway_name($metadata->gateway);
        }
    }

    return $values;
}

function mollie_get_gateway_credentials($gatewayName)
{
    $gatewayName = mollie_normalize_gateway_name($gatewayName);

    if (!$gatewayName) {
        return null;
    }

    $gatewayRows = Capsule::table('tblpaymentgateways')
        ->select(['setting', 'value'])
        ->where('gateway', $gatewayName)
        ->whereIn('setting', ['key', 'webhook_signing_secret'])
        ->get();

    if ($gatewayRows->isEmpty()) {
        return null;
    }

    $credentials = array(
        'gateway' => $gatewayName,
        'key' => '',
        'webhook_signing_secret' => '',
    );

    foreach ($gatewayRows as $gatewayRow) {
        $credentials[$gatewayRow->setting] = is_string($gatewayRow->value) ? trim($gatewayRow->value) : '';
    }

    return $credentials;
}

function mollie_get_candidate_gateways($preferredGatewayName = null)
{
    $candidateGateways = array();
    $preferredGatewayName = mollie_normalize_gateway_name($preferredGatewayName);

    if ($preferredGatewayName) {
        $preferredGateway = mollie_get_gateway_credentials($preferredGatewayName);

        if ($preferredGateway && $preferredGateway['key'] !== '') {
            $candidateGateways[] = $preferredGateway;
        }
    }

    if (!empty($candidateGateways)) {
        return $candidateGateways;
    }

    $configuredGateways = Capsule::table('tblpaymentgateways')
        ->select(['gateway', 'setting', 'value'])
        ->where('gateway', 'like', 'mollie%_devapp')
        ->whereIn('setting', ['key', 'webhook_signing_secret'])
        ->get()
        ->groupBy('gateway');

    foreach ($configuredGateways as $gatewayName => $gatewayRows) {
        $credentials = array(
            'gateway' => $gatewayName,
            'key' => '',
            'webhook_signing_secret' => '',
        );

        foreach ($gatewayRows as $gatewayRow) {
            $credentials[$gatewayRow->setting] = is_string($gatewayRow->value) ? trim($gatewayRow->value) : '';
        }

        if ($credentials['key'] !== '') {
            $candidateGateways[] = $credentials;
        }
    }

    return $candidateGateways;
}

function mollie_resolve_transaction_from_mollie($paymentId, $preferredGatewayName = null)
{
    foreach (mollie_get_candidate_gateways($preferredGatewayName) as $configuredGateway) {
        try {
            $mollie = new \Mollie\Api\MollieApiClient();
            $mollie->setApiKey($configuredGateway['key']);
            $payment = $mollie->payments->get($paymentId);

            $metadata = mollie_extract_metadata_values(isset($payment->metadata) ? $payment->metadata : null);

            if ($metadata['transaction_id'] !== null) {
                $transaction = Capsule::table('gateway_mollie')->where('id', $metadata['transaction_id'])->first();
            } else {
                $transaction = null;
            }

            if ($transaction) {
                if (empty($transaction->paymentid)) {
                    Capsule::table('gateway_mollie')->where('id', $transaction->id)->update(['paymentid' => $paymentId]);
                    $transaction->paymentid = $paymentId;
                }

                return array(
                    'transaction' => $transaction,
                    'gateway' => $configuredGateway['gateway'],
                    'payment' => $payment,
                );
            }
        } catch (\Throwable $e) {
            // Continue; this key might not own the payment in Mollie.
        }
    }

    return null;
}

function mollie_get_request_signature_headers()
{
    $headers = array();

    if (isset($_SERVER['HTTP_X_MOLLIE_SIGNATURE']) && is_string($_SERVER['HTTP_X_MOLLIE_SIGNATURE'])) {
        $headers[] = trim($_SERVER['HTTP_X_MOLLIE_SIGNATURE']);
    }

    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $headerName => $headerValue) {
            if (strcasecmp($headerName, SignatureValidator::SIGNATURE_HEADER) === 0) {
                if (is_array($headerValue)) {
                    foreach ($headerValue as $singleValue) {
                        if (is_string($singleValue) && trim($singleValue) !== '') {
                            $headers[] = trim($singleValue);
                        }
                    }
                } else if (is_string($headerValue) && trim($headerValue) !== '') {
                    $headers[] = trim($headerValue);
                }
            }
        }
    }

    return array_values(array_unique(array_filter($headers)));
}

function mollie_parse_nextgen_payload($rawInput)
{
    if (!is_string($rawInput) || trim($rawInput) === '') {
        return null;
    }

    $payload = json_decode($rawInput, true);

    if (!is_array($payload)) {
        return null;
    }

    $requiredFields = array('id', 'type', 'entityId', 'createdAt');

    foreach ($requiredFields as $requiredField) {
        if (!isset($payload[$requiredField]) || !is_string($payload[$requiredField]) || trim($payload[$requiredField]) === '') {
            return null;
        }
    }

    return $payload;
}

function mollie_payment_event_map()
{
    return array(
        PaymentPaid::type() => PaymentPaid::class,
        PaymentAuthorized::type() => PaymentAuthorized::class,
        PaymentCanceled::type() => PaymentCanceled::class,
        PaymentExpired::type() => PaymentExpired::class,
        PaymentFailed::type() => PaymentFailed::class,
    );
}

/**
 *
 *    Check parameters
 *
 */
if (true) {

    $rawInput = file_get_contents('php://input');
    $nextGenPayload = mollie_parse_nextgen_payload($rawInput);
    $signatureHeaders = mollie_get_request_signature_headers();
    $parsedBody = array();
    if ($nextGenPayload === null && !empty($rawInput)) {
        parse_str($rawInput, $parsedBody);
    }

    $paymentId = null;
    if ($nextGenPayload !== null) {
        $paymentId = trim($nextGenPayload['entityId']);
    } else if (isset($_POST['id']) && is_string($_POST['id'])) {
        $paymentId = trim($_POST['id']);
    } else if (isset($parsedBody['id']) && is_string($parsedBody['id'])) {
        $paymentId = trim($parsedBody['id']);
    }

    $transactionIdHint = null;
    if (isset($_GET['transaction_id']) && ctype_digit((string) $_GET['transaction_id'])) {
        $transactionIdHint = (int) $_GET['transaction_id'];
    }

    $gatewayHint = null;
    if (isset($_GET['gateway'])) {
        $gatewayHint = mollie_normalize_gateway_name($_GET['gateway']);
    }

    $resolvedPayment = null;
    $resolvedGatewayName = null;

    if ($nextGenPayload !== null) {
        $nextGenMetadata = array(
            'transaction_id' => null,
            'gateway' => null,
        );

        if (isset($nextGenPayload['_embedded']['entity']['metadata'])) {
            $nextGenMetadata = mollie_extract_metadata_values($nextGenPayload['_embedded']['entity']['metadata']);
        }

        if ($nextGenMetadata['transaction_id'] !== null) {
            $transactionIdHint = $nextGenMetadata['transaction_id'];
        }

        if ($gatewayHint === null && $nextGenMetadata['gateway'] !== null) {
            $gatewayHint = $nextGenMetadata['gateway'];
        }

        $signatureCandidates = array();
        foreach (mollie_get_candidate_gateways($gatewayHint) as $candidateGateway) {
            if ($candidateGateway['webhook_signing_secret'] !== '') {
                $signatureCandidates[$candidateGateway['gateway']] = $candidateGateway['webhook_signing_secret'];
            }
        }

        if (empty($signatureHeaders)) {
            logTransaction($gatewayHint ?: 'mollieunknown', array('payload' => $nextGenPayload), 'Callback - Failure (Missing webhook signature)');

            header('HTTP/1.1 400 Missing webhook signature');
            exit();
        }

        if (empty($signatureCandidates)) {
            logTransaction($gatewayHint ?: 'mollieunknown', array('payload' => $nextGenPayload), 'Callback - Failure (Webhook signing secret not configured)');

            header('HTTP/1.1 500 Webhook signing secret not configured');
            exit();
        }

        try {
            (new SignatureValidator(array_values($signatureCandidates)))->validatePayload($rawInput, $signatureHeaders);
            $event = (new WebhookEventMapper(mollie_payment_event_map()))->processPayload($nextGenPayload, $signatureHeaders[0]);
        } catch (InvalidSignatureException $e) {
            logTransaction($gatewayHint ?: 'mollieunknown', array('payload' => $nextGenPayload, 'error' => $e->getMessage()), 'Callback - Failure (Invalid webhook signature)');

            header('HTTP/1.1 400 Invalid webhook signature');
            exit();
        } catch (\InvalidArgumentException $e) {
            logTransaction($gatewayHint ?: 'mollieunknown', array('payload' => $nextGenPayload, 'error' => $e->getMessage()), 'Callback - Ignored (Unsupported next-gen webhook)');

            header('HTTP/1.1 200 OK');
            exit();
        }

        if (!mollie_is_valid_payment_id($paymentId)) {
            logTransaction($gatewayHint ?: 'mollieunknown', array('payload' => $nextGenPayload), 'Callback - Ignored (Unsupported entity ID)');

            header('HTTP/1.1 200 OK');
            exit();
        }

        if ($gatewayHint !== null) {
            $resolvedGatewayName = $gatewayHint;
        }

        if ($event->entity !== null) {
            $resolvedPayment = $event;
        }
    }

    if (!mollie_is_valid_payment_id($paymentId)) {
        logTransaction('mollieunknown', array('post' => $_POST, 'get' => $_GET, 'body' => $parsedBody), 'Callback - Failure 0 (Arg mismatch)');

        header('HTTP/1.1 500 Arg mismatch');
        exit();
    }

    // Get transaction
    $transaction = null;

    if ($transactionIdHint !== null) {
        $transaction = Capsule::table('gateway_mollie')->where('id', $transactionIdHint)->first();

        if ($transaction && empty($transaction->paymentid)) {
            Capsule::table('gateway_mollie')->where('id', $transaction->id)->update(['paymentid' => $paymentId]);
            $transaction->paymentid = $paymentId;
        }
    }

    if (!$transaction) {
        $transaction = Capsule::table('gateway_mollie')->where('paymentid', $paymentId)->first();
    }

    if (!$transaction) {
        $resolved = mollie_resolve_transaction_from_mollie($paymentId, $gatewayHint ?: $resolvedGatewayName);

        if ($resolved) {
            $transaction = $resolved['transaction'];
            $resolvedGatewayName = $resolved['gateway'];
            $resolvedPayment = $resolved['payment'];
        }
    }

    if (!$transaction) {
        logTransaction($gatewayHint ?: 'mollieunknown', array('id' => $paymentId, 'transaction_id' => $transactionIdHint, 'post' => $_POST, 'get' => $_GET), 'Callback v9.0.4 - Failure 2 (Transaction not found)');

        header('HTTP/1.1 200 OK');
        exit();
    }

    $method = $transaction->method;

    if (empty($method)) {
        $method = 'checkout';
    }

    $_GATEWAY = getGatewayVariables($resolvedGatewayName ? $resolvedGatewayName : ('mollie' . $method . '_devapp'));

    if (empty($_GATEWAY['type'])) {
        logTransaction('mollieunknown', array('id' => $paymentId, 'method' => $method), 'Callback - Failure 4 (Gateway not active)');

        header('HTTP/1.1 500 Gateway not active');
        exit();
    }

    if ($transaction->status != 'open') {
        logTransaction($_GATEWAY['paymentmethod'], array('transaction' => (array) $transaction, 'callback' => $_POST), 'Callback - Ignored (Transaction not open)');

        header('HTTP/1.1 200 OK');
        exit();
    }

    // Get user and transaction currencies
    $userCurrency = getCurrency($transaction->userid);
    $transactionCurrency = Capsule::table('tblcurrencies')->where('id', $transaction->currencyid)->first();

    // Check payment
    $mollie = new \Mollie\Api\MollieApiClient();

    if (!empty($_GATEWAY['key'])) {
        $mollie->setApiKey($_GATEWAY['key']);
    }

    try {
        if ($resolvedPayment instanceof \Mollie\Api\Webhooks\Events\BaseEvent && $resolvedPayment->entity !== null) {
            $payment = $resolvedPayment->asResource($mollie);
        } else if ($resolvedPayment) {
            $payment = $resolvedPayment;
        } else {
            $payment = $mollie->payments->get($paymentId);
        }
    } catch (\Throwable $e) {
        logTransaction($_GATEWAY['paymentmethod'], array('id' => $paymentId, 'error' => $e->getMessage()), 'Callback - Failure 5 (Unable to fetch payment)');

        header('HTTP/1.1 500 Unable to fetch payment');
        exit();
    }

    if (empty($transaction->paymentid)) {
        Capsule::table('gateway_mollie')->where('id', $transaction->id)->update(['paymentid' => $paymentId]);
        $transaction->paymentid = $paymentId;
    } else if ($transaction->paymentid !== $paymentId) {
        logTransaction($_GATEWAY['paymentmethod'], array('expected_paymentid' => $transaction->paymentid, 'callback_paymentid' => $paymentId, 'transaction_id' => $transaction->id), 'Callback - Ignored (Payment ID mismatch)');

        header('HTTP/1.1 200 OK');
        exit();
    }

    if (isset($payment->metadata)) {
        $metadata = $payment->metadata;
        if (is_array($metadata) && isset($metadata['transaction_id']) && ctype_digit((string) $metadata['transaction_id']) && (int) $metadata['transaction_id'] !== (int) $transaction->id) {
            logTransaction($_GATEWAY['paymentmethod'], array('transaction_id' => $transaction->id, 'metadata_transaction_id' => (int) $metadata['transaction_id'], 'paymentid' => $paymentId), 'Callback - Ignored (Metadata transaction mismatch)');

            header('HTTP/1.1 200 OK');
            exit();
        }

        if (is_object($metadata) && isset($metadata->transaction_id) && ctype_digit((string) $metadata->transaction_id) && (int) $metadata->transaction_id !== (int) $transaction->id) {
            logTransaction($_GATEWAY['paymentmethod'], array('transaction_id' => $transaction->id, 'metadata_transaction_id' => (int) $metadata->transaction_id, 'paymentid' => $paymentId), 'Callback - Ignored (Metadata transaction mismatch)');

            header('HTTP/1.1 200 OK');
            exit();
        }
    }

    if ($payment->isPaid()) {

        // Add conversion, when there is need to. WHMCS only supports currencies per user. WHY?!
        $amount = $transaction->amount;
        if ($transactionCurrency && $transactionCurrency->id != $userCurrency['id']) {
            $amount = convertCurrency($amount, $transaction->currencyid, $userCurrency['id']);
        }

        // Check invoice
        $invoiceid = checkCbInvoiceID($transaction->invoiceid, $_GATEWAY['paymentmethod']);

        checkCbTransID($paymentId);

        // Add invoice
        addInvoicePayment($invoiceid, $paymentId, $amount, '', $_GATEWAY['paymentmethod']);

        Capsule::table('gateway_mollie')->where('id', $transaction->id)->update(['status' => 'paid', 'updated' => date('Y-m-d H:i:s')]);
        $transaction->status = 'paid';
        $transaction->updated = date('Y-m-d H:i:s');

        logTransaction($_GATEWAY['paymentmethod'], array('transaction' => (array) $transaction, 'callback' => $_POST), 'Callback - Successful (Paid)');

        header('HTTP/1.1 200 OK');
        exit();
    } else if ($payment->isOpen() == FALSE) {
        Capsule::table('gateway_mollie')->where('id', $transaction->id)->update(['status' => 'closed', 'updated' => date('Y-m-d H:i:s')]);
        $transaction->status = 'closed';
        $transaction->updated = date('Y-m-d H:i:s');

        logTransaction($_GATEWAY['paymentmethod'], array('transaction' => (array) $transaction, 'callback' => $_POST), 'Callback - Successful (Closed)');

        header('HTTP/1.1 200 OK');
        exit();
    } else {
        logTransaction($_GATEWAY['paymentmethod'], array('transaction' => (array) $transaction, 'callback' => $_POST), 'Callback - Pending (Payment still open)');

        header('HTTP/1.1 200 OK');
        exit();
    }
}
