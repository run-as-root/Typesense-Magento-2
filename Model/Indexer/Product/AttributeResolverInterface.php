<?php

declare(strict_types=1);

namespace RunAsRoot\TypeSense\Model\Indexer\Product;

use Magento\Catalog\Model\Product;

interface AttributeResolverInterface
{
    /**
     * Values of the configured additional attributes, option attributes by label. Every attribute is a list
     * because a composite parent also holds its children's values.
     *
     * @return array<string, list<string>>
     */
    public function getExtraAttributes(Product $product): array;
}
