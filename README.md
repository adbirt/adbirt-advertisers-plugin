# Adbirt Advertisers (WordPress plugin)

Conversion tracking for advertisers whose Adbirt campaigns land on a WordPress site. Version 2 works with the
current Adbirt backend (`POST https://api.adbirt.com/api/v1/events`); version 1 called endpoints of the legacy
adbirt.com platform that no longer exist.

## Setup

1. In the Adbirt dashboard, open **SDKs & tracking**, pick the campaign, choose **Adbirt SDK**, set the landing page
   to a page on this site, add the events you pay for, save, then **Generate tokens**.
2. In WordPress: **Settings → Adbirt**. Paste the publishable token (`adb_pk_…`). For sign-ups and WooCommerce
   orders reported from the server, also paste the secret token (`adb_sk_…`); it is stored on this server only.
3. Use the same event names as the campaign's payable events (defaults: `registration`, `purchase`).

The bundled Adbirt web SDK (`assets/js/adbirt.js`, from `adbirt/adbirt-web-sdk`) keeps the Adbirt click id in a
first-party cookie on every page.

## What is reported

| Where | How | Event |
|---|---|---|
| Any page | `[adbirt-event name="registration"]` (optional `value`, `currency`, `transaction_id`) | on page view |
| Any element | `data-adbirt-event="registration"` on a `<form>` (submit) or anything else (click) | on submit / click |
| Download button | `[adbirt-download-link href="…" event="download"]Download[/adbirt-download-link]` | on click |
| WordPress sign-up | automatic with the secret token (`user_register`) | sign-up event |
| WooCommerce paid order | automatic with the secret token (`woocommerce_payment_complete` / `…_order_status_completed`) | order event, value = order total, `transaction_id` = `wc-order-<id>` |

Each WooCommerce order is reported once (order meta `_adbirt_reported`). With a repeatable payable event on the
campaign, every order is charged; each order number is charged once. Server events are sent as confirmed
(`validation_status: validated`), so campaigns that pay only for confirmed conversions are charged.

Version 1 shortcodes still work: `[adbirt-success-page-tracker]`, `[adbirt-redirect-form-submit-success]` report
`conversion` (or `event="…"`), `[adbirt-payment-success]` reports `purchase`; the `…-init` and landing ones are
no longer needed and render nothing.

## Tested

End to end on WordPress 7.1.4 + WooCommerce + PHP 8.2 (Docker) against the Adbirt backend on a throwaway
database, with a real headless Chrome: ad click → landing page (click id cookie) → thank-you page shortcode
(registration charged once, also after a reload) → download link (charged) → WordPress sign-up and two
WooCommerce paid orders from the server (each order charged once, a later "completed" not re-reported). Wallet
moved by exactly the configured payouts.
