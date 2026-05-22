<?php

require_once __DIR__ . '/mollie/mollie.php';

function mollieklarnapaylater_devapp_config()
{
    $config = mollie_config();

    $config = array_merge($config, array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Mollie Klarna Pay Later'
        )
    ));

    return $config;
}

function mollieklarnapaylater_devapp_link($params)
{
    return mollie_link($params, \Mollie\Api\Types\PaymentMethod::KLARNA_PAY_LATER);
}
