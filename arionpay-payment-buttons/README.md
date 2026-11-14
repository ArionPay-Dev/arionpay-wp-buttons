# ArionPay Payment Buttons for WordPress

"Pay with crypto" buttons for any WordPress site, no shop plugin needed: services, deposits, invoices, tips and donations. Your customers pay with Bitcoin, USDT, Litecoin, Ethereum, TRON and more through [ArionPay](https://arionpay.com), and the money goes straight to your own wallet.

Tested on WordPress 7.1.4 with PHP 8.3: fixed and "enter your amount" buttons, refused tampered prices, payment records, and webhook checks.

## Install

1. **Plugins > Add New > Upload Plugin**, choose `arionpay-payment-buttons.zip`, install and activate.
2. Go to **Settings > ArionPay** and fill in:

| Setting | Where in the ArionPay dashboard | Starts with |
| --- | --- | --- |
| API key | Open your store, **API key** | `pk_` |
| API secret | Open your store, **API secret** (asks for your authenticator code) | `sk_` |
| Webhook signing secret (optional) | **Webhooks** page, **signing secret** | `whsec_` |

3. Keep **Test mode** on, save, and press **Check connection**.

The secrets are never shown again on the settings page; leave the fields empty to keep them. With the webhook secret, every payment is listed under **ArionPay payments** in your admin area with its status.

## Add a button

Put a shortcode in any page or post (in the block editor, use a **Shortcode** block):

```
[arionpay_button amount="25" currency="USD" label="Pay with crypto" description="Consulting hour"]
```

A visitor-chosen amount (donations, tips, paying an invoice), limited to a range:

```
[arionpay_button custom_amount="yes" min="5" max="1000" currency="EUR" label="Donate with crypto" description="Donation"]
```

| Option | Meaning |
| --- | --- |
| `amount` | Fixed price, for example `25` or `19.90`. |
| `currency` | USD, EUR, GBP, CAD, AUD, CHF, JPY, TRY, AED, INR, BRL, RUB, CNY, SGD, HKD, KRW, MXN, PLN, SEK, NOK or ZAR. |
| `custom_amount="yes"` | The visitor types the amount, between `min` and `max`. |
| `label` | Button text. |
| `description` | What the payment is for; shown on the ArionPay page and in your dashboard. |
| `success` | Page to show after paying (default: the Thank-you page in the settings, or your home page). |

The price and limits are signed with your site's secret keys, so a visitor cannot change them in the page.

## How it works

1. The visitor presses the button and goes to the secure ArionPay page to choose a coin and pay.
2. ArionPay confirms the payment on the network and shows it in your dashboard (and your payment alerts).
3. With the webhook secret set, the payment's record under **ArionPay payments** turns **paid**. Developers can act on it with the `arionpay_button_paid` action (`$post_id`, `$invoice`).

Coming back from the payment page does not prove payment: check your ArionPay dashboard or the payment record before you deliver.

## Good to know

- Turn **Test mode** off before you take real payments.
- A payment that is not exact goes to **Payment Radar** in your ArionPay dashboard, where you decide.
- Refunds are made from your own wallet.
- The plugin sends ArionPay only the amount, currency, description and a reference. It does not collect names or emails.
