<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Config\Backend;

use Magento\Config\Model\Config\Backend\Serialized\ArraySerialized;

class SearchableAttributes extends ArraySerialized
{
    private const int DEFAULT_WEIGHT = 1;

    public function beforeSave()
    {
        $value = $this->getData('value');

        if (is_array($value)) {
            unset($value['__empty']);

            foreach ($value as $key => $row) {
                $weight = is_array($row) ? (int) ($row['weight'] ?? 0) : 0;
                $value[$key]['weight'] = $weight > 0 ? $weight : self::DEFAULT_WEIGHT;
            }

            // uasort is stable, so equal weights keep the admin's row order.
            uasort($value, static fn (array $a, array $b): int => $b['weight'] <=> $a['weight']);
            $this->setData('value', $value);
        }

        return parent::beforeSave();
    }
}
