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

use WHMCS\Database\Capsule;

function mollie_resolve_transaction_from_mollie($paymentId)
{
    $configuredGateways = Capsule::table('tblpaymentgateways')
        ->select(['gateway', 'value'])
        ->where('setting', 'key')
        ->where('gateway', 'like', 'mollie%_devapp')
        ->where('value', '!=', '')
        ->get();

    foreach ($configuredGateways as $configuredGateway) {
        try {
            $mollie = new \Mollie\Api\MollieApiClient();
            $mollie->setApiKey($configuredGateway->value);
            $payment = $mollie->payments->get($paymentId);

            $metadata = isset($payment->metadata) ? $payment->metadata : null;

            if (is_array($metadata) && isset($metadata['transaction_id']) && ctype_digit((string) $metadata['transaction_id'])) {
                $transaction = Capsule::table('gateway_mollie')->where('id', (int) $metadata['transaction_id'])->first();
            } else if (is_object($metadata) && isset($metadata->transaction_id) && ctype_digit((string) $metadata->transaction_id)) {
                $transaction = Capsule::table('gateway_mollie')->where('id', (int) $metadata->transaction_id)->first();
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
                    'gateway' => $configuredGateway->gateway,
                    'payment' => $payment,
                );
            }
        } catch (\Throwable $e) {
            // Continue; this key might not own the payment in Mollie.
        }
    }

    return null;
}

/**
 *
 *    Check parameters
 *
 */
if (true) {

    $rawInput = file_get_contents('php://input');
    $parsedBody = array();
    if (!empty($rawInput)) {
        parse_str($rawInput, $parsedBody);
    }

    $paymentId = '';
    if (isset($_POST['id']) && is_string($_POST['id'])) {
        $paymentId = trim($_POST['id']);
    } else if (isset($_GET['id']) && is_string($_GET['id'])) {
        $paymentId = trim($_GET['id']);
    } else if (isset($parsedBody['id']) && is_string($parsedBody['id'])) {
        $paymentId = trim($parsedBody['id']);
    }

    $transactionIdHint = null;
    if (isset($_GET['transaction_id']) && ctype_digit((string) $_GET['transaction_id'])) {
        $transactionIdHint = (int) $_GET['transaction_id'];
    }

    if ($paymentId === '') {
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

    $resolvedGatewayName = null;
    $resolvedPayment = null;

    if (!$transaction) {
        $resolved = mollie_resolve_transaction_from_mollie($paymentId);

        if ($resolved) {
            $transaction = $resolved['transaction'];
            $resolvedGatewayName = $resolved['gateway'];
            $resolvedPayment = $resolved['payment'];
        }
    }

    if (!$transaction) {
        logTransaction('mollieunknown', array('id' => $paymentId, 'transaction_id' => $transactionIdHint, 'post' => $_POST, 'get' => $_GET), 'Callback v9.0.4 - Failure 2 (Transaction not found)');

        header('HTTP/1.1 500 Transaction not found');
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
    $mollie->setApiKey($_GATEWAY['key']);

    try {
        $payment = $resolvedPayment ? $resolvedPayment : $mollie->payments->get($paymentId);
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
