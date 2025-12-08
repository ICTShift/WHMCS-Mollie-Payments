<?php

require_once __DIR__ . '/mollie/mollie.php';

function molliepaybybank_devapp_config()
{
    $config = mollie_config();

    $config = array_merge($config, array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Mollie Pay By Bank'
        )
    ));

    return $config;
}

function molliepaybybank_devapp_link($params)
{
    return mollie_link($params, \Mollie\Api\Types\PaymentMethod::PAYBYBANK);
}
