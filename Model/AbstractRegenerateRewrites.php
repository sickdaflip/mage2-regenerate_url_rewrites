<?php
/**
 * AbstractRegenerateRewrites.php
 *
 * @package OlegKoval_RegenerateUrlRewrites
 * @author Oleg Koval <olegkoval.ca@gmail.com>
 * @copyright 2017-2067 Oleg Koval
 * @license OSL-3.0, AFL-3.0
 */

namespace OlegKoval\RegenerateUrlRewrites\Model;

use OlegKoval\RegenerateUrlRewrites\Helper\Regenerate as RegenerateHelper;
use Magento\Framework\App\ResourceConnection;
use Magento\UrlRewrite\Model\Storage\DbStorage;
use Magento\CatalogUrlRewrite\Model\ResourceModel\Category\Product as ProductUrlRewriteResource;

abstract class AbstractRegenerateRewrites
{
    /**
     * @var string
     */
    protected $entityType = 'product';

    /**
     * @var array
     */
    protected $storeRootCategoryId = [];

    /**
     * @var integer
     */
    protected $progressBarProgress = 0;

    /**
     * @var integer
     */
    protected $progressBarTotal = 0;

    /**
     * @var string
     */
    protected $mainDbTable;

    /**
     * @var string
     */
    protected $secondaryDbTable;

    /**
     * @var string
     */
    protected $categoryProductsDbTable;

    /**
     * Regenerate Rewrites custom options
     * @var array
     */
    public $regenerateOptions = [];

    /**
     * @var RegenerateHelper
     */
    protected $helper;

    /**
     * @var ResourceConnection
     */
    protected $resourceConnection;

    /**
     * RegenerateAbstract constructor
     *
     * @param RegenerateHelper $helper
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        RegenerateHelper   $helper,
        ResourceConnection $resourceConnection
    )
    {
        $this->helper = $helper;
        $this->resourceConnection = $resourceConnection;

        // set default regenerate options
        $this->regenerateOptions['saveOldUrls'] = false;
        $this->regenerateOptions['categoriesFilter'] = [];
        $this->regenerateOptions['productsFilter'] = [];
        $this->regenerateOptions['categoryId'] = null;
        $this->regenerateOptions['productId'] = null;
        $this->regenerateOptions['checkUseCategoryInProductUrl'] = false;
        $this->regenerateOptions['noRegenUrlKey'] = false;
        $this->regenerateOptions['showProgress'] = false;
    }

    /**
     * Regenerate Url Rewrites in specific store
     * @param int $storeId
     * @return mixed
     */
    abstract function regenerate(int $storeId = 0);

    /**
     * Return resource connection
     * @return ResourceConnection
     */
    protected function _getResourceConnection(): ResourceConnection
    {
        return $this->resourceConnection;
    }

    /**
     * Save Url Rewrites
     *
     * @param array $urlRewrites
     * @param array $entityData
     * @return $this
     */
    public function saveUrlRewrites(array $urlRewrites, array $entityData = []): static
    {
        $data = $this->_prepareUrlRewrites($urlRewrites);

        if (empty($data) && empty($entityData)) {
            return $this;
        }

        // delete + insert must run in ONE transaction: if the insert fails, the old rewrites
        // are restored by the rollback instead of leaving entities without any rewrite
        // (which makes the frontend fall back to the raw system URLs)
        $this->_getResourceConnection()->getConnection()->beginTransaction();
        try {
            if (!$this->regenerateOptions['saveOldUrls']) {
                if (empty($entityData) && !empty($data)) {
                    $entityData = $data;
                }
                $this->_deleteCurrentRewrites($entityData);
            }

            if (!empty($data)) {
                $this->_getResourceConnection()->getConnection()->insertOnDuplicate(
                    $this->_getMainTableName(),
                    $data,
                    ['request_path', 'metadata']
                );
            }
            $this->_getResourceConnection()->getConnection()->commit();

        } catch (\Exception $e) {
            $this->_getResourceConnection()->getConnection()->rollBack();
        }

        return $this;
    }

    /**
     * Show a progress bar in the console
     *
     * @param int $size
     */
    protected function _showProgress(int $size = 70): void
    {
        if (!$this->regenerateOptions['showProgress']) {
            return;
        }

        // if we go over our bound, just ignore it
        if ($this->progressBarProgress > $this->progressBarTotal) {
            return;
        }

        $perc = $this->progressBarTotal ? (double)($this->progressBarProgress / $this->progressBarTotal) : 1;
        $bar = floor($perc * $size);

        $status_bar = "\r[";
        $status_bar .= str_repeat('=', $bar);
        if ($bar < $size) {
            $status_bar .= '>';
            $status_bar .= str_repeat(' ', $size - $bar);
        } else {
            $status_bar .= '=';
        }

        $disp = number_format($perc * 100, 0);

        $status_bar .= "] {$disp}%  {$this->progressBarProgress}/{$this->progressBarTotal}";

        echo $status_bar;
        flush();

        // when done, send a newline
        if ($this->progressBarProgress == $this->progressBarTotal) {
            echo "\r\n";
        }
    }

    /**
     * @return string
     */
    protected function _getMainTableName(): string
    {
        if (empty($this->mainDbTable)) {
            $this->mainDbTable = $this->_getResourceConnection()->getTableName(DbStorage::TABLE_NAME);
        }

        return $this->mainDbTable;
    }

    /**
     * @return string
     */
    protected function _getSecondaryTableName(): string
    {
        if (empty($this->secondaryDbTable)) {
            $this->secondaryDbTable = $this->_getResourceConnection()->getTableName(ProductUrlRewriteResource::TABLE_NAME);
        }

        return $this->secondaryDbTable;
    }

    /**
     * @return string
     */
    protected function _getCategoryProductsTableName(): string
    {
        if (empty($this->categoryProductsDbTable)) {
            $this->categoryProductsDbTable = $this->_getResourceConnection()->getTableName('catalog_category_product');
        }

        return $this->categoryProductsDbTable;
    }

    /**
     * Delete current Url Rewrites
     *
     * @param array $entitiesData
     * @return $this
     */
    protected function _deleteCurrentRewrites(array $entitiesData = []): static
    {
        if (!empty($entitiesData)) {
            $whereConditions = [];
            foreach ($entitiesData as $entityData) {
                $whereConditions[] = sprintf(
                    '(entity_type = \'%s\' AND entity_id = %d AND store_id = %d)',
                    $entityData['entity_type'], $entityData['entity_id'], $entityData['store_id']
                );
            }
            $whereConditions = array_unique($whereConditions);

            // runs inside the transaction opened by saveUrlRewrites()
            $this->_getResourceConnection()->getConnection()->delete(
                $this->_getMainTableName(),
                implode(' OR ', $whereConditions)
            );
        }

        return $this;
    }

    /**
     * Update "catalog_url_rewrite_product_category" table
     *
     * @return $this
     */
    protected function _updateSecondaryTable(): static
    {
        $this->_getResourceConnection()->getConnection()->beginTransaction();
        try {
            $this->_getResourceConnection()->getConnection()->delete(
                $this->_getSecondaryTableName(),
                "url_rewrite_id NOT IN (SELECT url_rewrite_id FROM {$this->_getMainTableName()})"
            );
            $this->_getResourceConnection()->getConnection()->commit();

        } catch (\Exception $e) {
            $this->_getResourceConnection()->getConnection()->rollBack();
        }

        $select = $this->_getResourceConnection()->getConnection()->select()
            ->from(
                $this->_getMainTableName(),
                [
                    'url_rewrite_id',
                    'category_id' => new \Zend_Db_Expr(
                        'SUBSTRING_INDEX(SUBSTRING_INDEX(' . $this->_getMainTableName() . '.metadata, \'"\', -2), \'"\', 1)'
                    ),
                    'product_id' => 'entity_id'
                ]
            )
            ->where('metadata LIKE \'{"category_id":"%"}\'')
            ->where("url_rewrite_id NOT IN (SELECT url_rewrite_id FROM {$this->_getSecondaryTableName()})");
        $data = $this->_getResourceConnection()->getConnection()->fetchAll($select);

        if (!empty($data)) {
            // I'm using row-by-row inserts because some products/categories not exists in entity tables but Url Rewrites
            // for this entity still exists in url_rewrite DB table.
            // This is the issue of Magento EE (Data integrity/assurance of the accuracy and consistency of data),
            // and this extension was made to not fix this; I just avoid this issue
            foreach ($data as $row) {
                $this->_getResourceConnection()->getConnection()->beginTransaction();
                try {
                    $this->_getResourceConnection()->getConnection()->insertOnDuplicate(
                        $this->_getSecondaryTableName(),
                        $row,
                        ['product_id']
                    );
                    $this->_getResourceConnection()->getConnection()->commit();

                } catch (\Exception $e) {
                    $this->_getResourceConnection()->getConnection()->rollBack();
                }
            }
        }

        return $this;
    }

    /**
     * @param array $urlRewrites
     * @return array
     */
    protected function _prepareUrlRewrites(array $urlRewrites): array
    {
        $result = [];
        // request paths already used in this batch: "store_id|request_path" => entity info
        $processedPaths = [];

        foreach ($urlRewrites as $urlRewrite) {
            $rewrite = $urlRewrite->toArray();

            // check if the same Url Rewrite already exists
            $originalRequestPath = trim($rewrite['request_path']);

            // skip empty Url Rewrites - I don't know how this possible, but it happens in Magento:
            // maybe someone did import product programmatically and product(s) name(s) are empty
            if (empty($originalRequestPath)) continue;

            // split generated Url Rewrite into parts
            $pathParts = pathinfo($originalRequestPath);

            // remove leading/trailing slashes and dots from parts
            $pathParts['dirname'] = trim($pathParts['dirname'], './');
            $pathParts['filename'] = trim($pathParts['filename'], './');

            // If the last symbol was slash - let's use it as url suffix
            $urlSuffix = substr($originalRequestPath, -1) === '/' ? '/' : '';

            // re-set Url Rewrite with sanitized parts
            $rewrite['request_path'] = $this->_mergePartsIntoRewriteRequest($pathParts, '', $urlSuffix);

            // check if we have a duplicate (maybe exists product with the same name => same Url Rewrite)
            // if exists then add additional index to avoid a duplicates.
            // The check covers the DB AND the current batch: insertOnDuplicate would silently
            // merge two batch rows with equal (request_path, store_id) into one row, leaving
            // one of the entities without any Url Rewrite
            $index = 0;
            $skipRow = false;
            while (true) {
                $pathKey = $rewrite['store_id'] . '|' . $rewrite['request_path'];
                if (isset($processedPaths[$pathKey])) {
                    if ($processedPaths[$pathKey]['entity_type'] === $rewrite['entity_type']
                        && (int)$processedPaths[$pathKey]['entity_id'] === (int)$rewrite['entity_id']
                    ) {
                        // identical request path of the same entity is already in the batch - drop duplicate
                        $skipRow = true;
                        break;
                    }
                } elseif (!$this->_urlRewriteExists($rewrite)) {
                    break;
                }
                $index++;
                $rewrite['request_path'] = $this->_mergePartsIntoRewriteRequest($pathParts, (string)$index, $urlSuffix);
            }
            if ($skipRow) continue;

            $processedPaths[$rewrite['store_id'] . '|' . $rewrite['request_path']] = [
                'entity_type' => $rewrite['entity_type'],
                'entity_id' => $rewrite['entity_id'],
            ];
            $result[] = $rewrite;
        }

        return $result;
    }

    /**
     * Check if Url Rewrite with the same request path exists
     *
     * @param array $rewrite
     * @return string|false
     */
    protected function _urlRewriteExists(array $rewrite): string|false
    {
        $connection = $this->_getResourceConnection()->getConnection();

        // the unique key of url_rewrite spans (request_path, store_id) across ALL entity types,
        // so a collision with a rewrite of another type (e.g. product vs category) must be
        // detected too - only rows of the same entity are excluded
        $select = $connection->select()
            ->from($this->_getMainTableName(), ['url_rewrite_id'])
            ->where('request_path = ?', $rewrite['request_path'])
            ->where('store_id = ?', $rewrite['store_id'])
            ->where(
                $connection->quoteInto('NOT (entity_type = ?', $rewrite['entity_type'])
                . $connection->quoteInto(' AND entity_id = ?)', $rewrite['entity_id'])
            );

        return $connection->fetchOne($select);
    }

    /**
     * Merge Url Rewrite parts into one string
     *
     * @param array $pathParts
     * @param string $index
     * @param string $urlSuffix
     * @return string
     */
    protected function _mergePartsIntoRewriteRequest(array $pathParts, string $index = '', string $urlSuffix = ''): string
    {
        return (!empty($pathParts['dirname']) ? $pathParts['dirname'] . '/' : '') . $pathParts['filename']
            . (!empty($index) ? '-' . $index : '')
            . (!empty($pathParts['extension']) ? '.' . $pathParts['extension'] : '')
            . ($urlSuffix ?: '');
    }

    /**
     * Get root category I'd of specific store
     *
     * @param $storeId
     * @return int|null
     */
    protected function _getStoreRootCategoryId($storeId): ?int
    {
        if (empty($this->storeRootCategoryId[$storeId])) {
            $value = null;
            try {
                $store = $this->helper->getStoreManager()->getStore($storeId);
                if ($store) {
                    $value = $store->getRootCategoryId();
                }
            } catch (\Exception $e) {
            }

            $this->storeRootCategoryId[$storeId] = $value;
        }

        return $this->storeRootCategoryId[$storeId];
    }
}
