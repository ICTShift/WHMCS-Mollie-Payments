<?php

require_once __DIR__ . '/mollie/mollie.php';

function molliebancomatpay_devapp_MetaData()
{
    return mollie_metadata('Mollie Bancomat Pay');
}

function molliebancomatpay_devapp_config()
{
    $config = mollie_config();

    $config = array_merge($config, array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Mollie BANCOMAT Pay'
        )
    ));

    return $config;
}

function molliebancomatpay_devapp_link($params)
{
    return mollie_link($params, \Mollie\Api\Types\PaymentMethod::BANCOMATPAY);
}
