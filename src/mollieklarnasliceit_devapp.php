<?php

require_once __DIR__ . '/mollie/mollie.php';

function mollieklarnasliceit_devapp_config()
{
    $config = mollie_config();

    $config = array_merge($config, array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Mollie Klarna Slice It'
        )
    ));

    return $config;
}

function mollieklarnasliceit_devapp_link($params)
{
    return mollie_link($params, \Mollie\Api\Types\PaymentMethod::KLARNA_SLICE_IT);
}
