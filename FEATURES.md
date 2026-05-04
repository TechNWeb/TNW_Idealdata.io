# TNW_Idealdata — обзор модуля и реализованных функций

Модуль `TNW_Idealdata` (composer: `tnw/module-idealdata`, версия 1.3) расширяет
стандартный REST/SOAP API Adobe Commerce (Magento 2) дополнительными данными,
необходимыми для интеграции с CRM-платформой **IdealData.io**. Модуль
преимущественно read-only с точки зрения публичного API и фокусируется на:

- обогащении сущностей (Product, Customer, Cart, CartItem) дополнительными полями;
- delta-sync — корректной отдаче сущностей, у которых изменились "связанные" таблицы (stock, address);
- сборе данных о неуспешных платежах (failed transactions);
- трекинге происхождения корзин (Cart A → Cart B lineage) для admin-флоу;
- предоставлении REST endpoints для статусов заказов и failed-transactions;
- adminhtml UI для онбординга/поддержки.

Целевая версия Magento Framework: `>=103.0.6` (Magento 2.4.x).
Зависимости: `Magento_Catalog`, `Magento_CatalogInventory`, `Magento_InventoryApi`,
`Magento_Customer`, `Magento_Quote`, `Magento_Sales`, `Magento_Payment`, `TNW_Marketing`.

---

## 1. Реализованные функции

### 1.1. Определение типа клиента (B2B vs B2C)

**Файлы:** `Plugin/CustomerRepositoryPlugin.php`, `etc/extension_attributes.xml`

Добавляется extension attribute `customer_type` (string) к
`Magento\Customer\Api\Data\CustomerInterface`.

Логика определения (приоритеты):
1. Если установлен модуль `Magento_Company` и `CompanyManagementInterface::getByCustomerId()` возвращает компанию → `company_user`.
2. Если у клиента есть кастомный атрибут `company` с непустым значением → `company_user`.
3. Если у дефолтного billing-адреса заполнено поле `company` → `company_user`.
4. Иначе → `individual_user`.

Активируется через `afterGetList` на `Magento\Customer\Api\CustomerRepositoryInterface`.

### 1.2. REST endpoint: статусы заказов

**Файлы:** `Api/OrderStatusRepositoryInterface.php`, `Api/Data/OrderStatusInterface.php`,
`Model/OrderStatusRepository.php`, `Model/OrderStatus.php`, `etc/webapi.xml`

`GET /V1/order/status` — возвращает все статусы заказов с человекочитаемыми
лейблами и связанным state (`new`, `processing`, …).
ACL: `Magento_Sales::sales`.

Источник данных — `Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory`
с `joinStates()`.

### 1.3. Захват неуспешных платежных транзакций

**Файлы:** `Observer/CaptureFailedPaymentObserver.php`, `etc/events.xml`,
`etc/db_schema.xml` (таблица `tnw_quote_payment_transaction`).

Observer на событии `sales_order_payment_place_end` пишет в новую таблицу
`tnw_quote_payment_transaction` запись о неуспешной попытке оплаты.

Что именно фиксируется:
- идентификаторы: quote_id, store_id, transaction_id, customer_id, customer_email;
- статус (`declined` / `failed` / `error`);
- decline_code (raw из шлюза), decline_reason (текст), decline_category (`hard` / `soft` / `technical`);
- gateway_message — sanitized JSON всего `additionalInformation` (вырезаны `cc_number`, `cc_cid`, `cc_ss_*`, `card_number`, `cvv`, `cvc`, `pan`);
- payment_method, card_type, card_last_four, card_expiry_month/year (PCI-safe);
- сумма + ISO 4217 валюта;
- IP, user-agent, признак гостя, attempt_number (увеличивающийся per-quote);
- created_at + updated_at (auto-update для delta-sync).

Признаки, по которым транзакция считается failed:
- TxnType = `void`;
- `additional_information.is_transaction_declined === true`;
- `additional_information.is_transaction_denied === true`;
- транзакция закрыта и `transaction_status === 'declined'`.

Категоризация decline_code:
- **hard**: `do_not_honor`, `pickup_card`, `lost_card`, `stolen_card`, `restricted_card`, `security_violation`, `transaction_not_allowed`, `blocked`, `revocation_of_authorization`;
- **technical**: `gateway_timeout`, `network_error`, `processor_unavailable`, `system_error`, `service_unavailable`, `timeout`, `unknown_error`;
- иначе — **soft**.

### 1.4. REST endpoint: failed transactions

**Файлы:** `Api/FailedTransactionRepositoryInterface.php`,
`Api/Data/FailedTransactionResultInterface.php`, `Api/Data/TransactionDataInterface.php`,
`Api/Data/CartSnapshotInterface.php`, `Api/Data/CartItemSnapshotInterface.php`,
`Api/Data/CustomerSnapshotInterface.php`,
`Api/Data/FailedTransactionSearchResultsInterface.php`,
`Model/FailedTransactionRepository.php`, `Model/Data/*`, `etc/acl.xml`, `etc/webapi.xml`.

`GET /V1/tnw/carts/failed-transactions` возвращает список failed-транзакций
с прикреплённым snapshot корзины, items и клиента.

Параметры:
- `updated_at_from` (обязательно, ISO 8601);
- `updated_at_to`, `status` (`declined|failed|error`), `store_id`, `is_guest` (опционально);
- `pageSize` (default 100, max 500), `currentPage` (default 1).

ACL: `TNW_Idealdata::failed_transactions_read`.

Реализация — нативные `select()` на `ResourceConnection` с агрегированной выборкой:
один COUNT, один основной SELECT с `LIMIT/OFFSET` и три batch-SELECT-а
(quotes, quote_items, customers) для сборки snapshot-ов. В ответе помимо
`items` и `total_count` возвращается `page_info` (`page_size`, `current_page`, `total_pages`).

### 1.5. Обогащение Product stock-данными

**Файлы:** `Plugin/Product/StockItemPlugin.php`, `etc/extension_attributes.xml`, `etc/di.xml`

К `Magento\Catalog\Api\Data\ProductInterface` добавляются extension attributes:

| Attribute | Описание |
|---|---|
| `manage_stock` (int) | резолвленное значение с учётом `use_config_*` |
| `out_of_stock_threshold` (float) | min_qty (резолвленное) |
| `min_cart_qty` (float) | min_sale_qty (резолвленное) |
| `max_cart_qty` (float) | max_sale_qty (резолвленное) |
| `qty_uses_decimals` (int) | is_qty_decimal |
| `backorders` (int) | резолвленное |
| `enable_qty_increments` (int) | резолвленное |
| `source_items` (`SourceItemInterface[]`) | строки из `inventory_source_item` |

Plugin срабатывает на `afterGet` и `afterGetList` `ProductRepositoryInterface`
и **batch-загружает** stock_items + source_items за **2 SQL-запроса** на любой объём
продуктов. Системные дефолты `cataloginventory/item_options/*` кешируются в памяти
запроса.

### 1.6. Delta-sync: продукты с изменившимся stock

**Файлы:** `Plugin/Product/StockUpdatedAtFilterPlugin.php`, `etc/db_schema.xml`

В таблицу `inventory_source_item` добавлен столбец `tnw_updated_at` (timestamp,
nullable, `on_update=true`) с индексом — MySQL автоматически обновляет его при
любой модификации строки.

Plugin перехватывает фильтр `updated_at` на `GET /V1/products` и **дополняет**
выдачу продуктами, у которых:
- `inventory_source_item.tnw_updated_at` удовлетворяет условию,
- НО `catalog_product_entity.updated_at` НЕ удовлетворяет (т.е. stock изменился, а сам продукт нет).

Особенности:
- Пагинация целиком на уровне SQL (LIMIT/OFFSET) — никаких массивов всех ID в PHP;
- Дочитка отсутствующих продуктов через 1 batch-`getList` (с recursion guard `isInternalCall`), что также триггерит `StockItemPlugin::afterGetList`;
- `total_count` суммируется с native;
- Поддержка операторов `gteq|lteq|gt|lt|eq|neq`.

### 1.7. Delta-sync: клиенты с изменившимся адресом

**Файлы:** `Plugin/Customer/AddressUpdatedAtFilterPlugin.php`, `etc/db_schema.xml`

Симметрично `StockUpdatedAtFilterPlugin`, но для адресов: в `customer_address_entity`
добавлен `tnw_updated_at`. Перехватывает `GET /V1/customers/search` с фильтром
`updated_at` и доклеивает клиентов, у которых менялся адрес, но сам customer — нет.
SQL-pagination, batch getList, recursion guard.

### 1.8. Дополнительные атрибуты Cart (Quote)

**Файлы:** `Plugin/Cart/AddCartAttributesPlugin.php`, `etc/extension_attributes.xml`

К `Magento\Quote\Api\Data\CartInterface` добавлены extension attributes:

- `coupon_code` (string)
- `applied_rule_ids` (string)
- `customer_is_guest` (int)
- `tnw_parent_quote_id` (int) — Cart A
- `tnw_parent_quote_created_at` (string)
- `tnw_quote_source` (string) — см. §1.10
- `tnw_child_quote_id` (int) — Cart B

Активируется на `afterGet` и `afterGetList` `CartRepositoryInterface`.

### 1.9. Дополнительные атрибуты Cart Item

**Файлы:** `Plugin/Cart/AddCartItemAttributesPlugin.php`, `etc/extension_attributes.xml`

К `Magento\Quote\Api\Data\CartItemInterface` добавлены атрибуты с префиксом `tnw_`
во избежание коллизий с магической геттерной логикой `Quote\Item`:

- `tnw_product_id`, `tnw_parent_item_id`, `tnw_product_type`,
- `tnw_row_total`, `tnw_row_total_with_discount`.

Plugin **работает только в API-контексте** (`webapi_rest`/`webapi_soap`) — это
сознательное ограничение, чтобы не ломать admin/frontend-флоу
("Create Order → Move from cart"). При `getList` элементы корзин подгружаются
одним запросом для всех корзин (batch). Дополнительно делается
`setData('items', ...)` — чтобы REST-сериализатор увидел items для неактивных
корзин (идущих не через `$_items`).

### 1.10. Трекинг происхождения корзин (Cart A → Cart B lineage)

**Файлы:** `Plugin/AdminOrder/RecordSourceCartPlugin.php`,
`Observer/SetQuoteSourceObserver.php`, `etc/db_schema.xml`, `etc/events.xml`, `etc/di.xml`

В `quote` добавлены столбцы: `tnw_parent_quote_id`, `tnw_parent_quote_created_at`,
`tnw_quote_source`, `tnw_child_quote_id` (+ индекс).

Возможные значения `tnw_quote_source`:
| Значение | Когда выставляется |
|---|---|
| `customer_frontend` | обычная фронтовая отправка заказа |
| `api` | submit через webapi |
| `reorder` | при наличии `orig_order_id` |
| `admin_split_from_cart` | админ создал заказ, переместив товары из активной корзины клиента (Cart A) в новую корзину (Cart B) |
| `admin_manual` | админ создал заказ вручную, без переноса из корзины |

#### `SetQuoteSourceObserver` (event `sales_model_service_quote_submit_before`)

Выставляет `tnw_quote_source` для НЕ-admin-контекстов (`reorder` / `api` /
`customer_frontend`). Для admin-контекста явно ничего не делает —
обработка идёт в `RecordSourceCartPlugin`.

#### `RecordSourceCartPlugin::aroundCreateOrder` (`Magento\Sales\Model\AdminOrder\Create`)

Используется around-плагин:
1. **Перед** вызовом оригинального `createOrder()`: снимок `items_count` Cart A (customer cart);
2. **После**: чтение `items_count` ещё раз;
3. **Решение**:
   - `getOrigOrderId()` → `reorder`;
   - cart A items_count *уменьшился* → `admin_split_from_cart`, проставляются
     `tnw_parent_quote_id`/`tnw_parent_quote_created_at` на Cart B и
     `tnw_child_quote_id` на Cart A;
   - иначе → `admin_manual`.

Все апдейты делаются **сырым SQL** (`$connection->update()`), чтобы избежать
побочных эффектов collectTotals / save. `updated_at` принудительно
сдвигается на `now + 1s`, чтобы delta-sync гарантированно увидел изменение
(после createOrder Magento мог только что обновить эту строку).

### 1.11. Adminhtml-UI

**Файлы:** `etc/adminhtml/system.xml`, `etc/acl.xml`, `Block/Adminhtml/System/Config/{Intro,Onboarding,Support}.php`,
`view/adminhtml/templates/system/config/*.phtml`, `view/adminhtml/web/css/idealdata.css`,
`view/adminhtml/layout/adminhtml_system_config_edit.xml`, `view/adminhtml/web/images/idealdata-logo.png`.

В админке появляется отдельная вкладка **IDEALDATA.IO** в System → Configuration
с тремя секциями (collapsible):
- **Introduction** — маркетинговый блок с описанием и кнопкой «Request a Quote»;
- **Onboarding** — кнопка «Request Onboarding»;
- **Support** — кнопка «Open a ticket».

CSS отображает кастомный логотип в навигации, JS-функции toggle секций встроены
в шаблоны. ACL: `TNW_Idealdata::config`.

### 1.12. Изменения схемы БД

| Таблица | Изменение |
|---|---|
| `tnw_quote_payment_transaction` | НОВАЯ. 24 столбца, 5 индексов (по `quote_id`, `updated_at`, `(updated_at, status)`, `customer_email`, `(store_id, updated_at)`) |
| `inventory_source_item` | + `tnw_updated_at` (timestamp, nullable, on_update) + индекс |
| `customer_address_entity` | + `tnw_updated_at` (timestamp, nullable, on_update) + индекс |
| `quote` | + `tnw_parent_quote_id` (+ индекс), `tnw_parent_quote_created_at`, `tnw_quote_source`, `tnw_child_quote_id` |

`db_schema_whitelist.json` синхронизирован.

### 1.13. ACL ресурсы

| ID | Назначение |
|---|---|
| `TNW_Idealdata::config` | доступ к секции конфигурации в админке |
| `TNW_Idealdata::failed_transactions_read` | доступ к REST-endpoint failed transactions |

---

## 2. Архитектурные особенности

- **Batch loading везде, где возможно** (stock items, source items, quote items, customers, quotes) — целевой profile: O(1) SQL запросов на N продуктов.
- **SQL-pagination** в delta-sync плагинах — массивы ID не загружаются в PHP.
- **Recursion guard (`isInternalCall`)** — внутренний `getList` для дочитки сущностей не триггерит сам плагин.
- **Property promotion + readonly + strict_types** в новых файлах (PHP 8.1+).
- **PCI-safe** sanitization gateway-ответов: ключи cc_number/cvv/cvc/pan/card_number/cc_cid/cc_ss_* удаляются перед сериализацией в `gateway_message`.
- **Defensive try/catch** во всех плагинах с логированием в `LoggerInterface` — ни один из плагинов не должен сломать основной флоу при ошибке обогащения.
- **Расширения «через прослойку»**: ни одна фича не модифицирует Magento core напрямую — только plugins, observers и extension attributes.

---

## 3. Замечания по коду (review)

### 3.1. Несоответствие стиля между файлами

`Plugin/CustomerRepositoryPlugin.php` и `Model/OrderStatusRepository.php` написаны
в старом стиле:
- нет `declare(strict_types=1)`;
- свойства `protected` вместо `private readonly`;
- неполный property promotion;
- использование `ObjectManagerInterface` напрямую (см. ниже).

Остальные плагины и модели соответствуют единому стилю PHP 8.1+. Стоит привести
к общему виду.

### 3.2. Anti-pattern: ObjectManager в `CustomerRepositoryPlugin`

```php
// Plugin/CustomerRepositoryPlugin.php:41
$companyManagement = $this->objectManager->get(\Magento\Company\Api\CompanyManagementInterface::class);
$address = $this->objectManager->get(AddressRepositoryInterface::class)->getById(...);
```

Прямое использование `ObjectManager` — это анти-паттерн в Magento 2. Решение:
- B2B-ветку вынести в отдельный плагин/класс с conditional-DI через `etc/di.xml`;
- `AddressRepositoryInterface` инжектить через конструктор.

### 3.3. N+1 в `CustomerRepositoryPlugin`

Цикл по `$items`:
- `companyManagement->getByCustomerId($customer->getId())` — потенциально 1 запрос на клиента;
- `addressRepository->getById($customer->getDefaultBilling())` — ещё 1 запрос на клиента.

При `pageSize = 100` это до 200 дополнительных SQL. Нужен batch-механизм
(одним запросом подтянуть company-mapping и default billing-адреса для всех ID).

### 3.4. Race condition в `attempt_number`

`Observer/CaptureFailedPaymentObserver::getNextAttemptNumber()`:

```php
$count = (int) $connection->fetchOne("SELECT COUNT(*) FROM ... WHERE quote_id = ?", [$quoteId]);
return $count + 1;
```

Между SELECT и INSERT нет атомарности — при параллельных попытках оплаты
возможны коллизии attempt_number. Решения:
- использовать `MAX(attempt_number)+1` внутри транзакции с `SELECT ... FOR UPDATE`;
- либо изначально не хранить attempt_number, а вычислять `ROW_NUMBER()` при выдаче;
- либо использовать `INSERT ... ON DUPLICATE KEY UPDATE` с уникальным индексом.

### 3.5. Неиспользуемые `Model/PaymentTransaction` и `ResourceModel/PaymentTransaction`

Объявлены ResourceModel + Collection для `tnw_quote_payment_transaction`, но
ни Observer, ни Repository их не используют — всё пишется/читается raw SQL.
Либо привести репозиторий к стандартному паттерну Magento (Collection +
SearchResult), либо удалить неиспользуемые классы.

### 3.6. `FailedTransactionRepository::getList` отступает от конвенций Magento

Стандартная подпись для repository в Magento — `getList(SearchCriteriaInterface)`.
Здесь же — кастомная подпись с 7 позиционными параметрами. Это:
- усложняет swagger/composer-bundle потребителю;
- лишает клиента возможности задавать произвольные фильтры;
- не позволяет использовать стандартные `SearchCriteriaBuilder` хелперы.

Рекомендуется переделать на `SearchCriteriaInterface` + Collection или хотя бы
обернуть параметры в DTO/`@api`-интерфейс.

### 3.7. `gateway_message` может содержать чувствительные данные

В `sanitizeGatewayMessage` блеклистится только узкий набор ключей. Реальные
шлюзы (Authorize.Net, Braintree, Stripe) могут возвращать в `additionalInformation`:
- AVS-данные (адрес, индекс);
- email клиента;
- billing/shipping address фрагменты;
- raw fingerprint карты.

Безопаснее реализовать **whitelist** разрешённых ключей вместо blacklist
запрещённых. Иначе при обновлении шлюза/появлении нового ключа возможна утечка
PII в сырое поле `gateway_message` БД.

### 3.8. Недетерминированный `now + 1s` в `RecordSourceCartPlugin`

```php
$now = date('Y-m-d H:i:s', time() + 1);
```

Используется для гарантии «updated_at AFTER createOrder write». Это хрупко:
- зависит от системного времени;
- при clock skew или быстрых системах через 1 секунду может оказаться, что
  delta-sync подхватит запись не тот раз;
- плохо тестируется.

Альтернатива — оборачивать createOrder в транзакцию и явно вызывать
`UPDATE ... SET updated_at = NOW(6)` после COMMIT, либо считать lineage без
зависимости от `updated_at` (использовать отдельный feed).

### 3.9. Эвристика «items_count after < before» в `RecordSourceCartPlugin`

Если в момент создания order пользователь параллельно добавил товары в Cart A
из фронта, items_count может **не уменьшиться** — и фактический split
квалифицируется как `admin_manual`. Edge-case редкий, но возможен. Стоит
зафиксировать в комментарии или использовать дополнительный сигнал
(например, `_moveQuoteItems` flag, если до него можно дотянуться через session).

### 3.10. Состояние плагина между запросами (Address/Stock filters)

Плагины `AddressUpdatedAtFilterPlugin` / `StockUpdatedAtFilterPlugin` хранят
`$updatedAtValue`, `$pageSize`, `$currentPage` как state объекта. Singleton-плагины
живут per-request, но если в одном запросе делается несколько `getList`,
возможны:
- утечка состояния между вызовами (частично решено reset в начале `beforeGetList`);
- если `before` отработал, а `after` — нет (исключение между ними),
  state останется заполненным до следующего `before`.

Лучше передавать state через параметры или wrap-объект, либо явно reset-ить в
`finally`-блоке `afterGetList`.

### 3.11. `setData('items', $items)` как workaround сериализации

`AddCartItemAttributesPlugin::ensureItemsLoaded()` делает:
```php
$cart->setItems($items);
$cart->setData('items', $items);
```
Комментарий объясняет причину (REST serializer читает через `getData('items')`).
Это работает, но:
- хрупко на апгрейдах;
- стоит сослаться на конкретный класс/метод сериализатора в комментарии;
- желательно покрыть API-тестом, чтобы регрессию поймать на CI.

### 3.12. Composer constraint `magento/framework: >=103.0.6`

Без верхней границы — потенциальная проблема при major upgrade Magento.
Рекомендуется зафиксировать как `^103.0.6` или `>=103.0.6 <105.0`.

### 3.13. ACL ref `Magento_Sales::sales` для `/V1/order/status`

Ресурс существует, но обычно для read-only sales-данных используется
`Magento_Sales::sales_order` или `Magento_Sales::actions_view`. Стоит
сверить с матрицей прав в реальной интеграции.

### 3.14. `OrderStatusInterface` без `declare(strict_types=1)`

Старый стиль файла — отличается от остальных интерфейсов API. Косметика, но
непоследовательно.

### 3.15. Отсутствуют тесты

В модуле нет unit/integration-тестов. Учитывая, что критическая часть
(`RecordSourceCartPlugin`, delta-sync) содержит нетривиальную логику с
edge-кейсами, тесты крайне желательны. Минимум:
- unit на категоризацию decline_code;
- integration на REST endpoint `/V1/tnw/carts/failed-transactions`;
- integration на корректность `total_count` в delta-sync для пагинации.

### 3.16. Сериализация snapshot-объектов через `DataObject`

`Model/Data/CartSnapshot`, `CartItemSnapshot` и т.д. наследуют `DataObject`,
но реализуют лишь геттеры. При этом не реализован `set*` по интерфейсу
(их и нет), но REST-сериализатор Magento может попытаться сериализовать через
магические методы. Стоит протестировать выдачу — особенно null-поля
(`getCustomerId(): ?string`), которые могут отдаваться как `""` вместо `null`
из-за `(string) $this->getData('customer_id')` в `CartItemSnapshot::getProductId()`.

### 3.17. Inline JS в phtml-шаблонах

`intro.phtml`, `onboarding.phtml`, `support.phtml` содержат inline `<script>`
с глобальными функциями. По CSP-гайдлайнам Adobe Commerce 2.4+ это нежелательно.
Рекомендация — вынести в RequireJS-модуль и подключать через `view/adminhtml/web/js`.

---

## 4. Резюме

Модуль решает чёткий продуктовый кейс: **отдать наружу всё, что нужно
IdealData.io для CRM-интеграции, ничего не сломав в Magento**. Архитектура
в целом грамотная — продуманный delta-sync, batch-загрузка, разумные fallback'и
и логирование. Главные направления улучшения:

1. **Стандартизировать стиль** старых файлов (CustomerRepositoryPlugin, OrderStatusRepository) под новый.
2. **Убрать ObjectManager** и устранить N+1 в `CustomerRepositoryPlugin`.
3. **Перевести `FailedTransactionRepository`** на стандартный `SearchCriteriaInterface` + Collection (которая уже частично готова в `Model/ResourceModel/PaymentTransaction/Collection`).
4. **Whitelist** для sanitization gateway-ответов вместо blacklist.
5. **Атомарность** `attempt_number`.
6. **Тесты** на ключевую бизнес-логику.
7. **Inline JS → RequireJS** в admin-шаблонах.
