# WHMCS Mollie Payments gateway ![GitHub All Releases](https://img.shields.io/github/downloads/0100Dev/WHMCS-Mollie-Payments/total) ![GitHub release (latest by date)](https://img.shields.io/github/v/release/0100Dev/WHMCS-Mollie-Payments)
Unofficial Mollie Payments gateway for WHMCS. This free gateway does NOT support Mollie Recurring, only Molie Payments. For Mollie Recurring we have a [paid](https://0100dev.nl/modules/whmcs#WHMCS%20Mollie%20Recurring) gateway. These gateways are not dependent on each other and can operate side by side, but also without each other.

Compatible with **all** WHMCS versions that are [supported by WHMCS](https://docs.whmcs.com/Long_Term_Support#WHMCS_Version_.26_LTS_Schedule).

### Fork Maintenance
This fork is maintained by [ICT Shift](https://www.ictshift.com/) and includes maintenance work focused on newer WHMCS versions, including WHMCS 9.x compatibility fixes validated against WHMCS 9.0.4.

Original project credit remains with [0100Dev](https://github.com/0100Dev/WHMCS-Mollie-Payments). Fork-specific changes are tracked in [CHANGELOG.md](CHANGELOG.md).

### Installation
+ Log in to your (s)FTP.
+ Download the `WHMCS-Mollie-Payments.zip` from the [releases page](https://github.com/ICTShift/WHMCS-Mollie-Payments/releases) (**PLEASE NOTE:** **not** `Source code (zip)` or `Source code (tar.gz)`!).
+ Upload all the files from the `src` folder to the `/modules/gateways` folder in your WHMCS installation.

### Webhooks
This fork supports both Mollie's legacy payment webhooks and Mollie's newer signed next-gen webhooks.

+ Legacy mode keeps working with the per-payment `webhookUrl` sent during payment creation.
+ Next-gen mode can be enabled by configuring a Mollie webhook that points to the same `callback.php` endpoint and by filling in the optional `Webhook signing secret` field in each active Mollie gateway inside WHMCS.
+ For best results with next-gen webhooks, subscribe to full payloads so the callback can resolve the transaction directly from signed metadata without extra API lookups.

New payments now include both `transaction_id` and `gateway` metadata, which lets the callback resolve the correct WHMCS transaction and gateway more directly.

### Payment Methodes
All payment methods from Mollie are supported (which is also supported by their API). Enable the desired payment methods by activating the gateway in WHMCS.

Support for new payment methods must be added manually, due to the structure of this gateway. It can therefore take a while before a new payment method is supported. Is it urgent? Contact our paid support or add support for it yourself and contribute it back using a pull request.

You can use `Mollie Checkout` to use the Mollie Payments checkout pages. In this case it'll use the Mollie Payments checkout screen and show all enabled payment methodes in your Mollie account.

### Support
Support is best-effort through the Github issue tracker. Business support (responsetime within 24 hours, normally less then 1 hour) through our [website](https://0100dev.nl/) against our hourly rate at € 75,- excl. VAT. Please create an account at our website before contacting us.

[More information through Mollie about Mollie Payments](https://www.mollie.com/en/payments)
