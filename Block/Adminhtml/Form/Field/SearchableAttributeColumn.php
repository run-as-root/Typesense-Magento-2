<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Block\Adminhtml\Form\Field;

use Magento\Framework\View\Element\Context;
use Magento\Framework\View\Element\Html\Select;
use RunAsRoot\TypeSense\Model\Search\SearchableAttributesProvider;

class SearchableAttributeColumn extends Select
{
    public function __construct(
        Context $context,
        private readonly SearchableAttributesProvider $searchableAttributesProvider,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    public function setInputName(string $value): self
    {
        return $this->setData('name', $value);
    }

    public function setInputId(string $value): self
    {
        return $this->setData('id', $value);
    }

    protected function _toHtml(): string
    {
        if (!$this->getOptions()) {
            $options = [];
            foreach ($this->searchableAttributesProvider->getOptions() as $code => $label) {
                $options[] = ['value' => $code, 'label' => $label];
            }
            $this->setOptions($options);
        }

        return parent::_toHtml();
    }
}
