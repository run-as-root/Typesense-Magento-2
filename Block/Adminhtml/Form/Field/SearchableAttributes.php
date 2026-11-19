<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Block\Adminhtml\Form\Field;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;

class SearchableAttributes extends AbstractFieldArray
{
    private ?SearchableAttributeColumn $attributeColumn = null;

    /** @throws LocalizedException */
    protected function _prepareToRender(): void
    {
        $this->addColumn('attribute', [
            'label' => __('Attribute'),
            'renderer' => $this->getAttributeColumn(),
        ]);
        $this->addColumn('weight', [
            'label' => __('Weight'),
            'class' => 'required-entry validate-digits validate-greater-than-zero',
        ]);

        $this->_addAfter = false;
        $this->_addButtonLabel = (string) __('Add Attribute');
    }

    /** @throws LocalizedException */
    protected function _prepareArrayRow(DataObject $row): void
    {
        $attribute = $row->getData('attribute');
        $options = [];

        if ($attribute !== null) {
            $options['option_' . $this->getAttributeColumn()->calcOptionHash($attribute)] = 'selected="selected"';
        }

        $row->setData('option_extra_attrs', $options);
    }

    /** @throws LocalizedException */
    private function getAttributeColumn(): SearchableAttributeColumn
    {
        return $this->attributeColumn ??= $this->getLayout()->createBlock(
            SearchableAttributeColumn::class,
            '',
            ['data' => ['is_render_to_js_template' => true]],
        );
    }
}
