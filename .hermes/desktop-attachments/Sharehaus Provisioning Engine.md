#   Final Spec

## Architectural boundary

> _"Studio owns entitlement. WordPress owns enforcement."_

|Studio owns (commercial)|WordPress owns (operational)|
|---|---|
|Plans, pricing, billing, Stripe|Site creation, configuration|
|Payment state|Template application|
|Progressive Trial lifecycle|Plugin activation/deactivation|
|AI credit entitlement|Feature flags, capabilities, limits|
|Customer-facing onboarding|WordPress users/roles|
|Brand configuration|Brand application to Divi|
|Commercial entitlement decisions|Domain mapping, Cloudflare DNS|
|"This customer gets Barista"|"Barista means Subscriptions active, Affiliate inactive"|

## Plugin activation model

|Class|Scope|Controlled by|
|---|---|---|
|Network-activated (Sharehaus core, WooCommerce, platform infrastructure)|Entire multisite|Platform admin — never toggled by tenant profiles|
|Site-activated (Subscriptions, Affiliate marketing, plan-specific plugins)|Individual tenant site|Operational profile reconciliation|

## Operational profiles (versioned, immutable)

Code· json

```
{
  "barista": {
    "version": 3,
    "network_dependencies": [
      "woocommerce/woocommerce.php",
      "sharehaus-core/sharehaus-core.php"
    ],
    "site_plugins": {
      "woocommerce-subscriptions/woocommerce-subscriptions.php": { "enabled": true },
      "affiliate-module/affiliate-module.php": { "enabled": false }
    },
    "capabilities": {
      "subscriptions": true,
      "affiliate_marketing": false
    },
    "limits": {
      "products": 25,
      "users": 3
    }
  },
  "master-roaster": {
    "version": 2,
    "network_dependencies": [
      "woocommerce/woocommerce.php",
      "sharehaus-core/sharehaus-core.php"
    ],
    "site_plugins": {
      "woocommerce-subscriptions/woocommerce-subscriptions.php": { "enabled": true },
      "affiliate-module/affiliate-module.php": { "enabled": true }
    },
    "capabilities": {
      "subscriptions": true,
      "affiliate_marketing": true
    },
    "limits": {
      "products": "unlimited",
      "users": 10
    }
  }
}
```

Rules:

- Once published, a profile version never changes. `barista-v3` always means the same configuration.
- Changes create a new version (`barista-v4`).
- Studio explicitly instructs migrations: `barista-v3 → barista-v4`.

## API endpoints

|Method|Path|Role|
|---|---|---|
|`POST`|`/api/v1/provision`|Begin provisioning (returns immediately)|
|`GET`|`/api/v1/provision/:id`|Provisioning status with step-level detail|
|`PUT`|`/api/v1/provision/:id/profile`|Primary: change plan (upgrade/downgrade)|
|`PUT`|`/api/v1/provision/:id/capabilities`|Support/migration: override specific capability|
|`PUT`|`/api/v1/provision/:id/plugins`|Support/repair: direct plugin reconciliation|
|`PUT`|`/api/v1/provision/:id/state`|Set operational state (suspend/restore)|
|`PUT`|`/api/v1/provision/:id/domain`|Assign/change domain|
|`POST`|`/api/v1/provision/:id/archive`|Archive site (recoverable)|
|`POST`|`/api/v1/provision/:id/restore`|Restore from archive|
|`POST`|`/api/v1/provision/:id/purge`|Permanent destruction (terminal, audited)|
|`GET`|`/api/v1/operational-profiles`|List available profiles with versions|
|`GET`|`/api/v1/templates`|List available site templates|

Hierarchy: `/profile` is the normal production interface. `/capabilities` is for exceptional overrides. `/plugins` is for support/migration/repair. Studio normally sends commercial concepts (Barista, subscriptions); WordPress knows which plugins implement them.

### POST /api/v1/provision

Code· json

```
{
  "operational_profile": "barista",
  "profile_version": 3,
  "template": "coffee-dropshipper",
  "site_title": "My Coffee Brand",
  "subdomain": "mybrand",
  "customer": {
    "email": "owner@mybrand.com",
    "display_name": "Jane Roaster"
  },
  "brand": {
    "primary_color": "#c4753b",
    "secondary_color": "#2a221a",
    "accent_color": "#f5f0eb",
    "heading_font": "Playfair Display",
    "body_font": "Open Sans",
    "logo_url": "https://studio.sharehaus.coffee/assets/logo.png"
  },
  "command_id": "uuid-v4",
  "idempotency_key": "uuid-v4"
}
```

### PUT /api/v1/provision/:id/profile — response

Code· json

```
{
  "status": "active",
  "profile": "master-roaster",
  "profile_version": 2,
  "changes": {
    "site_plugins_activated": ["affiliate-module/affiliate-module.php"],
    "site_plugins_deactivated": [],
    "network_dependencies_verified": ["sharehaus-core/sharehaus-core.php"],
    "capabilities_enabled": ["affiliate_marketing"],
    "capabilities_disabled": [],
    "limits_changed": []
  }
}
```

## Provisioning pipeline (async, resumable)

|Step|Idempotent|On failure|
|---|---|---|
|1. Validate + claim idempotency key|✅|Reject duplicate|
|2. Create operation record|✅|—|
|3. Create/find WP user|✅|Skip if exists|
|4. Create customer/membership projection|✅|—|
|5. Create WP subsite|✅|—|
|6. Apply template|✅|Retry from this step|
|7. Reconcile plugins + capabilities from profile|✅|Retry, never deactivate network plugins|
|8. Apply brand (Divi presets, fonts, logo)|✅|Retry|
|9. Configure domain/DNS|✅|Retry|
|10. Health check|✅|Retry|
|11. Mark active|—|—|

## State machine

accepted → provisioning → active ↘ failed (retryable — resume from failed step) → cleanup_required

active → suspended → archived → purged (terminal) → active (restore) archived → active (restore)

Archive: disabled, retained, recoverable. Purge: permanent destruction. Explicit command, strongly authenticated, audit logged, requires Studio approval.

## Reconciliation rules

Enable (plugin not yet active, profile says it should be):

1. Verify plugin exists on network
2. Verify dependencies satisfied
3. Activate on tenant site
4. Apply required configuration
5. Enable capability/access

Disable (plugin active, profile says it shouldn't be):

1. Disable capability/access
2. Deactivate site plugin if safe
3. Preserve configuration and business data
4. Never uninstall automatically
5. Never delete plugin tables or data
6. Never touch network-activated plugins

## Brand push

`Brand_Pusher → Divi_Brand_Adapter`:

1. `switch_to_blog(site_id)`
2. Sideload logo from whitelisted host (`studio.sharehaus.coffee` only) → create attachment → get ID
3. `set_theme_mod('custom_logo', attachment_id)`
4. Update `et_divi` global color presets
5. Set `header_font`, `body_font` theme mods
6. Update `et_global_colors_info`
7. `restore_current_blog()` (enforced in `finally` block)

## Auth (v1)

- HTTPS + `X-API-Key` header
- Separate staging/production keys
- Rotation supported
- Secrets stored outside repository
- Audit logged
- HMAC signing deferred to v2

## Idempotency table

Code· sql

```
provision_id        UUID PRIMARY KEY
idempotency_key     VARCHAR UNIQUE NOT NULL (DB constraint)
command_id          UUID
requested_at        TIMESTAMP
status              ENUM
current_step        VARCHAR
site_id             INT
customer_id         VARCHAR
operational_profile VARCHAR
profile_version     INT
request_payload     TEXT
last_error          TEXT
completed_at        TIMESTAMP
```

## Development sequence

1. Characterization tests around existing UM provisioning
2. Map UM component dependencies (membership → product → installer → checkout coupling)
3. Build the 12 provisioning API endpoints
4. Add operational profile registry
5. Verify: site creation, plugin gating, limits, template application
6. Incrementally remove Stripe paths
7. Incrementally remove trial logic
8. Remove payment tables only after no retained code depends on them
9. Continue pruning in small tested steps

## Fork: Keep

- Site provisioning pipeline (`inc/installers/`, `inc/duplication/`)
- Customer/site membership model (stripped to operational projection — no renewal, trial, or payment)
- Domain management + Cloudflare execution
- Site template selection UI
- Network admin pages (site overview, customer panel)

## Fork: Cut (incremental)

- Stripe gateways (Elements, Checkout, Base)
- Checkout flow (cart, order, payment creation)
- Trial lifecycle (cron, Trial_Limits)
- Payment model + tables
- Webhook handlers
- Legacy Product/Plan definitions (replaced by operational profiles)
  
  
