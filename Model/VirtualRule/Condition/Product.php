<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\VirtualRule\Condition;

use Magento\Backend\Helper\Data as BackendHelper;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ProductCategoryList;
use Magento\Catalog\Model\ProductFactory;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\CatalogRule\Model\Rule\Condition\Product as CatalogRuleProduct;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Set\Collection as AttributeSetCollection;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Locale\FormatInterface;
use Magento\Rule\Model\Condition\Context;
use RunAsRoot\TypeSense\Model\Config\TypeSenseConfigInterface;

/**
 * Restricts the attribute options Magento_CatalogRule's own Product condition offers to the fixed
 * core set Typesense always exposes (name, sku, price, category_ids) plus whichever additional
 * attributes are configured for indexing (RunAsRoot_TypeSense's "Additional Attributes" config).
 *
 * Everything else — operator rendering, value inputs, validate(), collectValidatedAttributes() —
 * is inherited unchanged from \Magento\CatalogRule\Model\Rule\Condition\Product.
 *
 * Verified against the real mage-os/module-catalog-rule and mage-os/module-rule source (installed
 * in the linked Warden Magento project, not present in this standalone module's composer setup):
 * - Magento\CatalogRule\Model\Rule\Condition\Product itself declares no constructor and no
 *   loadAttributeOptions() override; both are inherited from
 *   Magento\Rule\Model\Condition\Product\AbstractProduct.
 * - AbstractProduct::loadAttributeOptions() is `public function loadAttributeOptions()` with no
 *   parameters and no declared return type (docblock: "@return $this"). Matched exactly below.
 * - AbstractProduct's grandparent, Magento\Rule\Model\Condition\AbstractCondition::__construct(),
 *   calls $this->loadAttributeOptions() itself during construction — so any dependency our
 *   override needs must be assigned before parent::__construct() runs, which the constructor
 *   below does explicitly.
 */
class Product extends CatalogRuleProduct
{
    private const CORE_FILTERABLE_ATTRIBUTES = ['name', 'sku', 'price', 'category_ids'];

    private readonly TypeSenseConfigInterface $typeSenseConfig;

    /**
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        Context $context,
        BackendHelper $backendData,
        EavConfig $config,
        ProductFactory $productFactory,
        ProductRepositoryInterface $productRepository,
        ProductResource $productResource,
        AttributeSetCollection $attrSetCollection,
        FormatInterface $localeFormat,
        array $data = [],
        ?ProductCategoryList $categoryList = null,
        ?TypeSenseConfigInterface $typeSenseConfig = null,
    ) {
        // $typeSenseConfig is appended as a nullable, ObjectManager-backed dependency rather than
        // a plain required constructor argument for two reasons:
        // 1. AbstractProduct::__construct() itself appends its own new dependency ($categoryList)
        //    the exact same way, for the exact same reason: it lets a new dependency be added to a
        //    constructor whose full call chain we don't control without breaking any other code
        //    that instantiates this class with the "old" argument list.
        // 2. A required parameter cannot follow $data/$categoryList (both have defaults) without
        //    PHP deprecating the signature ("Optional parameter before required parameter").
        // It must be assigned before parent::__construct() below runs, because that call chain
        // ends in AbstractCondition::__construct(), which invokes $this->loadAttributeOptions()
        // (our override, which reads $this->typeSenseConfig) during construction itself.
        $this->typeSenseConfig = $typeSenseConfig ?? ObjectManager::getInstance()->get(TypeSenseConfigInterface::class);

        parent::__construct(
            $context,
            $backendData,
            $config,
            $productFactory,
            $productRepository,
            $productResource,
            $attrSetCollection,
            $localeFormat,
            $data,
            $categoryList,
        );
    }

    /**
     * Load attribute options
     *
     * @return $this
     */
    public function loadAttributeOptions()
    {
        parent::loadAttributeOptions();

        $allowed = array_merge(self::CORE_FILTERABLE_ATTRIBUTES, $this->typeSenseConfig->getAdditionalAttributes());
        $this->setAttributeOption(array_intersect_key($this->getAttributeOption(), array_flip($allowed)));

        return $this;
    }
}
