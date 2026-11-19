<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product\Decompound;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Words the store's product names use on their own ("Tisch", "Liege"). Such a word confirms a weak split
 * whose tail it is ("Ess|Tisch" scores only -0.15), while junk tails ("Thors|Ten") never occur on their own.
 */
class CatalogWordsProvider
{
    private const string WORD_PATTERN = '/\p{L}+/u';

    /** @var array<int, array<string, true>> */
    private array $wordsByStore = [];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly EavConfig $eavConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly MetadataPool $metadataPool,
    ) {
    }

    /** @return array<string, true> lower-case words */
    public function getWords(int $storeId): array
    {
        return $this->wordsByStore[$storeId] ??= $this->loadWords($storeId);
    }

    /** @return array<string, true> */
    private function loadWords(int $storeId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
        $attributeId = (int) $this->eavConfig->getAttribute('catalog_product', 'name')->getId();
        $valueTable = $this->resourceConnection->getTableName('catalog_product_entity_varchar');
        $joinCondition = "%1\$s.$linkField = e.$linkField AND %1\$s.attribute_id = $attributeId"
            . ' AND %1$s.store_id = ?';
        $websiteId = (int) $this->storeManager->getStore($storeId)->getWebsiteId();

        $select = $connection->select()
            ->from(['pw' => $this->resourceConnection->getTableName('catalog_product_website')], [])
            ->join(
                ['e' => $this->resourceConnection->getTableName('catalog_product_entity')],
                'e.entity_id = pw.product_id',
                [],
            )
            ->joinLeft(['d' => $valueTable], $connection->quoteInto(sprintf($joinCondition, 'd'), 0), [])
            ->joinLeft(['s' => $valueTable], $connection->quoteInto(sprintf($joinCondition, 's'), $storeId), [])
            ->columns(['name' => $connection->getIfNullSql('s.value', 'd.value')])
            ->where('pw.website_id = ?', $websiteId);

        $words = [];
        $names = $connection->fetchCol($select);
        foreach ($names as $name) {
            if (preg_match_all(self::WORD_PATTERN, (string) $name, $matches) > 0) {
                foreach ($matches[0] as $word) {
                    $words[mb_strtolower($word, 'UTF-8')] = true;
                }
            }
        }

        return $words;
    }
}
