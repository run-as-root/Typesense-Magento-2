<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Ui\Component\Form\DataProvider;

use Magento\Ui\DataProvider\AbstractDataProvider;
use RunAsRoot\TypeSense\Model\ResourceModel\BoostRule\CollectionFactory;

class BoostRuleFormDataProvider extends AbstractDataProvider
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
     * The conditions tree is rendered by Block\Adminhtml\BoostRule\Conditions, not bound to a field.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getData(): array
    {
        $data = [];
        $rules = $this->getCollection()->getItems();

        foreach ($rules as $rule) {
            $ruleData = $rule->getData();
            unset($ruleData['conditions_serialized']);
            $data[(int) $rule->getId()] = $ruleData;
        }

        return $data;
    }
}
