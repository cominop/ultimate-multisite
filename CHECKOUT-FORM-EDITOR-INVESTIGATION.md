# Checkout Form Editor — Comprehensive Investigation Report

Generated: 2026-09-12
Source: /tmp/ultimate-multisite/

---

## 1. TECH STACK

**Framework: Vue.js 2.x (NOT React, NOT Alpine, NOT pure jQuery)**

### How the stack is wired:

**PHP → JS bootstrap:**
- Admin page class: `inc/admin-pages/class-checkout-form-edit-admin-page.php` (line 1336)
- Registers script: `wu-get-asset('checkout-forms-editor.js', 'js')` with deps `['jquery', 'wu-vue', 'underscore', 'wu-vue-sortable', 'wu-vue-draggable']`

**Vue.js loading:**
- `scripts/build-vue.js` (line 115-118): Copies Vue 2.7.x UMD from `node_modules/vue/dist/vue.js` → `assets/js/lib/vue.js`
- Appends `window.wu_vue = { Vue: window.Vue, ... }` wrapper (lines 38-47)
- Registered in `inc/class-scripts.php` line 113: `$this->register_script('wu-vue', wu_get_asset('lib/vue.js', 'js'))`

**Vue ecosystem libraries (all UMD, no build step):**
- `assets/js/lib/vue.js` — Vue 2.7.x (dev + prod)
- `assets/js/lib/vue-draggable.js` — vuedraggable (drag-and-drop steps and fields)
- `assets/js/lib/vue-the-mask.js` — input masking
- `assets/js/lib/v-money.js` — currency formatting
- `assets/js/lib/vue-apexcharts.js` — charts (not used in editor)
- `assets/js/lib/sortablejs.js` — underlying sortable library for vue-draggable

**Key JS files for the editor:**
| File | Purpose |
|------|---------|
| `assets/js/checkout-forms-editor.js` | Main Vue app (289 lines): initializes `wu_checkout_forms_editor_app` |
| `assets/js/checkout-form-editor-modal.js` | Modal width control when adding fields (24 lines) |
| `assets/js/checkout-forms-editor.min.js` | Minified production build |
| `assets/js/vue-apps.js` | Generic Vue app framework (form rendering, data binding) |
| `assets/css/checkout-editor.css` | Editor-specific styles |

**Vue app initialization (checkout-forms-editor.js, line 31):**
```js
wu_checkout_forms_editor_app = new Vue({
  el: '#wu-checkout-editor-app',
  name: 'CheckoutEditor',
  data() {
    return Object.assign({}, {
      dragging: false,
      search: '',
      delete_step_id: '',
      preview_error: false,
      preview: false,
      loading_preview: false,
      preview_content: '',
      iframe_preview_url: '',
    }, wu_checkout_form);  // wu_checkout_form is localized from PHP
  },
  components: {
    vuedraggable,
    'wu-draggable-table': draggable_table,
  },
```

**Underscore.js** (lodash-like) used for array operations: `_.findWhere`, `_.reject`, `_.reduce`, `_.indexOf`

**Template files (Vue templates in `<script type="text/x-template">`):**
- `views/base/checkout-forms/steps.php` — Main editor layout with Vue directives
- `views/base/checkout-forms/js-templates.php` — `<script type="text/x-template" id="wu-table">` for field rows

**No webpack/bundler** — everything is UMD scripts concatenated or referenced individually on the page. jQuery wraps Vue initialization.

---

## 2. WHY "LOADING PREVIEW" HANGS

### The preview flow, traced:

**Step 1: User clicks "Preview" button**
- Template: `views/base/checkout-forms/steps.php` line 77: `@click.prevent="get_preview()"`

**Step 2: `get_preview()` method (checkout-forms-editor.js lines 82-123)**
```js
get_preview(type = null) {
  if (type === null) {
    this.preview = ! this.preview;
  } else {
    this.preview = true;
  }
  if (this.preview) {
    this.loading_preview = true;        // ← Shows LOADING PREVIEW spinner
    const that = this;
    const preview_type = type !== null ? type : 'user';
    that.iframe_preview_url = that.register_page +
      '?action=wu_generate_checkout_form_preview' +
      '&form_id=' + that.form_id +
      '&type=' + preview_type +
      '&uniq=' + (Math.random() * 1000);
    // ↑ Sets iframe src, waits for 'load' event
    $('#wp-ultimo-checkout-preview').off('load').one('load', function() {
      that.loading_preview = false; // ← Only clears spinner on iframe load
      // ...
    });
  }
}
```

**Step 3: `register_page` value comes from PHP (lines 1340-1355 in admin page):**
```php
wp_localize_script('wu-checkout-form-editor', 'wu_checkout_form', [
  'register_page' => wu_get_registration_url(),  // ← THIS is the critical value
  // ...
]);
```

**Step 4: `wu_get_registration_url()` (`inc/functions/checkout.php` lines 149-176):**
```php
function wu_get_registration_url($path = false) {
  $checkout_pages = \WP_Ultimo\Checkout\Checkout_Pages::get_instance();
  $url = $checkout_pages->get_page_url('register');
  if (!$url) {
    // Fallback: look for a page with slug 'register' + [wu_checkout] shortcode
    $url = wu_switch_blog_and_run(function() {
      $maybe_register_page = get_page_by_path('register');
      if ($maybe_register_page && has_shortcode($maybe_register_page->post_content, 'wu_checkout')) {
        return get_the_permalink($maybe_register_page->ID);
      }
    });
    return $url ?? '#no-registration-url';  // ← FALLBACK: fragment URL
  }
  return $url . $path;
}
```

**Step 5: `get_page_url('register')` (`inc/checkout/class-checkout-pages.php` lines 1016-1025):**
```php
public function get_page_url($page_slug = 'login') {
  $page = $this->get_signup_page($page_slug);
  if (!$page) {
    return false;  // ← Returns FALSE if no registration page configured
  }
  return wu_switch_blog_and_run(fn() => get_the_permalink($page));
}
```

**Step 6: `get_signup_page('register')` (lines 978-1007):**
```php
public function get_signup_pages() {
  return [
    'register' => wu_guess_registration_page(),  // ← Guesses or returns saved setting
    // ...
  ];
}
```

**Step 7: `wu_guess_registration_page()` (`inc/functions/pages.php` lines 18-44):**
```php
function wu_guess_registration_page() {
  return wu_switch_blog_and_run(function() {
    $saved_register_page_id = wu_get_setting('default_registration_page', 0);
    $page = get_post($saved_register_page_id);
    if ($page) return $page->ID;
    // Fallback: look for page with slug 'register'
    $maybe_register_page = get_page_by_path('register');
    if ($maybe_register_page && has_shortcode(...) && 'publish' === $maybe_register_page->post_status) {
      wu_save_setting('default_registration_page', $maybe_register_page->ID);
      return $maybe_register_page->ID;
    }
    return false;
  });
}
```

### ROOT CAUSE DIAGNOSIS:

The LOADING PREVIEW spinner hangs because **the iframe's `load` event never fires** (or fires on an invalid page that doesn't trigger the PHP handler). Three failure modes:

1. **No registration page configured**: If neither `default_registration_page` is set nor a published `register` page with `[wu_checkout]` exists on the main site, `wu_get_registration_url()` returns `'#no-registration-url'`. The iframe loads `#no-registration-url?action=wu_generate_checkout_form_preview...` — this is a **fragment-only URL** that the browser interprets as the current page, not an HTTP request. The `load` event may fire trivially on the fragment, but the PHP handler (`generate_checkout_form_preview`) is never reached.

2. **Registration page exists but is wrong type or on wrong site**: `wu_get_registration_url()` calls `wu_switch_blog_and_run()` to switch to the main site. If the registration page exists but doesn't contain the `[wu_checkout]` shortcode, the guess function returns false, leading to fallback `'#no-registration-url'`.

3. **The preview handler works, but the iframe loads an error**: Even if the correct page URL is used, the `generate_checkout_form_preview()` method (line 148-159) checks `wu_request('action') === 'wu_generate_checkout_form_preview'` at `init` hook. If the WordPress page loads but the `action` parameter doesn't arrive (e.g., URL rewriting, caching, redirects, or the page is a cached static page), the handler never fires and the iframe shows the normal registration page instead of the preview.

**Post-2.16.1 upgrade concern**: The `wu_get_registration_url()` function and the `Checkout_Pages` class haven't changed in a way that would break the URL resolution. However, if the upgrade moved/deleted the registration page or changed its slug, or if the `wu_guess_registration_page()` function now returns `false` due to changes in the page/post structure, the preview breaks.

**The spinner stays forever** because `loading_preview` is only set to `false` inside the iframe's `load` event handler (line 105-108). There is no timeout, no error fallback, no `onerror` handler. The `preview_error` flag (line 284-291 in template) exists but is never set to `true` in the JS code.

### Quick fix indicators:
- Open browser devtools → Network tab → check what URL the iframe actually loads
- Look for `#no-registration-url` in the iframe `src`
- If it's a real URL, check if the response contains the preview HTML or a full WordPress page
- The preview handler does not use REST API — it's a plain `init` hook checking `$_REQUEST['action']`

---

## 3. FEATURE INVENTORY

### What the form editor controls:

#### A. Steps (multi-step checkout flow)
- **Add/Edit/Delete steps** — via modal forms (`add_new_form_step`)
- **Drag-and-drop reorder** — vuedraggable on steps (steps.php line 114-123)
- **Step fields**: ID, Title, Description, Visibility (always/guests_only/logged_only), Element ID, CSS classes
- **Visibility rules**: Each step can be shown/hidden based on login status
- Template: `views/base/checkout-forms/steps.php`

#### B. Fields (24 registered types in `Signup_Fields_Manager`, line 50-73)

| Field Type | Class | Payment-specific? |
|-----------|-------|-------------------|
| `pricing_table` | `Signup_Field_Pricing_Table` | **YES** — product pricing display |
| `period_selection` | `Signup_Field_Period_Selection` | **YES** — billing period picker |
| `products` | `Signup_Field_Products` | **YES** — product selector |
| `template_selection` | `Signup_Field_Template_Selection` | **NO** — site template picker |
| `username` | `Signup_Field_Username` | **NO** — user account |
| `email` | `Signup_Field_Email` | **NO** — user account |
| `password` | `Signup_Field_Password` | **NO** — user account |
| `site_title` | `Signup_Field_Site_Title` | **NO** — site provisioning |
| `site_url` | `Signup_Field_Site_Url` | **NO** — site provisioning |
| `discount_code` | `Signup_Field_Discount_Code` | **YES** — coupon codes |
| `order_summary` | `Signup_Field_Order_Summary` | **YES** — cart/order display |
| `payment` | `Signup_Field_Payment` | **YES** — payment method selection |
| `order_bump` | `Signup_Field_Order_Bump` | **YES** — upsells |
| `billing_address` | `Signup_Field_Billing_Address` | **YES** — billing info |
| `steps` | `Signup_Field_Steps` | **NO** — step navigation UI |
| `text` | `Signup_Field_Text` | **NO** — generic text input |
| `checkbox` | `Signup_Field_Checkbox` | **NO** — generic checkbox |
| `color_picker` | `Signup_Field_Color` | **NO** — color selector |
| `select` | `Signup_Field_Select` | **NO** — dropdown |
| `hidden` | `Signup_Field_Hidden` | **NO** — hidden field |
| `shortcode` | `Signup_Field_Shortcode` | **NO** — arbitrary WP shortcode |
| `terms_of_use` | `Signup_Field_Terms_Of_Use` | **NO** — TOS acceptance |
| `submit_button` | `Signup_Field_Submit_Button` | **MAYBE** — form submission |

Per-field editor controls (lines 366-591 in admin page):
- Field type selection (icon grid)
- Name/Label, ID/Slug, required toggle
- Visibility (always/guests_only/logged_only)
- Pre-fill from request toggle
- Wrapper width (0-100%)
- Wrapper CSS classes
- Element CSS classes
- Save as: `customer_meta` / `user_meta` / etc.
- Each field type has additional type-specific fields (e.g., `pricing_table` has product selection, `template_selection` has template options)
- Drag-and-drop reorder within steps

#### C. Thank You Page Configuration (lines 1431-1428)
- Toggle: enable/disable thank you page
- Select: which WordPress page to use
- **Conversion snippets**: HTML/JS code editor (`code-editor` type) for tracking pixels
- Placeholder tokens: `%%CUSTOMER_ID%%`, `%%ORDER_ID%%`, etc.

#### D. Scripts / Custom CSS (lines 1459-1475)
- **Custom CSS** code editor (SCSS supported)

#### E. Restrictions (lines 1477-1511)
- **Country lock**: restrict checkout form to specific countries
- Multi-select country list with search

#### F. Form Metadata (lines 1525-1563)
- **Slug**: editable, with warning on change
- **Active/Inactive** toggle (line 1565-1578)

#### G. Preview (steps.php lines 258-304)
- Toggle between editor and preview mode
- Preview as "existing user" or "visitor"
- Renders in iframe

#### H. Shortcode Generator (line 122-137)
- One-click copy shortcode to clipboard

---

## 4. DEPENDENCIES

### Direct Stripe/Payment Gateway Dependencies

The **form editor itself** does NOT directly depend on Stripe. The editor is a configuration tool that stores field definitions as JSON in the `settings` column of `Checkout_Form` model.

However, the **rendering** of the checkout form on the frontend depends on:

1. **Payment field** (`Signup_Field_Payment`, class in `inc/checkout/signup-fields/class-signup-field-payment.php`): Renders payment method selection (Stripe, PayPal, manual). This is a field TYPE that the editor can add, but the editor itself doesn't need it.

2. **Stripe gateway** (`inc/gateways/class-stripe-checkout-gateway.php`): Loaded on frontend checkout only. Enqueues `assets/js/gateways/stripe-checkout.js`. Not loaded in the admin editor.

3. **Checkout class** (`inc/checkout/class-checkout.php`, 3841 lines): The main frontend checkout handler. Processes payments, creates memberships, handles the full signup flow including payment confirmation.

4. **Order/Billing fields**: `order_summary`, `order_bump`, `billing_address`, `discount_code` — all assume a commercial transaction flow.

### Would the editor BREAK if payment gateways are removed?

- **The editor itself would NOT break** — it stores field definitions, not runtime dependencies
- **BUT** several field types would become meaningless: `pricing_table`, `period_selection`, `products`, `payment`, `order_summary`, `order_bump`, `billing_address`, `discount_code`
- Removing the field classes from `Signup_Fields_Manager::get_field_types()` (lines 50-73) would hide them from the "Add Field" modal, but **fields already configured in existing checkout forms would retain their data**
- The `field_types()` method in the admin page (line 334-357) filters out hidden fields via `is_hidden()` — this is the cleanest removal point

### Trial/Pricing dependencies:
- The editor has **no built-in trial configuration**. Trials are configured on Products, not on checkout forms.
- The `period_selection` field references product price variations (which may include trial lengths), but the editor doesn't configure trials itself.

---

## 5. ALIGNMENT WITH NEW SCOPE

### Architecture: Studio handles checkout/payment, UM only handles provisioning

### What SHOULD STAY (provisioning-relevant):

| Component | Reason |
|-----------|--------|
| `template_selection` field | Site template picker — core to provisioning |
| `site_title` field | Required for site creation |
| `site_url` field | Required for subsite domain/subdirectory |
| `username` field | User account creation |
| `email` field | User account creation |
| `password` field | User account creation |
| `text` field | Custom data capture for provisioning |
| `select` field | Custom data capture |
| `checkbox` field | Custom data capture |
| `hidden` field | System fields |
| `steps` component | Step navigation for multi-page provisioning |
| `terms_of_use` | Legal compliance |
| `shortcode` field | Embed arbitrary WP content |
| `submit_button` | Form submission |
| Step management (add/edit/reorder) | Organizes provisioning into logical steps |
| Drag-and-drop field reordering | UX for form layout |
| Visibility rules (guests/logged-in) | Conditional field display |
| Custom CSS | Styling |
| Active/Inactive toggle | Form management |

### What SHOULD GO (payment/checkout-specific):

| Component | Reason |
|-----------|--------|
| `pricing_table` field | Product pricing display — Studio's concern |
| `period_selection` field | Billing period picker — Studio's concern |
| `products` field | Product selector — Studio's concern |
| `payment` field | Payment method selection (Stripe/PayPal) — Studio's concern |
| `order_summary` field | Cart/order total display — Studio's concern |
| `order_bump` field | Upsells — Studio's concern |
| `billing_address` field | Billing info — Studio's concern |
| `discount_code` field | Coupon codes — Studio's concern |
| Thank You page config | Post-purchase page — Studio's concern |
| Conversion snippets | E-commerce tracking — Studio's concern |
| Country restrictions | Payment geo-restrictions — Studio's concern |
| Shortcode generator (`[wu_checkout]`) | References the full checkout — irrelevant |
| Events list table widget | Checkout form event tracking |
| Templates: `blank`, `single-step`, `multi-step`, `simple` | Checkout form templates |

### Files that would need modification:

**Remove from `Signup_Fields_Manager::get_field_types()`** (lines 50-73):
```
'pricing_table', 'period_selection', 'products', 'payment',
'order_summary', 'order_bump', 'billing_address', 'discount_code'
```

**Remove from admin page** (`class-checkout-form-edit-admin-page.php`):
- Thank You Page section (lines 1449-1458)
- Conversion Snippets (lines 1403-1410)
- Country Restrictions (lines 1477-1511)
- Events widget (lines 1515-1523)
- Template selection validation (lines 110)
- `get_thank_you_page_fields()` (lines 1369-1413)
- `get_thank_you_settings()` (lines 1421-1428)
- `get_template_selection_notice_products()` (lines 1151-1223) — references pricing/products
- `get_price_variation_notice_products()` (lines 1235-1300) — references pricing/products

**Remove from `Checkout_Form` model** (`inc/models/class-checkout-form.php`):
- `allowed_countries` property (line 86)
- `thank_you_page_id` property (line 94)
- `conversion_snippets` property (line 102)
- `template` property (line 110) — unless repurposed
- Related getters/setters

**Preview fix** (addressing the LOADING PREVIEW hang):
- The preview loads on the registration page URL. If the registration page is removed (since checkout moves to Studio), the preview will definitely break.
- Options: (a) Move preview to a dedicated admin-ajax endpoint, (b) Use a standalone preview page within the admin, (c) Remove preview entirely if the form is simple enough.

---

## SUMMARY TABLE

| Aspect | Current State | Post-Scope Reduction |
|--------|--------------|---------------------|
| Framework | Vue.js 2.x + jQuery + vuedraggable | Same (Vue 2 is embedded; migrating would be a larger project) |
| Field types | 24 | ~16 (remove 8 payment fields) |
| Preview mechanism | iframe → registration page | Needs rewrite to not depend on registration page |
| Payment coupling | Field types only — editor is agnostic | Remove payment field types from manager |
| Thank You page | Full config (page, snippets) | Remove entirely |
| Custom CSS | Supported | Keep — useful for provisioning form styling |
| Restrictions | Country lock | Remove — Studio's concern |
| Events widget | Checkout form event tracking | Remove |
| Editor size | ~1760 lines PHP + 289 lines JS | Reduce by ~40% |