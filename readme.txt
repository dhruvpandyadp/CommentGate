=== CommentGate  ===
Contributors: dhruvpandya
Donate link: https://www.paypal.com/paypalme/dhruvpandyadp97
Tags: comments, paid comments, monetization, paywall, comment paywall
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Monetize comments by requiring Stripe or PayPal payment before visitors can comment.

== Description ==

**CommentGate** helps site owners turn comment access into a paid engagement flow. Visitors enter one email address, complete payment through Stripe or PayPal, and unlock the comment form for protected content.

Use CommentGate for paid communities, expert Q&A posts, premium discussion areas, gated announcements, niche publications, and any site where comment access should have value.

= Main Features =

* **Pay Before Commenting** - Hide the comment textarea and submit button until payment access is verified.
* **Stripe and PayPal** - Enable Stripe, PayPal, or both payment gateways.
* **Per-Content Control** - Enable paid comments globally, then override settings per post, page, or supported custom post type.
* **Flexible Pricing** - Set a global price, custom per-content prices, and currency from a dropdown.
* **Default USD Currency** - Start with USD, then switch to another supported currency when needed.
* **Email-Based Access** - Guests enter one email address for receipt and access tracking.
* **Logged-In User Support** - Logged-in users can unlock comments with account-based access.
* **Auto Approve Paid Comments** - Paid comments can be approved automatically after CommentGate access is verified.
* **Admin Payment Email Alerts** - Send the site administrator an email when a payment completes with site From and Reply-To headers.
* **Customer Email Templates** - Send payment-complete and refund emails through WordPress mail using HTML invoice format by default or simple text-style email, with an optional Media Library logo for HTML emails, editable templates, payment/refund previews, and footer.
* **Secure Email Access Links** - Let customers reopen the paid comment area from their receipt until comment credits are used or access expires.
* **Role Exemptions** - Let selected roles comment without payment.
* **Optional Access Expiry** - Limit how long a duration-based payment unlock remains active in minutes.
* **Comment Credit Access** - Sell a specific number of comments per purchase instead of time-based access. This is the default access type.
* **Per-Content Access Type** - Override duration-based or comment-credit access on individual posts, pages, or custom post types.
* **Transaction History** - Review payment records, filter by status, gateway, and date range, view earning summaries, export CSV reports, refund unused paid access, mark pending records, and delete records individually or in bulk.
* **Payment API Configuration** - Manage Stripe and PayPal credentials from one dashboard with a setup checklist.
* **Appearance Controls** - Customize payment button text and optional custom button colors.
* **WP-CLI Support** - Inspect settings, list payments, view payment records, and refund unused paid access from the command line.
* **Theme-Friendly Frontend** - Uses theme button styles by default while hiding unsupported comment fields before payment.
* **Webhook Support** - Supports gateway webhook callbacks for payment lifecycle updates.

= CommentGate Dashboard =

CommentGate lives under **Comments > CommentGate** and includes one tabbed dashboard:

* **General Settings** - Enable CommentGate, select post types, choose currency, configure price, free roles, access type, access duration in minutes, comment quantity, and auto approval.
* **Transaction History** - View earning summaries, filter by paid/pending/refunded status, gateway, and date range, export CSV reports, refund unused paid access, and manage pending or deleted transactions.
* **Payment API Configuration** - Add Stripe or PayPal API credentials and webhook details, then review the setup checklist.
* **Email Settings** - Configure admin payment alerts, customer payment emails, refund emails, HTML invoice or simple text-style email format, optional Media Library logo or logo URL, editable email templates, payment/refund previews, and the email footer.
* **Appearance** - Customize button labels and optional colors.

General Settings also includes a quick link to WordPress **Website Discussion Settings** so comment moderation, notifications, avatars, and default discussion rules stay easy to reach.

= Payment Gateways =

CommentGate can enable one or both payment gateways:

* Stripe Checkout
* PayPal Checkout

If both gateways are enabled, visitors can choose Stripe or PayPal on the payment wall.

= Third-Party Services =

CommentGate connects to third-party payment services only when an administrator configures a gateway and a visitor starts checkout, returns from checkout, when an administrator starts a refund, or when the payment service sends a configured webhook.

Stripe requests are sent to `https://api.stripe.com` to create Checkout Sessions, create refunds, verify webhook events, and update payment status. Data sent to Stripe may include the selected post title, payment amount, currency, plugin payment ID, access type, comment quantity, Stripe payment intent ID, and customer email when entered by a guest. Stripe terms: https://stripe.com/legal/ssa. Stripe privacy policy: https://stripe.com/privacy.

PayPal requests are sent to `https://api-m.sandbox.paypal.com` in sandbox mode or `https://api-m.paypal.com` in live mode to create orders, capture approved orders, create refunds, obtain API access tokens, verify webhook signatures, and update payment status. Data sent to PayPal may include the selected post title, payment amount, currency, plugin payment ID, PayPal order or capture ID, and configured API credentials. PayPal user agreement: https://www.paypal.com/us/legalhub/useragreement-full. PayPal privacy statement: https://www.paypal.com/us/legalhub/privacy-full.

= Refunds =

Administrators can refund paid transactions from Transaction History when access has not been used. For comment quantity access, unused means all purchased comment credits are still available. For duration access, unused means CommentGate has not attached the payment to a submitted comment. Refund actions call the configured Stripe or PayPal API, then mark the payment as refunded in CommentGate with refund ID, reason, and refund date fields when available.

= API Documentation =

Use these official guides when creating payment credentials:

* Stripe API keys: https://docs.stripe.com/keys
* PayPal REST API credentials: https://developer.paypal.com/api/rest/

= WP-CLI Commands =

CommentGate registers the `wp commentgate` command when WP-CLI is available.

* `wp commentgate status` - Show gateway, access, and webhook setup status.
* `wp commentgate settings` - Show plugin settings with API secrets masked by default.
* `wp commentgate settings --show-secrets` - Show plugin settings including API secrets.
* `wp commentgate payments --status=paid` - List payment records.
* `wp commentgate payment 123` - Show one payment record.
* `wp commentgate refund 123 --yes` - Refund unused paid access.

= Webhook Events =

For Stripe, configure the webhook URL shown in CommentGate and send these events:

* `checkout.session.completed`
* `charge.refunded`
* `checkout.session.async_payment_failed`

Stripe webhook secret is required so CommentGate can verify Stripe-signed webhook requests.

For PayPal, configure the webhook URL shown in CommentGate and send this event:

* `PAYMENT.CAPTURE.REFUNDED`

Copy the PayPal webhook ID into CommentGate settings so webhook signatures can be verified.

== Installation ==

1. Upload the `commentgate` folder to `/wp-content/plugins/`, or install the plugin zip through **Plugins > Add New > Upload Plugin**.
2. Activate **CommentGate** from the **Plugins** screen.
3. Open **Comments > CommentGate**.
4. Enable CommentGate in **General Settings**.
5. Choose post types, price, currency, free roles, access type, access duration in minutes, and comment quantity.
6. Configure admin and customer email options under **Email Settings**.
7. Select Stripe, PayPal, or both.
8. Add API credentials under **Payment API Configuration**.
9. Configure webhook URLs in the selected payment provider.
10. Customize button labels and colors under **Appearance** if needed.

== Frequently Asked Questions ==

= Can I use Stripe and PayPal at the same time? =

Yes. Enable Stripe, PayPal, or both in General Settings. When both are enabled, the payment wall shows one button for each gateway.

= Can I set different prices for different posts or pages? =

Yes. Set a global price in General Settings, then use the CommentGate meta box on supported content to override the price for that post, page, or custom post type.

= Does CommentGate hide the normal comment form before payment? =

Yes. Before access is verified, CommentGate hides the comment textarea and submit button and shows only the payment email field and payment button.

= Will paid comments be approved automatically? =

Yes, when auto approval is enabled. Paid comments can be approved automatically after CommentGate verifies payment access.

= Can logged-in users skip payment? =

Only if their role is selected as exempt in General Settings. Otherwise, logged-in users must pay like guests.

= How does guest access work? =

Guests enter one email address before checkout. After successful payment, CommentGate stores verified access and uses a secure token so the comment form unlocks. Customer receipt emails include a secure access link, so guests can reopen the paid comment area later until comment credits are used or access expires.

= How can I reduce email spam-folder placement? =

CommentGate sends email through the site's normal WordPress mail system and adds site From and Reply-To headers. For best deliverability, configure authenticated SMTP and DNS records such as SPF, DKIM, and DMARC for the sending domain.

= Can I customize the payment button? =

Yes. Appearance settings let admins change Stripe and PayPal button text. CommentGate uses theme button styles by default and can use custom colors when enabled.

= Where do I manage payment records? =

Open **Comments > CommentGate > Transaction History**. Admins can view records, mark records pending, or delete records individually or in bulk.

= Can I export transaction records? =

Yes. Transaction History includes a CSV export that respects the current status, gateway, and date range filters.

= Does this plugin replace WordPress Discussion Settings? =

No. CommentGate controls paid access to comment forms. WordPress Discussion Settings still control normal comment rules, moderation behavior, notifications, avatars, and related site-wide defaults.

== Screenshots ==

1. CommentGate Dashboard with General Settings.
2. Transaction History with earning summaries and payment filters.
3. Payment API Configuration for Stripe or PayPal credentials.
4. Email Settings with admin alerts, customer emails, HTML invoice or simple text-style email format, Media Library logo selection, and editable templates.
5. Appearance tab with payment button labels and optional custom colors.
6. Frontend payment wall before comment access unlocks.

== Changelog ==

= 1.0.0 =

Initial release.

* Added paid comment access for posts, pages, and public custom post types.
* Added Stripe Checkout and PayPal Checkout support.
* Added Stripe and PayPal gateway selection with support for enabling one or both gateways.
* Added global and per-content pricing.
* Added currency selector with USD default and common supported currencies.
* Added duration-based access in minutes and comment-credit access with comment-credit access as the default.
* Added per-content access type and comment quantity overrides.
* Added guest email access and logged-in user access.
* Added secure access tokens and server-side comment blocking.
* Added role exemptions.
* Added paid comment auto approval option.
* Added Transaction History with earning summaries, status/gateway/date filters, CSV export, pagination, refund actions, pending status actions, and delete actions.
* Added admin-only refunds for unused paid access through Stripe or PayPal.
* Added refund audit fields for refund ID, refund reason, and refunded date.
* Added admin payment email alerts.
* Added customer payment-complete and refund emails with HTML invoice or simple text-style email format, optional Media Library logo, editable templates, secure access links, payment/refund previews, and editable footer in Email Settings.
* Added Payment API Configuration tab with Stripe and PayPal credential fields, webhook URLs, and setup checklist.
* Added Appearance tab with button text and optional button color controls.
* Added WP-CLI commands for status checks, settings inspection, payment listing, payment lookup, and refunds.
* Added WordPress Discussion Settings shortcut.
* Added WordPress.org security hardening for nonces, capabilities, sanitization, escaping, prepared queries, webhook signature checks, and third-party service disclosures.

== Upgrade Notice ==

= 1.0.0 =

Initial public release.
