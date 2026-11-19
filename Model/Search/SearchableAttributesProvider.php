<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Search;

use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;
use RunAsRoot\TypeSense\Model\Indexer\Product\Decompound\NameDecompounder;

class SearchableAttributesProvider
{
    public const array CORE_FIELDS = [
        'name' => 'Name',
        'sku' => 'SKU',
        'description' => 'Description',
        'short_description' => 'Short Description',
        'categories_text' => 'Categories',
        NameDecompounder::FIELD => 'Name (decompounded)',
    ];

    private const string FALLBACK_FIELD = 'name';

    public function __construct(
        private readonly TypeSenseConfigInterface $config,
        private readonly ProductAttributeRepositoryInterface $attributeRepository,
    ) {
    }

    /** @return array<string, string> code => label */
    public function getOptions(?int $storeId = null): array
    {
        $options = self::CORE_FIELDS;

        $additional = $this->config->getAdditionalAttributes($storeId);
        foreach ($additional as $code) {
            $options[$code] ??= $this->getLabel($code);
        }

        return $options;
    }

    /** @return array<string, int> field code => weight, highest first */
    public function getWeightedFields(?int $storeId = null): array
    {
        $additional = $this->config->getAdditionalAttributes($storeId);
        $indexed = array_flip(array_merge(array_keys(self::CORE_FIELDS), $additional));
        if (!$this->config->isGermanDecompoundingEnabled($storeId)) {
            unset($indexed[NameDecompounder::FIELD]);
        }

        $fields = [];
        $rows = $this->config->getSearchableAttributes($storeId);
        foreach ($rows as $row) {
            $code = $row['attribute'];

            // Rows are saved heaviest first, so a repeated attribute keeps its highest weight.
            if (isset($indexed[$code]) && !isset($fields[$code])) {
                $fields[$code] = $row['weight'];
            }
        }

        return $fields !== [] ? $fields : [self::FALLBACK_FIELD => 1];
    }

    private function getLabel(string $code): string
    {
        try {
            return (string) ($this->attributeRepository->get($code)->getDefaultFrontendLabel() ?: $code);
        } catch (NoSuchEntityException) {
            return $code;
        }
    }
}
