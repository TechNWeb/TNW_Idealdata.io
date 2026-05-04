# TNW_Idealdata — Module Overview & Implemented Features

The `TNW_Idealdata` module (composer: `tnw/module-idealdata`, version 1.3)
extends the standard Adobe Commerce (Magento 2) REST/SOAP API with additional
data required for integration with the **IdealData.io** CRM platform. The module
is largely read-only from the public-API standpoint and focuses on:

- enriching entities (Product, Customer, Cart, CartItem) with extra fields;
- delta-sync — correctly returning entities whose "related" tables (stock, address) have changed;
- collecting data on failed payment transactions;
- tracking cart origin (Cart A → Cart B lineage) for admin flows;
- exposing REST endpoints for order statuses and failed transactions;
- providing an adminhtml UI for onboarding/support.

Target Magento Framework version: `>=103.0.6` (Magento 2.4.x).
Dependencies: `Magento_Catalog`, `Magento_CatalogInventory`, `Magento_InventoryApi`,
`Magento_Customer`, `Magento_Quote`, `Magento_Sales`, `Magento_Payment`, `TNW_Marketing`.

---

## 1. Implemented Features

### 1.1. Customer Type Detection (B2B vs B2C)

**Files:** `Plugin/CustomerRepositoryPlugin.php`, `etc/extension_attributes.xml`

Adds the `customer_type` extension attribute (string) to
`Magento\Customer\Api\Data\CustomerInterface`.

Detection logic (priority order):
1. If `Magento_Company` is installed and `CompanyManagementInterface::getByCustomerId()` returns a company → `company_user`.
2. If the customer has a custom `company` attribute with a non-empty value → `company_user`.
3. If the default billing address has a non-empty `company` field → `company_user`.
4. Otherwise → `individual_user`.

Wired in via `afterGetList` on `Magento\Customer\Api\CustomerRepositoryInterface`.

### 1.2. REST endpoint: order statuses

**Files:** `Api/OrderStatusRepositoryInterface.php`, `Api/Data/OrderStatusInterface.php`,
`Model/OrderStatusRepository.php`, `Model/OrderStatus.php`, `etc/webapi.xml`

`GET /V1/order/status` — returns all order statuses with human-readable
labels and the associated state (`new`, `processing`, …).
ACL: `Magento_Sales::sales`.

Data source: `Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory`
with `joinStates()`.

### 1.3. Failed payment transaction capture

**Files:** `Observer/CaptureFailedPaymentObserver.php`, `etc/events.xml`,
`etc/db_schema.xml` (table `tnw_quote_payment_transaction`).

An observer on `sales_order_payment_place_end` writes a record into the new
`tnw_quote_payment_transaction` table for each failed payment attempt.

What gets captured:
- identifiers: quote_id, store_id, transaction_id, customer_id, customer_email;
- status (`declined` / `failed` / `error`);
- decline_code (raw from the gateway), decline_reason (text), decline_category (`hard` / `soft` / `technical`);
- gateway_message — sanitized JSON of the entire `additionalInformation` (`cc_number`, `cc_cid`, `cc_ss_*`, `card_number`, `cvv`, `cvc`, `pan` are stripped);
- payment_method, card_type, card_last_four, card_expiry_month/year (PCI-safe);
- amount + ISO 4217 currency;
- IP, user-agent, guest flag, attempt_number (incremented per quote);
- created_at + updated_at (auto-updated for delta-sync).

Failure detection signals:
- TxnType = `void`;
- `additional_information.is_transaction_declined === true`;
- `additional_information.is_transaction_denied === true`;
- transaction is closed AND `transaction_status === 'declined'`.

decline_code categorization:
- **hard**: `do_not_honor`, `pickup_card`, `lost_card`, `stolen_card`, `restricted_card`, `security_violation`, `transaction_not_allowed`, `blocked`, `revocation_of_authorization`;
- **technical**: `gateway_timeout`, `network_error`, `processor_unavailable`, `system_error`, `service_unavailable`, `timeout`, `unknown_error`;
- otherwise → **soft**.

### 1.4. REST endpoint: failed transactions

**Files:** `Api/FailedTransactionRepositoryInterface.php`,
`Api/Data/FailedTransactionResultInterface.php`, `Api/Data/TransactionDataInterface.php`,
`Api/Data/CartSnapshotInterface.php`, `Api/Data/CartItemSnapshotInterface.php`,
`Api/Data/CustomerSnapshotInterface.php`,
`Api/Data/FailedTransactionSearchResultsInterface.php`,
`Model/FailedTransactionRepository.php`, `Model/Data/*`, `etc/acl.xml`, `etc/webapi.xml`.

`GET /V1/tnw/carts/failed-transactions` returns a paginated list of failed
transactions, each enriched with snapshots of the cart, its items and the customer.

Parameters:
- `updated_at_from` (required, ISO 8601);
- `updated_at_to`, `status` (`declined|failed|error`), `store_id`, `is_guest` (optional);
- `pageSize` (default 100, max 500), `currentPage` (default 1).

ACL: `TNW_Idealdata::failed_transactions_read`.

Implementation — direct `select()` calls on `ResourceConnection` with an
aggregated query plan: one COUNT, one main SELECT with `LIMIT/OFFSET`, plus
three batch SELECTs (quotes, quote_items, customers) used to assemble the
snapshots. The response includes `items` and `total_count` plus a `page_info`
block (`page_size`, `current_page`, `total_pages`).

### 1.5. Product stock-data enrichment

**Files:** `Plugin/Product/StockItemPlugin.php`, `etc/extension_attributes.xml`, `etc/di.xml`

Adds the following extension attributes to `Magento\Catalog\Api\Data\ProductInterface`:

| Attribute | Description |
|---|---|
| `manage_stock` (int) | resolved value taking `use_config_*` flags into account |
| `out_of_stock_threshold` (float) | resolved min_qty |
| `min_cart_qty` (float) | resolved min_sale_qty |
| `max_cart_qty` (float) | resolved max_sale_qty |
| `qty_uses_decimals` (int) | is_qty_decimal |
| `backorders` (int) | resolved |
| `enable_qty_increments` (int) | resolved |
| `source_items` (`SourceItemInterface[]`) | rows from `inventory_source_item` |

The plugin runs on `afterGet` and `afterGetList` of `ProductRepositoryInterface`
and **batch-loads** stock_items + source_items in **2 SQL queries total**,
regardless of how many products are returned. System defaults
(`cataloginventory/item_options/*`) are cached in request memory.

### 1.6. Delta-sync: products with changed stock

**Files:** `Plugin/Product/StockUpdatedAtFilterPlugin.php`, `etc/db_schema.xml`

A `tnw_updated_at` column (timestamp, nullable, `on_update=true`) is added to
`inventory_source_item` together with an index — MySQL automatically refreshes
the column on any row modification.

The plugin intercepts the `updated_at` filter on `GET /V1/products` and
**augments** the result set with products where:
- `inventory_source_item.tnw_updated_at` matches the filter,
- BUT `catalog_product_entity.updated_at` does NOT (i.e. stock changed but the product itself didn't).

Highlights:
- Pagination is fully SQL-based (LIMIT/OFFSET) — no in-memory ID arrays;
- Missing products are loaded via a single batch `getList` call (with a `isInternalCall` recursion guard), which also triggers `StockItemPlugin::afterGetList`;
- `total_count` is summed with the native total;
- Operators supported: `gteq|lteq|gt|lt|eq|neq`.

### 1.7. Delta-sync: customers with changed addresses

**Files:** `Plugin/Customer/AddressUpdatedAtFilterPlugin.php`, `etc/db_schema.xml`

Symmetric to `StockUpdatedAtFilterPlugin`, but for addresses: a `tnw_updated_at`
column is added to `customer_address_entity`. The plugin intercepts
`GET /V1/customers/search` filtered by `updated_at` and appends customers
whose address changed but whose own row did not. Same architecture:
SQL-pagination, batch getList, recursion guard.

### 1.8. Cart (Quote) extension attributes

**Files:** `Plugin/Cart/AddCartAttributesPlugin.php`, `etc/extension_attributes.xml`

Adds the following extension attributes to `Magento\Quote\Api\Data\CartInterface`:

- `coupon_code` (string)
- `applied_rule_ids` (string)
- `customer_is_guest` (int)
- `tnw_parent_quote_id` (int) — Cart A
- `tnw_parent_quote_created_at` (string)
- `tnw_quote_source` (string) — see §1.10
- `tnw_child_quote_id` (int) — Cart B

Activated via `afterGet` and `afterGetList` of `CartRepositoryInterface`.

### 1.9. Cart Item extension attributes

**Files:** `Plugin/Cart/AddCartItemAttributesPlugin.php`, `etc/extension_attributes.xml`

Adds the following attributes to `Magento\Quote\Api\Data\CartItemInterface`,
all prefixed with `tnw_` to avoid collisions with `Quote\Item`'s magic-getter logic:

- `tnw_product_id`, `tnw_parent_item_id`, `tnw_product_type`,
- `tnw_row_total`, `tnw_row_total_with_discount`.

The plugin **only operates in API context** (`webapi_rest`/`webapi_soap`) — this
is a deliberate restriction to avoid breaking admin/frontend flows
("Create Order → Move from cart"). On `getList`, items for all carts are
loaded in a single batch SQL query. Additionally, `setData('items', ...)` is
called explicitly so that the REST serializer picks up items for inactive
carts (which don't go through `$_items`).

### 1.10. Cart lineage tracking (Cart A → Cart B)

**Files:** `Plugin/AdminOrder/RecordSourceCartPlugin.php`,
`Observer/SetQuoteSourceObserver.php`, `etc/db_schema.xml`, `etc/events.xml`, `etc/di.xml`

The `quote` table gains the columns `tnw_parent_quote_id`,
`tnw_parent_quote_created_at`, `tnw_quote_source`, `tnw_child_quote_id`
(plus an index).

Possible values of `tnw_quote_source`:
| Value | When it is set |
|---|---|
| `customer_frontend` | normal frontend order submission |
| `api` | submission via webapi |
| `reorder` | when `orig_order_id` is present |
| `admin_split_from_cart` | admin created an order by moving items from the customer's active cart (Cart A) into a new cart (Cart B) |
| `admin_manual` | admin built the order manually, no items moved from a cart |

#### `SetQuoteSourceObserver` (event `sales_model_service_quote_submit_before`)

Sets `tnw_quote_source` for non-admin contexts (`reorder` / `api` /
`customer_frontend`). Admin context is intentionally skipped — it is handled
by `RecordSourceCartPlugin`.

#### `RecordSourceCartPlugin::aroundCreateOrder` (`Magento\Sales\Model\AdminOrder\Create`)

Implemented as an around-plugin:
1. **Before** the original `createOrder()` runs: snapshot `items_count` of Cart A (customer cart).
2. **After**: read `items_count` again.
3. **Decision tree**:
   - `getOrigOrderId()` is set → `reorder`;
   - Cart A items_count *decreased* → `admin_split_from_cart`; Cart B is stamped with `tnw_parent_quote_id` / `tnw_parent_quote_created_at`, Cart A is stamped with `tnw_child_quote_id`;
   - otherwise → `admin_manual`.

All updates are issued as **raw SQL** (`$connection->update()`) to avoid the
side effects of collectTotals / save. `updated_at` is forcibly set to
`now + 1s` so that delta-sync is guaranteed to pick up the change (Magento
may have just written the row inside `createOrder()`).

### 1.11. Adminhtml UI

**Files:** `etc/adminhtml/system.xml`, `etc/acl.xml`, `Block/Adminhtml/System/Config/{Intro,Onboarding,Support}.php`,
`view/adminhtml/templates/system/config/*.phtml`, `view/adminhtml/web/css/idealdata.css`,
`view/adminhtml/layout/adminhtml_system_config_edit.xml`, `view/adminhtml/web/images/idealdata-logo.png`.

A dedicated **IDEALDATA.IO** tab appears in System → Configuration with three
collapsible sections:
- **Introduction** — marketing block with a "Request a Quote" button;
- **Onboarding** — "Request Onboarding" button;
- **Support** — "Open a ticket" button.

CSS renders a custom logo in the navigation; per-section toggle JS is inlined
into each template. ACL: `TNW_Idealdata::config`.

### 1.12. DB schema changes

| Table | Change |
|---|---|
| `tnw_quote_payment_transaction` | NEW. 24 columns, 5 indexes (on `quote_id`, `updated_at`, `(updated_at, status)`, `customer_email`, `(store_id, updated_at)`) |
| `inventory_source_item` | + `tnw_updated_at` (timestamp, nullable, on_update) + index |
| `customer_address_entity` | + `tnw_updated_at` (timestamp, nullable, on_update) + index |
| `quote` | + `tnw_parent_quote_id` (+ index), `tnw_parent_quote_created_at`, `tnw_quote_source`, `tnw_child_quote_id` |

`db_schema_whitelist.json` is in sync.

### 1.13. ACL resources

| ID | Purpose |
|---|---|
| `TNW_Idealdata::config` | access to the configuration section in the admin |
| `TNW_Idealdata::failed_transactions_read` | access to the failed-transactions REST endpoint |

---

## 2. Architectural highlights

- **Batch loading wherever possible** (stock items, source items, quote items, customers, quotes) — target profile: O(1) SQL queries for N products.
- **SQL pagination** in delta-sync plugins — no ID arrays loaded into PHP memory.
- **Recursion guard (`isInternalCall`)** — internal `getList` calls used to fetch missing entities don't re-trigger the plugin.
- **Property promotion + readonly + strict_types** in newer files (PHP 8.1+).
- **PCI-safe** sanitization of gateway responses: the keys cc_number/cvv/cvc/pan/card_number/cc_cid/cc_ss_* are stripped before serialization into `gateway_message`.
- **Defensive try/catch** in every plugin with logging through `LoggerInterface` — no enrichment failure should ever break the main flow.
- **Extension via plugins**: no fix touches Magento core directly — only plugins, observers and extension attributes are used.

---

## 3. Code review notes

### 3.1. Inconsistent style between files

`Plugin/CustomerRepositoryPlugin.php` and `Model/OrderStatusRepository.php` use
the legacy style:
- no `declare(strict_types=1)`;
- `protected` properties instead of `private readonly`;
- incomplete property promotion;
- direct use of `ObjectManagerInterface` (see below).

The other plugins and models follow the unified PHP 8.1+ style. The legacy
files should be brought in line.

### 3.2. Anti-pattern: ObjectManager in `CustomerRepositoryPlugin`

```php
// Plugin/CustomerRepositoryPlugin.php:41
$companyManagement = $this->objectManager->get(\Magento\Company\Api\CompanyManagementInterface::class);
$address = $this->objectManager->get(AddressRepositoryInterface::class)->getById(...);
```

Direct use of `ObjectManager` is an anti-pattern in Magento 2. Suggested fix:
- move the B2B branch into a dedicated plugin/class with conditional DI via `etc/di.xml`;
- inject `AddressRepositoryInterface` through the constructor.

### 3.3. N+1 in `CustomerRepositoryPlugin`

Inside the `$items` loop:
- `companyManagement->getByCustomerId($customer->getId())` — potentially 1 query per customer;
- `addressRepository->getById($customer->getDefaultBilling())` — another 1 query per customer.

At `pageSize = 100` that adds up to ~200 extra SQL calls. A batch mechanism is
needed (load company-mapping and default-billing addresses for all IDs in a
single query).

### 3.4. Race condition on `attempt_number`

`Observer/CaptureFailedPaymentObserver::getNextAttemptNumber()`:

```php
$count = (int) $connection->fetchOne("SELECT COUNT(*) FROM ... WHERE quote_id = ?", [$quoteId]);
return $count + 1;
```

There is no atomicity between SELECT and INSERT — concurrent payment attempts
can produce duplicate attempt_numbers. Possible fixes:
- compute `MAX(attempt_number)+1` inside a transaction with `SELECT ... FOR UPDATE`;
- skip storing attempt_number altogether and compute `ROW_NUMBER()` at read time;
- use `INSERT ... ON DUPLICATE KEY UPDATE` against a unique index.

### 3.5. Unused `Model/PaymentTransaction` and `ResourceModel/PaymentTransaction`

A ResourceModel + Collection are declared for `tnw_quote_payment_transaction`,
but neither the Observer nor the Repository actually use them — everything is
read/written via raw SQL. Either align the repository with the standard
Magento pattern (Collection + SearchResult), or delete the unused classes.

### 3.6. `FailedTransactionRepository::getList` deviates from Magento conventions

The standard repository signature in Magento is `getList(SearchCriteriaInterface)`.
Here it is a custom signature with 7 positional parameters. Consequences:
- harder to consume from swagger / composer-bundled clients;
- the client cannot pass arbitrary filters;
- standard `SearchCriteriaBuilder` helpers are not usable.

Recommendation: switch to `SearchCriteriaInterface` + Collection, or at least
wrap the parameters in a DTO / `@api` interface.

### 3.7. `gateway_message` may contain sensitive data

`sanitizeGatewayMessage` blacklists only a narrow set of keys. Real gateways
(Authorize.Net, Braintree, Stripe) can return additional fields in
`additionalInformation`:
- AVS data (address, ZIP);
- customer email;
- billing/shipping address fragments;
- raw card fingerprint.

A safer approach is a **whitelist** of allowed keys instead of a blacklist of
forbidden ones. Otherwise a gateway upgrade or a new field can leak PII into
the raw `gateway_message` column.

### 3.8. Non-deterministic `now + 1s` in `RecordSourceCartPlugin`

```php
$now = date('Y-m-d H:i:s', time() + 1);
```

Used to guarantee that `updated_at` lands AFTER whatever `createOrder()` just
wrote. This is fragile:
- depends on system clock;
- under clock skew or fast hardware the +1s window may not actually be enough,
  causing delta-sync to miss the row;
- hard to test.

Alternative: wrap createOrder in a transaction and explicitly call
`UPDATE ... SET updated_at = NOW(6)` after COMMIT, or compute lineage without
relying on `updated_at` (use a dedicated feed).

### 3.9. "items_count after < before" heuristic in `RecordSourceCartPlugin`

If during admin order creation the customer concurrently adds items to Cart A
from the storefront, items_count may **fail to decrease** — and an actual
split is then mis-classified as `admin_manual`. Edge case, but possible. Worth
either documenting it explicitly or using an additional signal (e.g. the
`_moveQuoteItems` flag if reachable via session).

### 3.10. Plugin state across requests (Address/Stock filters)

The plugins `AddressUpdatedAtFilterPlugin` / `StockUpdatedAtFilterPlugin`
store `$updatedAtValue`, `$pageSize`, `$currentPage` as object state. Plugin
singletons live per-request, but if multiple `getList` calls happen within
the same request:
- state can leak between calls (partly mitigated by reset at the start of `beforeGetList`);
- if `before` ran but `after` didn't (exception in between), state remains
  populated until the next `before`.

Better to pass state via parameters or a wrapper object, or to reset
explicitly inside a `finally` block in `afterGetList`.

### 3.11. `setData('items', $items)` as a serialization workaround

`AddCartItemAttributesPlugin::ensureItemsLoaded()` does:
```php
$cart->setItems($items);
$cart->setData('items', $items);
```
The comment explains why (the REST serializer reads via `getData('items')`).
This works, but:
- it is fragile across Magento upgrades;
- the comment should reference the specific serializer class/method;
- ideally an API test would catch any regression on CI.

### 3.12. Composer constraint `magento/framework: >=103.0.6`

No upper bound — a potential problem on any Magento major upgrade.
Recommended: pin as `^103.0.6` or `>=103.0.6 <105.0`.

### 3.13. ACL ref `Magento_Sales::sales` for `/V1/order/status`

The resource exists, but read-only sales endpoints typically use
`Magento_Sales::sales_order` or `Magento_Sales::actions_view`. Worth
double-checking against the integration's permission matrix.

### 3.14. `OrderStatusInterface` without `declare(strict_types=1)`

Legacy file style — out of line with the rest of the API interfaces. Cosmetic,
but inconsistent.

### 3.15. No tests

The module ships without unit/integration tests. Given that some critical
parts (`RecordSourceCartPlugin`, delta-sync) contain non-trivial logic with
edge cases, tests are highly desirable. Minimum bar:
- unit test for decline_code categorization;
- integration test for the `/V1/tnw/carts/failed-transactions` REST endpoint;
- integration test for `total_count` correctness across delta-sync pagination.

### 3.16. Snapshot serialization via `DataObject`

`Model/Data/CartSnapshot`, `CartItemSnapshot`, etc. extend `DataObject` and
implement only getters. No `set*` methods declared on the interfaces (none
required), but Magento's REST serializer may still try to serialize via the
magic methods. Worth verifying the wire output — specifically nullable fields
(`getCustomerId(): ?string`) which may be returned as `""` instead of `null`
because of `(string) $this->getData('product_id')` in `CartItemSnapshot::getProductId()`.

### 3.17. Inline JS in phtml templates

`intro.phtml`, `onboarding.phtml`, `support.phtml` contain inline `<script>`
blocks with global functions. This is discouraged by Adobe Commerce 2.4+ CSP
guidelines. Recommendation: extract into a RequireJS module loaded from
`view/adminhtml/web/js`.

---

## 4. Summary

The module solves a clear product use case: **expose everything IdealData.io
needs for a CRM integration without breaking anything in Magento**. The
architecture is generally sound — thoughtful delta-sync, batch loading,
reasonable fallbacks and logging. Main areas for improvement:

1. **Standardize the style** of the legacy files (`CustomerRepositoryPlugin`, `OrderStatusRepository`) to match the new one.
2. **Drop ObjectManager** and remove the N+1 in `CustomerRepositoryPlugin`.
3. **Migrate `FailedTransactionRepository`** to the standard `SearchCriteriaInterface` + Collection (the Collection already partially exists in `Model/ResourceModel/PaymentTransaction/Collection`).
4. **Whitelist** for gateway-response sanitization instead of blacklist.
5. **Atomicity** for `attempt_number`.
6. **Tests** for the core business logic.
7. **Inline JS → RequireJS** in admin templates.
