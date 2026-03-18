<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Ui\Component\Listing\DataProvider;

use Magento\Ui\DataProvider\AbstractDataProvider;
use RunAsRoot\TypeSense\Model\ResourceModel\QueryMerchandising\CollectionFactory;

class QueryMerchandiserDataProvider extends AbstractDataProvider
{
    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        private readonly CollectionFactory $collectionFactory,
        array $meta = [],
        array $data = [],
    ) {
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
        $this->collection = $this->collectionFactory->create();
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        if (!$this->getCollection()->isLoaded()) {
            $this->getCollection()->load();
        }

        $items = [];

        foreach ($this->getCollection()->getItems() as $item) {
            $items[] = $item->getData();
        }

        return [
            'totalRecords' => $this->getCollection()->getSize(),
            'items'        => $items,
        ];
    }
}
