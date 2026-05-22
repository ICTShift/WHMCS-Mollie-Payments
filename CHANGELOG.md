# Changelog

All notable changes to this project will be documented in this file.

This project is originally developed and maintained by [0100Dev](https://0100dev.nl/).  
The changes below were contributed by [ICT Shift](https://www.ictshift.com/) to resolve compatibility issues encountered with WHMCS 9.x.

---

## [Unreleased] – 2026-05-22

### Added – Signed Next-Gen Webhooks (`src/mollie/mollie.php`, `src/mollie/callback.php`, `src/mollie/payment_webhook_events.php`)

- **Added optional `Webhook signing secret` gateway setting.**  
  Each Mollie gateway can now store a signing secret for Mollie's next-gen signed webhooks while keeping the existing API key configuration unchanged.

- **Added dual webhook handling in `callback.php`.**  
  The callback now accepts both the legacy payment-id webhook format and Mollie's signed JSON event payloads. Signed payloads are verified with `SignatureValidator`, mapped into payment events, and can use embedded metadata to resolve transactions without relying on a follow-up API fetch.

- **Added local payment event classes for payment lifecycle events.**  
  The vendored SDK currently documents next-gen webhooks and ships the generic webhook infrastructure, but does not expose dedicated payment event classes for this gateway's payment flow. This fork now provides those small event adapters locally so payment events can be processed through the v3 webhook mapper.

### Fixed – WHMCS 9.x Compatibility (`src/mollie/mollie.php`, `src/mollie/callback.php`)

- **Migrated all database calls to `WHMCS\Database\Capsule`.**  
  WHMCS 9.x deprecated the legacy `select_query`, `insert_query`, `update_query`, and `full_query` functions. All DB interactions in both `mollie.php` and `callback.php` have been rewritten to use the Capsule ORM, which is the supported approach in WHMCS 9.x.

- **Fixed `currencyid` always storing `0`.**  
  Capsule returns `stdClass` objects rather than arrays. References to `$result['id']` were updated to `$result->id`. Added a two-step currency resolution fallback: first by currency code from `tblcurrencies`, then via `getCurrency($userid)`.

- **Fixed webhook "Transaction not found" failures.**  
  The Mollie `webhookUrl` now includes a `?transaction_id=` query parameter, and the same value is stored in payment metadata. The callback uses this hint as its primary lookup, making webhook matching reliable even when the payment ID has not yet been stored in the local DB.

- **Fixed webhook retry loops.**  
  All non-actionable code paths in `callback.php` (transaction not found, gateway inactive, status already resolved, ID mismatch) now return HTTP 200 instead of 4xx/5xx, preventing Mollie from re-delivering the same webhook indefinitely.

- **Fixed duplicate payment creation.**  
  Before creating a new Mollie payment, `mollie.php` now checks for an existing open transaction for the same invoice, user, and payment method. If a still-open Mollie payment exists, the user is redirected to that checkout URL. If the prior payment has expired or been cancelled, it is marked `closed` before a new one is created.

- **Fixed false paid attribution.**  
  Removed a broad invoice+method fallback in `callback.php` that could match the wrong transaction. Recovery now relies exclusively on `metadata.transaction_id`. Added strict cross-checks on both `paymentid` and metadata before applying any payment to an invoice.

- **Improved log structure.**  
  Webhook log entries now use a nested structure (`['transaction' => ..., 'callback' => ...]`) to prevent key collisions between transaction fields and POST data.

### Changed

- **New payments now carry a gateway hint in both metadata and webhook URL query parameters.**  
  This allows the callback to target the correct configured Mollie gateway directly for newly created payments, instead of falling back to a broad scan across all configured Mollie API keys.

- **Legacy callback input handling is narrower.**  
  The callback now treats signed JSON payloads as the next-gen path and legacy payment callbacks as POST/body `id` deliveries only. This removes the old `GET id` fallback and keeps the broader gateway scan as a compatibility fallback for older deliveries only.

### Noted

- **iDEAL 2.0 compliance was already in place.**  
  No bank/issuer selection dropdown is rendered and the issuer field is not sent to the Mollie API, which is consistent with iDEAL 2.0 requirements (mandatory since March 2025). No code change was required.

- **`check_payment` polling now queries Mollie API directly.**  
  The polling page (`?check_payment=N`) previously relied solely on the webhook updating the local DB before it could redirect the user. It now also calls `$mollie->payments->get()` on each 2-second refresh. If Mollie reports the payment as paid or closed before the webhook arrives, the DB is updated immediately and the user is redirected without waiting.
