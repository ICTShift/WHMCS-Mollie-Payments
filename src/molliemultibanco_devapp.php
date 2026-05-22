<?php

require_once __DIR__ . '/mollie/mollie.php';

function molliemultibanco_devapp_config()
{
    $config = mollie_config();

    $config = array_merge($config, array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Mollie Multibanco'
        )
    ));

    return $config;
}

function molliemultibanco_devapp_link($params)
{
    return mollie_link($params, \Mollie\Api\Types\PaymentMethod::MULTIBANCO);
}
