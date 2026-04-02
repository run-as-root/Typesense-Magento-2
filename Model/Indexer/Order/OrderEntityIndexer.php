<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Order;

use Magento\Sales\Model\Order;
use RunAsRoot\TypeSense\Api\EntityIndexerInterface;
use RunAsRoot\TypeSense\Api\OrderSchemaProviderInterface;

class OrderEntityIndexer implements EntityIndexerInterface
{
    public function __construct(
        private readonly OrderDataBuilder $dataBuilder,
        private readonly OrderSchemaProviderInterface $schemaProvider,
    ) {
    }

    public function getEntityType(): string
    {
        return 'order';
    }

    public function getIndexerCode(): string
    {
        return 'typesense_order';
    }

    public function getSchemaFields(): array
    {
        return $this->schemaProvider->getFields();
    }

    public function buildDocuments(array $entityIds, int $storeId): iterable
    {
        $collection = $this->dataBuilder->getOrderCollection($entityIds, $storeId);

        /** @var Order $order */
        foreach ($collection as $order) {
            yield $this->dataBuilder->build($order, $storeId);
        }
    }
}
