<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Block\Adminhtml\Form\Field;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Config\Model\Config\Source\Yesno;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use RunAsRoot\TypeSense\Model\Config\Source\FacetAttributeSource;
use RunAsRoot\TypeSense\Model\Config\Source\FacetType;

class FacetTypes extends AbstractFieldArray
{
    /** @var array<string, OptionColumn> */
    private array $columns = [];

    public function __construct(
        Context $context,
        private readonly FacetAttributeSource $facetAttributeSource,
        private readonly FacetType $facetType,
        private readonly Yesno $yesno,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /** @throws LocalizedException */
    protected function _prepareToRender(): void
    {
        $this->addColumn('attribute', ['label' => __('Facet'), 'renderer' => $this->getColumn('attribute')]);
        $this->addColumn('type', ['label' => __('Type'), 'renderer' => $this->getColumn('type')]);
        $this->addColumn('searchable', ['label' => __('Value Search'), 'renderer' => $this->getColumn('searchable')]);

        $this->_addAfter = false;
        $this->_addButtonLabel = (string) __('Add Facet Type');
    }

    /** @throws LocalizedException */
    protected function _prepareArrayRow(DataObject $row): void
    {
        $options = [];
        $columnNames = ['attribute', 'type', 'searchable'];
        foreach ($columnNames as $columnName) {
            $value = $row->getData($columnName);
            if ($value !== null) {
                $hash = $this->getColumn($columnName)->calcOptionHash($value);
                $options['option_' . $hash] = 'selected="selected"';
            }
        }

        $row->setData('option_extra_attrs', $options);
    }

    /** @throws LocalizedException */
    private function getColumn(string $columnName): OptionColumn
    {
        if (!isset($this->columns[$columnName])) {
            /** @var OptionColumn $column */
            $column = $this->getLayout()->createBlock(
                OptionColumn::class,
                '',
                ['data' => ['is_render_to_js_template' => true]],
            );
            $column->setOptions(match ($columnName) {
                'attribute' => $this->facetAttributeSource->toOptionArray(),
                'type' => $this->facetType->toOptionArray(),
                default => $this->yesno->toOptionArray(),
            });
            $this->columns[$columnName] = $column;
        }

        return $this->columns[$columnName];
    }
}
