<?php

namespace WHMCSMollie\WebhookEvents;

use Mollie\Api\Webhooks\Events\BaseEvent;

abstract class PaymentEvent extends BaseEvent
{
}

final class PaymentPaid extends PaymentEvent
{
    public static function type(): string
    {
        return 'payment.paid';
    }
}

final class PaymentAuthorized extends PaymentEvent
{
    public static function type(): string
    {
        return 'payment.authorized';
    }
}

final class PaymentCanceled extends PaymentEvent
{
    public static function type(): string
    {
        return 'payment.canceled';
    }
}

final class PaymentExpired extends PaymentEvent
{
    public static function type(): string
    {
        return 'payment.expired';
    }
}

final class PaymentFailed extends PaymentEvent
{
    public static function type(): string
    {
        return 'payment.failed';
    }
}