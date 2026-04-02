<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Customer;

use Magento\Customer\Model\Customer;
use RunAsRoot\TypeSense\Api\CustomerSchemaProviderInterface;
use RunAsRoot\TypeSense\Api\EntityIndexerInterface;

class CustomerEntityIndexer implements EntityIndexerInterface
{
    public function __construct(
        private readonly CustomerDataBuilder $dataBuilder,
        private readonly CustomerSchemaProviderInterface $schemaProvider,
    ) {
    }

    public function getEntityType(): string
    {
        return 'customer';
    }

    public function getIndexerCode(): string
    {
        return 'typesense_customer';
    }

    public function getSchemaFields(): array
    {
        return $this->schemaProvider->getFields();
    }

    public function buildDocuments(array $entityIds, int $storeId): iterable
    {
        $collection = $this->dataBuilder->getCustomerCollection($entityIds, $storeId);

        /** @var Customer $customer */
        foreach ($collection as $customer) {
            yield $this->dataBuilder->build($customer, $storeId);
        }
    }
}
