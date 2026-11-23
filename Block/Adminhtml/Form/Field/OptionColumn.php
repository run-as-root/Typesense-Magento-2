<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Block\Adminhtml\Form\Field;

use Magento\Framework\View\Element\Html\Select;

/**
 * Select cell for dynamic-row config fields; the owning field array sets the options.
 */
class OptionColumn extends Select
{
    public function setInputName(string $value): self
    {
        return $this->setData('name', $value);
    }

    public function setInputId(string $value): self
    {
        return $this->setData('id', $value);
    }
}
