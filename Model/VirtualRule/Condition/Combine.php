<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\VirtualRule\Condition;

use Magento\CatalogRule\Model\Rule\Condition\Combine as CatalogRuleCombine;
use Magento\CatalogRule\Model\Rule\Condition\ProductFactory as CatalogRuleProductFactory;
use Magento\Rule\Model\Condition\AbstractCondition as RuleAbstractCondition;
use Magento\Rule\Model\Condition\Context;

/**
 * Restricts the rule builder's "Product Attribute" list to attributes present in the Typesense
 * schema (see RunAsRoot\TypeSense\Model\VirtualRule\Condition\Product).
 *
 * Verified against the real mage-os/module-catalog-rule and mage-os/module-rule source (installed
 * in the linked Warden Magento project, not present in this standalone module's composer setup):
 * - Magento\CatalogRule\Model\Rule\Condition\Combine::getNewChildSelectOptions() is `public`, has
 *   no declared return type, and unconditionally builds its "Product Attribute" bucket from
 *   \Magento\CatalogRule\Model\Rule\Condition\Product (the *unfiltered* condition) via its own
 *   $_productFactory. There is no smaller seam to override — calling
 *   parent::getNewChildSelectOptions() would merge the unfiltered attribute list back in — so this
 *   method is re-implemented here in full, matching the real implementation's structure exactly
 *   but sourcing options from our filtered Product condition instead.
 * - \Magento\Rule\Model\Condition\Combine (the grandparent) does not itself define
 *   getNewChildSelectOptions() at all; both it and CatalogRuleCombine ultimately delegate the base
 *   "please choose a condition" placeholder to
 *   \Magento\Rule\Model\Condition\AbstractCondition::getNewChildSelectOptions(). Calling that
 *   directly below reproduces exactly what `parent::getNewChildSelectOptions()` resolves to inside
 *   the real CatalogRuleCombine::getNewChildSelectOptions().
 * - CatalogRuleCombine::__construct(Context $context, ProductFactory $conditionFactory, array
 *   $data = []) stores $conditionFactory only to build the unfiltered Product Attribute options
 *   (see above) and sets $this->setType(CatalogRuleCombine::class). Its only other method,
 *   collectValidatedAttributes(), never reads that factory. We keep passing the original
 *   CatalogRule ProductFactory through unchanged purely to satisfy that constructor's signature,
 *   add our own TypeSense-restricted ProductFactory alongside it, and re-point setType() at this
 *   class so that recursively-nested "Conditions Combination" groups are rebuilt as this
 *   restricted Combine too, not the unfiltered Magento one.
 */
class Combine extends CatalogRuleCombine
{
    public function __construct(
        Context $context,
        CatalogRuleProductFactory $conditionFactory,
        private readonly ProductFactory $typeSenseProductFactory,
        array $data = [],
    ) {
        parent::__construct($context, $conditionFactory, $data);
        $this->setType(self::class);
    }

    /**
     * @return array
     */
    public function getNewChildSelectOptions()
    {
        $productAttributeOptions = $this->typeSenseProductFactory->create()->loadAttributeOptions()->getAttributeOption();

        $attributes = [];
        foreach ($productAttributeOptions as $code => $label) {
            $attributes[] = [
                'value' => Product::class . '|' . $code,
                'label' => $label,
            ];
        }

        $conditions = RuleAbstractCondition::getNewChildSelectOptions();

        return array_merge_recursive(
            $conditions,
            [
                [
                    'value' => self::class,
                    'label' => __('Conditions Combination'),
                ],
                ['label' => __('Product Attribute'), 'value' => $attributes],
            ],
        );
    }
}
