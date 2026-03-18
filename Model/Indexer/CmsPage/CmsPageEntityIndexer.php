<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\CmsPage;

use Magento\Cms\Model\Page;
use RunAsRoot\TypeSense\Api\EntityIndexerInterface;

readonly class CmsPageEntityIndexer implements EntityIndexerInterface
{
    public function __construct(
        private CmsPageDataBuilder $dataBuilder,
        private CmsPageSchemaProviderInterface $schemaProvider,
    ) {
    }

    public function getEntityType(): string
    {
        return 'cms_page';
    }

    public function getIndexerCode(): string
    {
        return 'typesense_cms_page';
    }

    public function getSchemaFields(): array
    {
        return $this->schemaProvider->getFields();
    }

    public function buildDocuments(array $entityIds, int $storeId): iterable
    {
        $collection = $this->dataBuilder->getPageCollection($entityIds, $storeId);

        /** @var Page $page */
        foreach ($collection as $page) {
            yield $this->dataBuilder->build($page, $storeId);
        }
    }
}
