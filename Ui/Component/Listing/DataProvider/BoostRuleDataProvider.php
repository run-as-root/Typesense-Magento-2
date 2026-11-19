<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Ui\Component\Listing\DataProvider;

use Magento\Ui\DataProvider\AbstractDataProvider;
use RunAsRoot\TypeSense\Model\ResourceModel\BoostRule\CollectionFactory;

class BoostRuleDataProvider extends AbstractDataProvider
{
    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        CollectionFactory $collectionFactory,
        array $meta = [],
        array $data = [],
    ) {
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
        $this->collection = $collectionFactory->create();
    }

    /**
     * @return array{totalRecords: int, items: array<int, array<string, mixed>>}
     */
    public function getData(): array
    {
        $items = [];
        $rules = $this->getCollection()->getItems();

        foreach ($rules as $rule) {
            $ruleData = $rule->getData();
            // The Store column skips an empty() value, so a bare 0 would render blank instead of "All Store Views".
            $ruleData['store_id'] = [(int) $ruleData['store_id']];
            $items[] = $ruleData;
        }

        return [
            'totalRecords' => $this->getCollection()->getSize(),
            'items' => $items,
        ];
    }
}
