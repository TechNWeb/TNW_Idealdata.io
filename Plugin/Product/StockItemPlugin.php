<?php

declare(strict_types=1);

namespace TNW\Idealdata\Plugin\Product;

use Magento\Catalog\Api\Data\ProductExtensionFactory;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\Data\ProductSearchResultsInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

class StockItemPlugin
{
    /** Config paths for cataloginventory defaults */
    private const CONFIG_MANAGE_STOCK = 'cataloginventory/item_options/manage_stock';
    private const CONFIG_BACKORDERS = 'cataloginventory/item_options/backorders';
    private const CONFIG_MIN_QTY = 'cataloginventory/item_options/min_qty';
    private const CONFIG_MIN_SALE_QTY = 'cataloginventory/item_options/min_sale_qty';
    private const CONFIG_MAX_SALE_QTY = 'cataloginventory/item_options/max_sale_qty';
    private const CONFIG_ENABLE_QTY_INC = 'cataloginventory/item_options/enable_qty_increments';

    /** @var array|null Cached config values (read once per request) */
    private ?array $configDefaults = null;

    public function __construct(
        private readonly ProductExtensionFactory $extensionFactory,
        private readonly ResourceConnection $resourceConnection,
        private readonly ObjectManagerInterface $objectManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGet(
        ProductRepositoryInterface $subject,
        ProductInterface $result
    ): ProductInterface {
        $this->attachAllStockData([$result]);
        return $result;
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetList(
        ProductRepositoryInterface $subject,
        ProductSearchResultsInterface $result
    ): ProductSearchResultsInterface {
        $products = $result->getItems();
        if (!empty($products)) {
            $this->attachAllStockData($products);
        }
        return $result;
    }

    /**
     * Batch-loads stock_item + source_items + resolved stock config
     * for all products. Exactly 2 SQL queries total.
     *
     * @param ProductInterface[] $products
     */
    private function attachAllStockData(array $products): void
    {
        $productIds = [];
        $skus = [];
        foreach ($products as $product) {
            $id = (int) $product->getId();
            $sku = $product->getSku();
            if ($id > 0) {
                $productIds[$id] = $product;
            }
            if ($sku) {
                $skus[] = $sku;
            }
        }

        $stockItemsMap = $this->batchLoadStockItems(array_keys($productIds));
        $sourceItemsMap = !empty($skus) ? $this->batchLoadSourceItems($skus) : [];
        $defaults = $this->getConfigDefaults();

        foreach ($products as $product) {
            try {
                $ext = $product->getExtensionAttributes();
                if ($ext === null) {
                    $ext = $this->extensionFactory->create();
                }

                // stock_item (raw)
                $productId = (int) $product->getId();
                $stockItem = $ext->getStockItem();
                if ($stockItem === null && isset($stockItemsMap[$productId])) {
                    $stockItem = $stockItemsMap[$productId];
                    $ext->setStockItem($stockItem);
                }

                // Resolved stock configuration
                $this->attachResolvedStockConfig($ext, $stockItem, $defaults);

                // source_items
                if (method_exists($ext, 'setSourceItems') && $ext->getSourceItems() === null) {
                    $ext->setSourceItems($sourceItemsMap[$product->getSku()] ?? []);
                }

                $product->setExtensionAttributes($ext);
            } catch (\Throwable $e) {
                $this->logger->debug(
                    'TNW_Idealdata: Could not attach stock data to product ' . ($product->getId() ?? '?'),
                    ['exception' => $e->getMessage()]
                );
            }
        }
    }

    /**
     * Set resolved stock config values on extension attributes.
     * When use_config_* is true, the value comes from system config.
     * When false, the value comes from the stock item row.
     */
    private function attachResolvedStockConfig(
        $ext,
        ?StockItemInterface $stockItem,
        array $defaults
    ): void {
        if ($ext->getManageStock() !== null) {
            return; // already resolved
        }

        if ($stockItem === null) {
            $ext->setManageStock((int) $defaults['manage_stock']);
            $ext->setOutOfStockThreshold((float) $defaults['min_qty']);
            $ext->setMinCartQty((float) $defaults['min_sale_qty']);
            $ext->setMaxCartQty((float) $defaults['max_sale_qty']);
            $ext->setQtyUsesDecimals(0);
            $ext->setBackorders((int) $defaults['backorders']);
            $ext->setEnableQtyIncrements((int) $defaults['enable_qty_increments']);
            return;
        }

        // Manage Stock
        $ext->setManageStock(
            $stockItem->getUseConfigManageStock()
                ? (int) $defaults['manage_stock']
                : (int) $stockItem->getManageStock()
        );

        // Out-of-Stock Threshold (min_qty)
        $ext->setOutOfStockThreshold(
            $stockItem->getUseConfigMinQty()
                ? (float) $defaults['min_qty']
                : (float) $stockItem->getMinQty()
        );

        // Minimum Qty Allowed in Shopping Cart
        $ext->setMinCartQty(
            $stockItem->getUseConfigMinSaleQty()
                ? (float) $defaults['min_sale_qty']
                : (float) $stockItem->getMinSaleQty()
        );

        // Maximum Qty Allowed in Shopping Cart
        $ext->setMaxCartQty(
            $stockItem->getUseConfigMaxSaleQty()
                ? (float) $defaults['max_sale_qty']
                : (float) $stockItem->getMaxSaleQty()
        );

        // Qty Uses Decimals (no use_config flag)
        $ext->setQtyUsesDecimals((int) $stockItem->getIsQtyDecimal());

        // Backorders
        $ext->setBackorders(
            $stockItem->getUseConfigBackorders()
                ? (int) $defaults['backorders']
                : (int) $stockItem->getBackorders()
        );

        // Enable Qty Increments
        $ext->setEnableQtyIncrements(
            $stockItem->getUseConfigEnableQtyInc()
                ? (int) $defaults['enable_qty_increments']
                : (int) $stockItem->getEnableQtyIncrements()
        );
    }

    /**
     * Read config defaults once per request, cache in memory.
     */
    private function getConfigDefaults(): array
    {
        if ($this->configDefaults === null) {
            $this->configDefaults = [
                'manage_stock'          => $this->scopeConfig->getValue(self::CONFIG_MANAGE_STOCK, ScopeInterface::SCOPE_STORE) ?? 1,
                'backorders'            => $this->scopeConfig->getValue(self::CONFIG_BACKORDERS, ScopeInterface::SCOPE_STORE) ?? 0,
                'min_qty'               => $this->scopeConfig->getValue(self::CONFIG_MIN_QTY, ScopeInterface::SCOPE_STORE) ?? 0,
                'min_sale_qty'          => $this->scopeConfig->getValue(self::CONFIG_MIN_SALE_QTY, ScopeInterface::SCOPE_STORE) ?? 1,
                'max_sale_qty'          => $this->scopeConfig->getValue(self::CONFIG_MAX_SALE_QTY, ScopeInterface::SCOPE_STORE) ?? 10000,
                'enable_qty_increments' => $this->scopeConfig->getValue(self::CONFIG_ENABLE_QTY_INC, ScopeInterface::SCOPE_STORE) ?? 0,
            ];
        }
        return $this->configDefaults;
    }

    /**
     * @param int[] $productIds
     * @return array<int, StockItemInterface>
     */
    private function batchLoadStockItems(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        try {
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName('cataloginventory_stock_item');

            $select = $connection->select()
                ->from($table)
                ->where('product_id IN (?)', $productIds);

            $rows = $connection->fetchAll($select);

            $factory = $this->objectManager->get(
                \Magento\CatalogInventory\Api\Data\StockItemInterfaceFactory::class
            );

            $map = [];
            foreach ($rows as $row) {
                $stockItem = $factory->create(['data' => $row]);
                $map[(int) $row['product_id']] = $stockItem;
            }

            return $map;
        } catch (\Throwable $e) {
            $this->logger->debug(
                'TNW_Idealdata: Could not batch-load stock items',
                ['exception' => $e->getMessage()]
            );
            return [];
        }
    }

    /**
     * @param string[] $skus
     * @return array<string, \Magento\InventoryApi\Api\Data\SourceItemInterface[]>
     */
    private function batchLoadSourceItems(array $skus): array
    {
        if (empty($skus)) {
            return [];
        }

        try {
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName('inventory_source_item');

            $select = $connection->select()
                ->from($table, ['sku', 'source_code', 'quantity', 'status'])
                ->where('sku IN (?)', $skus);

            $rows = $connection->fetchAll($select);

            if (empty($rows)) {
                return [];
            }

            $factory = $this->objectManager->get(
                \Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory::class
            );

            $map = [];
            foreach ($rows as $row) {
                $sku = $row['sku'];
                $sourceItem = $factory->create();
                $sourceItem->setSku($sku);
                $sourceItem->setSourceCode($row['source_code']);
                $sourceItem->setQuantity((float) $row['quantity']);
                $sourceItem->setStatus((int) $row['status']);
                $map[$sku][] = $sourceItem;
            }

            return $map;
        } catch (\Throwable $e) {
            $this->logger->debug(
                'TNW_Idealdata: Could not batch-load source items',
                ['exception' => $e->getMessage()]
            );
            return [];
        }
    }
}
