# Mollie Webhook Setup

This fork supports both legacy payment webhooks and Mollie's newer signed next-gen webhooks.

## What You Can Configure

You will configure two systems:

1. WHMCS gateway settings for each active Mollie gateway.
2. A Mollie webhook in the Mollie dashboard that points to the existing callback endpoint.

## WHMCS Configuration

For each active Mollie gateway in WHMCS:

1. Open Apps & Integrations or Payment Gateways, depending on your WHMCS version.
2. Edit the active Mollie gateway, for example `Mollie iDEAL` or `Mollie Checkout`.
3. Confirm the `API key` is the correct key for that Mollie profile.
4. Fill in the new `Webhook signing secret` field with the signing secret from the Mollie webhook configuration.
5. Save the gateway.

Repeat that for every Mollie gateway that can receive webhook traffic.

## Mollie Configuration

Create or update a Mollie next-gen webhook so it points to the existing callback endpoint in your WHMCS install:

`https://your-whmcs.example.com/modules/gateways/mollie/callback.php`

Recommended settings:

1. Subscribe to payment lifecycle events.
2. Prefer full payload deliveries instead of simple entity-id-only deliveries.
3. Copy the generated signing secret into the matching WHMCS gateway setting.

## How Resolution Works

New payments created by this fork now include:

1. `transaction_id` in Mollie payment metadata.
2. `gateway` in Mollie payment metadata.
3. `transaction_id` and `gateway` query hints on the legacy per-payment `webhookUrl`.

That allows the callback to resolve the correct WHMCS transaction and gateway directly for new payments. Older payments can still fall back to the legacy lookup path.

## Local Testing

Use the simulator in [tools/simulate-mollie-webhook.php](../tools/simulate-mollie-webhook.php) to generate or send signed next-gen webhook payloads.

Example that prints a signed payload without sending it:

```bash
php tools/simulate-mollie-webhook.php --secret your_secret --entity-id tr_test_123 --transaction-id 42 --gateway mollieideal_devapp
```

Example that posts directly to a callback endpoint:

```bash
php tools/simulate-mollie-webhook.php --secret your_secret --entity-id tr_test_123 --transaction-id 42 --gateway mollieideal_devapp --url https://your-whmcs.example.com/modules/gateways/mollie/callback.php?transaction_id=42\&gateway=mollieideal_devapp --send
```

## Notes

1. Signed next-gen webhooks require the signing secret to be configured in WHMCS. Without it, the callback rejects signed payloads.
2. The simulator is meant for development and verification. It does not replace end-to-end testing with real Mollie test payments.
3. The callback still accepts legacy webhook deliveries for backward compatibility.