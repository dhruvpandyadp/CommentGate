# CommentGate - Paid Comment Access for WordPress

[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4)](https://www.php.net/)
[![Stripe](https://img.shields.io/badge/Stripe-Checkout-635bff)](https://stripe.com/)
[![PayPal](https://img.shields.io/badge/PayPal-Checkout-003087)](https://www.paypal.com/)
[![License](https://img.shields.io/badge/License-GPL--2.0--or--later-green)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Version](https://img.shields.io/badge/Version-1.0.0-brightgreen)](https://commentgate.com)

**CommentGate** helps WordPress site owners monetize engagement by requiring visitors to complete Stripe or PayPal payment before commenting on selected posts, pages, or public custom post types.

![Paid Comments](https://img.shields.io/badge/Paid%20Comments-Enabled-blue)
![Comment Credits](https://img.shields.io/badge/Access-Comment%20Credits-orange)
![Refunds](https://img.shields.io/badge/Refunds-Unused%20Access-green)
![WP CLI](https://img.shields.io/badge/WP--CLI-Supported-lightgrey)

## Key Features

### Paid Comment Access
- **Pay Before Commenting** - Hide the normal comment fields until payment access is verified.
- **Comment Quantity Access** - Sell 1, 2, or custom comment credits per purchase.
- **Duration Access** - Unlock commenting for a set number of minutes, with `0` for no expiry.
- **Per-Content Overrides** - Override payment requirement, price, access type, and comment quantity per post, page, or custom post type.
- **Role Exemptions** - Let selected WordPress roles comment without payment.

### Payment Gateways
- **Stripe Checkout** - Redirect visitors to Stripe-hosted checkout.
- **PayPal Checkout** - Redirect visitors to PayPal approval checkout.
- **Enable One Or Both Gateways** - Show Stripe, PayPal, or both buttons on the frontend payment wall.
- **Webhook Support** - Confirm payments and refunds through gateway webhooks.

### Admin Dashboard
- **General Settings** - Enable paid comments, choose post types, set pricing, configure access type, and manage moderation.
- **Transaction History** - View payments, earning summaries, filters, CSV export, pagination, refunds, pending actions, and delete actions.
- **Payment API Configuration** - Add Stripe or PayPal credentials and review setup checks.
- **Email Settings** - Configure admin alerts, customer receipts, refund emails, templates, logo, footer, and previews.
- **Appearance** - Customize Stripe/PayPal button text and optional custom button colors.

### Email And Access Links
- **Customer Receipts** - Send payment-complete and refund emails through WordPress mail.
- **HTML Invoice Or Simple Text-Style Email** - Choose format from admin settings.
- **Media Library Logo** - Add optional logo to HTML invoice emails.
- **Secure Access Links** - Customers can reopen paid comment area until credits are used or access expires.
- **Admin Alerts** - Notify the site administrator when payment completes.

### Refunds And Reporting
- **Unused Access Refunds** - Refund payments only when paid access has not been used.
- **Stripe And PayPal API Refunds** - Refund from WordPress admin when gateway data is available.
- **Refund Audit Fields** - Store refund ID, reason, and refund date when available.
- **Earning Summaries** - See total paid earnings, pending value, transaction counts, and remaining comment credits.
- **CSV Export** - Export filtered transaction history.

## Quick Start

### Requirements
- WordPress 6.0 or higher
- PHP 7.4 or higher
- Stripe account or PayPal developer account
- HTTPS site for live payments

### Installation

1. **Download or clone this repository**
   ```bash
   git clone https://github.com/dhruvpandyadp/commentgate.git
   ```

2. **Install the plugin**
   ```text
   Copy the commentgate folder to wp-content/plugins/
   ```

3. **Activate in WordPress**
   ```text
   WordPress Admin -> Plugins -> CommentGate -> Activate
   ```

4. **Open settings**
   ```text
   Comments -> CommentGate
   ```

5. **Configure paid comment access**
   - Enable paid comments.
   - Choose supported post types.
   - Set price and currency.
   - Select comment quantity or duration access.
   - Enable Stripe, PayPal, or both.

6. **Add payment credentials**
   - Open **Payment API Configuration**.
   - Add Stripe and/or PayPal API credentials.
   - Configure webhook URLs shown in settings.

7. **Run a sandbox payment**
   - Use Stripe test mode or PayPal sandbox first.
   - Confirm transaction changes from pending to paid.
   - Confirm comment form unlocks after payment.

## Dashboard Overview

| Tab | Purpose |
|-----|---------|
| General Settings | Enable paid comments, post types, gateways, price, roles, access type, duration, and comment quantity |
| Transaction History | View earnings, filter payments, export CSV, refund unused access, and manage records |
| Payment API Configuration | Store Stripe/PayPal credentials, webhook details, and setup checklist |
| Email Settings | Configure admin emails, customer emails, templates, logo, footer, and previews |
| Appearance | Customize button labels and optional custom colors |

## Frontend Flow

| Step | Visitor Experience |
|------|--------------------|
| 1 | Visitor opens protected content |
| 2 | Comment form is locked behind payment wall |
| 3 | Visitor enters email address for receipt/access |
| 4 | Visitor clicks `Pay with Stripe to Comment` or `Pay with PayPal to Comment` |
| 5 | Gateway checkout opens |
| 6 | After payment, visitor returns to content |
| 7 | Comment form unlocks until credits are used or access expires |

## Access Types

### Comment Quantity Based
```text
Example: 1 comment credit
Visitor pays once and can submit 1 comment.
After credit is used, another payment is required.
```

### Duration Based
```text
Example: 15 minutes
Visitor pays once and can comment during the access window.
Use 0 minutes for no expiry.
```

## Payment Gateway Setup

### Stripe
- Add Stripe secret key.
- Add Stripe webhook secret.
- Configure webhook URL from the settings page.
- Send these events:
  - `checkout.session.completed`
  - `charge.refunded`
  - `checkout.session.async_payment_failed`

Official docs: [Stripe API Keys](https://docs.stripe.com/keys)

### PayPal
- Add PayPal client ID.
- Add PayPal secret.
- Choose sandbox or live mode.
- Configure webhook URL from the settings page.
- Send this event:
  - `PAYMENT.CAPTURE.REFUNDED`

Official docs: [PayPal REST API Credentials](https://developer.paypal.com/api/rest/)

## WP-CLI Commands

CommentGate registers `wp commentgate` when WP-CLI is available.

```bash
wp commentgate status
wp commentgate settings
wp commentgate settings --show-secrets --format=json
wp commentgate payments --status=paid
wp commentgate payments --gateway=stripe --format=csv
wp commentgate payment 123
wp commentgate refund 123 --yes
```

## Technical Details

### Built With
- **WordPress Plugin API** - Admin menus, settings, comments, hooks, REST routes, and mail.
- **Stripe Checkout API** - Hosted checkout, payment confirmation, and refunds.
- **PayPal Checkout API** - Order creation, capture, webhook verification, and refunds.
- **WP-CLI** - Command-line inspection and refund tools.
- **WordPress Mail** - Admin alerts and customer emails through the site mail system.

### Plugin Structure

```text
commentgate/
  commentgate.php
  readme.txt
  README.md
  includes/
    class-commentgate-admin-payments.php
    class-commentgate-cli.php
    class-commentgate-comment-gate.php
    class-commentgate-payments-table.php
    class-commentgate-paypal-gateway.php
    class-commentgate-plugin.php
    class-commentgate-settings.php
    class-commentgate-stripe-gateway.php
    class-commentgate-webhooks.php
  templates/
    payment-box.php
  assets/
    css/
      admin.css
      frontend.css
    js/
      admin.js
      frontend.js
  languages/
    index.php
```

## Third-Party Services

CommentGate connects to third-party payment services only when a gateway is configured and a visitor starts checkout, returns from checkout, an administrator starts a refund, or a configured webhook is received.

| Service | Endpoint | Purpose |
|---------|----------|---------|
| Stripe | `https://api.stripe.com` | Checkout sessions, refunds, webhook payment status |
| PayPal Sandbox | `https://api-m.sandbox.paypal.com` | Sandbox orders, captures, refunds, webhook verification |
| PayPal Live | `https://api-m.paypal.com` | Live orders, captures, refunds, webhook verification |

Data sent to gateways can include post title, amount, currency, plugin payment ID, access type, comment quantity, gateway payment IDs, capture IDs, and guest email when entered.

## Refund Rules

```text
Comment quantity access:
Refund allowed when all purchased comment credits remain unused.

Duration access:
Refund allowed when CommentGate has not attached the payment to a submitted comment.
```

Refunds call the configured Stripe or PayPal API first, then mark the payment record as refunded inside WordPress.

## Email Deliverability

CommentGate sends email through the normal WordPress mail system and adds site From and Reply-To headers. For best inbox placement, configure authenticated SMTP plus SPF, DKIM, and DMARC for the sending domain.

## Use Cases

### Premium Discussions
```text
Scenario: Site owner wants paid access to high-value comment threads
Action: Enable CommentGate for selected posts
Result: Visitors pay before joining discussion
Benefit: Monetized engagement without locking full content
```

### Expert Q&A
```text
Scenario: Expert publishes posts and charges per question/comment
Action: Use comment quantity based access with 1 comment per purchase
Result: Each paid visitor gets one verified comment credit
Benefit: Simple paid Q&A workflow
```

### Community Moderation
```text
Scenario: Admin wants fewer low-quality comments
Action: Require small payment before comment form unlocks
Result: Spam and drive-by comments decrease
Benefit: Cleaner discussion and stronger intent
```

## Important Notes

- Use sandbox/test credentials before enabling live payments.
- Configure webhooks so pending payments update correctly.
- Live payments should run on HTTPS.
- CommentGate controls paid access to comment forms; WordPress Discussion Settings still control site-wide comment rules.
- Email deliverability depends on site mail configuration and domain DNS records.

## WordPress.org Metadata

| Field | Value |
|-------|-------|
| Contributors | `dhruvpandya` |
| Requires at least | `6.0` |
| Tested up to | `7.0` |
| Requires PHP | `7.4` |
| Stable tag | `1.0.0` |
| License | `GPL-2.0-or-later` |
| Text Domain | `commentgate` |

## Donate

Support CommentGate development:

[https://www.paypal.com/paypalme/dhruvpandyadp97](https://www.paypal.com/paypalme/dhruvpandyadp97)

## License

This project is licensed under the GPL-2.0-or-later license.

## Author

**Created by Dhruv Pandya**

- Website: [https://pandyadhruv.com](https://pandyadhruv.com)
- Linkedin: [https://www.linkedin.com/in/dhruvpandyadp/](https://www.linkedin.com/in/dhruvpandyadp/)

---

## Ready To Monetize WordPress Comments?

Install CommentGate, connect Stripe or PayPal, run a sandbox payment, and turn selected comment forms into paid discussion spaces.
