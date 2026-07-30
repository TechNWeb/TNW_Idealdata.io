# TNW_Idealdata.io
Magento API module, extends native Adobecommerce(Magento) API and provides additional information for the Idealdata.io system.

To install/upgrade this module run the following commands in your Adobecommerce folder:
```
composer require tnw/module-idealdata=1.11 --no-update
composer upgrade tnw/module-idealdata
./bin/magento setup:upgrade; ./bin/magento setup:di:compile
```

## Storefront Pixel (customer presence)

Since 1.5 the module can inject the **IdealData storefront pixel**, which reports
logged-in-customer presence ("online now") to IdealData. It does two things:

1. **Exposes the logged-in customer id to storefront JavaScript** via a dedicated
   customer-data section (`tnw-idealdata-identity`). Because this loads through
   Magento's `/customer/section/load` AJAX endpoint **after Full Page Cache**, it
   is depersonalization-safe — unlike inline PHP, which FPC strips on cacheable
   pages.
2. **Injects the async pixel loader** on every storefront page (gated by config).

### Enabling (app-provisioned — since 1.6)

**You configure nothing in Magento by hand.** Enable the pixel on the store's
**Adobe Commerce connection** in the IdealData app; the app then **pushes** the
token + ingest/loader URLs into this store automatically over an authenticated
REST call. The admin fields under **Stores → Configuration → IDEALDATA.IO →
Storefront Pixel** show the delivered values but are **read-only** (managed by the
IdealData app):

| Field | Managed value |
|-------|---------------|
| **Enable Pixel** | Whether the app has enabled the pixel for this store. |
| **Ingest Base URL** | Public ingest base URL incl. the `/pixel-ingest` prefix. |
| **Loader URL** | Full URL to the static pixel loader script. |
| **Pixel Token** | The `idpx_…` token for this store's connection. Exposed to storefront JS by design — not a secret. |

Nothing is injected while **Enable Pixel** is `No`, or while the Token / Loader
URL are empty. The snippet is self-contained and fails silently: it only sets a
global and appends an async `<script>` — it can never block or break storefront
JavaScript.

Rotating the token in the IdealData app re-pushes the new value here; the old
token keeps working during a short grace window, so storefront presence is never
interrupted by a re-push.

#### Config-provisioning REST endpoint

The app delivers the config to:

```
PUT /rest/V1/tnw-idealdata/pixel-config
{ "enabled": true, "ingestBase": "https://my.idealdata.io/pixel-ingest",
  "loaderUrl": "https://pixel.idealdata.io/loader.js", "token": "idpx_…" }
```

It writes `tnw_idealdata_pixel/general/{enabled,ingest_base_url,loader_url,token}`
and flushes the config + full-page caches so the next storefront request reflects
the change. The endpoint is **ACL-protected** by the core
`Magento_Catalog::products` resource — the IdealData integration already holds it
(product sync + Signal 30 product writes both require it), so provisioning needs
**no new grant and no integration reauthorization**. There is no
public/self-service provisioning path. (This mirrors the module's `/V1/order/status`
route, which likewise reuses a core resource, `Magento_Sales::sales`.)

**Token preserve (drift-heal path, since 1.7).** Calling `save` with `enabled:true`
and an **empty** `token` PRESERVES the currently-stored token (it is not
overwritten) — only `enabled` + the URLs are re-written. This lets the app re-push
corrected config to heal drift **without minting or handling a raw token** (the app
stores only a SHA-256 of it). Enabling with an empty token when none is stored yet
is still rejected.

#### Config read-back REST endpoint (since 1.7)

Adobe Commerce — not IdealData — is the source of truth for what is actually
running on the storefront. The app reads the live config back to reconcile its
stored mirror and heal drift:

```
GET /rest/V1/tnw-idealdata/pixel-config
→ { "enabled": true,
    "ingest_base": "https://my.idealdata.io/pixel-ingest",
    "loader_url": "https://pixel.idealdata.io/loader.js",
    "token_present": true,
    "token_sha256": "<sha-256 hex of the stored token>" }
```

It reads the same `tnw_idealdata_pixel/general/*` config paths at the default
scope. **The raw token is never returned** — only whether one is stored
(`token_present`) and its SHA-256 fingerprint (`token_sha256`), so the app can
hash-compare against the SHA-256 it holds for the connection's active token (the
only way to detect token drift without exposing the raw value). Same ACL as the
write (`Magento_Catalog::products` — already held, so no new grant / no reauth).

### Manual fallback (stores not using app provisioning)

The admin fields are read-only, so a store not using app provisioning can set the
same config values out-of-band with the Magento CLI:

```bash
bin/magento config:set tnw_idealdata_pixel/general/enabled 1
bin/magento config:set tnw_idealdata_pixel/general/ingest_base_url https://my.idealdata.io/pixel-ingest
bin/magento config:set tnw_idealdata_pixel/general/loader_url https://pixel.idealdata.io/loader.js
bin/magento config:set tnw_idealdata_pixel/general/token idpx_YOUR_TOKEN
bin/magento cache:flush config full_page
```

Alternatively, if you prefer not to let the module inject the loader at all (or
you are on a theme / setup where the layout injection is not desired), paste this
snippet into the storefront `<head>` (e.g. **Content → Design → Configuration →
HTML Head → Scripts and Style Sheets**, or a "Miscellaneous HTML" block).
Substitute your own token / ingest base / loader URL:

```html
<script>
  window.idealdataSettings = {
    token: 'idpx_YOUR_TOKEN',
    platform: 'adobecommerce',
    ingestBase: 'https://my.idealdata.io/pixel-ingest'
  };
</script>
<script async src="https://pixel.idealdata.io/loader.js"></script>
```

> Do **not** add an inline-PHP `setCustomerId` line — it works only with Full
> Page Cache disabled. The pixel reads the customer id client-side from the
> `tnw-idealdata-identity` customer-data section instead (installed by this
> module).

### Content Security Policy (since 1.11)

Stores that run Magento's **Content Security Policy** module in *restrict* mode
block any script or network call to an origin that is not whitelisted. Without
the origins below, the browser refuses to load the loader script and the pixel
reports nothing — the store console shows
`Loading the script 'https://…/loader.js' violates the following Content Security
Policy directive: "script-src …"`.

**You do not need to configure anything** — this module ships the whitelist:

| Directive | Origin | Why |
|-----------|--------|-----|
| `script-src` | `https://pixel.idealdata.io` | the async pixel loader script |
| `connect-src` | `https://my.idealdata.io` | the SDK's ingest calls (`/pixel-ingest/config`, `/pixel-ingest/collect`) |
| `img-src` | `https://my.idealdata.io` | only used if the SDK falls back to an image beacon |

Two layers provide this, so both a canonical and a custom deployment work:

1. **`etc/csp_whitelist.xml`** — the canonical IdealData origins above, merged into
   the store policy declaratively. Applies even on stores that use the manual
   snippet instead of this module's layout injection.
2. **A CSP policy collector** (`TNW\Idealdata\Model\Csp\PixelPolicyCollector`) —
   derives the origins from the **configured** `loader_url` / `ingest_base_url` at
   request time and adds them to the storefront policy. A store the IdealData app
   provisioned with a non-canonical URL (staging, a per-tenant CDN hostname) is
   therefore whitelisted automatically, with no module release and no merchant
   action. It adds nothing at all while the pixel is disabled, and ignores a
   malformed URL rather than widening the policy.

Both layers only ever **add** sources; neither can narrow a policy the merchant
already has. The storefront policy is affected — the admin policy is untouched.

The inline `window.idealdataSettings` snippet and the loader `<script>` tag are
rendered through Magento's `SecureHtmlRenderer`, so both carry the request's CSP
nonce on stores using nonce-based CSP. (Under CSP, a nonce in `script-src` makes
browsers ignore `'unsafe-inline'` — a hand-written inline tag would stop executing
there, silently leaving the loader with no configuration.)

If a store has its own hardened policy that this does not reach, add the origins
in any module's `etc/csp_whitelist.xml`:

```xml
<policy id="script-src">
    <values><value id="idealdata_pixel_loader" type="host">https://pixel.idealdata.io</value></values>
</policy>
<policy id="connect-src">
    <values><value id="idealdata_pixel_ingest" type="host">https://my.idealdata.io</value></values>
</policy>
```

### Debug logging (local troubleshooting — since 1.8)

**Stores &rarr; Configuration &rarr; IDEALDATA.IO &rarr; Storefront Pixel** has a
**Debug Logging** field (Yes/No, default **No**). Unlike the token / URL fields,
it is **local and operator-editable** — it is **not** managed or pushed by the
IdealData app. When set to **Yes**, the loader emits
`window.idealdataSettings.debug = true` and the pixel SDK logs verbose diagnostics
to the **browser console** (prefixed `[idealdata-pixel]`):

- which cart capture layer produced each `cart.add` / `cart.remove` —
  `native-event` (with the raw Magento event, e.g. `ajax:addToCart`),
  `section-diff` (with the `summary_count` before&rarr;after), or `public-api`;
- every de-dup suppression (which key collapsed against which prior layer);
- page-view and presence transitions, and a per-event send/drop gate trace.

Use it to confirm, on a live theme, **which trigger actually fires** (e.g.
whether native Magento events fire on Luma, or the section-diff safety net carries
removes/qty-changes; on Hyvä, that `private-content-loaded` drives it). The logs
are **local console only — nothing leaves the browser**. Leave it **No** in
production. Manual fallback (stores not using the admin toggle):

```bash
bin/magento config:set tnw_idealdata_pixel/general/debug 1
bin/magento cache:flush config full_page
```

### Verify cart tracking on your theme (since 1.10)

Cart capture works automatically for standard flows, but **which** controls fire
depends on your theme's runtime behaviour — something that **can only be verified
empirically**, on a live page in the browser, so there is deliberately no "coverage
OK" indicator. Verify coverage with **Debug Logging** (above). The same procedure is
shown in the admin panel below:

**Prerequisite:** the pixel must be enabled first — **Enable Pixel** must be **Yes**
(turned on for you from the IdealData app when you enable the pixel on your Adobe
Commerce connection). While it is off, no pixel code is injected on your storefront
pages at all, so there is nothing to verify and the console stays silent.

1. Set **Debug Logging = Yes** and save.
2. Open the storefront with the browser **console open**. Debug Logging only **adds
   verbose lines to the browser console** — those log lines stay in the browser; it
   does not change what the pixel sends. Turn Debug back to **No** in production to
   keep the console clean.
3. **Exercise EVERY place a shopper can add to or change the cart — not just the
   product page.** Different surfaces route through different capture layers, so a
   control tracked on one page may be untracked on another. Walk through all that
   your store has:
   - product detail page **"Add to Cart"**;
   - category / listing **"Add to Cart"** (where present);
   - **mini-cart / cart drawer** — qty change, remove;
   - **cart page** (`/checkout/cart`) — qty update, remove;
   - **widgets / blocks**: related products, up-sells, cross-sells, "you may also
     like", promo blocks on the homepage or CMS pages, quick-view / quick-buy modals;
   - any **theme-specific or third-party add-to-cart**: bundles, configurable
     quick-add, one-click buy.
4. For each action, watch the console: a line showing `cart.add` / `cart.remove`
   captured via `native-event` or `section-diff` means auto-capture works there.
   **No line for an action means that surface is NOT auto-tracked** and needs a
   manual binding.
5. For any surface that didn't fire, add the matching snippet (below) to that theme
   control's handler, and re-test with debug on until every cart surface logs an event.

Auto-capture covers most standard flows out of the box; manual binding is only for
the gaps this procedure surfaces (custom themes, custom widgets, non-standard
add-to-cart). Adding a binding defensively where auto-capture already works is safe —
explicit calls and auto-capture **de-dupe against each other** within a short window
(explicit wins), so you won't double-count. (Concretely: a PDP/listing add usually
fires the native Magento event, a mini-cart qty change is often caught only by the
section-diff safety net, and a custom widget might fire neither — hence exercising
**all** surfaces, not just the catalog.)

### Developer: manual cart-event binding (informational — since 1.9)

**Stores &rarr; Configuration &rarr; IDEALDATA.IO &rarr; Storefront Pixel** has a
collapsible **"Developer: verify cart tracking &amp; manual binding (optional)"** panel
(which leads with the verification procedure above) with
copy-paste JavaScript snippets for a merchant's developer who wants to bind cart
events to the pixel explicitly. It is **purely informational** — it displays code
to copy and runs nothing; it is always visible in the pixel config area and gated
behind nothing.

Cart events are captured **automatically** on Adobe Commerce (native Magento
events + a customer-data section diff), so no code is required for the common case.
Manual binding via the public `idealdataPixel('track', 'cart.add' | 'cart.remove',
{...})` API is for precise, theme-known context or coverage the auto-capture does
not reach; explicit calls de-dupe against auto-capture (explicit wins). The panel
also shows the manual-identity snippet
(`idealdataPixel('setCustomerId', '<numericId>')`) for custom themes. Full API
reference: the `idealdata3-pixel` repo README ("Public API").

### Identity — storage key (for pixel SDK maintainers)

The `tnw-idealdata-identity` section is persisted by Magento's customer-data
mechanism to `localStorage['mage-cache-storage']` under the **section name**,
i.e. the key **`tnw-idealdata-identity`**, e.g.:

```json
{
  "tnw-idealdata-identity": { "customer_id": 42, "data_id": 1752624000 }
}
```

- `customer_id` — the numeric customer entity id (present only for a logged-in
  customer; **absent** for guests — never a `0` sentinel). `data_id` is added by
  Magento as a cache-version marker (ignore it).
- The storefront pixel SDK reads
  `mage-cache-storage['tnw-idealdata-identity'].customer_id`. This key is a
  cross-repo contract — renaming the section (in `etc/frontend/sections.xml` and
  `etc/frontend/di.xml`) requires updating the SDK adapter in lockstep.
