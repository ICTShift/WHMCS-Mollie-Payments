<?php

use WHMCS\Database\Capsule;

require_once __DIR__ . '/vendor/autoload.php';

function mollie_config()
{
    return array(
        'key' => array(
            'FriendlyName' => 'API key',
            'Type' => 'text',
            'Size' => '35',
            'Description' => 'Your channels API key.'
        ),
        'webhook_signing_secret' => array(
            'FriendlyName' => 'Webhook signing secret',
            'Type' => 'text',
            'Size' => '80',
            'Description' => 'Optional. Required only when using Mollie next-gen signed webhooks.'
        )
    );
}

function mollie_link($params, $method = \Mollie\Api\Types\PaymentMethod::IDEAL)
{
    global $whmcs;

    /**
     *
     *    Setting requirements and includes
     *
     */
    if (substr($params['returnurl'], 0, 1) == '/')
        $params['returnurl'] = $params['systemurl'] . $params['returnurl'];

    if (empty($params['language']))
        $params['language'] = ((isset($_SESSION['language'])) ? $_SESSION['language'] : $whmcs->get_config('Language'));

    if (empty($params['language']))
        $params['language'] = 'english';

    if (!file_exists(__DIR__ . '/lang/' . $params['language'] . '.php'))
        $params['language'] = 'english';

    /* @var array $_GATEWAYLANG */
    require __DIR__ . '/lang/' . $params['language'] . '.php';

    if (!Capsule::schema()->hasTable('gateway_mollie')) {
        Capsule::schema()->create('gateway_mollie', function ($table) {
            $table->increments('id');
            $table->string('paymentid', 64)->nullable()->unique();
            $table->double('amount');
            $table->integer('currencyid');
            $table->string('ip', 50);
            $table->integer('userid');
            $table->integer('invoiceid');
            $table->enum('status', ['open', 'paid', 'closed'])->default('open');
            $table->string('method', 25);
            $table->timestamp('created')->useCurrent();
            $table->timestamp('updated')->nullable();
        });
    }

    $mollie = new \Mollie\Api\MollieApiClient();
    $mollie->setApiKey($params['key']);

    /**
     *
     *    Check if good state to open transaction.
     *
     */
    if (isset($_GET['check_payment']) && ctype_digit($_GET['check_payment'])) {
        $transaction = Capsule::table('gateway_mollie')->where('id', (int) $_GET['check_payment'])->first();

        if (!$transaction) {
            return '<p>' . $_GATEWAYLANG['errorTransactionNotFound'] . '</p>';
        }

        if ($transaction->status == 'paid') {
            header('location: ' . $params['returnurl'] . '&paymentsuccess=true');
            exit();
        } else if ($transaction->status == 'closed') {
            header('location: ' . $params['returnurl'] . '&paymentfailed=true');
            exit();
        } else {
            if (!empty($transaction->paymentid)) {
                // DB hasn't been updated by webhook yet — poll Mollie directly.
                try {
                    $pollPayment = $mollie->payments->get($transaction->paymentid);

                    if ($pollPayment->isPaid()) {
                        Capsule::table('gateway_mollie')
                            ->where('id', $transaction->id)
                            ->update(['status' => 'paid', 'updated' => date('Y-m-d H:i:s')]);
                        header('location: ' . $params['returnurl'] . '&paymentsuccess=true');
                        exit();
                    } else if (!$pollPayment->isOpen()) {
                        Capsule::table('gateway_mollie')
                            ->where('id', $transaction->id)
                            ->update(['status' => 'closed', 'updated' => date('Y-m-d H:i:s')]);
                        header('location: ' . $params['returnurl'] . '&paymentfailed=true');
                        exit();
                    }
                } catch (\Throwable $e) {
                    // Mollie unreachable — keep spinning.
                }
            }

            return '<br/><img src="' . $params['systemurl'] . 'modules/gateways/mollie/ajax_loader.gif" /><br/>' . $_GATEWAYLANG['checkPayment'] . ' <script> window.onload = function(){ setTimeout("location.reload(true);", 2000); } </script>';
        }
    } else {
        $isAddFundsOrMassPayStart = isset($_GET['action'])
            && ($_GET['action'] == 'addfunds' || $_GET['action'] == 'masspay')
            && isset($_POST['paymentmethod'])
            && $_POST['paymentmethod'] == 'mollie' . $method;

        if (isset($_POST['start']) || isset($_POST['issuer']) || $isAddFundsOrMassPayStart) {

            $existingTransaction = Capsule::table('gateway_mollie')
                ->where('invoiceid', (int) $params['invoiceid'])
                ->where('userid', (int) $params['clientdetails']['userid'])
                ->where('method', $method)
                ->where('status', 'open')
                ->orderBy('id', 'desc')
                ->first();

            if ($existingTransaction && !empty($existingTransaction->paymentid)) {
                try {
                    $existingPayment = $mollie->payments->get($existingTransaction->paymentid);

                    if ($existingPayment->isOpen()) {
                        header('Location: ' . $existingPayment->getCheckoutUrl());
                        exit();
                    }

                    if (!$existingPayment->isPaid()) {
                        Capsule::table('gateway_mollie')
                            ->where('id', $existingTransaction->id)
                            ->update(['status' => 'closed', 'updated' => date('Y-m-d H:i:s')]);
                    }
                } catch (\Throwable $e) {
                    // If we can't resolve prior payment state, continue and create a new payment.
                }
            }

            $transactionCurrency = Capsule::table('tblcurrencies')->where('code', $params['currency'])->first();
            $resolvedCurrencyId = $transactionCurrency ? (int) $transactionCurrency->id : 0;

            if ($resolvedCurrencyId === 0) {
                $userCurrency = getCurrency((int) $params['clientdetails']['userid']);
                if (is_array($userCurrency) && isset($userCurrency['id']) && ctype_digit((string) $userCurrency['id'])) {
                    $resolvedCurrencyId = (int) $userCurrency['id'];
                }
            }

            if ($resolvedCurrencyId === 0) {
                logTransaction(
                    isset($params['paymentmethod']) ? $params['paymentmethod'] : ('mollie' . $method . '_devapp'),
                    array('invoiceid' => $params['invoiceid'], 'currency' => $params['currency']),
                    'Link - Failure (Unable to resolve currencyid)'
                );

                return '<p>Unable to determine invoice currency for this payment.</p>';
            }

            $transactionId = Capsule::table('gateway_mollie')->insertGetId([
                'amount'     => $params['amount'],
                'currencyid' => $resolvedCurrencyId,
                'ip'         => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
                'userid'     => $params['clientdetails']['userid'],
                'invoiceid'  => $params['invoiceid'],
                'method'     => $method,
            ]);

            $gatewayName = isset($params['paymentmethod']) && is_string($params['paymentmethod'])
                ? $params['paymentmethod']
                : ('mollie' . $method . '_devapp');

            $paymentData = array(
                'amount' => [
                    'value' => number_format((float) $params['amount'], 2, '.', ''),
                    'currency' => $params['currency'],
                ],
                'description' => $params['description'],
                'redirectUrl' => $params['returnurl'] . '&check_payment=' . $transactionId,
                'webhookUrl' => $params['systemurl'] . '/modules/gateways/mollie/callback.php?transaction_id=' . $transactionId . '&gateway=' . rawurlencode($gatewayName),
                'metadata' => array(
                    'invoice_id' => $params['invoiceid'],
                    'transaction_id' => $transactionId,
                    'gateway' => $gatewayName,
                ),
            );

            if (!empty($method)) {
                $paymentData['method'] = $method;
            }

            if ($method == \Mollie\Api\Types\PaymentMethod::BANKTRANSFER) {
                $paymentData['dueDate'] = date('Y-m-d', strtotime('+100 days'));
            }

            try {
                $payment = $mollie->payments->create($paymentData);
            } catch (\Mollie\Api\Exceptions\ApiException $e) {
                Capsule::table('gateway_mollie')->where('id', $transactionId)->whereNull('paymentid')->delete();
                return '<p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
            }

            Capsule::table('gateway_mollie')->where('id', $transactionId)->update(['paymentid' => $payment->id]);

            header('Location: ' . $payment->getCheckoutUrl());
            exit();
        } else {
            $return = '<form action="viewinvoice.php?id=' . $params['invoiceid'] . '" method="POST">';

            $methodLabelKey = !empty($method) ? ('payWith' . ucfirst($method)) : 'payWith';

            if (!isset($_GATEWAYLANG[$methodLabelKey])) {
                $methodLabelKey = 'payWith';
            }

            $return .= '<input type="submit" name="start" value="' . $_GATEWAYLANG[$methodLabelKey] . '" /></form>';

            return $return;
        }
    }
}
