# CLAUDE.md — TNW_Idealdata (Magento 2 module)

Merchant-facing **Adobe Commerce / Magento 2** module for the IdealData platform
(`TechNWeb/tnw_idealdata.io`). Namespace `TNW\Idealdata\` (PSR-4), composer package
`tnw/module-idealdata`. It is the on-store companion to the `idealdata3-adobecommerce`
connector: it exposes authenticated REST endpoints the connector calls, and hosts the
**storefront pixel** (customer-data identity section + loader injection + config).

## Layout / conventions

- **REST endpoints:** `etc/webapi.xml` route → `Api/*Interface.php` (`@api`, typed
  params — WebAPI marshals the JSON body by matching top-level keys to parameter
  names) → `Model/*.php` implementation, wired by a `<preference>` in `etc/di.xml`.
  Return DTOs are an `Api/Data/*Interface` + `Model/Data/*` (extends `DataObject`) pair
  with a matching `di.xml` preference (see the FailedTransaction + PixelConfig pairs).
- **ACL:** module resources under `Magento_Backend::admin` in `etc/acl.xml`
  (`TNW_Idealdata::config`, `TNW_Idealdata::failed_transactions_read`). Each webapi
  route references a resource in `<resources>`; a route MAY reuse a **core** resource
  (e.g. `/V1/order/status` → `Magento_Sales::sales`, pixel-config → `Magento_Catalog::products`).
- **Admin config:** `etc/adminhtml/system.xml` (+ defaults in `etc/config.xml`), tab
  `idealdata_tab`. Config paths are `<section>/<group>/<field>`.
- **Tests:** plain PHPUnit `TestCase` under `Test/Unit/`, mocking Magento deps with
  `getMockBuilder(...)->disableOriginalConstructor()`. No module-local `phpunit.xml`
  (relies on the host Magento's config).
- **Versioning:** bump BOTH `etc/module.xml` `setup_version` and `composer.json`
  `version` together on a shippable change (and the README install line). Current: **1.15**.
- Validate edits locally with `php -l` (all PHP) + a `DOMDocument` load per XML file.
  Storefront JS: `node --check` + the module's own dependency-free suite,
  `node Test/Js/cart-tracking.test.js` (Node `vm`; do NOT add a JS build chain).
- `FEATURES.md` is a stale snapshot of v1.3 and documents none of the pixel work —
  the pixel's live documentation is `README.md`.

## Storefront pixel

Reports logged-in-customer presence + activity to IdealData. Two on-store pieces:

1. **Identity** — `CustomerData/Identity.php` (`SectionSourceInterface`) exposes the
   numeric `customer_id` via the `tnw-idealdata-identity` customer-data section
   (loads post-FPC via `/customer/section/load`, so it is depersonalization-safe —
   inline-PHP `setCustomerId` is rejected under Full Page Cache). Persisted at
   `localStorage['mage-cache-storage']['tnw-idealdata-identity'].customer_id` — the
   cross-repo key the `idealdata3-pixel` SDK adapter reads.
2. **Loader** — `Block/Pixel/Loader.php` + `view/frontend/templates/pixel/loader.phtml`
   inject the async loader (`before.body.end`), gated by config.
3. **Cart tracking** (since 1.15) — `view/frontend/web/js/cart-tracking.js`, injected by
   the same template/gate as a classic script configured via data-attributes. Reports
   `cart.add`/`cart.remove` by DIFFING the core `cart` customer-data section, because
   **Hyvä dispatches no add-to-cart JS event** (and ships no jQuery/RequireJS) for the
   SDK's native-event layer to hook — it hooks `private-content-loaded` plus a
   `mage-cache-storage` watch, with the baseline persisted in `localStorage` so
   non-AJAX form posts are caught on the next page load. Theme-agnostic on purpose:
   it runs on Luma too. **Ownership split (do not "simplify"):** the SDK's de-dup is
   ONE-DIRECTIONAL (an explicit `track()` suppresses only the auto-capture diff that
   FOLLOWS it) and `cartAutoCapture` comes per-store from ingest, so the bridge
   reports ONLY while `window.idealdataPixelCore` (the SDK core global) is absent —
   the SDK can only report changes it sees after its core seeds its baseline, so the
   two can never both report one action. The suppression rules (login/logout,
   order-success, stale baseline, absent section, multi-tab) are the other
   safety-critical part — see the file header and README.
   Root cause of the Hyvä gap lives in `idealdata3-pixel`: the collector's baseline
   is in-memory per page load, so an add that NAVIGATES is lost. Persisting it there
   would fix Hyvä store-wide without a module release — worth doing, and it must be
   coordinated with this bridge's ownership rule if it happens.

### Config is app-provisioned (do not ask the merchant to type it)

Config lives at `tnw_idealdata_pixel/general/{enabled,ingest_base_url,loader_url,token}`.
The IdealData app **pushes** these values in via `PUT /rest/V1/tnw-idealdata/pixel-config`
(`Api/PixelConfigManagementInterface` → `Model/PixelConfigManagement`: writes the paths
via `WriterInterface` + flushes config + full_page caches). The `system.xml` fields are
**read-only / IdealData-managed** (`Block\Adminhtml\System\Config\ReadOnlyField`);
`bin/magento config:set` is the fallback for stores not using app provisioning. Full
cross-repo contract: `../idealdata3-docs/_docs/pixel/adobe-commerce-config-provisioning.md`.

**Read-back + heal (since 1.7).** `GET /rest/V1/tnw-idealdata/pixel-config`
(`PixelConfigManagementInterface::get` → `PixelConfigStateInterface`) returns the
LIVE config so the app reconciles its stored mirror + heals drift — AC is the
source of truth for what's actually running. The **raw token is never returned**:
only `token_present` + a `token_sha256` fingerprint (hash-compared against the
app's stored active-token SHA-256). Same ACL as the write. `save` also gained a
**token-preserve** path: `enabled:true` + an EMPTY `token` keeps the stored token
(so the app can re-push corrected enabled/URLs to heal drift WITHOUT minting a
raw token — it stores only a hash).

> **⚠️ Pixel-config endpoint ACL — intentional shortcut + follow-up TODO.**
> `PUT /rest/V1/tnw-idealdata/pixel-config` is guarded by the core
> `Magento_Catalog::products` resource, **not** a dedicated one. Reason: the IdealData
> integration already holds `Magento_Catalog::products` (product sync + Signal 30), so
> reusing it needs **no new grant and no integration reauthorization** for existing
> merchants. The trade-off is a slightly broader-than-ideal ACL (pixel config gated by a
> product permission).
>
> **TODO (opportunistic, low priority):** introduce a dedicated
> `TNW_Idealdata::pixel_config_write` ACL resource and point the route at it — but only
> when a future module change ALREADY forces the integration to be reauthorized (i.e. a
> new grant becomes unavoidable for another reason). Piggy-back the pixel grant on that
> same reauthorization so we get the clean, tightly-scoped ACL for free. Do **not** add
> it as a standalone change — a lone new grant would force every existing merchant to
> reauthorize just for this.

## Docs repo sync — standing rule

After ANY change here, check the documentation hub (`../idealdata3-docs`) and
add/update/remove the relevant `_docs/` entry (pixel docs live in
`_docs/pixel/`; `pixel-decisions.md` is authoritative, `pixel-implementation.md` is the
append-only build log). Commit docs on the same branch name as the code change.
