<?php

require_once __DIR__ . '/mollie/mollie.php';

function molliein3_devapp_config()
{
    $config = mollie_config();

    $config = array_merge($config, array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Mollie iDEAL in3'
        )
    ));

    return $config;
}

function molliein3_devapp_link($params)
{
    return mollie_link($params, \Mollie\Api\Types\PaymentMethod::IN3);
}
