# Sharehaus Webhook Catalog

> Generated from v3.0.17 fork. Documents all 17 webhook event types and their disposition.

---

## ✅ Retained — Operational Lifecycle (UM)

These fire from operational events within WordPress multisite. They remain in Ultimate Multisite.

| # | Event Slug | Label | Trigger | Payload |
|---|---|---|---|---|
| 1 | `site_published` | Site Published | `wu_pending_site_published` | site, customer, membership |
| 2 | `confirm_email_address` | Email Verification Needed | Customer created, email unverified | customer |
| 3 | `domain_created` | New Domain Mapping Added | `wu_domain_created` | domain, site, membership, customer |
| 4 | `membership_expired` | Membership Expired | `wu_transition_membership_status` → expired | membership, customer |
| 5 | `demo_site_expiring` | Demo Site Expiring | `wu_transition_membership_status` → demo_warning | membership, customer, site |
| 6 | `subsite_post_created` | Subsite Post Created | Post published on tenant site | subsite, post |
| 7 | `subsite_cpt_created` | Subsite Custom Post Type Entry Created | CPT entry created on tenant site | subsite, cpt |
| 8 | `subsite_user_registered` | Subsite User Registered | New user on tenant site | subsite, user |
| 9 | `subsite_woocommerce_order` | Subsite WooCommerce Order Placed | New order on tenant site | subsite, order |

---

## 🔄 Studio — Reimplement in Frontend App

These fire from commercial/payment flows that Studio owns. Remove from UM, catalogued for future reimplementation.

### Payment Events (already removed in v3.0.17)

| # | Event Slug | Label | UM Trigger (defunct) | Studio Equivalent |
|---|---|---|---|---|
| 10 | `payment_received` | Payment Received | `wu_do_event('payment_received')` | Fire after Stripe payment confirmation |
| 11 | `renewal_payment_created` | New Renewal Payment Created | `wu_do_event('renewal_payment_created')` | Fire after subscription renewal processed |
| 12 | `invoice_sent` | Invoice Sent | Admin invoice action | Fire after invoice generated + emailed |
| 13 | `payment_failed` | Recurring Payment Failed | `wu_do_event('payment_failed')` | Fire after Stripe payment failure webhook |

### Checkout Events (to be removed from UM)

| # | Event Slug | Label | UM Trigger (defunct) | Studio Equivalent |
|---|---|---|---|---|
| 14 | `checkout_started` | Checkout Started | `wu_do_event('checkout_started')` | Fire when user enters Studio onboarding |
| 15 | `checkout_step_completed` | Checkout Step Completed | `wu_do_event('checkout_step_completed')` | Fire on each onboarding step completion |
| 16 | `checkout_completed` | Checkout Completed | `wu_do_event('checkout_completed')` | Fire when onboarding + payment complete |
| 17 | `checkout_failed` | Checkout Failed | `wu_do_event('checkout_failed')` | Fire when onboarding/payment fails |

---

## Summary

| Disposition | Count | Events |
|---|---|---|
| ✅ Keep (UM) | 9 | 1–9 |
| 🔄 Studio (payment) | 4 | 10–13 (already removed) |
| 🔄 Studio (checkout) | 4 | 14–17 (to be removed) |
| **Total catalogued** | **17** | |

---

## Removal Plan — Checkout Events

**Source file:** `inc/class-signup-metrics.php`

Four checkout event registrations and their `wu_do_event()` dispatch calls must be removed. Subsite events in the same file must be preserved.

**After removal:** `wu_get_event_types()` returns 9 events. No checkout events in UI dropdown.