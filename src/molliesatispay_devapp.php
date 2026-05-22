<?php

require_once __DIR__ . '/mollie/mollie.php';

function molliesatispay_devapp_config()
{
    $config = mollie_config();

    $config = array_merge($config, array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Mollie Satispay'
        )
    ));

    return $config;
}

function molliesatispay_devapp_link($params)
{
    return mollie_link($params, \Mollie\Api\Types\PaymentMethod::SATISPAY);
}
