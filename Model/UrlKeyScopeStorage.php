<?php
/**
 * UrlKeyScopeStorage.php
 *
 * @package OlegKoval_RegenerateUrlRewrites
 * @author Philipp Breitsprecher
 * @license OSL-3.0, AFL-3.0
 */

declare(strict_types=1);

namespace OlegKoval\RegenerateUrlRewrites\Model;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Store\Model\ScopeInterface;
use OlegKoval\RegenerateUrlRewrites\Helper\Regenerate as RegenerateHelper;

/**
 * Low-level access to store-scoped varchar values of products and categories.
 *
 * The regeneration runs once per store. Writing url_key/url_path for every store
 * creates store-level overrides that are identical to the default value. Such
 * overrides make later changes in "All Store Views" ineffective, because the
 * store views keep their stale copy. This class lets the regenerate models read
 * the default-scope values, remove redundant overrides and build unique keys.
 */
class UrlKeyScopeStorage
{
    public const ENTITY_PRODUCT = 'catalog_product';
    public const ENTITY_CATEGORY = 'catalog_category';

    private const PRODUCT_URL_SUFFIX_PATH = 'catalog/seo/product_url_suffix';

    /**
     * @var array<string, int>
     */
    private array $attributeIds = [];

    /**
     * @var array<string, string>
     */
    private array $linkFields = [];

    /**
     * @var string[]|null
     */
    private ?array $productUrlSuffixes = null;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly MetadataPool $metadataPool,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly RegenerateHelper $helper
    ) {
    }

    /**
     * Link field of the entity table (entity_id in Open Source, row_id in Commerce)
     */
    public function getLinkField(string $entityTypeCode): string
    {
        if (!isset($this->linkFields[$entityTypeCode])) {
            $interface = $entityTypeCode === self::ENTITY_CATEGORY
                ? CategoryInterface::class
                : ProductInterface::class;
            $this->linkFields[$entityTypeCode] = $this->metadataPool->getMetadata($interface)->getLinkField();
        }

        return $this->linkFields[$entityTypeCode];
    }

    /**
     * Read a varchar attribute value of one entity in exactly one store (no fallback)
     */
    public function getStoreValue(string $entityTypeCode, string $attributeCode, int $linkId, int $storeId): ?string
    {
        $attributeId = $this->getAttributeId($entityTypeCode, $attributeCode);
        if ($attributeId === 0) {
            return null;
        }

        $connection = $this->resourceConnection->getConnection();
        $value = $connection->fetchOne(
            $connection->select()
                ->from($this->getVarcharTable($entityTypeCode), ['value'])
                ->where('attribute_id = ?', $attributeId)
                ->where($this->getLinkField($entityTypeCode) . ' = ?', $linkId)
                ->where('store_id = ?', $storeId)
                ->limit(1)
        );

        return $value === false || $value === null ? null : (string)$value;
    }

    /**
     * Delete the store-level value of an attribute so the store falls back to the default value
     */
    public function removeStoreValue(string $entityTypeCode, string $attributeCode, int $linkId, int $storeId): int
    {
        $attributeId = $this->getAttributeId($entityTypeCode, $attributeCode);
        if ($attributeId === 0 || $storeId === 0) {
            return 0;
        }

        return $this->resourceConnection->getConnection()->delete(
            $this->getVarcharTable($entityTypeCode),
            [
                'attribute_id = ?' => $attributeId,
                $this->getLinkField($entityTypeCode) . ' = ?' => $linkId,
                'store_id = ?' => $storeId,
            ]
        );
    }

    /**
     * Return a url_key that no other product uses.
     *
     * Order of preference, so that existing (indexed) URLs stay unchanged:
     *   1. the key generated from the name
     *   2. the product's current default-scope url_key
     *   3. the request path of the product's current canonical URL rewrite (e.g. "name-1")
     *   4. "<url_key>-<sku>" plus a counter if needed
     */
    public function getUniqueProductUrlKey(string $urlKey, string $sku, int $linkId, int $entityId): string
    {
        $candidates = [
            $urlKey,
            $this->getStoreValue(self::ENTITY_PRODUCT, 'url_key', $linkId, 0),
            $this->getCurrentProductRequestKey($entityId),
        ];
        foreach (array_unique(array_filter($candidates, static fn(?string $key): bool => !empty($key))) as $candidate) {
            if (!$this->isProductUrlKeyTaken($candidate, $linkId, $entityId)) {
                return $candidate;
            }
        }

        $skuSlug = $this->helper->formatUrlKey($this->helper->transliterateGermanCharacters($sku));
        $base = $skuSlug !== '' ? $urlKey . '-' . $skuSlug : $urlKey;
        $candidate = $base;
        $counter = 2;

        while ($this->isProductUrlKeyTaken($candidate, $linkId, $entityId)) {
            $candidate = $base . '-' . $counter++;
        }

        return $candidate;
    }

    /**
     * Check whether another product owns the url_key in any store, or whether its
     * request path is used by any other entity (including 301/302 redirects)
     */
    public function isProductUrlKeyTaken(string $urlKey, int $linkId, int $entityId): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $attributeId = $this->getAttributeId(self::ENTITY_PRODUCT, 'url_key');
        $linkField = $this->getLinkField(self::ENTITY_PRODUCT);

        $inAttribute = $connection->fetchOne(
            $connection->select()
                ->from($this->getVarcharTable(self::ENTITY_PRODUCT), ['value_id'])
                ->where('attribute_id = ?', $attributeId)
                ->where('value = ?', $urlKey)
                ->where($linkField . ' <> ?', $linkId)
                ->limit(1)
        );
        if ($inAttribute !== false) {
            return true;
        }

        $requestPaths = array_map(
            static fn(string $suffix): string => $urlKey . $suffix,
            $this->getProductUrlSuffixes()
        );

        $inRewrite = $connection->fetchOne(
            $connection->select()
                ->from($this->resourceConnection->getTableName('url_rewrite'), ['url_rewrite_id'])
                ->where('request_path IN (?)', $requestPaths)
                ->where(
                    $connection->quoteInto('NOT (entity_type = ?', 'product')
                    . $connection->quoteInto(' AND entity_id = ?)', $entityId)
                )
                ->limit(1)
        );

        return $inRewrite !== false;
    }

    /**
     * Request path of the product's canonical URL rewrite (lowest store id), without URL suffix
     */
    public function getCurrentProductRequestKey(int $entityId): ?string
    {
        $connection = $this->resourceConnection->getConnection();
        $requestPath = $connection->fetchOne(
            $connection->select()
                ->from($this->resourceConnection->getTableName('url_rewrite'), ['request_path'])
                ->where('entity_type = ?', 'product')
                ->where('entity_id = ?', $entityId)
                ->where('redirect_type = 0')
                ->where('metadata IS NULL')
                ->order('store_id ASC')
                ->limit(1)
        );
        if ($requestPath === false || $requestPath === '') {
            return null;
        }

        $requestPath = (string)$requestPath;
        foreach ($this->getProductUrlSuffixes() as $suffix) {
            if ($suffix !== '' && str_ends_with($requestPath, $suffix)) {
                return substr($requestPath, 0, -strlen($suffix));
            }
        }

        return $requestPath;
    }

    /**
     * All distinct product URL suffixes configured on default and store level
     *
     * @return string[]
     */
    private function getProductUrlSuffixes(): array
    {
        if ($this->productUrlSuffixes === null) {
            $suffixes = [(string)$this->scopeConfig->getValue(self::PRODUCT_URL_SUFFIX_PATH)];
            $connection = $this->resourceConnection->getConnection();
            $storeIds = $connection->fetchCol(
                $connection->select()
                    ->from($this->resourceConnection->getTableName('store'), ['store_id'])
                    ->where('store_id > 0')
            );
            foreach ($storeIds as $storeId) {
                $suffixes[] = (string)$this->scopeConfig->getValue(
                    self::PRODUCT_URL_SUFFIX_PATH,
                    ScopeInterface::SCOPE_STORE,
                    (int)$storeId
                );
            }
            $this->productUrlSuffixes = array_values(array_unique($suffixes));
        }

        return $this->productUrlSuffixes;
    }

    private function getAttributeId(string $entityTypeCode, string $attributeCode): int
    {
        $cacheKey = $entityTypeCode . '/' . $attributeCode;
        if (!isset($this->attributeIds[$cacheKey])) {
            $connection = $this->resourceConnection->getConnection();
            $this->attributeIds[$cacheKey] = (int)$connection->fetchOne(
                $connection->select()
                    ->from(['a' => $this->resourceConnection->getTableName('eav_attribute')], ['attribute_id'])
                    ->join(
                        ['t' => $this->resourceConnection->getTableName('eav_entity_type')],
                        't.entity_type_id = a.entity_type_id',
                        []
                    )
                    ->where('a.attribute_code = ?', $attributeCode)
                    ->where('t.entity_type_code = ?', $entityTypeCode)
            );
        }

        return $this->attributeIds[$cacheKey];
    }

    private function getVarcharTable(string $entityTypeCode): string
    {
        return $this->resourceConnection->getTableName(
            $entityTypeCode === self::ENTITY_CATEGORY
                ? 'catalog_category_entity_varchar'
                : 'catalog_product_entity_varchar'
        );
    }
}
