# TNW_Idealdata.io
Magento API module, extends native Adobecommerce(Magento) API and provides additional information for the Idealdata.io system.

To install/upgrade this module run the following commands in your Adobecommerce folder:
```
composer require tnw/module-idealdata=1.16 --no-update
composer upgrade tnw/module-idealdata
./bin/magento setup:upgrade; ./bin/magento setup:di:compile
```

## Storefront Pixel (customer presence + cart activity)

Since 1.5 the module can inject the **IdealData storefront pixel**, which reports
logged-in-customer presence ("online now") and cart activity to IdealData. It does
three things:

1. **Exposes the logged-in customer id to storefront JavaScript** via a dedicated
   customer-data section (`tnw-idealdata-identity`). Because this loads through
   Magento's `/customer/section/load` AJAX endpoint **after Full Page Cache**, it
   is depersonalization-safe — unlike inline PHP, which FPC strips on cacheable
   pages.
2. **Injects the async pixel loader** on every storefront page (gated by config).
3. **Reports `cart.add` / `cart.remove`** from a theme-agnostic cart-tracking bridge
   (since 1.15), which is what makes cart capture work on **Hyvä** as well as Luma —
   see "Cart tracking on any theme" below.

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
| `connect-src` | `https://pixel.idealdata.io` | the loader's source map (`loader.js.map`) — see below (since 1.14) |
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
   action. It adds nothing at all while the pixel is disabled, outside the
   storefront area, or for a malformed URL.

Both layers only ever **add** sources; neither can narrow a policy the merchant
already has. The storefront policy is affected — the admin policy is untouched.

**Why the loader origin is on `connect-src` too (since 1.14).** The loader ships a
`//# sourceMappingURL` comment, and browsers fetch `loader.js.map` whenever DevTools
is open. That fetch is checked against **`connect-src`**, not `script-src`, so a
`script-src`-only entry left every DevTools session logging
`Connecting to 'https://pixel.idealdata.io/loader.js.map' violates … "connect-src …"`
on each page view. The origin already serves the loader script, so allowing it to be
fetched adds no new trust. Nothing about the pixel's data collection changes — the
violation was console noise only.

> **Fixed in 1.13 — upgrade from 1.11 / 1.12.** In 1.11 and 1.12 the collector was
> registered into `Magento\Csp\Model\CompositePolicyCollector` from
> `etc/frontend/di.xml`. Magento merges object-manager arguments across scopes by
> argument **name**, not by array item, so that area-scoped declaration *replaced*
> core's whole `collectors` array rather than adding to it. On a store running CSP
> in restrict mode the storefront policy collapsed to only the three IdealData
> origins — no `'self'`, no `'unsafe-inline'`, no `data:`, no `csp_whitelist.xml`
> hosts and no per-request nonce — so the browser blocked essentially every store
> script, image and XHR. 1.13 moves the registration to the module's global
> `etc/di.xml` (where item names merge correctly) and gates the collector to the
> storefront area in PHP.
>
> On 1.11/1.12 the immediate workaround, without a deploy, is
> `bin/magento config:set tnw_idealdata_pixel/general/enabled 0 && bin/magento cache:flush`
> — that empties the collapsed policy so no header is emitted, at the cost of the
> pixel and of the store's CSP.

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
    <values>
        <value id="idealdata_pixel_ingest" type="host">https://my.idealdata.io</value>
        <value id="idealdata_pixel_loader_map" type="host">https://pixel.idealdata.io</value>
    </values>
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
- since 1.15, the module's own cart-tracking bridge logs under the
  `[idealdata] cart bridge:` prefix — the runtime it detected, each reported event
  and its payload, and every suppression (baseline reset, stale-baseline removal);
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

### Cart tracking on any theme — Hyvä included (since 1.15)

Since 1.15 the module ships its own **cart-tracking bridge**
(`view/frontend/web/js/cart-tracking.js`), injected by the same
`pixel/loader.phtml` and behind the same `isPixelEnabled()` gate as the loader.
It reports `cart.add` / `cart.remove` by **diffing Magento's `cart` customer-data
section**, and pins the customer id via `setCustomerId`.

**Why it was needed.** The SDK's automatic capture has two layers: native Magento
JS events and a customer-data section diff — both written against Luma, with the
event layer built on jQuery. Hyvä ships **no jQuery, no RequireJS**, and
dispatches **no add-to-cart event at all** (its cart-related events are
`toggle-cart` and `reload-customer-section-data`; there is nothing for "an item
was added"). On a Hyvä store the event layer therefore has nothing to hook, and
cart activity never reaches the platform. What Hyvä *does* share with Luma is the
private-content pipeline: the same `/customer/section/load` endpoint, the same
`localStorage['mage-cache-storage']` key and shape, plus a
`private-content-loaded` CustomEvent on `window` carrying the whole section map.
Every cart mutation is a POST, which rotates the `private_content_version` cookie
and makes Hyvä refetch sections.

**Why a section diff instead of binding theme controls.** The cart section is the
one thing every add-to-cart surface necessarily changes. Instrumenting controls
means chasing PDP forms, listing buttons, drawer qty, cart page, product sliders,
quick-add, Algolia results and PageBuilder widgets — per theme and per extension,
forever. Diffing the section covers all of them by construction, including plain
non-AJAX form posts that navigate away: the baseline is persisted, so the diff is
computed on the next page load.

It hooks all of these, so no single one is load-bearing:

| Hook | Covers |
|------|--------|
| `private-content-loaded` on `window` | Hyvä (and anything following its pattern) — earliest signal, no storage needed |
| `mage-cache-storage` watch (1s, paused while the tab is hidden) | Luma, custom/headless themes, any control that reloads sections without an event |
| Read on script start / `pageshow` | non-AJAX add-to-cart that navigated to a new page |
| `storage` event | a cart changed in another tab |

Each event carries `productId` (numeric, as a string), `variantId` (the line's
SKU), `title`, the `quantity` **delta** for that action, and `cartTotalQuantity`
summed from the cart lines.

It needs **no CSP entry** — the file is served from the store's own static base
URL, so any policy that already allows the theme's JavaScript allows this.

**Who owns an event (why this cannot double-count).** The SDK's cross-layer
de-duplication is **one-directional**: an explicit `track()` call suppresses the
auto-capture diff that *follows* it, but an explicit call arriving *after* an
auto-capture emit is not suppressed — and `cartAutoCapture` is delivered per store
from ingest, so the module cannot switch the SDK's capture off. Ownership is
therefore split on **two** conditions, both of which must hold before the bridge
stays silent:

1. `window.idealdataPixelCore` — the SDK core bundle's global, which exists only
   once its collectors are running; **and**
2. the runtime is one those collectors can actually observe, i.e. **not Hyvä**.

So the rule is: **on Hyvä the bridge always owns cart events; everywhere else it
reports only while the core global is absent.** Luma is unaffected and behaves
exactly as it did before.

> **Corrected in 1.16.** 1.15 deferred on condition 1 alone, on the reasoning that
> Hyvä's lost add would normally be observed by the bridge *before* the SDK core
> came up. On a real store the race goes the other way every time: the core is
> already loaded when the post-navigation section refetch lands, so the bridge
> handed the change to a collector that cannot see it and **nobody reported it**.
> Measured on a live Hyvä store (AC 2.4.6-p15) with the pixel enabled and the core
> up: a PDP add-to-cart and a `/checkout/cart` quantity change each produced
> `page.viewed` + `presence.heartbeat` on the wire and **no cart event at all**.

There is nothing to double-count against on Hyvä, because the SDK reports no cart
events there: its event layer needs jQuery, which Hyvä does not ship, and its
section-diff layer seeds a per-page in-memory baseline that a navigating add has
already outrun. One reporter beats none.

> **Root cause, for the record.** The SDK's cart collector keeps its baseline
> **in memory for one page load** (`prevSummaryCount`, seeded at `start()`, with
> the first section reload suppressed so a page load cannot emit a phantom event).
> On Luma that is fine — adds are in-page AJAX, so they happen with the collector
> already bound. On Hyvä the PDP/listing add is a form POST that **navigates**, so
> the only trace is a section refetch right after the next page load — which the
> async SDK usually has not loaded in time to see, and which its first-snapshot
> guard would swallow anyway. Persisting that baseline across page loads in the
> SDK (`idealdata3-pixel`) would fix Hyvä for every Adobe Commerce store without a
> module release; this bridge is the module-side fix and is scoped so the two
> cannot collide.
>
> ⚠️ **Coupling to watch, from either side:**
> - The split assumes the SDK's auto-capture is **on** (ingest does not emit
>   `cartAutoCapture` today, so the `true` default applies). If IdealData ever sets
>   `cartAutoCapture:false` for an Adobe Commerce store, this bridge has to be told
>   too — otherwise live in-page cart changes would be reported by nobody. Note the
>   ownership rule keys on the core global, **not** on `cartAutoCapture`, so
>   switching it off alone does not hand reporting back to the bridge.
> - If `idealdata3-pixel` ever gains cart capture that works on Hyvä — most likely
>   by persisting that collector baseline — the Hyvä branch in the bridge starts
>   double-counting and **must be removed in the same release**. Ship the two
>   together, never one alone.

**It will not invent shopper activity.** Each of these is a false-event source
that is explicitly handled:

| Situation | Behaviour |
|-----------|-----------|
| Cart section absent (invalidated, not yet fetched, storage unavailable) | skipped — never read as "cart emptied" |
| Login / logout | baseline reset silently (quote merge, cart disappearing) |
| Order placed (`checkout/onepage/success`, multishipping) | baseline reset silently — the order emptied the cart, not the shopper |
| Baseline older than 30 min | additions still reported, **removals suppressed** (cart expiry, an admin editing the quote, or an order placed on another device all look like a removal) |
| Two tabs open | the baseline lives in `localStorage`, so the first tab to see a change advances it and the others stay quiet |
| `summary_count` | only ever a fallback: it is a line **count** or a total **qty** depending on `checkout/cart_link/use_qty`, so quantities come from the per-line `qty` |
| Item list empty while the cart is not | falls back to counting instead of reporting every line as removed |
| localStorage unavailable (private mode) / malformed cache | degrades to the event path; never throws into the theme |

**Identity repair.** The bridge also calls
`idealdataPixel('setCustomerId', '<numericId>')` from the
`tnw-idealdata-identity` section. If a shopper is logged in but that section is
missing or stale, it fetches **just that section** once per page view. Luma and
Hyvä both preload every section, so this repair normally never runs.

Behaviour is covered by a dependency-free test suite (Node's own `vm`, no build
chain added):

```bash
node Test/Js/cart-tracking.test.js
```

After upgrading, deploy static content so the new asset is published:

```bash
bin/magento setup:upgrade
bin/magento setup:static-content:deploy -f   # add your locales, e.g. en_US
bin/magento cache:flush
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
   Since 1.15 the module's own bridge logs the same events under the
   `[idealdata] cart bridge:` prefix (`… cart.add captured via section-diff`), which
   is what covers Hyvä. **No line from either for an action means that surface is
   NOT tracked** and needs a manual binding.
5. For any surface that didn't fire, add the matching snippet (below) to that theme
   control's handler, and re-test with debug on until every cart surface logs an event.

**If the console shows nothing at all — check the network before you conclude
anything.** The console is only a *view* of what happened, and plenty of production
storefronts make it a lying one: a DevTools log-level filter hides `console.log`
while still showing errors, and it is a common production practice to replace
`window.console` with a detached one, which silences every log while
`console.log.toString()` still reports `[native code]`. Neither has anything to do
with the pixel. The ground truth is the **Network** tab — tick **Preserve log**, filter
for `collect`, and exercise the surface. A POST to `…/pixel-ingest/collect` whose
payload contains `cart.add` / `cart.remove` means the event fired and was sent,
whatever the console does or does not show. Two quick corroborating reads, both of
which print via the console's *expression echo* rather than `console.log`:

```js
JSON.parse(localStorage['idealdata-pixel-cart-state']).ts   // advances when the bridge sees a cart change
!!window.idealdataPixelCore                                  // true = SDK core loaded
```

Seeing only `page.viewed` and `presence.heartbeat` on the wire, with no cart event
for any surface, is **not** a coverage gap and manual binding will not fix it — the
bridge sees the change and something is stopping it being reported. Report that
rather than instrumenting controls.

Auto-capture covers standard flows on Hyvä and Luma out of the box; manual binding is
only for the gaps this procedure surfaces (a custom theme that bypasses the cart
section, a checkout that changes the cart without a page or section update).
**Bind only the surfaces that logged nothing.** De-dup is **one-directional**: an
explicit call suppresses auto-capture that happens *after* it (same action + same
`productId`, within `cartDedupWindowMs` — default 1000ms), but it cannot suppress
capture that already fired, so a snippet on an already-tracked control is counted
twice. (Concretely: a Luma PDP/listing add fires the native Magento event at click
time, so instrumenting it by hand would double-count; a mini-cart qty change is often caught
only by the section diff; a Hyvä add-to-cart that reloads the page is carried by this
module's bridge — hence exercising **all** surfaces, not just the catalog.)

### Developer: manual cart-event binding (informational — since 1.9)

**Stores &rarr; Configuration &rarr; IDEALDATA.IO &rarr; Storefront Pixel** has a
collapsible **"Developer: verify cart tracking &amp; manual binding (optional)"** panel
(which leads with the verification procedure above) with
copy-paste JavaScript snippets for a merchant's developer who wants to bind cart
events to the pixel explicitly. It is **purely informational** — it displays code
to copy and runs nothing; it is always visible in the pixel config area and gated
behind nothing.

Cart events are captured **automatically** on Adobe Commerce — native Magento events
plus a customer-data section diff, with this module's bridge covering themes that
dispatch no add-to-cart event (Hyvä) — so no code is required for the common case.
Manual binding via the public `idealdataPixel('track', 'cart.add' | 'cart.remove',
{...})` API is for precise, theme-known context or coverage the auto-capture does
not reach. It must be called **at the moment of the cart change** and only on
surfaces that are not already tracked: an explicit call suppresses the auto-capture
that *follows* it, never capture that already fired (see the ownership section
above). The panel
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
