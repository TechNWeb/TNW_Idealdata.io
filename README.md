# TNW_Idealdata.io
Magento API module, extends native Adobecommerce(Magento) API and provides additional information for the Idealdata.io system.

To install/upgrade this module run the following commands in your Adobecommerce folder:
```
composer require tnw/module-idealdata=1.5 --no-update
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

### Enabling from the admin

Configure under **Stores → Configuration → IDEALDATA.IO → Storefront Pixel**:

| Field | Value |
|-------|-------|
| **Enable Pixel** | `Yes` |
| **Ingest Base URL** | Full ingest base URL **including** the `/pixel-ingest` prefix (e.g. `https://app.idealdata.io/pixel-ingest`, or an ngrok/localhost URL for local testing). |
| **Loader URL** | Full URL to the pixel loader script (e.g. `https://app.idealdata.io/pixel/loader.js`). |
| **Pixel Token** | The `idpx_…` token issued by IdealData for this store's connection (see below). Exposed to storefront JS by design — not a secret. |

Nothing is injected while **Enable Pixel** is `No`, or while the Token / Loader
URL are empty. The snippet is self-contained and fails silently: it only sets a
global and appends an async `<script>` — it can never block or break storefront
JavaScript.

Get the token by enabling the pixel on the store's **Adobe Commerce connection**
in the IdealData app (the enable action issues the token and returns its raw
value once — copy it into the **Pixel Token** field above).

### Manual install (fallback, when module-owned injection is not used)

If you prefer not to let the module inject the loader (or you are on a theme /
setup where the layout injection is not desired), paste this snippet into the
storefront `<head>` (e.g. **Content → Design → Configuration → HTML Head →
Scripts and Style Sheets**, or a "Miscellaneous HTML" block). Substitute your
own token / ingest base / loader URL:

```html
<script>
  window.idealdataSettings = {
    token: 'idpx_YOUR_TOKEN',
    platform: 'adobecommerce',
    ingestBase: 'https://app.idealdata.io/pixel-ingest'
  };
</script>
<script async src="https://app.idealdata.io/pixel/loader.js"></script>
```

> Do **not** add an inline-PHP `setCustomerId` line — it works only with Full
> Page Cache disabled. The pixel reads the customer id client-side from the
> `tnw-idealdata-identity` customer-data section instead (installed by this
> module).

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
